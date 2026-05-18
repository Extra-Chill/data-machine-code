<?php
/**
 * Pure-PHP smoke for Sandbox Runtime agent sandbox runner.
 *
 * Run: php tests/smoke-sandbox-runtime-agent-sandbox-runner.php
 */

declare( strict_types=1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/dmc-sandbox-runtime-agent-sandbox/' );
	}

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
			public function get_error_data(): array { return $this->data; }
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
	}

	require __DIR__ . '/../inc/Runtime/SandboxRuntimeAgentSandboxRunner.php';

	use DataMachineCode\Runtime\SandboxRuntimeAgentSandboxRunner;

	$root = sys_get_temp_dir() . '/dmc-sandbox-runtime-agent-sandbox-' . getmypid();
	foreach ( array( 'agents-api', 'data-machine', 'data-machine-code', 'ai-provider-for-openai', 'artifacts' ) as $dir ) {
		mkdir( $root . '/' . $dir, 0777, true );
	}
	file_put_contents( $root . '/sandbox-runtime.js', "#!/usr/bin/env node\n" );

	$failures = 0;
	$total    = 0;
	$assert   = function ( bool $condition, string $message ) use ( &$failures, &$total ): void {
		++$total;
		if ( $condition ) {
			echo "  ok {$message}\n";
			return;
		}

		++$failures;
		echo "  fail {$message}\n";
	};

	echo "Sandbox Runtime agent sandbox runner - smoke\n\n";

	$captured_command = '';
	$runner           = new SandboxRuntimeAgentSandboxRunner(
		array(
			'shell_available' => fn() => true,
			'command_runner'  => function ( string $command ) use ( &$captured_command ): array {
				$captured_command = $command;
				return array(
					'exit_code' => 0,
					'output'    => "Preparing runtime\n" . json_encode(
						array(
							'success'   => true,
							'runtime'   => array( 'backend' => 'wordpress-playground' ),
							'execution' => array( 'command' => 'agent-sandbox-run' ),
							'artifacts' => array( 'manifest' => 'run.json' ),
						)
					),
				);
			},
		)
	);

	$result = $runner->run(
		array(
			'task'                   => 'Run an isolated coding sandbox task.',
			'agents_api_path'        => $root . '/agents-api',
			'data_machine_path'      => $root . '/data-machine',
			'data_machine_code_path' => $root . '/data-machine-code',
			'openai_provider_path'   => $root . '/ai-provider-for-openai',
			'artifacts_path'         => $root . '/artifacts',
			'sandbox_runtime_bin'    => 'sandbox-runtime',
			'wp'                     => 'trunk',
			'code'                   => 'echo "sandbox ok";',
		)
	);

	$assert( ! is_wp_error( $result ), 'happy path returns a result array' );
	$assert( true === ( $result['success'] ?? false ), 'success flag is included' );
	$assert( 'data-machine-code/run-agent-sandbox/v1' === ( $result['schema'] ?? '' ), 'schema is pinned' );
	$assert( 'Run an isolated coding sandbox task.' === ( $result['task'] ?? '' ), 'task is carried through' );
	$assert( 'trunk' === ( $result['wp'] ?? '' ), 'WordPress version is carried through' );
	$assert( $root . '/artifacts' === ( $result['artifacts'] ?? '' ), 'artifact path is carried through' );
	$assert( true === ( $result['run']['success'] ?? false ), 'CLI JSON output is decoded' );
	$assert( 'wordpress-playground' === ( $result['run']['runtime']['backend'] ?? '' ), 'sandbox runtime metadata is preserved' );
	$assert( str_contains( $captured_command, 'agent-sandbox-run' ), 'command invokes agent-sandbox-run' );
	$assert( str_contains( $captured_command, '--task' ), 'command includes task text' );
	$assert( str_contains( $captured_command, '--code' ), 'command includes optional task code' );
	$assert( str_contains( $captured_command, '--agents-api' ), 'command includes Agents API mount path' );
	$assert( str_contains( $captured_command, '--json' ), 'command requests JSON output' );

	$runner->run(
		array(
			'task'                   => 'Run with JS CLI.',
			'agents_api_path'        => $root . '/agents-api',
			'data_machine_path'      => $root . '/data-machine',
			'data_machine_code_path' => $root . '/data-machine-code',
			'openai_provider_path'   => $root . '/ai-provider-for-openai',
			'sandbox_runtime_bin'    => $root . '/sandbox-runtime.js',
		)
	);
	$assert( str_contains( $captured_command, 'node ' ), 'JS CLI path is run through node' );

	$missing = $runner->run(
		array(
			'task'                   => 'Run missing path test.',
			'agents_api_path'        => $root . '/missing',
			'data_machine_path'      => $root . '/data-machine',
			'data_machine_code_path' => $root . '/data-machine-code',
			'openai_provider_path'   => $root . '/ai-provider-for-openai',
		)
	);
	$assert( is_wp_error( $missing ), 'missing plugin path fails closed' );
	$assert( is_wp_error( $missing ) && 'datamachine_code_sandbox_path_missing' === $missing->get_error_code(), 'missing path error code is explicit' );

	$missing_task = $runner->run(
		array(
			'agents_api_path'        => $root . '/agents-api',
			'data_machine_path'      => $root . '/data-machine',
			'data_machine_code_path' => $root . '/data-machine-code',
			'openai_provider_path'   => $root . '/ai-provider-for-openai',
		)
	);
	$assert( is_wp_error( $missing_task ), 'missing task fails closed' );
	$assert( is_wp_error( $missing_task ) && 'datamachine_code_sandbox_task_missing' === $missing_task->get_error_code(), 'missing task error code is explicit' );

	$invalid_bin = $runner->run(
		array(
			'task'                   => 'Run invalid binary test.',
			'agents_api_path'        => $root . '/agents-api',
			'data_machine_path'      => $root . '/data-machine',
			'data_machine_code_path' => $root . '/data-machine-code',
			'openai_provider_path'   => $root . '/ai-provider-for-openai',
			'sandbox_runtime_bin'    => 'sandbox-runtime; rm -rf /',
		)
	);
	$assert( is_wp_error( $invalid_bin ), 'invalid binary fails closed' );
	$assert( is_wp_error( $invalid_bin ) && 'datamachine_code_sandbox_bin_invalid' === $invalid_bin->get_error_code(), 'invalid binary error code is explicit' );

	$bad_json = ( new SandboxRuntimeAgentSandboxRunner(
		array(
			'shell_available' => fn() => true,
			'command_runner'  => fn() => array( 'exit_code' => 0, 'output' => 'not-json' ),
		)
	) )->run(
		array(
			'task'                   => 'Run invalid JSON test.',
			'agents_api_path'        => $root . '/agents-api',
			'data_machine_path'      => $root . '/data-machine',
			'data_machine_code_path' => $root . '/data-machine-code',
			'openai_provider_path'   => $root . '/ai-provider-for-openai',
		)
	);
	$assert( is_wp_error( $bad_json ), 'invalid JSON fails closed' );
	$assert( is_wp_error( $bad_json ) && 'datamachine_code_sandbox_json_invalid' === $bad_json->get_error_code(), 'invalid JSON error code is explicit' );

	if ( $failures > 0 ) {
		echo "\nFAIL: {$failures}/{$total} assertion(s) failed\n";
		exit( 1 );
	}

	echo "\nOK ({$total} assertions)\n";
	exit( 0 );
}
