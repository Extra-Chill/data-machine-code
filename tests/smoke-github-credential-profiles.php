<?php
/**
 * Pure-PHP smoke test for GitHub credential profiles.
 *
 * Covers:
 *   - Zero-arg resolve() falls back to the default profile.
 *   - Explicit profile_id resolves the matching profile.
 *   - Unknown profile_id fails closed (no silent fallback).
 *   - repo selector picks the profile whose allowed_repos contains the repo.
 *   - repo selector falls back to default when no profile matches.
 *   - Legacy github_pat shape still works (synthesized into "default" profile).
 *   - DM update-settings filter round-trips profile arrays end-to-end.
 *
 * Run: php tests/smoke-github-credential-profiles.php
 */

declare( strict_types=1 );

namespace DataMachine\Core {
    class PluginSettings
    {
        public static function get( string $key, mixed $default_value = null ): mixed
        {
            return $GLOBALS['dmc_settings'][ $key ] ?? $default_value;
        }
    }
}

namespace {
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__ . '/');
    }

    class WP_Error
    {
        private string $code;
        private string $message;
        private array $data;

        public function __construct( string $code, string $message, array $data = array() )
        {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(): array
        {
            return $this->data;
        }
    }

    function is_wp_error( $value ): bool
    {
        return $value instanceof WP_Error;
    }

    function wp_json_encode( $value, $flags = 0, $depth = 512 )
    {
        return json_encode($value, $flags, $depth);
    }

    function get_transient( string $key )
    {
        return $GLOBALS['dmc_transients'][ $key ] ?? false;
    }

    function set_transient( string $key, $value, int $expiration ): bool
    {
        $GLOBALS['dmc_transients'][ $key ] = $value;
        return true;
    }

    function get_option( string $key, $default = false )
    {
        if ('datamachine_settings' === $key ) {
            return $GLOBALS['dmc_settings'] ?? $default;
        }
        return $GLOBALS['dmc_options'][ $key ] ?? $default;
    }

    function update_option( string $key, $value, $autoload = null ): bool
    {
        if ('datamachine_settings' === $key ) {
            $GLOBALS['dmc_settings'] = $value;
            return true;
        }
        $GLOBALS['dmc_options'][ $key ] = $value;
        return true;
    }

    function wp_remote_retrieve_response_code( $response ): int
    {
        return (int) ( $response['response']['code'] ?? 0 );
    }

    function wp_remote_retrieve_body( $response ): string
    {
        return (string) ( $response['body'] ?? '' );
    }

    function sanitize_text_field( $value )
    {
        // Mimic WP: collapse whitespace, strip tags. Good enough for smoke.
        return is_string($value) ? trim(strip_tags($value)) : '';
    }

    function sanitize_key( $key )
    {
        // Mirrors WP's sanitize_key: lowercase first, then strip to [a-z0-9_-].
        if (! is_string($key) ) {
            return '';
        }
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }

    include __DIR__ . '/../inc/Support/GitHubCredentialResolver.php';
    include __DIR__ . '/../inc/Support/GitHubProfileSanitizer.php';
    include __DIR__ . '/../inc/Support/GitHubCredentialSettingsMigration.php';

    use DataMachineCode\Support\GitHubCredentialResolver;
    use DataMachineCode\Support\GitHubCredentialSettingsMigration;
    use DataMachineCode\Support\GitHubProfileSanitizer;

    $failures = array();
    $assert   = function ( string $label, bool $cond ) use ( &$failures ): void {
        if ($cond ) {
            echo "  ok {$label}\n";
            return;
        }
        $failures[] = $label;
        echo "  fail {$label}\n";
    };

    $reset = function ( array $settings = array() ): void {
        $GLOBALS['dmc_settings']   = $settings;
        $GLOBALS['dmc_transients'] = array();
        $GLOBALS['dmc_options']    = array();
    };

    echo "GitHub credential profiles — smoke\n";

    // 1. Zero-arg resolve falls back to default (legacy github_pat shape).
    $reset(array( 'github_pat' => 'legacy-token' ));
    $cred = GitHubCredentialResolver::resolve();
    $assert('zero-arg resolve uses legacy github_pat as default profile', ! is_wp_error($cred) && 'legacy-token' === $cred['token'] && 'default' === $cred['profile_id']);

    // 2. Multiple profiles → explicit profile_id picks the right one.
    $reset(
        array(
        'github_credential_profiles' => array(
        array(
                'id'    => 'extrachill',
                'label' => 'Extra Chill',
                'mode'  => 'pat',
                'pat'   => 'extrachill-token',
        ),
        array(
                'id'    => 'automattic',
                'label' => 'Automattic',
                'mode'  => 'pat',
                'pat'   => 'automattic-token',
        ),
        ),
        'github_default_profile_id'  => 'extrachill',
        ) 
    );

    $default_cred = GitHubCredentialResolver::resolve();
    $assert('default_profile_id wins when no selector is passed', ! is_wp_error($default_cred) && 'extrachill-token' === $default_cred['token'] && 'extrachill' === $default_cred['profile_id']);

    $auto_cred = GitHubCredentialResolver::resolve(null, null, array( 'profile_id' => 'automattic' ));
    $assert('explicit profile_id selects matching profile', ! is_wp_error($auto_cred) && 'automattic-token' === $auto_cred['token']);

    // 3. Unknown profile_id fails closed.
    $missing = GitHubCredentialResolver::resolve(null, null, array( 'profile_id' => 'does-not-exist' ));
    $assert('unknown profile_id fails closed', is_wp_error($missing) && 'github_profile_not_found' === $missing->get_error_code());
    $assert('unknown profile_id error mentions requested id', is_wp_error($missing) && str_contains($missing->get_error_message(), 'does-not-exist'));

    // 4. repo selector picks profile whose allowed_repos contains the repo.
    $reset(
        array(
        'github_credential_profiles' => array(
        array(
                'id'            => 'personal',
                'mode'          => 'pat',
                'pat'           => 'personal-token',
                'allowed_repos' => array( 'chubes4/site' ),
        ),
        array(
                'id'            => 'work',
                'mode'          => 'pat',
                'pat'           => 'work-token',
                'allowed_repos' => array( 'Automattic/wpcom', 'Automattic/intelligence' ),
        ),
        ),
        'github_default_profile_id'  => 'personal',
        ) 
    );

    $work = GitHubCredentialResolver::resolve(null, null, array( 'repo' => 'Automattic/intelligence' ));
    $assert('repo selector picks profile via allowed_repos', ! is_wp_error($work) && 'work-token' === $work['token']);

    $work_case = GitHubCredentialResolver::resolve(null, null, array( 'repo' => 'automattic/wpcom' ));
    $assert('repo selector is case-insensitive', ! is_wp_error($work_case) && 'work-token' === $work_case['token']);

    $personal = GitHubCredentialResolver::resolve(null, null, array( 'repo' => 'chubes4/site' ));
    $assert('repo selector picks personal profile', ! is_wp_error($personal) && 'personal-token' === $personal['token']);

    // 5. Repo with no profile match falls through to default (does NOT error).
    $fallthrough = GitHubCredentialResolver::resolve(null, null, array( 'repo' => 'someone-else/repo' ));
    $assert('unmatched repo falls back to default profile', ! is_wp_error($fallthrough) && 'personal-token' === $fallthrough['token']);

    // 6. default_repo on a profile is used when allowed_repos has no match.
    $reset(
        array(
        'github_credential_profiles' => array(
        array(
                'id'           => 'project-a',
                'mode'         => 'pat',
                'pat'          => 'project-a-token',
                'default_repo' => 'team/project-a',
        ),
        array(
                'id'           => 'project-b',
                'mode'         => 'pat',
                'pat'          => 'project-b-token',
                'default_repo' => 'team/project-b',
        ),
        ),
        'github_default_profile_id'  => 'project-a',
        ) 
    );
    $by_default_repo = GitHubCredentialResolver::resolve(null, null, array( 'repo' => 'team/project-b' ));
    $assert('repo selector falls back to default_repo match', ! is_wp_error($by_default_repo) && 'project-b-token' === $by_default_repo['token']);

    $reset(
        array(
        'github_credential_profiles' => array(
        array(
                'id'            => 'repo-token',
                'mode'          => 'pat',
                'pat'           => 'repo-token-value',
                'allowed_repos' => array( 'owner/repo' ),
        ),
        array(
                'id'            => 'app-token',
                'mode'          => 'pat',
                'pat'           => 'app-token-value',
                'allowed_repos' => array( 'owner/repo' ),
                'capabilities'  => array( 'pull_request_create' ),
        ),
        ),
        'github_default_profile_id'  => 'repo-token',
        )
    );
    $repo_default = GitHubCredentialResolver::resolve(null, null, array( 'repo' => 'owner/repo' ));
    $assert('repo selector without capability keeps first matching profile', ! is_wp_error($repo_default) && 'repo-token-value' === $repo_default['token']);
    $pr_credential = GitHubCredentialResolver::resolve(null, null, array( 'repo' => 'owner/repo', 'capability' => 'pull_request_create' ));
    $assert('repo selector with capability prefers matching operation profile', ! is_wp_error($pr_credential) && 'app-token-value' === $pr_credential['token']);

    // 7. Profile with empty PAT fails closed (does not silently use default).
    $reset(
        array(
        'github_credential_profiles' => array(
        array(
                'id'   => 'empty',
                'mode' => 'pat',
                'pat'  => '',
        ),
        array(
                'id'   => 'real',
                'mode' => 'pat',
                'pat'  => 'real-token',
        ),
        ),
        'github_default_profile_id'  => 'real',
        ) 
    );
    $empty_explicit = GitHubCredentialResolver::resolve(null, null, array( 'profile_id' => 'empty' ));
    $assert('explicit empty profile fails closed (no fallback to default)', is_wp_error($empty_explicit) && 'github_pat_not_configured' === $empty_explicit->get_error_code());

    // 8. Status surface exposes profile summaries without leaking secrets.
    $reset(
        array(
        'github_credential_profiles' => array(
        array(
                'id'            => 'p1',
                'label'         => 'First',
                'mode'          => 'pat',
                'pat'           => 'p1-secret',
                'allowed_repos' => array( 'a/b' ),
                'capabilities'  => array( 'pull_request_create' ),
        ),
        array(
                'id'    => 'p2',
                'label' => 'Second',
                'mode'  => 'pat',
                'pat'   => '',
        ),
        ),
        'github_default_profile_id'  => 'p1',
        ) 
    );
    $status = GitHubCredentialResolver::status();
    $assert('status reports configured profiles', count($status['profiles']) === 2);
    $assert('status default_profile_id matches setting', 'p1' === $status['default_profile_id']);
    $assert('status profile entry hides PAT body', ! str_contains(wp_json_encode($status), 'p1-secret'));
    $assert('status profile reports configured booleans', $status['profiles'][0]['pat_configured'] && ! $status['profiles'][1]['pat_configured']);
    $assert('status profile reports non-secret capabilities', array( 'pull_request_create' ) === $status['profiles'][0]['capabilities']);

    // 9. Migration shape: legacy single-cred install still resolves.
    $reset(
        array(
        'github_pat'         => 'legacy-only',
        'github_default_repo' => 'team/legacy',
        ) 
    );
    $legacy = GitHubCredentialResolver::resolve();
    $assert('legacy install still resolves via synthesized default profile', ! is_wp_error($legacy) && 'legacy-only' === $legacy['token']);
    $legacy_status = GitHubCredentialResolver::status();
    $assert('legacy status surface still reports pat_configured at top-level', true === $legacy_status['pat_configured']);

    // 10. DM update-settings filter wiring — round-trip through GitHubProfileSanitizer.
    $raw_input = array(
    array(
    'id'            => 'Marketing',
    'label'         => '<b>Marketing</b>',
    'mode'          => 'PAT',
    'pat'           => 'marketing-secret',
    'default_repo'  => 'team/marketing',
    'allowed_repos' => array( 'team/marketing', '   team/sub  ' ),
    'capabilities'  => array( 'PULL_REQUEST_CREATE', 'issues_write' ),
    ),
    array(
    // Missing id → must be dropped.
    'mode' => 'pat',
    'pat'  => 'orphan',
    ),
    );
    $sanitized = GitHubProfileSanitizer::sanitize($raw_input);
    $assert('sanitizer drops profiles without id', count($sanitized) === 1);
    $assert('sanitizer normalizes mode to lowercase', 'pat' === $sanitized[0]['mode']);
    $assert('sanitizer normalizes id via sanitize_key', 'marketing' === $sanitized[0]['id']);
    $assert('sanitizer preserves PAT', 'marketing-secret' === $sanitized[0]['pat']);
    $assert('sanitizer trims allowed_repos entries', $sanitized[0]['allowed_repos'] === array( 'team/marketing', 'team/sub' ));
    $assert('sanitizer normalizes capabilities', $sanitized[0]['capabilities'] === array( 'pull_request_create', 'issues_write' ));

    // 11. Legacy settings migration dry-runs without leaking secrets, then applies profiles.
    $reset(
        array(
        'github_pat'         => 'legacy-secret-token',
        'github_default_repo' => 'team/legacy',
        )
    );
    $migration_status = GitHubCredentialSettingsMigration::status();
    $assert('migration status detects legacy keys', true === $migration_status['legacy_keys_present'] && in_array('github_pat', $migration_status['legacy_keys'], true));
    $dry_run = GitHubCredentialSettingsMigration::migrate(false);
    $dry_json = wp_json_encode($dry_run);
    $assert('migration dry run does not expose PAT body', is_string($dry_json) && ! str_contains($dry_json, 'legacy-secret-token'));
    $assert('migration dry run reports redacted PAT configured flag', true === ( $dry_run['profile']['pat_configured'] ?? false ));
    $applied = GitHubCredentialSettingsMigration::migrate(true);
    $assert('migration apply writes profiles', true === ( $applied['applied'] ?? false ) && isset($GLOBALS['dmc_settings']['github_credential_profiles'][0]));
    $migrated = GitHubCredentialResolver::resolve();
    $assert('resolver uses migrated profile after apply', ! is_wp_error($migrated) && 'legacy-secret-token' === $migrated['token']);
    $assert('migration apply marks migrated option', true === ( $GLOBALS['dmc_options'][GitHubCredentialSettingsMigration::MIGRATED_OPTION] ?? false ));

    if ($failures ) {
        echo "\nFailures:\n";
        foreach ( $failures as $failure ) {
            echo " - {$failure}\n";
        }
        exit(1);
    }

    echo "All assertions passed.\n";
}
