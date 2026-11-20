<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * D3: resolves the effective filesystem/shell root for a session. A session's
 * opaque `workspace` (when set) wins; otherwise the instance-global workspace
 * is used. Loop stages and child runs inherit whatever a session resolves to.
 * With a policy, a stored workspace the policy refuses resolves to the default.
 */
final readonly class SessionWorkspaceResolver
{
    public function __construct(
        private SessionStorage $storage,
        private string $defaultWorkspace,
        private ?SessionWorkspacePolicy $policy = null,
    ) {}

    public function resolve(?string $sessionId): string
    {
        if ($sessionId === null) {
            return $this->defaultWorkspace;
        }

        $session = $this->storage->getSession($sessionId);
        $workspace = $session['workspace'] ?? null;

        if (!is_string($workspace) || $workspace === '') {
            return $this->defaultWorkspace;
        }

        // A row written before the PATCH check existed (or by another path)
        // must not widen the sandbox: fall back to the instance workspace.
        if ($this->policy !== null && !$this->policy->allows($workspace)) {
            return $this->defaultWorkspace;
        }

        return $workspace;
    }
}
