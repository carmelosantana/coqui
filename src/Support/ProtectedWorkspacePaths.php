<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

/**
 * Workspace paths the agent's file tools must not touch.
 *
 * Coqui keeps its credentials and control files inside the same workspace the
 * agent uses as its sandbox root. These rules carve them out:
 *
 * - Secrets (no read, no write): `.env`, `.mcp-tokens/`, `data/*.db` (plus the
 *   SQLite `-wal`, `-shm` and `-journal` side files) and `openclaw.json`.
 * - Control files (read allowed, no write): everything under `vendor/` (the
 *   workspace autoloader is required into the Coqui process at boot),
 *   `mcp.json` (spawns stdio servers) and `roles/*.md` (role access levels).
 *
 * All paths are relative to the sandbox root, compared case-insensitively so
 * a case-insensitive filesystem cannot be used to sidestep them.
 */
final class ProtectedWorkspacePaths
{
    /** @var list<string> Directories whose whole subtree is secret. */
    private const array SECRET_DIRS = ['.mcp-tokens'];

    /** @var list<string> Single files that are secret. */
    private const array SECRET_FILES = ['.env', 'openclaw.json'];

    /** @var list<string> Directories whose whole subtree is write-protected. */
    private const array CONTROL_DIRS = ['vendor'];

    /** @var list<string> Single files that are write-protected. */
    private const array CONTROL_FILES = ['mcp.json'];

    /** Matches roles/<name>.md. */
    private const string CONTROL_ROLE_PATTERN = '#^roles/[^/]+\.md$#';

    /** Matches data/<name>.db and its SQLite side files. */
    private const string SECRET_DB_PATTERN = '#^data/[^/]+\.db(?:-wal|-shm|-journal)?$#';

    /**
     * True when the agent must neither read nor write $relativePath.
     */
    public static function isSecret(string $relativePath): bool
    {
        $path = self::canonical($relativePath);
        if ($path === '') {
            return false;
        }

        if (in_array($path, self::SECRET_FILES, true)) {
            return true;
        }

        foreach (self::SECRET_DIRS as $dir) {
            if ($path === $dir || str_starts_with($path, $dir . '/')) {
                return true;
            }
        }

        return preg_match(self::SECRET_DB_PATTERN, $path) === 1;
    }

    /**
     * True when $relativePath is the root or a directory that contains a
     * secret, so copying or moving it would carry the secret along.
     */
    public static function containsSecret(string $relativePath): bool
    {
        $path = self::canonical($relativePath);

        return $path === '' || $path === 'data' || self::isSecret($path);
    }

    /**
     * True when the agent must not create, change or delete $relativePath.
     * Every secret is also write-protected.
     */
    public static function isWriteProtected(string $relativePath): bool
    {
        $path = self::canonical($relativePath);
        if ($path === '') {
            return false;
        }

        if (self::isSecret($path) || in_array($path, self::CONTROL_FILES, true)) {
            return true;
        }

        foreach (self::CONTROL_DIRS as $dir) {
            if ($path === $dir || str_starts_with($path, $dir . '/')) {
                return true;
            }
        }

        return preg_match(self::CONTROL_ROLE_PATTERN, $path) === 1;
    }

    /**
     * True when replacing, moving or deleting $relativePath as a whole would
     * change a write-protected path: the root, a directory that holds
     * protected files, or a protected path itself.
     */
    public static function containsWriteProtected(string $relativePath): bool
    {
        $path = self::canonical($relativePath);

        return self::containsSecret($path) || $path === 'roles' || self::isWriteProtected($path);
    }

    /**
     * Lower-case, forward-slash, no leading `./` or `/`, no trailing `/`.
     */
    private static function canonical(string $relativePath): string
    {
        $path = strtolower(str_replace('\\', '/', $relativePath));
        $segments = array_values(array_filter(
            explode('/', $path),
            static fn(string $s): bool => $s !== '' && $s !== '.',
        ));

        return implode('/', $segments);
    }
}
