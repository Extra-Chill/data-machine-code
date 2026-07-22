# Data Machine Code

**Data Machine Code gives a WordPress site code-adjacent GitHub and workspace abilities** — API-first GitHub operations and shell-backed workspaces for installs that can run a local coding runtime. Built on [Data Machine](https://github.com/Extra-Chill/data-machine), powered by the Abilities API.

Who pulls the trigger is up to you. DMC has three driver modes, and each mode uses only the capabilities the host actually supports:

- **An external coding-agent runtime** on your machine pointed at the site — interactive, human-in-the-loop; requires shell/git/workspace access.
- **The site itself, via a Data Machine flow** — scheduled or webhook-triggered; can use API-first GitHub abilities without a shell, or workspace abilities when the host supports them.
- **An ephemeral CI job** that boots a WordPress instance with DMC loaded for a single check (Playground + GitHub Actions). The site exists for one job, codes for one PR, dies.

Managed-host support depends on the subsystem. GitHub API abilities use `wp_remote_request()` and are designed to work on managed hosts. Workspace, worktree, shell-git, AGENTS.md projection, and co-located runtime features require a host where PHP can see and mutate the configured workspace and, for git operations, execute shell commands.

## What It Is

DMC's activation is the declarative answer to **"does this WordPress site have code-adjacent capabilities?"** When DMC is loaded, the install gains GitHub API abilities and shell-backed workspace/git abilities when the environment supports them. The `\DataMachineCode\Environment` surface lets other plugins gate disk-side behavior without guessing which host they are running on.

That framing reshapes what every other plugin can assume:

- **AGENTS.md is composed and written to the WP root** for external runtimes that discover it on session start, when the filesystem permits it. DMC owns the file, contributes core sections, and lets other plugins register more via Data Machine's `SectionRegistry`. In-process and CI drivers don't use AGENTS.md — they get context through Data Machine's normal channels (system prompts, memory files, flow user messages). AGENTS.md is a co-located-runtime feature, not the universal interface.
- **The site gains GitHub, workspace, and git abilities** through the Abilities API. API-first abilities are host-portable; workspace/git abilities are still gated by filesystem and shell capability.
- **A worktree-native workspace area** at `~/.datamachine/workspace/` lets shell-capable installs clone repos, edit files in isolated branches, and push changes — all gated by per-repo policies. Same workspace whether the editor is an external runtime, an in-process AI step, or a CI job.
- **`\DataMachineCode\Environment` is a capability surface** plugins can read to ask "is DMC active?", "can we shell out?", "is the filesystem writable outside `/uploads`?". Other DM plugins use it to gate disk-side hooks (e.g. Intelligence's `SKILL.md` sync).

The important seam is not self-hosted versus managed; it is API-first versus shell-backed. Managed sites can use the API-first pieces when installed and configured. Shell-backed coding-runtime features remain intentionally limited to hosts that expose shell/git/workspace access.

## Driver Modes

| Mode | Driver | Lifetime | Example |
|---|---|---|---|
| **Co-located runtime** | External agent CLI on the host | Long-lived install | [`wp-coding-agents`](https://github.com/Extra-Chill/wp-coding-agents) on a VPS, a Studio site with a co-located coding agent |
| **In-process flow** | DM AI step inside a flow | Long-lived install | An Intelligence wiki maintenance flow calling workspace abilities; a webhook-triggered PR review flow |
| **Ephemeral CI** | DM flow inside Playground / GitHub Actions | One job | [`wc-site-generator`](https://github.com/chubes4/wc-site-generator) static-site validation; the Stage 5 Playground proof |

The registered surface is broad, but callers should select abilities that match the host. For example, a managed-host flow can use GitHub API abilities, while a co-located runtime can additionally use workspace and shell-git abilities.

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
Requires a host where PHP can read and write the configured workspace path.

### Git Operations
- Status, log, diff (read-only)
- Pull, add, commit, push (policy-controlled)
- Per-repo write/push policies with path allowlists and branch restrictions
Requires shell execution and a local git binary.

### AI Agent Tools
- GitHub and workspace chat tools for read-only context gathering plus managed PR review comments
- WordPress runtime inspection tools for read-only perception of live versions, plugins/themes, drop-ins, and allowlisted source files
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
wp datamachine-code workspace path repo-name@fix-foo
wp datamachine-code workspace list
wp datamachine-code workspace clone https://github.com/org/repo.git
wp datamachine-code workspace show repo-name

# Worktrees — one per branch, parallel-safe
wp datamachine-code workspace worktree add repo-name fix/foo
wp datamachine-code workspace worktree list
wp datamachine-code workspace worktree remove repo-name fix/foo
wp datamachine-code workspace worktree prune

# Typed Homeboy promotion apply (explicit handle supports {handle} argv substitution)
wp datamachine-code workspace promotion-apply repo-name@fix-foo < promotion-request.json

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

`promotion-apply` reads one `homeboy/agent-task-promotion-apply-request/v1`
object from stdin and emits `homeboy/agent-task-promotion-apply-response/v1` on
stdout. It only applies through the managed worktree patch primitive and refuses
primary, dirty, mismatched, and untrusted-unpushed targets.

### Worktrees: parallel-safe branch work

The workspace is **worktree-native**. Each branch lives in its own directory at
`<workspace>/<repo>@<branch-slug>` (slashes in branch names become dashes). Multiple
agent sessions — or multiple flows, or multiple CI jobs — can edit different
branches of the same repo simultaneously without stepping on each other.

DMC discovers primary checkouts and worktrees by scanning the configured workspace
root. Worktree lifecycle metadata supports cleanup and reconciliation; if that
workspace path is not visible to PHP, DMC cannot see the checkouts.

SQLite is supported for DMC registries and lower-concurrency workspace use. For
concurrent fleet cooking, use MySQL: SQLite permits one writer at a time. DMC
retries short SQLite busy/locked registry writes with bounded backoff and returns
a structured lock-contention error when that budget is exhausted; it does not
make prolonged multi-writer contention equivalent to MySQL throughput.

Workspace and worktree commands are shell-backed and require a host where PHP can see the configured workspace root.

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
- A driver for the abilities you plan to use — at least one of:
  - An external coding-agent runtime on the same host; see [`wp-coding-agents`](https://github.com/Extra-Chill/wp-coding-agents) for an opinionated setup.
  - A Data Machine flow on the site that calls DMC's tools / abilities (in-process driver).
  - A CI workflow that boots WordPress with DMC loaded and runs a DM flow against it; see [`wc-site-generator`](https://github.com/chubes4/wc-site-generator) for the canonical Playground-based example.
- Shell-backed workspace/git features require `exec()`, a local `git` binary, and a visible writable workspace path.
DMC's abilities still register without a co-located runtime. API-first flows can exercise GitHub abilities directly; an idle workspace is only relevant when using workspace/git abilities.

## Installation

1. Install and activate Data Machine core
2. Clone this repo to `wp-content/plugins/data-machine-code`
3. Run `composer install`
4. Activate the plugin
5. Wire up at least one driver or API-first flow:
   - **Co-located runtime:** point a coding-agent runtime at the install. See [`wp-coding-agents`](https://github.com/Extra-Chill/wp-coding-agents).
   - **In-process flow:** create a DM flow whose AI step calls GitHub, workspace, or git abilities directly. PR review flows (`wp datamachine-code github review-flow create`) are the bundled example.
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
│      any external coding agent CLI                                │
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

Other plugins should gate disk-side or shell-using behavior on explicit DMC capability checks rather than on platform sniffing:

```php
if ( class_exists( '\DataMachineCode\Environment' ) ) {
    // DMC is active. Register API-first integrations, then check
    // narrower filesystem/shell capabilities before disk runtime hooks.
}

\DataMachineCode\Environment::has_shell();        // can we shell_exec?
\DataMachineCode\Environment::has_writable_fs();  // can we write outside /uploads?
```

This is intentionally simpler than detecting WP.com vs VIP vs self-hosted vs CI vs Studio. What matters is which DMC subsystem the caller needs: API-first GitHub, writable filesystem projection, or shell-backed workspace/git.

### Mounted Runtime Context

Mounted runtimes can self-configure DMC by passing a context through `$GLOBALS['mounted_runtime_context']`, `$GLOBALS['wordpress_runtime_context']`, `MOUNTED_RUNTIME_CONTEXT`, or `MOUNTED_RUNTIME_WORKSPACE_ROOT`.

When a runtime needs to pass a versioned contract, use the generic WordPress runtime schema:

```json
{
  "schema": "wordpress/runtime-context/v1",
  "payload": {
    "workspace_root": "/workspace",
    "runtime_workspace": {
      "root": "/workspace",
      "mounts": [
        { "target": "/workspace/example", "sourceMode": "mounted" }
      ]
    }
  }
}
```

DMC unwraps the versioned `payload` to the same internal context shape as the generic mounted-runtime contract, so existing workspace root and mount adoption behavior stays shared. `runtime_workspace` is the canonical workspace object; legacy runtime-specific workspace aliases should be translated by the runtime owner before DMC receives the context.

## Roadmap

- **Phase 1**: Core extraction complete, own settings management
- **Phase 2**: New dev tools — grep, search, code analysis, enhanced git
- **Phase 3**: Full PR workflow — autonomous code changes
- **Phase 4**: Multi-repo orchestration, webhooks, admin UI

## License

GPL v2 or later
