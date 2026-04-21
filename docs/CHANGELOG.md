# Changelog

All notable changes to Data Machine Code will be documented in this file.

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
