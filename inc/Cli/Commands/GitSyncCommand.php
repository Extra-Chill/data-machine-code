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

	/**
	 * Stage paths for commit in a binding's working tree.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Binding slug.
	 *
	 * <paths>...
	 * : Relative paths inside the binding to stage. Must live under
	 *   policy.allowed_paths; sensitive-file patterns are always refused.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code gitsync add intelligence-wiki articles/new-article.md
	 *     wp datamachine-code gitsync add intelligence-wiki articles/a.md articles/b.md
	 *
	 * @subcommand add
	 */
	public function add( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$slug = $args[0] ?? '';
		if ( '' === $slug ) {
			WP_CLI::error( 'Binding slug is required.' );
		}
		$paths = array_slice( $args, 1 );
		if ( empty( $paths ) ) {
			WP_CLI::error( 'At least one path is required.' );
		}

		$result = $this->execute_ability(
			'datamachine/gitsync-add',
			array(
				'slug'  => $slug,
				'paths' => $paths,
			)
		);

		WP_CLI::success( (string) ( $result['message'] ?? 'Staged.' ) );
	}

	/**
	 * Commit staged changes in a binding's working tree.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Binding slug.
	 *
	 * --message=<message>
	 * : Commit message (8–200 characters).
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code gitsync commit intelligence-wiki \
	 *       --message="Add new CIAB article"
	 *
	 * @subcommand commit
	 */
	public function commit( array $args, array $assoc_args ): void {
		$slug = $args[0] ?? '';
		if ( '' === $slug ) {
			WP_CLI::error( 'Binding slug is required.' );
		}
		$message = (string) ( $assoc_args['message'] ?? '' );
		if ( '' === $message ) {
			WP_CLI::error( '--message is required.' );
		}

		$result = $this->execute_ability(
			'datamachine/gitsync-commit',
			array(
				'slug'    => $slug,
				'message' => $message,
			)
		);

		WP_CLI::success( (string) ( $result['message'] ?? 'Committed.' ) );
		if ( ! empty( $result['commit'] ) ) {
			WP_CLI::log( sprintf( '  head: %s', $result['commit'] ) );
		}
	}

	/**
	 * Direct-push a binding to its pinned branch on origin.
	 *
	 * Requires policy.push_enabled=true AND policy.safe_direct_push=true.
	 * For most workflows you want `submit` instead, which pushes to a
	 * feature branch and opens a PR.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Binding slug.
	 *
	 * [--force]
	 * : Use git push --force-with-lease. Default: false.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code gitsync push personal-wiki
	 *     wp datamachine-code gitsync push personal-wiki --force
	 *
	 * @subcommand push
	 */
	public function push( array $args, array $assoc_args ): void {
		$slug = $args[0] ?? '';
		if ( '' === $slug ) {
			WP_CLI::error( 'Binding slug is required.' );
		}

		$result = $this->execute_ability(
			'datamachine/gitsync-push',
			array(
				'slug'  => $slug,
				'force' => ! empty( $assoc_args['force'] ),
			)
		);

		WP_CLI::success( sprintf( 'Pushed %s → %s @ %s', $slug, (string) ( $result['branch'] ?? '' ), (string) ( $result['head'] ?? '-' ) ) );
		$output = trim( (string) ( $result['message'] ?? '' ) );
		if ( '' !== $output ) {
			WP_CLI::log( $output );
		}
	}

	/**
	 * Submit local edits as a pull request.
	 *
	 * Orchestrates the sticky proposal branch flow: align with upstream,
	 * push gitsync/<slug> with --force-with-lease, open or update a PR.
	 * Phase 2 supports github.com remotes only.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Binding slug.
	 *
	 * --message=<message>
	 * : Commit message (8–200 characters).
	 *
	 * [--paths=<paths>]
	 * : Comma-separated list of relative paths to stage. If omitted,
	 *   every dirty file under policy.allowed_paths is staged.
	 *
	 * [--title=<title>]
	 * : PR title. Defaults to the commit message.
	 *
	 * [--body=<body>]
	 * : PR body. Defaults to a generated summary.
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code gitsync submit intelligence-wiki \
	 *       --message="Add CIAB kickoff article"
	 *
	 *     wp datamachine-code gitsync submit intelligence-wiki \
	 *       --message="Update two articles" \
	 *       --paths=articles/a.md,articles/b.md \
	 *       --title="docs: update a + b"
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
		WP_CLI::log( sprintf( '  commit: %s', (string) ( $result['commit'] ?? '-' ) ) );
		WP_CLI::log( sprintf( '  files:  %d staged', count( (array) ( $result['staged'] ?? array() ) ) ) );
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
	 * : Gate add + commit.
	 *
	 * [--push-enabled=<bool>]
	 * : Gate push + submit.
	 *
	 * [--safe-direct-push=<bool>]
	 * : Second key required for direct push to the pinned branch.
	 *
	 * [--allowed-paths=<list>]
	 * : Comma-separated list of relative path prefixes that may be staged.
	 *   Use "clear" to reset to an empty allowlist.
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
	 * : Enroll in scheduled sync (Phase 3).
	 *
	 * [--pull-interval=<interval>]
	 * : Scheduled pull cadence.
	 *
	 * ## EXAMPLES
	 *
	 *     # Open up writes + submit for a wiki binding
	 *     wp datamachine-code gitsync policy intelligence-wiki \
	 *       --write-enabled=true --push-enabled=true \
	 *       --allowed-paths=articles/,images/
	 *
	 *     # Allow direct push for a personal (single-owner) binding
	 *     wp datamachine-code gitsync policy personal-wiki \
	 *       --push-enabled=true --safe-direct-push=true
	 *
	 * @subcommand policy
	 */
	public function policy( array $args, array $assoc_args ): void {
		$slug = $args[0] ?? '';
		if ( '' === $slug ) {
			WP_CLI::error( 'Binding slug is required.' );
		}

		$patch = array();
		$bool_keys = array(
			'write-enabled'    => 'write_enabled',
			'push-enabled'     => 'push_enabled',
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

	/**
	 * Coerce a CLI-provided string into a boolean. Treats common truthy
	 * strings (`true`, `1`, `yes`, `on`) as true; everything else as false.
	 */
	private function coerce_bool( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

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
