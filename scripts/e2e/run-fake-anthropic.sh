#!/usr/bin/env bash
# End-to-end run of the real `coqui run --no-terminal` against a scripted fake
# Anthropic Messages API (scripts/e2e/fake-anthropic.php). No API key needed.
#
#   scripts/e2e/run-fake-anthropic.sh [scenario ...]
#
# Scenarios: plain tool budget gate secret refusal refusal_tool maxtokens stream_error
# Each run uses a throwaway workspace; request bodies land in $OUT/requests.jsonl.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PORT="${FAKE_PORT:-8787}"
OUT="${E2E_OUT:-$(mktemp -d)}"
SCENARIOS=("$@")
[ ${#SCENARIOS[@]} -eq 0 ] && SCENARIOS=(plain tool budget gate secret refusal refusal_tool maxtokens stream_error)

mkdir -p "$OUT"
export FAKE_LOG="$OUT/requests.jsonl"
: > "$FAKE_LOG"

php -S "127.0.0.1:$PORT" "$ROOT/scripts/e2e/fake-anthropic.php" > "$OUT/server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
for _ in $(seq 1 50); do
    curl -s -o /dev/null "http://127.0.0.1:$PORT/v1/models" && break
    sleep 0.1
done

for scenario in "${SCENARIOS[@]}"; do
    WS="$OUT/ws-$scenario"
    mkdir -p "$WS"
    cat > "$WS/openclaw.json" <<JSON
{
  "agents": { "defaults": {
    "workspace": "$WS",
    "model": { "primary": "anthropic/claude-sonnet-5-5" },
    "roles": { "orchestrator": "anthropic/claude-sonnet-5-5", "coder": "anthropic/claude-sonnet-5-5",
               "reviewer": "anthropic/claude-sonnet-5-5", "title-generator": "anthropic/claude-sonnet-5-5" },
    "context": { "autoSummarizeMode": "manual" }
  } },
  "models": { "mode": "merge", "providers": { "anthropic": {
    "baseUrl": "http://127.0.0.1:$PORT/v1", "apiKeyEnvVar": "ANTHROPIC_API_KEY", "api": "anthropic",
    "models": [ { "id": "claude-sonnet-5-5", "name": "Fake Sonnet", "contextWindow": 200000, "maxTokens": 8192, "toolCalls": true } ]
  } } }
}
JSON
    echo "=== scenario: $scenario"
    set +e
    (cd "$ROOT" && ANTHROPIC_API_KEY=sk-ant-e2e-fake-key-0000 COQUI_AUTO_APPROVE=true \
        timeout 120 php bin/coqui-console run --no-terminal --workspace "$WS" --format json \
        --prompt "SCENARIO:$scenario please answer") > "$OUT/$scenario.json" 2> "$OUT/$scenario.err"
    echo "exit=$?"
    set -e
    cat "$OUT/$scenario.json"; echo
    tail -5 "$OUT/$scenario.err"
done

echo "=== requests logged: $(wc -l < "$FAKE_LOG") in $FAKE_LOG"
