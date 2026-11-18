<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\ConfigGuard;
use CoquiBot\Coqui\Config\ConfigManager;
use CoquiBot\Coqui\Config\ConfigValidator;
use CoquiBot\Coqui\Config\DefaultsLoader;
use CoquiBot\Coqui\Tool\ConfigTool;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/coqui-configtool-test-' . bin2hex(random_bytes(4));
    mkdir($this->tmpDir, 0755, true);
    mkdir($this->tmpDir . '/workspace', 0755, true);
    mkdir($this->tmpDir . '/project', 0755, true);

    $this->manager = new ConfigManager(
        workspacePath: $this->tmpDir . '/workspace',
        projectRoot: $this->tmpDir . '/project',
        defaultsLoader: new DefaultsLoader(),
        validator: new ConfigValidator(),
    );

    // Seed a config
    $config = [
        'agents' => [
            'defaults' => [
                'model' => [
                    'primary' => 'openai/gpt-4o',
                    'fallbacks' => ['anthropic/claude-sonnet-4-20250514'],
                ],
                'roles' => [
                    'orchestrator' => 'openai/gpt-4o',
                    'coder' => 'anthropic/claude-sonnet-4-20250514',
                ],
                'maxIterations' => 25,
            ],
        ],
        'models' => [
            'providers' => [
                'openai' => [
                    'apiKey' => 'sk-secret123',
                    'models' => [
                        ['id' => 'gpt-4o', 'name' => 'GPT-4o'],
                        ['id' => 'gpt-4.1', 'name' => 'GPT-4.1'],
                    ],
                ],
                'anthropic' => [
                    'apiKey' => 'sk-ant-secret',
                    'models' => [
                        ['id' => 'claude-sonnet-4-20250514', 'name' => 'Claude Sonnet 4'],
                    ],
                ],
            ],
        ],
    ];
    file_put_contents(
        $this->tmpDir . '/workspace/openclaw.json',
        json_encode($config, JSON_PRETTY_PRINT),
    );
    $this->manager->load();

    $this->guard = new ConfigGuard();
    $this->tool = new ConfigTool(
        configManager: $this->manager,
        configGuard: $this->guard,
    );
});

afterEach(function () {
    $cleanup = function (string $dir) use (&$cleanup): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $cleanup($path) : unlink($path);
        }
        rmdir($dir);
    };
    $cleanup($this->tmpDir);
});

test('has correct name', function () {
    expect($this->tool->name())->toBe('config');
});

test('get reads a config value', function () {
    $result = $this->tool->execute([
        'action' => 'get',
        'key' => 'agents.defaults.model.primary',
    ]);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('openai/gpt-4o');
});

test('get returns message for missing key', function () {
    $result = $this->tool->execute([
        'action' => 'get',
        'key' => 'nonexistent.key',
    ]);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('not set');
});

