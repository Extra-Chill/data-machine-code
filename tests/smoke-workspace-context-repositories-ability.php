<?php
/**
 * Pure-PHP smoke for workspace context repository ability registration.
 *
 * Run: php tests/smoke-workspace-context-repositories-ability.php
 */

declare( strict_types=1 );

namespace DataMachineCode\Abilities {
	class AbilityRegistry
	{
	}
}

namespace DataMachineCode\Support {
	class RuntimeCapabilities
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

	function sanitize_key( string $key ): string
	{
		$key = strtolower($key);
		return preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';
	}

	function update_option( string $name, $value, $autoload = null ): bool
	{
		$GLOBALS['dmc_context_repository_options'][ $name ] = $value;
		$GLOBALS['dmc_context_repository_autoload'][ $name ] = $autoload;
		return true;
	}

	function get_option( string $name, $default = false )
	{
		return $GLOBALS['dmc_context_repository_options'][ $name ] ?? $default;
	}

	function apply_filters( string $tag, $value )
	{
		return $value;
	}

	function wp_json_encode( $data, int $flags = 0 )
	{
		return json_encode($data, $flags);
	}

	require __DIR__ . '/../inc/Abilities/WorkspaceAbilities.php';
	require __DIR__ . '/../inc/Workspace/WorkspaceAliasResolver.php';

	$failures = array();
	$assert   = function ( string $label, bool $condition ) use ( &$failures ): void {
		if ( $condition ) {
			echo "  ok {$label}\n";
			return;
		}

		$failures[] = $label;
		echo "  fail {$label}\n";
	};

	echo "Workspace context repositories ability - smoke\n";

	$result = \DataMachineCode\Abilities\WorkspaceAbilities::registerContextRepositories(
		array(
			'target_repo'      => 'Automattic/build-with-wordpress',
			'target_workspace' => 'build-with-wordpress@skills',
			'access'           => 'readonly',
			'repositories'     => array(
				array(
					'repo'  => 'Automattic/studio',
					'ref'   => 'trunk',
					'alias' => 'studio',
					'paths' => array( 'apps/cli/ai/tools/**', 'apps/cli/ai/tools/**', 'README.md' ),
				),
			),
		)
	);

	$stored = get_option('datamachine_code_context_repositories', array());
	$policy = \DataMachineCode\Workspace\WorkspaceAliasResolver::policy_attestation('studio');

	$assert('registers successfully', is_array($result) && true === ( $result['success'] ?? false ));
	$assert('reports repository count', is_array($result) && 1 === ( $result['count'] ?? 0 ));
	$assert('stores context repositories option', isset($stored['studio']));
	$assert('stores option with autoload disabled', false === ( $GLOBALS['dmc_context_repository_autoload']['datamachine_code_context_repositories'] ?? null ));
	$assert('deduplicates allowed paths', array( 'apps/cli/ai/tools/**', 'README.md' ) === ( $stored['studio']['paths'] ?? array() ));
	$assert('resolver exposes read-only policy', false === ( $policy['writable'] ?? true ) && true === ( $policy['read_only'] ?? false ));
	$assert('resolver keeps repo and ref', 'Automattic/studio' === ( $policy['repo'] ?? '' ) && 'trunk' === ( $policy['ref'] ?? '' ));

	if ( $failures ) {
		echo "\nFailures:\n";
		foreach ( $failures as $failure ) {
			echo " - {$failure}\n";
		}
		exit(1);
	}

	echo "\nOK\n";
}
