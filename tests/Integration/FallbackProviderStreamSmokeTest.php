<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\Agent;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\Tick;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CoquiBot\Coqui\Observer\SseObserver;
use CoquiBot\Coqui\Provider\FallbackProvider;
use CoquiBot\Coqui\Tests\Unit\Observer\TestWritableStream;

/**
 * Smoke test: FallbackProvider loads against the installed php-agents and runs
 * real Agent loops whose stream is interrupted, by a Stop and by a failure.
 */

/**
 * A provider that streams $before, then calls $interrupt (which may throw).
 *
 * @param list<\CarmeloSantana\PHPAgents\Provider\Stream\StreamEvent> $before
 */
function smokeStreamProvider(string $model, array $before, ?\Closure $interrupt = null): ProviderInterface
{
    return new class ($model, $before, $interrupt) implements ProviderInterface {
        public int $streams = 0;

        public function __construct(
            private readonly string $model,
            private readonly array $before,
            private readonly ?\Closure $interrupt,
        ) {}

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            throw new \LogicException('chat() is not used by the agent loop');
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            throw new \LogicException('stream() is not used by the agent loop');
        }

        public function streamEvents(array $messages, array $tools = [], array $options = []): iterable
        {
            $this->streams++;
            foreach ($this->before as $event) {
                yield $event;
            }
            if ($this->interrupt !== null) {
                ($this->interrupt)();
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

test('FallbackProvider loads as a concrete ProviderInterface on the installed php-agents', function () {
    $class = new ReflectionClass(FallbackProvider::class);

    expect($class->isInstantiable())->toBeTrue()
        ->and($class->implementsInterface(ProviderInterface::class))->toBeTrue()
        ->and($class->hasMethod('streamEvents'))->toBeTrue();
});

test('a Stop mid-stream ends the run Cancelled with no fallback request', function (array $before, string $expectedContent) {
    $token = new ProcessCancellationToken();
    $primary = smokeStreamProvider(
        'primary',
        $before,
        static function () use ($token): never {
            // Stop aborts the transport, which surfaces as a retryable network error.
            $token->cancel();
            throw new \RuntimeException('Network error: transfer aborted');
        },
    );
    $fallback = smokeStreamProvider('fallback', [new TextDelta('A whole other answer'), new Finish(ProviderFinishReason::Stop)]);

    $stream = new TestWritableStream();
    $agent = new Agent(new FallbackProvider($primary, [$fallback], $token), cancellationToken: $token);
    $agent->attach(new SseObserver($stream));

    $output = $agent->run(new UserMessage('Explain it'));

    expect($output->finishReason)->toBe(AgentFinishReason::Cancelled)
        ->and($output->content)->toContain($expectedContent)
        ->and($fallback->streams)->toBe(0)
        ->and($stream->buffer)->toContain('event: cancelled')
        ->and($stream->buffer)->not->toContain('event: error');
})->with([
    'after streamed text' => [[new TextDelta('Half an ans'), new Tick()], 'Half an ans'],
    // Only idle ticks so far: without the token check this would start a fallback.
    'while the model is still silent' => [[new Tick(), new Tick()], 'Task was cancelled.'],
]);

test('a failure before any output fails over to the fallback stream', function () {
    $primary = smokeStreamProvider(
        'primary',
        [new Tick()],
        static fn(): never => throw new \RuntimeException('HTTP 503 Service Unavailable'),
    );
    $fallback = smokeStreamProvider('fallback', [
        new TextDelta('From the fallback'),
        new Finish(ProviderFinishReason::Stop, usage: new Usage(promptTokens: 7, completionTokens: 3, totalTokens: 10), model: 'fallback'),
    ]);

    $warnings = [];
    $provider = new FallbackProvider($primary, [$fallback], new ProcessCancellationToken());
    $provider->setOnNotify(static function (string $message) use (&$warnings): void {
        $warnings[] = $message;
    });

    $output = (new Agent($provider))->run(new UserMessage('Explain it'));

    expect($output->finishReason)->not->toBe(AgentFinishReason::Error)
        ->and($output->content)->toBe('From the fallback')
        ->and($output->usage?->totalTokens)->toBe(10)
        ->and($fallback->streams)->toBe(1)
        ->and($warnings)->toHaveCount(2);
});
