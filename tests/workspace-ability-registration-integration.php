<?php

declare(strict_types=1);

if ( 'worker' === ( $argv[1] ?? '' ) ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
	define('WPINC', 'wp-includes');
	define('WP_CLI', true);

	$GLOBALS['dmc_ability_actions']    = array();
	$GLOBALS['dmc_ability_doing']      = array();
	$GLOBALS['dmc_ability_did']        = array();
	$GLOBALS['dmc_ability_registry']   = array();
	$GLOBALS['dmc_ability_categories'] = array();
	$GLOBALS['argv'] = array( 'wp', 'datamachine-code', 'workspace', 'worktree', 'add', 'fixture', 'fix/1085' );

	function add_action( string $hook, callable|string|array $callback, int $priority = 10 ): void {
		$GLOBALS['dmc_ability_actions'][ $hook ][] = array( 'callback' => $callback, 'priority' => $priority );
	}
	function add_filter( string $hook, callable|string|array $callback, int $priority = 10 ): void {}
	function doing_action( string $hook ): bool { return ! empty($GLOBALS['dmc_ability_doing'][ $hook ]); }
	function did_action( string $hook ): int { return (int) ( $GLOBALS['dmc_ability_did'][ $hook ] ?? 0 ); }
	function dmc_ability_do_action( string $hook ): void {
		$GLOBALS['dmc_ability_did'][ $hook ] = (int) ( $GLOBALS['dmc_ability_did'][ $hook ] ?? 0 ) + 1;
		$GLOBALS['dmc_ability_doing'][ $hook ] = true;
		$callbacks = $GLOBALS['dmc_ability_actions'][ $hook ] ?? array();
		usort($callbacks, static fn ( array $left, array $right ): int => $left['priority'] <=> $right['priority']);
		foreach ( $callbacks as $callback ) {
			call_user_func($callback['callback']);
		}
		unset($GLOBALS['dmc_ability_doing'][ $hook ]);
	}
	function plugin_dir_path( string $file ): string { return dirname($file) . '/'; }
	function plugin_dir_url( string $file ): string { return 'https://example.test/'; }
	function register_activation_hook( string $file, callable|string $callback ): void {}
	function __( string $text, string $domain = '' ): string { return $text; }
	function wp_register_ability_category( string $slug, array $args ): void { $GLOBALS['dmc_ability_categories'][ $slug ] = $args; }
	function wp_has_ability_category( string $slug ): bool { return isset($GLOBALS['dmc_ability_categories'][ $slug ]); }
	function wp_register_ability( string $slug, array $args ): void {
		if ( ! doing_action('wp_abilities_api_init') ) {
			throw new RuntimeException('Ability registered outside the Core lifecycle.');
		}
		$GLOBALS['dmc_ability_registry'][ $slug ] = $args;
	}
	function wp_has_ability( string $slug ): bool { return isset($GLOBALS['dmc_ability_registry'][ $slug ]); }
	function wp_get_abilities( array $args = array() ): array {
		$namespace = isset($args['namespace']) ? rtrim((string) $args['namespace'], '/') . '/' : '';
		return array_filter($GLOBALS['dmc_ability_registry'], static fn ( mixed $ability, string $slug ): bool => '' === $namespace || str_starts_with($slug, $namespace), ARRAY_FILTER_USE_BOTH);
	}
	function wp_get_ability( string $slug ): mixed {
		if ( ! did_action('wp_abilities_api_init') ) {
			dmc_ability_do_action('wp_abilities_api_categories_init');
			dmc_ability_do_action('wp_abilities_api_init');
		}
		return $GLOBALS['dmc_ability_registry'][ $slug ] ?? null;
	}

	if ( 'closed' === ( $argv[2] ?? '' ) ) {
		dmc_ability_do_action('wp_abilities_api_categories_init');
		dmc_ability_do_action('wp_abilities_api_init');
	}

	require_once dirname(__DIR__) . '/data-machine-code.php';
	if ( 'closed' === ( $argv[2] ?? '' ) ) {
		$diagnostic = \DataMachineCode\Abilities\WorkspaceAbilities::unavailable_diagnostic('datamachine-code/workspace-list');
		if ( 'closed' !== ( $diagnostic['registration_phase'] ?? null ) || 'scheduled' !== ( $diagnostic['workspace_registration_state'] ?? null ) || ! empty($diagnostic['registered_siblings']) ) {
			throw new RuntimeException('Unavailable-ability diagnostic did not distinguish a closed registration lifecycle.');
		}
		echo json_encode(array( 'diagnostic' => $diagnostic ), JSON_THROW_ON_ERROR) . "\n";
		exit(0);
	}

	$expected = array(
		'datamachine-code/workspace-list',
		'datamachine-code/workspace-worktree-add',
		'datamachine-code/workspace-worktree-handoff-revalidate',
		'datamachine-code/workspace-worktree-list',
		'datamachine-code/workspace-git-pull',
	);
	foreach ( $expected as $ability ) {
		if ( ! wp_get_ability($ability) ) {
			throw new RuntimeException(sprintf('Expected workspace ability %s was not registered.', $ability));
		}
	}
	$proof_fields = array( 'version', 'proof_id', 'handle', 'worktree_sha', 'resolved_base_ref', 'resolved_base_sha', 'remote_default_ref', 'remote_default_sha', 'digest' );
	$add_freshness = (array) ( $GLOBALS['dmc_ability_registry']['datamachine-code/workspace-worktree-add']['output_schema']['properties']['handoff_freshness'] ?? array() );
	$add_proof = (array) ( $add_freshness['properties']['proof'] ?? array() );
	$revalidate = (array) ( $GLOBALS['dmc_ability_registry']['datamachine-code/workspace-worktree-handoff-revalidate'] ?? array() );
	$input_proof = (array) ( $revalidate['input_schema']['properties']['proof'] ?? array() );
	$output_proof = (array) ( $revalidate['output_schema']['properties']['proof'] ?? array() );
	foreach ( array( $add_proof, $input_proof, $output_proof ) as $schema ) {
		if ( $proof_fields !== array_keys((array) ( $schema['properties'] ?? array() )) || $proof_fields !== (array) ( $schema['required'] ?? array() ) ) {
			throw new RuntimeException('Handoff proof schema did not expose its exact required fields.');
		}
	}
	if ( array( 'success', 'handoff_freshness' ) !== (array) ( $GLOBALS['dmc_ability_registry']['datamachine-code/workspace-worktree-add']['output_schema']['required'] ?? array() ) || array( 'status' ) !== (array) ( $add_freshness['required'] ?? array() ) || array( 'verified', 'unverified', 'not_applicable' ) !== (array) ( $add_freshness['properties']['status']['enum'] ?? array() ) ) {
		throw new RuntimeException('Worktree add did not require the typed handoff freshness contract.');
	}
	$status = (array) ( $revalidate['output_schema']['properties']['status']['enum'] ?? array() );
	$errors = (array) ( $revalidate['output_schema']['properties']['error']['properties']['code']['enum'] ?? array() );
	if ( array( 'current', 'drift', 'fetch_failed', 'contention' ) !== $status || array( 'invalid_worktree_handoff_proof', 'untrusted_worktree_handoff_proof', 'worktree_handoff_revalidation_timeout', 'remote_default_unresolved', 'worktree_handoff_base_unresolved' ) !== $errors ) {
		throw new RuntimeException('Handoff revalidation schema omitted typed statuses or errors.');
	}
	$diagnostic = \DataMachineCode\Abilities\WorkspaceAbilities::unavailable_diagnostic('datamachine-code/workspace-unsupported');
	if ( 'datamachine_code_ability_unavailable' !== ( $diagnostic['code'] ?? null ) || 1 !== ( $diagnostic['registration_generation'] ?? null ) || ! in_array('datamachine-code/workspace-worktree-add', $diagnostic['registered_siblings'] ?? array(), true) ) {
		throw new RuntimeException('Unavailable-ability diagnostic omitted lifecycle or sibling information.');
	}
	echo json_encode(array( 'abilities' => array_keys($GLOBALS['dmc_ability_registry']), 'diagnostic' => $diagnostic ), JSON_THROW_ON_ERROR) . "\n";
	exit(0);
}

