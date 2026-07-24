<?php
/**
 * Bounded, read-only inspection of one workspace target.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Support\CommandSpec;
use DataMachineCode\Support\ProcessRunner;

defined('ABSPATH') || exit;

final class WorkspaceTargetInspector {

	private const DEFAULT_TIMEOUT_SECONDS = 5;

	/**
	 * Inspect exactly one path in a killable child process.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function inspect( string $path, string $handle ): array|\WP_Error {
		$timeout = function_exists('apply_filters')
			? (int) apply_filters('datamachine_code_workspace_target_lookup_timeout_seconds', self::DEFAULT_TIMEOUT_SECONDS, $handle)
			: self::DEFAULT_TIMEOUT_SECONDS;
		$timeout = max(1, $timeout);

		$diagnostic = array(
			'phase'           => 'filesystem',
			'resource'        => $path,
			'probe_owner'     => 'workspace-show',
			'timeout_seconds' => $timeout,
		);
		if ( function_exists('do_action') ) {
			do_action('datamachine_code_workspace_lookup_waiting', $diagnostic);
		}

		$probe_path       = __DIR__ . '/workspace-target-probe.php';
		$filesystem_probe = '';
		$git_command      = 'git';
		if ( function_exists('apply_filters') ) {
			$filesystem_probe = (string) apply_filters('datamachine_code_workspace_target_filesystem_probe_command', '', $handle, $path);
			$git_command      = (string) apply_filters('datamachine_code_workspace_target_git_command', 'git', $handle, $path);
		}
		$command = CommandSpec::from_argv(array( PHP_BINARY, $probe_path, $path, $filesystem_probe, $git_command ));
		if ( is_wp_error($command) ) {
			return $command;
		}

		$result = ProcessRunner::run(
			$command,
			array(
				'timeout_seconds'  => $timeout,
				'separate_streams' => true,
				'output_cap_bytes' => 32768,
				'error_code'       => 'workspace_target_lookup_failed',
			)
		);
		if ( is_wp_error($result) ) {
			$data   = is_array($result->get_error_data()) ? $result->get_error_data() : array();
			$stderr = (string) ( $data['stderr'] ?? '' );
			if ( preg_match_all('/^DMC_BOUNDARY:([a-z_]+):([^\r\n]+)/m', $stderr, $matches) && ! empty($matches[1]) ) {
				$diagnostic['phase']     = (string) end($matches[1]);
				$diagnostic['operation'] = (string) end($matches[2]);
			}

			if ( isset($data['timeout']) ) {
				return new \WP_Error(
					'workspace_target_lookup_timeout',
					sprintf('Workspace lookup for "%s" timed out during the %s probe after %d second(s).', $handle, $diagnostic['phase'], $timeout),
					array_merge($data, $diagnostic, array( 'status' => 504 ))
				);
			}

			return new \WP_Error(
				'workspace_target_lookup_failed',
				sprintf('Workspace lookup for "%s" failed during the %s probe.', $handle, $diagnostic['phase']),
				array_merge($data, $diagnostic, array( 'status' => 500 ))
			);
		}

		$decoded = json_decode(( string ) ( $result['stdout'] ?? '' ), true);
		if ( ! is_array($decoded) ) {
			return new \WP_Error(
				'workspace_target_lookup_invalid_response',
				sprintf('Workspace lookup for "%s" returned an invalid probe response.', $handle),
				array_merge($diagnostic, array( 'status' => 500 ))
			);
		}

		return $decoded;
	}
}
