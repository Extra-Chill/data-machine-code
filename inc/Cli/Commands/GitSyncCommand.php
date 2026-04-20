<?php
/**
 * WP-CLI GitSync Command
 *
 * Thin CLI shell over the `datamachine/gitsync-*` abilities. Every
 * subcommand resolves the corresponding ability via `wp_get_ability()`,
 * maps CLI args into the ability's input schema, and formats the output
 * — no business logic lives here.
 *
 * @package DataMachineCode\Cli\Commands
 * @since   0.7.0
 */

namespace DataMachineCode\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;

defined( 'ABSPATH' ) || exit;

class GitSyncCommand extends BaseCommand {

	/**
	 * List all GitSync bindings.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code gitsync list
	 *     wp datamachine-code gitsync list --format=json
	 *
	 * @subcommand list
	 */
	public function list_bindings( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$result = $this->execute_ability( 'datamachine/gitsync-list', array() );

		if ( empty( $result['bindings'] ) ) {
			WP_CLI::log( 'No GitSync bindings registered.' );
			WP_CLI::log( 'Create one with: wp datamachine-code gitsync bind <slug> --local=<path> --remote=<url>' );
			return;
		}

		$items = array_map(
			function ( array $b ): array {
				return array(
					'slug'          => $b['slug'],
					'local_path'    => $b['local_path'],
					'remote_url'    => $b['remote_url'],
					'branch'        => $b['branch'],
					'is_repo'       => ! empty( $b['is_repo'] ) ? 'yes' : 'no',
					'auto_pull'     => ! empty( $b['auto_pull'] ) ? 'yes' : 'no',
					'interval'      => (string) ( $b['pull_interval'] ?? '-' ),
					'last_pulled'   => (string) ( $b['last_pulled'] ?? '-' ),
					'last_commit'   => (string) ( $b['last_commit'] ?? '-' ),
				);
			},
			$result['bindings']
		);

		$this->format_items(
			$items,
			array( 'slug', 'local_path', 'remote_url', 'branch', 'is_repo', 'auto_pull', 'interval', 'last_pulled', 'last_commit' ),
			$assoc_args,
			'slug'
		);
	}

