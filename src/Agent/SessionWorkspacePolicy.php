<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PathHelper\PathHelper;
use CoquiBot\Coqui\Config\MountManager;

/**
 * Decides which directories a session may use as its workspace root.
 *
 * A session's workspace becomes the filesystem and shell sandbox root, so it
 * must be the instance workspace, a directory inside it, or a directory in a
 * read-write mount. Read-only mounts are refused: inside the sandbox root
 * the agent may write, which would undo the mount's read-only flag.
 */
final readonly class SessionWorkspacePolicy
{
    /**
     * @param array<int, array{realPath: string, readOnly: bool}> $allowedPaths Mounts, as MountManager::allowedPaths() returns them.
     */
    public function __construct(
        private string $defaultWorkspace,
        private array $allowedPaths = [],
    ) {}

    public static function fromMountManager(string $defaultWorkspace, ?MountManager $mountManager): self
    {
        return new self($defaultWorkspace, $mountManager?->allowedPaths() ?? []);
    }

    /**
     * True when $path is an existing directory inside the workspace or a
     * read-write mount (after resolving symlinks and `..`).
     */
    public function allows(string $path): bool
    {
        if (!PathHelper::isAbsolutePath($path)) {
            return false;
        }

        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            return false;
        }

        $root = realpath($this->defaultWorkspace);
        if ($root !== false && PathHelper::isWithinBasePath($real, $root)) {
            return true;
        }

        foreach ($this->allowedPaths as $allowed) {
            if (!$allowed['readOnly'] && PathHelper::isWithinBasePath($real, $allowed['realPath'])) {
                return true;
            }
        }

        return false;
    }
}
