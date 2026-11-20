<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CoquiBot\Coqui\Provider\FallbackProvider;

/**
 * Create a stub provider that succeeds with the given content.
 */
function stubProvider(string $model, string $content = 'ok'): ProviderInterface
{
    return new class ($model, $content) implements ProviderInterface {
        use \CarmeloSantana\PHPAgents\Provider\Stream\StreamsEventsFromResponses;

        public function __construct(
            private readonly string $model,
            private readonly string $content,
        ) {}

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            return new Response(content: $this->content, finishReason: ProviderFinishReason::Stop, model: $this->model);
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            yield new Response(content: $this->content, finishReason: ProviderFinishReason::Stop, model: $this->model);
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            return ['result' => $this->content];
        }

        public function models(): array
        {
            return [];
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function getModel(): string
        {
            return $this->model;
        }

        public function withModel(string $model): static
        {
            return $this;
        }
    };
}

/**
 * Create a stub provider that throws the given exception.
 */
function failingProvider(string $model, \Throwable $error): ProviderInterface
{
    return new class ($model, $error) implements ProviderInterface {
        use \CarmeloSantana\PHPAgents\Provider\Stream\StreamsEventsFromResponses;

        public function __construct(
            private readonly string $model,
            private readonly \Throwable $error,
        ) {}

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            throw $this->error;
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            throw $this->error;
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            throw $this->error;
        }

        public function models(): array
        {
            return [];
        }

        public function isAvailable(): bool
        {
            return false;
        }

        public function getModel(): string
        {
            return $this->model;
        }

        public function withModel(string $model): static
        {
            return $this;
        }
    };
}

// --- chat() tests ---

test('chat returns primary response when primary succeeds', function () {
    $primary = stubProvider('primary-model', 'primary response');
    $fallback = stubProvider('fallback-model', 'fallback response');

    $provider = new FallbackProvider($primary, [$fallback]);
    $response = $provider->chat([]);

    expect($response->content)->toBe('primary response');
    expect($response->model)->toBe('primary-model');
});

test('chat falls through to fallback on retryable error', function () {
    $primary = failingProvider('primary-model', new \RuntimeException('HTTP 429 Too Many Requests'));
    $fallback = stubProvider('fallback-model', 'fallback response');

    $provider = new FallbackProvider($primary, [$fallback]);
    $response = $provider->chat([]);

    expect($response->content)->toBe('fallback response');
    expect($response->model)->toBe('fallback-model');
});

test('chat throws immediately on non-retryable error', function () {
    $primary = failingProvider('primary-model', new \RuntimeException('HTTP 401 Unauthorized'));
    $fallback = stubProvider('fallback-model', 'fallback response');

    $provider = new FallbackProvider($primary, [$fallback]);
    $provider->chat([]);
})->throws(\RuntimeException::class, 'HTTP 401 Unauthorized');

test('chat throws original error when all fallbacks fail', function () {
    $primary = failingProvider('primary-model', new \RuntimeException('HTTP 500 Internal Server Error'));
    $fallback1 = failingProvider('fallback-1', new \RuntimeException('HTTP 502 Bad Gateway'));
    $fallback2 = failingProvider('fallback-2', new \RuntimeException('HTTP 503 Service Unavailable'));

    $provider = new FallbackProvider($primary, [$fallback1, $fallback2]);
    $provider->chat([]);
})->throws(\RuntimeException::class, 'HTTP 500 Internal Server Error');

test('chat tries second fallback when first fails', function () {
    $primary = failingProvider('primary-model', new \RuntimeException('HTTP 500 Internal Server Error'));
    $fallback1 = failingProvider('fallback-1', new \RuntimeException('HTTP 502 Bad Gateway'));
    $fallback2 = stubProvider('fallback-2', 'second fallback');

    $provider = new FallbackProvider($primary, [$fallback1, $fallback2]);
    $response = $provider->chat([]);

    expect($response->content)->toBe('second fallback');
    expect($response->model)->toBe('fallback-2');
});

// --- stream() tests ---

test('stream yields primary chunks when primary succeeds', function () {
    $primary = stubProvider('primary-model', 'streamed');
    $fallback = stubProvider('fallback-model', 'fallback streamed');

    $provider = new FallbackProvider($primary, [$fallback]);
    $chunks = iterator_to_array($provider->stream([]));

    expect($chunks)->toHaveCount(1);
    expect($chunks[0]->content)->toBe('streamed');
});

