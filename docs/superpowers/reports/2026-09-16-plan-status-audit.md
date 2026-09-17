# Superpowers plan status audit — 2026-09-16

Audit of every plan in `docs/superpowers/plans/` against what reached `main` (`origin/main` @ `79d87b3`).

**How status was decided.** Each status rests on at least one of these:

- a merged PR from `gh pr list --state merged`;
- a merge commit checked with `git merge-base --is-ancestor <sha> origin/main`;
- a check that the plan's files exist (or were removed) in `src/`;
- the phase status reports in `docs/superpowers/reports/`;
- the project memory notes.

Unchecked `- [ ]` boxes do not count as evidence. Every plan has 0 checked boxes, including the ones that shipped. `main`'s history was rewritten during the org move, but every merge SHA cited below (#145–#168) is still an ancestor of `origin/main`. PR numbers are from `carmelosantana/coqui` unless marked `app#` (`carmelosantana/coqui-app`).

| Plan | Status | Evidence | Open follow-ups |
| --- | --- | --- | --- |
| 2026-07-07-lean-default | shipped | PR #146 → `bd83abc`; `src/Config/ToolProfileResolver.php` present | — |
| 2026-07-07-mcp-client-core-migration | shipped | PR #145 → `75c7b9f`; `src/Mcp/` present | — |
| 2026-07-10-background-detool | shipped | PR #148 → `c871c17` (branch merge `b1c6716`); `src/Toolkit/BackgroundTaskToolkit.php` absent | — |
| 2026-07-10-backstory-formats-extraction | superseded | Shipped in PR #146 (`1f3564b`, extractors and 3 deps moved to `coqui-toolkit-backstory-formats`). PR #157 (`c578aff`) then removed the whole generator, and the `BackstoryExtractorDiscovery` hook is gone from `src/`. `coqui-toolkit-backstory` v0.1.0 took over the formats mod's extractors. | Out of scope for this core plan; tracked in Kanboard #4427, Task 3 of [the sibling-repos hygiene plan](../plans/2026-09-16-coquibot-sibling-repos-hygiene.md). Do **not** push the local commit `2cbfe9c` as it is: its message has a `Co-Authored-By` trailer. Its `"abandoned"` target (`coquibot/coqui-toolkit-backstory`) is the correct Packagist name, but re-check it when the commit is reworded. The GitHub repo is still unarchived. |
| 2026-07-10-channels-removal | shipped | PR #148 → `c871c17` (branch merge `1a77426`); `src/Channel*` absent | — |
| 2026-07-10-composer-packagist-removal | shipped | PR #148 → `c871c17` (branch merge `1e1a8c3`); `src/Toolkit/ComposerToolkit.php` absent | — |
| 2026-07-10-repl-slim | shipped | PR #147 → `6a827ba`; `src/Tui/` absent | — |
| 2026-07-11-custom-loop-definitions | shipped | PR #149 → `3a79e0a` (branch merge `4c9351b`). CAP Phase 4 reshaped it: `066e9c4` added PUT create/update + version, and `beaea0a` removed the unwired `deleteDefinition`. | — |
| 2026-07-11-headless-loop-start | shipped | PR #149 → `3a79e0a` (branch merge `bbbfc2e`). CAP Phase 3 extended it (`0315354`, headless workspace inheritance). | — |
| 2026-07-11-loop-events-stream | shipped | PR #151 → `6ac4b2d`; `src/Api/LoopStreamTracker.php` present | — |
| 2026-07-11-loop-live-view | shipped | PR #149 → `3a79e0a`, plus fix PR #150 → `f11e486`. CAP Phase 4 replaced `LoopLiveViewBuilder` with `src/Api/Loop/LoopLiveProducer.php` (`29ac774`, `beaea0a`). | — |
| 2026-07-11-webhooks-extraction | shipped | Part A: PR #152 → `4f1405b` (no `src/Webhook*` in core). Part B: `carmelosantana/coqui-toolkit-webhooks`, tag v0.1.0. | — |
| 2026-07-12-artifacts-files-only | shipped | PR #155 → `bb9492b`; `src/Artifact/ArtifactFileService.php` present | — |
| 2026-07-12-identity-backstory-consolidation | shipped | PR #157 → `c578aff`; `src/Backstory/` absent; `PersonaContextReader` present; toolkit published as Composer package `coquibot/coqui-toolkit-backstory` v0.1.0 (Packagist) from GitHub repo `carmelosantana/coqui-toolkit-backstory` | Non-blocking test minors T2/T4/T7 (see below). User-gated: submit the toolkit to agencoqui.com (memory `identity-backstory-consolidation.md`). |
| 2026-07-13-loop-hardening | shipped | PR #158 → `fd34c75`; `StageGateEvaluator` present in `src/` | — |
| 2026-07-14-mod-public-api-routes | shipped | PR #159 → `7010b80`; `Router::addPublicRoute` present. The webhooks mod adopted it in `coqui-toolkit-webhooks@259a01f`. | — |
| 2026-07-14-structured-questions | shipped | PR #160 → `fe59c91`; `src/Toolkit/QuestionToolkit.php`, `docs/QUESTIONS.md` present | — |
| 2026-07-15-tech-debt-sweep | shipped | PR #161 → `45d2e46` | — |
| 2026-07-16-source-map-removal-and-docs-redesign | shipped | PR #162 → `e9c4de2`; `config/source.json` and `CoquiSourceToolkit` absent; `src/Config/DocumentationIndex.php` present | — |
| 2026-07-20-audit-log-redaction-and-access | shipped | PR #165 → `d5d4df8`; `AuditRedactor`, `src/Storage/AuditLogStore.php` present | — |
| 2026-07-30-phase-0-conformance-gate-scaffold | shipped | PR #166 → `61209e7` (`5c2f0f2`…`ecce668`), plus PR #168 → `b91cdaa` (vendored vectors had been gitignored); `tests/conformance/CoreChecklistTest.php` present | — |
| 2026-07-30-phase-1-persona-rename | shipped | PR #166 → `61209e7` (`0b1f511`…`e93afa8`); `src/Config/Persona*.php`; [phase-1 report](2026-07-30-phase-1-status.md) | Four non-CAP extension sites still use `profile` (see the [phase-6 report](2026-08-04-phase-6-status.md) carry-forwards). |
| 2026-07-30-phase-2-storage-reshape | shipped | PR #166 → `61209e7` (`ae0b8b2`…`e06623f`); [phase-2 report](2026-07-30-phase-2-status.md) | Small tidies (see below): `ArtifactStore` still has 5 `migrateAddColumn` calls. |
| 2026-07-31-phase-3-runtime | shipped | PR #166 → `61209e7` (`f9f0b18`…`211b0b1`); [phase-3 report](2026-07-31-phase-3-status.md) | — |
| 2026-08-04-phase-4-api-surface | shipped | PR #166 → `61209e7` (`db120b9` status); [phase-4 report](2026-08-04-phase-4-status.md) | Turn-events replay is not CAP-normalized (see below). |
| 2026-08-04-phase-5-new-ops-profiles | shipped | PR #166 → `61209e7` (`4b770b5` status); [phase-5 report](2026-08-04-phase-5-status.md) | HTTP child run has no toolkits (see below); `ImportService` is not wired to any route. |
| 2026-08-04-phase-6-gate-green-flutter-delta | shipped | Part A: PR #166 → `61209e7` (`dde68dc`, `fa11b55`). Part B: app#20 (2026-08-06), app#22 (2026-08-07), app releases v0.0.6 and v0.0.7. The [phase-6 report](2026-08-04-phase-6-status.md) still says "unpushed; no PRs opened", which is out of date. | See below. |
| 2026-09-16-coqui-core-cleanup | partially shipped | This plan; tasks 5+ pending. Tasks 1–3 verified #173/#174/#175 on `origin/main`. This audit is Task 4. | — |
| 2026-09-16-coquibot-sibling-repos-hygiene | not started | Added in this commit; no execution yet | — |

