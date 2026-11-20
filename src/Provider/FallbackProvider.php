<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Provider;

use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Provider\Stream\StreamEvent;
use CarmeloSantana\PHPAgents\Provider\Stream\Tick;
use CoquiBot\Coqui\Exception\ProviderErrorClassifier;

/**
 * Decorator that adds automatic failover to any ProviderInterface.
 *
 * Wraps a primary provider and transparently falls through to fallback
 * providers on retryable errors (429, 5xx, network failures). Non-retryable
 * errors (401, 403, 400, 404) are thrown immediately.
 *
 * A cancel stops failover: once the optional cancellation token is set, the
 * error that ended the request is rethrown instead of starting another one,
 * because a cancel that aborts the transport surfaces as a network error.
 *
 * The optional $onNotify callback receives warning strings for observer
 * integration (e.g. "Primary provider failed (rate_limited). Attempting
 * 2 fallback(s)...").
 */
final class FallbackProvider implements ProviderInterface
{
    /** @var (\Closure(string): void)|null */
    private ?\Closure $onNotify = null;

    /**
     * @param ProviderInterface $primary The primary provider to try first
     * @param ProviderInterface[] $fallbacks Fallback providers in priority order
     * @param CancellationTokenInterface|null $cancellationToken The run's token; no fallback starts once it is set
     */
    public function __construct(
        private readonly ProviderInterface $primary,
        private readonly array $fallbacks,
        private readonly ?CancellationTokenInterface $cancellationToken = null,
    ) {}

