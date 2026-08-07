<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The interface and its small provider implementations form one process-probe contract.
/**
 * Portable process path probes.
 *
 * @package DataMachineCode\Support
 */

namespace DataMachineCode\Support;

defined('ABSPATH') || exit;

if ( ! class_exists(CommandSpec::class) ) {
	require_once __DIR__ . '/CommandSpec.php';
}
if ( ! class_exists(ProcessRunner::class) ) {
	require_once __DIR__ . '/ProcessRunner.php';
}

interface ProcessPathProbeInterface {

	/** @return array{status:string,records:array<int,array<string,mixed>>,diagnostics:array<string,mixed>} */
	public function snapshot(): array;

	/** @param array<int,string> $paths Absolute candidate roots to scope the inspection to. */
	public function snapshot_for_paths( array $paths ): array;
}

final class ProcfsProcessPathProbe implements ProcessPathProbeInterface {

	/** @param callable $scanner Returns a procfs snapshot. */
	public function __construct(private $scanner) {}

	public function snapshot(): array {
		return ( $this->scanner )();
	}

	public function snapshot_for_paths( array $paths ): array {
		$result = $this->snapshot();
		$result['diagnostics']['scoped_paths'] = array_values(array_filter($paths, fn( $path ) => is_string($path) && str_starts_with($path, '/')));
		return $result;
	}
}

final class UnsupportedProcessPathProbe implements ProcessPathProbeInterface {

	public function __construct(private string $platform) {}

	public function snapshot(): array {
		return array(
			'status'      => 'unsupported',
			'records'     => array(),
			'diagnostics' => array(
				'reason'      => 'process_path_probe_unsupported',
				'platform'    => $this->platform,
				'remediation' => 'Use a supported host process-path provider before artifact cleanup.',
			),
		);
	}

	public function snapshot_for_paths( array $paths ): array {
		$result = $this->snapshot();
		$result['diagnostics']['scoped_paths'] = array_values(array_filter($paths, fn( $path ) => is_string($path) && str_starts_with($path, '/')));
		return $result;
	}
}

final class MacOSLsofProcessPathProbe implements ProcessPathProbeInterface {

	/** @param callable|null $runner Receives argv and returns a ProcessRunner envelope. */
	public function __construct(private $runner = null) {}

	public function snapshot(): array {
		return $this->run_snapshot(array());
	}

	public function snapshot_for_paths( array $paths ): array {
		$paths = array_values(array_unique(array_filter($paths, fn( $path ) => is_string($path) && str_starts_with($path, '/'))));
		return $this->run_snapshot($paths);
	}

	/** @param array<int,string> $paths */
	private function run_snapshot( array $paths ): array {
		$argv = array( 'lsof', '-n', '-P', '-Fpcfn0' );
		if ( array() !== $paths ) {
			$argv[] = '--';
			$argv   = array_merge($argv, $paths);
		}
		if ( is_callable($this->runner) ) {
			$result = ( $this->runner )($argv);
		} else {
			$command = CommandSpec::from_argv($argv);
			$result  = $command instanceof \WP_Error ? $command : ProcessRunner::run($command, array(
				'timeout_seconds'  => 2,
				'output_cap_bytes' => 1048576,
				'error_as_result'  => true,
			));
		}
		if ( is_array($result) && empty($result['success']) && 1 === (int) ( $result['exit_code'] ?? 0 ) && '' === trim( (string) ( $result['output'] ?? '' ) ) ) {
			return array(
				'status'      => 'available',
				'records'     => array(),
				'diagnostics' => array(
					'provider'     => 'lsof',
					'path_records' => 0,
					'scoped_paths' => $paths,
				),
			);
		}
		if ( $result instanceof \WP_Error || ! is_array($result) || empty($result['success']) ) {
			$data = $result instanceof \WP_Error ? (array) $result->get_error_data() : (array) $result;
			return array(
				'status'      => 'uncertain',
				'records'     => array(),
				'diagnostics' => array(
					'reason'   => ! empty($data['timeout']) ? 'process_path_probe_timeout' : self::failure_reason($data),
					'provider' => 'lsof',
				),
			);
		}

		$records = array();
		$pid     = 0;
		$command = '';
		$fd      = '';
		$malformed_fields = 0;
		$awaiting_name     = false;
		foreach ( explode("\0", (string) ( $result['output'] ?? '' )) as $field ) {
			$field = ltrim($field, "\n");
			if ( '' === $field ) {
				continue;
			}
			$type  = $field[0];
			$value = substr($field, 1);
			if ( 'p' === $type ) {
				if ( $awaiting_name ) {
					++$malformed_fields;
				}
				if ( ! ctype_digit($value) ) {
					++$malformed_fields;
					$pid = 0;
					continue;
				}
				$pid           = (int) $value;
				$command       = '';
				$fd            = '';
				$awaiting_name = false;
			} elseif ( 'c' === $type ) {
				$command = $value;
			} elseif ( 'f' === $type ) {
				if ( $awaiting_name ) {
					++$malformed_fields;
				}
				$fd            = $value;
				$awaiting_name = true;
			} elseif ( 'n' === $type && $pid > 0 && str_starts_with($value, '/') ) {
				$records[] = array(
					'pid'        => $pid,
					'command'    => $command,
					'match_type' => 'cwd' === $fd ? 'cwd' : 'open_file',
					'path'       => preg_replace('/ \(deleted\)$/', '', $value),
				) + ( 'cwd' === $fd ? array() : array( 'fd' => $fd ) );
				$awaiting_name = false;
			} elseif ( 'n' === $type && str_starts_with($value, '/') ) {
				++$malformed_fields;
				$awaiting_name = false;
			} elseif ( 'n' === $type ) {
				$awaiting_name = false;
			} elseif ( ! in_array($type, array( 'p', 'c', 'f', 'n' ), true) ) {
				++$malformed_fields;
			}
		}
		if ( $malformed_fields > 0 || $awaiting_name ) {
			return array(
				'status'      => 'uncertain',
				'records'     => array(),
				'diagnostics' => array(
					'reason'   => 'process_path_probe_malformed_output',
					'provider' => 'lsof',
				),
			);
		}

		return array(
			'status'      => 'available',
			'records'     => $records,
			'diagnostics' => array(
				'provider'     => 'lsof',
				'path_records' => count($records),
				'scoped_paths' => $paths,
			),
		);
	}

	/** @param array<string,mixed> $data */
	private static function failure_reason( array $data ): string {
		$details = strtolower(implode(' ', array_filter(array_map('strval', array_intersect_key($data, array_flip(array( 'error', 'message', 'output', 'stderr' )))))));
		return str_contains($details, 'permission denied') || str_contains($details, 'operation not permitted') ? 'process_path_probe_permission_denied' : 'process_path_probe_failed';
	}
}
