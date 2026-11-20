<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Agent\Agent;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolGrantInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Tool\ToolRegistry;
use CoquiBot\Coqui\Tool\ToolSearchTool;

function grantTestTool(string $name, string $description, ?Closure $onCall = null): ToolInterface
{
    return new Tool(
        name: $name,
        description: $description,
        parameters: [],
        callback: static function (array $args) use ($onCall, $name): ToolResult {
            if ($onCall !== null) {
                $onCall();
            }

            return ToolResult::success($name . ' ran');
        },
    );
}

test('tool_search grants the tools its last search found', function () {
    $registry = new ToolRegistry();
    $registry->register(grantTestTool('git_commit', 'Commit staged changes to the git repository'));
    $registry->register(grantTestTool('web_fetch', 'Fetch a web page over HTTP'));

    $search = new ToolSearchTool($registry);

    expect($search)->toBeInstanceOf(ToolGrantInterface::class);

    $search->execute(['query' => 'git commit']);
    expect($search->grantedToolNames())->toContain('git_commit')
        ->and($search->grantedToolNames())->not->toContain('web_fetch');

    $search->execute(['query' => 'zzzz-no-such-capability']);
    expect($search->grantedToolNames())->toBe([]);
});

test('a tool beyond the maxTools cap runs after tool_search finds it', function () {
    $ran = false;
    $capped = grantTestTool('git_commit', 'Commit staged changes to the git repository', function () use (&$ran): void {
        $ran = true;
    });

    $registry = new ToolRegistry();
    $registry->register($capped);
    $search = new ToolSearchTool($registry);

    $provider = new class implements ProviderInterface {

        use \CarmeloSantana\PHPAgents\Provider\Stream\StreamsEventsFromResponses;

        private int $call = 0;

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            return $this->next();
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            yield $this->next();
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            return [];
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

        private function next(): Response
        {
            return match ($this->call++) {
                0 => new Response('', ProviderFinishReason::ToolUse, [new ToolCall('c1', 'tool_search', ['query' => 'git commit'])]),
                1 => new Response('', ProviderFinishReason::ToolUse, [new ToolCall('c2', 'git_commit', [])]),
                default => new Response('done', ProviderFinishReason::Stop),
            };
        }
    };

    // tool_search plus done fill a cap of 2, so git_commit is never advertised.
    $agent = new Agent($provider, tools: [$search, $capped]);
    $agent->setMaxTools(2);
    $agent->run(new UserMessage('commit please'));

    expect($ran)->toBeTrue();
});