test('stream falls through to fallback on retryable error', function () {
    $primary = failingProvider('primary-model', new \RuntimeException('HTTP 502 Bad Gateway'));
    $fallback = stubProvider('fallback-model', 'fallback streamed');

    $provider = new FallbackProvider($primary, [$fallback]);
    $chunks = iterator_to_array($provider->stream([]));

    expect($chunks)->toHaveCount(1);
    expect($chunks[0]->content)->toBe('fallback streamed');
});

test('stream throws immediately on non-retryable error', function () {
    $primary = failingProvider('primary-model', new \RuntimeException('HTTP 403 Forbidden'));
    $fallback = stubProvider('fallback-model', 'fallback');

    $provider = new FallbackProvider($primary, [$fallback]);
    iterator_to_array($provider->stream([]));
})->throws(\RuntimeException::class, 'HTTP 403 Forbidden');

// --- onNotify callback tests ---

test('onNotify receives fallback start and success messages', function () {
    $messages = [];
    $primary = failingProvider('primary-model', new \RuntimeException('HTTP 429 Too Many Requests'));
    $fallback = stubProvider('fallback-model', 'ok');

    $provider = new FallbackProvider($primary, [$fallback]);
    $provider->setOnNotify(function (string $msg) use (&$messages) {
        $messages[] = $msg;
    });

    $provider->chat([]);

    expect($messages)->toHaveCount(2);
    expect($messages[0])->toContain('Primary provider failed (rate_limited)');
    expect($messages[0])->toContain('1 fallback model(s)');
    expect($messages[1])->toContain('Fallback to fallback-model succeeded');
});

test('onNotify receives failure messages for each failed fallback', function () {
    $messages = [];
    $primary = failingProvider('primary-model', new \RuntimeException('HTTP 500 Server Error'));
    $fallback1 = failingProvider('fallback-1', new \RuntimeException('HTTP 502 Bad Gateway'));
    $fallback2 = failingProvider('fallback-2', new \RuntimeException('HTTP 503 Unavailable'));

    $provider = new FallbackProvider($primary, [$fallback1, $fallback2]);
    $provider->setOnNotify(function (string $msg) use (&$messages) {
        $messages[] = $msg;
    });

    try {
        $provider->chat([]);
    } catch (\RuntimeException) {
        // expected
    }

    expect($messages)->toHaveCount(3);
    expect($messages[0])->toContain('Primary provider failed');
    expect($messages[1])->toContain('fallback-1 also failed');
    expect($messages[2])->toContain('fallback-2 also failed');
});

// --- Delegation tests ---

test('getModel returns primary model', function () {
    $primary = stubProvider('my-model');
    $provider = new FallbackProvider($primary, []);

    expect($provider->getModel())->toBe('my-model');
});

test('isAvailable delegates to primary', function () {
    $primary = stubProvider('model');
    $provider = new FallbackProvider($primary, []);

    expect($provider->isAvailable())->toBeTrue();
});

function partialStreamProvider(string $model, string $partial, \Throwable $error): ProviderInterface
{
    return new class ($model, $partial, $error) implements ProviderInterface {
        use \CarmeloSantana\PHPAgents\Provider\Stream\StreamsEventsFromResponses;

        public function __construct(
            private readonly string $model,
            private readonly string $partial,
            private readonly \Throwable $error,
        ) {}

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            throw $this->error;
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            yield new Response(content: $this->partial, finishReason: ProviderFinishReason::Stop, model: $this->model);
            throw $this->error;
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            return null;
        }

        public function models(): array
        {
            return [];
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function getModel(): string
        {
            return $this->model;
        }

        public function withModel(string $model): static
        {
            return $this;
        }
    };
}

test('stream does not move to a second fallback once a fallback has yielded a chunk', function () {
    $provider = new FallbackProvider(
        failingProvider('primary', new \RuntimeException('HTTP 502 Bad Gateway')),
        [
            partialStreamProvider('fallback-1', 'Half', new \RuntimeException('Idle timeout reached')),
            stubProvider('fallback-2', 'Other answer'),
        ],
    );

    $chunks = [];
    try {
        foreach ($provider->stream([]) as $chunk) {
            $chunks[] = $chunk->content;
        }
    } catch (\RuntimeException) {
    }

    expect($chunks)->toBe(['Half']);
});