	/**
	 * Bind a site-owned directory to a remote git repository.
	 *
	 * Clones the remote into the target path, or adopts an existing
	 * checkout whose origin matches `--remote`.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Unique binding identifier. Lowercase letters, digits, hyphen, underscore.
	 *
	 * --local=<path>
	 * : Path relative to ABSPATH (leading slash). e.g. /wp-content/uploads/markdown/wiki/
	 *
	 * --remote=<url>
	 * : Git remote URL (https:// or git@).
	 *
	 * [--branch=<branch>]
	 * : Branch to track. Default: main.
	 *
	 * [--conflict=<strategy>]
	 * : Conflict policy.
	 * ---
	 * default: fail
	 * options:
	 *   - fail
	 *   - upstream_wins
	 *   - manual
	 * ---
	 *
	 * [--auto-pull]
	 * : Opt the binding into scheduled sync (Phase 3 honors this flag).
	 *
	 * [--pull-interval=<interval>]
	 * : Scheduled pull cadence. Default: hourly.
	 *
	 * ## EXAMPLES
	 *
	 *     # Bind the wiki content directory
	 *     wp datamachine-code gitsync bind intelligence-wiki \
	 *       --local=/wp-content/uploads/markdown/wiki/ \
	 *       --remote=https://github.com/Automattic/a8c-wiki-woocommerce
	 *
	 *     # Bind + auto-pull hourly
	 *     wp datamachine-code gitsync bind wg-agent-def \
	 *       --local=/wp-content/uploads/datamachine-files/agents/wiki-generator/ \
	 *       --remote=https://github.com/Automattic/a8c-wiki-generator \
	 *       --auto-pull --pull-interval=hourly
	 *
	 * @subcommand bind
	 */
	public function bind( array $args, array $assoc_args ): void {
		$slug = $args[0] ?? '';
		if ( '' === $slug ) {
			WP_CLI::error( 'Binding slug is required (first positional argument).' );
			return;
		}

		$local  = (string) ( $assoc_args['local'] ?? '' );
		$remote = (string) ( $assoc_args['remote'] ?? '' );
		if ( '' === $local || '' === $remote ) {
			WP_CLI::error( 'Both --local and --remote are required.' );
			return;
		}

		$policy = array();
		if ( isset( $assoc_args['conflict'] ) ) {
			$policy['conflict'] = (string) $assoc_args['conflict'];
		}
		if ( ! empty( $assoc_args['auto-pull'] ) ) {
			$policy['auto_pull'] = true;
		}
		if ( isset( $assoc_args['pull-interval'] ) ) {
			$policy['pull_interval'] = (string) $assoc_args['pull-interval'];
		}

		$input = array(
			'slug'       => $slug,
			'local_path' => $local,
			'remote_url' => $remote,
		);
		if ( ! empty( $assoc_args['branch'] ) ) {
			$input['branch'] = (string) $assoc_args['branch'];
		}
		if ( ! empty( $policy ) ) {
			$input['policy'] = $policy;
		}

		$result = $this->execute_ability( 'datamachine/gitsync-bind', $input );

		$verb = ! empty( $result['adopted'] ) ? 'Adopted existing' : 'Cloned and bound';
		WP_CLI::success( sprintf( '%s: %s → %s', $verb, $slug, $result['local_path'] ?? '' ) );
		WP_CLI::log( sprintf( '  remote: %s', $result['binding']['remote_url'] ?? '' ) );
		WP_CLI::log( sprintf( '  branch: %s', $result['binding']['branch'] ?? '' ) );
		if ( ! empty( $result['binding']['last_commit'] ) ) {
			WP_CLI::log( sprintf( '  head:   %s', $result['binding']['last_commit'] ) );
		}
	}

