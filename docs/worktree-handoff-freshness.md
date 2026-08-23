# Worktree Handoff Freshness

Every successful `worktree add` result has `handoff_freshness`. Its contract
is either `{ status: "verified", proof: <metadata-bound proof> }` or
`{ status: "unverified", reason: <typed reason> }`. Consumers use only this
object: they call `worktree handoff-revalidate` with the verified proof
immediately before admission, then converge on `current` or refuse on drift,
fetch failure, contention, or timeout. An unverified result is refused unless
the caller explicitly set `allow_unverified_freshness=true`; that opt-in is
intended for offline work and the GitHub API backend, which cannot issue a
local Git freshness proof (`remote_freshness_probe_unsupported`).

`current` is a bounded observation, not an atomic cross-process lease held
across external admission. The proof and revalidation endpoint intentionally
do not invent that lease; the broader admission contract remains tracked by
issue #1117.

The revalidation five-second deadline is a hard bound only for the Git remote
probe after the repository lock is acquired and fresh metadata has been read:
`fetch origin`, remote-default resolution, and commit probes share that
deadline. Lock acquisition has its own five-second wait. Fresh metadata uses
the option and inventory/DB repositories, whose APIs expose no query timeout,
so it is deliberately outside the remote-probe deadline. This endpoint makes
no hard whole-call wall-clock claim.
