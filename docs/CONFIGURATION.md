---
title: Configuration
description: openclaw.json schema, agent defaults, model providers, API config, environment overrides, and setup wizard
---

# Configuration

Coqui uses an `openclaw.json` file as its single source of configuration. This format is fully compatible with the [OpenClaw](https://github.com/openclaw/openclaw) standard, meaning you can use an existing OpenClaw config file with Coqui without any modifications.

## Config File Location

Coqui resolves the config file in this order:

1. **`--config` CLI flag** — explicit path to a config file
2. **`./openclaw.json`** — in the current working directory
3. **Bundled default** — the `openclaw.json` shipped with Coqui
4. **Setup wizard** — if no config exists in interactive mode, the wizard runs automatically

```bash
# Use a specific config file
coqui --config /path/to/openclaw.json

# Default: looks for ./openclaw.json in the working directory
coqui

# Run the setup wizard directly — no REPL, no session
coqui --wizard
coqui -w
```

## How Config Changes Are Applied

Most config changes require a restart to take effect. Coqui normally loads configuration once at boot and constructs internal components from it. A restart ensures every component is freshly initialized with the new values.

**After editing config, restart using one of these methods:**

| Change Source | How Restart Happens |
| ------------- | ------------------- |
| `coqui --wizard` / `coqui -w` | Edit config without starting the REPL — changes apply on next launch |
| `/config edit` (setup wizard) | Coqui prompts: "Restart now to apply?" — confirm to restart immediately |
| API (`PATCH /api/v1/config/context`) | Saves supported context toggles, but you still need to restart for them to take effect |
| Manual edit in your editor | Use `/restart` in the REPL, or the `restart_coqui` agent tool |
| Agent `config` tool (set/switch_model/context toggle) | Agent can call `restart_coqui`, or you can use `/restart` |

A restart re-reads `openclaw.json`, re-discovers toolkit packages, re-seeds roles, and reconstructs all providers and resolvers from scratch.

## Config Schema

### Minimal Config

The simplest valid config only needs a primary model:

```json
{
    "agents": {
        "defaults": {
            "model": {
                "primary": "ollama/qwen3:latest"
            }
        }
    }
}
```

### Full Config Reference

```json
{
    "agents": {
        "defaults": {
            "model": {
                "primary": "ollama/qwen3:latest",
                "fallbacks": ["ollama/llama3.2:latest"],
                "utility": "ollama/gemma3:4b",
                "imageModel": "ollama/jmorgan/z-image-turbo:fp8",
                "imageFallbacks": ["openai/gpt-image-1.5"]
            },
            "roles": {
                "orchestrator": "ollama/qwen3:latest",
                "coder": "anthropic/claude-opus-4-6",
                "reviewer": "openai/gpt-4.1",
                "vision": "gemini/gemini-2.5-flash"
            },
            "workspace": "~/.coqui/.workspace",
            "persona": "caelum",
            "maxIterations": 256,
            "backgroundTaskMaxIterations": 512,
            "shellAllowedCommands": ["php", "git", "grep", "find", "cat", "ls"],
            "allowSudo": false,
            "shell": {
                "sandboxWrites": true,
                "scrubEnvironment": true
            },
            "blacklist": ["/pattern-to-block/i"],
            "mcp": {
                "allowedStdioCommands": [
                    ["npx", "-y", "@modelcontextprotocol/server-github"]
                ],
                "deniedStdioCommands": [
                    ["uvx", "mcp-server-fetch"]
                ]
            },
            "mounts": [
                {
                    "path": "/home/user/data",
                    "alias": "data",
                    "access": "ro",
                    "description": "Shared datasets"
                }
            ],
            "memory": {
                "embeddingModel": "openai/text-embedding-3-small",
                "autoExtract": false,
                "coreSummaryMaxTokens": 500,
                "coreSummaryEntryLimit": 50
            },
            "context": {
                "conversationHistoryInSystemPrompt": false,
                "autoSummarizeMode": "token",
                "autoSummarizeThreshold": 64,
                "autoSummarizeTurnThreshold": 20,
                "autoSummarizeKeepRecent": 15,
                "keepRecentTurns": 24,
                "budgetSafetyMarginPercent": 20,
                "budgetExitThreshold": 0.85,
                "budgetExitWrapUpIterations": 2
            },
            "maxTools": 0,
            "toolkitTokenBudget": 20000,
            "toolkitPromotionBudgetPercent": 60,
            "codeReview": {
                "enabled": true,
                "maxRounds": 2,
                "autoIterate": true
            },
            "editHistory": {
                "retentionDays": 7
            },
            "hints": true,
            "footer": {
                "backgroundTasks": true
            }
        }
    },
    "images": {
        "ownerName": "Your Name",
        "providers": {
            "openai": {
                "model": "gpt-image-1.5",
                "baseUrl": "https://api.openai.com/v1",
                "quality": "standard",
                "size": "1024x1024"
            },
            "ollama": {
                "model": "jmorgan/z-image-turbo:fp8",
                "baseUrl": "http://localhost:11434"
            }
        }
    },
    "models": {
        "mode": "merge",
        "providers": {
            "ollama": {
                "baseUrl": "http://localhost:11434/v1",
                "api": "openai-completions",
                "models": []
            }
        }
    },
    "api": {
        "key": "your-api-key",
        "tasks": {
            "maxConcurrent": 6
        }
    }
}
```

## Agent Defaults (`agents.defaults`)

### `model`

The primary model used when no role-specific mapping exists.

| Key | Type | Required | Description |
| --- | ---- | -------- | ----------- |
| `primary` | string | yes | Model string in `provider/model` format |
| `fallbacks` | string[] | no | Fallback models tried in order if the primary fails |
| `utility` | string | no | Cheap/fast model for internal tasks (titles, summaries, memory compression) |
| `imageModel` | string | no | Default image-generation model in `provider/model` format (see [Image generation](#image-generation)) |
| `imageFallbacks` | string[] | no | Image models tried in order when `imageModel` fails |

```json
{
    "model": {
        "primary": "ollama/qwen3:latest",
        "fallbacks": ["ollama/llama3.2:latest", "openai/gpt-4.1-mini"],
        "utility": "ollama/gemma3:4b"
    }
}
```

**Utility model resolution**: `model.utility` → `COQUI_UTILITY_MODEL` env var → title-generator role model → primary model.

### Image generation

Image-generation toolkits and the `/image` REPL command use their own model settings, independent of the active chat or role model. The image model lives under `agents.defaults.model`, and per-vendor settings live in a top-level `images` block:

| Key | Type | Description |
| --- | ---- | ----------- |
| `agents.defaults.model.imageModel` | string | Default image model in `provider/model` format |
| `agents.defaults.model.imageFallbacks` | string[] | Image models tried in order by image-capable toolkits |
| `images.ownerName` | string | Default owner name embedded in generated-image metadata unless explicitly overridden |
| `images.providers.<vendor>` | object | Vendor defaults: `model` (required), `baseUrl`, and for OpenAI `quality` and `size` |

```json
{
    "agents": {
        "defaults": {
            "model": {
                "primary": "ollama/qwen3:latest",
                "imageModel": "ollama/jmorgan/z-image-turbo:fp8",
                "imageFallbacks": ["openai/gpt-image-1.5"]
            }
        }
    },
    "images": {
        "providers": {
            "openai": {
                "model": "gpt-image-1.5",
                "baseUrl": "https://api.openai.com/v1",
                "quality": "standard",
                "size": "1024x1024"
            },
            "ollama": {
                "model": "jmorgan/z-image-turbo:fp8",
                "baseUrl": "http://localhost:11434"
            }
        }
    }
}
```

The setup wizard writes this shape. Current first-party image support targets `openai` and `ollama`. For Ollama, Coqui checks whether the resolved image model is already available locally and asks for confirmation before pulling a missing model.

### `roles`

Map agent roles to specific models. This enables cost-efficient orchestration where the orchestrator uses a fast, cheap model and delegates expensive work to stronger models.

| Role | Description | Default |
| ---- | ----------- | ------- |
| `orchestrator` | Routes tasks, handles simple queries | Primary model |
| `coder` | Writes and refactors code | Primary model |
| `reviewer` | Reviews code for bugs, security, style | Primary model |
| `vision` | Analyzes images | Primary model |

Custom roles defined in `workspace/roles/` are also resolved here.

```json
{
    "roles": {
        "orchestrator": "openai/gpt-4.1-mini",
        "coder": "anthropic/claude-opus-4-6",
        "reviewer": "openai/gpt-4.1",
        "vision": "gemini/gemini-2.5-flash"
    }
}
```

**Resolution priority**: role file `model` field > `agents.defaults.roles` mapping (for example `agents.defaults.roles.orchestrator`) > primary model.

### `workspace`

The sandboxed directory where Coqui reads and writes files. Supports `~` (home directory), relative paths (resolved against the project root), and absolute paths.

| Value | Behavior |
| ----- | -------- |
| `~/.coqui/.workspace` | Default — uses a shared workspace in your home directory |
| `/path/to/workspace` | Absolute path to any directory |

**Default behavior** (when not set): uses `~/.coqui/.workspace` in your home directory. This prevents session sprawl across directories.

**Single location for all runtime state**: every piece of runtime state lives inside the resolved workspace — sessions and databases (`data/`), skills (`skills/`), roles, loops, schedules, and the launcher's service PID files (`pids/`). The bash launcher (`bin/coqui`) resolves the PID directory using the same precedence as the rest of Coqui: `--workspace` / `COQUI_WORKSPACE` → `agents.defaults.workspace` in `openclaw.json` → `~/.coqui/.workspace`. Nothing is written to a `.workspace/` folder in the project root. (If the resolved `pids/` directory is not writable — for example a root-owned Docker mount — the launcher falls back to `/tmp/coqui-pids-<uid>`.)

### `persona`

Optional default startup persona name. The value must match a directory under `workspace/personas/{name}/` that contains a `soul.md` file.

```json
{
    "persona": "caelum"
}
```

When set, Coqui tries to reattach the current `.coqui-session` if it already belongs to that persona. Otherwise it resumes the most recent session for that persona or creates a new one.

### Tool profile (`agents.defaults.toolProfile`)

Controls how many tools load into a fresh session's context.

- `lean` (default) — only a bootstrap core loads eagerly (filesystem, shell,
  `tool_search`, `config`, `credentials`, `coqui_toolkits`, `coqui_skills`,
  `php_execute`). Everything else — memory tools, loops, schedules, artifacts,
  projects, web, vision, sub-agents, and more — is deferred and discovered on
  demand via `tool_search`. This keeps the prompt small enough for local Ollama
  models. Passive memory recall and active-project context still apply.
- `full` — restores the legacy behavior where every built-in toolkit and
  standalone tool loads eagerly.

Advanced: `agents.defaults.coreToolkits` accepts an explicit list of toolkit
class basenames to keep eager, overriding the profile preset. Per-toolkit
overrides via `/toolkits` (or `.workspace/toolkit-loading.json`) still apply on
top — e.g. pin `MemoryToolkit` eager without switching to `full`.

### `maxIterations`

Global limit on agent loop iterations per turn. Each iteration is one LLM call that may include tool use. Default: `256`.

Set to `0` for unlimited iterations (the agent runs until it calls the `done` tool or encounters an error). Background tasks are clamped separately via `backgroundTaskMaxIterations`.

Per-role overrides are configured in role `.md` files via the `max_iterations` frontmatter field.

### `emptyResponse`

Policy for turns where the model returns no content and no tool calls. Some serving stacks (notably Ollama with qwen/gemma thinking models) route the entire completion into the reasoning channel and leave content empty; without a policy the agent would silently retry until `maxIterations`.

```json
{
    "agents": {
        "defaults": {
            "emptyResponse": {
                "handling": "nudge_then_fallback",
                "maxRetries": 2
            }
        }
    }
}
```

| Key | Type | Default | Description |
| --- | ---- | ------- | ----------- |
| `handling` | string | `nudge_then_fallback` | `ignore` (legacy silent retry), `nudge` (corrective retry, then fail with `empty_response`), `nudge_then_fallback` (corrective retry, then surface accumulated reasoning as the answer), or `fallback` (immediately surface reasoning as the answer) |
| `maxRetries` | int | `2` | Corrective retries before the policy gives up or falls back |

For thinking-capable Ollama models you can also disable thinking entirely with the per-model `reasoningEffort` field (see [Model Catalog](#model-catalog)) or the `/thinking` REPL command.

### `backgroundTaskMaxIterations`

Maximum iterations any single background task can run. This is a per-task safety limit that prevents unattended tasks from running indefinitely. Default: `512`.

```json
{
    "agents": {
        "defaults": {
            "backgroundTaskMaxIterations": 512
        }
    }
}
```

This cap applies to all background tasks: loop iterations, schedule-triggered tasks, and API-created tasks.

### `childBackgroundTasks` (removed)

This key has been removed. Agents no longer have background-task tools, so child agents cannot create background tasks and the setting no longer has any effect. If it is present in an existing `openclaw.json` it is silently ignored. Agent-driven async work now goes through loops — `loop_start(definition: "goal-driven", goal: "…")`. See [BACKGROUND-TASKS.md](BACKGROUND-TASKS.md) and [LOOPS.md](LOOPS.md).

### `shellAllowedCommands`

An opt-in restrictive allowlist for shell commands. When omitted, all commands are permitted (open-by-default mode), subject to built-in deny patterns and the `allowSudo` setting below. When set to a non-empty array, only commands whose first word matches the list are permitted, and shell metacharacters (`;`, `&&`, `|`, `$(...)`, backticks) are also blocked to prevent allowlist bypass.

```json
{
    "agents": {
        "defaults": {
            "shellAllowedCommands": [
                "php", "git", "grep", "find", "cat", "head", "tail", "wc", "ls",
                "curl", "wget", "make", "sort", "uniq", "sed", "awk", "diff"
            ]
        }
    }
}
```

Omit the key entirely to keep the default open-by-default behavior.

### `allowSudo`

Controls whether the `sudo` command is permitted. Defaults to `false` (sudo is blocked via the denied-commands list). Set to `true` to allow sudo — it will still be subject to `CatastrophicBlacklist` and `InteractiveApprovalPolicy`.

```json
{
    "agents": {
        "defaults": {
            "allowSudo": true
        }
    }
}
```

> **`exec` `cwd` parameter** — the `exec` tool accepts an optional `cwd` argument. Relative paths are resolved from the default working directory (project root). If the path does not exist or is not a directory, the tool returns an error.

### `shell`

Two safety switches for the `exec` shell tool. Both default to `true`, and both apply even with `--auto-approve` or `--unsafe`. Turning either off weakens Coqui's sandbox, so leave them on unless you have a specific reason.

| Key | Type | Default | Description |
| --- | ---- | ------- | ----------- |
| `sandboxWrites` | bool | `true` | Reject shell commands whose write targets (redirections such as `>` and `>>`, and the destinations of `cp`, `mv` and similar) resolve outside the workspace and the configured `mounts`. The agent is told about the sandbox in its tool description. |
| `scrubEnvironment` | bool | `true` | Run shell subprocesses with a sanitized environment. Variables whose names contain `KEY`, `TOKEN`, `SECRET`, `PASSWORD`, `CREDENTIAL` or `AUTH` are removed, so API keys loaded into Coqui do not leak into commands the agent runs. Common safe variables (`PATH`, `HOME`, locale, terminal, `GIT_*`, `COMPOSER_*`, `NODE_*`, `NPM_*`, `SSH_AUTH_SOCK`, `DOCKER_*` and similar) are always kept, and the safe list wins: a safe-prefixed variable is kept even when its name contains `TOKEN` or `AUTH`, so secrets such as `NPM_TOKEN`, `COMPOSER_AUTH`, `DOCKER_AUTH_CONFIG` or a `GIT_*` token still reach shell commands. |

```json
{
    "shell": {
        "sandboxWrites": true,
        "scrubEnvironment": true
    }
}
```

These map to `agents.defaults.shell.sandboxWrites` and `agents.defaults.shell.scrubEnvironment`. The agent cannot change them through the `config` tool.

### `blacklist`

Additional regex patterns to add to the catastrophic blacklist. These patterns block commands regardless of `--auto-approve` or `--unsafe` mode. The hardcoded patterns (`rm -rf /`, `shutdown`, fork bombs, etc.) cannot be removed.

```json
{
    "blacklist": [
        "/\\bdrop\\s+database\\b/i",
        "/\\btruncate\\b/i"
    ]
}
```

Each entry must be a complete PHP regular expression, delimiters and flags included. A pattern that does not compile is silently dropped at load time and blocks nothing, so test new patterns (for example with `php -r 'var_dump(preg_match("/your-pattern/", ""));'`, which prints `int(0)` for a valid pattern and `bool(false)` for an invalid one) before relying on them.

### MCP configuration

The MCP (Model Context Protocol) client engine, per-server tool exposure, and HTTP API ship in Coqui core by default (`CoquiBot\Coqui\Mcp\*`) — no additional package is required to connect MCP servers and use their tools. Server definitions live in `.workspace/mcp.json` (Claude Desktop-style `mcpServers` format), and per-server loading-mode overrides (eager/deferred) live in `.workspace/toolkit-loading.json` alongside other toolkit loading state. OAuth tokens for authenticated servers are stored under `.workspace/.mcp-tokens/`.

Per-server MCP tools are added as candidate toolkits and participate in Coqui's normal budget-gated loading model — **deferred by default**, promotable to eager per server.

Interactive management (the `mcp` agent tool, the `/mcp` REPL command, and browser-based OAuth) is provided by the optional [`coquibot/coqui-toolkit-mcp-client`](https://github.com/carmelosantana/coqui-toolkit-mcp-client) package. Without it, MCP servers can still be managed fully over the HTTP API (see [docs/API.md](API.md)), with the exception of the OAuth endpoint, which requires the toolkit.

### `mcp.allowedStdioCommands`

An optional exact-match allowlist for stdio MCP server launch tuples. Each entry is an array in the form `[command, arg1, arg2, ...]`. When this key is present and non-empty, Coqui only permits MCP servers whose configured command and args exactly match one of the allowed tuples.

```json
{
    "agents": {
        "defaults": {
            "mcp": {
                "allowedStdioCommands": [
                    ["npx", "-y", "@modelcontextprotocol/server-github"],
                    ["uvx", "mcp-server-sqlite", "--db-path", "/srv/readonly.db"]
                ]
            }
        }
    }
}
```

### `mcp.deniedStdioCommands`

An optional exact-match denylist for stdio MCP server launch tuples. Denied tuples always win over the allowlist. Use this when you want to keep the normal open posture for most MCP servers but explicitly block known-risk launch definitions.

```json
{
    "agents": {
        "defaults": {
            "mcp": {
                "deniedStdioCommands": [
                    ["docker", "run", "--privileged", "dangerous-mcp"],
                    ["npx", "-y", "unapproved-package"]
                ]
            }
        }
    }
}
```

Both MCP stdio policy keys are enforced by the shared MCP management service, so the same command policy applies to the `/mcp` REPL command, the `mcp` tool, and the MCP HTTP API.

### `mounts`

Declare external directory mounts that give agents access to directories outside the workspace. Mounts appear as symlinks under `workspace/mnt/{alias}`.

| Field | Required | Default | Description |
| ----- | -------- | ------- | ----------- |
| `path` | yes | — | Absolute path to the external directory (must exist) |
| `alias` | yes | — | Short name used as the symlink name |
| `access` | no | `ro` | `ro` (read-only) or `rw` (read-write) |
| `description` | no | `''` | Description shown in the agent's storage map |

```json
{
    "mounts": [
        {
            "path": "/home/user/datasets",
            "alias": "datasets",
            "access": "ro",
            "description": "Training datasets (read-only)"
        },
        {
            "path": "/home/user/projects/my-app",
            "alias": "my-app",
            "access": "rw",
            "description": "External application source code"
        }
    ]
}
```

**Access control**:

- Mounts default to read-only unless explicitly set to `rw`
- Child agents (spawned via `spawn_agent`) always get read-only access regardless of the mount's declared access level
- Write protection is enforced at the filesystem toolkit level

### `memory`

Configure the memory system's embedding provider for semantic search.

| Key | Type | Description |
| --- | ---- | ----------- |
| `embeddingModel` | string | Embedding provider in `provider/model` format |
| `enabled` | bool | Set to `false` to disable memory embeddings entirely |
| `autoExtract` | bool | Extract memories automatically after every turn (default `false`) |
| `coreSummaryMaxTokens` | int | Token budget for the compressed core-memory summary in the system prompt (default `500`) |
| `coreSummaryEntryLimit` | int | Maximum memories fetched when building the core summary (default `50`) |

```json
{
    "memory": {
        "embeddingModel": "ollama/nomic-embed-text"
    }
}
```

**Auto-detection**: If no embedding model is configured but an `OPENAI_API_KEY` is set, Coqui automatically uses `text-embedding-3-small`. Without any embedding provider, memory still works using SQLite FTS5 keyword search.

### `context`

Configure automatic conversation summarization behavior.

| Key | Type | Default | Description |
| --- | ---- | ------- | ----------- |
| `autoSummarizeMode` | string | `"token"` | Summarization trigger mode: `"token"` (trigger on context window usage), `"turn"` (trigger after N user turns), or `"manual"` (no auto-summarization; use `/summarize` on demand) |
| `conversationHistoryInSystemPrompt` | bool | `false` | When enabled, prior active messages are also rendered into a final `Conversation History` system-prompt block while normal provider replay remains enabled. This duplicates context intentionally so the model can correlate replayed messages with a compact, timestamped history view. `GET /api/v1/server/prompt`, `GET /api/v1/server/budget`, and `/prompt export` can preview the real section when a session is supplied |
| `autoSummarizeThreshold` | int/float | `64` | Token usage percentage that triggers auto-summarization (used when mode is `"token"`). Accepts 1–100 (percentage) or 0.0–1.0 (ratio, auto-converted) |
| `autoSummarizeTurnThreshold` | int | `32` | Number of user turns that triggers auto-summarization (used when mode is `"turn"`) |
| `autoSummarizeKeepRecent` | int | `15` | Turns preserved during auto-summarization (clamped 1–20) |
| `keepRecentTurns` | int | `24` | Default turns preserved during on-demand summarization (`/summarize` and the `summarize_conversation` tool). A configured value is clamped to 1–20 for the per-iteration pruning safety net |
| `budgetSafetyMarginPercent` | int | `20` | Safety margin percentage applied by per-iteration budget pruning to account for token estimation inaccuracy (0–50) |
| `budgetExitThreshold` | float | `0.85` | Context window usage ratio (0.0–1.0) based on the latest provider-reported usage for the current iteration. When crossed, Coqui injects a wrap-up instruction and the agent has `budgetExitWrapUpIterations` iterations to call `done()` before it is force-exited. Set to `0.0` to disable |
| `budgetExitWrapUpIterations` | int | `2` | Number of iterations the agent has to wrap up after the budget exit threshold is crossed. Must be ≥ 1 |

```json
{
    "context": {
        "autoSummarizeMode": "token",
        "autoSummarizeThreshold": 64,
        "autoSummarizeKeepRecent": 32,
        "keepRecentTurns": 24
    }
}
```

**Summarization modes:**

- **`token`** (default) — Summarizes when estimated token usage exceeds `autoSummarizeThreshold` percent of the effective context window. This is the recommended mode: it preserves as much conversation as possible while preventing context overflow.
- **`turn`** — Summarizes after `autoSummarizeTurnThreshold` user turns, regardless of token usage. Useful for predictable summarization behavior on smaller context models.
- **`manual`** — Disables all automatic pre-turn summarization. Use the `/summarize` REPL command, the `summarize_conversation` agent tool, or the API endpoint to summarize on demand. The per-iteration `SummarizePruningStrategy` safety net still fires to prevent context window overflow during agent execution.

Regardless of mode, the per-iteration budget pruning strategy always runs as a safety net to prevent the conversation from exceeding the model's context window within a single turn.

**Budget-based exit:**

When `budgetExitThreshold` is set (default `0.85`), the agent monitors the latest provider-reported context usage for each iteration as a percentage of the effective context window. When usage crosses the threshold, php-agents emits a generic budget warning event and Coqui reacts by injecting a workflow-aware wrap-up instruction that preserves artifacts and project state. The agent then has `budgetExitWrapUpIterations` iterations to call `done()`. If it does not exit gracefully within that wrap-up window, the turn ends with a `budget_exhausted` finish reason.

This budget-based exit complements `maxIterations`; it does not replace the iteration limit. A turn can still stop because the configured iteration cap was reached before or after any budget warning.

### Tool loading budget

These keys control how many tool schemas reach the model. The two budget keys can also be set per role as `agents.defaults.roles.<role>.toolkitTokenBudget` and `agents.defaults.roles.<role>.toolkitPromotionBudgetPercent`, which take precedence over the global value. `maxTools` is global only.

| Key | Type | Default | Description |
| --- | ---- | ------- | ----------- |
| `maxTools` | int | `0` | Cap on the number of tools sent to the model. `0` means unlimited. `tool_search` is always kept. |
| `toolkitTokenBudget` | int | `20000` | Token budget for non-system toolkit schemas. When the total exceeds it, toolkits are deferred and discovered through `tool_search`. |
| `toolkitPromotionBudgetPercent` | int | `60` | Share of `toolkitTokenBudget` (0–100) used to promote frequently used auto-mode toolkits to eager loading. |

Use `/budget` in the REPL or `GET /api/v1/server/budget` to see how the budget was applied.

### `codeReview`

Automated review of code written by spawned coder agents.

| Key | Type | Default | Description |
| --- | ---- | ------- | ----------- |
| `enabled` | bool | `true` | Run the reviewer after coder child agents finish |
| `maxRounds` | int | `2` | Maximum review-then-iterate rounds |
| `autoIterate` | bool | `true` | Send the coder back to fix `NEEDS_CHANGES` verdicts automatically |

### `editHistory`

| Key | Type | Default | Description |
| --- | ---- | ------- | ----------- |
| `retentionDays` | int | `7` | Default age, in days, beyond which `edit_history(action: "prune")` removes edit-history entries and their undo backups when no `prune_days` is given. Must be a positive integer. |

### `notifications`

The notification inbox shown in the REPL and exposed to the agent. Invalid or out-of-range values fall back to the defaults.

| Key | Type | Default | Description |
| --- | ---- | ------- | ----------- |
| `enabled` | bool | `true` | Enable the notification system |
| `replDisplayLimit` | int | `5` | Notifications shown when the REPL is idle |
| `promptInjectionLimit` | int | `10` | Notifications injected into an agent turn's context |
| `retentionHours.informational` | int | `24` | Hours before informational notifications are pruned |
| `retentionHours.actionable` | int | `72` | Hours before actionable notifications are pruned |
| `automation.enabled` | bool | `true` | Process actionable notifications automatically in API mode |
| `automation.processTickSeconds` | int | `10` | Seconds between processing ticks |
| `automation.reclaimTickSeconds` | int | `30` | Seconds between reclaiming expired leases |
| `automation.leaseSeconds` | int | `300` | Lease duration for a claimed notification |
| `automation.batchSize` | int | `5` | Notifications processed per tick |
| `automation.maxAttempts` | int | `3` | Attempts before a notification fails permanently |
| `automation.retryDelaySeconds` | int | `60` | Delay before retrying after a recoverable failure |

### Display: `hints` and `footer`

| Key | Type | Default | Description |
| --- | ---- | ------- | ----------- |
| `hints` | bool | `true` | Show command hints in the REPL. `/hints` toggles it and saves the choice. |
| `footer.backgroundTasks` | bool | `true` | Include active background tasks in the post-turn footer (REPL and the API `complete` event) |

## Model Providers (`models.providers`)

Each provider is a named entry under `models.providers` with connection settings and an optional model catalog.

When available, Coqui hydrates model metadata from the provider API during setup and uses that saved metadata at runtime. If provider metadata is missing or incomplete, Coqui falls back to curated `defaults.json` records and then family-level defaults.

### Provider Configuration

| Field | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| `baseUrl` | string | yes | API endpoint URL |
| `apiKey` | string | no | API key (prefer environment variables instead) |
| `api` | string | yes | API protocol: `openai-completions`, `openai-responses`, `anthropic`, `gemini`, `mistral` |
| `models` | array | no | Model catalog with capabilities and parameters |

### Supported Providers

| Provider | `api` Protocol | Env Variable | Default Base URL |
| -------- | ------------- | ----------- | ---------------- |
| Ollama | `openai-completions` | — | `http://localhost:11434/v1` |
| OpenAI | `openai-completions` | `OPENAI_API_KEY` | `https://api.openai.com/v1` |
| Anthropic | `anthropic` | `ANTHROPIC_API_KEY` | `https://api.anthropic.com/v1` |
| OpenRouter | `openai-completions` | `OPENROUTER_API_KEY` | `https://openrouter.ai/api/v1` |
| xAI (Grok) | `openai-completions` | `XAI_API_KEY` | `https://api.x.ai/v1` |
| Google Gemini | `gemini` | `GEMINI_API_KEY` | `https://generativelanguage.googleapis.com/v1beta` |
| Mistral | `mistral` | `MISTRAL_API_KEY` | `https://api.mistral.ai/v1` |
| MiniMax | `openai-completions` | `MINIMAX_API_KEY` | `https://api.minimax.chat/v1` |
| Claude Code CLI | `claude-cli` | — (uses the binary's own login) | — (local binary) |

Any OpenAI-compatible provider can be added using `openai-completions` as the API protocol.

#### Claude Code CLI provider

The `claude-cli` provider drives the locally installed `claude` binary instead of an HTTP API. Select it with model strings like `claude-cli/sonnet`, `claude-cli/opus`, or `claude-cli/haiku` (a full id such as `claude-cli/claude-opus-4-8` also works). It runs `claude` headless as a raw chat completion (`--tools "" --bare --no-session-persistence`), so Coqui's own toolkits and safety model stay in control and provider-reported token usage feeds normal budgeting.

Authentication is delegated entirely to your `claude` install — Coqui does not manage an API key for this provider, so no env var or `apiKey` is required. **Authenticate the binary with an Anthropic API key** (`ANTHROPIC_API_KEY`, or a token from `claude setup-token` / `CLAUDE_CODE_OAUTH_TOKEN`). Coqui does **not** use a claude.ai Pro/Max subscription login on your behalf: Anthropic's terms forbid programmatic/third-party use of consumer subscription auth, and the Agent SDK docs state *"Unless previously approved, Anthropic does not allow third party developers to offer claude.ai login or rate limits for their products … Please use the API key authentication methods instead."* Override the binary path with a `"binary"` field on the provider config if `claude` is not on `PATH`.

Model discovery is **curated, not live** — the `claude` CLI exposes no model-list command, so only the stable aliases (`fable`/`opus`/`sonnet`/`haiku`) plus anything in this catalog are listed. A full model id still works in the model string (`claude-cli/claude-opus-4-8`).

### Model Catalog

Each model entry describes capabilities and parameters:

```json
{
    "id": "qwen3:latest",
    "name": "Qwen 3",
    "reasoning": false,
    "input": ["text"],
    "contextWindow": 128000,
    "maxTokens": 8192,
    "family": "qwen",
    "toolCalls": true,
    "metadataSource": "provider-api",
    "alias": "qwen",
    "numCtx": 32768,
    "cost": {
        "input": 0,
        "output": 0,
        "cacheRead": 0,
        "cacheWrite": 0
    }
}
```

| Field | Type | Default | Description |
| ----- | ---- | ------- | ----------- |
| `id` | string | — | Model identifier as recognized by the provider |
| `name` | string | `id` | Display name |
| `reasoning` | bool | `false` | Whether this is a reasoning/chain-of-thought model |
| `input` | string[] | `["text"]` | Input capabilities: `text`, `image`, `audio` |
| `contextWindow` | int | `4096` | Maximum context window in tokens |
| `maxTokens` | int | `2048` | Maximum output tokens |
| `family` | string | inferred | Model family key used for fallback defaults |
| `toolCalls` | bool | `false` | Whether the model supports tool or function calling |
| `thinking` | bool | `false` | Whether the model exposes a separate thinking/reasoning mode |
| `metadataSource` | string | — | Where the saved metadata came from: `provider-api`, `provider-inspection`, `static-fallback`, `family-default`, or `heuristic` |
| `fieldSources` | object | — | Optional per-field source map for resolved limits |
| `alias` | string | — | Short alias for quick reference (e.g., `"opus"`) |
| `numCtx` | int | — | Ollama-specific context override (useful for memory-constrained setups) |
| `reasoningEffort` | string | — | Reasoning effort for thinking-capable models: `high`, `medium`, `low`, or `none` (disables thinking). Ollama only; also settable from the REPL with `/thinking` |
| `cost` | object | — | Token pricing for cost tracking |

### `models.mode`

Controls how the model catalog is built:

| Mode | Behavior |
| ---- | -------- |
| `merge` | Append your declared models to the provider's discovered models |
| `override` | Use only your declared models, ignore discovery |

If omitted, models are resolved via provider-specific discovery (e.g., Ollama's model list endpoint).

## API Configuration (`api`)

Settings for the launcher-managed HTTP API server (`coqui` or `coqui --api-only`).

| Key | Type | Default | Description |
| --- | ---- | ------- | ----------- |
| `api.key` | string | — | API authentication key (required for network-bound hosts) |
| `api.tasks.maxConcurrent` | int | `6` | Maximum concurrent background tasks |

## Environment Variable Overrides

Several settings can be overridden via environment variables. These take precedence over `openclaw.json` values for their respective concerns:

| Variable | Purpose |
| -------- | ------- |
| `OPENAI_API_KEY` | OpenAI API key |
| `ANTHROPIC_API_KEY` | Anthropic API key |
| `XAI_API_KEY` | xAI API key |
| `GEMINI_API_KEY` | Google Gemini API key |
| `MISTRAL_API_KEY` | Mistral API key |
| `OPENROUTER_API_KEY` | OpenRouter API key |
| `MINIMAX_API_KEY` | MiniMax API key |
| `OLLAMA_HOST` | Ollama base URL (useful for Docker: `http://host.docker.internal:11434`) |
| `COQUI_CHECK_UPDATES` | Check for updates on startup (`true`/`false`, default: `true`) |
| `COQUI_AUTO_UPDATE` | Auto-apply updates on startup (`true`/`false`, default: `false`) |
| `COQUI_AUTO_APPROVE` | Auto-approve tool executions (`true`/`false`, env equivalent of `--auto-approve`) |
| `COQUI_UNSAFE` | Disable script sanitization (`true`/`false`, env equivalent of `--unsafe`) |

API keys set via environment variables are checked fresh on every agent turn, so you can update them at runtime without restarting.

## OpenClaw Compatibility

Coqui natively supports the [OpenClaw](https://github.com/openclaw/openclaw) configuration format. You can use your existing `openclaw.json` with Coqui without any modifications.

### Shared Format (OpenClaw Standard)

These config sections are part of the OpenClaw standard and work identically across OpenClaw-compatible tools:

- **`models.providers.*`** — provider connection settings (baseUrl, apiKey, api protocol)
- **`models.providers.*.models[]`** — model catalog with capabilities and parameters
- **`models.mode`** — merge vs override behavior
- **`agents.defaults.model`** — primary model and fallbacks
- **`agents.defaults.roles`** — role-to-model mapping

### Coqui Extensions

Coqui adds the following keys under `agents.defaults` that are specific to Coqui and safely ignored by other OpenClaw-compatible tools. This is the complete list of keys Coqui reads; each links to its section above.

| Key | Default | Purpose |
| --- | ------- | ------- |
| `agents.defaults.model.primary` | — | Primary model ([`model`](#model)) |
| `agents.defaults.model.fallbacks` | `[]` | Fallback models |
| `agents.defaults.model.utility` | see [resolution order](#model) | Model for internal tasks |
| `agents.defaults.model.imageModel` | — | Default image model ([Image generation](#image-generation)) |
| `agents.defaults.model.imageFallbacks` | `[]` | Image fallback models |
| `agents.defaults.models` | `{}` | Per-model settings; an `alias` entry defines a short model name |
| `agents.defaults.roles` | `{}` | Role-to-model mapping, e.g. `agents.defaults.roles.orchestrator` ([`roles`](#roles)) |
| `agents.defaults.workspace` | `~/.coqui/.workspace` | Workspace directory path |
| `agents.defaults.persona` | — | Default startup persona |
| `agents.defaults.toolProfile` | `lean` | Tool profile preset |
| `agents.defaults.coreToolkits` | profile preset | Toolkits always loaded eagerly |
| `agents.defaults.mounts` | `[]` | External directory mounts |
| `agents.defaults.maxIterations` | `256` | Agent iteration budget |
| `agents.defaults.backgroundTaskMaxIterations` | `512` | Per-task background iteration cap |
| `agents.defaults.emptyResponse.handling` | `nudge_then_fallback` | Policy for empty model turns |
| `agents.defaults.emptyResponse.maxRetries` | `2` | Corrective retries for empty turns |
| `agents.defaults.shellAllowedCommands` | `[]` | Opt-in shell command allowlist (empty = open-by-default) |
| `agents.defaults.allowSudo` | `false` | Allow `sudo` commands |
| `agents.defaults.shell.sandboxWrites` | `true` | Confine shell writes to workspace and mounts ([`shell`](#shell)) |
| `agents.defaults.shell.scrubEnvironment` | `true` | Strip secrets from shell subprocess environments |
| `agents.defaults.blacklist` | `[]` | Additional catastrophic blacklist patterns |
| `agents.defaults.mcp.allowedStdioCommands` | `[]` | Exact-match allowlist for stdio MCP server command tuples |
| `agents.defaults.mcp.deniedStdioCommands` | `[]` | Exact-match denylist for stdio MCP server command tuples |
| `agents.defaults.memory.enabled` | `true` | Memory embeddings on/off ([`memory`](#memory)) |
| `agents.defaults.memory.embeddingModel` | auto-detected | Embedding provider |
| `agents.defaults.memory.autoExtract` | `false` | Extract memories after every turn |
| `agents.defaults.memory.coreSummaryMaxTokens` | `500` | Core-memory summary token budget |
| `agents.defaults.memory.coreSummaryEntryLimit` | `50` | Memories fetched for the core summary |
| `agents.defaults.context.autoSummarizeMode` | `token` | Summarization trigger ([`context`](#context)) |
| `agents.defaults.context.autoSummarizeThreshold` | `64` | Token-usage percentage that triggers summarization |
| `agents.defaults.context.autoSummarizeTurnThreshold` | `32` | Turn count that triggers summarization |
| `agents.defaults.context.autoSummarizeKeepRecent` | `15` | Turns kept by auto-summarization |
| `agents.defaults.context.keepRecentTurns` | `24` | Turns kept by `/summarize` |
| `agents.defaults.context.conversationHistoryInSystemPrompt` | `false` | Also render history into the system prompt |
| `agents.defaults.context.budgetSafetyMarginPercent` | `20` | Safety margin for budget pruning |
| `agents.defaults.context.budgetExitThreshold` | `0.85` | Context usage that triggers wrap-up |
| `agents.defaults.context.budgetExitWrapUpIterations` | `2` | Iterations allowed to wrap up |
| `agents.defaults.maxTools` | `0` | Tool count cap, `0` = unlimited ([Tool loading budget](#tool-loading-budget)) |
| `agents.defaults.toolkitTokenBudget` | `20000` | Token budget before toolkits are deferred |
| `agents.defaults.toolkitPromotionBudgetPercent` | `60` | Share of the budget for promoting auto-mode toolkits |
| `agents.defaults.codeReview.enabled` | `true` | Automated review of coder output ([`codeReview`](#codereview)) |
| `agents.defaults.codeReview.maxRounds` | `2` | Review-then-iterate rounds |
| `agents.defaults.codeReview.autoIterate` | `true` | Iterate automatically on `NEEDS_CHANGES` |
| `agents.defaults.editHistory.retentionDays` | `7` | Edit history retention ([`editHistory`](#edithistory)) |
| `agents.defaults.notifications.enabled` | `true` | Notification system on/off ([`notifications`](#notifications)) |
| `agents.defaults.notifications.replDisplayLimit` | `5` | Notifications shown in the idle REPL |
| `agents.defaults.notifications.promptInjectionLimit` | `10` | Notifications injected into a turn |
| `agents.defaults.notifications.retentionHours.informational` | `24` | Informational notification retention |
| `agents.defaults.notifications.retentionHours.actionable` | `72` | Actionable notification retention |
| `agents.defaults.notifications.automation.enabled` | `true` | Automatic processing in API mode |
| `agents.defaults.notifications.automation.processTickSeconds` | `10` | Processing tick interval |
| `agents.defaults.notifications.automation.reclaimTickSeconds` | `30` | Lease reclaim interval |
| `agents.defaults.notifications.automation.leaseSeconds` | `300` | Claim lease duration |
| `agents.defaults.notifications.automation.batchSize` | `5` | Notifications per tick |
| `agents.defaults.notifications.automation.maxAttempts` | `3` | Attempts before permanent failure |
| `agents.defaults.notifications.automation.retryDelaySeconds` | `60` | Retry delay |
| `agents.defaults.hints` | `true` | REPL command hints ([Display](#display-hints-and-footer)) |
| `agents.defaults.footer.backgroundTasks` | `true` | Background tasks in the post-turn footer |
| `images.*` | — | Image vendor settings ([Image generation](#image-generation)) |
| `api.*` | — | HTTP API server settings |

### Drop-in Migration

To use an existing OpenClaw config with Coqui:

1. Copy your `openclaw.json` to the Coqui project directory (or use `--config`)
2. Run `coqui` — it works immediately
3. Optionally add Coqui-specific settings (workspace, mounts, etc.) as needed

To use a Coqui config with OpenClaw:

1. The OpenClaw tool reads the shared `models.*` and `agents.defaults.model/roles` sections
2. Coqui-specific keys are ignored by OpenClaw — no conflicts

## Managing Config

### Setup Wizard

Run the interactive wizard to create or modify your config:

```bash
# First-time setup (runs automatically if no config exists)
coqui setup

# Re-run from within a session
/config edit
```

When an existing `openclaw.json` is detected, the wizard offers **section-based editing** — you choose which sections to reconfigure while preserving all other settings. You can also start fresh if needed.

The wizard attempts live model discovery first. For providers that expose rich metadata, saved model entries will include discovered token limits and capabilities. Ollama models are additionally inspected per model so the saved `contextWindow` can reflect the real local model context instead of a generic placeholder.

### REPL Commands

| Command | Description |
| ------- | ----------- |
| `/config` | Show current config summary |
| `/config show` | Display raw `openclaw.json` content |
| `/config edit` | Re-run the setup wizard |
| `/config history [status\|on\|off]` | Show or toggle the `conversationHistoryInSystemPrompt` setting |
| `/restart` | Full restart (re-reads config, re-discovers toolkits, re-seeds roles) |

### Credential Management

API keys should be stored as environment variables or in the workspace `.env` file — not directly in `openclaw.json`. The agent manages credentials via the `credentials` tool:

```text
credentials(action: "set", key: "OPENAI_API_KEY", value: "sk-...")
```

Credentials set this way are persisted to `workspace/.env` and take effect immediately via `putenv()` hot-reload.

## Architecture Notes

The configuration system is split between two packages:

- **`php-agents`** provides `OpenClawConfig` — a thin config reader with dot-notation access, alias resolution, and model definition parsing. It has no opinion about workspace management, safety, or agent behavior.
- **Coqui** interprets the config through its own `src/Config/` layer: `BootManager` orchestrates the boot sequence, `RoleResolver` maps roles to models, `WorkspaceResolver` handles workspace path resolution, `MountManager` creates directory mounts, and `CatastrophicBlacklist` reads safety patterns.

This separation means `php-agents` remains a general-purpose provider implementation that any project can use, while Coqui owns all the agent-specific behavior built on top of the shared config format.
