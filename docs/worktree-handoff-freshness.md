# Worktree Handoff Freshness

`worktree add` may return `handoff_freshness_proof`. A consumer calls
`worktree handoff-revalidate` immediately before it admits work and either
converges on `current` or refuses on drift, fetch failure, contention, or
timeout.

`current` is a bounded observation, not an atomic cross-process lease held
across external admission. The proof and revalidation endpoint intentionally
do not invent that lease; the broader admission contract remains tracked by
issue #1117.