function workspace_ability_integration_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$command = array( PHP_BINARY, __FILE__, 'worker' );
$workers = array();
for ( $index = 0; $index < 4; ++$index ) {
	$workers[] = proc_open($command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes);
	workspace_ability_integration_assert(is_resource($workers[ $index ]), 'Could not start parallel ability-registration worker.');
	$worker_pipes[ $index ] = $pipes;
}

$results = array();
foreach ( $workers as $index => $worker ) {
	$output = stream_get_contents($worker_pipes[ $index ][1]);
	$error  = stream_get_contents($worker_pipes[ $index ][2]);
	$status = proc_close($worker);
	workspace_ability_integration_assert(0 === $status, sprintf('Ability-registration worker failed: %s', $error));
	$results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

$closed = proc_open(array( PHP_BINARY, __FILE__, 'worker', 'closed' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $closed_pipes);
workspace_ability_integration_assert(is_resource($closed), 'Could not start closed-lifecycle diagnostic worker.');
$closed_output = stream_get_contents($closed_pipes[1]);
$closed_error  = stream_get_contents($closed_pipes[2]);
$closed_status = proc_close($closed);
workspace_ability_integration_assert(0 === $closed_status, sprintf('Closed-lifecycle diagnostic worker failed: %s', $closed_error));
$closed_result = json_decode($closed_output, true, 512, JSON_THROW_ON_ERROR);
workspace_ability_integration_assert('closed' === ( $closed_result['diagnostic']['registration_phase'] ?? null ), 'Closed-lifecycle diagnostic did not report its registration phase.');

$baseline = $results[0]['abilities'];
foreach ( $results as $result ) {
	workspace_ability_integration_assert($baseline === $result['abilities'], 'Repeated parallel requests registered different workspace ability sets.');
}

echo "workspace-ability-registration-integration: ok\n";
