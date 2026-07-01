# GitHub App Setup

Operator runbook for switching Data Machine Code's GitHub authentication from a classic PAT to a GitHub App installation. Posts as a bot user instead of a human, scopes to one install, rotates per-call installation tokens automatically.

This is a one-time setup per host. After it lands, every `datamachine/*-github-*` ability call (issue filing, PR creation, comments, workspace pushes) automatically posts as the bot. Consumers don't need to know which mode is active.

## When to do this

Switch from PAT to App when any of these are true:

- You want a non-personal author identity for bot-filed issues / bot-opened PRs / bot-authored commits.
- You want to scope which repos the credential can touch via `allowed_repos` per credential profile.
- The PAT is owned by a single human whose departure would break the platform.
- You want per-call installation tokens (short-lived, mintable, revocable) instead of a long-lived PAT.

The PAT can stay around as a named fallback profile — operators who legitimately need to post as themselves keep that path open.

## Prerequisites

- Admin access to the GitHub org where the App will be installed.
- WP-CLI access on the host (`wp datamachine ...`, `wp datamachine-code ...`).
- A safe place to store the App private key (e.g. `/root/.secrets/<app-slug>.private-key.pem`, mode `0600`).
- An existing PAT-based credential profile if you want to preserve it as a fallback.

## Concept primer

The credential resolver is in `inc/Support/GitHubCredentialResolver.php`. It supports two modes per credential profile:

| Mode   | Token shape                                    | Lifetime         | Best for                                         |
|--------|------------------------------------------------|------------------|--------------------------------------------------|
| `pat`  | `Authorization: token ghp_...`                 | Until revoked    | Personal automation, single-human attribution    |
| `app`  | `Authorization: token ghs_...` (installation)  | ~1 hour, cached  | Bot identity, org-scoped, per-call minting       |

`mode: 'app'` profiles store `github_app_id`, `github_app_installation_id`, and `github_app_private_key`. On `resolve()` the resolver:

1. Signs a JWT from the App ID + private key (RS256, ~10 min validity).
2. Exchanges the JWT for an installation access token via `POST /app/installations/:id/access_tokens`.
3. Caches the installation token in a transient until its `expires_at` minus a 60-second skew.
4. Returns the token with `Authorization: token <ghs_...>` ready for any GitHub API call.

JWT signing requires either the `openssl` PHP extension (default on most hosts) or `firebase/php-jwt` as a fallback (already pulled in via Composer for installs that want a non-OpenSSL path).

## Step 1 — Create the GitHub App

In the org's GitHub settings → Developer settings → GitHub Apps → New GitHub App:

- **Name:** a brand-aligned slug, e.g. `homeboy-ci`. This becomes the visible author identity on issues/PRs/commits as `<slug>[bot]`.
- **Homepage URL:** anything (required field; not used at runtime).
- **Webhook:** uncheck "Active". DMC doesn't consume webhooks.
- **Permissions** (mirror `wp datamachine-code github status`):
  - Repository: Contents — read
  - Repository: Issues — read & write
  - Repository: Pull requests — read & write
  - Repository: Checks — read
  - Repository: Commit statuses — read
  - Repository: Actions — read (artifact downloads)
- **Where can this app be installed:** "Only on this account" (the org).

Submit. GitHub takes you to the App's settings page.

Record from that page:

- The numeric **App ID** (top of page).
- Click **Generate a private key** → downloads a `.pem` file. Move it to the host:
  ```bash
  install -m 0600 -o root -g root \
    <download>/<app-slug>.<date>.private-key.pem \
    /root/.secrets/<app-slug>.private-key.pem
  ```

## Step 2 — Install the App on the org

From the App's settings page: **Install App** → select the org → choose "All repositories" (matches the default PAT scope; switch to "Only select repositories" if you want finer scoping at the GitHub level).

After install, the URL is `https://github.com/organizations/<org>/settings/installations/<installation_id>`. Record the **Installation ID**.

You can verify both IDs against the org from the host:

```bash
gh api /orgs/<org>/installations \
  --jq '.installations[] | {app_id, app_slug, installation_id: .id, account: .account.login, repos: .repository_selection}'
```

Expected:

```json
{"app_id":3034937,"app_slug":"homeboy-ci","installation_id":114752821,"account":"Extra-Chill","repos":"all"}
```

## Step 3 — Land the credentials on the host

Three settings to write into `datamachine_settings` via the canonical `datamachine/update-settings` ability. Use the ability rather than `wp option patch` so the sanitizer in `data-machine-code.php` runs (PEM newlines are preserved; other fields go through `sanitize_text_field()`).

```bash
# IDs are short, plain strings — set via the CLI shortcut.
wp datamachine settings set github_app_id 3034937
wp datamachine settings set github_app_installation_id 114752821
```

