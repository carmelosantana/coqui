<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PathHelper\PathHelper;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CoquiBot\Coqui\Support\FileSystemOperations;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use React\ChildProcess\Process as ReactProcess;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use function React\Async\await;

final class ShellToolkit implements ToolkitInterface
{
    /**
     * Regex patterns that indicate dangerous shell constructs.
     * These are checked in addition to the configurable denylist.
     *
     * @var string[]
     */
    private const DENIED_PATTERNS = [
        '/\brm\s+(-[a-z]*r[a-z]*\s+-[a-z]*f|-[a-z]*f[a-z]*\s+-[a-z]*r)\b/i',  // rm -rf / rm -fr variants
        '/\brm\s+-[a-z]*rf\b/i',                                                  // rm -rf combined
        '/\|\s*(bash|sh|zsh|dash)\b/i',                                            // Pipe to shell
        '/\bcurl\b.*\|\s*(bash|sh|zsh)\b/i',                                      // curl | bash
        '/\bwget\b.*\|\s*(bash|sh|zsh)\b/i',                                      // wget | bash
        '/\bphp\s+-r\b/i',                                                         // php -r inline execution
        '/\bmkfifo\b/i',                                                            // Named pipe creation
        '/\b(nc|ncat|netcat)\s/i',                                                  // Network tools
        // Inline code hides its writes from the write-target check
        '/\b(ba|z|da|k|fi)?sh\s+(-[a-z]+\s+)*-[a-z]*c[a-z]*\b/i',                  // sh -c, bash -lc
        '/\bpython[0-9.]*\s+(-[a-z]+\s+)*-[a-z]*c\b/i',                           // python3 -c
        '/\b(perl|ruby)\s+(-[a-z]+\s+)*-[a-z]*e\b/i',                             // perl -e, perl -pi -e, ruby -e
        '/\b(node|nodejs|deno|bun)\s+(-[a-z-]+\s+)*(-e|-p|--eval|--print)\b/i',   // node -e
        '/\beval\s/i',                                                            // eval
    ];

    /**
     * Commands whose last positional argument is a write target.
     *
     * @var string[]
     */
    private const WRITE_COMMANDS = [
        'tee', 'cp', 'mv', 'install', 'rsync', 'scp',
    ];

    /**
     * Environment variable name patterns that are always stripped.
     * Substring-matched case-insensitively, so they also catch NPM_TOKEN,
     * COMPOSER_AUTH, DOCKER_AUTH_CONFIG and SSH_AUTH_SOCK (agent forwarding).
     *
     * @var string[]
     */
    private const SENSITIVE_ENV_PATTERNS = [
        'KEY', 'TOKEN', 'SECRET', 'PASSWORD', 'CREDENTIAL', 'AUTH',
    ];

    /**
     * Exact names that match a sensitive pattern but carry no secret.
     *
     * @var string[]
     */
    private const SENSITIVE_ENV_EXCEPTIONS = [
        'GIT_AUTHOR_NAME', 'GIT_AUTHOR_EMAIL', 'GIT_AUTHOR_DATE',
    ];

    /**
     * @param string[] $allowedCommands
     * @param string[] $deniedCommands
     * @param array<int, array{realPath: string, readOnly: bool}> $allowedPaths Mount paths for cwd sandbox
     */
    public function __construct(
        private readonly string $workDir = '.',
        private readonly array $allowedCommands = [],
        private readonly array $deniedCommands = ['sudo', 'chmod 777'],
        private readonly int $timeout = 30,
        private readonly bool $unsafe = false,
        private readonly ?ProcessCancellationToken $cancellationToken = null,
        private readonly ?string $rootPath = null,
        private readonly array $allowedPaths = [],
        private readonly bool $sandboxWrites = true,
        private readonly bool $scrubEnvironment = true,
    ) {
        $this->fs = $rootPath !== null ? new FileSystemOperations($rootPath, $allowedPaths) : null;
    }

    /** Sandbox view used to recognise protected files; null without a root. */
    private readonly ?FileSystemOperations $fs;

    public function tools(): array
    {
        return [$this->execTool()];
    }