    /**
     * Set a callback for fallback notifications (warnings, successes).
     */
    public function setOnNotify(\Closure $onNotify): void
    {
        $this->onNotify = $onNotify;
    }

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        try {
            return $this->primary->chat($messages, $tools, $options);
        } catch (\Throwable $e) {
            return $this->attemptFallbackChat($e, $messages, $tools, $options);
        }
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        // Fall back only before the primary yields: the caller has already
        // consumed any chunk it sent, so a second model's stream would be
        // spliced onto a partial answer.
        $yielded = false;
        try {
            foreach ($this->primary->stream($messages, $tools, $options) as $chunk) {
                $yielded = true;
                yield $chunk;
            }
        } catch (\Throwable $e) {
            if ($yielded) {
                throw $e;
            }
            yield from $this->attemptFallbackStream($e, $messages, $tools, $options);
        }
    }

    public function streamEvents(array $messages, array $tools = [], array $options = []): iterable
    {
        // Same rule as stream(): fall back only before the primary sends output.
        // Idle ticks carry none, so they pass through and keep the agent's
        // cancellation and run-limit checks alive without ruling out failover.
        $yielded = false;
        try {
            foreach ($this->primary->streamEvents($messages, $tools, $options) as $event) {
                $yielded = $yielded || !$event instanceof Tick;
                yield $event;
            }
        } catch (\Throwable $e) {
            if ($yielded) {
                throw $e;
            }
            yield from $this->attemptFallbackStreamEvents($e, $messages, $tools, $options);
        }
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        try {
            return $this->primary->structured($messages, $schema, $options);
        } catch (\Throwable $e) {
            return $this->attemptFallbackStructured($e, $messages, $schema, $options);
        }
    }

    public function models(): array
    {
        return $this->primary->models();
    }

    public function isAvailable(): bool
    {
        return $this->primary->isAvailable();
    }

    public function getModel(): string
    {
        return $this->primary->getModel();
    }

    public function withModel(string $model): static
    {
        // Cannot use static return with final class decorator — delegate to primary
        // and reconstruct with same fallbacks. This preserves the decorator chain.
        $new = clone $this;

        return $new;
    }

    /**
     * @param MessageInterface[] $messages
     * @param ToolInterface[] $tools
     * @param array<string, mixed> $options
     */
    private function attemptFallbackChat(
        \Throwable $originalError,
        array $messages,
        array $tools,
        array $options,
    ): Response {
        $this->guardFallback($originalError);
        $this->notifyFallbackStart($originalError);

        foreach ($this->fallbacks as $fallback) {
            try {
                $result = $fallback->chat($messages, $tools, $options);
                $this->notify(sprintf('Fallback to %s succeeded.', $fallback->getModel()));
                return $result;
            } catch (\Throwable $e) {
                $this->notifyFallbackFailed($fallback, $e);
                $this->guardCancelled($originalError);
            }
        }

        throw $originalError;
    }

    /**
     * @param MessageInterface[] $messages
     * @param ToolInterface[] $tools
     * @param array<string, mixed> $options
     * @return iterable<Response>
     */
    private function attemptFallbackStream(
        \Throwable $originalError,
        array $messages,
        array $tools,
        array $options,
    ): iterable {
        $this->guardFallback($originalError);
        $this->notifyFallbackStart($originalError);

        foreach ($this->fallbacks as $fallback) {
            $yielded = false;
            try {
                foreach ($fallback->stream($messages, $tools, $options) as $chunk) {
                    $yielded = true;
                    yield $chunk;
                }
                $this->notify(sprintf('Fallback to %s succeeded.', $fallback->getModel()));
                return;
            } catch (\Throwable $e) {
                if ($yielded) {
                    throw $e;
                }
                $this->notifyFallbackFailed($fallback, $e);
                $this->guardCancelled($originalError);
            }
        }

        throw $originalError;
    }

    /**
     * @param MessageInterface[] $messages
     * @param ToolInterface[] $tools
     * @param array<string, mixed> $options
     * @return iterable<StreamEvent>
     */
    private function attemptFallbackStreamEvents(
        \Throwable $originalError,
        array $messages,
        array $tools,
        array $options,
    ): iterable {
        $this->guardFallback($originalError);
        $this->notifyFallbackStart($originalError);

        foreach ($this->fallbacks as $fallback) {
            $yielded = false;
            try {
                foreach ($fallback->streamEvents($messages, $tools, $options) as $event) {
                    $yielded = $yielded || !$event instanceof Tick;
                    yield $event;
                }
                $this->notify(sprintf('Fallback to %s succeeded.', $fallback->getModel()));
                return;
            } catch (\Throwable $e) {
                if ($yielded) {
                    throw $e;
                }
                $this->notifyFallbackFailed($fallback, $e);
                $this->guardCancelled($originalError);
            }
        }

        throw $originalError;
    }

    /**
     * @param MessageInterface[] $messages
     * @param array<string, mixed> $options
     */
    private function attemptFallbackStructured(
        \Throwable $originalError,
        array $messages,
        string $schema,
        array $options,
    ): mixed {
        $this->guardFallback($originalError);
        $this->notifyFallbackStart($originalError);

        foreach ($this->fallbacks as $fallback) {
            try {
                $result = $fallback->structured($messages, $schema, $options);
                $this->notify(sprintf('Fallback to %s succeeded.', $fallback->getModel()));
                return $result;
            } catch (\Throwable $e) {
                $this->notifyFallbackFailed($fallback, $e);
                $this->guardCancelled($originalError);
            }
        }

        throw $originalError;
    }

    /**
     * Re-throw immediately if the error is not retryable or the run was cancelled.
     */
    private function guardFallback(\Throwable $e): void
    {
        $this->guardCancelled($e);
        if (!ProviderErrorClassifier::isRetryable($e)) {
            throw $e;
        }
    }

    /**
     * Re-throw the error that ended the request once the run is cancelled, so no
     * further request starts. The agent ends such a run Cancelled, not Error.
     */
    private function guardCancelled(\Throwable $e): void
    {
        if ($this->cancellationToken?->isCancelled() === true) {
            throw $e;
        }
    }

    private function notifyFallbackFailed(ProviderInterface $fallback, \Throwable $e): void
    {
        $this->notify(sprintf(
            'Fallback model %s also failed: %s',
            $fallback->getModel(),
            $e->getMessage(),
        ));
    }

    private function notifyFallbackStart(\Throwable $originalError): void
    {
        $classification = ProviderErrorClassifier::classify($originalError);
        $this->notify(sprintf(
            'Primary provider failed (%s). Attempting %d fallback model(s)...',
            $classification,
            count($this->fallbacks),
        ));
    }

    private function notify(string $message): void
    {
        if ($this->onNotify !== null) {
            ($this->onNotify)($message);
        }
    }
}
