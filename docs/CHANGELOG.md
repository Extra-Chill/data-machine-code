# Changelog

All notable changes to Data Machine Code will be documented in this file.

## [0.20.1] - 2026-04-28

### Fixed
- polish worktree cleanup UX

## [0.20.0] - 2026-04-28

### Added
- add repository review profile context
- run checkout-backed Homeboy PR reviews

## [0.19.0] - 2026-04-28

### Added
- record worktree lifecycle metadata
- add PR documentation impact packets
- add PR review escalation policy

### Fixed
- route external cleanup skips
- cache cleanup GitHub lookups
- stabilize cleanup report contract

## [0.18.0] - 2026-04-27

### Added
- install PR review flows

## [0.17.0] - 2026-04-27

### Added
- fetch Homeboy CI artifacts
- support GitHub App authentication

## [0.16.0] - 2026-04-27

### Added
- add PR review check status context
- strengthen PR review context loop

## [0.15.0] - 2026-04-27

### Added
- upsert managed PR review comments
- add PR review flow scaffold
- validate PR review webhooks

## [0.14.0] - 2026-04-27

### Added
- expose expanded review context tool params
- expose read-only source context tools
- expand PR review context
- add PR review context fetch
- add narrow PR comment tool

## [0.13.2] - 2026-04-27

### Changed
- test(agents-md): pin memory compose marker

### Fixed
- serialize worktree mutations per repo
- surface minion routing in injected context

## [0.13.1] - 2026-04-27

### Fixed
- bootstrap nested dependency roots
- accept base-branch worktree alias
- fix(agents-md): point compose verb at `datamachine memory`, not `datamachine agent`

## [0.13.0] - 2026-04-25

