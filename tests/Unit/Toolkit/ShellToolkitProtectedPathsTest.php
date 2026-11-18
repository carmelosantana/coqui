<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Toolkit\ShellToolkit;

beforeEach(function () {
    $this->workDir = sys_get_temp_dir() . '/coqui-shell-protected-' . bin2hex(random_bytes(8));
    mkdir($this->workDir . '/data', 0755, true);
    mkdir($this->workDir . '/sub', 0755, true);
    file_put_contents($this->workDir . '/.env', "OPENAI_API_KEY=sk-test123\n");
    file_put_contents($this->workDir . '/openclaw.json', '{}');
    file_put_contents($this->workDir . '/data/coqui.db', 'sqlite');
    file_put_contents($this->workDir . '/notes.txt', 'hello');

    $this->exec = function (string $command, ?string $cwd = null): ToolResult {
        $toolkit = new ShellToolkit(workDir: $this->workDir, rootPath: $this->workDir);
        $input = ['command' => $command];
        if ($cwd !== null) {
            $input['cwd'] = $cwd;
        }

        return $toolkit->tools()[0]->execute($input);
    };
});

afterEach(function () {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->workDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->workDir);
});

// ---------------------------------------------------------------
// #1 — exec refuses commands that name a secret file
// ---------------------------------------------------------------

test('exec refuses to read a secret file', function (string $command, ?string $cwd = null) {
    $result = ($this->exec)(str_replace('{root}', $this->workDir, $command), $cwd);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('protected')
        ->and($result->content)->not->toContain('sk-test123');
})->with([
    'cat .env' => ['cat .env'],
    'quoted' => ['cat ".env"'],
    'dot-slash' => ['cat ./.env'],
    'absolute' => ['cat {root}/.env'],
    'from a sub dir' => ['cat ../.env', 'sub'],
    'glob' => ['cat .en?'],
    'star glob' => ['head -n 5 .e*'],
    'input redirect' => ['while read l; do echo $l; done < .env'],
    'option value' => ['dd if=.env status=none'],
    'chained' => ['ls && cat openclaw.json'],
    'database' => ['sqlite3 data/coqui.db .dump'],
    'cd then read' => ['cd sub && cat ../.env'],
]);

test('exec still runs commands that touch ordinary files', function () {
    $result = ($this->exec)('cat notes.txt');

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('hello');
});

test('exec still allows a mention of .env inside unrelated text', function () {
    $result = ($this->exec)('echo "copy .env.example to get started"');

    expect($result->status)->toBe(ToolResultStatus::Success);
});

// ---------------------------------------------------------------
// #7 — exec cannot write Coqui's control files
// ---------------------------------------------------------------

test('exec refuses to write a control file', function (string $command, bool $sandboxWrites = true) {
    mkdir($this->workDir . '/vendor', 0755, true);
    mkdir($this->workDir . '/roles', 0755, true);
    file_put_contents($this->workDir . '/vendor/autoload.php', '<?php // original');
    file_put_contents($this->workDir . '/roles/coder.md', 'original');
    file_put_contents($this->workDir . '/mcp.json', '{}');

    $toolkit = new ShellToolkit(workDir: $this->workDir, rootPath: $this->workDir, sandboxWrites: $sandboxWrites);
    $result = $toolkit->tools()[0]->execute(['command' => $command]);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('protected')
        ->and(file_get_contents($this->workDir . '/vendor/autoload.php'))->toBe('<?php // original')
        ->and(file_get_contents($this->workDir . '/roles/coder.md'))->toBe('original')
        ->and(file_get_contents($this->workDir . '/mcp.json'))->toBe('{}');
})->with([
    'redirect into vendor' => ['echo "<?php system(1);" > vendor/autoload.php'],
    'append to a role' => ['echo "access_level: full" >> roles/coder.md'],
    'noclobber override' => ['echo "{}" >| mcp.json'],
    'tee' => ['echo "{}" | tee mcp.json'],
    'cp over a role' => ['cp notes.txt roles/coder.md'],
    'cd then redirect' => ['cd vendor && echo x > autoload.php'],
    'with sandboxWrites off' => ['echo x > mcp.json', false],
]);
