# Changelog

All notable changes to Data Machine Code will be documented in this file.

## [0.51.9] - 2026-07-17

### Changed
- Preserve artifact cleanup force intent through apply

## [0.51.8] - 2026-07-16

### Changed
- Keep cleanup safe JSON output machine-readable
- Make artifact cleanup apply guidance actionable
- Ignore artifact maintenance in worktree recency
- Report DB-backed cleanup apply counts accurately
- Repair undrainable cleanup children during drain

## [0.51.7] - 2026-07-14

### Fixed
- protect live worktrees from cleanup

## [0.51.6] - 2026-07-13

### Changed
- externalize runtime context projections

## [0.51.5] - 2026-07-13

### Fixed
- Fix optional workspace clone name handling

## [0.51.4] - 2026-07-13

### Fixed
- Fix SQLite worktree registry contention

## [0.51.3] - 2026-07-13

### Fixed
- Fix canonical worktree handle lookup

## [0.51.2] - 2026-07-13

### Fixed
- serialize worktree list JSON directly

## [0.51.1] - 2026-07-13

### Changed
- Align targeted worktree lookup assignments

## [0.51.0] - 2026-07-13

### Added
- add targeted worktree safety lookup

## [0.50.0] - 2026-07-12

### Added
- expose worktree safety fields for orchestrators

## [0.49.5] - 2026-07-12

### Fixed
- exclude submodules from package bootstrap

## [0.49.4] - 2026-07-12

