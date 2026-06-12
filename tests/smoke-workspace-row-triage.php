<?php
/**
 * Smoke test for workspace row triage reporting and metadata-only actions.
 *
 * Run: php tests/smoke-workspace-row-triage.php
 */

declare( strict_types=1 );

namespace DataMachine\Core\FilesRepository {
	if ( ! class_exists(__NAMESPACE__ . '\\FilesystemHelper') ) {
		class FilesystemHelper {
			public static function get() {
				return null;
			}
		}
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/');
	}

	if ( ! class_exists('WP_Error') ) {
		class WP_Error {
			public string $code;
			public string $message;
			public array $data;

			public function __construct( $code = '', $message = '', $data = array() ) {
				$this->code    = (string) $code;
				$this->message = (string) $message;
				$this->data    = (array) $data;
			}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}
		}
	}

	if ( ! function_exists('is_wp_error') ) {
		function is_wp_error( $thing ): bool {
			return $thing instanceof \WP_Error;
		}
	}

	if ( ! function_exists('wp_mkdir_p') ) {
		function wp_mkdir_p( string $path ): bool {
			return is_dir($path) || mkdir($path, 0755, true);
		}
	}

	if ( ! function_exists('get_option') ) {
		function get_option( string $name, $default = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			global $datamachine_code_test_options;
			return $datamachine_code_test_options[ $name ] ?? $default;
		}
	}

	if ( ! function_exists('update_option') ) {
		function update_option( string $name, $value, $autoload = null ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			global $datamachine_code_test_options;
			$datamachine_code_test_options[ $name ] = $value;
			return true;
		}
	}

	if ( ! function_exists('current_time') ) {
		function current_time( string $type, bool $gmt = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return gmdate('Y-m-d H:i:s');
		}
	}

	if ( ! function_exists('wp_json_encode') ) {
		function wp_json_encode( $value, int $flags = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return json_encode($value, $flags);
		}
	}

	if ( ! function_exists('apply_filters') ) {
		function apply_filters( string $hook_name, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return $value;
		}
	}

	include __DIR__ . '/../inc/Support/GitHubRemote.php';
	include __DIR__ . '/../inc/Support/GitRunner.php';
	include __DIR__ . '/../inc/Support/PathSecurity.php';
	include __DIR__ . '/../inc/Workspace/WorkspaceMutationLock.php';
	include __DIR__ . '/../inc/Workspace/WorktreeDiskBudget.php';
	include __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';
	include __DIR__ . '/../inc/Storage/WorktreeInventoryRepository.php';
	include __DIR__ . '/../inc/Workspace/Workspace.php';

	exec('git --version 2>&1', $_git_version, $git_version_exit);
	if ( 0 !== $git_version_exit ) {
		echo "SKIP: git not available\n";
		exit(0);
	}

	$tmp = sys_get_temp_dir() . '/dmc-row-triage-smoke-' . bin2hex(random_bytes(4));
	mkdir($tmp, 0755, true);
	register_shutdown_function(
		function () use ( $tmp ) {
			if ( is_dir($tmp) ) {
				exec('rm -rf ' . escapeshellarg($tmp));
			}
		}
	);

	define('DATAMACHINE_WORKSPACE_PATH', realpath($tmp) ?: $tmp);

	$failures = 0;
	$total    = 0;
	$assert   = function ( $expected, $actual, string $message ) use ( &$failures, &$total ): void {
		++$total;
		if ( $expected === $actual ) {
			echo "  ✓ {$message}\n";
			return;
		}
		++$failures;
		echo "  ✗ {$message}\n";
		echo '    expected: ' . var_export($expected, true) . "\n";
		echo '    actual:   ' . var_export($actual, true) . "\n";
	};

	$run = function ( string $cmd, string $cwd = '' ): array {
		$full = '' === $cwd ? $cmd : sprintf('cd %s && %s', escapeshellarg($cwd), $cmd);
		exec($full . ' 2>&1', $out, $rc);
		return array(
			'output' => implode("\n", $out),
			'exit'   => $rc,
		);
	};

	$make_repo = function ( string $name ) use ( $tmp, $run ): string {
		$path = $tmp . '/' . $name;
		mkdir($path, 0755, true);
		$run('git init', $path);
		$run('git config user.email test@example.com', $path);
		$run('git config user.name test', $path);
		file_put_contents($path . '/README.md', $name . "\n");
		$run('git add README.md && git commit -m init', $path);
		return $path;
	};

	echo "Setting up workspace at {$tmp}\n";
	$primary      = $make_repo('canonical-plugin');
	$noncanonical = $make_repo('bad@@name');
	$non_git      = $tmp . '/not-git';
	mkdir($non_git, 0755, true);

	$run('git branch external-branch', $primary);
	$external = sys_get_temp_dir() . '/dmc-row-triage-external-' . bin2hex(random_bytes(4));
	register_shutdown_function(
		function () use ( $external ) {
			if ( is_dir($external) ) {
				exec('rm -rf ' . escapeshellarg($external));
			}
		}
	);
	$run(sprintf('git worktree add %s external-branch', escapeshellarg($external)), $primary);

	$ws = new \DataMachineCode\Workspace\Workspace();

	echo "\nList unresolved rows\n";
	$list = $ws->workspace_row_triage_list();
	$assert(true, ! is_wp_error($list), 'triage list succeeds');
	$rows = is_wp_error($list) ? array() : (array) $list['rows'];
	$ids  = array_map(static fn( array $row ): string => (string) $row['row_id'], $rows);
	$assert(true, in_array('not-git', $ids, true), 'non-git row is listed');
	$assert(true, in_array('bad@@name', $ids, true), 'noncanonical row is listed');
	$assert(true, 1 === count(array_filter($ids, static fn( string $id ): bool => str_starts_with($id, 'external:'))), 'external worktree row is listed');
	$assert(1, (int) ( $list['summary']['non_git'] ?? 0 ), 'summary counts non-git rows');
	$assert(1, (int) ( $list['summary']['external_worktree'] ?? 0 ), 'summary counts external rows');

	$external_id = (string) array_values(array_filter($ids, static fn( string $id ): bool => str_starts_with($id, 'external:')))[0];

	echo "\nQuarantine external row\n";
	$marked = $ws->workspace_row_triage_mark($external_id, 'quarantined', 'raw git worktree outside DMC ownership');
	$assert(true, ! is_wp_error($marked) && ( $marked['success'] ?? false ), 'quarantine metadata writes');
	$assert('quarantined', is_wp_error($marked) ? '' : (string) ( $marked['row']['triage_status'] ?? '' ), 'quarantined row status is returned');

	$unresolved_after_mark = $ws->workspace_row_triage_list();
	$ids_after_mark        = is_wp_error($unresolved_after_mark) ? array() : array_map(static fn( array $row ): string => (string) $row['row_id'], (array) $unresolved_after_mark['rows']);
	$assert(false, in_array($external_id, $ids_after_mark, true), 'quarantined row leaves unresolved default list');

	echo "\nAdopt canonical primary\n";
	$adopted = $ws->workspace_row_triage_adopt('canonical-plugin');
	$assert(true, ! is_wp_error($adopted) && ( $adopted['success'] ?? false ), 'canonical primary can be adopted');
	$assert('canonical-plugin', is_wp_error($adopted) ? '' : (string) ( $adopted['adopt']['name'] ?? '' ), 'adopted name is canonical handle');

	$non_git_adopt = $ws->workspace_row_triage_adopt('not-git');
	$assert('triage_adopt_non_git_unsupported', is_wp_error($non_git_adopt) ? $non_git_adopt->get_error_code() : '', 'non-git adoption is rejected');

	echo "\nCleanup and reconciliation evidence separates quarantines\n";
	\DataMachineCode\Workspace\WorktreeContextInjector::store_lifecycle_metadata(
		'canonical-plugin@triaged',
		array(
			'handle'            => 'canonical-plugin@triaged',
			'repo'              => 'canonical-plugin',
			'branch'            => 'triaged',
			'path'              => $tmp . '/canonical-plugin@triaged',
			'created_at'        => gmdate('c', time() - 86400),
			'lifecycle_state'   => 'active',
			'triage_status'     => 'quarantined',
			'triage_reason'     => 'operator review pending',
			'triage_updated_at' => gmdate('c'),
		)
	);
	mkdir($tmp . '/canonical-plugin@triaged', 0755, true);

	$cleanup = $ws->worktree_cleanup_merged(array( 'dry_run' => true, 'inventory_only' => true ));
	$assert(1, is_wp_error($cleanup) ? -1 : (int) ( $cleanup['summary']['skipped_by_reason']['triage_quarantined'] ?? 0 ), 'inventory cleanup counts triage quarantines separately');
	$assert(1, is_wp_error($cleanup) ? -1 : (int) ( $cleanup['summary']['cleanup_buckets']['intentional_triage'] ?? 0 ), 'cleanup bucket exposes intentional triage');

	$reconcile = $ws->worktree_reconcile_metadata(array( 'dry_run' => true ));
	$assert(1, is_wp_error($reconcile) ? -1 : (int) ( $reconcile['summary']['prefiltered']['reasons']['triage_quarantined'] ?? 0 ), 'metadata reconciliation prefilter separates quarantines');

	echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
	exit($failures > 0 ? 1 : 0);
}
