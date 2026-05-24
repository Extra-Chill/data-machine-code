<?php
/**
 * WP-CLI code-task command.
 *
 * @package DataMachineCode\Cli\Commands
 */

namespace DataMachineCode\Cli\Commands;

use DataMachine\Cli\BaseCommand;
use WP_CLI;

defined('ABSPATH') || exit;

class CodeTaskCommand extends BaseCommand {



	/**
	 * Create a workspace coding task from an evidence packet.
	 *
	 * ## OPTIONS
	 *
	 * --packet=<path>
	 * : Path to a JSON evidence packet file.
	 *
	 * [--branch=<branch>]
	 * : Optional explicit branch name.
	 *
	 * [--base-ref=<ref>]
	 * : Optional base ref for the worktree. Defaults to origin/main.
	 *
	 * [--allow-stale]
	 * : Allow creating a worktree from a stale base.
	 *
	 * [--force]
	 * : Override workspace disk-budget refusal threshold.
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
	 *     wp datamachine-code code-task create --packet=packet.json --format=json
	 *
	 * @subcommand create
	 */
	public function create( array $args, array $assoc_args ): void {
		if ( empty($assoc_args['packet']) ) {
			WP_CLI::error('Usage: wp datamachine-code code-task create --packet=<packet.json> [--format=json]');
			return;
		}

		$packet = $this->read_packet_file( (string) $assoc_args['packet']);
		$input  = array( 'packet' => $packet );
		foreach ( array( 'branch', 'base-ref' ) as $key ) {
			if ( isset($assoc_args[ $key ]) && '' !== trim( (string) $assoc_args[ $key ]) ) {
				$input[ str_replace('-', '_', $key) ] = (string) $assoc_args[ $key ];
			}
		}

		$input['allow_stale'] = ! empty($assoc_args['allow-stale']);
		$input['force']       = ! empty($assoc_args['force']);

		$ability = wp_get_ability('datamachine/create-code-task');
		if ( ! $ability ) {
			WP_CLI::error('Code-task ability not available.');
			return;
		}

		$result = $ability->execute($input);
		if ( $result instanceof \WP_Error ) {
			WP_CLI::error($result->get_error_message());
			return;
		}

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::log(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return;
		}

		$this->format_items(
			array(
				array(
					'repo'        => $result['repo'] ?? '',
					'branch'      => $result['branch'] ?? '',
					'handle'      => $result['handle'] ?? '',
					'prompt_path' => $result['prompt_path'] ?? '',
					'source_url'  => $result['source_url'] ?? '',
				),
			),
			array( 'repo', 'branch', 'handle', 'prompt_path', 'source_url' ),
			$assoc_args,
			'repo'
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function read_packet_file( string $path ): array {
		if ( ! is_file($path) || ! is_readable($path) ) {
			WP_CLI::error(sprintf('Packet file not readable: %s', $path));
			return array();
		}

     // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$json = file_get_contents($path);
		if ( false === $json ) {
			WP_CLI::error(sprintf('Failed to read packet file: %s', $path));
			return array();
		}

		$decoded = json_decode($json, true);
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array($decoded) ) {
			WP_CLI::error(sprintf('Packet file is not valid JSON object: %s', $path));
			return array();
		}

		return $decoded;
	}
}
