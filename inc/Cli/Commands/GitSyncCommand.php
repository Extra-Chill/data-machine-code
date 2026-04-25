<?php
/**
 * WP-CLI GitSync Command
 *
 * Thin shell over the `datamachine/gitsync-*` abilities. Every subcommand
 * resolves the matching ability via `wp_get_ability()`, maps CLI args
 * into the ability's input schema, and formats the response — no
 * business logic in the CLI layer.
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
					'slug'        => $b['slug'],
					'local_path'  => $b['local_path'],
					'remote_url'  => $b['remote_url'],
					'branch'      => $b['branch'],
					'exists'      => ! empty( $b['exists'] ) ? 'yes' : 'no',
					'write'       => ! empty( $b['write_enabled'] ) ? 'yes' : 'no',
					'direct'      => ! empty( $b['push_enabled'] ) ? 'yes' : 'no',
					'pulled'      => (int) ( $b['pulled_count'] ?? 0 ),
					'last_pulled' => (string) ( $b['last_pulled'] ?? '-' ),
				);
			},
			$result['bindings']
		);

		$this->format_items(
			$items,
			array( 'slug', 'local_path', 'remote_url', 'branch', 'exists', 'write', 'direct', 'pulled', 'last_pulled' ),
			$assoc_args,
			'slug'
		);
	}

	/**
	 * Bind a site-owned directory to a GitHub repository.
	 *
	 * Registers the binding but does NOT clone files. Run `gitsync pull`
	 * after bind to materialize upstream content.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Unique binding identifier. Lowercase letters, digits, hyphen, underscore.
	 *
	 * --local=<path>
	 * : ABSPATH-relative path (leading slash). e.g. /wp-content/uploads/markdown/wiki/
	 *
	 * --remote=<url>
	 * : GitHub URL (https://github.com/owner/repo or git@github.com:owner/repo).
	 *
	 * [--branch=<branch>]
	 * : Branch to track. Default: main.
	 *
	 * [--conflict=<strategy>]
	 * : Conflict policy for pull.
	 * ---
	 * default: fail
	 * options:
	 *   - fail
	 *   - upstream_wins
	 *   - manual
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code gitsync bind intelligence-wiki \
	 *       --local=/wp-content/uploads/markdown/wiki/ \
	 *       --remote=https://github.com/Automattic/a8c-wiki-woocommerce
	 *
	 * @subcommand bind
	 */
	public function bind( array $args, array $assoc_args ): void {
		$slug = $args[0] ?? '';
		if ( '' === $slug ) {
			WP_CLI::error( 'Binding slug is required.' );
		}

		$local  = (string) ( $assoc_args['local'] ?? '' );
		$remote = (string) ( $assoc_args['remote'] ?? '' );
		if ( '' === $local || '' === $remote ) {
			WP_CLI::error( 'Both --local and --remote are required.' );
		}

		$input = array(
			'slug'       => $slug,
			'local_path' => $local,
			'remote_url' => $remote,
		);
		if ( ! empty( $assoc_args['branch'] ) ) {
			$input['branch'] = (string) $assoc_args['branch'];
		}
		if ( isset( $assoc_args['conflict'] ) ) {
			$input['policy'] = array( 'conflict' => (string) $assoc_args['conflict'] );
		}

		$result = $this->execute_ability( 'datamachine/gitsync-bind', $input );

		WP_CLI::success( (string) ( $result['message'] ?? 'Bound.' ) );
	}

	/**
	 * Unbind a GitSync binding.
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

		if ( ! empty( $result['purged'] ) ) {
			WP_CLI::success( sprintf( 'Unbound "%s" and purged %s', $slug, $result['local_path'] ?? '' ) );
		} else {
			WP_CLI::success( sprintf( 'Unbound "%s" (directory preserved: %s)', $slug, $result['local_path'] ?? '' ) );
		}
	}

	/**
	 * Pull the remote into a bound directory via GitHub Contents API.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Binding slug.
	 *
	 * [--allow-dirty]
	 * : Bypass the conflict policy for this pull only.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code gitsync pull intelligence-wiki
	 *
	 * @subcommand pull
	 */
	public function pull( array $args, array $assoc_args ): void {
		$slug = $args[0] ?? '';
		if ( '' === $slug ) {
			WP_CLI::error( 'Binding slug is required.' );
		}

		$result = $this->execute_ability(
			'datamachine/gitsync-pull',
			array(
				'slug'        => $slug,
				'allow_dirty' => ! empty( $assoc_args['allow-dirty'] ),
			)
		);

		$updated   = (array) ( $result['updated'] ?? array() );
		$deleted   = (array) ( $result['deleted'] ?? array() );
		$conflicts = (array) ( $result['conflicts'] ?? array() );
		$unchanged = (int) ( $result['unchanged'] ?? 0 );

		WP_CLI::success( sprintf(
			'Pulled %s: %d updated, %d unchanged, %d deleted, %d conflicts',
			$slug,
			count( $updated ),
			$unchanged,
			count( $deleted ),
			count( $conflicts )
		) );

		if ( ! empty( $conflicts ) ) {
			WP_CLI::warning( 'Conflicts (run with --allow-dirty to override, or change conflict policy):' );
			foreach ( $conflicts as $c ) {
				WP_CLI::log( sprintf( '  %s — %s', $c['path'] ?? '?', $c['reason'] ?? '?' ) );
			}
		}
		if ( ! empty( $result['truncated'] ) ) {
			WP_CLI::warning( 'GitHub tree response was truncated. Some paths may be missing. Consider narrowing the repo or upgrading to Git Data API pagination (not yet implemented).' );
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
	 * @subcommand status
	 */
	public function status( array $args, array $assoc_args ): void {
		$slug = $args[0] ?? '';
		if ( '' === $slug ) {
			WP_CLI::error( 'Binding slug is required.' );
		}
		$result = $this->execute_ability( 'datamachine/gitsync-status', array( 'slug' => $slug ) );

		$format = $assoc_args['format'] ?? 'table';
		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$rows = array(
			array(
				'field' => 'slug',
				'value' => (string) ( $result['slug'] ?? '' ),
			),
			array(
				'field' => 'local_path',
				'value' => (string) ( $result['local_path'] ?? '' ),
			),
			array(
				'field' => 'remote_url',
				'value' => (string) ( $result['remote_url'] ?? '' ),
			),
			array(
				'field' => 'tracked_branch',
				'value' => (string) ( $result['tracked_branch'] ?? '' ),
			),
			array(
				'field' => 'exists',
				'value' => ! empty( $result['exists'] ) ? 'yes' : 'no',
			),
			array(
				'field' => 'pulled_count',
				'value' => (string) ( (int) ( $result['pulled_count'] ?? 0 ) ),
			),
			array(
				'field' => 'last_pulled',
				'value' => (string) ( $result['last_pulled'] ?? '-' ),
			),
			array(
				'field' => 'last_commit',
				'value' => (string) ( $result['last_commit'] ?? '-' ),
			),
		);
		$this->format_items( $rows, array( 'field', 'value' ), $assoc_args, 'field' );
	}

	/**
	 * Submit local edits as a pull request.
	 *
	 * Uploads changed files to the sticky proposal branch (gitsync/<slug>)
	 * and opens or updates a PR against the pinned branch.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Binding slug.
	 *
	 * --message=<message>
	 * : Commit / PR title (8–200 chars).
	 *
	 * [--paths=<paths>]
	 * : Comma-separated list of relative paths to submit. If omitted,
	 *   every changed file under allowed_paths is included.
	 *
	 * [--title=<title>]
	 * : PR title. Defaults to --message.
	 *
	 * [--body=<body>]
	 * : PR body. Defaults to a generated summary.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code gitsync submit intelligence-wiki \
	 *       --message="Add CIAB kickoff article"
	 *
	 * @subcommand submit
	 */
	public function submit( array $args, array $assoc_args ): void {
		$slug = $args[0] ?? '';
		if ( '' === $slug ) {
			WP_CLI::error( 'Binding slug is required.' );
		}
		$message = (string) ( $assoc_args['message'] ?? '' );
		if ( '' === $message ) {
			WP_CLI::error( '--message is required.' );
		}

		$input = array(
			'slug'    => $slug,
			'message' => $message,
		);
		if ( isset( $assoc_args['paths'] ) && '' !== $assoc_args['paths'] ) {
			$input['paths'] = array_values( array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['paths'] ) ) ) );
		}
		if ( isset( $assoc_args['title'] ) ) {
			$input['title'] = (string) $assoc_args['title'];
		}
		if ( isset( $assoc_args['body'] ) ) {
			$input['body'] = (string) $assoc_args['body'];
		}

		$result = $this->execute_ability( 'datamachine/gitsync-submit', $input );

		$pr = $result['pr'] ?? array();
		WP_CLI::success( sprintf(
			'%s PR #%d on %s: %s',
			'updated' === ( $pr['action'] ?? '' ) ? 'Updated' : 'Opened',
			(int) ( $pr['number'] ?? 0 ),
			(string) ( $result['branch'] ?? '' ),
			(string) ( $pr['html_url'] ?? '' )
		) );
		WP_CLI::log( sprintf( '  commits: %d', count( (array) ( $result['commits'] ?? array() ) ) ) );
	}

	/**
	 * Commit changes directly to the pinned branch — no PR.
	 *
	 * Requires policy.write_enabled=true AND policy.safe_direct_push=true.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Binding slug.
	 *
	 * --message=<message>
	 * : Commit message base.
	 *
	 * [--paths=<paths>]
	 * : Comma-separated list of relative paths. If omitted, every changed
	 *   file under allowed_paths is committed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code gitsync push personal-wiki \
	 *       --message="Daily notes"
	 *
	 * @subcommand push
	 */
	public function push( array $args, array $assoc_args ): void {
		$slug = $args[0] ?? '';
		if ( '' === $slug ) {
			WP_CLI::error( 'Binding slug is required.' );
		}
		$message = (string) ( $assoc_args['message'] ?? '' );
		if ( '' === $message ) {
			WP_CLI::error( '--message is required.' );
		}

		$input = array(
			'slug'    => $slug,
			'message' => $message,
		);
		if ( isset( $assoc_args['paths'] ) && '' !== $assoc_args['paths'] ) {
			$input['paths'] = array_values( array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['paths'] ) ) ) );
		}

		$result = $this->execute_ability( 'datamachine/gitsync-push', $input );

		WP_CLI::success( sprintf( 'Pushed %d file(s) to %s on "%s"', count( (array) ( $result['commits'] ?? array() ) ), $result['branch'] ?? '', $slug ) );
	}

	/**
	 * Update policy fields on an existing binding.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Binding slug.
	 *
	 * [--write-enabled=<bool>]
	 * : Gate submit + push.
	 *
	 * [--safe-direct-push=<bool>]
	 * : Second key required for direct push to the pinned branch.
	 *
	 * [--allowed-paths=<list>]
	 * : Comma-separated relative path prefixes that may be uploaded. Use
	 *   "clear" to empty the allowlist.
	 *
	 * [--conflict=<strategy>]
	 * : Conflict strategy for pull.
	 * ---
	 * options:
	 *   - fail
	 *   - upstream_wins
	 *   - manual
	 * ---
	 *
	 * [--auto-pull=<bool>]
	 * : Mark binding for scheduled sync (honored when a future task consumes it).
	 *
	 * [--pull-interval=<interval>]
	 * : Scheduled pull cadence hint.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code gitsync policy intelligence-wiki \
	 *       --write-enabled=true --allowed-paths=articles/,images/
	 *
	 * @subcommand policy
	 */
	public function policy( array $args, array $assoc_args ): void {
		$slug = $args[0] ?? '';
		if ( '' === $slug ) {
			WP_CLI::error( 'Binding slug is required.' );
		}

		$patch     = array();
		$bool_keys = array(
			'write-enabled'    => 'write_enabled',
			'safe-direct-push' => 'safe_direct_push',
			'auto-pull'        => 'auto_pull',
		);
		foreach ( $bool_keys as $cli_key => $policy_key ) {
			if ( array_key_exists( $cli_key, $assoc_args ) ) {
				$patch[ $policy_key ] = $this->coerce_bool( (string) $assoc_args[ $cli_key ] );
			}
		}
		if ( isset( $assoc_args['conflict'] ) ) {
			$patch['conflict'] = (string) $assoc_args['conflict'];
		}
		if ( isset( $assoc_args['pull-interval'] ) ) {
			$patch['pull_interval'] = (string) $assoc_args['pull-interval'];
		}
		if ( isset( $assoc_args['allowed-paths'] ) ) {
			$raw = (string) $assoc_args['allowed-paths'];
			if ( 'clear' === trim( strtolower( $raw ) ) ) {
				$patch['allowed_paths'] = array();
			} else {
				$patch['allowed_paths'] = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
			}
		}

		if ( empty( $patch ) ) {
			WP_CLI::error( 'No policy flags provided.' );
		}

		$result = $this->execute_ability(
			'datamachine/gitsync-policy-update',
			array(
				'slug'   => $slug,
				'policy' => $patch,
			)
		);

		WP_CLI::success( sprintf( 'Policy updated for "%s".', $slug ) );
		WP_CLI::log( wp_json_encode( $result['policy'] ?? array(), JSON_PRETTY_PRINT ) );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

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

	private function coerce_bool( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