    public function guidelines(): string
    {
        $allowed = $this->unsafe
            ? 'all (unsafe mode — only catastrophic patterns blocked at policy layer)'
            : (empty($this->allowedCommands) ? 'all (except denied)' : implode(', ', $this->allowedCommands));

        $lines = [
            '<SHELL-GUIDELINES>',
            "Working directory: {$this->workDir}",
            "Allowed commands: {$allowed}",
            "Timeout: {$this->timeout}s",
            '- Use shell commands for build, test, and system operations.',
            '- Do not use shell just to run ad hoc PHP snippets or quick PHP validation; prefer php_execute for inline PHP execution.',
            '- Prefer specific commands over broad ones.',
            '- Always check exit codes and stderr.',
        ];

        if ($this->sandboxWrites) {
            $lines[] = '- Shell output (redirections, cp, mv) is sandboxed to the workspace and mounted directories. Absolute paths outside the sandbox will be rejected.';
        }

        if (!empty($this->allowedCommands)) {
            $lines[] = '- Allowlist mode rejects shell operators, redirection, line breaks, and leading environment assignments.';
        }

        $lines[] = '</SHELL-GUIDELINES>';

        return implode("\n", $lines);
    }

    private function execTool(): ToolInterface
    {
        return new Tool(
            name: 'exec',
            description: 'Execute a shell command.',
            parameters: [
                new StringParameter('command', 'The shell command to execute'),
                new StringParameter('cwd', 'Working directory to run the command in. Relative paths are resolved from the default working directory. Defaults to the workspace root.', required: false),
                new NumberParameter('timeout', 'Timeout in seconds', required: false, integer: true),
            ],
            callback: function (array $input): ToolResult {
                $command = $input['command'] ?? '';
                $timeout = (int) ($input['timeout'] ?? $this->timeout);
                $cwd = $input['cwd'] ?? null;

                if ($command === '') {
                    return ToolResult::error('Command is required');
                }

                $allowlistViolation = $this->validateAllowlistedCommand($command);
                if ($allowlistViolation !== null) {
                    return ToolResult::error($allowlistViolation);
                }

                // In unsafe mode, skip all command validation — only basic sanity
                // and working directory resolution apply. Catastrophic commands are
                // blocked at the execution policy layer (CatastrophicBlacklist).
                if (!$this->unsafe) {
                    if (!$this->isCommandAllowed($command)) {
                        return ToolResult::error("Command not allowed: {$command}");
                    }

                    // Check configurable denylist (substring match)
                    foreach ($this->deniedCommands as $denied) {
                        if (str_contains($command, $denied)) {
                            return ToolResult::error("Denied command pattern detected: {$denied}");
                        }
                    }

                    // Check built-in regex deny patterns
                    foreach (self::DENIED_PATTERNS as $pattern) {
                        if (preg_match($pattern, $command)) {
                            return ToolResult::error("Denied: command matches a blocked security pattern.");
                        }
                    }
                }

                // Resolve working directory
                $effectiveCwd = $this->resolveCwd($cwd);
                if ($effectiveCwd === null) {
                    return ToolResult::error("Invalid working directory: {$cwd}");
                }

                // Protected files (.env, tokens, databases, openclaw.json) are
                // off limits however the sandbox is configured.
                $secretViolation = $this->validateSecretReferences($command, $effectiveCwd);
                if ($secretViolation !== null) {
                    return ToolResult::error($secretViolation);
                }

                // Write targets: control files are always protected; with
                // sandboxWrites on, every target must also stay inside the
                // sandbox. Not gated by allowlist or --unsafe.
                $writeViolation = $this->validateWriteTargets($command, $effectiveCwd);
                if ($writeViolation !== null) {
                    return ToolResult::error($writeViolation);
                }

                // Build subprocess environment
                $processEnv = $this->scrubEnvironment ? $this->buildSanitizedEnvironment() : null;

                // Use ReactPHP child-process for non-blocking execution.
                // During await(), the event loop runs — spinner timer fires.
                $reactProcess = new ReactProcess($command, $effectiveCwd, $processEnv);
                $deferred = new Deferred();
                $stdout = '';
                $stderr = '';
                $timedOut = false;
                $cancelled = false;

                try {
                    $reactProcess->start();
                } catch (\Throwable $e) {
                    return ToolResult::error("Failed to execute command: {$e->getMessage()}");
                }

                $reactProcess->stdout?->on('data', static function (string $chunk) use (&$stdout): void {
                    $stdout .= $chunk;
                });

                $reactProcess->stderr?->on('data', static function (string $chunk) use (&$stderr): void {
                    $stderr .= $chunk;
                });

                $this->cancellationToken?->onCancel(static function () use ($reactProcess, &$cancelled): void {
                    $cancelled = true;
                    $reactProcess->terminate();
                });

                $timeoutTimer = Loop::addTimer($timeout, static function () use ($reactProcess, &$timedOut): void {
                    $timedOut = true;
                    $reactProcess->terminate();
                });

                $reactProcess->on('exit', static function (?int $code) use ($deferred, $timeoutTimer): void {
                    Loop::cancelTimer($timeoutTimer);
                    $deferred->resolve($code ?? 1);
                });

                $exitCode = (int) await($deferred->promise());

                if ($timedOut) {
                    return ToolResult::error("Command timed out after {$timeout}s");
                }

                if ($cancelled) {
                    return ToolResult::error('Command cancelled.');
                }

                $result = [
                    'exit_code' => $exitCode,
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                ];

                return new ToolResult(
                    status: $exitCode === 0
                        ? ToolResultStatus::Success
                        : ToolResultStatus::Error,
                    content: json_encode($result, JSON_PRETTY_PRINT) ?: '',
                );
            },
        );
    }