test('stream rethrows without falling back once the primary has yielded a chunk', function () {
    $primary = new class implements ProviderInterface {
        use \CarmeloSantana\PHPAgents\Provider\Stream\StreamsEventsFromResponses;

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            throw new \RuntimeException('unused');
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            yield new Response(content: 'Half an ans', finishReason: ProviderFinishReason::Stop, model: 'primary');
            throw new \RuntimeException('Idle timeout reached');
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            return null;
        }

        public function models(): array
        {
            return [];
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function getModel(): string
        {
            return 'primary';
        }

        public function withModel(string $model): static
        {
            return $this;
        }
    };

    $provider = new FallbackProvider($primary, [stubProvider('fallback', 'A whole other answer')]);

    $chunks = [];
    $thrown = null;
    try {
        foreach ($provider->stream([]) as $chunk) {
            $chunks[] = $chunk->content;
        }
    } catch (\RuntimeException $e) {
        $thrown = $e;
    }

    expect($chunks)->toBe(['Half an ans'])
        ->and($thrown?->getMessage())->toBe('Idle timeout reached');
});

// --- streamEvents() tests ---

/**
 * A provider whose streamEvents() yields the given events, then throws $error when set.
 * Records each call's options so forwarding can be asserted.
 *
 * @param list<\CarmeloSantana\PHPAgents\Provider\Stream\StreamEvent> $events
 */
function eventsProvider(string $model, array $events, ?\Throwable $error = null): ProviderInterface
{
    return new class ($model, $events, $error) implements ProviderInterface {
        /** @var list<array<string, mixed>> */
        public array $calls = [];

        public function __construct(
            private readonly string $model,
            private readonly array $events,
            private readonly ?\Throwable $error,
        ) {}

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            $this->calls[] = $options;
            if ($this->error !== null) {
                throw $this->error;
            }

            return new Response(content: 'chat', finishReason: ProviderFinishReason::Stop, model: $this->model);
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            throw new \LogicException('stream() must not be called; the agent iterates streamEvents()');
        }

        public function streamEvents(array $messages, array $tools = [], array $options = []): iterable
        {
            $this->calls[] = $options;
            foreach ($this->events as $event) {
                yield $event;
            }
            if ($this->error !== null) {
                throw $this->error;
            }
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            return null;
        }

        public function models(): array
        {
            return [];
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function getModel(): string
        {
            return $this->model;
        }

        public function withModel(string $model): static
        {
            return $this;
        }
    };
}

function cancellableToken(): \CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface
{
    return new class implements \CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface {
        public bool $cancelled = false;

        public function isCancelled(): bool
        {
            return $this->cancelled;
        }
    };
}

test('streamEvents forwards the primary events and options unchanged, tool input and ticks included', function () {
    $usage = new \CarmeloSantana\PHPAgents\Provider\Usage(promptTokens: 12, completionTokens: 3, totalTokens: 15, cacheReadTokens: 4);
    $events = [
        new \CarmeloSantana\PHPAgents\Provider\Stream\Tick(),
        new \CarmeloSantana\PHPAgents\Provider\Stream\TextDelta('Hi'),
        new \CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart(0, 'call_1', 'search'),
        new \CarmeloSantana\PHPAgents\Provider\Stream\ToolInputDelta(0, 'call_1', '{"q":"x"}'),
        new \CarmeloSantana\PHPAgents\Provider\Stream\ToolInputEnd(0, 'call_1', 'search', ['q' => 'x']),
        new \CarmeloSantana\PHPAgents\Provider\Stream\Finish(ProviderFinishReason::ToolUse, 'tool_calls', $usage, 'primary'),
    ];
    $primary = eventsProvider('primary', $events);
    $fallback = eventsProvider('fallback', []);

    $provider = new FallbackProvider($primary, [$fallback]);
    $seen = iterator_to_array($provider->streamEvents([], [], ['temperature' => 0.2]), false);

    expect($seen)->toBe($events)
        ->and($primary->calls)->toBe([['temperature' => 0.2]])
        ->and($fallback->calls)->toBe([]);
});

