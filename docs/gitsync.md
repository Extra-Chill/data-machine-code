# GitSync

GitSync binds a **site-owned directory** under `ABSPATH` to a **GitHub repository**, keeping them in lockstep via pull/submit/push — all through the GitHub Contents + Git Data APIs. **No git binary, no `.git/` directory, no shell.** Works identically on self-hosted VPS, WordPress.com Business, WP Engine, Pantheon, and local dev.

It is parallel to the [`Workspace`](../inc/Workspace/Workspace.php) system:

| System       | Owns                                | Storage                               | Transport          | Typical user                  |
|--------------|-------------------------------------|---------------------------------------|--------------------|-------------------------------|
| `Workspace`  | Agent-owned code checkouts          | `~/.datamachine/workspace/<repo>`     | Shell git + binary | Coding agent                  |
| `GitSync`    | Site-owned subtrees (content, data) | `ABSPATH/<path>` of the site's choice | GitHub REST API    | Plugins (Intelligence, etc.)  |

The two systems share low-level helpers (`Support/PathSecurity`, `Support/GitHubRemote`) but don't share implementations. Workspace needs shell git for `git checkout`, `git rebase`, multi-file staging, local branching. GitSync doesn't — content sync is a per-file operation that maps cleanly to `PUT /repos/:slug/contents/:path`.

Consumers (filed, not yet wired):