### Fixed
- waive reviewed cleanup recency guard (#888)

## [0.49.3] - 2026-07-12

### Fixed
- Fix retention cleanup observer-heartbeat deadlock

## [0.49.2] - 2026-07-08

### Fixed
- treat patch-equivalence to default as a merged cleanup signal

## [0.49.1] - 2026-07-06

### Fixed
- Fix worktree cleanup merged classifier

## [0.49.0] - 2026-07-06

### Added
- prune orphaned missing_path inventory rows

## [0.48.44] - 2026-07-05

### Fixed
- Fix cleanup release lint

## [0.48.43] - 2026-07-05

### Fixed
- Fix cleanup repo scoping for paginated paths

## [0.48.42] - 2026-07-05

### Changed
- Add patch-equivalent worktree cleanup path
- Improve abandoned cleanup no-progress guidance

### Fixed
- Fix safe cleanup evidence byte rollups

## [0.48.41] - 2026-07-05

### Changed
- use review quality commands

## [0.48.40] - 2026-07-04

### Changed
- Protect active worktrees from retention cleanup

## [0.48.39] - 2026-07-04

### Fixed
- honor upstream-equivalent cleanup signals

## [0.48.38] - 2026-07-03

### Changed
- Stop abandoned cleanup on zero-yield pages

### Fixed
- Fix cleanup eligible drain dry-run counts

## [0.48.37] - 2026-07-03

### Changed
- Stop active no-signal drain on zero-yield pages
- Explain remaining metadata reconcile blockers
- Explain hygiene cleanup revalidation blockers

### Fixed
- Fix retention cleanup plan worktree rows
- Fix empty safe cleanup status finalization

## [0.48.36] - 2026-07-03

### Fixed
- Fix active no-signal drain restart continuation

## [0.48.35] - 2026-07-01

### Changed
- Expose safe cleanup progress evidence
- Make cleanup blocker size accounting explicit

## [0.48.34] - 2026-07-01

### Changed
- cover worktree disk pressure gate

## [0.48.33] - 2026-07-01

### Changed
- Resolve mounted paths through workspace refs

## [0.48.32] - 2026-06-30

### Changed
- Expose safe workspace cleanup ability

## [0.48.31] - 2026-06-28

### Fixed
- make primary refresh recoverable when default branch has no upstream

## [0.48.30] - 2026-06-28

### Changed
- Reframe plugin description around the coding tools DMC provides

## [0.48.29] - 2026-06-26

### Changed
- Add safe workspace cleanup entrypoint
- Clarify hygiene inventory blocker counts
- Clarify cleanup restart continuations

### Fixed
- Fix hygiene cleanup candidate terminology
- clarify active no-signal drain budget continuation
- Fix cleanup metadata compact samples

## [0.48.28] - 2026-06-26

### Changed
- Summarize active no-signal drain backlog

### Fixed
- Fix artifact cleanup child drainability
- Fix hygiene cleanup candidate safety labels

## [0.48.27] - 2026-06-26

### Changed
- Add active no-signal cleanup drain

### Fixed
- Fix cleanup drain lint

## [0.48.26] - 2026-06-26

### Changed
- Remove obsolete bounded cleanup formatter
- Compact worktree cleanup operator JSON

### Fixed
- Fix cleanup blocked-only lint
- Fix artifact cleanup blocked-only completion

## [0.48.25] - 2026-06-25

### Changed
- Align AGENTS inventory assignments
- Refresh AGENTS inventory after primary pulls
- Surface stale primaries in AGENTS inventory

### Fixed
- Fix AGENTS inventory lint

## [0.48.24] - 2026-06-25

### Changed
- Compact workspace cleanup JSON output

### Fixed
- Fix cleanup apply pagination restart
- Fix artifact cleanup apply command contract
- Fix cleanup revalidation candidate state

## [0.48.23] - 2026-06-24

### Changed
- Remove mounted sandbox runtime compatibility

## [0.48.22] - 2026-06-24

### Changed
- Neutralize mounted runtime fixture vocabulary

## [0.48.21] - 2026-06-22

### Changed
- Preserve mounted workspace refs

## [0.48.20] - 2026-06-22

### Changed
- Tighten mounted runtime vocabulary coverage

## [0.48.19] - 2026-06-22

### Changed
- Remove Codebox runtime context vocabulary

## [0.48.18] - 2026-06-22

### Changed
- Use generic mounted runtime context
- Use runtime workspace context for mounted runtimes

## [0.48.17] - 2026-06-21

### Changed
- Use runtime workspace context for mounted runtimes

## [0.48.16] - 2026-06-21

### Changed
- Add mounted runtime context contract

## [0.48.15] - 2026-06-21

### Changed
- Improve worktree metadata finalization

## [0.48.14] - 2026-06-19

### Fixed
- Fix mounted sandbox open_basedir probe

## [0.48.13] - 2026-06-18

### Fixed
- Fix local worktree remove routing

## [0.48.12] - 2026-06-17

### Changed
- Add cleanup-eligible drain command

## [0.48.11] - 2026-06-17

### Changed
- Add active no-signal triage preview
- Make workspace hygiene size scans opt-in
- Improve cleanup summary output

### Fixed
- Fix disk-budget refusal cleanup guidance

## [0.48.10] - 2026-06-17

### Fixed
- Fix release lint formatting

## [0.48.9] - 2026-06-17

### Changed
- Refactor cleanup evidence behind DMC boundary
- Refactor worktree cleanup candidate classification
- Refactor worktree active-no-signal input routing
- Refactor JSON state serialization
- Refactor worktree metadata inventory lookup

## [0.48.8] - 2026-06-17

### Changed
- Add argv command specs to process runner

## [0.48.7] - 2026-06-17

### Changed
- Refactor shared worktree cleanup primitives
- Refactor CLI JSON rendering through shared renderer
- Fail closed on cleanup state persistence errors
- Refactor git probe helpers

## [0.48.6] - 2026-06-17

### Changed
- Refactor metadata reconciliation row building
- Refactor workspace handle parsing
- Refactor contained recursive directory removal
- isolate PR run artifact access
- centralize PR label handling

## [0.48.5] - 2026-06-17

### Changed
- Refactor abandoned worktree cleanup orchestration
- Centralize workspace writable-root policy

## [0.48.4] - 2026-06-17

### Changed
- share CLI rendering helpers
- make AGENTS workspace policy filterable
- centralize GitHub repository descriptors
- register worktree context projections

### Fixed
- require workspace handles for file abilities
- commit remote workspace files atomically
- fail closed on unverified worktree freshness
- resolve PR publication targets

## [0.48.3] - 2026-06-17

### Fixed
- verify worktree add persistence

## [0.48.2] - 2026-06-17

### Changed
- Improve worktree cleanup at scale

## [0.48.1] - 2026-06-16

### Fixed
- gate AGENTS sections on core setting

## [0.48.0] - 2026-06-16

### Added
- accept repo@branch handle in worktree remove

## [0.47.136] - 2026-06-16

### Changed
- Bound cleanup planning scans

## [0.47.135] - 2026-06-16

### Fixed
- add explicit discard for unpushed cleanup worktrees

## [0.47.134] - 2026-06-16

### Fixed
- compact bounded cleanup JSON output

## [0.47.133] - 2026-06-16

### Fixed
- repair cleanup-eligible stale worktree markers

## [0.47.132] - 2026-06-15

### Changed
- stop narrating core's datamachine namespace in DMC AGENTS.md section

## [0.47.131] - 2026-06-15

### Fixed
- guard ability category registration against double-fire _doing_it_wrong notice

## [0.47.130] - 2026-06-15

### Changed
- Add cleanup evidence summary output

### Fixed
- Fix cleanup summary lint
- Fix stale lock guidance lint
- Fix stale lock cleanup guidance

## [0.47.129] - 2026-06-15

### Fixed
- Fix cleanup until-empty blocked skip success

## [0.47.128] - 2026-06-15

### Changed
- Use neutral mounted runtime bootstrap
- Add mounted sandbox self-configuration

### Fixed
- Fix mounted runtime bootstrap lint

## [0.47.127] - 2026-06-15

### Fixed
- fall back to local workspace git status

## [0.47.126] - 2026-06-15

### Fixed
- satisfy workspace guard lint
- separate safe primary refresh from mutations

## [0.47.125] - 2026-06-15

### Fixed
- align workspace reader assignments
- prefer local workspace reads when remote state exists

## [0.47.124] - 2026-06-14

### Fixed
- add artifact cleanup until-empty

## [0.47.123] - 2026-06-14

### Changed
- remove GitSync feature

## [0.47.122] - 2026-06-14

### Fixed
- verify artifact cleanup removals

## [0.47.121] - 2026-06-14

### Fixed
- guard command introspection outside WP-CLI

## [0.47.120] - 2026-06-14

### Fixed
- satisfy cleanup lint checks
- satisfy worktree prune lint
- align cleanup scanned counters
- make cleanup worktree removal timeout resumable
- report stale marker blockers during worktree prune
- honor bulk abandoned cleanup limits

## [0.47.119] - 2026-06-14

### Fixed
- support branch pull for detached primaries

## [0.47.118] - 2026-06-13

### Fixed
- skip dotfile and non-git dirs in workspace list classifier

## [0.47.117] - 2026-06-13

### Fixed
- Fix cleanup idle wrapper convergence

## [0.47.116] - 2026-06-13

### Fixed
- Fix cleanup drain JSON run visibility

## [0.47.115] - 2026-06-13

### Changed
- Finish cleanup command lint
- Add stale worktree cleanup profile
- Improve cleanup plan summary

### Fixed
- Fix AGENTS.md section registry lint
- fix(agents-md): generate datamachine-code sections from real command tree (#671)
- Fix stale cleanup lint issues
- Fix cleanup plan lint
- Fix local workspace path precedence
- Fix cleanup planning lint
- Fix cleanup snapshot lint findings
- Fix cleanup status lint
- Fix artifact cleanup lint
- Fix cleanup snapshot lint formatting
- Fix high-volume workspace cleanup planning
- Fix cleanup pagination snapshots
- Fix stale marker artifact cleanup
- Fix cleanup parent status convergence

## [0.47.113] - 2026-06-13

### Changed
- Avoid undocumented worktree shape access
- Satisfy workspace routing static analysis

### Fixed
- Fix local primary worktree routing

## [0.47.112] - 2026-06-12

### Fixed
- Fix disk budget lint failures
- Fix worktree disk budget percentage refusal

## [0.47.111] - 2026-06-12

### Changed
- Include lifecycle candidates in active cleanup evidence

## [0.47.110] - 2026-06-12

### Changed
- Clarify cleanup chunk drain progress

### Fixed
- Fix cleanup disk-pressure recommendations

## [0.47.109] - 2026-06-12

### Changed
- Enforce workspace writable roots in mutation tools

## [0.47.108] - 2026-06-12

### Fixed
- Fix cleanup plan local worktree signals

## [0.47.107] - 2026-06-12

### Fixed
- Fix remote worktree remove for reused handles

## [0.47.106] - 2026-06-12

### Changed
- Improve cleanup blocker follow-up reports

## [0.47.105] - 2026-06-12

### Changed
- Classify metadata identity reconciliation buckets

## [0.47.104] - 2026-06-12

### Changed
- guard release tags against stale source
- Bound metadata reconciliation recommendations
- Add workspace row triage flow

### Fixed
- Fix cleanup resume limit batching
- Fix stale workspace lock triage

## [0.47.103] - 2026-06-12

### Fixed
- Fix workspace hygiene and cleanup resume
- Fix listed primary workspace fallback

## [0.47.102] - 2026-06-12

### Changed
- Add workspace remote backend hygiene diagnostics

## [0.47.101] - 2026-06-12

### Fixed
- Fix workspace worktree repo resolution

## [0.47.100] - 2026-06-12

### Fixed
- Fix cleanup planning triage

## [0.47.99] - 2026-06-11

### Fixed
- Fix disk budget for bounded workspaces

## [0.47.98] - 2026-06-11

### Changed
- Support workspace path repo handles

## [0.47.97] - 2026-06-11

### Changed
- Add runner workspace command ability

## [0.47.96] - 2026-06-11

### Changed
- Add runner workspace publication API

## [0.47.95] - 2026-06-10

### Changed
- add workspace context repositories ability

## [0.47.94] - 2026-06-10

### Fixed
- fall back when workspace clone lacks git

## [0.47.93] - 2026-06-10

### Fixed
- route workspace clone CLI through ability

## [0.47.92] - 2026-06-10

### Changed
- Add read-only context repositories

## [0.47.91] - 2026-06-10

### Fixed
- harden cleanup apply resumability

## [0.47.90] - 2026-06-09

### Fixed
- expose scoped artifact cleanup apply command

## [0.47.89] - 2026-06-09

### Fixed
- normalize mounted workspace writes

## [0.47.88] - 2026-06-08

### Changed
- Drop vestigial test_backend setting

### Fixed
- keep workspace ls sizes schema-safe

## [0.47.87] - 2026-06-08

### Fixed
- guard workspace primary freshness

## [0.47.86] - 2026-06-08

### Fixed
- handle no-git worktree cleanup

## [0.47.85] - 2026-06-08

### Fixed
- retry bundle preload through remote workspace backend

## [0.47.84] - 2026-06-08

### Changed
- Project write and git workspace tools for coding agents

## [0.47.83] - 2026-06-08

### Fixed
- use remote workspace backend without streaming git

## [0.47.82] - 2026-06-08

### Fixed
- discover worktree-backed workspace primaries

## [0.47.81] - 2026-06-08

### Fixed
- register workspace preload artifacts early

## [0.47.80] - 2026-06-07

### Fixed
- clean stale remote-backed worktrees

## [0.47.79] - 2026-06-07

### Changed
- extract workspace core utilities

### Fixed
- expose shared workspace cleanup helpers

## [0.47.78] - 2026-06-07

### Changed
- move cleanup backend helpers

## [0.47.77] - 2026-06-07

### Changed
- extract worktree cleanup engine

## [0.47.76] - 2026-06-07

### Changed
- extract active cleanup trait

### Fixed
- align active cleanup trait formatting

## [0.47.75] - 2026-06-07

### Changed
- share git cherry count parsing

## [0.47.74] - 2026-06-07

### Changed
- share active cleanup metadata envelope

## [0.47.73] - 2026-06-07

### Changed
- share active cleanup revalidation

### Fixed
- align active cleanup options

## [0.47.72] - 2026-06-07

### Fixed
- align label task assignments
- add deterministic GitHub label task

## [0.47.71] - 2026-06-07

### Changed
- share active worktree metadata apply loop

### Fixed
- satisfy active apply lint

## [0.47.70] - 2026-06-07

### Fixed
- register handlers behind trait gate

## [0.47.69] - 2026-06-07

### Fixed
- satisfy workspace lint narrowings
- narrow workspace lint cleanup
- clear workspace phpstan baseline

## [0.47.68] - 2026-06-07

### Fixed
- persist active probe cache state

## [0.47.67] - 2026-06-07

### Fixed
- cache active worktree classifier probes

## [0.47.66] - 2026-06-07

### Fixed
- compact abandoned cleanup pagination handles

## [0.47.65] - 2026-06-07

### Fixed
- compact abandoned cleanup JSON blockers

## [0.47.64] - 2026-06-07

### Fixed
- delete marked worktrees before classification

## [0.47.63] - 2026-06-07

### Fixed
- drain marked worktrees during abandoned cleanup

## [0.47.62] - 2026-06-07

### Fixed
- clean remote-tracking active worktrees

## [0.47.61] - 2026-06-07

### Fixed
- account for active cleanup pagination shifts

## [0.47.60] - 2026-06-06

### Fixed
- continue same-offset abandoned cleanup pages

## [0.47.59] - 2026-06-06

### Fixed
- resume abandoned cleanup stages

## [0.47.58] - 2026-06-06

### Fixed
- bound abandoned cleanup budget

## [0.47.57] - 2026-06-06

### Changed
- Make Data Machine optional for DMC core

## [0.47.56] - 2026-06-06

### Fixed
- drain abandoned cleanup pages

## [0.47.55] - 2026-06-06

### Changed
- Refactor worktree cleanup classification

## [0.47.54] - 2026-06-06

### Changed
- Declare abandoned cleanup passes flag

## [0.47.53] - 2026-06-06

### Fixed
- add abandoned worktree cleanup command
- opt workspace-maintenance tasks out of the agent-context gate
- route manage issue comments through wrapper

## [0.47.52] - 2026-06-06

### Changed
- centralize runtime capability probes

### Fixed
- align runtime capability lint

## [0.47.51] - 2026-06-06

### Changed
- centralize process execution plumbing

### Fixed
- align process runner lint

## [0.47.50] - 2026-06-06

### Changed
- Emit workspace executor metrics

## [0.47.49] - 2026-06-06

### Changed
- Add Agents API workspace executor adapter

### Fixed
- Fix workspace executor lint

## [0.47.48] - 2026-06-05

### Changed
- Use wrapper for GitHub add-label tool

## [0.47.47] - 2026-06-05

### Fixed
- bind GitHub publish job context

## [0.47.46] - 2026-06-05

### Changed
- Escape GitHub fetch auth errors
- Use repo-scoped GitHub auth for fetches

## [0.47.45] - 2026-06-05

### Changed
- Escape GitHub fetch error messages
- Fail GitHub fetches on API errors

## [0.47.44] - 2026-06-05

### Fixed
- fix worktree alias lint
- fix worktree base-ref alias

## [0.47.43] - 2026-06-05

### Fixed
- fix github actions token auth fallback

## [0.47.42] - 2026-06-05

### Fixed
- fix github issue publish job context

## [0.47.41] - 2026-06-04

### Fixed
- align cleanup apply assignments

## [0.47.40] - 2026-06-04

### Fixed
- bound cleanup run apply batches

## [0.47.39] - 2026-06-03

### Fixed
- retry bootstrap after plugin activation
- bootstrap after late plugin activation

## [0.47.38] - 2026-06-03

### Changed
- Add AGENTS.md section provenance metadata

## [0.47.37] - 2026-06-03

### Fixed
- Fix lint formatting
- Fix cleanup metadata identity recovery

## [0.47.36] - 2026-06-03

### Changed
- Classify broken worktree probe failures

### Fixed
- Fix lint formatting

## [0.47.35] - 2026-06-03

### Changed
- Classify artifact-only dirty worktrees

### Fixed
- Fix lint formatting

## [0.47.34] - 2026-06-03

### Fixed
- Fix lint formatting
- make no-merge cleanup rows actionable

## [0.47.33] - 2026-06-03

### Changed
- Use ability-native projections for DMC tools

## [0.47.32] - 2026-06-03

### Fixed
- filter workspace list by checkout type

## [0.47.31] - 2026-06-03

### Changed
- Preserve active cleanup scan budgets

## [0.47.30] - 2026-06-03

### Changed
- Preserve active/no-signal apply pagination

## [0.47.29] - 2026-06-03

### Changed
- cover workspace tool transcript payloads

## [0.47.28] - 2026-06-03

### Fixed
- align worktree guard assignments
- refuse worktrees behind default branch

## [0.47.27] - 2026-06-02

### Fixed
- remove unused escalation policy parameter
- remove Homeboy runtime integration
- remove Homeboy AGENTS guidance

## [0.47.26] - 2026-06-02

### Fixed
- keep active cleanup apply pagination symmetric

## [0.47.25] - 2026-06-02

### Changed
- Guard clean cleanup branch identity

## [0.47.24] - 2026-06-02

### Changed
- Classify clean active worktree equivalence

## [0.47.23] - 2026-06-02

### Fixed
- Fix active cleanup branch identity

## [0.47.22] - 2026-06-01

### Changed
- Auto-finalize active worktrees merged to default
- Remove generic CLI channel runtime

## [0.47.21] - 2026-06-02

### Changed
- Separate remote workspace backend guidance
- Extract AGENTS.md section registration
- Add worktree origin session backfill
- Add GitHub credential profile migration

## [0.47.20] - 2026-06-02

### Changed
- Prefilter metadata reconciliation candidates
- Support generic fan-out env projection

### Fixed
- Fix DMC ability routing contracts
- summarize cleanup remaining work
- reject zero cleanup report limits
- accept branch slugs in cleanup plan revalidation

## [0.47.19] - 2026-06-01

### Fixed
- Fix workspace alias compatibility coverage

## [0.47.18] - 2026-06-01

### Changed
- Normalize mounted workspace paths in workspace tools

## [0.47.17] - 2026-06-01

### Fixed
- Fix workspace lock lint findings
- Fix workspace mutation lock recovery evidence

## [0.47.16] - 2026-06-01

### Fixed
- Fix Studio worktree context projection crash

## [0.47.15] - 2026-05-31

### Fixed
- guard repeated issue list calls

## [0.47.14] - 2026-06-01

### Fixed
- select issue read credentials

## [0.47.13] - 2026-05-31

### Fixed
- skip actor lookup for fresh issue comments

## [0.47.12] - 2026-05-31

### Fixed
- use write credentials for issue mutations

## [0.47.11] - 2026-05-31

### Fixed
- select GitHub credentials by operation

## [0.47.10] - 2026-05-31

### Fixed
- include issue comments in GitHub fetch content

## [0.47.9] - 2026-05-31

### Fixed
- register bundle artifact hooks early

## [0.47.8] - 2026-05-31

### Changed
- route lock owner-context runtime IDs through signatures registry

### Fixed
- accept worktree base alias

## [0.47.7] - 2026-05-30

### Fixed
- include lock owner diagnostics on busy workspace errors

## [0.47.6] - 2026-05-30

### Fixed
- deduplicate workspace hygiene lock counts

## [0.47.5] - 2026-05-30

### Changed
- remove vendor brand names from generic-layer description and docblock

## [0.47.4] - 2026-05-29

### Fixed
- align remote workspace alias assignments

## [0.47.3] - 2026-05-29

### Fixed
- resolve aliases in remote workspace backend

## [0.47.2] - 2026-05-29

### Fixed
- fix cleanup run cast spacing
- fix cleanup run scheduling context

## [0.47.1] - 2026-05-28

### Changed
- Revert "Merge pull request #459 from Extra-Chill/fix/workspace-tools-sandbox-mode"

## [0.47.0] - 2026-05-28

### Added
- expose workspace files to source inventory

### Fixed
- satisfy context injector alignment lint
- align generated context formatting
- update generated agent context guidance
- mark source inventory callback input used
- align workspace source inventory lint
- allow overriding remote workspace backend
- expose workspace tools in sandbox mode

## [0.46.18] - 2026-05-28

### Changed
- Prevent repeated workspace git status loops

## [0.46.17] - 2026-05-28

### Changed
- Prevent repeated workspace show loops

## [0.46.16] - 2026-05-27

### Fixed
- align gitsync proposal lint
- avoid nested gitsync proposal refs
- support keyed gitsync proposal branches

## [0.46.15] - 2026-05-26

### Changed
- enable homeboy lint autofix

## [0.46.14] - 2026-05-26

### Fixed
- resolve GitSync wp-content paths via WP_CONTENT_DIR

## [0.46.13] - 2026-05-26

### Fixed
- avoid filesystem null reads

## [0.46.12] - 2026-05-24

### Changed
- Internal improvements

## [0.46.11] - 2026-05-24

### Fixed
- control active cleanup child jobs

## [0.46.10] - 2026-05-21

### Fixed
- avoid virtual AGENTS symlinks in worktrees

## [0.46.9] - 2026-05-20

### Fixed
- support token-authenticated workspace clones

## [0.46.8] - 2026-05-20

### Changed
- Preserve local PR cleanup results

## [0.46.7] - 2026-05-20

### Fixed
- register code abilities during api hook replay

## [0.46.6] - 2026-05-20

### Fixed
- defer code ability registration until api loads

## [0.46.5] - 2026-05-20

### Fixed
- register code abilities after init

## [0.46.4] - 2026-05-19

### Changed
- Bound artifact cleanup apply scheduling

### Fixed
- aggregate cleanup chunk evidence from job data

## [0.46.3] - 2026-05-19

### Fixed
- quiet cleanup status child output

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
