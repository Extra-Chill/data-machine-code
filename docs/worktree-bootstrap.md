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
