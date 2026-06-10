<?php
/**
 * Pure-PHP smoke for workspace clone ability fallback routing.
 *
 * Run: php tests/smoke-workspace-clone-ability-fallback.php
 */

declare( strict_types=1 );

namespace DataMachineCode\Abilities {
	class AbilityRegistry
	{
	}
}

namespace DataMachineCode\Workspace {
	class RemoteWorkspaceBackend
	{
		public static array $clone_input = array();

		public static function should_handle(): bool
		{
			return false;
		}

		public function clone_repo( string $url, ?string $name = null ): array
		{
			self::$clone_input = compact('url', 'name');
			return array(
				'success' => true,
				'backend' => 'github_api',
				'name'    => (string) $name,
				'path'    => 'github://Extra-Chill/data-machine-code',
				'message' => 'Registered remote workspace.',
			);
		}
	}

	class Workspace
	{
		public static array $clone_input = array();

		public function clone_repo( string $url, ?string $name = null, array $options = array() ): \WP_Error
		{
			self::$clone_input = compact('url', 'name', 'options');
			return new \WP_Error(
				'datamachine_workspace_git_unavailable',
				'Clone workspace repository cannot run with the current workspace backend.'
			);
		}
	}
}

namespace DataMachineCode\Support {
	class RuntimeCapabilities
	{
	}

	class GitRunner
	{
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__);
	}

	class WP_Error
	{
		public function __construct( private string $code, private string $message, private array $data = array() )
		{
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

	require __DIR__ . '/../inc/Abilities/WorkspaceAbilities.php';

	$failures = array();
	$assert   = function ( string $label, bool $condition ) use ( &$failures ): void {
		if ( $condition ) {
			echo "  ok {$label}\n";
			return;
		}

		$failures[] = $label;
		echo "  fail {$label}\n";
	};

	echo "Workspace clone ability fallback - smoke\n";

	$result = \DataMachineCode\Abilities\WorkspaceAbilities::cloneRepo(
		array(
			'url'                    => 'https://github.com/Extra-Chill/data-machine-code.git',
			'name'                   => 'data-machine-code',
			'allow_duplicate_remote' => true,
		)
	);

	$assert('local clone path was attempted first', 'https://github.com/Extra-Chill/data-machine-code.git' === ( \DataMachineCode\Workspace\Workspace::$clone_input['url'] ?? '' ));
	$assert('local clone receives duplicate remote option', true === ( \DataMachineCode\Workspace\Workspace::$clone_input['options']['allow_duplicate_remote'] ?? false ));
	$assert('remote fallback receives clone URL', 'https://github.com/Extra-Chill/data-machine-code.git' === ( \DataMachineCode\Workspace\RemoteWorkspaceBackend::$clone_input['url'] ?? '' ));
	$assert('remote fallback receives clone name', 'data-machine-code' === ( \DataMachineCode\Workspace\RemoteWorkspaceBackend::$clone_input['name'] ?? '' ));
	$assert('fallback returns remote backend result', is_array($result) && 'github_api' === ( $result['backend'] ?? '' ));
	$assert('fallback keeps clone guidance', is_array($result) && 'workspace_worktree_add' === ( $result['next_required_tool'] ?? '' ));

	if ( $failures ) {
		echo "\nFailures:\n";
		foreach ( $failures as $failure ) {
			echo " - {$failure}\n";
		}
		exit(1);
	}

	echo "\nOK\n";
}
