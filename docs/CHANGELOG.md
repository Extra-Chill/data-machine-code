# Changelog

All notable changes to Data Machine Code will be documented in this file.

## [0.46.2] - 2026-05-18

### Fixed
- emit real unified diff for remote workspace pending changes (#429)

## [0.46.1] - 2026-05-18

### Fixed
- keep artifact cleanup branch identity stable

## [0.46.0] - 2026-05-18

### Added
- project active workspace identity into Data Machine engine_data

## [0.45.2] - 2026-05-17

### Changed
- Expose pull request URLs in create PR schema

## [0.45.1] - 2026-05-17

### Changed
- generalize worktree session-attribution schema (#416)

## [0.45.0] - 2026-05-17

### Added
- switch release pipeline to homeboy-native release workflow

## [0.44.0] - 2026-05-16

### Added
- generic CLI transport runtime for agents/dispatch-message

### Fixed
- reuse existing pull requests

## [0.43.0] - 2026-05-16

### Changed
- Add workspace PR rebase abilities
- move release-asset build to release.published trigger
- add homeboy release asset workflow

### Fixed
- require opt-in for pipeline workspace tools

## [0.42.0] - 2026-05-15

### Added
- enforce workspace git policies
- support scoped workspace aliases
- add opaque workspace aliases
- add GitHub PR merge cleanup commands

### Changed
- extract workspace inventory cleanup
- extract workspace emergency cleanup
- extract workspace worktree lifecycle
- extract workspace repository lifecycle
- extract workspace git operations

### Fixed
- warn on base branch worktrees
- load site agents alongside repo instructions
- project site agents into worktrees
- clean up merged PR worktrees before branch deletion
- skip submodule worktrees during cleanup apply

## [0.41.4] - 2026-05-12

### Fixed
- budget cleanup preflight probes

## [0.41.3] - 2026-05-12

### Changed
- extract workspace hygiene reporting
- extract workspace cleanup planning

### Fixed
- budget full worktree cleanup dry-runs
- filter workspace list by repo

## [0.41.2] - 2026-05-12

### Changed
- extract workspace metadata reconciliation

### Fixed
- skip PR lookup for unsafe active rows

## [0.41.1] - 2026-05-12

### Changed
- extract workspace artifact cleanup

### Fixed
- batch dirty path classification

## [0.41.0] - 2026-05-12

### Added
- expose active no-signal probe timing

## [0.40.0] - 2026-05-12

### Added
- expose slow worktree row timing

## [0.39.1] - 2026-05-12

### Fixed
- make worktree reconciliation budget-aware

## [0.39.0] - 2026-05-11

### Added
- promote equivalent clean worktrees

## [0.38.0] - 2026-05-11

### Added
- classify effective dirty worktree status

## [0.37.0] - 2026-05-11

### Added
- diagnose dirty worktree upstream equivalence

## [0.36.0] - 2026-05-11

### Added
- promote finalized worktree report evidence
- fetch GitHub Actions artifact items

## [0.35.0] - 2026-05-11

### Added
- report active worktree cleanup evidence

### Fixed
- declare agent tool runtime metadata
- support remote workspace show and diff

## [0.34.4] - 2026-05-11

### Fixed
- resolve finalized prs by branch head

## [0.34.3] - 2026-05-11

### Fixed
- reconcile active branches with github signals

## [0.34.2] - 2026-05-11

### Fixed
- keep direct reconciliation apply bounded

## [0.34.1] - 2026-05-11

### Fixed
- expose reconciliation apply cli flags

## [0.34.0] - 2026-05-11

### Added
- expose worktree add tool
- expose policy-gated workspace tools

### Fixed
- add budgeted metadata reconciliation drain
- schedule metadata reconciliation jobs
- support bounded reconciliation apply

## [0.33.0] - 2026-05-11

### Added
- enrich GitHub context metadata

### Fixed
- clarify worktree cleanup reporting
- persist cleanup eligibility signals
- bound worktree metadata reconciliation
- guard repeated issue automation comments
- omit empty run artifact sections

## [0.32.0] - 2026-05-10

### Added
- add surgical GitHub label tools
- attach run artifacts to agent pull requests
- write run artifacts to PR branches
- add workspace grep match anchors
- render agent run artifact PR sections
- add workspace diff validation tools
- label GitHub artifacts with agent provenance
- add WordPress runtime inspection tools
- expose GitHub file upsert tool
- add remote workspace backend
- add guarded GitHub PR merge ability
- classify merged dirty worktrees with only obsolete-on-default edits

### Changed
- isolate workspace diff tools
- Add workspace grep tool

### Fixed
- guard GitHub file writes by path scope
- fall back when remote workspace branch is absent
- return github files as a batch
- declare no-argument tool schemas
- use canonical AI tool schemas
- declare workspace diff array schema items
- forward PR tool run artifact context
- persist direct PR run artifacts
- satisfy split diff lint
- align run artifact attachment formatting
- finish diff validation alignment
- clean workspace anchor lint
- align diff validation locals
- satisfy diff validation lint
- expose canonical repo for workspace pushes
- align diff summary parsing locals
- clean diff summary formatting
- clarify AI tool result contracts
- Fix remote workspace file updates
- expose workspace git capability diagnostics
- use temp workspace fallback in ephemeral runtimes
- stream workspace clone progress
- declare GitHub issue array schema items
- complete issue array schemas

## [0.31.0] - 2026-05-07

### Added
- surface live workspace inventory in AGENTS.md
- add exclude_labels post-fetch filter to GitHub fetch handler
- support targeted github fetch by issue_number / pull_number
- apply labels on github_pull_request publish

### Changed
- Add GitHub pull request publish handler
- Add GitHub issue publish handler

### Fixed
- add workspace patch application
- generalize GitHub Actions artifact retrieval
- skip schema upgrade during wp install bootstrap
- expose bounded cleanup-eligible apply path
- commit files during GitHub PR publish
- create missing GitHub upsert branches

## [0.30.1] - 2026-05-05

### Fixed
- declare GitHub tool array item schemas
- expose GitHub pull request creation tool

## [0.30.0] - 2026-05-05

### Added
- support multiple GitHub credential profiles per agent/repo
- add datamachine/create-github-issue and create-github-pull-request abilities

### Fixed
- auto-finalize merged worktree metadata
- enable safe workspace retention cleanup
- surface cleanup lifecycle buckets

## [0.29.1] - 2026-05-04

### Fixed
- route database cleanup run ids

## [0.29.0] - 2026-05-04

### Added
- persist worktree lifecycle inventory
- persist cleanup runs in database
- add DB-backed worktree inventory

### Fixed
- isolate cleanup evidence storage
- expose workspace cleanup lock retention

## [0.28.4] - 2026-05-04

### Fixed
- rename repaired metadata cleanup surface

## [0.28.3] - 2026-05-04

### Fixed
- add legacy repaired cleanup opt-in
- remove legacy cleanup compatibility paths
- surface actionable cleanup skip commands

## [0.28.2] - 2026-05-04

### Fixed
- fan out artifact cleanup discovery
- aggregate cleanup chunk evidence
- aggregate cleanup child job status

## [0.28.1] - 2026-05-04

### Fixed
- bound artifact cleanup apply revalidation

## [0.28.0] - 2026-05-04

### Added
- bounded cleanup-eligible apply for explicit lifecycle metadata
- surface agent/session liveness on worktrees (#221)

### Fixed
- bound artifact cleanup dry-run on huge workspaces
- bounded batched reconciliation for legacy worktrees
- scale workspace worktree list on huge workspaces (#213)

## [0.27.2] - 2026-05-03

### Changed
- Refactor workspace cleanup onto system tasks

## [0.27.1] - 2026-05-03

### Fixed
- accept maintenance flow provision action

## [0.27.0] - 2026-05-03

### Added
- provision agent maintenance flows
- emit chunked workspace cleanup plans
- register workspace maintenance pipeline templates

### Fixed
- trigger emergency cleanup on disk pressure
- run cleanup chunks as jobs
- add flow-backed cleanup CLI controls

## [0.26.0] - 2026-05-03

### Added
- add retention cleanup task

### Fixed
- clean up release-blocking workspace lint
- mark finalized PR worktrees cleanup eligible
- surface disk inventory offenders
- repair legacy worktree metadata
- add emergency worktree cleanup mode
- bound worktree cleanup probes
- refuse unsafe worktree disk budgets
- document worktree finalizer CLI flags

## [0.25.1] - 2026-05-02

### Changed
- Internal improvements

## [0.25.0] - 2026-04-30

### Added
- adopt existing primary checkouts

### Fixed
- diagnose invisible workspace roots
- verify shell commands execute

## [0.24.0] - 2026-04-30

### Added
- reconcile legacy worktree metadata
- feat(code-task): scaffold tasks from evidence packets

### Fixed
- declare homeboy availability for compose
- add artifact-only cleanup plans

## [0.23.3] - 2026-04-29

### Fixed
- expose inventory-only cleanup CLI flag

## [0.23.2] - 2026-04-29

### Fixed
- add inventory-only cleanup review

## [0.23.1] - 2026-04-29

### Fixed
- keep hygiene cheap without status scans

## [0.23.0] - 2026-04-29

### Added
- add workflow run PR review trigger

## [0.22.0] - 2026-04-29

### Added
- add hygiene report
- report worktree disk cleanup pressure
- track worktree finalizer metadata

### Fixed
- guard worktree creation by disk budget

## [0.21.0] - 2026-04-28

### Added
- document disk projection boundary
- add cleanup age filter

### Fixed
- add cleanup plan apply workflow
- reduce cleanup GitHub lookups

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
