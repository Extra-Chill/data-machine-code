# Manual Testing — Workspace Worktrees

End-to-end test plan for the worktree-native workspace. Pure unit-style coverage
of handle parsing and slugification lives in `smoke-worktree-handles.php`.
Integration coverage for the cleanup-merged flow (real git repos in a tmpdir,
including safety-rail regressions) lives in `smoke-worktree-cleanup.php`.

## 0. Pre-flight — the automated smoke tests

```bash
php tests/smoke-worktree-handles.php     # pure-unit, fast
php tests/smoke-worktree-cleanup.php     # spawns a real git workspace
php tests/smoke-worktree-bootstrap.php   # fixture + real git, no WP required
```

Expected: `32/32 passed`, `18/18 passed`, and `30/30 passed` respectively.
Skip `smoke-worktree-cleanup.php` and `smoke-worktree-bootstrap.php` if `git`
is unavailable.

Prereqs:
- WordPress 6.9+ with Data Machine + data-machine-code activated.
- A writable `~/.datamachine/workspace/` (or `DATAMACHINE_WORKSPACE_PATH`).
- `git` available on `$PATH`.

## 1. Pure unit smoke (no WP required)

```bash
php tests/smoke-worktree-handles.php
```

Expected: `32/32 passed`.

## 2. Clone a primary checkout

```bash
wp datamachine-code workspace clone https://github.com/Extra-Chill/data-machine-code.git
wp datamachine-code workspace list
```

Expected: list shows `data-machine-code` with `git: true`, `is_worktree: false`.

Negative case — names with `@` are reserved:

```bash
wp datamachine-code workspace clone https://example.com/r.git --name=foo@bar
```

Expected: `invalid_clone_name` error.

## 3. Create a worktree

```bash
wp datamachine-code workspace worktree add data-machine-code feat/test-worktree
wp datamachine-code workspace worktree list
ls ~/.datamachine/workspace/
```

Expected:
- New dir `~/.datamachine/workspace/data-machine-code@feat-test-worktree/`.
- `worktree list` shows two entries (primary + worktree) with branch info.
- `data-machine-code@feat-test-worktree/.git` is a **file** (worktree pointer).

Idempotency check — re-running `worktree add` with the same branch fails cleanly:

```bash
wp datamachine-code workspace worktree add data-machine-code feat/test-worktree
```

Expected: `worktree_exists` error.

### 3a. Bootstrap-on-by-default and the `--skip-bootstrap` escape hatch

`worktree add` runs the bootstrap pass by default:

```bash
wp datamachine-code workspace worktree add data-machine-code feat/bootstrap-smoke
ls ~/.datamachine/workspace/data-machine-code@feat-bootstrap-smoke/vendor/composer/
```

Expected:
- Output includes a `Bootstrap: ok` block listing each step (submodules
  skipped, packages skipped or ran depending on lockfile presence,
  composer ran).
- `vendor/composer/` exists inside the worktree (composer install ran).
- For a repo with `package-lock.json` the bootstrap block shows `packages
  ran: npm ci` (pnpm/bun/yarn equivalents for their lockfiles) and
  `node_modules/` is populated.
- Re-running `worktree add` still errors with `worktree_exists` before
  bootstrap runs — a failed bootstrap never half-creates worktrees.

Escape hatch — skip the bootstrap pass when you only need to read code:

```bash
wp datamachine-code workspace worktree add data-machine-code feat/readonly-smoke --skip-bootstrap
ls ~/.datamachine/workspace/data-machine-code@feat-readonly-smoke/vendor 2>&1
```

Expected:
- No `Bootstrap: …` block in the output.
- `vendor/` and `node_modules/` do not exist in the worktree.

Negative case — a step with a missing tool downgrades to skipped rather
than failing (simulate by temporarily moving `composer` off PATH; skip the
submodule step by adding a repo without `.gitmodules`).

## 4. Operate on the worktree handle

```bash
wp datamachine-code workspace git status data-machine-code@feat-test-worktree
wp datamachine-code workspace ls data-machine-code@feat-test-worktree inc/
wp datamachine-code workspace read data-machine-code@feat-test-worktree README.md --limit=20
```

Expected: each command targets the worktree's directory; status reports
`is_worktree: true`, `repo: data-machine-code`.

## 5. Primary mutation guard

Make sure mutating the primary is blocked by default:

```bash
wp datamachine-code workspace git pull data-machine-code
```

Expected: `primary_mutation_blocked` error with the suggested override.

Override works:

```bash
wp datamachine-code workspace git pull data-machine-code --allow-primary-mutation
```

Expected: pull runs (or reports clean / dirty state). The primary checkout
should still be on its original branch — pull is fast-forward only.

## 6. Edit, commit, push from the worktree

Set up a per-repo policy if needed (see `datamachine_workspace_git_policies`
option). For this smoke pass, set `write_enabled` and `push_enabled` for
`data-machine-code` and add `tests/` to `allowed_paths`.

```bash
wp datamachine-code workspace write data-machine-code@feat-test-worktree tests/SMOKE.txt --content="hello"
wp datamachine-code workspace git status data-machine-code@feat-test-worktree
wp datamachine-code workspace git add data-machine-code@feat-test-worktree --rel=tests/SMOKE.txt
wp datamachine-code workspace git commit data-machine-code@feat-test-worktree "test: smoke worktree commit"
wp datamachine-code workspace git log data-machine-code@feat-test-worktree --limit=3
```

Expected: commit lands on the worktree's branch. Primary's branch is unchanged.
Verify:

```bash
git -C ~/.datamachine/workspace/data-machine-code log --oneline -3
git -C ~/.datamachine/workspace/data-machine-code@feat-test-worktree log --oneline -3
```

The worktree shows the new commit; the primary does not.

## 7. Remove the worktree

Dirty case — should refuse without `--force`:

```bash
wp datamachine-code workspace write data-machine-code@feat-test-worktree tests/DIRTY.txt --content="x"
wp datamachine-code workspace worktree remove data-machine-code feat/test-worktree
```

Expected: error from `git worktree remove` because the worktree is dirty.

Force removal:

```bash
wp datamachine-code workspace worktree remove data-machine-code feat/test-worktree --force
ls ~/.datamachine/workspace/
```

Expected: directory gone; `worktree list` no longer shows it.

## 8. Cleanup

```bash
wp datamachine-code workspace worktree prune
wp datamachine-code workspace remove data-machine-code --yes
```

Expected: prune reports the primary it inspected; remove deletes the primary
(only after worktrees are gone — try removing while a worktree exists to confirm
the `has_worktrees` guard).
