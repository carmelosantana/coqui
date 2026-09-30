# CoquiBot Sibling Repos Hygiene Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Implementer/fixer = **Opus**, reviewer = **Opus**. Pass `model:` explicitly on every dispatch; never Haiku/Sonnet. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring every CoquiBot repo outside `Core/coqui` to a clean, known state: on `main`, no stray worktrees or unignored secrets, merged branches pruned, and open PRs and unpublished repos surfaced for a decision.

**Architecture:** One task per repo (or repo group). Every task is report-first: gather state read-only, make non-destructive fixes on a branch, and ask with AskUserQuestion before any push, PR, merge, branch/worktree deletion, repo creation or archive.

**Tech Stack:** git, gh CLI, Composer (PHP toolkits), Flutter (coqui-app), shell (installer).

**Spec:** No design spec. Scope comes from the 2026-09-15 survey of `~/Projects/CoquiBot` (restated per task). The companion core plan is `docs/superpowers/plans/2026-09-16-coqui-core-cleanup.md`, which owns `Core/coqui`. Don't touch that repo here.

## Global Constraints

- Operate with absolute paths (`git -C <repo> …`, `cd <repo> && gh …`). Repo root is `/home/carmelo/Projects/CoquiBot`, and it isn't a git repo itself.
- Never read, print, stage or commit any `.env` file's contents.
- Never push, open/merge a PR, delete a branch or worktree, create a GitHub repo, archive a repo, or touch Packagist without explicit approval via AskUserQuestion. Batch questions per task.
- Commits: one concern each, conventional prefix, **no attribution trailers**. Commit on a branch named `chore/repo-hygiene-2026-09`, never directly on `main`.
- Check the git identity per directory with `/powerup:who-am-i` before the first commit in each repo.
- A dirty or ahead state you can't explain is a stop-and-ask, not a cleanup.

---

### Task 1: coqui-app (Flutter client): ignore rules, worktrees, open PR

**Files:**
- Modify: `/home/carmelo/Projects/CoquiBot/Apps/coqui-app/.gitignore`

**Interfaces:**
- Consumes: nothing.
- Produces: coqui-app on `main`, with `.worktrees/` and `scripts/e2e/.env` ignored, and a worktree list the user approved.

