<?php

declare(strict_types=1);

namespace {
	define('ABSPATH', __DIR__ . '/fixtures/');
	$GLOBALS['external_process_probe_option'] = null;
	$GLOBALS['external_process_probe_filter'] = null;

	class WP_Error {
		public function __construct(private string $code, private string $message = '', private mixed $data = null) {}
		public function get_error_data(): mixed { return $this->data; }
	}

	function get_option( string $key, mixed $default = false ): mixed {
		return 'datamachine_code_external_process_path_probe_argv' === $key ? $GLOBALS['external_process_probe_option'] : $default;
	}

	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		return 'datamachine_code_external_process_path_probe_argv' === $hook && is_callable($GLOBALS['external_process_probe_filter'])
			? ( $GLOBALS['external_process_probe_filter'] )($value)
			: $value;
	}
}

namespace DataMachineCode\Workspace {
	require_once dirname(__DIR__) . '/vendor/autoload.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceArtifactCleanup.php';

	final class ExternalProcessProbeHarness {
		use WorkspaceArtifactCleanup;

		public function probe(): \DataMachineCode\Support\ProcessPathProbeInterface {
			return $this->artifact_process_path_probe();
		}
	}
}

namespace {
	use DataMachineCode\Support\ExternalProcessPathProbe;
	use DataMachineCode\Workspace\ExternalProcessProbeHarness;

	function external_probe_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
		}
	}

	$received = array();
	$probe = new ExternalProcessPathProbe(
		array( 'host-process-probe', '--json' ),
		function ( array $argv, string $stdin ) use ( &$received ): array {
			$received = array( 'argv' => $argv, 'stdin' => $stdin );
			return array( 'success' => true, 'output' => json_encode(array(
				'status' => 'available',
				'path' => '/workspace/candidate',
				'processes' => array(array( 'pid' => 42, 'command' => 'builder', 'match_type' => 'cwd', 'path' => '/workspace/candidate' )),
			)) );
		}
	);
	$result = $probe->snapshot_for_paths(array( '/workspace/candidate' ));
	external_probe_assert_same(array( 'host-process-probe', '--json' ), $received['argv'], 'external probe must preserve configured argv without shell parsing');
	external_probe_assert_same("/workspace/candidate\n", $received['stdin'], 'external probe must send the candidate path on stdin');
	external_probe_assert_same('available', $result['status'], 'well-formed external evidence must be available');
	external_probe_assert_same(42, $result['records'][0]['pid'] ?? null, 'external evidence must preserve process PID');

	$malformed = new ExternalProcessPathProbe(array( 'probe' ), fn( array $argv, string $stdin ): array => array( 'success' => true, 'output' => '{' ));
	external_probe_assert_same('uncertain', $malformed->snapshot_for_paths(array( '/workspace/candidate' ))['status'], 'invalid JSON must fail closed');

	$wrong_path = new ExternalProcessPathProbe(array( 'probe' ), fn( array $argv, string $stdin ): array => array( 'success' => true, 'output' => json_encode(array( 'status' => 'available', 'path' => '/other', 'processes' => array() ))));
	external_probe_assert_same('process_path_probe_malformed_output', $wrong_path->snapshot_for_paths(array( '/workspace/candidate' ))['diagnostics']['reason'], 'mismatched response paths must fail closed');

	$failed = new ExternalProcessPathProbe(array( 'probe' ), fn( array $argv, string $stdin ): array => array( 'success' => false, 'timeout' => 2 ));
	external_probe_assert_same('process_path_probe_timeout', $failed->snapshot_for_paths(array( '/workspace/candidate' ))['diagnostics']['reason'], 'external probe timeouts must fail closed');

	$rejected = new ExternalProcessPathProbe(array( 'probe' ), fn( array $argv, string $stdin ): array => array( 'success' => false, 'output' => json_encode(array( 'status' => 'rejected', 'path' => '/workspace/candidate' ))));
	external_probe_assert_same('process_path_probe_rejected', $rejected->snapshot_for_paths(array( '/workspace/candidate' ))['diagnostics']['reason'], 'provider path rejection must retain a typed fail-closed reason');

	$unavailable = new ExternalProcessPathProbe(array( 'probe' ), fn( array $argv, string $stdin ): array => array( 'success' => false, 'output' => json_encode(array( 'status' => 'unavailable', 'path' => '/workspace/candidate' ))));
	external_probe_assert_same('process_path_probe_unavailable', $unavailable->snapshot_for_paths(array( '/workspace/candidate' ))['diagnostics']['reason'], 'incomplete host evidence must retain a typed fail-closed reason');

	$overflow = new ExternalProcessPathProbe(array( 'probe' ), fn( array $argv, string $stdin ): array => array( 'success' => false, 'output_overflow' => true ));
	external_probe_assert_same('process_path_probe_output_limit', $overflow->snapshot_for_paths(array( '/workspace/candidate' ))['diagnostics']['reason'], 'provider output overflow must remain a typed fail-closed reason');

	$outside_record = new ExternalProcessPathProbe(array( 'probe' ), fn( array $argv, string $stdin ): array => array( 'success' => true, 'output' => json_encode(array( 'status' => 'available', 'path' => '/workspace/candidate', 'processes' => array(array( 'pid' => 42, 'path' => '/other' )) ))));
	external_probe_assert_same('process_path_probe_malformed_output', $outside_record->snapshot_for_paths(array( '/workspace/candidate' ))['diagnostics']['reason'], 'process evidence outside the requested candidate must fail closed');

	$invalid = new ExternalProcessPathProbe('probe');
	external_probe_assert_same('process_path_probe_invalid_configuration', $invalid->snapshot_for_paths(array( '/workspace/candidate' ))['diagnostics']['reason'], 'invalid argv configuration must fail closed');

	$harness = new ExternalProcessProbeHarness();
	external_probe_assert_same(false, $harness->probe() instanceof ExternalProcessPathProbe, 'an absent external configuration must preserve the native default provider');
	$GLOBALS['external_process_probe_option'] = array( 'option-probe', '--safe' );
	external_probe_assert_same(true, $harness->probe() instanceof ExternalProcessPathProbe, 'the WordPress option must select the external provider');
	$GLOBALS['external_process_probe_option'] = null;
	$GLOBALS['external_process_probe_filter'] = fn( mixed $value ): array => array( 'filtered-probe' );
	external_probe_assert_same(true, $harness->probe() instanceof ExternalProcessPathProbe, 'the generic filter must select the external provider');

	fwrite(STDOUT, "external-process-path-probe ok\n");
}
