<?php
/**
 * Smoke test for remote workspace backend override filter.
 *
 * Run: php tests/smoke-remote-workspace-backend-filter.php
 */

declare( strict_types=1 );

namespace DataMachineCode\Support {
	class GitRunner
	{
		public static bool $available = true;

		public static function is_available(): bool
		{
			return self::$available;
		}
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/');
	}

	$GLOBALS['dmc_remote_backend_filters'] = array();

	function add_filter( string $tag, callable $callback, int $priority = 10 ): void
	{
		$GLOBALS['dmc_remote_backend_filters'][ $tag ][ $priority ][] = $callback;
	}

	function apply_filters( string $tag, $value )
	{
		if ( empty($GLOBALS['dmc_remote_backend_filters'][ $tag ]) ) {
			return $value;
		}

		ksort($GLOBALS['dmc_remote_backend_filters'][ $tag ]);
		foreach ( $GLOBALS['dmc_remote_backend_filters'][ $tag ] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$value = $callback($value);
			}
		}

		return $value;
	}

	require __DIR__ . '/../inc/Workspace/RemoteWorkspaceBackend.php';

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

	echo "Remote workspace backend filter - smoke\n";

	\DataMachineCode\Support\GitRunner::$available = true;
	$assert('defaults to local git when git is available', false === \DataMachineCode\Workspace\RemoteWorkspaceBackend::should_handle());

	add_filter('datamachine_code_remote_workspace_backend_should_handle', static fn(): bool => true, 100);
	$assert('filter can force remote backend on', true === \DataMachineCode\Workspace\RemoteWorkspaceBackend::should_handle());

	$GLOBALS['dmc_remote_backend_filters'] = array();
	\DataMachineCode\Support\GitRunner::$available = false;
	$assert('defaults to remote backend when git is unavailable', true === \DataMachineCode\Workspace\RemoteWorkspaceBackend::should_handle());

	add_filter('datamachine_code_remote_workspace_backend_should_handle', static fn(): bool => false, 100);
	$assert('filter can force mounted local backend on', false === \DataMachineCode\Workspace\RemoteWorkspaceBackend::should_handle());

	if ( $failures ) {
		echo "\nFailures:\n";
		foreach ( $failures as $failure ) {
			echo " - {$failure}\n";
		}
		exit(1);
	}

	echo "\nOK ({$passes} assertions)\n";
}
