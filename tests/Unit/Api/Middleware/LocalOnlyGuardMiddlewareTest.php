<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Middleware\CorsMiddleware;
use CoquiBot\Coqui\Api\Middleware\LocalOnlyGuardMiddleware;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;

function localGuardRun(LocalOnlyGuardMiddleware $guard, ServerRequest $request, bool &$called = false): Response
{
    return $guard($request, function () use (&$called) {
        $called = true;

        return new Response(200, [], 'ok');
    });
}

test('lets loopback Host values through', function (string $host) {
    $called = false;
    $request = new ServerRequest('POST', "http://{$host}/api/v1/sessions", ['Host' => $host]);

    $response = localGuardRun(new LocalOnlyGuardMiddleware(), $request, $called);

    expect($called)->toBeTrue()->and($response->getStatusCode())->toBe(200);
})->with(['127.0.0.1:3300', '127.0.0.1', 'localhost:3300', 'LOCALHOST', '[::1]:3300']);

test('refuses a rebinding Host header', function (string $host) {
    $called = false;
    $request = new ServerRequest('GET', 'http://127.0.0.1:3300/api/v1/sessions', ['Host' => $host]);

    $response = localGuardRun(new LocalOnlyGuardMiddleware(), $request, $called);

    expect($called)->toBeFalse()->and($response->getStatusCode())->toBe(403);
})->with(['evil.example:3300', 'localhost.evil.example', '127.0.0.1.nip.io', '']);

test('refuses a browser request from a foreign Origin', function () {
    $called = false;
    $request = new ServerRequest('POST', 'http://127.0.0.1:3300/api/v1/sessions', [
        'Host' => '127.0.0.1:3300',
        'Origin' => 'https://evil.example',
        'Content-Type' => 'multipart/form-data; boundary=x',
    ]);

    $response = localGuardRun(new LocalOnlyGuardMiddleware(), $request, $called);

    expect($called)->toBeFalse()->and($response->getStatusCode())->toBe(403);
});

test('allows an Origin the operator listed', function () {
    $called = false;
    $request = new ServerRequest('POST', 'http://127.0.0.1:3300/api/v1/sessions', [
        'Host' => '127.0.0.1:3300',
        'Origin' => 'http://localhost:3000',
    ]);

    localGuardRun(new LocalOnlyGuardMiddleware(['http://localhost:3000']), $request, $called);

    expect($called)->toBeTrue();
});

test('without an API key, CORS is off unless the operator asks for it', function () {
    expect(CorsMiddleware::resolveOrigins(null, hasApiKey: false))->toBe([])
        ->and(CorsMiddleware::resolveOrigins(null, hasApiKey: true))->toBe(['*'])
        ->and(CorsMiddleware::resolveOrigins('http://localhost:3000, https://app.example', hasApiKey: false))
            ->toBe(['http://localhost:3000', 'https://app.example']);
});