The private key is multiline so the positional-arg CLI form rejects it. Use a small eval-file:

```bash
cat > /tmp/set-gh-app-key.php <<'PHP'
<?php
$pem = file_get_contents( '/root/.secrets/homeboy-ci.private-key.pem' );
if ( ! is_string( $pem ) || false === strpos( $pem, 'BEGIN' ) ) {
    fwrite( STDERR, "ERROR: PEM read failed\n" );
    exit( 1 );
}

$ability = wp_get_ability( 'datamachine/update-settings' );
if ( ! $ability ) {
    fwrite( STDERR, "ERROR: datamachine/update-settings ability not registered\n" );
    exit( 1 );
}

$result = $ability->execute( array( 'github_app_private_key' => $pem ) );
if ( is_wp_error( $result ) ) {
    fwrite( STDERR, 'ability_error: ' . $result->get_error_message() . "\n" );
    exit( 1 );
}

$stored = \DataMachine\Core\PluginSettings::get( 'github_app_private_key', '' );
printf(
    "configured: %s\nbyte_count: %d\n",
    false !== strpos( $stored, 'BEGIN' ) ? 'yes' : 'no',
    strlen( $stored )
);
PHP

wp --allow-root --path=/path/to/wordpress eval-file /tmp/set-gh-app-key.php
rm /tmp/set-gh-app-key.php
```

Expected:

```
configured: yes
byte_count: 1674
```

Byte count varies (~1700 for a 2048-bit key). `configured: yes` is the contract.

## Step 4 — Define credential profiles

`github_credential_profiles` is the new shape that replaces the legacy single-credential keys. Each profile is independently selectable per-call via `selector: { profile_id: '...' }`. `github_default_profile_id` points at whichever profile zero-arg `resolve()` returns.

Recommended layout: keep the App as the default, demote the existing PAT to a named fallback.

```bash
cat > /tmp/set-gh-profiles.php <<'PHP'
<?php
$ability  = wp_get_ability( 'datamachine/update-settings' );
$profiles = array(
    array(
        'id'             => 'homeboy-ci',
        'label'          => 'homeboy-ci[bot] (GitHub App)',
        'mode'           => 'app',
        'default_repo'   => '',
        'allowed_repos'  => array(),
    ),
    array(
        'id'             => 'personal-pat',
        'label'          => 'Chris personal PAT (fallback)',
        'mode'           => 'pat',
        'pat'            => \DataMachine\Core\PluginSettings::get( 'github_pat', '' ),
        'default_repo'   => '',
        'allowed_repos'  => array(),
    ),
);

$result = $ability->execute(
    array(
        'github_credential_profiles' => $profiles,
        'github_default_profile_id'  => 'homeboy-ci',
    )
);

if ( is_wp_error( $result ) ) {
    fwrite( STDERR, 'profiles_error: ' . $result->get_error_message() . "\n" );
    exit( 1 );
}

echo "profiles_written: " . count( $profiles ) . "\n";
echo "default: homeboy-ci\n";
PHP

wp --allow-root --path=/path/to/wordpress eval-file /tmp/set-gh-profiles.php
rm /tmp/set-gh-profiles.php
```

`GitHubProfileSanitizer::sanitize()` (`inc/Support/GitHubProfileSanitizer.php`) enforces shape: each profile must have a non-empty `id`, a `mode` in `{pat, app}`, and the credentials matching its mode. Unknown keys are dropped. Duplicate `id`s collapse to the last write.

**Per-repo scoping (optional).** To restrict a profile to specific repos, populate `allowed_repos`:

```php
'allowed_repos' => array( 'Extra-Chill/extrachill-roadie', 'Extra-Chill/data-machine-code' ),
```

When a caller passes `selector: { repo: '<owner/name>' }`, the resolver picks the profile whose `allowed_repos` contains that repo, or whose `default_repo` matches. Unmatched repos fall through to the default profile.

## Step 5 — Verify the resolver

```bash
wp datamachine-code github status
```

Expected:

```
Configured credential profiles:
Default profile: homeboy-ci
setting                       value
Auth Mode                     app
Configured                    Configured
GitHub App ID                 Configured
GitHub App Installation ID    Configured
GitHub App Private Key        Configured
...
id            label                                  mode  configured
personal-pat  Chris personal PAT (fallback)          pat   yes
homeboy-ci    homeboy-ci[bot] (GitHub App)           app   yes
```

The top-level `GitHub PAT` may show `Not configured` even though `personal-pat` shows configured in the profile list — that's the legacy single-cred surface vs the new profile surface, and both can coexist. The profile list is authoritative for `resolve()`.

