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
