# Data Machine Code

**Data Machine Code gives a WordPress site the ability to write and ship code** — clone repos, edit files in worktrees, commit, push, open issues and PRs, comment on reviews. Built on [Data Machine](https://github.com/Extra-Chill/data-machine), powered by the Abilities API.

Who pulls the trigger is up to you. Same capability surface, three driver modes:

- **An external coding-agent runtime** on your machine pointed at the site (Claude Code, OpenCode, kimaki, Studio Code) — interactive, human-in-the-loop.
- **The site itself, via a Data Machine flow** — scheduled or webhook-triggered, no human and no external runtime. The site codes on its own behalf.
- **An ephemeral CI job** that boots a WordPress instance with DMC loaded for a single check (Playground + GitHub Actions). The site exists for one job, codes for one PR, dies.

On managed hosts (WordPress.com, VIP, sandboxed environments) DMC is **never installed by design**. Those sites cannot write code, and that's the point — DMC's presence is the switch between "this WordPress site can ship code" and "this one can't."

## What It Is

DMC's activation is the declarative answer to **"can this WordPress site code?"** When DMC is loaded, the install is no longer just a site running an AI — it has a worktree-native workspace, GitHub/git/workspace abilities, an `AGENTS.md` at the WP root for any runtime that asks, and a capability surface other plugins can read to gate disk-side behavior.

That framing reshapes what every other plugin can assume:

- **AGENTS.md is composed and written to the WP root** for external runtimes that discover it on session start. DMC owns the file, contributes core sections, and lets other plugins register more via Data Machine's `SectionRegistry`. In-process and CI drivers don't use AGENTS.md — they get context through Data Machine's normal channels (system prompts, memory files, flow user messages). AGENTS.md is a feature for one of the three drivers, not the universal interface.
- **The site gains GitHub, workspace, and git abilities** through the Abilities API — every ability is automatically callable from chat, MCP, REST, WP-CLI, *and* directly from inside Data Machine flows running on the site.
- **A worktree-native workspace area** at `~/.datamachine/workspace/` lets the site clone repos, edit files in isolated branches, and push changes — all gated by per-repo policies. Same workspace whether the editor is an external runtime, an in-process AI step, or a CI job.
- **`\DataMachineCode\Environment` is a capability surface** plugins can read to ask "can this site code?", "can we shell out?", "is the filesystem writable outside `/uploads`?". Other DM plugins use it to gate disk-side hooks (e.g. Intelligence's `SKILL.md` sync).

The asymmetry between self-hosted and managed is the whole product statement: self-hosted sites can install DMC and ship code; managed sites cannot. DMC is the seam.

## Driver Modes

| Mode | Driver | Lifetime | Example |
|---|---|---|---|
| **Co-located runtime** | External agent CLI on the host | Long-lived install | [`wp-coding-agents`](https://github.com/Extra-Chill/wp-coding-agents) on a VPS, a Studio site with kimaki |
| **In-process flow** | DM AI step inside a flow | Long-lived install | An Intelligence wiki maintenance flow calling workspace abilities; a webhook-triggered PR review flow |
| **Ephemeral CI** | DM flow inside Playground / GitHub Actions | One job | [`wc-site-generator`](https://github.com/chubes4/wc-site-generator) static-site validation; the Stage 5 Playground proof |

The capability surface is identical in all three. The differences are who pulls the trigger and how long the site lives.

## How It Differs From Other Data Machine Extensions

Sibling extensions like `data-machine-socials` and `data-machine-business` are **handler-adding** — they extend DM's pipeline machinery with new sources, destinations, or transforms. DMC is **runtime-altering** — its presence changes what kind of system this is. That's why DMC owns AGENTS.md, declares environment capabilities, and gates the existence of an entire class of disk-side functionality. Same plugin chassis, fundamentally different role.

## Features

### GitHub Integration
- List, view, update, close, and comment on issues
- List pull requests and repositories
- Create issues (async via Action Scheduler)
- GitHub fetch handler for pipeline workflows
- GitHub pull request webhook validation mode for review-flow triggers
- PR review flow scaffold for webhook-triggered review automation with bounded context gathering and managed comment upsert

### Workspace Management
- Clone and manage git repositories in a secure workspace directory
- Read, write, and edit files within workspace repos
- List directory contents with file metadata
- Bundled Data Machine pipeline templates for workspace inventory, metadata repair, artifact cleanup, retention cleanup, and emergency cleanup

### Git Operations
- Status, log, diff (read-only)
- Pull, add, commit, push (policy-controlled)
- Per-repo write/push policies with path allowlists and branch restrictions

### AI Agent Tools
- GitHub and workspace chat tools for read-only context gathering plus managed PR review comments
- Async GitHub issue creation via system tasks
- All tools register through Data Machine's tool system, which means they're equally available to external runtimes (via MCP/REST/WP-CLI) and to in-process / CI drivers (via direct AI-step tool calls)

### WP-CLI
```bash
# GitHub
wp datamachine-code github issues --repo=owner/repo
wp datamachine-code github view 123 --repo=owner/repo
wp datamachine-code github close 123 --repo=owner/repo
wp datamachine-code github comment 123 "Fixed." --repo=owner/repo
wp datamachine-code github pulls --repo=owner/repo
wp datamachine-code github repos owner
wp datamachine-code github review-flow create --repo=owner/repo --agent=code-reviewer
wp datamachine-code github status

# Workspace
wp datamachine-code workspace path
wp datamachine-code workspace list
wp datamachine-code workspace clone https://github.com/org/repo.git
wp datamachine-code workspace show repo-name

# Worktrees — one per branch, parallel-safe
wp datamachine-code workspace worktree add repo-name fix/foo
wp datamachine-code workspace worktree list
wp datamachine-code workspace worktree remove repo-name fix/foo
wp datamachine-code workspace worktree prune

# Daily workspace cleanup — task-backed, inspectable by run_id
wp datamachine-code workspace cleanup run --mode=retention
wp datamachine-code workspace cleanup status cleanup-run-123
wp datamachine-code workspace cleanup evidence cleanup-run-123 --format=json

# All read/write/git ops accept either <repo> (primary) or <repo>@<branch-slug> (worktree)
wp datamachine-code workspace read repo-name@fix-foo src/main.php
wp datamachine-code workspace ls repo-name@fix-foo src/
wp datamachine-code workspace write repo-name@fix-foo path/to/file.txt @content.txt
wp datamachine-code workspace edit repo-name@fix-foo path/to/file.txt --old="foo" --new="bar"
wp datamachine-code workspace remove repo-name
wp datamachine-code workspace git status repo-name@fix-foo
wp datamachine-code workspace git pull repo-name@fix-foo
wp datamachine-code workspace git add repo-name@fix-foo --rel=src/file.php
wp datamachine-code workspace git commit repo-name@fix-foo "fix: something"
wp datamachine-code workspace git push repo-name@fix-foo
wp datamachine-code workspace git log repo-name@fix-foo
wp datamachine-code workspace git diff repo-name@fix-foo
```

### Worktrees: parallel-safe branch work

The workspace is **worktree-native**. Each branch lives in its own directory at
`<workspace>/<repo>@<branch-slug>` (slashes in branch names become dashes). Multiple
agent sessions — or multiple flows, or multiple CI jobs — can edit different
branches of the same repo simultaneously without stepping on each other.

DMC discovers primary checkouts and worktrees by scanning the configured workspace
root. Worktree lifecycle metadata supports cleanup and reconciliation; if that
workspace path is not visible to PHP, DMC cannot see the checkouts.

The primary checkout (bare `<repo>`) is **read-only by default** for mutating
operations — pass `--allow-primary-mutation` to override. The default-deny is
intentional: the primary tracks the deployed branch, and silent branch-switches
on it are how parallel agents corrupt each other's work.

```
~/.datamachine/workspace/
├── data-machine/                    ← primary, hands-off by default
├── data-machine@fix-foo/            ← worktree for fix/foo
└── data-machine@feat-bar/           ← worktree for feat/bar
```

## Requirements

- WordPress 6.9+
- PHP 8.2+
- [Data Machine](https://github.com/Extra-Chill/data-machine) plugin (core)
- A driver — at least one of:
  - An external coding-agent runtime on the same host (Claude Code, OpenCode, kimaki, Studio Code, etc.); see [`wp-coding-agents`](https://github.com/Extra-Chill/wp-coding-agents) for an opinionated setup.
  - A Data Machine flow on the site that calls DMC's tools / abilities (in-process driver).
  - A CI workflow that boots WordPress with DMC loaded and runs a DM flow against it; see [`wc-site-generator`](https://github.com/chubes4/wc-site-generator) for the canonical Playground-based example.

DMC's abilities still register without any of these — but nothing exercises them, and an idle workspace is just an empty directory.

## Installation

1. Install and activate Data Machine core
2. Clone this repo to `wp-content/plugins/data-machine-code`
3. Run `composer install`
4. Activate the plugin
5. Wire up at least one driver:
   - **Co-located runtime:** point a coding-agent runtime at the install. See [`wp-coding-agents`](https://github.com/Extra-Chill/wp-coding-agents).
   - **In-process flow:** create a DM flow whose AI step calls workspace / GitHub abilities directly. PR review flows (`wp datamachine-code github review-flow create`) are the bundled example.
   - **Ephemeral CI:** check DMC out into a CI job alongside Data Machine and run a flow inside Playground or a fresh WP install. See `wc-site-generator`'s `.github/workflows/playground-stage-5.yml` and `static-site-validation.yml` for working references.

## Configuration

Set your GitHub Personal Access Token and default repository in Data Machine settings:
- `github_pat` — GitHub Personal Access Token with repo access
- `github_default_repo` — Default repository in `owner/repo` format

### GitHub PR review webhooks

DMC registers a Data Machine webhook verifier mode named `github_pull_request` for PR-review flows. Configure the flow's webhook auth verifier with that mode and the same secret configured in the GitHub repository webhook.

The verifier checks `X-Hub-Signature-256` against the raw request body, requires `X-GitHub-Event: pull_request`, accepts only `opened`, `reopened`, `synchronize`, and `ready_for_review` actions by default, optionally restricts `repository.full_name`, and skips draft PRs unless `allow_drafts` is true.

Example verifier config:

```php
array(
    'mode'            => 'github_pull_request',
    'secrets'         => array(
        array(
            'id'    => 'github_webhook',
            'value' => '<shared webhook secret>',
        ),
    ),
    'repo'            => 'Extra-Chill/data-machine-code',
    'allowed_actions' => array( 'opened', 'reopened', 'synchronize', 'ready_for_review' ),
    'allow_drafts'    => false,
)
```

Workspace git policies are configured via the `datamachine_workspace_git_policies` option for per-repo write/push controls.

### GitHub fetch handler config

The GitHub fetch handler (`data_source: issues` or `pulls`) supports both server-side and post-fetch label/keyword filters. Server-side `labels` is forwarded to GitHub's REST `list issues` / `list pulls` endpoints; the post-fetch filters run after the API call and trim the in-memory result set.

| Field | Tier | Semantics |
|---|---|---|
| `labels` | server-side | Comma-separated label names. Forwarded to the GitHub REST endpoint. ANY-match include. |
| `exclude_labels` | post-fetch | Comma-separated label names. ANY-match drop, case-insensitive. Empty/missing is a no-op. Applies to both `issues` and `pulls`. |
| `exclude_keywords` | post-fetch | Comma-separated terms matched against title + body. ANY-match drop. |
| `search` | post-fetch | Required-keyword filter against title + body. |
| `timeframe_limit` | post-fetch | Bounds items by `created_at`. |

GitHub's REST `list issues` endpoint does not support negative label syntax, so `exclude_labels` is implemented as a post-fetch array intersection — symmetric with `exclude_keywords` in the same handler. Combine `labels` (positive, server-side) with `exclude_labels` (negative, post-fetch) to express "match these AND NOT those" in flow JSON without an AI-step prompt guard. Dropped items are logged at `debug` with the offending label(s).

### Workspace maintenance pipelines

DMC registers bundled, agent-generic Data Machine pipeline templates for recurring workspace maintenance. They are discoverable through the normal pipeline surface:

```bash
wp datamachine pipeline list --search="DMC Workspace"
```

Terminology:

- **Pipeline**: reusable recipe/template, owned by DMC and safe to inspect without running anything.
- **Flow**: agent/site-specific instance of a pipeline with scheduling and config such as workspace root, thresholds, retention windows, artifact profile, and dry-run/apply mode.
- **Job**: one execution created when a flow runs.

Bundled templates:

- **DMC Workspace Inventory**: cheap disk/worktree inventory and top cleanup offenders.
- **DMC Workspace Metadata Repair**: review-first lifecycle metadata reconciliation plan.
- **DMC Workspace Artifact Cleanup**: artifact-first cleanup for reconstructable generated files.
- **DMC Workspace Retention Cleanup**: age-gated cleanup for finalized, merged, or stale worktrees.
- **DMC Workspace Emergency Cleanup**: disk-pressure recovery template that starts with inventory and escalates only after review.

The bundled records do not provision flows or schedules. Create flows from these pipelines when an agent/site has explicit maintenance policy, thresholds, and dry-run/apply settings.

## Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│ Drivers — anything that pulls the trigger                        │
│                                                                  │
│  (a) External coding-agent runtime on the host                   │
│      Claude Code, OpenCode, kimaki, Studio Code                  │
│      → reads AGENTS.md, calls back via WP-CLI / MCP / REST       │
│                                                                  │
│  (b) In-process Data Machine flow on the site                    │
│      AI step → tool call → DMC ability                           │
│      → no external runtime, site codes on its own behalf         │
│                                                                  │
│  (c) Ephemeral CI job (Playground + Actions)                     │
│      Boots WP with DMC + DM, runs a flow, posts to PR, dies      │
└──────────────────────────────────────────────────────────────────┘
                              ▲
                              │  uses the same capability surface
                              ▼
┌──────────────────────────────────────────────────────────────────┐
│ data-machine-code (this plugin)                                  │
│  - composes & owns AGENTS.md at the WP root                      │
│  - declares \DataMachineCode\Environment capabilities            │
│  - hosts the workspace area (~/.datamachine/workspace/)          │
│  - registers GitHub / workspace / git abilities                  │
└──────────────────────────────────────────────────────────────────┘
                              ▲
                              │  built on
                              ▼
┌──────────────────────────────────────────────────────────────────┐
│ data-machine (core)                                              │
│  - AI engine, pipelines, jobs, agents                            │
│  - Memory system, MemoryFileRegistry, SectionRegistry            │
│  - Abilities API plumbing, tool framework                        │
└──────────────────────────────────────────────────────────────────┘
                              ▲
                              │  also extended by
                              ▼
┌──────────────────────────────────────────────────────────────────┐
│ Handler-adding siblings — different role from DMC                │
│  - data-machine-socials (social platforms)                       │
│  - data-machine-business (transforms)                            │
│  - ...                                                           │
└──────────────────────────────────────────────────────────────────┘
```

DMC extends core base classes (`BaseTool`, `SystemTask`, `BaseCommand`, `FetchHandler`) and registers its capabilities through standard Data Machine hooks (`datamachine_tools`, `datamachine_tasks`, `MemoryFileRegistry`, `SectionRegistry`, Abilities API). Its difference from the handler-adding siblings is **what it changes about the install**, not what API it uses to register things.

Memory and guideline disk projection follows the same boundary: Data Machine owns logical memory semantics and storage backends; DMC owns local runtime/file projection when explicit update events and writable disk are available. See [Memory Disk Projection](docs/memory-disk-projection.md).

### Capability detection

Other plugins gate disk-side or shell-using behavior on DMC's presence rather than on platform sniffing:

```php
if ( class_exists( '\DataMachineCode\Environment' ) ) {
    // This site can write code — register disk hooks,
    // sync SKILL.md to .opencode/skills/, write MEMORY.md, etc.
}

\DataMachineCode\Environment::has_shell();        // can we shell_exec?
\DataMachineCode\Environment::has_writable_fs();  // can we write outside /uploads?
```

This is intentionally simpler than detecting WP.com vs VIP vs self-hosted vs CI vs Studio — those distinctions don't matter. What matters is "can this WordPress site write code?" and that question has exactly one answer: "is DMC active?"

## Roadmap

- **Phase 1**: Core extraction complete, own settings management
- **Phase 2**: New dev tools — grep, search, code analysis, enhanced git
- **Phase 3**: Full PR workflow — autonomous code changes
- **Phase 4**: Multi-repo orchestration, webhooks, admin UI

## License

GPL v2 or later
