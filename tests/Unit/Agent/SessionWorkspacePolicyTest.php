<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\SessionWorkspacePolicy;
use CoquiBot\Coqui\Agent\SessionWorkspaceResolver;
use CoquiBot\Coqui\Api\Session\SessionUpdateRequest;
use CoquiBot\Coqui\Api\Session\SessionUpdateRequestResolver;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\Message\Response;

beforeEach(function () {
    $this->base = sys_get_temp_dir() . '/coqui-ws-policy-' . bin2hex(random_bytes(6));
    mkdir($this->base . '/ws/projects/app', 0755, true);
    mkdir($this->base . '/ws-other', 0755, true);
    mkdir($this->base . '/rw-mount/repo', 0755, true);
    mkdir($this->base . '/ro-mount', 0755, true);

    $this->policy = new SessionWorkspacePolicy($this->base . '/ws', [
        ['realPath' => realpath($this->base . '/rw-mount'), 'readOnly' => false],
        ['realPath' => realpath($this->base . '/ro-mount'), 'readOnly' => true],
    ]);
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->base));
});

test('allows the workspace, its sub directories and read-write mounts', function () {
    expect($this->policy->allows($this->base . '/ws'))->toBeTrue()
        ->and($this->policy->allows($this->base . '/ws/projects/app'))->toBeTrue()
        ->and($this->policy->allows($this->base . '/rw-mount/repo'))->toBeTrue();
});

test('refuses the filesystem root, siblings, read-only mounts and missing paths', function () {
    expect($this->policy->allows('/'))->toBeFalse()
        ->and($this->policy->allows($this->base . '/ws-other'))->toBeFalse()
        ->and($this->policy->allows($this->base . '/ws/../ws-other'))->toBeFalse()
        ->and($this->policy->allows($this->base . '/ro-mount'))->toBeFalse()
        ->and($this->policy->allows($this->base . '/ws/missing'))->toBeFalse()
        ->and($this->policy->allows('relative/path'))->toBeFalse();
});

test('session PATCH rejects a workspace outside the workspace and mounts', function () {
    $resolver = new SessionUpdateRequestResolver($this->policy);

    $rejected = $resolver->resolve(['workspace' => '/']);
    $accepted = $resolver->resolve(['workspace' => $this->base . '/ws/projects/app']);

    expect($rejected)->toBeInstanceOf(Response::class)
        ->and($rejected->getStatusCode())->toBe(422)
        ->and($accepted)->toBeInstanceOf(SessionUpdateRequest::class);
});

test('a stored workspace outside the policy falls back to the default at run time', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-wspol-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    try {
        $resolver = new SessionWorkspaceResolver($storage, $this->base . '/ws', $this->policy);

        $escaped = $storage->createSession('orchestrator', null, 'caelum', workspace: '/');
        $rooted = $storage->createSession('orchestrator', null, 'caelum', workspace: $this->base . '/rw-mount/repo');

        expect($resolver->resolve($escaped))->toBe($this->base . '/ws')
            ->and($resolver->resolve($rooted))->toBe($this->base . '/rw-mount/repo');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});
