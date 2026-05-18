<?php
/**
 * Sandbox Runtime agent stack probe runner.
 *
 * @package DataMachineCode\Runtime
 */

namespace DataMachineCode\Runtime;

use DataMachineCode\Environment;

defined( 'ABSPATH' ) || exit;

final class SandboxRuntimeAgentProbeRunner {

	private const SCHEMA = 'data-machine-code/sandbox-runtime-agent-probe/v1';

	/** @var array<string, callable> */
	private array $callbacks;

	/**
	 * @param array<string, callable> $callbacks Test seams for pure-PHP smoke coverage.
	 */
	public function __construct( array $callbacks = array() ) {
		$this->callbacks = $callbacks;
	}

	/**
	 * Run the Sandbox Runtime WordPress agent stack probe.
	 *
	 * @param array<string,mixed> $input Probe input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function run( array $input ): array|\WP_Error {
		if ( ! $this->shell_available() ) {
			return new \WP_Error( 'datamachine_code_shell_unavailable', 'Shell execution is not available for Sandbox Runtime.', array( 'status' => 500 ) );
		}

		$paths = array(
			'agents_api'        => $this->clean_path( (string) ( $input['agents_api_path'] ?? '' ) ),
			'data_machine'      => $this->clean_path( (string) ( $input['data_machine_path'] ?? '' ) ),
			'data_machine_code' => $this->clean_path( (string) ( $input['data_machine_code_path'] ?? ( defined( 'DATAMACHINE_CODE_PATH' ) ? DATAMACHINE_CODE_PATH : '' ) ) ),
			'openai_provider'   => $this->clean_path( (string) ( $input['openai_provider_path'] ?? '' ) ),
		);

		foreach ( $paths as $key => $path ) {
			if ( '' === $path || ! is_dir( $path ) ) {
				return new \WP_Error( 'datamachine_code_probe_path_missing', sprintf( 'Sandbox Runtime probe path %s is missing or not a directory.', $key ), array( 'status' => 400 ) );
			}
		}

		$artifacts = $this->clean_path( (string) ( $input['artifacts_path'] ?? '' ) );
		if ( '' === $artifacts ) {
			$artifacts = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'datamachine-code-sandbox-runtime-' . $this->generate_run_id();
		}

		$wp_version = trim( (string) ( $input['wp'] ?? 'trunk' ) );
		if ( '' === $wp_version ) {
			$wp_version = 'trunk';
		}

		$bin = trim( (string) ( $input['sandbox_runtime_bin'] ?? 'sandbox-runtime' ) );
		if ( '' === $bin || ! preg_match( '#^[A-Za-z0-9_./:@+-]+$#', $bin ) ) {
			return new \WP_Error( 'datamachine_code_probe_bin_invalid', 'sandbox_runtime_bin must be a command name or path without shell metacharacters.', array( 'status' => 400 ) );
		}
		$command_prefix = $this->command_prefix( $bin );

		$command = sprintf(
			'%s agent-runtime-probe --agents-api %s --data-machine %s --data-machine-code %s --openai-provider %s --wp %s --artifacts %s --json',
			$command_prefix,
			escapeshellarg( $paths['agents_api'] ),
			escapeshellarg( $paths['data_machine'] ),
			escapeshellarg( $paths['data_machine_code'] ),
			escapeshellarg( $paths['openai_provider'] ),
			escapeshellarg( $wp_version ),
			escapeshellarg( $artifacts )
		);

		$result    = $this->run_command( $command );
		$exit_code = (int) ( $result['exit_code'] ?? 1 );
		$output    = (string) ( $result['output'] ?? '' );
		$decoded   = $this->decode_json_output( $output );

		if ( is_wp_error( $decoded ) ) {
			return new \WP_Error(
				'datamachine_code_probe_json_invalid',
				'Sandbox Runtime probe did not return valid JSON: ' . $decoded->get_error_message(),
				array(
					'status'    => 500,
					'exit_code' => $exit_code,
					'output'    => $this->bound_output( $output ),
				)
			);
		}

		if ( 0 !== $exit_code ) {
			return new \WP_Error(
				'datamachine_code_probe_failed',
				'Sandbox Runtime probe failed.',
				array(
					'status'    => 500,
					'exit_code' => $exit_code,
					'output'    => $this->bound_output( $output ),
					'probe'     => $decoded,
				)
			);
		}

		return array(
			'success'   => true,
			'schema'    => self::SCHEMA,
			'command'   => $command,
			'wp'        => $wp_version,
			'paths'     => $paths,
			'artifacts' => $artifacts,
			'exit_code' => $exit_code,
			'probe'     => $decoded,
		);
	}

	private function shell_available(): bool {
		if ( isset( $this->callbacks['shell_available'] ) ) {
			return (bool) ( $this->callbacks['shell_available'] )();
		}

		return Environment::has_shell();
	}

	private function clean_path( string $path ): string {
		return rtrim( trim( $path ), DIRECTORY_SEPARATOR );
	}

	private function command_prefix( string $bin ): string {
		if ( str_ends_with( $bin, '.js' ) && is_file( $bin ) ) {
			return 'node ' . escapeshellarg( $bin );
		}

		return escapeshellarg( $bin );
	}

	private function generate_run_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return \wp_generate_uuid4();
		}

		return bin2hex( random_bytes( 16 ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function decode_json_output( string $output ): array|\WP_Error {
		$trimmed = trim( $output );
		if ( '' === $trimmed ) {
			return new \WP_Error( 'empty_output', 'Empty output.' );
		}

		$decoded = json_decode( $trimmed, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		$offset = strrpos( $trimmed, "\n{" );
		if ( false !== $offset ) {
			$decoded = json_decode( substr( $trimmed, $offset + 1 ), true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return new \WP_Error( 'json_decode_failed', json_last_error_msg() );
	}

	/** @return array{exit_code:int,output:string} */
	private function run_command( string $command ): array {
		if ( isset( $this->callbacks['command_runner'] ) ) {
			return ( $this->callbacks['command_runner'] )( $command );
		}

		$output = array();
		$exit   = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Required host-side Sandbox Runtime execution primitive.
		exec( $command . ' 2>&1', $output, $exit );

		return array(
			'exit_code' => $exit,
			'output'    => implode( "\n", $output ),
		);
	}

	private function bound_output( string $output ): string {
		if ( strlen( $output ) <= 4000 ) {
			return $output;
		}

		return substr( $output, 0, 4000 );
	}
}
