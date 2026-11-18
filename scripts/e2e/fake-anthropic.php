<?php

declare(strict_types=1);

/**
 * Scripted stand-in for the Anthropic Messages API, for end-to-end runs of
 * Coqui through the real AnthropicProvider (HTTP + SSE) with no API key.
 *
 *   FAKE_LOG=/tmp/requests.jsonl php -S 127.0.0.1:8787 scripts/e2e/fake-anthropic.php
 *
 * The first user message picks the scenario with a "SCENARIO:<name>" marker.
 * The step is the number of tool_result blocks already in the conversation.
 * Every request body is appended to FAKE_LOG as one JSON line.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];

$log = getenv('FAKE_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode(['path' => $path, 'body' => $body]) . "\n", FILE_APPEND | LOCK_EX);
}

if ($path === '/v1/models') {
    header('Content-Type: application/json');
    echo json_encode(['data' => [['id' => 'claude-sonnet-5-5', 'type' => 'model', 'display_name' => 'Fake Sonnet']], 'has_more' => false]);
    return true;
}

if ($path !== '/v1/messages') {
    http_response_code(404);
    return true;
}

$scenario = 'plain';
$step = 0;
foreach ($body['messages'] ?? [] as $message) {
    $blocks = is_string($message['content']) ? [['type' => 'text', 'text' => $message['content']]] : $message['content'];
    foreach ($blocks as $block) {
        if (($block['type'] ?? '') === 'text' && preg_match('/SCENARIO:([a-z_]+)/', (string) $block['text'], $m) && $scenario === 'plain') {
            $scenario = $m[1];
        }
        if (($block['type'] ?? '') === 'tool_result') {
            $step++;
        }
    }
}

$toolNames = array_column($body['tools'] ?? [], 'name');

// Non-streaming calls (titles, memory extraction, summaries) get a short text answer.
if (($body['stream'] ?? false) !== true) {
    header('Content-Type: application/json');
    echo json_encode([
        'id' => 'msg_fake', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
        'content' => [['type' => 'text', 'text' => 'E2E title']],
        'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
    ]);
    return true;
}

header('Content-Type: text/event-stream');

$send = static function (string $event, array $data): void {
    echo "event: {$event}\ndata: " . json_encode($data) . "\n\n";
};

$start = static function (int $inputTokens = 50) use ($send): void {
    $send('message_start', ['type' => 'message_start', 'message' => [
        'id' => 'msg_fake', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
        'content' => [], 'stop_reason' => null, 'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => 1],
    ]]);
};

$text = static function (int $index, string $text) use ($send): void {
    $send('content_block_start', ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'text', 'text' => '']]);
    $send('content_block_delta', ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'text_delta', 'text' => $text]]);
    $send('content_block_stop', ['type' => 'content_block_stop', 'index' => $index]);
};

$thinking = static function (int $index) use ($send): void {
    $send('content_block_start', ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']]);
    $send('content_block_delta', ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'I should look before answering.']]);
    $send('content_block_delta', ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'signature_delta', 'signature' => 'SIG-E2E-0001']]);
    $send('content_block_stop', ['type' => 'content_block_stop', 'index' => $index]);
};

$toolUse = static function (int $index, string $name, array $input) use ($send): void {
    $send('content_block_start', ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_e2e_' . $index, 'name' => $name, 'input' => new stdClass()]]);
    $send('content_block_delta', ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode($input)]]);
    $send('content_block_stop', ['type' => 'content_block_stop', 'index' => $index]);
};

$stop = static function (string $reason, int $outputTokens = 5) use ($send): void {
    $send('message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => $reason, 'stop_sequence' => null], 'usage' => ['output_tokens' => $outputTokens]]);
    $send('message_stop', ['type' => 'message_stop']);
};

$pick = static function (array $preferred) use ($toolNames): ?string {
    foreach ($preferred as $name) {
        if (in_array($name, $toolNames, true)) {
            return $name;
        }
    }

    return null;
};

$shellTool = $pick(['exec', 'shell', 'bash', 'run_command', 'execute_command']);

switch ($scenario . ':' . min($step, 1)) {
    case 'tool:0':
        $start();
        $thinking(0);
        $toolUse(1, $pick(['read_file', 'file_read']) ?? 'read_file', ['path' => 'README.md']);
        $stop('tool_use');
        break;

    case 'budget:0':
        // Report 180k input tokens against a 200k window: crosses the 85% budget-exit threshold.
        $start(180000);
        $toolUse(0, $pick(['read_file', 'file_read']) ?? 'read_file', ['path' => 'README.md']);
        $stop('tool_use');
        break;

    case 'gate:0':
        $start();
        $toolUse(0, $shellTool ?? 'exec', ['command' => 'rm -rf / --no-preserve-root']);
        $stop('tool_use');
        break;

    case 'secret:0':
        $start();
        $toolUse(0, $shellTool ?? 'exec', ['command' => 'env']);
        $stop('tool_use');
        break;

    case 'refusal:0':
    case 'refusal:1':
        $start();
        $stop('refusal', 0);
        break;

    case 'refusal_tool:0':
        // A refusal that arrives with a pending tool call: no tool may run.
        $start();
        $toolUse(0, $shellTool ?? 'exec', ['command' => 'touch /tmp/coqui-e2e-should-not-exist']);
        $stop('refusal');
        break;

    case 'maxtokens:0':
        $start();
        $stop('max_tokens', 4096);
        break;

    case 'stream_error:0':
        $start();
        $send('error', ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => "Overloaded\u{202E} " . str_repeat('x', 400)]]);
        break;

    default:
        $start();
        $text(0, 'E2E-FINAL-' . strtoupper($scenario) . ' step=' . $step);
        $stop('end_turn');
}

return true;
