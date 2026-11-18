<?php

declare(strict_types=1);

use CoquiBot\Coqui\Support\FileSystemException;
use CoquiBot\Coqui\Support\FileSystemOperations;

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/coqui-fs-protected-' . bin2hex(random_bytes(8));
    mkdir($this->root . '/data', 0755, true);
    mkdir($this->root . '/.mcp-tokens', 0755, true);
    file_put_contents($this->root . '/.env', "OPENAI_API_KEY=sk-test123\nCOQUI_API_KEY=abc\n");
    file_put_contents($this->root . '/openclaw.json', '{"api":{"key":"SECRETKEY"}}');
    file_put_contents($this->root . '/data/coqui.db', 'sqlite');
    file_put_contents($this->root . '/data/coqui.db-wal', 'wal');
    file_put_contents($this->root . '/data/notes.json', '{}');
    file_put_contents($this->root . '/.mcp-tokens/github.json', '{"token":"ghp_x"}');
    $this->fs = new FileSystemOperations($this->root);
});

afterEach(function () {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->root);
});

// ---------------------------------------------------------------
// #1 — secrets are neither readable nor writable
// ---------------------------------------------------------------

test('secret files cannot be read', function (string $path) {
    $path = str_replace('{root}', $this->root, $path);

    expect(fn() => $this->fs->read($path))
        ->toThrow(FileSystemException::class, 'protected');
})->with([
    '.env' => '.env',
    'dot-slash .env' => './.env',
    'traversal to .env' => 'data/../.env',
    'absolute .env' => '{root}/.env',
    'upper-case .ENV' => '.ENV',
    'openclaw.json' => 'openclaw.json',
    'sqlite db' => 'data/coqui.db',
    'sqlite wal' => 'data/coqui.db-wal',
    'mcp token file' => '.mcp-tokens/github.json',
]);

test('secret files cannot be overwritten, appended to or deleted', function () {
    expect(fn() => $this->fs->write('.env', 'X=1'))->toThrow(FileSystemException::class, 'protected');
    expect(fn() => $this->fs->append('.env', 'X=1'))->toThrow(FileSystemException::class, 'protected');
    expect(fn() => $this->fs->delete('data/coqui.db'))->toThrow(FileSystemException::class, 'protected');
    expect(fn() => $this->fs->write('.mcp-tokens/new.json', '{}'))->toThrow(FileSystemException::class, 'protected');

    expect(file_get_contents($this->root . '/.env'))->toContain('sk-test123');
});

test('a symlink pointing at a secret cannot be used to read it', function () {
    symlink($this->root . '/.env', $this->root . '/innocent.txt');

    expect(fn() => $this->fs->read('innocent.txt'))
        ->toThrow(FileSystemException::class, 'protected');
});

test('a directory holding secrets cannot be copied or moved out', function () {
    expect(fn() => $this->fs->copyPath('data', 'stolen'))->toThrow(FileSystemException::class, 'protected');
    expect(fn() => $this->fs->copyPath('.', 'stolen'))->toThrow(FileSystemException::class, 'protected');
    expect(fn() => $this->fs->movePath('data', 'stolen'))->toThrow(FileSystemException::class, 'protected');
    expect(fn() => $this->fs->copyPath('.env', 'env.txt'))->toThrow(FileSystemException::class, 'protected');

    expect(is_dir($this->root . '/stolen'))->toBeFalse();
});

test('glob results leave secret files out', function () {
    $glob = fn(string $pattern): array => array_map(
        fn(string $p) => $this->fs->makeRelative($p),
        $this->fs->resolveGlob($pattern),
    );

    expect($glob('data/*'))->toContain('data/notes.json')->not->toContain('data/coqui.db');
    expect($glob('*.json'))->not->toContain('openclaw.json');
    expect($glob('.*'))->not->toContain('.env');
    expect($glob('**/*.json'))->toContain('data/notes.json')
        ->not->toContain('.mcp-tokens/github.json')
        ->not->toContain('openclaw.json');
});

test('ordinary files beside the secrets stay readable and writable', function () {
    $this->fs->write('data/notes.json', '{"ok":true}');
    $this->fs->write('.env.example', 'OPENAI_API_KEY=');

    expect($this->fs->read('data/notes.json'))->toBe('{"ok":true}')
        ->and($this->fs->read('.env.example'))->toBe('OPENAI_API_KEY=');
});
