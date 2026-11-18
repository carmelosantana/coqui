<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Support\FileSystemOperations;
use CoquiBot\Coqui\Toolkit\ShellToolkit;

beforeEach(function () {
    $this->base = sys_get_temp_dir() . '/coqui-shell-gaps-' . bin2hex(random_bytes(6));
    $this->ws = $this->base . '/ws';
    mkdir($this->ws . '/sub', 0755, true);
    mkdir($this->base . '/ws-other', 0755, true);
    file_put_contents($this->ws . '/notes.txt', 'a');
    file_put_contents($this->base . '/ws-other/secret.txt', 'other project');

    $this->exec = function (string $command, ?string $cwd = null): ToolResult {
        $toolkit = new ShellToolkit(workDir: $this->ws, rootPath: $this->ws);
        $input = ['command' => str_replace('{base}', $this->base, $command)];
        if ($cwd !== null) {
            $input['cwd'] = str_replace('{base}', $this->base, $cwd);
        }

        return $toolkit->tools()[0]->execute($input);
    };
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->base));
});

// ---------------------------------------------------------------
// cd is tracked by the write check
// ---------------------------------------------------------------

test('a write after cd out of the sandbox is refused', function (string $command) {
    $result = ($this->exec)($command);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Denied')
        ->and(file_exists($this->base . '/pwned.txt'))->toBeFalse();
})->with([
    'cd to a parent' => ['cd .. && echo pwned >> pwned.txt'],
    'cd absolute' => ['cd {base}; echo pwned > pwned.txt'],
    'cd to home' => ['cd ~ && echo pwned >> .bashrc'],
    'cd with a variable' => ['cd $HOME && echo pwned > pwned.txt'],
    'cd dash' => ['cd - && echo pwned > pwned.txt'],
    'pushd' => ['pushd .. && cp notes.txt pwned.txt'],
]);

test('a write after cd into a sub directory is still allowed', function () {
    $result = ($this->exec)('cd sub && echo ok > out.txt');

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and(file_get_contents($this->ws . '/sub/out.txt'))->toBe("ok\n");
});

// ---------------------------------------------------------------
// Inline interpreters and sed -i
// ---------------------------------------------------------------

test('inline shell and interpreter code is refused', function (string $command) {
    $result = ($this->exec)($command);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Denied');
})->with([
    "sh -c 'echo x > /tmp/y'",
    'bash -c "id"',
    'bash -lc "id"',
    "zsh -c 'id'",
    "python3 -c 'print(1)'",
    "python -c 'print(1)'",
    "perl -e 'print 1'",
    "perl -pi -e 's/a/b/' notes.txt",
    "ruby -e 'puts 1'",
    "node -e 'console.log(1)'",
    "env bash -c 'id'",
]);

test('sed -i outside the sandbox is refused', function (string $command) {
    $result = ($this->exec)($command);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Denied')
        ->and(file_get_contents($this->base . '/ws-other/secret.txt'))->toBe('other project');
})->with([
    "sed -i 's/other/pwned/' {base}/ws-other/secret.txt",
    "sed -i.bak -e 's/other/pwned/' ../ws-other/secret.txt",
    "sed --in-place 's/other/pwned/' ../ws-other/secret.txt",
]);

test('sed -i inside the sandbox still works', function () {
    $result = ($this->exec)("sed -i 's/a/b/' notes.txt");

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and(file_get_contents($this->ws . '/notes.txt'))->toBe('b');
});

// ---------------------------------------------------------------
// /ws must not match /ws-other
// ---------------------------------------------------------------

test('a sibling directory that shares the root prefix is outside the sandbox', function () {
    $cwd = ($this->exec)('pwd', '{base}/ws-other');
    $write = ($this->exec)('echo pwned > {base}/ws-other/new.txt');

    expect($cwd->status)->toBe(ToolResultStatus::Error)
        ->and($cwd->content)->toContain('Invalid working directory')
        ->and($write->status)->toBe(ToolResultStatus::Error)
        ->and(file_exists($this->base . '/ws-other/new.txt'))->toBeFalse();
});

test('glob does not list a sibling directory that shares the root prefix', function () {
    $fs = new FileSystemOperations($this->ws);

    expect($fs->resolveGlob('../ws-other/*'))->toBe([]);
});

// ---------------------------------------------------------------
// Environment scrubbing
// ---------------------------------------------------------------

test('sensitive variables are scrubbed even under a safe prefix', function () {
    $vars = [
        'SSH_AUTH_SOCK' => '/tmp/agent.sock',
        'NPM_TOKEN' => 'npm_secret',
        'COMPOSER_AUTH' => '{"github-oauth":{}}',
        'DOCKER_AUTH_CONFIG' => 'docker_secret',
        'GIT_ASKPASS_TOKEN' => 'git_secret',
        'GIT_AUTHOR_NAME' => 'Ada',
        'GIT_COMMITTER_EMAIL' => 'ada@example.test',
    ];
    foreach ($vars as $name => $value) {
        putenv("{$name}={$value}");
    }

    try {
        $result = ($this->exec)('env');
    } finally {
        foreach (array_keys($vars) as $name) {
            putenv($name);
        }
    }

    $stdout = json_decode($result->content, true)['stdout'];
    expect($stdout)->not->toContain('SSH_AUTH_SOCK')
        ->not->toContain('npm_secret')
        ->not->toContain('github-oauth')
        ->not->toContain('docker_secret')
        ->not->toContain('git_secret')
        ->toContain('GIT_AUTHOR_NAME=Ada')
        ->toContain('GIT_COMMITTER_EMAIL=ada@example.test');
});
