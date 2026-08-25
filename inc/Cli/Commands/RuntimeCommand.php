<?php

namespace DataMachineCode\Cli\Commands;

use DataMachine\Cli\BaseCommand;
use DataMachineCode\Cli\CliResponseRenderer;
use DataMachineCode\Runtime\ManagedReleaseDrift;
use DataMachineCode\Runtime\RuntimeSourceDoctor;
use DataMachineCode\Abilities\WorkspaceAbilities;
use WP_CLI;

defined('ABSPATH') || exit;

final class RuntimeCommand extends BaseCommand {
	/**
	 * Show the compact loaded-runtime and managed-source comparison.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 * ---
	 *
	 * @subcommand identity
	 */
	public function identity( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		WP_CLI::line((string) wp_json_encode(WorkspaceAbilities::runtimeIdentity(array()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}
	/**
	 * Inspect or converge the configured managed release channel.
	 *
	 * [--apply]
	 * : Invoke the owning channel's declared convergence callback.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @subcommand release
	 */
	public function release( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$result = ! empty($assoc_args['apply']) ? ( new ManagedReleaseDrift() )->converge() : ( new ManagedReleaseDrift() )->status();
		if ( ! empty($assoc_args['apply']) && ( 'current' !== $result['state'] || 'verified' !== ( $result['verification']['state'] ?? '' ) ) ) {
			WP_CLI::error( (string) wp_json_encode($result, JSON_UNESCAPED_SLASHES));
			return;
		}
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			( new CliResponseRenderer() )->json($result);
			return;
		}

		WP_CLI::log(sprintf('Managed release: %s', (string) $result['state']));
		WP_CLI::log(sprintf('Installed: %s; latest: %s', (string) $result['installed_version'], (string) ( $result['latest_version'] ?? 'unavailable' )));
		if ( is_array($result['action'] ?? null) ) {
			WP_CLI::log( (string) ( $result['action']['command'] ?? $result['action']['message'] ?? '' ));
		}
	}

	/**
	 * Inspect active plugin runtime/source/release drift without mutation.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Explicitly apply the safe configured reconciliation. Without this flag the command is read-only.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code runtime doctor
	 *     wp datamachine-code runtime doctor --apply
	 *
	 * @subcommand doctor
	 */
	public function doctor( array $args, array $assoc_args ): void {
		$config = apply_filters('datamachine_code_runtime_source_doctor_config', array(
			'source_path' => defined('DATAMACHINE_CODE_SOURCE_PATH') ? DATAMACHINE_CODE_SOURCE_PATH : '',
			'release_ref' => defined('DATAMACHINE_CODE_RELEASE_REF') ? DATAMACHINE_CODE_RELEASE_REF : 'release-latest',
		));
		if ( ! is_array($config) ) {
			$config = array(); }
		$contract = (array) ( $config['command_contract'] ?? array() );
		if ( ! isset($contract['runtime_supports']) && ! empty($contract['command']) && ! empty($contract['flag']) ) {
			try {
				$help                         = (string) WP_CLI::runcommand( (string) $contract['command'] . ' --help', array(
					'return'     => true,
					'exit_error' => false,
				));
				$contract['runtime_supports'] = str_contains($help, (string) $contract['flag']);
			} catch ( \Throwable ) {
				$contract['runtime_supports'] = false;
			}
		}
		$config['command_contract'] = $contract;
		if ( ! empty($assoc_args['apply']) ) {
			$result = RuntimeSourceDoctor::apply(DATAMACHINE_CODE_PATH . 'data-machine-code.php', $config);
			if ( is_wp_error($result) ) {
				WP_CLI::error($result->get_error_message());
				return; }
			if ( empty($result['success']) ) {
				WP_CLI::error( (string) wp_json_encode($result, JSON_UNESCAPED_SLASHES));
				return; }
			WP_CLI::success( (string) $result['message']);
			return;
		}
		WP_CLI::line( (string) wp_json_encode(RuntimeSourceDoctor::inspect(DATAMACHINE_CODE_PATH . 'data-machine-code.php', DATAMACHINE_CODE_VERSION, $config), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}
}
