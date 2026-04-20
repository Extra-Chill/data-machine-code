<?php
/**
 * Pure-PHP end-to-end smoke test for GitSync Phase 1.
 *
 * Exercises bind (clone) → status → pull → unbind against a real public
 * remote. Runs on native macOS/Linux PHP + git — does not require a full
 * WordPress bootstrap. Needed because Studio WASM can't spawn git clone
 * into its mounted /wordpress filesystem (Studio#3082), so the CLI smoke
 * lives here instead.
 *
 * Run: php tests/smoke-gitsync.php
 *
 * The test uses `mcp-context-wporg` as a small, stable public remote. If
 * you don't have network, skip this smoke and rely on the handle/binding
 * unit checks plus manual in-Studio verification for the non-git surface.
 */

declare( strict_types=1 );

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		// Point ABSPATH at a scratch directory we control so bindings
		// actually land in real writable space during the smoke run.
		$scratch = sys_get_temp_dir() . '/dmc-gitsync-smoke-' . getmypid();
		@mkdir( $scratch, 0755, true );
		define( 'ABSPATH', $scratch . '/' );
	}

	// Minimal WordPress polyfills. Options are held in-memory for the run.
	$GLOBALS['__dmc_options'] = array();

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public string $code;
			public string $message;
			public array $data;
			public function __construct( string $code = '', string $message = '', array $data = array() ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}
			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool { return $thing instanceof \WP_Error; }
	}

	if ( ! function_exists( 'wp_mkdir_p' ) ) {
		function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0755, true ); }
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $name, $default = false ) {
			return $GLOBALS['__dmc_options'][ $name ] ?? $default;
		}
	}

	if ( ! function_exists( 'update_option' ) ) {
		function update_option( string $name, $value, $autoload = null ): bool {
			$GLOBALS['__dmc_options'][ $name ] = $value;
			return true;
		}
	}

	require __DIR__ . '/../inc/Support/GitRunner.php';
	require __DIR__ . '/../inc/Support/PathSecurity.php';
	require __DIR__ . '/../inc/GitSync/GitSyncBinding.php';
	require __DIR__ . '/../inc/GitSync/GitSyncRegistry.php';
	require __DIR__ . '/../inc/GitSync/GitSync.php';

	$failures = 0;
	$total    = 0;

	$assert = function ( $cond, string $message ) use ( &$failures, &$total ): void {
		$total++;
		if ( $cond ) {
			echo "  ✓ {$message}\n";
			return;
		}
		$failures++;
		echo "  ✗ {$message}\n";
	};

	$cleanup = function () use ( $scratch ): void {
		if ( is_dir( $scratch ) ) {
			exec( 'rm -rf ' . escapeshellarg( $scratch ) );
		}
	};

	register_shutdown_function( $cleanup );

	echo "GitSync Phase 1 — end-to-end smoke\n";
	echo "Scratch ABSPATH: " . ABSPATH . "\n\n";

	$gs = new \DataMachineCode\GitSync\GitSync();

	// ---------------------------------------------------------------------
	// 1. Input validation rejects bad inputs.
	// ---------------------------------------------------------------------
	echo "Input validation\n";

	$bad_slug = $gs->bind( array(
		'slug'       => 'Bad Slug!',
		'local_path' => '/scratch/repo/',
		'remote_url' => 'https://github.com/example/repo',
	) );
	$assert( is_wp_error( $bad_slug ) && 'invalid_slug' === $bad_slug->get_error_code(), 'rejects invalid slug' );

	$bad_url = $gs->bind( array(
		'slug'       => 'repo',
		'local_path' => '/scratch/repo/',
		'remote_url' => 'not-a-url',
	) );
	$assert( is_wp_error( $bad_url ) && 'invalid_remote_url' === $bad_url->get_error_code(), 'rejects invalid remote URL' );

	$traversal = $gs->bind( array(
		'slug'       => 'repo',
		'local_path' => '/../../etc/',
		'remote_url' => 'https://github.com/example/repo',
	) );
	$assert( is_wp_error( $traversal ) && 'path_traversal' === $traversal->get_error_code(), 'rejects traversal in local_path' );

	$sensitive = $gs->bind( array(
		'slug'       => 'repo',
		'local_path' => '/.env/',
		'remote_url' => 'https://github.com/example/repo',
	) );
	$assert( is_wp_error( $sensitive ) && 'sensitive_path' === $sensitive->get_error_code(), 'rejects sensitive local_path' );

	// ---------------------------------------------------------------------
	// 2. Real clone against a public repo.
	// ---------------------------------------------------------------------
	echo "\nBind + clone (public repo)\n";

	$remote = 'https://github.com/Automattic/mcp-context-wporg';
	$result = $gs->bind( array(
		'slug'       => 'smoke',
		'local_path' => '/sync/mcp-context-wporg/',
		'remote_url' => $remote,
	) );

	if ( is_wp_error( $result ) ) {
		echo "  ! clone failed: " . $result->get_error_message() . "\n";
		echo "  ! skipping remaining smoke (network or git unavailable)\n";
		exit( $failures > 0 ? 1 : 0 );
	}

	$assert( true === ( $result['success'] ?? false ), 'bind returned success' );
	$assert( true === ( $result['cloned'] ?? false ), 'bind marked path as newly cloned' );
	$assert( false === ( $result['adopted'] ?? true ), 'bind did not mark path as adopted' );
	$assert( is_dir( $result['local_path'] . '/.git' ), '.git/ present after clone' );

	// ---------------------------------------------------------------------
	// 3. Duplicate bind is rejected.
	// ---------------------------------------------------------------------
	echo "\nDuplicate bind\n";
	$dup = $gs->bind( array(
		'slug'       => 'smoke',
		'local_path' => '/sync/mcp-context-wporg/',
		'remote_url' => $remote,
	) );
	$assert( is_wp_error( $dup ) && 'binding_exists' === $dup->get_error_code(), 'refuses duplicate slug' );

	// ---------------------------------------------------------------------
	// 4. Adopt: new binding slug against already-cloned path + matching origin.
	// ---------------------------------------------------------------------
	echo "\nAdopt existing checkout\n";
	$adopt = $gs->bind( array(
		'slug'       => 'smoke-adopt',
		'local_path' => '/sync/mcp-context-wporg/',
		'remote_url' => $remote,
	) );
	$assert( ! is_wp_error( $adopt ), 'adopt bind succeeded' );
	$assert( true === ( $adopt['adopted'] ?? false ), 'adopt flagged as adopted' );
	$assert( false === ( $adopt['cloned'] ?? true ), 'adopt did not re-clone' );

	// Cleanup adopt entry so status/list results stay readable.
	$gs->unbind( 'smoke-adopt' );

	// ---------------------------------------------------------------------
	// 5. Adopt rejection: mismatched origin.
	// ---------------------------------------------------------------------
	echo "\nReject adopt on origin mismatch\n";
	$mismatch = $gs->bind( array(
		'slug'       => 'smoke-mismatch',
		'local_path' => '/sync/mcp-context-wporg/',
		'remote_url' => 'https://github.com/example/other-repo',
	) );
	$assert( is_wp_error( $mismatch ) && 'origin_mismatch' === $mismatch->get_error_code(), 'rejects adopt on origin mismatch' );

	// ---------------------------------------------------------------------
	// 6. Status reports repo + branch + head.
	// ---------------------------------------------------------------------
	echo "\nStatus\n";
	$status = $gs->status( 'smoke' );
	$assert( ! is_wp_error( $status ), 'status succeeded' );
	$assert( true === ( $status['exists'] ?? false ), 'status reports exists' );
	$assert( true === ( $status['is_repo'] ?? false ), 'status reports is_repo' );
	$assert( is_string( $status['head'] ?? null ) && '' !== $status['head'], 'status has HEAD hash' );
	$assert( 0 === ( $status['ahead'] ?? -1 ), 'status ahead=0 on fresh clone' );

	// ---------------------------------------------------------------------
	// 7. Pull on an already-up-to-date tree is idempotent and succeeds.
	// ---------------------------------------------------------------------
	echo "\nPull (idempotent on clean tree)\n";
	$pull = $gs->pull( 'smoke' );
	$assert( ! is_wp_error( $pull ), 'pull succeeded' );
	$assert( ( $pull['previous_head'] ?? null ) === ( $pull['head'] ?? null ), 'pull did not advance HEAD on clean tree' );

	// ---------------------------------------------------------------------
	// 8. Dirty-tree pull fails under default policy.
	// ---------------------------------------------------------------------
	echo "\nDirty-tree pull refusal\n";
	file_put_contents( $result['local_path'] . '/SMOKE_DIRTY_FILE', 'dirty' );
	$dirty = $gs->pull( 'smoke' );
	$assert( is_wp_error( $dirty ) && 'dirty_working_tree' === $dirty->get_error_code(), 'pull refuses on dirty tree with fail policy' );

	$allow = $gs->pull( 'smoke', true );
	$assert( ! is_wp_error( $allow ), 'pull succeeds with allow_dirty=true' );

	unlink( $result['local_path'] . '/SMOKE_DIRTY_FILE' );

	// ---------------------------------------------------------------------
	// 9. List reports both bindings before + only remaining after unbind.
	// ---------------------------------------------------------------------
	echo "\nList + unbind\n";
	$list = $gs->list_bindings();
	$assert( 1 === count( $list['bindings'] ?? array() ), 'list reports 1 binding' );

	$unbind = $gs->unbind( 'smoke' );
	$assert( ! is_wp_error( $unbind ), 'unbind succeeded' );
	$assert( false === ( $unbind['purged'] ?? true ), 'unbind preserved directory by default' );
	$assert( is_dir( $result['local_path'] . '/.git' ), 'working tree preserved after unbind' );

	$list_after = $gs->list_bindings();
	$assert( 0 === count( $list_after['bindings'] ?? array() ), 'list empty after unbind' );

	// ---------------------------------------------------------------------
	// 10. Re-bind + purge wipes the directory.
	// ---------------------------------------------------------------------
	echo "\nRebind + purge\n";
	$rebind = $gs->bind( array(
		'slug'       => 'smoke',
		'local_path' => '/sync/mcp-context-wporg/',
		'remote_url' => $remote,
	) );
	$assert( ! is_wp_error( $rebind ), 'rebind adopted existing tree' );
	$assert( true === ( $rebind['adopted'] ?? false ), 'rebind flagged as adopted' );

	$purge = $gs->unbind( 'smoke', true );
	$assert( ! is_wp_error( $purge ), 'purge unbind succeeded' );
	$assert( true === ( $purge['purged'] ?? false ), 'purge flag set' );
	$assert( ! is_dir( $result['local_path'] ), 'working tree removed by purge' );

	echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
	exit( $failures > 0 ? 1 : 0 );
}