## Open follow-ups needing a decision

Each fact below was checked against the code at `79d87b3`.

| Item | Source | Recommendation |
| --- | --- | --- |
| T7 `GET /server/prompt` route-dispatch test | identity-backstory SDD (`.superpowers/sdd/progress.md` in the main checkout, not tracked in git) | **Do (small).** The route is still registered at `src/Command/ApiCommand.php:734`. `tests/Unit/Api/Handler/PromptHandlerTest.php` calls `PromptHandler` directly, so nothing tests that the router sends the request to it. One router-level test would close the coverage gap left when `PromptBackstoryRoutesTest.php` was deleted. |
| Turn-events replay not CAP-frame-normalized | Phase 6 carry-forward | **Do (small–medium TDD).** Code check: `TurnHandler::events` (`src/Api/Handler/TurnHandler.php:76-99`, route at `ApiCommand.php:665`) is a JSON endpoint, not SSE. It returns `{session_id, turn_id, events[{id, event_type, data, created_at}], count}` straight from `SessionStorage::getDecodedTurnEvents`. Error rows are stored as `{message}` with no `code` (`AgentTurnManager.php:226`, `TurnRunCommand.php:277`). The live path sends `{error, code}` (`MessageHandler.php:366-382`). Normalizing error rows on read in `TurnHandler::events` would let the app drop its coalesce workaround. That fits the API-first direction, and no stored data has to change. |
| HTTP-spawned child run is toolkit-less | Phase 5/6 carry-forward | **Defer (keep as documented).** `ChildRunHandler.php:108-113` builds `ChildAgent` with no toolkits. `docs/API.md` §Child Runs already says this is intentional ("text-only… out of scope for this endpoint's current revision"). The child also runs synchronously inside the request. Giving it toolkits would mean pulling `SpawnAgentTool::buildToolkits` out into a shared factory, and it would make long tool-driven runs block the API loop. Revisit only if the app needs tool-using child runs, probably together with async child execution. |
| T2 stub-context test, T4 duplicate ordering test | identity-backstory SDD | **Fold into the T7 change (trivial) or drop.** T2: `PersonaPreferencesContextGateTest` checks that `stub` mode parses, but nothing checks the orchestrator stub text (`OrchestratorAgent.php:1182`); one assertion would cover it. T4: `OrchestratorContextSectionTest.php:86` repeats a test in `PromptLoaderContextTest`. It does no harm, so deleting it is optional. |
| `coqui-toolkit-backstory-formats` abandon/archive, agencoqui.com submission | memory `identity-backstory-consolidation.md` | **Handle in the sibling-repos plan, not here** (Kanboard #4427; [plan](../plans/2026-09-16-coquibot-sibling-repos-hygiene.md) Task 3). Read-only checks on 2026-09-16:<br>• Local commit `2cbfe9c` must **not** be pushed as it is. Its message has a `Co-Authored-By` trailer, so it needs rewording first.<br>• That commit sets `"abandoned": "coquibot/coqui-toolkit-backstory"`, which is the right name: it matches the published repo's `composer.json` and is live on Packagist. `carmelosantana/coqui-toolkit-backstory` returns 404 on Packagist.<br>• `coquibot/coqui-toolkit-backstory-formats` is **not on Packagist** (404), so there is nothing to mark abandoned there. The `composer.json` flag plus archiving the GitHub repo cover it.<br>• Submitting `coqui-toolkit-backstory` to agencoqui.com is still user-gated. |
| Smaller Phase 2–5 tidies | memory `cap-0.5-conformance-migration.md` | **Batch into one tech-debt plan.** Checked today: `ArtifactStore` still has 5 `migrateAddColumn` calls, which breaks the no-legacy rule. `ImportService` is only referenced inside `src/Import/`. `/projects` routes are still registered. The remaining memory items (skills profile with no routes, schedule enable/disable/trigger returning raw rows, idempotency-key TTL, attachment scratch cleanup, `ScheduleFileDefinition` spellings) were not rechecked in this audit. |

## Branches

Checked 2026-09-17 after `git fetch --prune`. The main checkout's `main` now matches `origin/main` (`79d87b3`; its reflog shows `pull --ff-only` at 2026-09-16 18:37), so it is no longer stale. Ahead and unique counts below are still measured against `origin/main` (`git rev-list --count origin/main..<b>`, `git cherry origin/main <b>`).

**The refs have changed since the plan was written (2026-09-15).** Every branch the plan lists as a delete candidate is already gone: all 8 upstream-gone local branches, `fix/test-warnings`, `chore/php-agents-0.15.2`, `chore/react-http-1.11.1`, `verify/combined`, and all 13 Apr–Jun remote branches. `git ls-remote --heads origin` returns only `main`. This audit did not delete them, and the git reflog does not record who did. The worktree `reverent-diffie-bd7f41` left `verify/combined` at 2026-09-16 18:38 and is now on a detached HEAD at `79d87b3` with 0 dirty files.

### Local branches and worktrees

| Branch / worktree | Where | State | Recommendation |
| --- | --- | --- | --- |
| `chore/core-cleanup-2026-09` | local; worktree `gifted-greider-b7467e` | ahead 2, unique 2 (this cleanup: `103caa0`, `27917b1`, plus this commit) | keep |
| `claude/gifted-greider-b7467e` | local; not checked out | at `79d87b3`, ahead 0 / unique 0. This session's scratch branch, created with the worktree, which then switched to `chore/core-cleanup-2026-09`. It holds no commits of its own. | delete once this session ends |
| `claude/hungry-dhawan-ec8475` | local; worktree `hungry-dhawan-ec8475` (created 2026-09-16 19:58) | at `79d87b3`, ahead 0 / unique 0, 0 dirty files. Probably a separate live session. | keep until that session is confirmed finished |
| worktree `reverent-diffie-bd7f41` | detached HEAD `79d87b3` | 0 dirty files. Belongs to the session that ran #173/#174/#175 and `verify/combined`. | keep until that session is confirmed finished |
| `main` (main checkout) | local | `79d87b3` = `origin/main`. Only untracked files are the 2 plan files from `103caa0`, byte-identical. | no action (plain `pull` is done). Remove the 2 untracked copies after this branch merges. |
| `claude/nostalgic-golick-577934` (#172 MERGED), `claude/reverent-diffie-bd7f41`, `feat/webhooks-extraction`, `claude/jolly-montalcini-98a864`, `feat/repl-slim`, `feat_mcp-client-core`, `feat/backstory-formats-extraction`, `feat_lean-default` | local | already deleted | none |
| `fix/test-warnings` (#174), `chore/php-agents-0.15.2` (#173), `chore/react-http-1.11.1` (#175) | local and origin | already deleted (all 3 PRs MERGED 2026-09-16) | none |
| `verify/combined` | local | already deleted (throwaway merge branch) | none |

### Remote branches with merged PRs (all already deleted from origin)

Each was confirmed with `gh pr view N --json state,mergedAt`.

| Branch | PR | State | Recommendation |
| --- | --- | --- | --- |
| `chore_refactor-launcher` | #135 | MERGED 2026-04-25 | none (already deleted) |
| `chore_agentcoqui-domain` | #136 | MERGED 2026-04-25 | none (already deleted) |
| `chore_clean-packages` | #137 | MERGED 2026-04-25 | none (already deleted) |
| `chore_add-json-tool-results` | #138 | MERGED 2026-04-28 | none (already deleted) |
| `chore_remove-toolkit-gen` | #139 | MERGED 2026-04-28 | none (already deleted) |
| `feat_adds-options-api` | #140 | MERGED 2026-05-13 | none (already deleted) |
| `chore_bump-composer-cicd` | #141 | MERGED 2026-05-13 | none (already deleted) |
| `feat_adds-thinking-settings` | #142 | MERGED 2026-06-30 | none (already deleted) |
| `chore_cleanup-workspace-creation` | #143 | MERGED 2026-06-30 | none (already deleted) |

### Remote branches that never had a PR (already deleted from origin; commits now unreachable)

Each of these was a single orphan root commit carrying the whole tree, so the check was by behavior, not by diff. `gh pr list --head <b>` finds no PR for any of them. The commits are still present in the main checkout's object store: `git fsck --unreachable` lists all four. That lasts only until `gc` prunes them.

| Branch | Commit | Behavior | Evidence on `main` (`79d87b3`) | Verdict |
| --- | --- | --- | --- | --- |
| `chore_refactor-big-constructors` | `736d89e` (2026-06-20) | Moves OrchestratorAgent's optional dependencies into one value object | `src/Agent/OrchestratorDependencies.php:49` `final readonly class OrchestratorDependencies`; `src/Agent/OrchestratorAgent.php:208-214` has 5 core params plus `OrchestratorDependencies $deps` | landed |
| `chore_support-native-arrays` | `b7464c7` (2026-04-26) | MCP server loading modes (eager/deferred/auto) and composite toolkits | `src/Contract/ToolkitLoadingMode.php:17`; `src/Contract/CompositeToolkitProvider.php:16`, used at `src/Agent/OrchestratorAgent.php:2614`; `src/Config/ToolkitLoadingRegistry.php`; `src/Api/Handler/McpServerHandler.php:33,162-172` (promote/demote/auto); `src/Mcp/Support/ServerLoadingModeStore.php` | landed |
| `chore_surface-session-cleanup` | `38c8a9c` (2026-04-23) | Leaves hidden background sessions out of the API and REPL | `src/Storage/SessionStorage.php:777` (`listSessions` filters `visibility = 'visible'`), `:969` `getSurfacedSession`; `src/Api/SessionAccess.php:17` uses it; REPL `src/Repl/Handler/SessionHandler.php:187` calls `listSessions` | landed |
| `feat_queue-title-session` | `056a2c9` (2026-04-24) | Queued session-title job plus worker command | `src/Command/SessionTitleRunCommand.php:21` (`session-title:run`); `src/Api/SessionTitleJobManager.php:18`; wired at `src/Command/ApiCommand.php:196` and `bin/coqui-console:26`; `tests/Unit/Command/ConsoleWorkerRegistrationTest.php` matches the branch's version byte for byte | landed |

None of the four is worth reviving, so losing the unreachable objects costs nothing.
