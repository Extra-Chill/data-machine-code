# GitSync

GitSync binds a **site-owned local directory** under `ABSPATH` to a **remote git repository**, then keeps the two in lockstep via pull (Phase 1), push (Phase 2), and scheduled sync (Phase 3).

It is the layer *above* the existing [`Workspace`](../inc/Workspace/Workspace.php) system:

| System       | Owns                                 | Lives under                           | Typical user                  |
|--------------|--------------------------------------|---------------------------------------|-------------------------------|
| `Workspace`  | Agent-owned code checkouts           | `~/.datamachine/workspace/<repo>`     | The coding agent itself       |
| `GitSync`    | Site-owned subtrees (content, data)  | `ABSPATH/<path>` of the site's choice | Plugins (Intelligence, etc.)  |

The two systems share low-level primitives — `Support\GitRunner` for shelling out to git, `Support\PathSecurity` for containment and sensitive-file checks — but their scopes stay separate. Workspace knows about `<repo>@<branch-slug>` handles and worktrees; GitSync knows about user-named bindings pinned to a single branch.

First consumers (filed, not yet wired):

- [Automattic/intelligence#31](https://github.com/Automattic/intelligence/issues/31) — Git-synced wiki content subtrees (WooCommerce wiki, Jetpack wiki, etc.).
- [Automattic/intelligence#125](https://github.com/Automattic/intelligence/issues/125) — Git-tracked wiki-generator agent definitions.

Both map cleanly to one GitSync binding per synced subtree.

## Binding shape

Bindings are stored as an associative array in the `datamachine_gitsync_bindings` WordPress option:

```php
array(
    'slug'        => 'intelligence-wiki',                       // unique identifier
    'local_path'  => '/wp-content/uploads/markdown/wiki/',       // ABSPATH-relative, leading slash
    'remote_url'  => 'https://github.com/Automattic/a8c-wiki-woocommerce',
    'branch'      => 'main',                                     // pinned branch
    'policy'      => array(
        'auto_pull'     => false,                                // Phase 3 honors this
        'pull_interval' => 'hourly',                             // reuses DM SchedulerIntervals
        'write_enabled' => false,                                // Phase 2 honors this
        'push_enabled'  => false,                                // Phase 2 honors this
        'allowed_paths' => array(),                              // Phase 2 write containment
        'conflict'      => 'fail',                               // fail | upstream_wins | manual
    ),
    'created_at'  => '2026-04-20T12:00:00+00:00',
    'last_pulled' => '2026-04-20T12:15:00+00:00',
    'last_commit' => 'abc1234',
)
```

Defaults are deliberately conservative: no writes, no push, no auto-pull, `conflict = fail`. Every destructive behavior must be explicitly enabled per binding.

## CLI (Phase 1)

```bash
# List bindings
wp datamachine-code gitsync list

# Bind + clone (or adopt an existing matching checkout)
wp datamachine-code gitsync bind intelligence-wiki \
  --local=/wp-content/uploads/markdown/wiki/ \
  --remote=https://github.com/Automattic/a8c-wiki-woocommerce \
  --branch=main

# Status for a single binding
wp datamachine-code gitsync status intelligence-wiki
wp datamachine-code gitsync status intelligence-wiki --format=json

# Pull latest (fast-forward, conflict policy applies)
wp datamachine-code gitsync pull intelligence-wiki
wp datamachine-code gitsync pull intelligence-wiki --allow-dirty

# Unbind (keeps the directory by default)
wp datamachine-code gitsync unbind intelligence-wiki
wp datamachine-code gitsync unbind intelligence-wiki --purge --yes
```

## Abilities

Every CLI subcommand is a thin shell over the matching WordPress ability. Consumers should call the ability, not the service class.

| Ability                           | Category                    | REST? | Purpose                                                     |
|-----------------------------------|-----------------------------|-------|-------------------------------------------------------------|
| `datamachine/gitsync-list`        | `datamachine-code-gitsync`  | yes   | List all bindings + lightweight status.                    |
| `datamachine/gitsync-status`      | `datamachine-code-gitsync`  | yes   | Detailed status for one binding (branch/HEAD/dirty/ahead/behind). |
| `datamachine/gitsync-bind`        | `datamachine-code-gitsync`  | no    | Register a binding and clone or adopt the working tree.    |
| `datamachine/gitsync-unbind`      | `datamachine-code-gitsync`  | no    | Remove a binding; optional `--purge` deletes the dir.      |
| `datamachine/gitsync-pull`        | `datamachine-code-gitsync`  | no    | Fast-forward pull, honoring conflict policy.               |

Mutating abilities are CLI-only (`show_in_rest = false`) in Phase 1 — they change filesystem state and should stay behind explicit operator action.

```php
$ability = wp_get_ability( 'datamachine/gitsync-pull' );
$result  = $ability->execute( array(
    'slug'        => 'intelligence-wiki',
    'allow_dirty' => false,
) );
```

## `bind` semantics

When you call `bind`, GitSync inspects the target path and chooses one of four paths:

| Path state                             | Action                                                        |
|----------------------------------------|---------------------------------------------------------------|
| Doesn't exist                          | `git clone --branch <branch> <remote> <path>`                |
| Exists, empty                          | `git clone` into it                                          |
| Exists, has `.git/` with matching origin | **Adopt** — register the binding, no clone                  |
| Exists, has `.git/` but origin mismatch | Error (`origin_mismatch`, HTTP 409)                          |
| Exists, non-empty, no `.git/`          | Error (`dirty_target`, HTTP 409) — refuses to overlay        |

Adopt semantics mean `bind` is **idempotent**: running it twice against the same path with the same remote is safe. This is important when bindings are provisioned declaratively (e.g. by a plugin on activation).

## `pull` semantics

- Pull is **fast-forward only** (`git pull --ff-only origin <branch>`).
- If the working tree's current branch drifted from the binding's pinned branch, pull **refuses** rather than silently switching.
- The binding's `conflict` policy drives dirty-tree behavior:

| Policy          | Dirty tree behavior                                                        |
|-----------------|----------------------------------------------------------------------------|
| `fail` (default) | Refuse pull with `dirty_working_tree` (HTTP 400).                         |
| `upstream_wins` | `git reset --hard HEAD` before pull, discarding local changes.            |
| `manual`        | Attempt the pull; git surfaces the conflict and the admin resolves it.    |

`--allow-dirty` on the CLI overrides `fail` for a single invocation without changing the policy.

## Security posture

Containment is enforced at every mutation point:

- `local_path` must be ABSPATH-relative (leading slash, no `..`, no `.`).
- Sensitive filename fragments (`.env`, `id_rsa`, `.pem`, `.key`, `credentials.json`, `secrets`) are refused outright.
- `purge` re-validates containment via `realpath()` immediately before `rm -rf`, defending against symlink escapes.
- All abilities require `PermissionHelper::can_manage()`.

Shared with the Workspace system via `DataMachineCode\Support\PathSecurity` — the block list and traversal detection have one canonical source of truth.

## Write path (Phase 2)

Five more abilities/CLI commands let a binding propose changes upstream.

| Ability | CLI | Purpose |
|---|---|---|
| `datamachine/gitsync-add` | `gitsync add <slug> <paths>…` | Stage paths. Enforces `allowed_paths` + sensitive-file filter. |
| `datamachine/gitsync-commit` | `gitsync commit <slug> --message=…` | Commit staged changes. 8–200 char message. |
| `datamachine/gitsync-push` | `gitsync push <slug> [--force]` | Direct push to pinned branch. Two-key auth. |
| `datamachine/gitsync-submit` | `gitsync submit <slug> --message=…` | Blessed PR flow on the sticky proposal branch. |
| `datamachine/gitsync-policy-update` | `gitsync policy <slug> --flag=…` | Modify policy fields on an existing binding. |

All mutating, all CLI-only (`show_in_rest = false`), all gated by `PermissionHelper::can_manage()`.

### Policy gates

Every write passes through policy checkpoints:

| Policy | Gates |
|---|---|
| `write_enabled` (default: false) | `add` + `commit` |
| `push_enabled` (default: false) | `push` + `submit` |
| `safe_direct_push` (default: false) | `push` (direct push to pinned branch). Second key — `push_enabled` alone isn't enough. |
| `allowed_paths` (default: `[]`) | Every staged path must sit under one of these roots. Empty allowlist = nothing stageable. |

`safe_direct_push=true` requires `push_enabled=true` — the policy-update validator refuses the orphan combination.

Typical progression for a wiki-content binding:

```bash
# 1. Bind read-only (Phase 1 default)
wp datamachine-code gitsync bind wiki \
  --local=/wp-content/uploads/markdown/wiki/ \
  --remote=https://github.com/Automattic/a8c-wiki-woocommerce

# 2. Open writes + submit, restricted to the articles subtree
wp datamachine-code gitsync policy wiki \
  --write-enabled=true --push-enabled=true \
  --allowed-paths=articles/,images/

# 3. Stage + commit + propose via PR
wp datamachine-code gitsync submit wiki \
  --message="Add CIAB kickoff article"
```

Personal-wiki (single-owner) bindings can flip the third key for direct push:

```bash
wp datamachine-code gitsync policy personal-wiki \
  --push-enabled=true --safe-direct-push=true \
  --allowed-paths=notes/,daily/
```

### `submit` — the sticky proposal branch

Each binding gets exactly one feature branch on the remote: `gitsync/<slug>`. Every submit rewrites it from upstream + user's edits and opens (or updates) a single PR.

Algorithm:

1. `git fetch origin --prune` — refresh upstream refs.
2. Stash any dirty/untracked files on the pinned branch.
3. `git reset --hard origin/<pinned>` — align local pinned branch with upstream.
4. `git checkout -B gitsync/<slug>` — create/reset the feature branch.
5. `git stash pop` — restore edits on the feature branch.
6. Stage (explicit `--paths=` list, or every dirty file under `allowed_paths`).
7. `git commit`.
8. `git push --force origin gitsync/<slug>` — we own this branch exclusively.
9. Open/update PR via GitHubAbilities (using existing PAT).
10. `git checkout <pinned>` — leave the working tree clean.

Any step can fail and the `finally`-shaped cleanup always tries to return you to the pinned branch. A failed `stash pop` leaves the stash in place and logs a warning so your edits are recoverable via `git stash list`.

Phase 2 supports `github.com` remotes only for `submit` — the PR backend is DMC's `GitHubAbilities`. Non-GitHub remotes error with `unsupported_remote`; pluggable backends (GitLab MR, Gitea) are Phase 3+.

### Direct push — why two keys?

`push_enabled` alone doesn't authorize direct push to the pinned branch. `safe_direct_push` must also be true. Rationale: bindings model a *read-synchronized mirror that tracks upstream*. The default posture says "changes go upstream via PR review" (`submit`), and anyone wanting to bypass that flow has to flip two explicit keys. Half-opting-in (write_enabled + push_enabled + push to feature branch via submit) stays safe by default; full direct-push to the tracked branch requires deliberate intent.

### Auth

- **github.com remotes:** the existing GitHubAbilities PAT is injected into the push URL as `https://<token>@github.com/...`. Standard pattern, matches DMC's other git-writing code.
- **Other https remotes:** fall back to the system's `credential.helper`. Works out of the box on macOS/Linux; may need config in Studio WASM.
- **SSH / non-GitHub PRs:** Phase 3+.

## What's next

**Phase 3 — scheduled sync:**

- `GitSyncPullTask extends SystemTask` (mirror of `WorktreeCleanupTask`).
- Registered via `datamachine_tasks` + `datamachine_recurring_schedules` filters.
- Honors each binding's `auto_pull` + `pull_interval` policy.
- Opt-in via a PluginSetting gate.

See [data-machine-code#38](https://github.com/Extra-Chill/data-machine-code/issues/38) for the full design + acceptance checklist.
