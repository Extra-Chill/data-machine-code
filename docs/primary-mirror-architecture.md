# Primary Mirror Architecture

## Problem

DMC primary checkouts currently serve three roles at once: default-branch reference, mutable working tree, and base for creating task worktrees. That makes stale reads and accidental default-branch mutations likely.

## Target Model

- A primary mirror is DMC-owned reference state for a remote default branch.
- Agents do feature, release, and repair work only in managed `repo@slug` worktrees.
- Mirror refresh is a narrow operation: fetch and fast-forward to the tracked remote ref.
- Dangerous operations on mirrors are blocked by default and require explicit operator approval.
- Read surfaces fail closed when a mirror is stale, diverged, detached, or otherwise unsafe unless the caller opts in with stale-read metadata.

## Migration Plan

1. Keep existing primary directories as the compatibility mirror path.
2. Enforce tactical safety first: separate primary refresh from dangerous primary mutation, and guard stale primary reads.
3. Add mirror metadata to each primary row: remote URL, tracked ref, last refresh result, freshness state, and whether local commits/dirty state block automated conversion.
4. For clean/current primaries, mark them `mirror_compatible` and allow DMC to refresh them as mirrors.
5. For dirty/diverged primaries, keep them read-protected and report a non-destructive remediation plan: preserve local commits in a worktree, push/open PR if needed, or archive before mirror conversion.
6. After migration, create release/default-branch work via explicit managed worktrees, not the mirror.

## Command Shape

- Investigation: `workspace show <repo>` then `workspace read <repo>@<slug> ...` or `workspace read <repo> ... --allow-stale-primary` when intentionally inspecting stale local state.
- Refresh: `workspace git pull <repo> --allow-primary-refresh`.
- Feature work: `workspace worktree add <repo> <branch> --from=origin/<base>`.
- Dangerous primary repair: `workspace git <commit|push|reset|rebase> <repo> --allow-dangerous-primary-mutation` with operator approval.

## Open Decisions

- Whether mirrors should become bare repositories or remain non-bare worktrees with stricter DMC-owned metadata.
- Whether stale primary reads should stay fail-closed permanently or return warnings for privileged UI callers.
- How long to keep compatibility for `--allow-primary-mutation` as a pull alias.
- Whether release checkouts need a distinct lifecycle state separate from normal task worktrees.