	/**
	 * Unbind a GitSync binding.
	 *
	 * By default the on-disk directory is preserved — the binding is
	 * metadata, not ownership of the filesystem. Pass `--purge` to also
	 * delete the working tree.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Binding slug.
	 *
	 * [--purge]
	 * : Also delete the on-disk directory.
	 *
	 * [--yes]
	 * : Skip the purge confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code gitsync unbind intelligence-wiki
	 *     wp datamachine-code gitsync unbind intelligence-wiki --purge
	 *
	 * @subcommand unbind
	 */
	public function unbind( array $args, array $assoc_args ): void {
		$slug = $args[0] ?? '';
		if ( '' === $slug ) {
			WP_CLI::error( 'Binding slug is required.' );
			return;
		}

		$purge = ! empty( $assoc_args['purge'] );

		if ( $purge && empty( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( sprintf( 'Purge the on-disk directory for binding "%s"? This permanently deletes files.', $slug ) );
		}

		$result = $this->execute_ability(
			'datamachine/gitsync-unbind',
			array(
				'slug'  => $slug,
				'purge' => $purge,
			)
		);

		if ( $result['purged'] ?? false ) {
			WP_CLI::success( sprintf( 'Unbound "%s" and purged %s', $slug, $result['local_path'] ?? '' ) );
		} else {
			WP_CLI::success( sprintf( 'Unbound "%s" (directory preserved: %s)', $slug, $result['local_path'] ?? '' ) );
		}
	}

	/**
	 * Pull the remote into a bound directory.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Binding slug.
	 *
	 * [--allow-dirty]
	 * : Bypass dirty-working-tree safety for this pull only.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code gitsync pull intelligence-wiki
	 *     wp datamachine-code gitsync pull intelligence-wiki --allow-dirty
	 *
	 * @subcommand pull
	 */
	public function pull( array $args, array $assoc_args ): void {
		$slug = $args[0] ?? '';
		if ( '' === $slug ) {
			WP_CLI::error( 'Binding slug is required.' );
			return;
		}

		$result = $this->execute_ability(
			'datamachine/gitsync-pull',
			array(
				'slug'        => $slug,
				'allow_dirty' => ! empty( $assoc_args['allow-dirty'] ),
			)
		);

		$previous = $result['previous_head'] ?? '-';
		$head     = $result['head'] ?? '-';

		if ( $previous === $head ) {
			WP_CLI::success( sprintf( 'Already up to date: %s @ %s', $slug, (string) $head ) );
		} else {
			WP_CLI::success( sprintf( 'Pulled %s: %s → %s', $slug, (string) $previous, (string) $head ) );
		}

		$message = trim( (string) ( $result['message'] ?? '' ) );
		if ( '' !== $message ) {
			WP_CLI::log( $message );
		}
	}

	/**
	 * Show status for a GitSync binding.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Binding slug.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code gitsync status intelligence-wiki
	 *     wp datamachine-code gitsync status intelligence-wiki --format=json
	 *
	 * @subcommand status
	 */
	public function status( array $args, array $assoc_args ): void {
		$slug = $args[0] ?? '';
		if ( '' === $slug ) {
			WP_CLI::error( 'Binding slug is required.' );
			return;
		}

		$result = $this->execute_ability( 'datamachine/gitsync-status', array( 'slug' => $slug ) );

		$format = $assoc_args['format'] ?? 'table';
		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		if ( 'yaml' === $format ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			WP_CLI::log( (string) print_r( $result, true ) );
			return;
		}

		// Default table-like summary — a single record flattened into key/value rows.
		$rows = array(
			array( 'field' => 'slug',           'value' => (string) ( $result['slug'] ?? '' ) ),
			array( 'field' => 'local_path',     'value' => (string) ( $result['local_path'] ?? '' ) ),
			array( 'field' => 'remote_url',     'value' => (string) ( $result['remote_url'] ?? '' ) ),
			array( 'field' => 'tracked_branch', 'value' => (string) ( $result['tracked_branch'] ?? '' ) ),
			array( 'field' => 'exists',         'value' => ! empty( $result['exists'] ) ? 'yes' : 'no' ),
			array( 'field' => 'is_repo',        'value' => ! empty( $result['is_repo'] ) ? 'yes' : 'no' ),
			array( 'field' => 'branch',         'value' => (string) ( $result['branch'] ?? '-' ) ),
			array( 'field' => 'head',           'value' => (string) ( $result['head'] ?? '-' ) ),
			array( 'field' => 'dirty',          'value' => (string) ( (int) ( $result['dirty'] ?? 0 ) ) ),
			array( 'field' => 'ahead',          'value' => null === ( $result['ahead'] ?? null ) ? '-' : (string) $result['ahead'] ),
			array( 'field' => 'behind',         'value' => null === ( $result['behind'] ?? null ) ? '-' : (string) $result['behind'] ),
			array( 'field' => 'last_pulled',    'value' => (string) ( $result['last_pulled'] ?? '-' ) ),
		);

		$this->format_items( $rows, array( 'field', 'value' ), $assoc_args, 'field' );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Resolve + execute an ability, erroring out on missing or failed calls.
	 *
	 * Centralized so every subcommand gets the same "ability missing → CLI
	 * error" and "WP_Error result → CLI error" behavior without boilerplate.
	 *
	 * @param string               $ability_name Fully qualified ability name.
	 * @param array<string, mixed> $input        Ability input payload.
	 * @return array<string, mixed> On success. Exits via WP_CLI::error otherwise.
	 */
	private function execute_ability( string $ability_name, array $input ): array {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			WP_CLI::error( 'WordPress Abilities API unavailable — requires WP 6.9+ or the Abilities API plugin.' );
		}

		$ability = wp_get_ability( $ability_name );
		if ( ! $ability ) {
			WP_CLI::error( sprintf( 'Ability "%s" is not registered.', $ability_name ) );
		}

		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		return is_array( $result ) ? $result : array();
	}
}