## Step 6 — Smoke the bot identity

File a throwaway issue and verify the GitHub-side author:

```bash
wp eval '
$result = wp_get_ability( "datamachine/create-github-issue" )->execute(
    array(
        "repo"   => "<org>/<test-repo>",
        "title"  => "[smoke] GitHub App identity verification",
        "body"   => "Closing immediately.",
        "labels" => array( "smoke-test" ),
    )
);
echo "issue_url: " . ( $result["html_url"] ?? "?" ) . PHP_EOL;
echo "issue_number: " . ( $result["number"] ?? "?" ) . PHP_EOL;
'
```

Confirm the author on GitHub:

```bash
gh api repos/<org>/<test-repo>/issues/<number> \
  --jq '{author: .user.login, author_type: .user.type}'
```

Expected:

```json
{"author":"<app-slug>[bot]","author_type":"Bot"}
```

If `author_type` is still `User`, the resolver is falling back to the PAT — check `github_default_profile_id` and re-run.

Close the smoke issue:

```bash
gh issue close <number> --repo <org>/<test-repo> --comment "Smoke verified."
```

## Step 7 — Test the fallback path (optional)

To confirm the PAT profile is still selectable:

```bash
wp eval '
$result = wp_get_ability( "datamachine/create-github-issue" )->execute(
    array(
        "repo"          => "<org>/<test-repo>",
        "title"         => "[smoke] PAT fallback verification",
        "body"          => "Closing immediately.",
        "labels"        => array( "smoke-test" ),
        // Explicit selector overrides the default profile.
        "credential_selector" => array( "profile_id" => "personal-pat" ),
    )
);
'
```

This issue should show your human GitHub username as the author.

If `credential_selector` is not yet plumbed through to the specific ability you're testing, the resolver test path is:

```bash
wp eval '
$resolved = \DataMachineCode\Support\GitHubCredentialResolver::resolve(
    null, null,
    array( "profile_id" => "personal-pat" )
);
echo "mode: " . ( $resolved["mode"] ?? "?" ) . PHP_EOL;
echo "profile_id: " . ( $resolved["profile_id"] ?? "?" ) . PHP_EOL;
'
```

Expected:

```
mode: pat
profile_id: personal-pat
```

## Troubleshooting

### `github_pat_not_configured` for profile "homeboy-ci"

The profile is declared as `mode: 'app'` but the resolver picked the PAT branch — this means the mode field got rewritten to `pat` somewhere. Inspect the stored profile:

```bash
wp eval 'var_dump( \DataMachine\Core\PluginSettings::get( "github_credential_profiles", array() ) );'
```

Common cause: rewriting the profile array without setting `mode` explicitly. Always include `'mode' => 'app'` in the App profile entry.

### `github_app_token_request_failed` with HTTP 401

The installation rejected the JWT. Three things to check:

1. App ID matches the deployed App (compare with `gh api /orgs/<org>/installations`).
2. Installation ID matches the install on this org (the same API call returns it).
3. The PEM file is the matching private key for the App. If you regenerated the key after first install, the old PEM is rejected — store the new one and re-run step 3.

### Installation token cache poisoned

The resolver caches installation tokens in `transient_datamachine_code_github_app_token_<hash>`. To force a fresh mint:

```bash
wp transient delete --all
```

Cheap; only DMC's GitHub App tokens are stored under that prefix.

### JWT signing fails with `openssl_sign() not available`

Either install the `openssl` PHP extension (preferred — bundled with most distros) or confirm `firebase/php-jwt` is in the Composer autoloader. The resolver falls back to `firebase/php-jwt` automatically when the OpenSSL extension is missing.

## Behavioral notes

- **Per-call token minting** means each batch of GitHub API calls runs against a token that's at most ~1 hour old. There's no long-lived secret to rotate beyond the App's private key itself.
- **Token expiry is observed.** The resolver re-mints when the cached token is within 60 seconds of expiry (constant `APP_TOKEN_EXPIRY_SKEW`). Long-running CLI commands don't hit "token expired" errors mid-run.
- **Author identity is determined by the credential.** `mode: 'app'` posts as `<app-slug>[bot]`. `mode: 'pat'` posts as the PAT owner. Consumers don't have to know — they call abilities, the resolver picks the credential, GitHub does the rest.
- **Audit trail.** Every API call from a `mode: 'app'` profile is attributable to the App installation (visible in the org's audit log) and tagged with the bot author on every commit/issue/PR/comment.
- **Removing the PAT entirely.** Optional. The PAT can stay as a fallback profile or be dropped from `github_credential_profiles`. Removing it is recommended once OAuth-linked per-user accounts are available (so individual humans have their own identities) and the bot covers all automation.
