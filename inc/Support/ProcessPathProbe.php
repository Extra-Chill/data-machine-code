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
}

final class ProcfsProcessPathProbe implements ProcessPathProbeInterface {

	/** @param callable $scanner Returns a procfs snapshot. */
	public function __construct(private $scanner) {}

	public function snapshot(): array {
		return ( $this->scanner )();
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
}

final class MacOSLsofProcessPathProbe implements ProcessPathProbeInterface {

	/** @param callable|null $runner Receives argv and returns a ProcessRunner envelope. */
	public function __construct(private $runner = null) {}

	public function snapshot(): array {
		$argv = array( 'lsof', '-n', '-P', '-Fpcfn0' );
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
		if ( $result instanceof \WP_Error || ! is_array($result) || empty($result['success']) ) {
			$data = $result instanceof \WP_Error ? (array) $result->get_error_data() : (array) $result;
			return array(
				'status'      => 'uncertain',
				'records'     => array(),
				'diagnostics' => array(
					'reason'   => ! empty($data['timeout']) ? 'process_path_probe_timeout' : 'process_path_probe_failed',
					'provider' => 'lsof',
					'details'  => $data,
				),
			);
		}

		$records = array();
		$pid     = 0;
		$command = '';
		$fd      = '';
		foreach ( explode("\0", (string) ( $result['output'] ?? '' )) as $field ) {
			$field = ltrim($field, "\n");
			if ( '' === $field ) {
				continue;
			}
			$type  = $field[0];
			$value = substr($field, 1);
			if ( 'p' === $type ) {
				$pid = ctype_digit($value) ? (int) $value : 0;
			} elseif ( 'c' === $type ) {
				$command = $value;
			} elseif ( 'f' === $type ) {
				$fd = $value;
			} elseif ( 'n' === $type && $pid > 0 && str_starts_with($value, '/') ) {
				$records[] = array(
					'pid'        => $pid,
					'command'    => $command,
					'match_type' => 'cwd' === $fd ? 'cwd' : 'open_file',
					'path'       => preg_replace('/ \(deleted\)$/', '', $value),
				) + ( 'cwd' === $fd ? array() : array( 'fd' => $fd ) );
			}
		}

		return array(
			'status'      => 'available',
			'records'     => $records,
			'diagnostics' => array(
				'provider'     => 'lsof',
				'path_records' => count($records),
			),
		);
	}
}
