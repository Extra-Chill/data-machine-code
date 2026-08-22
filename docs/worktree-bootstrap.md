# Worktree Bootstrap

DMC bootstraps the worktree root and ordinary one-level monorepo dependency
roots detected from lockfiles. Git submodule roots are excluded by default:
they are independent repositories with their own dependency lifecycle.

To deliberately let DMC bootstrap a submodule dependency root, commit this
superproject-owned contract:

```json
{
  "submodule_dependency_roots": ["vendor/example package"]
}
```

Save it as `.datamachine/worktree-bootstrap.json`. Entries are relative paths
and only take effect when the path is also declared in `.gitmodules`. Bootstrap
evidence reports every package-shaped submodule root skipped by the default
boundary policy in `skipped_package_roots`.

Creation records separate `provisioning.create` from `provisioning.bootstrap`.
`workspace worktree get <handle>` and `workspace show <handle>` expose a
readiness result. A pending, running, or failed bootstrap is `incomplete` and
includes the exact `resume_command`; rerun it to resume the matching managed
worktree. Records created before provisioning evidence existed remain ready
with reason `legacy_metadata_without_bootstrap_evidence`.
# Creator-owned disposable worktrees

`worktree add` optionally accepts `--purpose`, `--owner-run-ref`, and `--cleanup-policy=manual|remove_on_success|preserve_on_failure`. A creator using `remove_on_success` owns terminal finalization: after its run succeeds and the checkout is clean and pushed, it must run `worktree finalize <handle> --owner-terminal-outcome=success`. This records an explicit cleanup handoff; it does not bypass Git, containment, or liveness safety checks. Other policies remain operator-managed.
