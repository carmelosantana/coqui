<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\Agent;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\Finish;
use CarmeloSantana\PHPAgents\Provider\Stream\ReasoningDelta;
use CarmeloSantana\PHPAgents\Provider\Stream\TextDelta;
use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CoquiBot\Coqui\Observer\BackgroundTaskObserver;
use CoquiBot\Coqui\Observer\EscCancellationObserver;
use CoquiBot\Coqui\Observer\SseObserver;
use CoquiBot\Coqui\Observer\TerminalObserver;
use CoquiBot\Coqui\Observer\TurnProcessObserver;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Tests\Unit\Observer\TestWritableStream;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Since php-agents 0.18.1 an exception an observer throws while a response
 * streams escapes Agent::run() instead of ending the run Error. Coqui's
 * streaming observers must therefore never throw on what a model sends.
 */

/** Invalid UTF-8: a lone continuation byte and a truncated sequence. */
const OBSERVER_SAFETY_BAD_UTF8 = "Half \xB1 an \xE2\x82 answer";

function observerSafetyProvider(): ProviderInterface
{
    return new class implements ProviderInterface {
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
            yield new ReasoningDelta(OBSERVER_SAFETY_BAD_UTF8);
            yield new TextDelta(OBSERVER_SAFETY_BAD_UTF8);
            yield new Finish(ProviderFinishReason::Stop);
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
            return 'test/mock';
        }

        public function withModel(string $model): static
        {
            return $this;
        }
    };
}

/**
 * @return array{0: SplObserver, 1: \Closure(): void}
 */
function observerSafetyStorageObserver(string $kind): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-observer-safety-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $sessionId = $storage->createSession('orchestrator', 'test/model');

    $observer = $kind === 'turn'
        ? new TurnProcessObserver($storage, $storage->createTurnProcess($sessionId, 'Explain it'))
        : new BackgroundTaskObserver($storage, $storage->createTask($sessionId, 'Explain it'));

    $cleanup = static function () use ($dbPath): void {
        foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    };

    return [$observer, $cleanup];
}

test('a streaming observer does not throw on invalid UTF-8 from the model', function (string $kind) {
    $cleanup = static function (): void {};

    $observer = match ($kind) {
        'sse' => new SseObserver(new TestWritableStream()),
        'terminal' => new TerminalObserver(new BufferedOutput()),
        'esc' => new EscCancellationObserver(
            new TerminalObserver(new BufferedOutput()),
            new ProcessCancellationToken(),
            new BufferedOutput(),
            fopen('php://memory', 'r'),
            false,
        ),
        'turn', 'task' => (function () use ($kind, &$cleanup) {
            [$observer, $cleanup] = observerSafetyStorageObserver($kind);

            return $observer;
        })(),
    };

    try {
        $agent = new Agent(observerSafetyProvider());
        $agent->attach($observer);

        $output = $agent->run(new UserMessage('Explain it'));

        expect($output->finishReason)->not->toBe(AgentFinishReason::Error);
    } finally {
        $cleanup();
    }
})->with(['sse', 'terminal', 'esc', 'turn', 'task']);

test('the SSE observer substitutes invalid UTF-8 rather than dropping the delta', function () {
    $stream = new TestWritableStream();
    $agent = new Agent(observerSafetyProvider());
    $agent->attach(new SseObserver($stream));

    $agent->run(new UserMessage('Explain it'));

    expect($stream->buffer)->toContain('event: text_delta')
        ->and($stream->buffer)->toContain('"content":"Half \\ufffd an \\ufffd answer"')
        ->and(mb_check_encoding($stream->buffer, 'UTF-8'))->toBeTrue();
});