### Added
- feat(agents.md): priority-0 marker section + tighten Code subsection
- add delete primitive (closes #64)

### Fixed
- collect repeatable --rel flag from argv (closes #67)
- land delete_path impl + ability registration missed by #65

## [0.12.1] - 2026-04-25

### Fixed
- fix(agents-md): point Memory verb at `datamachine memory`, not `datamachine agent`

## [0.12.0] - 2026-04-25

### Added
- expose metadata in listings

### Fixed
- align $metadata assignment with surrounding block
- rename workspace --path to --rel (collides with WP-CLI global)
- clear lint check blockers

## [0.11.0] - 2026-04-25

### Added
- feat(agents-md): contribute homeboy CLI section when on PATH

### Fixed
- fix(agents-md): include resolved workspace root

## [0.10.0] - 2026-04-24

### Added
- feat(agents-md): surface full DM CLI command surface (chat, email, agents mgmt, content ops, retention, gitsync)

## [0.9.0] - 2026-04-24

### Added
- gate stale worktree creation + opt-in rebase
- surface worktree staleness at create-time
- bootstrap worktrees by default (closes #50)

## [0.8.0] - 2026-04-21

### Added
- migrate GitHubIssueTask + WorktreeCleanupTask to executeTask() contract
- inject originating site agent context into worktrees

### Fixed
- allow nullable branch/remote/commit in workspace output schemas

## [0.7.0] - 2026-04-21

### Added
- **GitSync subsystem** (Phases 1–3): site-owned directories bound to remote git repos via the GitHub Contents API. Managed-hosting compatible; conflict policies (`manual_fail`, `upstream_wins`); sticky proposal-branch submit path. Core classes: `GitSync`, `GitSyncBinding`, `GitSyncAbilities`.
- `PathSecurity` support helper for GitSync and future path-based APIs.
- `WorktreeCleanupTask` system task + `worktree cleanup` subcommand for merged branches, on a daily recurring schedule via `data-machine#1117`.
- `DataMachineCode\Environment` signal for local-runtime detection.
- `datamachine_workspace_git_policies` filter for runtime-injected per-repo policy alongside the existing option.
- Phase 2 — write path + sticky proposal branch submit (#38)
- Phase 1 — bind site-owned dirs to remote git repos (#38)
- daily recurring schedule via Extra-Chill/data-machine#1117
- WorktreeCleanupTask system task + unpushed-commits guard

### Fixed
- workspace git wrapper: `git pull/add/commit/push` no longer fail with `git_write_disabled` on repos that have no entry in `datamachine_workspace_git_policies`. Unconfigured repos now default to permissive (primary-vs-worktree protection remains via `--allow-primary-mutation`). Explicit `write_enabled: false` / `push_enabled: false` still deny. Error messages clarified to reference the policy option.
- `workspace git add`: `no_allowed_paths` no longer blocks unconfigured repos. The `allowed_paths` list is now opt-in — when configured, it restricts; when absent, any relative path is accepted (sensitive-path + traversal checks still enforced).
- permissive-by-default git mutation gates

### Changed
- AGENTS.md: flatten hierarchy to H2 top-level sections; declare header via `MemoryFileRegistry` arg.
- restore on-disk version + changelog to v0.6.2 state
- rebuild on GitHub Contents API for managed-hosting compatibility (#38)

## [0.6.2] - 2026-04-19

### Changed
- rename executeUpdate() → executeUpsert() to match renamed base class

## [0.6.1] - 2026-04-19

### Fixed
- match abstract method name executeUpdate from UpsertHandler base class

## [0.6.0] - 2026-04-19

### Added
- `worktree cleanup` subcommand for merged branches
- add DataMachineCode\Environment as the local-runtime signal

### Changed
- Add 'files' data source to GitHub fetch handler
- rename GitHubUpdate → GitHubUpsert for naming accuracy
- Add GitHub update handler for committing files back to repos

### Fixed
- path-aware worktree cleanup + safety rails + tests
- Fix workspace-clone ability validation: omit name when not provided

## [0.5.0] - 2026-04-17

### Added
- Worktree-native workspace: each branch lives in its own directory at `<workspace>/<repo>@<branch-slug>`. Multiple agent sessions can edit different branches of the same repo simultaneously.
- Four new abilities: `datamachine/workspace-worktree-add|list|remove|prune`.
- New CLI subcommand: `wp datamachine-code workspace worktree add|list|remove|prune`.
- All read/write/git abilities accept the new `<repo>@<branch-slug>` handle format alongside bare repo names.
- Pure-PHP smoke test for handle parsing and slug generation (`tests/smoke-worktree-handles.php`).
- Manual end-to-end test plan (`tests/TESTING.md`).
- worktree-native workspace for parallel branch work

### Changed
- `clone` rejects names containing `@` — that suffix is reserved for worktrees.
- `remove` refuses to delete a primary checkout that has linked worktrees attached.
- `git push` only enforces the `fixed_branch` policy on the primary checkout. Worktrees may push any branch.
- Mutating ops (`git pull|add|commit|push`) on a primary checkout require `--allow-primary-mutation` (CLI) / `allow_primary_mutation: true` (ability input). Worktrees are always allowed. Default-deny prevents parallel agents from clobbering the deployed branch.

## [0.4.0] - 2026-04-15

### Added
- ability categories and tool-ability linkage

### Changed
- Make WP-CLI command prefix filterable via datamachine_wp_cli_cmd
- Emphasize workspace/GitHub workflow over direct file editing in AGENTS.md sections

### Fixed
- Fix ability category registration — correct hook and slug format

## [0.3.0] - 2026-04-11

### Added
- full AGENTS.md sections with dynamic WP-CLI resolution

### Fixed
- Fix section registry slugs to use datamachine prefix

## [0.2.2] - 2026-04-08

### Fixed
- resolve PHPCS and PHPStan lint issues

## [0.2.1] - 2026-04-07

### Fixed
- add $HOME/.datamachine/workspace fallback for local/macOS environments

## [0.2.0] - 2026-04-02

### Added
- own workspace service classes — migrated from data-machine core
- register code.md context file via datamachine_default_context_files
- initial scaffold — extract developer tools from Data Machine core

### Changed
- Migrate all ability callbacks to WP_Error returns
- Add homeboy project config and composer lock

### Fixed
- move CLI registration to plugins_loaded to resolve load order

## [0.1.0] - 2026-03-21

### Added
- Initial release — extracted developer tooling from Data Machine core
- GitHub integration: issues, PRs, repos, comments via Abilities API
- Workspace management: clone, list, show, read, write, edit, remove
- Git operations: status, log, diff, pull, add, commit, push
- GitHub fetch handler for pipeline integration
- CLI commands: `wp datamachine-code github` and `wp datamachine-code workspace`
- AI agent tools: GitHub tools (5 tools) and Workspace tools (5 tools)
- GitHub issue creation system task
- GitHub issue creation AI tool (async via Action Scheduler)
