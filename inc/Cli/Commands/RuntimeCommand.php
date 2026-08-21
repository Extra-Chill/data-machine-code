<?php
/**
 * WP-CLI managed runtime diagnostics.
 *
 * @package DataMachineCode\Cli\Commands
 */

namespace DataMachineCode\Cli\Commands;

use DataMachine\Cli\BaseCommand;
use DataMachineCode\Cli\CliResponseRenderer;
use DataMachineCode\Runtime\ManagedReleaseDrift;
use WP_CLI;

defined('ABSPATH') || exit;

final class RuntimeCommand extends BaseCommand {

	/**
	 * Inspect or converge the Data Machine Code managed release channel.
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
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code runtime release --format=json
	 *     wp datamachine-code runtime release --apply --format=json
	 *
	 * @subcommand release
	 */
	public function release( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$result = ! empty($assoc_args['apply']) ? ( new ManagedReleaseDrift() )->converge() : ( new ManagedReleaseDrift() )->status();
		if ( ! empty($assoc_args['apply']) && ( 'current' !== $result['state'] || 'verified' !== ( $result['verification']['state'] ?? '' ) ) ) {
			WP_CLI::error((string) wp_json_encode($result, JSON_UNESCAPED_SLASHES));
			return;
		}
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			( new CliResponseRenderer() )->json($result);
			return;
		}

		WP_CLI::log(sprintf('Managed release: %s', (string) $result['state']));
		WP_CLI::log(sprintf('Installed: %s; latest: %s', (string) $result['installed_version'], (string) ( $result['latest_version'] ?? 'unavailable' )));
		if ( is_array($result['action'] ?? null) ) {
			WP_CLI::log((string) ( $result['action']['command'] ?? $result['action']['message'] ?? '' ));
		}
	}
}