test('get requires key parameter', function () {
    $result = $this->tool->execute(['action' => 'get']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('Key is required');
});

test('set updates an allowed config value', function () {
    $result = $this->tool->execute([
        'action' => 'set',
        'key' => 'agents.defaults.maxIterations',
        'value' => '20',
    ]);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('20');
    expect($result->content)->toContain('restart is required');
});

test('set denies security-sensitive keys', function () {
    $result = $this->tool->execute([
        'action' => 'set',
        'key' => 'agents.defaults.blacklist',
        'value' => '["rm -rf"]',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('blacklist');
});

test('set denies API key modification', function () {
    $result = $this->tool->execute([
        'action' => 'set',
        'key' => 'models.providers.openai.apiKey',
        'value' => 'new-key',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('Provider');
});

test('set requires key and value', function () {
    $noKey = $this->tool->execute(['action' => 'set', 'value' => 'x']);
    $noValue = $this->tool->execute(['action' => 'set', 'key' => 'agents.defaults.maxIterations']);

    expect($noKey->status->value)->toBe('error');
    expect($noValue->status->value)->toBe('error');
});

test('show returns sanitized config', function () {
    $result = $this->tool->execute(['action' => 'show']);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('openclaw.json');
    expect($result->content)->toContain('openai/gpt-4o');
    // API keys should be masked
    expect($result->content)->toContain('***');
    expect($result->content)->not->toContain('sk-secret123');
    expect($result->content)->not->toContain('sk-ant-secret');
});

test('list_models shows available models', function () {
    $result = $this->tool->execute(['action' => 'list_models']);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('openai/gpt-4o');
    expect($result->content)->toContain('openai/gpt-4.1');
    expect($result->content)->toContain('anthropic/claude-sonnet-4-20250514');
    expect($result->content)->toContain('← current');
});

test('switch_model changes primary model', function () {
    $result = $this->tool->execute([
        'action' => 'switch_model',
        'value' => 'anthropic/claude-sonnet-4-20250514',
    ]);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('anthropic/claude-sonnet-4-20250514');
    expect($result->content)->toContain('restart is required');

    // Verify it was persisted
    $data = json_decode(file_get_contents($this->manager->path()), true);
    expect($data['agents']['defaults']['model']['primary'])->toBe('anthropic/claude-sonnet-4-20250514');
});

test('switch_model validates model format', function () {
    $result = $this->tool->execute([
        'action' => 'switch_model',
        'value' => 'invalid-no-slash',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('Invalid model format');
});

test('switch_model requires value', function () {
    $result = $this->tool->execute(['action' => 'switch_model']);

    expect($result->status->value)->toBe('error');
});

test('unknown action returns error', function () {
    $result = $this->tool->execute(['action' => 'unknown']);

    expect($result->status->value)->toBe('error');
});

test('set parses JSON array values', function () {
    $result = $this->tool->execute([
        'action' => 'set',
        'key' => 'agents.defaults.model.fallbacks',
        'value' => '["openai/gpt-4o","ollama/llama3.2"]',
    ]);

    expect($result->status->value)->toBe('success');

    $data = json_decode(file_get_contents($this->manager->path()), true);
    expect($data['agents']['defaults']['model']['fallbacks'])->toBe(['openai/gpt-4o', 'ollama/llama3.2']);
});

test('set parses boolean values', function () {
    $this->tool->execute([
        'action' => 'set',
        'key' => 'agents.defaults.maxIterations',
        'value' => '15',
    ]);

    $data = json_decode(file_get_contents($this->manager->path()), true);
    expect($data['agents']['defaults']['maxIterations'])->toBe(15);
});

test('set parses integer values', function () {
    $this->tool->execute([
        'action' => 'set',
        'key' => 'agents.defaults.maxIterations',
        'value' => '12',
    ]);

    $data = json_decode(file_get_contents($this->manager->path()), true);
    expect($data['agents']['defaults']['maxIterations'])->toBe(12);
});

test('set toggles conversation history prompt mode', function () {
    $result = $this->tool->execute([
        'action' => 'set',
        'key' => 'agents.defaults.context.conversationHistoryInSystemPrompt',
        'value' => 'true',
    ]);

    expect($result->status->value)->toBe('success');

    $data = json_decode(file_get_contents($this->manager->path()), true);
    expect($data['agents']['defaults']['context']['conversationHistoryInSystemPrompt'])->toBeTrue();
});

test('get masks secrets nested under the requested key', function () {
    $config = json_decode((string) file_get_contents($this->tmpDir . '/workspace/openclaw.json'), true);
    $config['api'] = ['key' => 'coqui-api-secret', 'port' => 3300];
    $config['mcp'] = ['servers' => ['x' => ['headers' => ['Authorization' => 'Bearer mcp-secret-token'], 'env' => ['GITHUB_TOKEN' => 'ghtoken-secret']]]];
    file_put_contents($this->tmpDir . '/workspace/openclaw.json', json_encode($config));
    $this->manager->load();

    $api = $this->tool->execute(['action' => 'get', 'key' => 'api']);
    $provider = $this->tool->execute(['action' => 'get', 'key' => 'models.providers.openai']);
    $mcp = $this->tool->execute(['action' => 'get', 'key' => 'mcp']);

    expect($api->content)->not->toContain('coqui-api-secret')->toContain('3300')
        ->and($provider->content)->not->toContain('sk-secret123')->toContain('gpt-4o')
        ->and($mcp->content)->not->toContain('mcp-secret-token')->not->toContain('ghtoken-secret');
});

test('show masks the API key and nested secrets', function () {
    $config = json_decode((string) file_get_contents($this->tmpDir . '/workspace/openclaw.json'), true);
    $config['api'] = ['key' => 'coqui-api-secret'];
    $config['mcp'] = ['servers' => ['x' => ['headers' => ['Authorization' => 'Bearer mcp-secret-token']]]];
    file_put_contents($this->tmpDir . '/workspace/openclaw.json', json_encode($config));
    $this->manager->load();

    $result = $this->tool->execute(['action' => 'show']);

    expect($result->content)->not->toContain('coqui-api-secret')
        ->not->toContain('mcp-secret-token')
        ->toContain('openai/gpt-4o');
});

test('set lets the agent lower maxIterations but not raise or unbound it', function (string $value, string $status) {
    $result = $this->tool->execute(['action' => 'set', 'key' => 'agents.defaults.maxIterations', 'value' => $value]);

    expect($result->status->value)->toBe($status);
})->with([
    'lower' => ['10', 'success'],
    'same' => ['25', 'success'],
    'raise' => ['100000', 'error'],
    'zero (unlimited)' => ['0', 'error'],
    'negative' => ['-1', 'error'],
    'not a number' => ['lots', 'error'],
]);

test('set refuses to raise maxIterations above the built-in default when none is configured', function () {
    $config = json_decode((string) file_get_contents($this->tmpDir . '/workspace/openclaw.json'), true);
    unset($config['agents']['defaults']['maxIterations']);
    file_put_contents($this->tmpDir . '/workspace/openclaw.json', json_encode($config));
    $this->manager->load();

    $over = $this->tool->execute(['action' => 'set', 'key' => 'agents.defaults.maxIterations', 'value' => (string) (\CoquiBot\Coqui\Contract\CoquiDefaults::MAX_ITERATIONS + 1)]);

    expect($over->status->value)->toBe('error');
});