State as of 2026-09-15:
- The main checkout is on a **detached HEAD** at `043cf23` ("chore: release v0.0.7"), with no tracked changes.
- Untracked and **not ignored**: `.worktrees/` and `scripts/e2e/.env` (43 bytes, dated Jul 25; the only file in `scripts/e2e/`).
- Worktrees:
  - `Apps/coqui-app-cap` on `feat/cap-0.5-wire` (PR #20 **merged**)
  - `.worktrees/demo` (detached `8fc8ee4`)
  - `.worktrees/ios-build-fix` on `fix/apple-build` (PR #23 **open**, all 4 CI checks pass)
  - `.worktrees/web-release-asset` on `feat/web-release-asset` (PR #24 **merged**)
- Local branches: `feat/discord-redesign` (PR #19 **closed** unmerged), `feat/cap-0.5-wire`, `feat/web-release-asset`, `fix/apple-build`, `main`.

- [ ] **Step 1: Confirm state (read-only)**

```bash
A=/home/carmelo/Projects/CoquiBot/Apps/coqui-app
git -C $A fetch --prune -q
git -C $A status -sb
git -C $A worktree list
for w in $(git -C $A worktree list --porcelain | awk '/^worktree /{print $2}'); do echo "$w dirty=$(git -C $w status --porcelain | wc -l)"; done
git -C $A branch -vv
git -C $A log --oneline -1 origin/main
git -C $A merge-base --is-ancestor 043cf23 origin/main && echo "detached HEAD is on main's history"
```
Expected: every worktree reports `dirty=0`, and HEAD is an ancestor of `origin/main`. If any worktree is dirty, list its files (not `.env` contents) and ask.

- [ ] **Step 2: Put the main checkout on `main`**

```bash
git -C $A switch main
git -C $A pull --ff-only
git -C $A status -sb
```
Expected: `## main...origin/main` with only the untracked `.worktrees/` and `scripts/e2e/`.

- [ ] **Step 3: Ignore worktrees and the e2e env file (TDD-style check)**

```bash
git -C $A check-ignore -q .worktrees && echo ignored || echo NOT-ignored    # expect NOT-ignored
git -C $A switch -c chore/repo-hygiene-2026-09
printf '\n# Local agent worktrees and e2e secrets\n/.worktrees/\n/scripts/e2e/.env\n' >> $A/.gitignore
git -C $A check-ignore -q .worktrees && git -C $A check-ignore -q scripts/e2e/.env && echo both-ignored
git -C $A status --porcelain
```
Expected: the first line prints `NOT-ignored`. After the edit it prints `both-ignored`, and `status` shows only ` M .gitignore`.

```bash
git -C $A add .gitignore
git -C $A commit -m "chore: ignore local agent worktrees and e2e env file"
```

- [ ] **Step 4: Decide worktrees, branches and PR #23 (ask)**

```bash
cd $A && gh pr view 23 --json title,state,mergeable,reviewDecision,statusCheckRollup --jq '{title,state,mergeable,reviewDecision,checks:[.statusCheckRollup[].conclusion]}'
```
Ask the user (multiSelect) about:
- (a) removing worktree `coqui-app-cap` + branch `feat/cap-0.5-wire` (merged #20)
- (b) removing worktree `.worktrees/web-release-asset` + branch `feat/web-release-asset` (merged #24)
- (c) removing worktree `.worktrees/demo`
- (d) deleting branch `feat/discord-redesign` (PR #19 closed unmerged; memory says the Discord brief is deferred, so recommend **keep**)
- (e) merging PR #23 (`fix/apple-build`, CI green)
- (f) pushing `chore/repo-hygiene-2026-09` and opening a PR

- [ ] **Step 5: Run only the approved items**

```bash
git -C $A worktree remove /home/carmelo/Projects/CoquiBot/Apps/coqui-app-cap      # (a)
git -C $A branch -d feat/cap-0.5-wire                                              # (a)
git -C $A worktree remove .worktrees/web-release-asset                             # (b)
git -C $A branch -d feat/web-release-asset                                         # (b)
git -C $A worktree remove .worktrees/demo                                          # (c)
cd $A && gh pr merge 23 --merge                                                    # (e)
git -C $A push -u origin chore/repo-hygiene-2026-09 && (cd $A && gh pr create --fill)   # (f)
git -C $A worktree list; git -C $A branch -vv
```
Use `branch -d` (not `-D`). If git refuses, stop and report; don't force.

---

### Task 2: coqui-installer: ignore worktrees, open PR #23

**Files:**
- Modify: `/home/carmelo/Projects/CoquiBot/Core/coqui-installer/.gitignore`

**Interfaces:**
- Consumes: nothing.
- Produces: installer `main` with `.worktrees/` ignored, and PR #23 decided.

State: on `main` (`2599f93`, no upstream tracking was reported). Untracked, **not ignored** `.worktrees/`. Worktree `.worktrees/ollama-v1` is on `fix/ollama-baseurl-v1`, which is **open PR #23** ("give the default ollama baseUrl the /v1 suffix"). This relates to the php-agents OpenAI-compatible provider work.

- [ ] **Step 1: Confirm state**

```bash
I=/home/carmelo/Projects/CoquiBot/Core/coqui-installer
git -C $I fetch --prune -q
git -C $I status -sb; git -C $I branch -vv; git -C $I worktree list
git -C $I -C .worktrees/ollama-v1 status --porcelain | wc -l
cd $I && gh pr view 23 --json state,mergeable,statusCheckRollup --jq '{state,mergeable,checks:[.statusCheckRollup[].conclusion]}'
```
If `main` has no upstream, run `git -C $I branch --set-upstream-to=origin/main main` and report ahead/behind.

- [ ] **Step 2: Ignore `.worktrees/`**

```bash
git -C $I check-ignore -q .worktrees && echo ignored || echo NOT-ignored    # expect NOT-ignored
git -C $I switch -c chore/repo-hygiene-2026-09
printf '\n# Local agent worktrees\n/.worktrees/\n' >> $I/.gitignore
git -C $I check-ignore -q .worktrees && echo ignored
git -C $I add .gitignore
git -C $I commit -m "chore: ignore local agent worktrees"
```
Expected: `NOT-ignored`, then `ignored`.

- [ ] **Step 3: Ask, then act**

Ask about: (a) merging PR #23 if CI is green; if CI is red, report the failing check instead of offering a merge; (b) removing worktree `.worktrees/ollama-v1` after the merge; (c) pushing the hygiene branch and opening a PR. Run only the approved items, using the same commands as Task 1 Step 5.

---

### Task 3: coqui-toolkit-backstory-formats: publish the abandonment

**Files:** none. The commit already exists: `2cbfe9c chore: mark package abandoned in favor of coquibot/coqui-toolkit-backstory`.

**Interfaces:**
- Consumes: nothing.
- Produces: the abandonment marker is pushed, and the repo is archived if the user approves.

State: `main` is 1 commit ahead of `origin/main`. `composer.json` line 6 has `"abandoned": "coquibot/coqui-toolkit-backstory"`. The replacement `coqui-toolkit-backstory` v0.1.0 is published on Packagist. Memory lists "-formats abandon/archive" as remaining work.

- [ ] **Step 1: Verify**

```bash
F=/home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-backstory-formats
git -C $F fetch -q; git -C $F status -sb; git -C $F log --oneline origin/main..HEAD
composer validate --no-check-publish -d $F
```
Expected: `ahead 1`, the one commit is `2cbfe9c`, and composer validates.

- [ ] **Step 2: Ask, then act**

Ask about: (a) pushing `2cbfe9c` to `origin/main` (this repo's convention allows a direct push, because the commit is metadata-only and was authored for main; confirm with the user); (b) `gh repo archive carmelosantana/coqui-toolkit-backstory-formats --yes` after the push; (c) marking the package abandoned on Packagist, which is a **manual step for the user** in the Packagist UI. Give them the package URL and don't attempt it yourself.

```bash
git -C $F push origin main                                           # (a)
gh repo archive carmelosantana/coqui-toolkit-backstory-formats --yes # (b)
```

---

### Task 4: Merged feature branches still checked out (mcp-client, docs site)

**Files:** none.

**Interfaces:**
- Consumes: nothing.
- Produces: both repos on `main`, with merged branches deleted where the user approves.

State:
- `Core/coqui-toolkit-mcp-client` is on `feat_mcp-client-core`, with 0 commits not in `origin/main` (merged in PR #4, `4d52fad`).
- `Websites/docs-coquibot-org` is on `feat/frontmatter-source-of-truth` (PR #2 **merged**).

- [ ] **Step 1: Verify and switch**

```bash
for R in /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-mcp-client /home/carmelo/Projects/CoquiBot/Websites/docs-coquibot-org; do
  B=$(git -C $R rev-parse --abbrev-ref HEAD)
  git -C $R fetch --prune -q
  echo "== $R on $B, dirty=$(git -C $R status --porcelain | wc -l), unmerged=$(git -C $R rev-list --count origin/main..$B)"
done
```
Expected: `dirty=0`. For `unmerged`, a nonzero count on the docs repo can mean a merge commit strategy: confirm with `git -C $R cherry origin/main $B | grep -c '^+'` before assuming there's unmerged work. If both repos are clean and merged, run `git -C $R switch main && git -C $R pull --ff-only` in each.

- [ ] **Step 2: Ask, then delete**

Ask about deleting each merged branch locally (`git branch -d`) and on the remote (`git push origin --delete`). Run only the approved deletions.

---

### Task 5: Unpublished and remote-less repos: surface a decision

**Files:** none.

**Interfaces:**
- Consumes: nothing.
- Produces: a decision per repo. No repo is created or published without an explicit yes.

State: these repos have **no remote**, 1 commit each, and are on `main`: `Core/coqui-toolkit-images`, `Core/coqui-toolkit-mod-manager`, `Core/coqui-toolkit-mod-publish` and `Scripts/code-report`. Two repos have a remote but `main` has no upstream tracking: `Websites/com-agentcoqui-docs` (`carmelosantana/agentcoqui-docs`, 12 commits) and `Core/coqui-agent-spec` (`carmelosantana/coqui-agent-spec`, 95 commits).

- [ ] **Step 1: Describe each repo (read-only)**

```bash
cd /home/carmelo/Projects/CoquiBot
for d in Core/coqui-toolkit-images Core/coqui-toolkit-mod-manager Core/coqui-toolkit-mod-publish Scripts/code-report; do
  echo "== $d: $(git -C $d log -1 --format='%h %cs %s')"; ls $d | head -15
  [ -f $d/composer.json ] && jq -r '.name + " — " + (.description // "")' $d/composer.json
  gh repo view carmelosantana/$(basename $d) --json name,visibility 2>&1 | head -1
done
for d in Websites/com-agentcoqui-docs Core/coqui-agent-spec; do
  git -C $d fetch -q && git -C $d branch --set-upstream-to=origin/main main && git -C $d status -sb | head -1
done
```
Setting upstream tracking isn't destructive and is allowed. Report ahead/behind for the two tracked repos. If `coqui-agent-spec` is ahead, **don't push**: its memory notes it's a separate program with its own workflow, so list the unpushed commits instead.

- [ ] **Step 2: Ask**

For each of the 4 remote-less repos, ask: publish as a **private** GitHub repo / publish as **public** / leave local-only / archive locally. Check the memory index `~/.claude/projects/-home-carmelo-Projects-CoquiBot-Core-coqui/memory/MEMORY.md` first; for example, the mod-manager and mod-publish toolkits relate to the "API-extensible mods" track. If the user picks publish, run:

```bash
cd /home/carmelo/Projects/CoquiBot/<repo> && gh repo create carmelosantana/<name> --private --source . --push   # or --public
```

---

### Task 6: Hand-off report

**Files:**
- Modify (outside repos, not committed): `~/.claude/projects/-home-carmelo-Projects-CoquiBot-Core-coqui/memory/` — create or update one memory file `coquibot-repo-hygiene.md` and its line in `MEMORY.md`.

**Interfaces:**
- Consumes: the results of Tasks 1–5.
- Produces: a final status the user can scan, plus a durable memory entry.

- [ ] **Step 1: Re-survey all repos**

```bash
cd /home/carmelo/Projects/CoquiBot
for d in $(find . -maxdepth 3 -name .git -not -path '*/.worktrees/*' | xargs -n1 dirname | sort); do
  printf '%-45s %-28s dirty=%-3s ahead=%s\n' "$d" "$(git -C $d rev-parse --abbrev-ref HEAD)" "$(git -C $d status --porcelain | wc -l)" "$(git -C $d rev-list --count @{u}..HEAD 2>/dev/null || echo no-upstream)"
done
```
Expected: every repo is on `main` (or on the approved hygiene branch) with `dirty=0`. The only `no-upstream` entries are repos the user chose to keep local.

- [ ] **Step 2: Record and report**

Write the memory entry: date, per-repo final state, decisions made, and what's still open. Then report to the user: a per-repo table (before → after), PRs opened or merged, refs deleted, repos created or archived, and open decisions. The Packagist abandonment is a manual step for the user.
