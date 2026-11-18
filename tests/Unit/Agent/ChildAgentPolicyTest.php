<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Agent\ChildAgent;
use CoquiBot\Coqui\Config\CatastrophicBlacklist;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Repl\ExecutionPolicyFactory;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Tool\SpawnAgentTool;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A provider that asks for one `probe` tool call, then calls `done`.
 */
function childPolicyProvider(int &$calls): ProviderInterface
{
    return new class ($calls) implements ProviderInterface {
        private int $calls;

        public function __construct(int &$calls)
        {
            $this->calls = &$calls;
        }

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            $this->calls++;

            return $this->calls === 1
                ? new Response(
                    content: '',
                    finishReason: ProviderFinishReason::ToolUse,
                    toolCalls: [new ToolCall('call_1', 'probe', ['command' => 'id'])],
                )
                : new Response(
                    content: '',
                    finishReason: ProviderFinishReason::ToolUse,
                    toolCalls: [new ToolCall('call_2', 'done', ['response' => 'finished'])],
                );
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            return [$this->chat($messages, $tools, $options)];
        }

        public function structured(array $messages, string $schema, array $options = []): mixed { return null; }
        public function models(): array { return []; }
        public function isAvailable(): bool { return true; }
        public function getModel(): string { return 'test/model'; }
        public function withModel(string $model): static { return $this; }
    };
}

function childPolicyProbeToolkit(bool &$ran): ToolkitInterface
{
    return new class ($ran) implements ToolkitInterface {
        private bool $ran;

        public function __construct(bool &$ran)
        {
            $this->ran = &$ran;
        }

        public function tools(): array
        {
            return [new Tool(
                name: 'probe',
                description: 'Probe tool',
                parameters: [],
                callback: function (): ToolResult {
                    $this->ran = true;

                    return ToolResult::success('ran');
                },
            )];
        }

        public function guidelines(): string { return ''; }
    };
}

final class RecordingDenyPolicy implements ToolExecutionPolicyInterface
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $seen = [];

    /** @param list<string> $deny */
    public function __construct(private readonly array $deny = ['probe']) {}

    public function shouldExecute(string $toolName, array $arguments): true|string
    {
        $this->seen[] = [$toolName, $arguments];

        return in_array($toolName, $this->deny, true) ? "policy denied {$toolName}" : true;
    }
}

test('a child agent runs its tool calls through the execution policy it is given', function () {
    $calls = 0;
    $ran = false;
    $policy = new RecordingDenyPolicy();

    $child = new ChildAgent(
        provider: childPolicyProvider($calls),
        role: 'coder',
        taskInstructions: 'Run the probe.',
        toolkits: [childPolicyProbeToolkit($ran)],
        maxIterations: 5,
        executionPolicy: $policy,
    );
    $child->run(new UserMessage('go'));

    expect($ran)->toBeFalse()
        ->and(array_column($policy->seen, 0))->toContain('probe');
});

test('a cancelled turn stops a child agent before it calls the provider', function () {
    $calls = 0;
    $ran = false;
    $token = new class implements CancellationTokenInterface {
        public function isCancelled(): bool { return true; }
    };

    $child = new ChildAgent(
        provider: childPolicyProvider($calls),
        role: 'coder',
        taskInstructions: 'Run the probe.',
        toolkits: [childPolicyProbeToolkit($ran)],
        maxIterations: 5,
        cancellationToken: $token,
    );
    $child->run(new UserMessage('go'));

    expect($calls)->toBe(0)->and($ran)->toBeFalse();
});

test('spawn_agent asks the execution policy before starting a full-access child', function () {
    $config = OpenClawConfig::fromArray([
        'agents' => ['defaults' => [
            'model' => ['primary' => 'ollama/qwen3:latest'],
            'roles' => ['coder' => 'ollama/qwen3:latest'],
        ]],
    ]);
    $policy = new RecordingDenyPolicy(deny: ['spawn_agent']);

    $tool = new SpawnAgentTool(
        roleResolver: new RoleResolver($config),
        config: $config,
        projectRoot: '/tmp',
        workspacePath: '/tmp',
        executionPolicy: $policy,
    );
    $result = $tool->execute(['role' => 'coder', 'task' => 'rm everything']);

    expect($result->content)->toContain('policy denied spawn_agent')
        ->and($policy->seen)->toHaveCount(1)
        ->and($policy->seen[0][1]['access_level'] ?? null)->toBe('full');
});

test('the REPL policy gates full-access spawns but not read-only ones', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession('orchestrator', 'test/model');
    $factory = new ExecutionPolicyFactory(
        new CatastrophicBlacklist(),
        $storage,
        new ToolkitDiscovery('/tmp', sys_get_temp_dir() . '/coqui-policy-' . bin2hex(random_bytes(4))),
    );
    $input = new ArrayInput([]);
    $input->setInteractive(false);
    $policy = $factory->buildInteractive($sessionId, new SymfonyStyle($input, new BufferedOutput()), autoApprove: false);

    expect($policy->shouldExecute('spawn_agent', ['role' => 'coder', 'task' => 'x', 'access_level' => 'full']))->toBeString()
        ->and($policy->shouldExecute('spawn_agent', ['role' => 'explorer', 'task' => 'x']))->toBeTrue();
});

test('the REPL policy gates scheduling and loop tools', function (string $tool, array $args) {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession('orchestrator', 'test/model');
    $factory = new ExecutionPolicyFactory(
        new CatastrophicBlacklist(),
        $storage,
        new ToolkitDiscovery('/tmp', sys_get_temp_dir() . '/coqui-policy-' . bin2hex(random_bytes(4))),
    );
    $input = new ArrayInput([]);
    $input->setInteractive(false);
    $policy = $factory->buildInteractive($sessionId, new SymfonyStyle($input, new BufferedOutput()), autoApprove: false);

    expect($policy->shouldExecute($tool, $args))->toBeString();
})->with([
    'schedule_create' => ['schedule_create', ['name' => 'x', 'cron' => '* * * * *', 'prompt' => 'run exec']],
    'schedule_update' => ['schedule_update', ['id' => 'x', 'prompt' => 'run exec']],
    'schedule_trigger' => ['schedule_trigger', ['id' => 'x']],
    'loop_start' => ['loop_start', ['definition' => 'x', 'goal' => 'y']],
]);
