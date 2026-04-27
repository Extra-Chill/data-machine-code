# Data Machine Code

The bridge between WordPress and an external coding-agent runtime (Claude Code, OpenCode, kimaki, etc.). Built on [Data Machine](https://github.com/Extra-Chill/data-machine).

## What It Is

Data Machine Code's activation is the declarative answer to **"is there a coding agent here?"** When DMC is loaded, the WordPress install is no longer just a site running an AI — it is the WordPress half of a coding-agent system. The other half is whatever runtime the user pointed at the install (Claude Code, OpenCode, kimaki, Studio Code, etc.).

That framing reshapes what every other plugin can assume:

- **AGENTS.md is composed and written to the WP root**, where the runtime discovers it on session start. DMC owns the file, contributes core sections, and lets other plugins register more via Data Machine's `SectionRegistry`.
- **The runtime gains GitHub, workspace, and git abilities** through the Abilities API — every ability is automatically callable from chat, MCP, REST, and WP-CLI.
- **A worktree-native workspace area** at `~/.datamachine/workspace/` lets agents clone repos, edit files in isolated branches, and push changes — all gated by per-repo policies.
- **`\DataMachineCode\Environment` is a capability surface** plugins can read to ask "is a coding agent here?", "can we shell out?", "is the filesystem writable outside `/uploads`?". Other DM plugins use it to gate disk-side hooks (e.g. Intelligence's `SKILL.md` sync).

On managed hosts (WordPress.com, VIP, sandboxed environments) DMC is **never installed by design**. The class doesn't exist, AGENTS.md isn't composed, no shell capabilities are advertised. Site owners get a vanilla Data Machine install without any expectation of a coding agent running alongside it. That asymmetry is the whole point — DMC is the seam between "WordPress that knows about a coding agent" and "WordPress that doesn't."

## How It Differs From Other Data Machine Extensions

Sibling extensions like `data-machine-socials` and `data-machine-business` are **handler-adding** — they extend DM's pipeline machinery with new sources, destinations, or transforms. DMC is **runtime-altering** — its presence changes what kind of system this is. That's why DMC owns AGENTS.md, declares environment capabilities, and gates the existence of an entire class of disk-side functionality. Same plugin chassis, fundamentally different role.

## Features

### GitHub Integration
- List, view, update, close, and comment on issues
- List pull requests and repositories
- Create issues (async via Action Scheduler)
- GitHub fetch handler for pipeline workflows
- GitHub pull request webhook validation mode for review-flow triggers

### Workspace Management
- Clone and manage git repositories in a secure workspace directory
- Read, write, and edit files within workspace repos
- List directory contents with file metadata

### Git Operations
- Status, log, diff (read-only)
- Pull, add, commit, push (policy-controlled)
- Per-repo write/push policies with path allowlists and branch restrictions

### AI Agent Tools
- 10 chat tools for GitHub and workspace operations
- Async GitHub issue creation via system tasks
- All tools register through Data Machine's tool system

### WP-CLI
```bash
# GitHub
wp datamachine-code github issues --repo=owner/repo
wp datamachine-code github view 123 --repo=owner/repo
wp datamachine-code github close 123 --repo=owner/repo
wp datamachine-code github comment 123 "Fixed." --repo=owner/repo
wp datamachine-code github pulls --repo=owner/repo
wp datamachine-code github repos owner
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
agent sessions can edit different branches of the same repo simultaneously without
stepping on each other.

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
- A coding-agent runtime on the same host (Claude Code, OpenCode, kimaki, etc.) — DMC is the WordPress half of that pairing. Without a runtime calling back, DMC's abilities still register but nothing exercises them.

## Installation

1. Install and activate Data Machine core
2. Clone this repo to `wp-content/plugins/data-machine-code`
3. Run `composer install`
4. Activate the plugin
5. Point a coding-agent runtime at the install (out of scope for this README; see [`wp-coding-agents`](https://github.com/Extra-Chill/wp-coding-agents) for an opinionated setup)

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

## Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│ Coding-agent runtime (Claude Code, OpenCode, kimaki, ...)        │
│  - reads AGENTS.md on session start                              │
│  - calls back into WP via WP-CLI, MCP, REST                      │
└──────────────────────────────────────────────────────────────────┘
                              ▲
                              │  bridge
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

### Capability detection

Other plugins gate disk-side or shell-using behavior on DMC's presence rather than on platform sniffing:

```php
if ( class_exists( '\DataMachineCode\Environment' ) ) {
    // Co-located coding-agent runtime exists — register disk hooks,
    // sync SKILL.md to .opencode/skills/, write MEMORY.md, etc.
}

\DataMachineCode\Environment::has_shell();        // can we shell_exec?
\DataMachineCode\Environment::has_writable_fs();  // can we write outside /uploads?
```

This is intentionally simpler than detecting WP.com vs VIP vs self-hosted — those distinctions don't matter. What matters is "is a coding agent listening?" and that question has exactly one answer: "is DMC active?"

## Roadmap

- **Phase 1**: Core extraction complete, own settings management
- **Phase 2**: New dev tools — grep, search, code analysis, enhanced git
- **Phase 3**: Full PR workflow — autonomous code changes
- **Phase 4**: Multi-repo orchestration, webhooks, admin UI

## License

GPL v2 or later