- [Automattic/intelligence#31](https://github.com/Automattic/intelligence/issues/31) — Git-synced wiki content subtrees (WooCommerce wiki, Jetpack wiki, etc.).
- [Automattic/intelligence#125](https://github.com/Automattic/intelligence/issues/125) — Git-tracked wiki-generator agent definitions.

Both map cleanly to one GitSync binding per synced subtree.

## Why API-first

Earlier iterations of this primitive shelled out to `git`. That ruled out every managed host (exec disabled, no git binary) and added ~2000 lines of stash/branch/rebase orchestration. The API-first rebuild:

- Works anywhere `wp_remote_request` works.
- No local `.git/` state means pulls are idempotent reads + writes; no stash conflicts.
- Each file upload is one atomic commit via `createOrUpdateFile` — the feature-branch push dance collapses to a sequence of PUT requests.
- Reuses `GitHubAbilities::apiGet` / `apiRequest` so auth, headers, and error envelopes match the rest of DMC's GitHub surface.

Trade-offs: GitHub-only for now (no GitLab/Gitea yet), per-file commits rather than one atomic multi-file commit. Both are addressable later without reshaping the surface.

## Binding shape

Bindings are stored in the `datamachine_gitsync_bindings` option:

```php
array(
    'slug'         => 'intelligence-wiki',
    'local_path'   => '/wp-content/uploads/markdown/wiki/',  // ABSPATH-relative
    'remote_url'   => 'https://github.com/Automattic/a8c-wiki-woocommerce',
    'branch'       => 'main',
    'policy'       => array(
        'write_enabled'    => false,                         // gates submit + push
        'safe_direct_push' => false,                         // second key for push
        'allowed_paths'    => array(),                       // staging allowlist
        'conflict'         => 'fail',                        // fail | upstream_wins | manual
        'auto_pull'        => false,                         // future-scheduler hint
        'pull_interval'    => 'hourly',                      // future-scheduler hint
    ),
    'created_at'   => '2026-04-20T12:00:00+00:00',
    'last_pulled'  => '2026-04-20T12:15:00+00:00',
    'last_commit'  => 'abc1234',
    'pulled_paths' => array( 'articles/a.md', 'articles/b.md' ),
)
```

`pulled_paths` tracks which files this binding materialized so pulls can detect upstream deletions (files in the list that vanish from the next tree get removed from disk). Files *not* in the list are treated as consumer-owned and left alone.

## CLI

```bash
# List bindings
wp datamachine-code gitsync list

# Bind (registry only — doesn't touch disk)
wp datamachine-code gitsync bind intelligence-wiki \
  --local=/wp-content/uploads/markdown/wiki/ \
  --remote=https://github.com/Automattic/a8c-wiki-woocommerce

# First pull materializes files on disk
wp datamachine-code gitsync pull intelligence-wiki

# Status snapshot
wp datamachine-code gitsync status intelligence-wiki
wp datamachine-code gitsync status intelligence-wiki --format=json

# Open writes + submit via PR (PR flow)
wp datamachine-code gitsync policy intelligence-wiki \
  --write-enabled=true --allowed-paths=articles/,images/
wp datamachine-code gitsync submit intelligence-wiki \
  --message="Add CIAB kickoff article"

# Direct push (personal-wiki case — two-key auth)
wp datamachine-code gitsync policy personal-wiki \
  --write-enabled=true --safe-direct-push=true \
  --allowed-paths=notes/
wp datamachine-code gitsync push personal-wiki --message="Daily notes"

# Unbind (directory preserved)
wp datamachine-code gitsync unbind intelligence-wiki
# Unbind + delete directory
wp datamachine-code gitsync unbind intelligence-wiki --purge --yes
```

## Abilities

Every CLI subcommand is a thin shell over the matching ability. Consumers should call abilities directly, not the service class.

| Ability                              | Category                    | REST? | Purpose                                                     |
|--------------------------------------|-----------------------------|-------|-------------------------------------------------------------|
| `datamachine/gitsync-list`           | `datamachine-code-gitsync`  | yes   | List all bindings.                                         |
| `datamachine/gitsync-status`         | `datamachine-code-gitsync`  | yes   | Detailed status for one binding.                           |
| `datamachine/gitsync-bind`           | `datamachine-code-gitsync`  | no    | Register a binding. Doesn't touch disk.                    |
| `datamachine/gitsync-unbind`         | `datamachine-code-gitsync`  | no    | Remove a binding; optional `--purge` deletes the dir.      |
| `datamachine/gitsync-pull`           | `datamachine-code-gitsync`  | no    | Download upstream files via Contents API.                  |
| `datamachine/gitsync-submit`         | `datamachine-code-gitsync`  | no    | Upload changed files to `gitsync/<slug>`, open or update PR. |
| `datamachine/gitsync-push`           | `datamachine-code-gitsync`  | no    | Direct commits to pinned branch. Two-key auth.             |
| `datamachine/gitsync-policy-update`  | `datamachine-code-gitsync`  | no    | Modify policy fields on an existing binding.               |

## Pull semantics

`pull` executes:

1. `GET /git/trees/:branch?recursive=1` — list every blob on the pinned branch.
2. For each blob: compute the git-blob SHA (`sha1("blob " + len + "\0" + content)`) of the local file, compare to the tree entry's SHA. Match → skip. Mismatch → `GET /contents/:path` and write.
3. For each path in the binding's `pulled_paths` that's *not* in the tree: delete it locally. Paths never pulled are untouched.

The binding's `conflict` policy handles the case where a tracked file was edited locally *and* upstream changed:

| Policy          | Behavior                                                   |
|-----------------|------------------------------------------------------------|
| `fail` (default) | Abort with conflict list. Local edits preserved.          |
| `upstream_wins` | Overwrite local files with upstream content.              |
| `manual`        | Skip conflicting files; surface them in the result.       |

`--allow-dirty` on the CLI overrides `fail` for a single call without changing policy.

Large files (>1MB, where the Contents API truncates the response) are transparently refetched via `GET /git/blobs/:sha` — the fallback path most GitHub clients implement.

## Submit semantics — the sticky proposal branch

Each binding gets exactly one feature branch on the remote: `gitsync/<slug>`. Every submit rewrites it from fresh upstream + user's edits and opens (or updates) a single PR.

Algorithm:

1. `GET /git/ref/heads/<pinned>` — current SHA of the pinned branch.
2. Walk the binding's local directory (or the explicit `--paths=` list), diff each file's git-blob SHA against upstream.
3. Ensure `gitsync/<slug>` exists and points at pinned's current SHA:
   - Branch missing → `POST /git/refs` to create.
   - Branch at a different SHA → `PATCH /git/refs/heads/gitsync-<slug>` with `force=true` to rewind.
4. For each changed file: `PUT /contents/:path` with `branch=gitsync/<slug>` and `sha=<upstream-sha>` (so GitHub creates a commit on the feature branch correctly referencing the old blob).
5. `GET /pulls?head=<owner>:gitsync/<slug>&state=open` — find existing PR.
6. `PATCH /pulls/:n` (update) or `POST /pulls` (create).

The branch represents *the latest proposal from this binding* and updates in place. No per-submit branches accumulating.

## Push semantics — direct to pinned

`push` skips the feature branch and PR — commits go straight to the pinned branch. Requires **both** `policy.write_enabled=true` AND `policy.safe_direct_push=true`. The second key is intentional friction: bindings model a *read-synchronized mirror that tracks upstream*, and the default posture says "changes go upstream via PR review." Direct push is valid for single-owner scenarios (personal wikis, config repos you own) but requires deliberate intent.

## Security posture

- `local_path` must be ABSPATH-relative (leading slash, no `..`, no `.`).
- Sensitive filename fragments (`.env`, `id_rsa`, `.pem`, `.key`, `credentials.json`, `secrets`) are refused outright at both bind and submit time.
- `purge` re-validates containment via `realpath()` immediately before `rm -rf`, defending against symlink escapes.
- Staging (submit/push) enforces `policy.allowed_paths` as an allowlist prefix match. Empty allowlist = nothing uploadable.
- All abilities require `PermissionHelper::can_manage()`.
- GitHub PAT stored via DMC settings (`GitHubAbilities::getPat()`); submitter does not invent its own credential storage.

## Known limitations (Phase 1)

- **GitHub-only.** Non-GitHub remotes are refused at bind time. A pluggable PR backend for GitLab/Gitea is a future concern.
- **Per-file commits on submit.** A submit with N changed files produces N commits. PR reviewers see the aggregate diff; a future optimization using the Git Data API (single tree + single commit + single ref update) would collapse to one commit per submit.
- **No deletion propagation on submit.** Files *added* to or *modified* locally propagate upstream. Files *deleted* locally do not yet delete upstream. Workaround: delete upstream manually, then pull.
- **Scheduled sync not implemented.** `auto_pull` / `pull_interval` are accepted and stored, but nothing honors them. Revisit once a consumer actually needs it.

## Consumers

Intelligence (`#31`, `#125`) is the first consumer. Integration pattern:

1. Bind on plugin activation (or admin action).
2. Call `pull` on a hook that makes sense (cron, admin action, webhook) to import upstream content to disk.
3. A consumer-specific bridge imports disk content into the CPT (e.g. Intelligence's wiki importer reads markdown files and creates/updates wiki posts).
4. When the CPT is edited in wp-admin, the bridge exports changes back to disk.
5. Call `submit` to propose disk changes as a PR upstream.

The disk↔DB bridge is consumer-owned — GitSync only cares about disk↔upstream. That boundary is deliberate: each consumer's CPT has its own frontmatter shape, content model, and edit story. GitSync doesn't need to know.
