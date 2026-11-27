<?php

declare(strict_types=1);

/**
 * AgentTurnManager::cancel() stops an API turn by sending SIGTERM to its
 * turn:run child. That child's SIGTERM handler only cancels a token, and the
 * handler replaces the default kill, so the token has to reach the agent or a
 * Stop does nothing.
 */
test('turn:run hands its SIGTERM cancellation token to every agent run', function () {
    $source = (string) file_get_contents(__DIR__ . '/../../../src/Command/TurnRunCommand.php');

    foreach (['runWithObserver', 'runSegment'] as $method) {
        preg_match('/\$agentRunner->' . $method . '\((.*?)\n\s*\);/s', $source, $call);

        expect($call)->not->toBeEmpty("TurnRunCommand no longer calls {$method}()")
            ->and($call[1])->toContain('cancellationToken: $cancellationToken');
    }
});
