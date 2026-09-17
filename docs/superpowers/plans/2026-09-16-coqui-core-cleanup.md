# Coqui Core Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Implementer/fixer = **Opus**, reviewer = **Opus** — pass `model:` explicitly on every dispatch; never Haiku/Sonnet. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land the open dependency PRs (#173, #175), confirm the zero-warning suite from #174, and reconcile the superpowers docs and git branches with what actually shipped.

**Architecture:** Merge two lock-only dependency PRs in a safe order. Verify on `main` the two warning fixes #174 already shipped (invalid user regexes rejected at construction; a stale generated docs index treated as a cache miss). Then two report-first housekeeping tasks, whose destructive steps need user approval.

**Tech Stack:** PHP 8.4, Pest 5 / PHPUnit 13, PHPStan 2, Composer 2.10.

**Spec:** No design spec — scope comes from the 2026-09-15 investigation (stale vendor install, 3 test warnings, branch/doc survey). Findings are restated inline in each task.

## Status update (2026-09-16, supersedes older notes below)

An earlier session ("Clean up coqui: warnings, deps, branches, docs") already did part of this plan:
- **Tasks 2 and 3 are DONE.** They were merged in PR #174 `fix/test-warnings`: `b725b41` (blacklist patterns dropped at construction), `cec88e7` (docs toolkit skips missing docs), `c717a6d` (docs index rebuilds when the cached list doesn't match the disk). Only the verification step remains.
- **Task 1 is PR #173** (`chore/php-agents-0.15.2`, `cb0c63b`), which is **open**. Also open is PR #175 (`chore/react-http-1.11.1`, `017932e`, which fixes two DoS advisories, CI green). Both PRs change only `composer.lock`, so merging one will make the other conflict on the lock's `content-hash`.
- That session is idle and **lives in** `.claude/worktrees/reverent-diffie-bd7f41`, now on branch `verify/combined` (which merges #173 and #175 for a combined test run). Don't remove that worktree or `verify/combined` unless the user confirms that session is finished.

## Global Constraints

- Baseline: `origin/main` includes #174, so `vendor/bin/pest tests/Unit tests/conformance` there should show **0 warnings**. Record the exact passed count before changing anything.
- `composer analyse` must stay `[OK]` after every task.
- Pre-release project: no migrations, deprecation shims or compat branches.
- `CatastrophicBlacklist::HARDCODED_PATTERNS` must stay byte-identical (the CAP 0.5.0 conformance certification relies on it).
- Commits: small, one concern each, conventional prefix, **no attribution trailers**.
- Never push, open a PR, delete a branch/worktree, or edit another repo without explicit user approval (ask with AskUserQuestion).
- Work in a worktree on branch `chore/core-cleanup-2026-09` cut from `origin/main`. `vendor/` is gitignored, so run `composer install` first.
- The main checkout at `/home/carmelo/Projects/CoquiBot/Core/coqui` is on a stale local `main` (`e7cdbcb`). It has an uncommitted `composer.lock` (the v0.15.2 bump, now redundant with #173) and the two untracked 2026-09-16 plan files. Don't modify it; Task 5 Step 4 offers to reset it, with approval.

---

### Task 1: Land php-agents v0.15.2

**Files:**
- None new. PR #173 (`chore/php-agents-0.15.2`, `cb0c63b`) already carries the `composer.lock` change. PR #175 (`chore/react-http-1.11.1`, `017932e`) is the sibling lock bump.

**Interfaces:**
- Consumes: nothing.
- Produces: `origin/main` pins `carmelosantana/php-agents` **v0.15.2** (ref `287f96c1469b0d2b04fb7db314bb4100549d138e`) and `react/http` **v1.11.1**.

Context: the main checkout's `vendor/` was a stale install (php-agents v0.15.0 while the lock pinned v0.15.1). v0.15.2 wraps structured-output schemas as `{name, schema, strict}` and adds `StrictSchemaNormalizer`.

- [ ] **Step 1: Verify both PRs (read-only)**

```bash
gh pr view 173 --json state,mergeable,files,statusCheckRollup --jq '{state,mergeable,files:[.files[].path],checks:[.statusCheckRollup[].conclusion]}'
gh pr view 175 --json state,mergeable,files,statusCheckRollup --jq '{state,mergeable,files:[.files[].path],checks:[.statusCheckRollup[].conclusion]}'
gh pr diff 173 | grep -E '^[+-] +"(version|reference)"'
```
Expected: both are `OPEN`, each touches only `composer.lock`, and the #173 diff shows only php-agents `v0.15.1`→`v0.15.2` / `07c048a…`→`287f96c…`. If #173 has no CI results, say so.

- [ ] **Step 2: Ask, then merge in order**

Use AskUserQuestion: "Merge #175 (react/http security) then #173 (php-agents)?" Recommend merging the security fix first. After the first merge, rebase the second PR's lock onto `main` instead of hand-merging the JSON:

```bash
gh pr merge 175 --merge
git fetch origin
git switch -c rebase-173 origin/chore/php-agents-0.15.2
git reset --hard origin/main
composer update carmelosantana/php-agents
git diff --stat composer.lock   # php-agents entry + content-hash only
vendor/bin/pest tests/Unit tests/conformance 2>&1 | tail -3
git commit -am "chore(deps): bump carmelosantana/php-agents to v0.15.2"
```
Ask before force-pushing: `git push --force-with-lease origin rebase-173:chore/php-agents-0.15.2`. Then run `gh pr merge 173 --merge` once CI is green.

- [ ] **Step 3: Verify main**

```bash
git fetch origin && git switch -C chore/core-cleanup-2026-09 origin/main
composer install
composer show carmelosantana/php-agents | grep -E '^versions'
vendor/bin/pest tests/Unit tests/conformance 2>&1 | tail -3
composer analyse 2>&1 | tail -2
```
Expected: `* v0.15.2`, **0 warnings**, and `[OK]`. Record the passed count in the ticket comment.

---

### Task 2: Reject invalid user blacklist regexes at construction

**Status: DONE**, merged in PR #174 (`b725b41`). Verification only.

**Files:** none.

**Interfaces:**
- Consumes: `origin/main` after Task 1.
- Produces: confirmation only.

- [ ] **Step 1: Verify on main**

```bash
git log --oneline origin/main | grep -m1 b725b41 || git log --oneline origin/main --grep="blacklist patterns at construction" | head -1
vendor/bin/pest tests/Unit/Config/CatastrophicBlacklistTest.php 2>&1 | tail -2
git show --format= b725b41 -- src/Config/CatastrophicBlacklist.php | grep -E "^[-+][[:space:]]+'/" || echo "HARDCODED_PATTERNS untouched"
```
Expected: the commit is present, the tests pass with no warnings, and the output ends with `HARDCODED_PATTERNS untouched`. Tick the subtask.

---

### Task 3: Treat a stale generated docs index as a cache miss

**Status: DONE**, merged in PR #174 (`cec88e7`, `c717a6d`). Verification only.

**Files:** none.

**Interfaces:**
- Consumes: `origin/main` after Task 1.
- Produces: confirmation only.

- [ ] **Step 1: Verify the stale-cache path on main**

```bash
cp /home/carmelo/Projects/CoquiBot/Core/coqui/config/documentation.json config/documentation.json 2>/dev/null && echo "using stale cache from main checkout"
vendor/bin/pest tests/Unit/Config 2>&1 | tail -3
rm -f config/documentation.json
```
Expected: `0 warnings`, even with the stale cache that still lists `docs/PROFILES.md`. If the main checkout has no cache, say so and rely on the tests added in #174. Tick the subtask.

---

### Task 4: Audit superpowers plans against what shipped

**Files:**
- Create: `docs/superpowers/reports/2026-09-16-plan-status-audit.md`
- Modify (outside the repo, not committed): `~/.claude/projects/-home-carmelo-Projects-CoquiBot-Core-coqui/memory/cap-0.5-conformance-migration.md` and its line in `MEMORY.md`

**Interfaces:**
- Consumes: the 27 files in `docs/superpowers/plans/` and 6 status reports in `docs/superpowers/reports/`, the memory index, and `gh pr list`.
- Produces: one status table the user can act on.

Known facts to start from:
- CAP 0.5.0 Phases 0–6 are **merged**. Core: PR #166 (+ #168 vectors). Flutter app (`carmelosantana/coqui-app`): #20 wire, #22 UI completion, released v0.0.6/v0.0.7. The memory note still says "two unpushed worktrees", which is stale.
- Most plans still have unchecked `- [ ]` boxes even though they merged. Unchecked boxes are not evidence of unfinished work.
- Known open follow-ups:
  - Identity/backstory SDD minors, from `.superpowers/sdd/progress.md`: T2 stub-context path test, T4 duplicate ordering test, T7 lost `GET /server/prompt` router-dispatch test. The final review triaged all three as non-blocking; `PromptHandlerTest` still covers the handler, and the route is registered at `src/Command/ApiCommand.php:734`.
  - Phase 6 carry-forwards, from `docs/superpowers/reports/2026-08-04-phase-6-status.md` §Carry-forwards: `GET /sessions/{id}/turns/{turnId}/events` (`TurnHandler::events`) returns stored events, not CAP frames; the HTTP-spawned child run has no toolkits.

- [ ] **Step 1: Collect merge evidence**

```bash
gh pr list --state merged --limit 200 --json number,title,headRefName,mergedAt > /tmp/coqui-merged-prs.json
for f in docs/superpowers/plans/*.md; do slug=$(basename "$f" .md | cut -d- -f4-); echo "== $slug"; git log --oneline --grep="$slug" -i | head -3; jq -r --arg s "$slug" '.[] | select((.headRefName|ascii_downcase|contains($s)) or (.title|ascii_downcase|contains($s|gsub("-";" ")))) | "PR #\(.number) \(.title)"' /tmp/coqui-merged-prs.json; done
cat ~/.claude/projects/-home-carmelo-Projects-CoquiBot-Core-coqui/memory/MEMORY.md
```
For each plan with no hit, check the memory entry for that program by name.

- [ ] **Step 2: Write the report**

Write `docs/superpowers/reports/2026-09-16-plan-status-audit.md` with this structure (one row per plan file, every row filled in from Step 1 evidence):

```markdown
# Superpowers plan status audit — 2026-09-16

| Plan | Status | Evidence | Open follow-ups |
| --- | --- | --- | --- |
| 2026-07-07-lean-default | shipped | PR #… / commit … | — |

## Open follow-ups needing a decision
| Item | Source | Recommendation |
| --- | --- | --- |
| T7 `GET /server/prompt` route-dispatch test | identity-backstory SDD | … |
| Turn-events replay not CAP-frame-normalized | Phase 6 carry-forward | … |
| HTTP-spawned child run is toolkit-less | Phase 5/6 carry-forward | … |
| T2 stub-context test, T4 duplicate ordering test | identity-backstory SDD | … |
```
Status values: `shipped`, `partially shipped`, `superseded`, `not started`.

- [ ] **Step 3: Fix the stale memory note**

In `cap-0.5-conformance-migration.md`, replace the "unpushed" state with: merged core #166 (+#168), app #20/#22, released app v0.0.6/v0.0.7, and point open carry-forwards at the new report. Update the matching one-line hook in `MEMORY.md`.

- [ ] **Step 4: Commit and ask**

```bash
cp /home/carmelo/Projects/CoquiBot/Core/coqui/docs/superpowers/plans/2026-09-16-*.md docs/superpowers/plans/
git add docs/superpowers/reports/2026-09-16-plan-status-audit.md docs/superpowers/plans/2026-09-16-*.md
git commit -m "docs(superpowers): audit plan status against shipped work; add 2026-09-16 cleanup plans"
```
Use AskUserQuestion (multiSelect) to present the "Open follow-ups" table. Don't implement any follow-up in this plan; the user's picks become new plans.

---

### Task 5: Branch and worktree hygiene

**Files:**
- Modify: `docs/superpowers/reports/2026-09-16-plan-status-audit.md` (append a `## Branches` section)

**Interfaces:**
- Consumes: local branches, `origin` remote branches, `git worktree list`, `gh pr list`.
- Produces: a branch table, plus the deletions the user approved.

Known facts, verified 2026-09-15:
- 8 local branches whose upstreams are gone and that have no commits missing from `main`: `claude/nostalgic-golick-577934`, `claude/reverent-diffie-bd7f41`, `feat/webhooks-extraction`, `claude/jolly-montalcini-98a864`, `feat/repl-slim`, `feat_mcp-client-core`, `feat/backstory-formats-extraction`, `feat_lean-default`. Also created by the 2026-09-16 session: `fix/test-warnings` (#174 merged, delete candidate), `chore/php-agents-0.15.2` / `chore/react-http-1.11.1` (delete after Task 1 merges them), and `verify/combined` (throwaway merge branch). The worktree `.claude/worktrees/reverent-diffie-bd7f41` is **now on `verify/combined` and hosts that session**: keep it unless the user confirms that session is done.
- 13 remote branches (Apr–Jun 2026) share **no merge base** with `main` (history was rewritten during the org move). Each is one commit.
  - 9 had merged PRs: `chore_add-json-tool-results` #138, `chore_agentcoqui-domain` #136, `chore_bump-composer-cicd` #141, `chore_clean-packages` #137, `chore_cleanup-workspace-creation` #143, `chore_refactor-launcher` #135, `chore_remove-toolkit-gen` #139, `feat_adds-options-api` #140, `feat_adds-thinking-settings` #142.
  - 4 never had a PR: `chore_refactor-big-constructors` (736d89e), `chore_support-native-arrays` (b7464c7), `chore_surface-session-cleanup` (38c8a9c), `feat_queue-title-session` (056a2c9).

- [ ] **Step 1: Re-verify the local branches (run in the main checkout, read-only)**

```bash
M=/home/carmelo/Projects/CoquiBot/Core/coqui
git -C $M fetch --prune -q
for b in $(git -C $M for-each-ref --format='%(refname:short)' refs/heads/ | grep -v '^main$'); do echo "$b ahead=$(git -C $M rev-list --count main..$b) unique=$(git -C $M cherry main $b | grep -c '^+')"; done
git -C $M worktree list
git -C $M -C .claude/worktrees/reverent-diffie-bd7f41 status --porcelain | wc -l
```
Expected: branches merged via PR show `unique=0` (`verify/combined` shows merge commits only), and the worktree reports `0` dirty files.

- [ ] **Step 2: Triage the 4 PR-less remote branches by behavior**

For each branch, find the files and symbols it touched and check whether current `main` already has the behavior:

```bash
for b in chore_refactor-big-constructors chore_support-native-arrays chore_surface-session-cleanup feat_queue-title-session; do echo "== $b"; git -C $M show --stat --format='%h %cs %s' origin/$b | head -25; done
```
Then grep `main` for the feature, for example:
- `refactor-big-constructors`: `grep -rn "OrchestratorAgent" src/Agent/OrchestratorAgent.php | head` and look for an optional-dependencies value object.
- `support-native-arrays`: `grep -rn "loading_mode\|LoadingMode\|Composite" src/Mcp | head`.
- `surface-session-cleanup`: `grep -rn "hidden\|is_background" src/Api/Handler/SessionHandler.php | head`.
- `queue-title-session`: `grep -rn "'/title'\|TitleCommand\|title" src/Repl -l | head`.

Classify each as `landed`, `obsolete` (the area was removed or redesigned) or `worth reviving`.

- [ ] **Step 3: Append the table and ask**

Append to the audit report:

```markdown
## Branches
| Branch | Where | State | Recommendation |
| --- | --- | --- | --- |
| claude/nostalgic-golick-577934 | local | merged (#172), upstream gone | delete |
```
Commit it (`git commit -m "docs(superpowers): branch hygiene table"`), then use AskUserQuestion (multiSelect) to get approval, grouped as: local branches + worktree, the 9 merged remote branches, and each of the 4 PR-less branches individually.

- [ ] **Step 4: Run only the approved deletions**

```bash
git -C $M worktree remove .claude/worktrees/reverent-diffie-bd7f41   # only if approved AND that session is finished
git -C $M checkout -- composer.lock && git -C $M pull --ff-only     # only if approved
git -C $M branch -D <approved-local-branch>                          # repeat per approved branch
git -C $M push origin --delete <approved-remote-branch>              # repeat per approved branch
git -C $M branch -a | sed -n '1,40p'
```
Expected: only approved refs are gone. Report the final `git branch -a`.

- [ ] **Step 5: Finish**

Use superpowers:finishing-a-development-branch for `chore/core-cleanup-2026-09`. Report: commits (SHA + subject), suite counts on main (0 warnings) and the php-agents/react-http versions, the audit report path, the deletions made, and decisions still open. Don't push or open a PR without asking.