test('streamEvents falls back on a retryable error raised before any output, ticks aside', function () {
    $primary = eventsProvider('primary', [new \CarmeloSantana\PHPAgents\Provider\Stream\Tick()], new \RuntimeException('HTTP 503 Service Unavailable'));
    $finish = new \CarmeloSantana\PHPAgents\Provider\Stream\Finish(ProviderFinishReason::Stop, model: 'fallback');
    $fallback = eventsProvider('fallback', [new \CarmeloSantana\PHPAgents\Provider\Stream\TextDelta('from fallback'), $finish]);

    $messages = [];
    $provider = new FallbackProvider($primary, [$fallback]);
    $provider->setOnNotify(function (string $msg) use (&$messages) {
        $messages[] = $msg;
    });
    $seen = iterator_to_array($provider->streamEvents([], [], ['max_tokens' => 9]), false);

    expect(array_map(static fn($e) => $e::class, $seen))->toBe([
        \CarmeloSantana\PHPAgents\Provider\Stream\Tick::class,
        \CarmeloSantana\PHPAgents\Provider\Stream\TextDelta::class,
        \CarmeloSantana\PHPAgents\Provider\Stream\Finish::class,
    ])
        ->and($seen[2])->toBe($finish)
        ->and($fallback->calls)->toBe([['max_tokens' => 9]])
        ->and($messages[1])->toContain('Fallback to fallback succeeded');
});

test('streamEvents rethrows without falling back once the primary has streamed output', function () {
    $primary = eventsProvider('primary', [new \CarmeloSantana\PHPAgents\Provider\Stream\ToolInputStart(0, 'call_1', 'search')], new \RuntimeException('Idle timeout reached'));
    $fallback = eventsProvider('fallback', []);

    $provider = new FallbackProvider($primary, [$fallback]);

    expect(fn() => iterator_to_array($provider->streamEvents([]), false))
        ->toThrow(\RuntimeException::class, 'Idle timeout reached')
        ->and($fallback->calls)->toBe([]);
});

test('streamEvents throws immediately on a non-retryable error', function () {
    $primary = eventsProvider('primary', [], new \RuntimeException('HTTP 401 Unauthorized'));
    $fallback = eventsProvider('fallback', []);

    $provider = new FallbackProvider($primary, [$fallback]);

    expect(fn() => iterator_to_array($provider->streamEvents([]), false))
        ->toThrow(\RuntimeException::class, 'HTTP 401 Unauthorized')
        ->and($fallback->calls)->toBe([]);
});

test('a cancel that aborts the primary stream does not start a fallback request', function () {
    $token = cancellableToken();
    $token->cancelled = true;
    // The aborted transport surfaces as a retryable network error.
    $primary = eventsProvider('primary', [], new \RuntimeException('Network error: transfer aborted'));
    $fallback = eventsProvider('fallback', []);

    $messages = [];
    $provider = new FallbackProvider($primary, [$fallback], $token);
    $provider->setOnNotify(function (string $msg) use (&$messages) {
        $messages[] = $msg;
    });

    expect(fn() => iterator_to_array($provider->streamEvents([]), false))
        ->toThrow(\RuntimeException::class, 'Network error: transfer aborted')
        ->and($fallback->calls)->toBe([])
        ->and($messages)->toBe([]);
});

test('a cancel during a failing fallback stops before the next fallback', function () {
    $token = cancellableToken();
    $primary = eventsProvider('primary', [], new \RuntimeException('HTTP 502 Bad Gateway'));
    $first = new class ($token) implements ProviderInterface {
        use \CarmeloSantana\PHPAgents\Provider\Stream\StreamsEventsFromResponses;

        public function __construct(private readonly object $token) {}

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            $this->token->cancelled = true;
            throw new \RuntimeException('HTTP 503 Service Unavailable');
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            $this->token->cancelled = true;
            throw new \RuntimeException('HTTP 503 Service Unavailable');
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            return null;
        }

        public function models(): array
        {
            return [];
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function getModel(): string
        {
            return 'fallback-1';
        }

        public function withModel(string $model): static
        {
            return $this;
        }
    };
    $second = eventsProvider('fallback-2', []);

    $provider = new FallbackProvider($primary, [$first, $second], $token);

    expect(fn() => iterator_to_array($provider->streamEvents([]), false))
        ->toThrow(\RuntimeException::class, 'HTTP 502 Bad Gateway')
        ->and($second->calls)->toBe([]);
});

test('chat and structured do not fall back once the token is cancelled', function () {
    $token = cancellableToken();
    $token->cancelled = true;
    $primary = failingProvider('primary', new \RuntimeException('HTTP 503 Service Unavailable'));
    $fallback = eventsProvider('fallback', []);

    $provider = new FallbackProvider($primary, [$fallback], $token);

    expect(fn() => $provider->chat([]))->toThrow(\RuntimeException::class, 'HTTP 503')
        ->and(fn() => $provider->structured([], '{}'))->toThrow(\RuntimeException::class, 'HTTP 503')
        ->and($fallback->calls)->toBe([]);
});
