<?php
/**
 * Smoke test for DMC agent/session lifecycle tracking on worktrees.
 *
 * Covers:
 *  - liveness classification (live / stale / stopped / unknown) with reason codes
 *  - heartbeat updates persisted to lifecycle metadata
 *  - duplicate task ownership detection (task_url, task_ref, pr_url, pr_repo#pr_number)
 *  - owner/session/task summary helpers exposing unknown-safe defaults
 *  - env-driven session and task capture from build_lifecycle_metadata
 *
 * Run: php tests/smoke-worktree-agent-session-lifecycle.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace DataMachine\Core\FilesRepository {
	if ( ! class_exists( __NAMESPACE__ . '\FilesystemHelper' ) ) {
		class FilesystemHelper {
			public static function get() {
				return null;
			}
		}
	}

	if ( ! class_exists( __NAMESPACE__ . '\DirectoryManager' ) ) {
		class DirectoryManager {
			public function get_effective_user_id( int $fallback ): int {
				return 7;
			}

			public function resolve_agent_slug( array $args ): string {
				return 'minion-franklin';
			}
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public string $code;
			public string $message;
			public array $data;

			public function __construct( $code = '', $message = '', $data = array() ) {
				$this->code    = (string) $code;
				$this->message = (string) $message;
				$this->data    = (array) $data;
			}

			public function get_error_message(): string {
				return $this->message;
			}

			public function get_error_data() {
				return $this->data;
			}
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool {
			return $thing instanceof \WP_Error;
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $name, $default = false ) {
			global $datamachine_code_test_options;
			return $datamachine_code_test_options[ $name ] ?? $default;
		}
	}

	if ( ! function_exists( 'update_option' ) ) {
		function update_option( string $name, $value, $autoload = null ): bool {
			global $datamachine_code_test_options;
			$datamachine_code_test_options[ $name ] = $value;
			return true;
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( string $hook_name, $value, ...$args ) {
			global $datamachine_code_test_filters;
			if ( isset( $datamachine_code_test_filters[ $hook_name ] ) && is_callable( $datamachine_code_test_filters[ $hook_name ] ) ) {
				return $datamachine_code_test_filters[ $hook_name ]( $value, ...$args );
			}
			return $value;
		}
	}

	if ( ! function_exists( 'home_url' ) ) {
		function home_url(): string {
			return 'https://intelligence.example.test';
		}
	}

	if ( ! function_exists( 'get_bloginfo' ) ) {
		function get_bloginfo( string $show ): string {
			return 'Intelligence';
		}
	}

	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id(): int {
			return 7;
		}
	}

	if ( ! function_exists( 'get_userdata' ) ) {
		function get_userdata( int $user_id ): object {
			return (object) array(
				'user_login'   => 'chris',
				'display_name' => 'Chris Huber',
			);
		}
	}

	if ( ! function_exists( 'wp_mkdir_p' ) ) {
		function wp_mkdir_p( string $path ): bool {
			return is_dir( $path ) || mkdir( $path, 0755, true );
		}
	}

	require __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';

	$assertions = 0;
	$failures   = 0;

	$assert = function ( $expected, $actual, string $message ) use ( &$assertions, &$failures ): void {
		++$assertions;
		if ( $expected === $actual ) {
			echo "  [PASS] {$message}\n";
			return;
		}
		++$failures;
		echo "  [FAIL] {$message}\n";
		echo '         expected: ' . var_export( $expected, true ) . "\n";
		echo '         actual:   ' . var_export( $actual, true ) . "\n";
	};

	echo "=== smoke-worktree-agent-session-lifecycle ===\n";

	// Reset option + filter state for this run.
	$GLOBALS['datamachine_code_test_options'] = array();
	$GLOBALS['datamachine_code_test_filters'] = array();

	// Register two synthetic test runtimes via the public filter contract.
	// DMC has zero knowledge of these IDs or env-var names — the integration
	// layer (here, the test) owns both.
	$GLOBALS['datamachine_code_test_filters']['datamachine_code_worktree_runtime_signatures'] = function ( array $signatures ): array {
		$signatures['alpha-runtime'] = array(
			'session_id' => 'DMC_SMOKE_ALPHA_SESSION_ID',
			'thread_id'  => 'DMC_SMOKE_ALPHA_THREAD_ID',
			'thread_url' => 'DMC_SMOKE_ALPHA_THREAD_URL',
		);
		$signatures['beta-runtime'] = array(
			'session_id' => 'DMC_SMOKE_BETA_SESSION_ID',
			'run_id'     => 'DMC_SMOKE_BETA_RUN_ID',
		);
		return $signatures;
	};

	// --- 1) build_lifecycle_metadata captures env-driven session + task fields ---
	putenv( 'DMC_SMOKE_BETA_SESSION_ID=ses_smoke_42' );
	putenv( 'DMC_SMOKE_BETA_RUN_ID=run-smoke-1' );
	putenv( 'DMC_SMOKE_ALPHA_SESSION_ID=alpha-ses-99' );
	putenv( 'DMC_SMOKE_ALPHA_THREAD_ID=thr_111' );
	putenv( 'DMC_SMOKE_ALPHA_THREAD_URL=https://example.test/threads/1/2/3' );
	putenv( 'DATAMACHINE_TASK_URL=https://github.com/Extra-Chill/data-machine-code/issues/221' );
	putenv( 'DATAMACHINE_TASK_REF=Extra-Chill/data-machine-code#221' );

	$built = \DataMachineCode\Workspace\WorktreeContextInjector::build_lifecycle_metadata( array(
		'handle' => 'demo@feature-x',
		'path'   => '/tmp/demo@feature-x',
		'repo'   => 'demo',
		'branch' => 'feature/x',
	) );

	$assert( 'active', $built['lifecycle_state'] ?? null, 'new metadata defaults lifecycle to active' );
	$assert( true, isset( $built['last_seen_at'] ) && '' !== $built['last_seen_at'], 'last_seen_at heartbeat is set at creation' );
	$assert( $built['created_at'] ?? '', $built['last_seen_at'] ?? '_none_', 'last_seen_at matches created_at on first record' );
	$assert( 'minion-franklin', $built['origin_agent'] ?? null, 'origin agent recorded' );
	$assert( 'Intelligence', $built['origin_site'] ?? null, 'origin site name recorded' );
	$assert( 'https://intelligence.example.test', $built['origin_site_url'] ?? null, 'origin site URL recorded' );
	$assert( 'chris', $built['origin_user']['login'] ?? null, 'origin user login recorded' );
	$assert( 'ses_smoke_42', $built['origin_session']['ids']['beta-runtime']['session_id'] ?? null, 'beta runtime session id captured under ids envelope' );
	$assert( 'run-smoke-1', $built['origin_session']['ids']['beta-runtime']['run_id'] ?? null, 'beta runtime run id captured under ids envelope' );
	$assert( 'alpha-ses-99', $built['origin_session']['ids']['alpha-runtime']['session_id'] ?? null, 'alpha runtime session id captured under ids envelope' );
	$assert( 'thr_111', $built['origin_session']['ids']['alpha-runtime']['thread_id'] ?? null, 'alpha runtime thread id captured under ids envelope' );
	$assert( 'https://example.test/threads/1/2/3', $built['origin_session']['ids']['alpha-runtime']['thread_url'] ?? null, 'alpha runtime thread URL captured under ids envelope' );
	$assert( 'alpha-ses-99', $built['origin_session']['primary_id'] ?? null, 'primary_id resolves to first registered runtime session_id' );
	$assert( 'https://github.com/Extra-Chill/data-machine-code/issues/221', $built['origin_task']['task_url'] ?? null, 'task URL captured from env' );
	$assert( 'Extra-Chill/data-machine-code#221', $built['origin_task']['task_ref'] ?? null, 'task ref captured from env' );

	// URL-suffixed subkeys must look like http(s) URLs to be captured.
	putenv( 'DMC_SMOKE_ALPHA_THREAD_URL=not-a-url' );
	$built_bad_url = \DataMachineCode\Workspace\WorktreeContextInjector::build_lifecycle_metadata( array(
		'handle' => 'demo@bad-url',
		'path'   => '/tmp/demo@bad-url',
		'repo'   => 'demo',
		'branch' => 'bad/url',
	) );
	$assert( null, $built_bad_url['origin_session']['ids']['alpha-runtime']['thread_url'] ?? null, 'non-URL value for *_url subkey is dropped' );
	putenv( 'DMC_SMOKE_ALPHA_THREAD_URL=https://example.test/threads/1/2/3' );

	// Caller-supplied task_url/task_ref override env.
	putenv( 'DATAMACHINE_TASK_URL=https://github.com/some/other/issues/9999' );
	$built_explicit = \DataMachineCode\Workspace\WorktreeContextInjector::build_lifecycle_metadata( array(
		'handle'   => 'demo@bug-fix',
		'task_url' => 'https://github.com/Extra-Chill/data-machine-code/issues/221',
		'task_ref' => 'EC/dmc#221',
	) );
	$assert( 'https://github.com/Extra-Chill/data-machine-code/issues/221', $built_explicit['origin_task']['task_url'] ?? null, 'explicit task_url wins over env' );
	$assert( 'EC/dmc#221', $built_explicit['origin_task']['task_ref'] ?? null, 'explicit task_ref wins over env' );

	// Clear env so subsequent assertions get unknown-safe defaults.
	putenv( 'DMC_SMOKE_BETA_SESSION_ID' );
	putenv( 'DMC_SMOKE_BETA_RUN_ID' );
	putenv( 'DMC_SMOKE_ALPHA_SESSION_ID' );
	putenv( 'DMC_SMOKE_ALPHA_THREAD_ID' );
	putenv( 'DMC_SMOKE_ALPHA_THREAD_URL' );
	putenv( 'DATAMACHINE_TASK_URL' );
	putenv( 'DATAMACHINE_TASK_REF' );

	// --- 2) Liveness classification ---
	$now = strtotime( '2026-05-10T12:00:00Z' );

	$missing = \DataMachineCode\Workspace\WorktreeContextInjector::classify_liveness( null, $now );
	$assert( 'unknown', $missing['liveness'], 'null metadata classifies as unknown' );
	$assert( 'metadata_missing', $missing['reason'], 'null metadata has metadata_missing reason' );

	$fresh = \DataMachineCode\Workspace\WorktreeContextInjector::classify_liveness(
		array(
			'lifecycle_state' => 'active',
			'last_seen_at'    => gmdate( 'c', $now - 600 ),
		),
		$now,
		3600
	);
	$assert( 'live', $fresh['liveness'], 'recent heartbeat classifies as live' );
	$assert( 'heartbeat_fresh', $fresh['reason'], 'live carries heartbeat_fresh reason' );
	$assert( 600, $fresh['heartbeat_age_seconds'], 'heartbeat age computed in seconds' );

	$stale = \DataMachineCode\Workspace\WorktreeContextInjector::classify_liveness(
		array(
			'lifecycle_state' => 'active',
			'last_seen_at'    => gmdate( 'c', $now - ( 2 * 86400 ) ),
		),
		$now,
		86400
	);
	$assert( 'stale', $stale['liveness'], 'heartbeat older than TTL classifies as stale' );
	$assert( 'heartbeat_stale', $stale['reason'], 'stale carries heartbeat_stale reason' );

	$pr_opened = \DataMachineCode\Workspace\WorktreeContextInjector::classify_liveness(
		array( 'lifecycle_state' => 'pr_opened' ),
		$now
	);
	$assert( 'stopped', $pr_opened['liveness'], 'pr_opened lifecycle classifies as stopped liveness' );
	$assert( 'lifecycle_pr_opened', $pr_opened['reason'], 'pr_opened liveness carries explicit reason' );

	$cleanup_eligible = \DataMachineCode\Workspace\WorktreeContextInjector::classify_liveness(
		array( 'lifecycle_state' => 'cleanup_eligible' ),
		$now
	);
	$assert( 'stopped', $cleanup_eligible['liveness'], 'cleanup_eligible classifies as stopped' );
	$assert( 'lifecycle_finalized', $cleanup_eligible['reason'], 'cleanup_eligible carries lifecycle_finalized reason' );

	$no_heartbeat = \DataMachineCode\Workspace\WorktreeContextInjector::classify_liveness(
		array( 'lifecycle_state' => 'active' ),
		$now
	);
	$assert( 'unknown', $no_heartbeat['liveness'], 'active without heartbeat or created_at is unknown' );
	$assert( 'heartbeat_unknown', $no_heartbeat['reason'], 'unknown liveness reports heartbeat_unknown' );

	$bad_timestamp = \DataMachineCode\Workspace\WorktreeContextInjector::classify_liveness(
		array(
			'lifecycle_state' => 'active',
			'last_seen_at'    => 'not-a-date',
		),
		$now
	);
	$assert( 'unknown', $bad_timestamp['liveness'], 'unparseable last_seen_at degrades to unknown rather than crashing' );

	// --- 3) Heartbeat persistence ---
	\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
		'demo@feature-heartbeat',
		array(
			'lifecycle_state' => 'active',
			'created_at'      => gmdate( 'c', $now - 3600 ),
			'last_seen_at'    => gmdate( 'c', $now - 3600 ),
		)
	);
	$bumped = \DataMachineCode\Workspace\WorktreeContextInjector::record_heartbeat( 'demo@feature-heartbeat', gmdate( 'c', $now ) );
	$assert( gmdate( 'c', $now ), $bumped, 'record_heartbeat returns persisted timestamp' );
	$reloaded = \DataMachineCode\Workspace\WorktreeContextInjector::get_metadata( 'demo@feature-heartbeat' );
	$assert( gmdate( 'c', $now ), $reloaded['last_seen_at'] ?? null, 'heartbeat is persisted to metadata' );

	$missing_handle = \DataMachineCode\Workspace\WorktreeContextInjector::record_heartbeat( 'demo@does-not-exist' );
	$assert( null, $missing_handle, 'record_heartbeat returns null when handle has no metadata (does not invent records)' );

	// --- 4) Owner / session summaries with unknown-safe defaults ---
	$owner_unknown = \DataMachineCode\Workspace\WorktreeContextInjector::summarize_owner( null );
	$assert( 'unknown', $owner_unknown['site'], 'summarize_owner defaults site to unknown' );
	$assert( 'unknown', $owner_unknown['agent'], 'summarize_owner defaults agent to unknown' );
	$assert( 'unknown', $owner_unknown['user'], 'summarize_owner defaults user to unknown' );
	$assert( null, $owner_unknown['site_url'], 'summarize_owner site_url is null when missing' );

	$owner_filled = \DataMachineCode\Workspace\WorktreeContextInjector::summarize_owner( $built );
	$assert( 'Intelligence', $owner_filled['site'], 'summarize_owner reflects origin site' );
	$assert( 'minion-franklin', $owner_filled['agent'], 'summarize_owner reflects origin agent' );
	$assert( 'chris', $owner_filled['user'], 'summarize_owner prefers user_login over display_name' );

	$session_unknown = \DataMachineCode\Workspace\WorktreeContextInjector::summarize_session( null );
	$assert( null, $session_unknown['primary_id'], 'summarize_session primary_id null on missing metadata' );
	$assert( array(), $session_unknown['ids'], 'summarize_session ids is empty map on missing metadata' );

	$session_filled = \DataMachineCode\Workspace\WorktreeContextInjector::summarize_session( $built );
	$assert( 'alpha-ses-99', $session_filled['primary_id'], 'summarize_session primary_id follows registered runtime order' );
	$assert( 'ses_smoke_42', $session_filled['ids']['beta-runtime']['session_id'] ?? null, 'summarize_session exposes beta runtime session id under ids envelope' );
	$assert( 'alpha-ses-99', $session_filled['ids']['alpha-runtime']['session_id'] ?? null, 'summarize_session exposes alpha runtime session id under ids envelope' );

	// --- 4b) Legacy-shape migration (pre-#416 stored rows). ---
	// Stored rows that persisted vendor-specific top-level keys must normalize
	// transparently into the generic envelope without losing data.
	$legacy_metadata = array(
		'origin_session' => array(
			'alpha-runtime_session_id' => 'legacy-alpha-ses',
			'alpha-runtime_thread_id'  => 'legacy-alpha-thr',
			'beta-runtime_run_id'      => 'legacy-beta-run',
		),
	);
	$legacy_view = \DataMachineCode\Workspace\WorktreeContextInjector::summarize_session( $legacy_metadata );
	$assert( 'legacy-alpha-ses', $legacy_view['ids']['alpha-runtime']['session_id'] ?? null, 'legacy migration projects <runtime>_session_id into ids envelope' );
	$assert( 'legacy-alpha-thr', $legacy_view['ids']['alpha-runtime']['thread_id'] ?? null, 'legacy migration projects <runtime>_thread_id into ids envelope' );
	$assert( 'legacy-beta-run', $legacy_view['ids']['beta-runtime']['run_id'] ?? null, 'legacy migration projects <runtime>_run_id into ids envelope' );
	$assert( 'legacy-alpha-ses', $legacy_view['primary_id'], 'legacy-migrated rows still resolve a primary_id via runtime precedence' );

	// Mixed envelope: explicit primary_id wins over runtime-derived precedence.
	$mixed_view = \DataMachineCode\Workspace\WorktreeContextInjector::summarize_session( array(
		'origin_session' => array(
			'primary_id' => 'explicit-primary',
			'ids'        => array(
				'alpha-runtime' => array( 'session_id' => 'alpha-ses' ),
				'beta-runtime'  => array( 'session_id' => 'beta-ses' ),
			),
		),
	) );
	$assert( 'explicit-primary', $mixed_view['primary_id'], 'explicit primary_id on stored envelope overrides runtime scan' );

	// --- 5) Duplicate task ownership detection ---
	$rows = array(
		array(
			'handle'   => 'a@one',
			'metadata' => array(
				'origin_task' => array( 'task_url' => 'https://github.com/Extra-Chill/data-machine-code/issues/221' ),
			),
		),
		array(
			'handle'   => 'a@two',
			'metadata' => array(
				'origin_task' => array( 'task_url' => 'https://github.com/Extra-Chill/data-machine-code/issues/221' ),
			),
		),
		array(
			'handle'   => 'a@three',
			'metadata' => array(
				'pr_url'   => 'https://github.com/foo/bar/pull/42',
				'pr_repo'  => 'foo/bar',
				'pr_number' => 42,
			),
		),
		array(
			'handle'   => 'a@four',
			'metadata' => array(
				'pr_url'    => 'https://github.com/foo/bar/pull/42',
				'pr_repo'   => 'foo/bar',
				'pr_number' => 42,
			),
		),
		array(
			'handle'   => 'a@five',
			'metadata' => array(
				'origin_task' => array( 'task_ref' => 'org/repo#7' ),
			),
		),
	);

	$dups = \DataMachineCode\Workspace\WorktreeContextInjector::find_duplicate_task_ownership( $rows );
	$dup_kinds = array_map( fn( $g ) => $g['kind'], $dups );
	$assert( true, in_array( 'task_url', $dup_kinds, true ), 'duplicate detection flags task_url collisions' );
	$assert( true, in_array( 'pr_url', $dup_kinds, true ), 'duplicate detection flags pr_url collisions' );
	$assert( true, in_array( 'pr_ref', $dup_kinds, true ), 'duplicate detection flags pr_repo#pr_number collisions' );
	$assert( false, in_array( 'task_ref', $dup_kinds, true ), 'a single task_ref does not produce a duplicate group' );

	$task_url_group = array_values( array_filter( $dups, fn( $g ) => 'task_url' === $g['kind'] ) )[0] ?? array();
	$assert( true, in_array( 'a@one', (array) $task_url_group['handles'], true ) && in_array( 'a@two', (array) $task_url_group['handles'], true ), 'task_url group lists both handles' );

	// Single row: no duplicates.
	$single = \DataMachineCode\Workspace\WorktreeContextInjector::find_duplicate_task_ownership(
		array(
			array(
				'handle'   => 'solo@one',
				'metadata' => array(
					'origin_task' => array( 'task_url' => 'https://example.test/issues/1' ),
				),
			),
		)
	);
	$assert( 0, count( $single ), 'single worktree never produces duplicate group' );

	if ( $failures > 0 ) {
		echo "\n{$failures} failure(s) across {$assertions} assertion(s).\n";
		exit( 1 );
	}

	echo "\nAll {$assertions} agent/session lifecycle assertions passed.\n";
}