    private function isCommandAllowed(string $command): bool
    {
        if (empty($this->allowedCommands)) {
            return true;
        }

        $trimmed = trim($command);
        $words = preg_split('/\s+/', $trimmed, 2) ?: [$trimmed];
        $firstWord = $words[0];

        if ($firstWord === '') {
            return false;
        }

        // Check against allowlist
        foreach ($this->allowedCommands as $allowed) {
            if ($firstWord === $allowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reject shell syntax that makes allowlist mode unsafe.
     */
    private function validateAllowlistedCommand(string $command): ?string
    {
        if (empty($this->allowedCommands)) {
            return null;
        }

        if (preg_match('/[\r\n]/', $command) === 1) {
            return 'Denied: allowlisted commands cannot contain line breaks.';
        }

        $tokens = preg_split('/\s+/', trim($command)) ?: [];
        if ($tokens !== [] && $this->hasLeadingEnvironmentAssignment($tokens)) {
            return 'Denied: allowlisted commands cannot start with environment variable assignments.';
        }

        if ($this->hasShellOperators($command)) {
            return 'Denied: allowlisted commands cannot use shell operators, redirection, or command substitution.';
        }

        return null;
    }

    /**
     * @param string[] $tokens
     */
    private function hasLeadingEnvironmentAssignment(array $tokens): bool
    {
        $firstToken = $tokens[0] ?? '';

        if ($firstToken === '') {
            return false;
        }

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*=.*/', $firstToken) === 1;
    }

    /**
     * Detect shell operators outside of quoted strings.
     */
    private function hasShellOperators(string $command): bool
    {
        $quote = null;
        $escapeNext = false;
        $length = strlen($command);

        for ($index = 0; $index < $length; $index++) {
            $char = $command[$index];

            if ($escapeNext) {
                $escapeNext = false;
                continue;
            }

            if ($quote === "'") {
                if ($char === "'") {
                    $quote = null;
                }

                continue;
            }

            if ($char === '\\') {
                $escapeNext = true;
                continue;
            }

            if ($quote === '"') {
                if ($char === '"') {
                    $quote = null;
                    continue;
                }

                if ($char === '`') {
                    return true;
                }

                if ($char === '$' && ($command[$index + 1] ?? '') === '(') {
                    return true;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if (in_array($char, [';', '&', '|', '<', '>', '`'], true)) {
                return true;
            }

            if ($char === '$' && ($command[$index + 1] ?? '') === '(') {
                return true;
            }
        }

        return $quote !== null;
    }

    /**
     * Validate the write targets of a shell command.
     *
     * Parses redirect operators, write-oriented commands and `dd of=` in each
     * simple command, and resolves every target against each working
     * directory that command may run in (`cd` is tracked). A target that is a
     * protected control file is always refused. With sandboxWrites on, a
     * target must also stay inside the workspace or a read-write mount.
     *
     * This is a best-effort heuristic parser — it handles the common patterns
     * generated by LLMs but cannot parse all shell edge cases (eval, variable
     * indirection, process substitution, interpreters writing files). Defense-
     * in-depth via CatastrophicBlacklist and environment scrubbing covers some
     * of what the parser misses; exec is not an isolation boundary.
     *
     * @return string|null Error message if a write target is refused, null if clean
     */
    private function validateWriteTargets(string $command, string $effectiveCwd): ?string
    {
        if (!$this->sandboxWrites && $this->fs === null) {
            return null;
        }

        foreach ($this->simpleCommands($command, $effectiveCwd) as ['text' => $text, 'cwds' => $cwds]) {
            $targets = [
                ...$this->extractRedirectTargets($text),
                ...$this->extractWriteCommandTargets($text),
            ];
            if (preg_match('/\bdd\b.*\bof=([^\s]+)/i', $text, $m)) {
                $targets[] = $m[1];
            }

            foreach ($targets as $target) {
                $violation = $this->validateWriteTarget(trim($target, "'\""), $cwds);
                if ($violation !== null) {
                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string|null> $cwds Candidate working directories (null = unknown)
     */
    private function validateWriteTarget(string $target, array $cwds): ?string
    {
        if ($target === '' || $target === '/dev/null' || $target === '/dev/stderr' || $target === '/dev/stdout') {
            return null;
        }

        // Block paths containing variable expansion or command substitution — we
        // can't resolve them statically and they could point anywhere
        if (preg_match('/\$[\({a-zA-Z_]|`/', $target)) {
            return $this->sandboxWrites
                ? "Denied: shell write target contains variable expansion or command substitution: {$target}"
                : null;
        }

        foreach ($cwds as $cwd) {
            if ($cwd === null && !str_starts_with($target, '/') && !str_starts_with($target, '~')) {
                if ($this->sandboxWrites) {
                    return "Denied: cannot tell which directory this write lands in after a dynamic cd: {$target}";
                }
                continue;
            }

            $absolute = $this->absolutize($target, $cwd ?? '/');

            if ($this->fs !== null && $this->fs->isWriteProtectedPath($absolute)) {
                return "Denied: shell write target is a protected Coqui control file: {$target}";
            }

            if ($this->sandboxWrites) {
                $violation = $this->isPathOutsideSandbox($absolute, $target);
                if ($violation !== null) {
                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * Refuse a command when any word in it names a protected secret file.
     *
     * Each simple command is checked against the working directory it will
     * run in (`cd` is tracked), globs are expanded, and words are also split
     * on `=`, `<` and `>` so `if=.env` and `<.env` are caught. This is a
     * heuristic: a path built at run time (variables, base64, recursive
     * readers such as `grep -r .`) is not caught. See docs/FEATURES.md.
     */
    private function validateSecretReferences(string $command, string $effectiveCwd): ?string
    {
        if ($this->fs === null) {
            return null;
        }

        foreach ($this->simpleCommands($command, $effectiveCwd) as ['tokens' => $tokens, 'cwds' => $cwds]) {
            foreach ($tokens as $token) {
                foreach (preg_split('/[=<>]+/', $token) ?: [] as $word) {
                    if ($word === '' || str_starts_with($word, '-')) {
                        continue;
                    }

                    foreach ($cwds as $cwd) {
                        foreach ($this->candidatePaths($word, $cwd) as $path) {
                            if ($this->fs->isSecretPath($path)) {
                                return "Denied: command references a protected Coqui file (credentials or control state): {$word}";
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Split a command line into simple commands, each with the set of working
     * directories it may run in.
     *
     * Splits on unquoted `;`, `&`, `|`, newlines, parentheses and backticks.
     * A `cd`/`pushd` adds its target to the candidate set for every later
     * command (the old directories stay, since a subshell or `||` may not
     * have changed directory). A target that cannot be resolved statically
     * yields a null cwd, meaning "unknown".
     *
     * @return list<array{text: string, tokens: list<string>, cwds: list<string|null>}>
     */
    private function simpleCommands(string $command, string $effectiveCwd): array
    {
        $commands = [];
        $cwds = [$effectiveCwd];

        foreach ($this->splitOnOperators($command) as $segment) {
            $tokens = array_values($this->tokenizeCommand($segment));
            if ($tokens === []) {
                continue;
            }

            $commands[] = ['text' => $segment, 'tokens' => $tokens, 'cwds' => $cwds];

            if (in_array($tokens[0], ['cd', 'pushd'], true)) {
                $target = $tokens[1] ?? '~';
                foreach ($cwds as $cwd) {
                    $cwds[] = $this->resolveCdTarget($target, $cwd);
                }
                $cwds = array_values(array_unique($cwds, SORT_REGULAR));
            }
        }

        return $commands;
    }

    /**
     * @return list<string>
     */
    private function splitOnOperators(string $command): array
    {
        $segments = [];
        $current = '';
        $quote = null;
        $escapeNext = false;
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($escapeNext) {
                $current .= $char;
                $escapeNext = false;
                continue;
            }

            if ($char === '\\' && $quote !== "'") {
                $current .= $char;
                $escapeNext = true;
                continue;
            }

            if ($quote !== null) {
                $current .= $char;
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $current .= $char;
                continue;
            }

            // Redirection forms that contain & or |: >| >& &> 2>&1
            $prev = $current === '' ? '' : $current[strlen($current) - 1];
            if (($char === '|' || $char === '&') && $prev === '>') {
                $current .= $char;
                continue;
            }
            if ($char === '&' && ($command[$i + 1] ?? '') === '>') {
                $current .= $char;
                continue;
            }

            if (in_array($char, [';', '&', '|', "\n", "\r", '(', ')', '`', '{', '}'], true)) {
                $segments[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $segments[] = $current;

        return array_values(array_filter(array_map('trim', $segments), static fn(string $s): bool => $s !== ''));
    }

    /**
     * Resolve a `cd` argument against $cwd, or null when it is not static.
     */
    private function resolveCdTarget(string $target, ?string $cwd): ?string
    {
        if ($cwd === null || $target === '-' || preg_match('/[\$`*?\[]/', $target) === 1) {
            return null;
        }

        return $this->absolutize($target, $cwd);
    }

    /**
     * Absolute, lexically canonical paths $word may refer to from $cwd
     * (glob matches when it holds glob characters). A null cwd only resolves
     * absolute and home-relative words.
     *
     * @return list<string>
     */
    private function candidatePaths(string $word, ?string $cwd): array
    {
        if ($cwd === null && !str_starts_with($word, '/') && !str_starts_with($word, '~')) {
            return [];
        }

        $path = $this->absolutize($word, $cwd ?? '/');

        if (preg_match('/[*?\[]/', $word) === 1) {
            return glob($path, GLOB_NOSORT) ?: [];
        }

        return [$path];
    }

    /**
     * Make $path absolute against $cwd (expanding `~`) and canonicalise `.`
     * and `..` lexically.
     */
    private function absolutize(string $path, string $cwd): string
    {
        if (str_starts_with($path, '~/') || $path === '~') {
            $home = getenv('HOME') ?: '/';
            $path = $home . '/' . ltrim(substr($path, 1), '/');
        } elseif (!str_starts_with($path, '/')) {
            $path = $cwd . '/' . $path;
        }

        $resolved = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($resolved);
            } else {
                $resolved[] = $segment;
            }
        }

        return '/' . implode('/', $resolved);
    }

    /**
     * Extract output redirect targets from a command string.
     *
     * Parses the command character-by-character to respect quoting contexts,
     * then finds redirect operators and their target paths.
     *
     * @return string[]
     */
    private function extractRedirectTargets(string $command): array
    {
        $targets = [];
        $length = strlen($command);
        $quote = null;
        $escapeNext = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($escapeNext) {
                $escapeNext = false;
                continue;
            }

            // Track quote context
            if ($quote === "'") {
                if ($char === "'") {
                    $quote = null;
                }
                continue;
            }

            if ($char === '\\') {
                $escapeNext = true;
                continue;
            }

            if ($quote === '"') {
                if ($char === '"') {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            // Outside quotes: look for output redirect operators
            // Match: >, >>, 2>, 2>>, &>, &>>, 1>, 1>>
            if ($char === '>' || (($char === '1' || $char === '2' || $char === '&') && ($command[$i + 1] ?? '') === '>')) {
                $pos = $i;

                // Skip the operator chars
                if ($char === '1' || $char === '2' || $char === '&') {
                    $pos++; // skip digit/&
                }
                $pos++; // skip first >
                if (($command[$pos] ?? '') === '>') {
                    $pos++; // skip second > (append mode)
                } elseif (($command[$pos] ?? '') === '|') {
                    $pos++; // skip | (>| overrides noclobber)
                }

                // Skip whitespace after operator
                while ($pos < $length && $command[$pos] === ' ') {
                    $pos++;
                }

                // Extract the target path — up to next whitespace or shell metachar
                $target = '';
                $tQuote = null;
                while ($pos < $length) {
                    $tc = $command[$pos];
                    if ($tQuote === null && ($tc === "'" || $tc === '"')) {
                        $tQuote = $tc;
                        $pos++;
                        continue;
                    }
                    if ($tQuote !== null && $tc === $tQuote) {
                        $tQuote = null;
                        $pos++;
                        continue;
                    }
                    if ($tQuote === null && in_array($tc, [' ', "\t", ';', '|', '&', '<', '>', "\n"], true)) {
                        break;
                    }
                    $target .= $tc;
                    $pos++;
                }

                if ($target !== '') {
                    $targets[] = $target;
                }

                // Advance outer loop past what we consumed
                $i = $pos - 1;
            }
        }

        return $targets;
    }

    /**
     * Extract destination paths from write-oriented commands (cp, mv, tee, etc.).
     *
     * Uses a simple heuristic: the last non-option argument is the destination.
     * For tee, the path arguments after the command name are targets.
     *
     * @return string[]
     */
    private function extractWriteCommandTargets(string $command): array
    {
        $targets = [];

        // Split on pipe sequences to handle each pipeline segment
        $segments = preg_split('/\s*\|\s*/', $command) ?: [$command];

        foreach ($segments as $segment) {
            $segment = trim($segment);
            $tokens = $this->tokenizeCommand($segment);
            if ($tokens === []) {
                continue;
            }

            $cmd = basename($tokens[0]);

            if ($cmd === 'sed') {
                $targets = [...$targets, ...$this->sedInPlaceTargets($tokens)];
                continue;
            }

            if (!in_array($cmd, self::WRITE_COMMANDS, true)) {
                continue;
            }

            if ($cmd === 'tee') {
                // tee writes to all file arguments (skip options starting with -)
                for ($j = 1, $count = count($tokens); $j < $count; $j++) {
                    if (!str_starts_with($tokens[$j], '-')) {
                        $targets[] = $tokens[$j];
                    }
                }
            } else {
                // cp, mv, install, rsync, scp — last non-option arg is destination
                $nonOptions = array_values(array_filter(
                    array_slice($tokens, 1),
                    static fn(string $t): bool => !str_starts_with($t, '-'),
                ));
                if (count($nonOptions) >= 2) {
                    $targets[] = $nonOptions[count($nonOptions) - 1];
                }
            }
        }

        return $targets;
    }

    /**
     * File operands of a `sed -i` / `sed --in-place` command (none otherwise).
     *
     * @param string[] $tokens
     * @return string[]
     */
    private function sedInPlaceTargets(array $tokens): array
    {
        $inPlace = false;
        $scriptGiven = false;
        $operands = [];

        for ($j = 1, $count = count($tokens); $j < $count; $j++) {
            $token = $tokens[$j];

            if (in_array($token, ['-e', '--expression', '-f', '--file'], true)) {
                $scriptGiven = true;
                $j++; // skip the script / script file
                continue;
            }

            if (str_starts_with($token, '--')) {
                $inPlace = $inPlace || str_starts_with($token, '--in-place');
                $scriptGiven = $scriptGiven || str_starts_with($token, '--expression=') || str_starts_with($token, '--file=');
                continue;
            }

            if (str_starts_with($token, '-') && strlen($token) > 1) {
                // Short options cluster (-n, -E, -ni); -i takes an optional suffix (-i.bak)
                $letters = (string) preg_replace('/[^a-zA-Z].*$/', '', substr($token, 1));
                $inPlace = $inPlace || str_contains($letters, 'i');
                if (str_ends_with($letters, 'e') || str_ends_with($letters, 'f')) {
                    $scriptGiven = true;
                    $j++;
                }
                continue;
            }

            $operands[] = $token;
        }

        if (!$inPlace) {
            return [];
        }

        return $scriptGiven ? $operands : array_slice($operands, 1);
    }

    /**
     * Simple shell tokenizer that respects quoting.
     *
     * @return string[]
     */
    private function tokenizeCommand(string $command): array
    {
        $tokens = [];
        $current = '';
        $quote = null;
        $escapeNext = false;
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($escapeNext) {
                $current .= $char;
                $escapeNext = false;
                continue;
            }

            if ($char === '\\' && $quote !== "'") {
                $escapeNext = true;
                continue;
            }

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                    continue;
                }
                $current .= $char;
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === ' ' || $char === "\t") {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }
                continue;
            }

            $current .= $char;
        }

        if ($current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }

    /**
     * Check if an absolute, lexically canonical write target is outside the
     * sandbox boundary.
     *
     * @return string|null Error message if outside sandbox, null if within
     */
    private function isPathOutsideSandbox(string $canonicalized, string $target): ?string
    {
        if ($this->rootPath === null) {
            return null; // No sandbox configured
        }

        $realRoot = realpath($this->rootPath);
        if ($realRoot === false) {
            return null;
        }

        // Resolve symlinks when the parent directory exists (handles macOS /var → /private/var)
        $parentDir = dirname($canonicalized);
        $realParent = realpath($parentDir);
        if ($realParent !== false) {
            $canonicalized = $realParent . '/' . basename($canonicalized);
        }

        // Check if under workspace root
        if (PathHelper::isWithinBasePath($canonicalized, $realRoot)) {
            return null;
        }

        // Check against allowed mount paths (rw only — block writes to ro mounts)
        foreach ($this->allowedPaths as $allowed) {
            $realMountPath = realpath($allowed['realPath']);
            if ($realMountPath === false) {
                $realMountPath = $allowed['realPath'];
            }
            if (PathHelper::isWithinBasePath($canonicalized, $realMountPath)) {
                if ($allowed['readOnly']) {
                    return "Denied: write target is in a read-only mount: {$target}";
                }
                return null;
            }
        }

        return "Denied: shell write target escapes the workspace sandbox: {$target}";
    }

    /**
     * Build a sanitized environment for subprocess execution.
     *
     * Strips every variable whose name contains a sensitive pattern (KEY,
     * TOKEN, SECRET, etc.), whatever its prefix, except a few exact names
     * known to be harmless (GIT_AUTHOR_NAME). Everything else passes.
     *
     * @return array<string, string>
     */
    private function buildSanitizedEnvironment(): array
    {
        $env = getenv();

        $sanitized = [];

        foreach ($env as $name => $value) {
            $upperName = strtoupper($name);

            if (in_array($upperName, self::SENSITIVE_ENV_EXCEPTIONS, true)) {
                $sanitized[$name] = $value;
                continue;
            }

            $isSensitive = false;
            foreach (self::SENSITIVE_ENV_PATTERNS as $pattern) {
                if (str_contains($upperName, $pattern)) {
                    $isSensitive = true;
                    break;
                }
            }

            if (!$isSensitive) {
                $sanitized[$name] = $value;
            }
        }

        return $sanitized;
    }

    private function resolveCwd(?string $cwd): ?string
    {
        if ($cwd === null || $cwd === '') {
            return $this->workDir;
        }

        // Resolve relative paths against the default working directory
        if (!str_starts_with($cwd, '/')) {
            $cwd = $this->workDir . '/' . $cwd;
        }

        $resolved = realpath($cwd);

        if ($resolved === false || !is_dir($resolved)) {
            return null;
        }

        // Enforce sandbox: resolved cwd must be under the root path or an allowed mount
        if ($this->rootPath !== null) {
            $realRoot = realpath($this->rootPath);
            if ($realRoot !== false) {
                if (PathHelper::isWithinBasePath($resolved, $realRoot)) {
                    return $resolved;
                }

                // Check allowed mount paths
                foreach ($this->allowedPaths as $allowed) {
                    if (PathHelper::isWithinBasePath($resolved, $allowed['realPath'])) {
                        return $resolved;
                    }
                }

                // Path escapes sandbox
                return null;
            }
        }

        return $resolved;
    }
}
