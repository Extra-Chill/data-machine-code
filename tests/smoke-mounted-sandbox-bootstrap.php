<?php
/**
 * Smoke test for mounted sandbox self-configuration.
 *
 * Run: php tests/smoke-mounted-sandbox-bootstrap.php
 */

declare( strict_types=1 );

namespace DataMachineCode\Abilities {
	class WorkspaceAbilities {
		public static array $adopted = array();

		public static function adoptRepo( array $input ): array {
			self::$adopted[] = $input;
			return array( 'success' => true ) + $input;
		}
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/');
	}

	$GLOBALS['dmc_mounted_sandbox_actions'] = array();
	$GLOBALS['dmc_mounted_sandbox_filters'] = array();

	function add_action( string $tag, callable $callback, int $priority = 10 ): void {
		$GLOBALS['dmc_mounted_sandbox_actions'][ $tag ][ $priority ][] = $callback;
	}

	function do_action( string $tag, ...$args ): void {
		if ( empty($GLOBALS['dmc_mounted_sandbox_actions'][ $tag ]) ) {
			return;
		}
		ksort($GLOBALS['dmc_mounted_sandbox_actions'][ $tag ]);
		foreach ( $GLOBALS['dmc_mounted_sandbox_actions'][ $tag ] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$callback(...$args);
			}
		}
	}

	function add_filter( string $tag, callable|string $callback, int $priority = 10 ): void {
		$GLOBALS['dmc_mounted_sandbox_filters'][ $tag ][ $priority ][] = $callback;
	}

	function apply_filters( string $tag, $value ) {
		if ( empty($GLOBALS['dmc_mounted_sandbox_filters'][ $tag ]) ) {
			return $value;
		}
		ksort($GLOBALS['dmc_mounted_sandbox_filters'][ $tag ]);
		foreach ( $GLOBALS['dmc_mounted_sandbox_filters'][ $tag ] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$value = is_string($callback) && '__return_false' === $callback ? false : $callback($value);
			}
		}
		return $value;
	}

	function wp_mkdir_p( string $path ): bool {
		return is_dir($path) || mkdir($path, 0775, true);
	}

	require __DIR__ . '/../inc/Runtime/MountedSandboxBootstrap.php';

	$failures = array();
	$passes   = 0;
	$assert   = function ( string $label, bool $condition ) use ( &$failures, &$passes ): void {
		if ( $condition ) {
			++$passes;
			echo "  ok {$label}\n";
			return;
		}

		$failures[] = $label;
		echo "  fail {$label}\n";
	};

	echo "Mounted sandbox bootstrap - smoke\n";

	$root        = sys_get_temp_dir() . '/dmc-mounted-sandbox-' . bin2hex(random_bytes(4));
	$repo_backed = $root . '/repo-backed';
	$local_repo  = $root . '/local-repo';
	mkdir($repo_backed . '/.git', 0775, true);
	mkdir($local_repo . '/.git', 0775, true);
	register_shutdown_function(
		static function () use ( $root ): void {
			if ( is_dir($root) ) {
				exec('rm -rf ' . escapeshellarg($root));
			}
		}
	);

	\DataMachineCode\Runtime\MountedSandboxBootstrap::register();
	do_action(
		'wp_codebox_sandbox_runtime_bootstrap',
		array(
			'workspace_root'     => $root,
			'sandbox_workspace' => array(
				'root'   => $root,
				'mounts' => array(
					array( 'target' => $repo_backed, 'sourceMode' => 'repo-backed' ),
					array( 'target' => $local_repo, 'sourceMode' => 'local' ),
				),
			),
		)
	);

	$assert('defines workspace path from generic sandbox context', defined('DATAMACHINE_WORKSPACE_PATH') && DATAMACHINE_WORKSPACE_PATH === $root);
	$assert('forces mounted local workspace backend', false === apply_filters('datamachine_code_remote_workspace_backend_should_handle', true));

	do_action('wp_abilities_api_init');
	$adopted = \DataMachineCode\Abilities\WorkspaceAbilities::$adopted;
	$assert('adopts exactly one non repo-backed workspace mount', 1 === count($adopted));
	$assert('adopts the local workspace mount', isset($adopted[0]['path']) && $adopted[0]['path'] === $local_repo);

	if ( $failures ) {
		echo "\nFailures:\n";
		foreach ( $failures as $failure ) {
			echo " - {$failure}\n";
		}
		exit(1);
	}

	echo "\nOK ({$passes} assertions)\n";
}
