<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Observer;

use CarmeloSantana\PHPAgents\Agent\Event\RunFinish;
use CarmeloSantana\PHPAgents\Enum\AgentFinishReason;

/**
 * Recognises a cancelled run in the agent's event stream.
 *
 * Since php-agents 0.18 every cancel fires `agent.error` with 'Task cancelled'
 * (kept for observers that predate the Cancelled reason) and then `agent.finish`
 * with AgentFinishReason::Cancelled. Observers skip that error and report the
 * finish instead, so a Stop reads as cancelled rather than as a failure.
 */
final class RunCancellation
{
    public const string MESSAGE = 'Task cancelled';

    public static function isCancelError(string $event, mixed $data): bool
    {
        return $event === 'agent.error' && $data === self::MESSAGE;
    }

    public static function isCancelledFinish(string $event, mixed $data): bool
    {
        return $event === 'agent.finish'
            && $data instanceof RunFinish
            && $data->reason === AgentFinishReason::Cancelled;
    }
}
