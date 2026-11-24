<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\ProcessCancellationToken;

test('onCancel listeners run when token is cancelled', function () {
    $token = new ProcessCancellationToken();
    $calls = 0;

    $token->onCancel(static function () use (&$calls): void {
        $calls++;
    });

    $token->cancel();

    expect($token->isCancelled())->toBeTrue()
        ->and($calls)->toBe(1);
});

test('onCancel listener runs immediately when token already cancelled', function () {
    $token = new ProcessCancellationToken();
    $token->cancel();
    $calls = 0;

    $token->onCancel(static function () use (&$calls): void {
        $calls++;
    });

    expect($calls)->toBe(1);
});

test('cancel is idempotent', function () {
    $token = new ProcessCancellationToken();
    $calls = 0;

    $token->onCancel(static function () use (&$calls): void {
        $calls++;
    });

    $token->cancel();
    $token->cancel();

    expect($calls)->toBe(1);
});
test('a cancelled token stays cancelled: nothing can reset it within a run', function () {
    // php-agents 0.18.1 latches the first cancel for the rest of run(); Coqui
    // relies on a fresh token per run, so the token itself offers no reset.
    $token = new ProcessCancellationToken();
    $token->cancel();

    $publicMethods = array_map(
        static fn(ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass($token))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    expect($token->isCancelled())->toBeTrue()
        ->and($token->isCancelled())->toBeTrue()
        ->and($publicMethods)->toEqualCanonicalizing(['cancel', 'isCancelled', 'onCancel']);
});
