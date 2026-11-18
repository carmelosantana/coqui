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
