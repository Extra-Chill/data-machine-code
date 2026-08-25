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

Capacity-remediation dry-run responses allocate nothing and return
`{ status: "not_applicable", reason: "non_allocation_dry_run" }`.

If allocation, metadata, context, and bootstrap commit but final proof issuance
times out, add still fails closed and returns `partial_success: true`,
`mutation_committed: true`, and
`mutation_boundary: "worktree_allocation_committed"`. Callers must not repeat
add or bootstrap. They execute the returned `continuation` exactly: `worktree
handoff-resume` validates the server-issued allocation identity, unchanged clean
branch and HEAD, and bootstrap readiness before a no-fetch remote advertisement
observation. It does not allocate, plan capacity, inject context, bootstrap, or
persist lifecycle metadata, so it is safe to repeat. Any identity, checkout,
remote, contention, or deadline mismatch remains a handoff refusal.

`current` is a bounded observation, not an atomic cross-process lease held
across external admission. The proof and revalidation endpoint intentionally
do not invent that lease; the broader admission contract remains tracked by
issue #1117.

The revalidation five-second deadline begins before repository lock acquisition
and fresh metadata lookup. Lock wait, `fetch origin`, remote-default resolution,
and commit probes share that deadline. Metadata storage APIs expose no query
timeout, so an overdue lookup is refused before another Git operation starts;
the endpoint does not claim it can interrupt a blocked storage call.
