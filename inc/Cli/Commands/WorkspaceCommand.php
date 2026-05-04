<?php
/**
 * WP-CLI Workspace Command
 *
 * Provides CLI access to the agent workspace — a managed directory
 * for cloning repos and working with files outside the web root.
 *
 * All commands delegate to WordPress Abilities API primitives registered
 * in WorkspaceAbilities. The CLI layer handles argument parsing, confirmation
 * prompts, and output formatting only.
 *
 * @package DataMachineCode\Cli\Commands
 * @since 0.1.0
 */

namespace DataMachineCode\Cli\Commands;

use WP_CLI;
use DataMachine\Cli\BaseCommand;
use DataMachineCode\Maintenance\MaintenanceFlowProvisioner;
use DataMachineCode\Workspace\Workspace;

defined( 'ABSPATH' ) || exit;

class WorkspaceCommand extends BaseCommand {

	/**
	 * Show the workspace directory path.
	 *
	 * Displays the resolved workspace path. The path is determined by:
	 * 1. DATAMACHINE_WORKSPACE_PATH constant (if defined)
	 * 2. /var/lib/datamachine/workspace (if writable — VPS)
	 * 3. $HOME/.datamachine/workspace (local/macOS)
	 *
	 * ## OPTIONS
	 *
	 * [--ensure]
	 * : Create the directory if it doesn't exist.
	 *
	 * ## EXAMPLES
	 *
	 *     # Show workspace path
	 *     wp datamachine-code workspace path
	 *
	 *     # Show path and create if missing
	 *     wp datamachine-code workspace path --ensure
	 *
	 * @subcommand path
	 */
	public function path( array $args, array $assoc_args ): void {
		$ability = wp_get_ability( 'datamachine/workspace-path' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace path ability not available.' );
			return;
		}

		$result = $ability->execute( array(
			'ensure' => ! empty( $assoc_args['ensure'] ),
		) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( ! empty( $result['created'] ) ) {
			WP_CLI::success( sprintf( 'Created workspace: %s', $result['path'] ) );
			return;
		}

		WP_CLI::log( $result['path'] );

		if ( empty( $result['exists'] ) && empty( $assoc_args['ensure'] ) ) {
			WP_CLI::warning( 'Directory does not exist yet. Use --ensure to create it.' );
		}
	}

	/**
	 * Provision agent-owned workspace maintenance flows.
	 *
	 * Creates or updates the DMC maintenance pipeline/flow instances for a coding
	 * agent: inventory, metadata repair, artifact cleanup, retention cleanup, and
	 * emergency cleanup. The operation is idempotent and uses stable portable
	 * slugs, so rerunning setup updates existing flows instead of duplicating them.
	 *
	 * ## OPTIONS
	 *
	 * provision
	 * : Provision/update the maintenance flows.
	 *
	 * --agent=<agent>
	 * : Agent slug or numeric agent ID that should own the flows.
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
	 *     wp datamachine-code workspace maintenance-flows provision --agent=intelligence-chubes4
	 *
	 *     wp datamachine-code workspace maintenance-flows provision --agent=3 --format=json
	 *
	 * @subcommand maintenance-flows
	 */
	public function maintenance_flows( array $args, array $assoc_args ): void {
		$action = (string) ( $args[0] ?? '' );
		if ( 'provision' !== $action ) {
			WP_CLI::error( 'Usage: wp datamachine-code workspace maintenance-flows provision --agent=<agent>' );
			return;
		}

		$agent = (string) ( $assoc_args['agent'] ?? '' );
		if ( '' === trim( $agent ) ) {
			WP_CLI::error( '--agent=<slug|id> is required.' );
			return;
		}

		$input  = ctype_digit( $agent ) ? array( 'agent_id' => (int) $agent ) : array( 'agent' => $agent );
		$result = ( new MaintenanceFlowProvisioner() )->provision( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( 'json' === ( $assoc_args['format'] ?? '' ) ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		WP_CLI::success( sprintf( 'Provisioned DMC maintenance flows for agent %s (ID %d).', (string) $result['agent_slug'], (int) $result['agent_id'] ) );
		$items = array_map(
			static function ( array $flow ): array {
				return array(
					'slug'     => $flow['slug'],
					'status'   => $flow['status'],
					'flow_id'  => $flow['flow_id'],
					'interval' => $flow['interval'],
					'name'     => $flow['flow_name'],
				);
			},
			(array) ( $result['flows'] ?? array() )
		);
		$this->format_items( $items, array( 'slug', 'status', 'flow_id', 'interval', 'name' ), $assoc_args, 'slug' );
	}

	/**
	 * List repositories in the workspace.
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
	 *     # List workspace repos
	 *     wp datamachine-code workspace list
	 *
	 *     # List as JSON
	 *     wp datamachine-code workspace list --format=json
	 *
	 * @subcommand list
	 */
	public function list_repos( array $args, array $assoc_args ): void {
		$ability = wp_get_ability( 'datamachine/workspace-list' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace list ability not available.' );
			return;
		}

		$result = $ability->execute( array() );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( empty( $result['repos'] ) ) {
			WP_CLI::log( sprintf( 'No repos in workspace (%s).', $result['path'] ?? '' ) );
			WP_CLI::log( 'Clone one with: wp datamachine-code workspace clone <url>' );
			return;
		}

		$items = array_map(
			function ( $repo ) {
				return array(
					'name'   => $repo['name'],
					'kind'   => ! empty( $repo['is_worktree'] ) ? 'worktree' : 'primary',
					'repo'   => $repo['repo'] ?? $repo['name'],
					'branch' => $repo['branch'] ?? '-',
					'remote' => $repo['remote'] ?? '-',
					'git'    => $repo['git'] ? 'yes' : 'no',
					'path'   => $repo['path'],
				);
			},
			$result['repos']
		);

		$this->format_items(
			$items,
			array( 'name', 'kind', 'repo', 'branch', 'remote', 'git' ),
			$assoc_args,
			'name'
		);
	}

	/**
	 * Clone a git repository into the workspace.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Git repository URL to clone.
	 *
	 * [--name=<name>]
	 * : Directory name in workspace (derived from URL if omitted).
	 *
	 * ## EXAMPLES
	 *
	 *     # Clone a repo
	 *     wp datamachine-code workspace clone https://github.com/Extra-Chill/homeboy.git
	 *
	 *     # Clone with custom name
	 *     wp datamachine-code workspace clone https://github.com/Extra-Chill/homeboy.git --name=homeboy-dev
	 *
	 * @subcommand clone
	 */
	public function clone_repo( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Repository URL is required.' );
			return;
		}

		$ability = wp_get_ability( 'datamachine/workspace-clone' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace clone ability not available.' );
			return;
		}

		$input = array( 'url' => $args[0] );
		if ( ! empty( $assoc_args['name'] ) ) {
			$input['name'] = $assoc_args['name'];
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		WP_CLI::success( $result['message'] );
		WP_CLI::log( sprintf( 'Path: %s', $result['path'] ) );
	}

	/**
	 * Adopt an existing primary checkout already under the workspace root.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : Existing git primary checkout path to adopt.
	 *
	 * [--name=<name>]
	 * : Workspace name (derived from path basename if omitted).
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code workspace adopt /Users/chubes/Developer/homeboy --name=homeboy
	 *
	 * @subcommand adopt
	 */
	public function adopt_repo( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Checkout path is required.' );
			return;
		}

		$ability = wp_get_ability( 'datamachine/workspace-adopt' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace adopt ability not available.' );
			return;
		}

		$input = array( 'path' => $args[0] );
		if ( ! empty( $assoc_args['name'] ) ) {
			$input['name'] = $assoc_args['name'];
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		WP_CLI::success( $result['message'] );
		WP_CLI::log( sprintf( 'Name: %s', $result['name'] ) );
		WP_CLI::log( sprintf( 'Path: %s', $result['path'] ) );
	}

	/**
	 * Remove a repository from the workspace.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Repository directory name to remove.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Remove a repo (with confirmation)
	 *     wp datamachine-code workspace remove homeboy
	 *
	 *     # Remove without confirmation
	 *     wp datamachine-code workspace remove homeboy --yes
	 *
	 * @subcommand remove
	 */
	public function remove_repo( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Repository name is required.' );
			return;
		}

		$name = $args[0];

		// Confirm unless --yes is passed. This stays in CLI — abilities don't prompt.
		if ( empty( $assoc_args['yes'] ) ) {
			$workspace = new Workspace();
			$repo_path = $workspace->get_repo_path( $name );
			WP_CLI::confirm( sprintf( 'Remove "%s" from workspace? This deletes %s', $name, $repo_path ) );
		}

		$ability = wp_get_ability( 'datamachine/workspace-remove' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace remove ability not available.' );
			return;
		}

		$result = $ability->execute( array( 'name' => $name ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * Show a non-destructive workspace hygiene report.
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
	 * ---
	 *
	 * [--skip-cleanup]
	 * : Skip the local cleanup dry-run summary.
	 *
	 * [--skip-sizes]
	 * : Skip best-effort workspace size collection.
	 *
	 * [--include-worktree-status]
	 * : Include full per-worktree git status. This can be expensive on huge workspaces.
	 *
	 * [--size-limit=<count>]
	 * : Maximum top-level workspace entries to size.
	 * ---
	 * default: 1000
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp datamachine-code workspace hygiene
	 *     wp datamachine-code workspace hygiene --format=json
	 *
	 * @subcommand hygiene
	 */
	public function hygiene( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$ability = wp_get_ability( 'datamachine/workspace-hygiene-report' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace hygiene ability not available.' );
			return;
		}

		$input = array(
			'include_cleanup'         => empty( $assoc_args['skip-cleanup'] ),
			'include_sizes'           => empty( $assoc_args['skip-sizes'] ),
			'include_worktree_status' => ! empty( $assoc_args['include-worktree-status'] ),
		);
		if ( isset( $assoc_args['size-limit'] ) ) {
			$input['size_limit'] = (int) $assoc_args['size-limit'];
		}

		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		$this->render_workspace_hygiene_report( $result, $assoc_args );
	}

	/**
	 * Show detailed info about a workspace repo.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Repository directory name.
	 *
	 * ## EXAMPLES
	 *
	 *     # Show repo info
	 *     wp datamachine-code workspace show homeboy
	 *
	 * @subcommand show
	 */
	public function show( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Repository name is required.' );
			return;
		}

		$ability = wp_get_ability( 'datamachine/workspace-show' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace show ability not available.' );
			return;
		}

		$result = $ability->execute( array( 'name' => $args[0] ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		WP_CLI::log( sprintf( 'Name:     %s', $result['name'] ) );
		WP_CLI::log( sprintf( 'Path:     %s', $result['path'] ) );
		WP_CLI::log( sprintf( 'Branch:   %s', $result['branch'] ?? '-' ) );
		WP_CLI::log( sprintf( 'Remote:   %s', $result['remote'] ?? '-' ) );
		WP_CLI::log( sprintf( 'Latest:   %s', $result['commit'] ?? '-' ) );

		$dirty = $result['dirty'] ?? 0;
		WP_CLI::log( sprintf( 'Dirty:    %s', ( 0 === $dirty ) ? 'no' : "yes ({$dirty} files)" ) );
	}

	/**
	 * Read a file from a workspace repo.
	 *
	 * Reads text file contents from a cloned repository in the workspace.
	 * Binary files are detected and rejected. Large files are limited by
	 * --max-size (default 1 MB). Use --offset and --limit to read specific
	 * line ranges.
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Repository directory name.
	 *
	 * <path>
	 * : Relative file path within the repo.
	 *
	 * [--max-size=<bytes>]
	 * : Maximum file size in bytes.
	 * ---
	 * default: 1048576
	 * ---
	 *
	 * [--offset=<line>]
	 * : Line number to start reading from (1-indexed).
	 *
	 * [--limit=<lines>]
	 * : Maximum number of lines to return.
	 *
	 * ## EXAMPLES
	 *
	 *     # Read a file
	 *     wp datamachine-code workspace read homeboy src/main.rs
	 *
	 *     # Read with custom size limit
	 *     wp datamachine-code workspace read homeboy Cargo.toml --max-size=2097152
	 *
	 *     # Read lines 100-130 from a file
	 *     wp datamachine-code workspace read extrachill style.css --offset=100 --limit=30
	 *
	 * @subcommand read
	 */
	public function read( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || empty( $args[1] ) ) {
			WP_CLI::error( 'Usage: wp datamachine-code workspace read <repo> <path>' );
			return;
		}

		$ability = wp_get_ability( 'datamachine/workspace-read' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace read ability not available.' );
			return;
		}

		$input = array(
			'repo' => $args[0],
			'path' => $args[1],
		);

		if ( isset( $assoc_args['max-size'] ) ) {
			$input['max_size'] = (int) $assoc_args['max-size'];
		}

		if ( isset( $assoc_args['offset'] ) ) {
			$input['offset'] = (int) $assoc_args['offset'];
		}

		if ( isset( $assoc_args['limit'] ) ) {
			$input['limit'] = (int) $assoc_args['limit'];
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		// Output raw content — suitable for piping.
		WP_CLI::log( $result['content'] );
	}

	/**
	 * List directory contents within a workspace repo.
	 *
	 * Lists files and directories. Directories are listed first, then
	 * files, both sorted alphabetically.
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Repository directory name.
	 *
	 * [<path>]
	 * : Relative directory path within the repo (defaults to root).
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
	 *     # List repo root
	 *     wp datamachine-code workspace ls homeboy
	 *
	 *     # List subdirectory
	 *     wp datamachine-code workspace ls homeboy src/commands
	 *
	 *     # List as JSON
	 *     wp datamachine-code workspace ls homeboy --format=json
	 *
	 * @subcommand ls
	 */
	public function ls( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Usage: wp datamachine-code workspace ls <repo> [<path>]' );
			return;
		}

		$ability = wp_get_ability( 'datamachine/workspace-ls' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace ls ability not available.' );
			return;
		}

		$input = array( 'repo' => $args[0] );

		if ( ! empty( $args[1] ) ) {
			$input['path'] = $args[1];
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( empty( $result['entries'] ) ) {
			WP_CLI::log( 'Empty directory.' );
			return;
		}

		$items = array_map(
			function ( $entry ) {
				return array(
					'name' => $entry['name'],
					'type' => $entry['type'],
					'size' => isset( $entry['size'] ) ? size_format( $entry['size'] ) : '-',
				);
			},
			$result['entries']
		);

		$this->format_items(
			$items,
			array( 'name', 'type', 'size' ),
			$assoc_args,
			'name'
		);
	}

	/**
	 * Write a file to a workspace repo.
	 *
	 * Creates or overwrites a file. Parent directories are created as needed.
	 * Content can be passed via --content flag or piped via stdin.
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Repository directory name.
	 *
	 * <path>
	 * : Relative file path within the repo.
	 *
	 * [--content=<content>]
	 * : File content to write. Prefix with @ to read from a local file (e.g. --content=@/tmp/code.rs).
	 * : If omitted, reads from stdin.
	 *
	 * ## EXAMPLES
	 *
	 *     # Write with content flag
	 *     wp datamachine-code workspace write homeboy src/new.rs --content="fn main() {}"
	 *
	 *     # Write from a local file (@ syntax)
	 *     wp datamachine-code workspace write homeboy src/main.rs --content=@/tmp/staged-code.rs
	 *
	 *     # Write from stdin
	 *     cat local-file.rs | wp datamachine-code workspace write homeboy src/main.rs
	 *
	 * @subcommand write
	 */
	public function write( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || empty( $args[1] ) ) {
			WP_CLI::error( 'Usage: wp datamachine-code workspace write <repo> <path> --content=<content>' );
			return;
		}

		$ability = wp_get_ability( 'datamachine/workspace-write' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace write ability not available.' );
			return;
		}

		$content = $assoc_args['content'] ?? null;

		// Resolve @file syntax — read content from a local file.
		if ( null !== $content ) {
			$content = $this->resolveAtFile( $content );
		}

		// Read from stdin if --content not provided.
		if ( null === $content ) {
			if ( function_exists( 'posix_isatty' ) && posix_isatty( STDIN ) ) {
				WP_CLI::error( 'No content provided. Use --content=<content> or pipe content via stdin.' );
				return;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = file_get_contents( 'php://stdin' );
			if ( false === $content ) {
				WP_CLI::error( 'Failed to read from stdin.' );
				return;
			}
		}

		$result = $ability->execute( array(
			'repo'    => $args[0],
			'path'    => $args[1],
			'content' => $content,
		) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		$action = ! empty( $result['created'] ) ? 'Created' : 'Updated';
		WP_CLI::success( sprintf( '%s %s (%s)', $action, $result['path'], size_format( $result['size'] ) ) );
	}

	/**
	 * Edit a file in a workspace repo via find-and-replace.
	 *
	 * Performs exact string replacement. Fails if the old string is not found,
	 * or if multiple matches exist (unless --replace-all is used).
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Repository directory name.
	 *
	 * <path>
	 * : Relative file path within the repo.
	 *
	 * --old=<string>
	 * : Text to find. Prefix with @ to read from a local file (e.g. --old=@/tmp/old.txt).
	 *
	 * --new=<string>
	 * : Replacement text. Prefix with @ to read from a local file (e.g. --new=@/tmp/new.txt).
	 *
	 * [--replace-all]
	 * : Replace all occurrences instead of requiring a unique match.
	 *
	 * ## EXAMPLES
	 *
	 *     # Replace a single occurrence
	 *     wp datamachine-code workspace edit homeboy src/main.rs --old="old_func" --new="new_func"
	 *
	 *     # Replace using @ file syntax
	 *     wp datamachine-code workspace edit homeboy src/main.rs --old=@/tmp/old.txt --new=@/tmp/new.txt
	 *
	 *     # Replace all occurrences
	 *     wp datamachine-code workspace edit homeboy src/main.rs --old="v1" --new="v2" --replace-all
	 *
	 * @subcommand edit
	 */
	public function edit( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || empty( $args[1] ) ) {
			WP_CLI::error( 'Usage: wp datamachine-code workspace edit <repo> <path> --old=<string> --new=<string>' );
			return;
		}

		if ( ! isset( $assoc_args['old'] ) || ! isset( $assoc_args['new'] ) ) {
			WP_CLI::error( 'Both --old and --new flags are required.' );
			return;
		}

		$ability = wp_get_ability( 'datamachine/workspace-edit' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace edit ability not available.' );
			return;
		}

		$input = array(
			'repo'       => $args[0],
			'path'       => $args[1],
			'old_string' => $this->resolveAtFile( $assoc_args['old'] ),
			'new_string' => $this->resolveAtFile( $assoc_args['new'] ),
		);

		if ( ! empty( $assoc_args['replace-all'] ) ) {
			$input['replace_all'] = true;
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		$count = $result['replacements'] ?? 1;
		WP_CLI::success( sprintf(
			'Edited %s (%d replacement%s)',
			$result['path'],
			$count,
			1 === $count ? '' : 's'
		) );
	}

	/**
	 * Delete a tracked or untracked path from a workspace repo.
	 *
	 * Tracked paths are removed via `git rm` (working tree + index in one
	 * shot). Untracked paths are unlinked from disk; directories require
	 * --recursive. Sensitive-path, traversal, allowlist, and primary-mutation
	 * gates apply just like `workspace git add`.
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Workspace handle: `<repo>` (primary) or `<repo>@<branch-slug>` (worktree).
	 *
	 * <path>
	 * : Relative path within the repo (file or directory).
	 *
	 * [--recursive]
	 * : Required when target is a directory.
	 *
	 * [--allow-primary-mutation]
	 * : Permit mutation on the primary checkout (default off). Worktrees are always allowed.
	 *
	 * ## EXAMPLES
	 *
	 *     # Delete a tracked file (staged via git rm)
	 *     wp datamachine-code workspace delete homeboy@my-branch src/old_module.rs
	 *
	 *     # Delete an entire directory tree
	 *     wp datamachine-code workspace delete homeboy@my-branch src/legacy --recursive
	 *
	 *     # Delete on the primary checkout (rare, gated)
	 *     wp datamachine-code workspace delete homeboy notes.md --allow-primary-mutation
	 *
	 * @subcommand delete
	 */
	public function delete( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || empty( $args[1] ) ) {
			WP_CLI::error( 'Usage: wp datamachine-code workspace delete <repo> <path> [--recursive] [--allow-primary-mutation]' );
			return;
		}

		$ability = wp_get_ability( 'datamachine/workspace-delete' );
		if ( ! $ability ) {
			WP_CLI::error( 'Workspace delete ability not available.' );
			return;
		}

		$input = array(
			'repo' => $args[0],
			'path' => $args[1],
		);

		if ( ! empty( $assoc_args['recursive'] ) ) {
			$input['recursive'] = true;
		}
		if ( ! empty( $assoc_args['allow-primary-mutation'] ) ) {
			$input['allow_primary_mutation'] = true;
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		$count = is_array( $result['deleted'] ?? null ) ? count( $result['deleted'] ) : 1;
		$mode  = ! empty( $result['was_tracked'] ) ? 'git rm' : 'unlink';
		WP_CLI::success( sprintf(
			'Deleted %s via %s (%d path%s removed)',
			$result['path'],
			$mode,
			$count,
			1 === $count ? '' : 's'
		) );
	}

	/**
	 * Collect every `--<flag>=<value>` occurrence from the raw process argv.
	 *
	 * WP-CLI's parsed `$assoc_args` is last-wins for repeated assoc flags —
	 * it never returns an array, even when the synopsis declares the flag as
	 * repeatable (`...`). For commands that document a flag as repeatable
	 * (`--rel`, `--include`, etc.), we walk $GLOBALS['argv'] directly so
	 * every occurrence is preserved in argv order.
	 *
	 * Empty values are filtered out. Bare `--<flag>` (no value) is ignored
	 * because it's ambiguous in the assoc-flag context.
	 *
	 * @param string $flag Flag name without the leading `--` (e.g. 'rel').
	 * @return string[] Every value, in argv order, for the named flag.
	 */
	private function collectRepeatableFlag( string $flag ): array {
		$argv = $GLOBALS['argv'] ?? array();
		if ( ! is_array( $argv ) ) {
			return array();
		}

		$prefix     = '--' . $flag . '=';
		$prefix_len = strlen( $prefix );
		$values     = array();

		foreach ( $argv as $token ) {
			if ( ! is_string( $token ) ) {
				continue;
			}
			if ( 0 === strpos( $token, $prefix ) ) {
				$value = substr( $token, $prefix_len );
				if ( '' !== $value ) {
					$values[] = $value;
				}
			}
		}

		return $values;
	}

	/**
	 * Resolve @file syntax — if a string starts with @, read file contents.
	 *
	 * Mirrors curl's -d @filename convention. If the value doesn't start
	 * with @, it's returned unchanged.
	 *
	 * @param string $value Raw CLI argument value.
	 * @return string Resolved content (file contents or original value).
	 */
	private function resolveAtFile( string $value ): string {
		if ( 0 !== strpos( $value, '@' ) ) {
			return $value;
		}

		$file_path = substr( $value, 1 );

		if ( empty( $file_path ) ) {
			WP_CLI::error( 'Empty file path after @. Usage: --content=@/path/to/file' );
		}

		if ( ! file_exists( $file_path ) ) {
			WP_CLI::error( sprintf( 'File not found: %s', $file_path ) );
		}

		if ( ! is_readable( $file_path ) ) {
			WP_CLI::error( sprintf( 'File not readable: %s', $file_path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $file_path );

		if ( false === $content ) {
			WP_CLI::error( sprintf( 'Failed to read file: %s', $file_path ) );
		}

		return $content;
	}

	/**
	 * Git operations for workspace repositories.
	 *
	 * ## OPTIONS
	 *
	 * <operation>
	 * : Git operation: status, pull, add, commit, push, log, diff
	 *
	 * <repo>
	 * : Repository directory name.
	 *
	 * [<value>]
	 * : Optional operation value (e.g., commit message for commit).
	 *
	 * [--rel=<path>]
	 * : Relative path (repeatable) for add/diff operations. Named `--rel`
	 *   to avoid colliding with WP-CLI's documented global `--path` flag.
	 *
	 * [--allow-dirty]
	 * : Allow pull with dirty working tree.
	 *
	 * [--allow-primary-mutation]
	 * : Permit mutating ops (pull/add/commit/push) on the primary checkout. Default-deny — use a worktree handle (`<repo>@<branch-slug>`) instead whenever possible.
	 *
	 * [--remote=<remote>]
	 * : Remote name for push (default: origin).
	 *
	 * [--branch=<branch>]
	 * : Branch override for push.
	 *
	 * [--from=<ref>]
	 * : From ref for diff.
	 *
	 * [--to=<ref>]
	 * : To ref for diff.
	 *
	 * [--staged]
	 * : Show staged diff.
	 *
	 * [--limit=<n>]
	 * : Number of log entries to return (default 20).
	 *
	 * ## EXAMPLES
	 *
	 *     # Show git status for a workspace repo
	 *     wp datamachine-code workspace git status data-machine
	 *
	 *     # Pull latest changes
	 *     wp datamachine-code workspace git pull data-machine
	 *
	 *     # Stage docs paths
	 *     wp datamachine-code workspace git add extrachill-docs --rel=ec_docs/community/getting-started.md
	 *
	 *     # Commit staged changes
	 *     wp datamachine-code workspace git commit extrachill-docs "docs: update community guide"
	 *
	 *     # Push current branch to origin
	 *     wp datamachine-code workspace git push extrachill-docs --remote=origin
	 *
	 *     # Show recent log
	 *     wp datamachine-code workspace git log data-machine --limit=10
	 *
	 *     # Show diff for a path
	 *     wp datamachine-code workspace git diff data-machine --rel=inc/Core/FilesRepository/Workspace.php
	 *
	 * @subcommand git
	 */
	public function git( array $args, array $assoc_args ): void {
		$operation = $args[0] ?? '';
		$repo      = $args[1] ?? '';

		if ( '' === $operation || '' === $repo ) {
			WP_CLI::error( 'Usage: wp datamachine-code workspace git <operation> <repo> [<value>] [--flags]' );
			return;
		}

		$ability_name = match ( $operation ) {
			'status' => 'datamachine/workspace-git-status',
			'pull'   => 'datamachine/workspace-git-pull',
			'add'    => 'datamachine/workspace-git-add',
			'commit' => 'datamachine/workspace-git-commit',
			'push'   => 'datamachine/workspace-git-push',
			'log'    => 'datamachine/workspace-git-log',
			'diff'   => 'datamachine/workspace-git-diff',
			default  => '',
		};

		if ( '' === $ability_name ) {
			WP_CLI::error( sprintf( 'Unknown git operation: %s', $operation ) );
			return;
		}

		$ability = wp_get_ability( $ability_name );
		if ( ! $ability ) {
			WP_CLI::error( sprintf( 'Workspace git ability not available: %s', $ability_name ) );
			return;
		}

		$input = array( 'name' => $repo );

		// Mutating ops accept --allow-primary-mutation to operate on a primary checkout.
		if ( in_array( $operation, array( 'pull', 'add', 'commit', 'push' ), true ) ) {
			$input['allow_primary_mutation'] = ! empty( $assoc_args['allow-primary-mutation'] );
		}

		if ( 'pull' === $operation ) {
			$input['allow_dirty'] = ! empty( $assoc_args['allow-dirty'] );
		}

		if ( 'add' === $operation ) {
			$input['paths'] = $this->collectRepeatableFlag( 'rel' );

			if ( empty( $input['paths'] ) ) {
				WP_CLI::error( 'git add requires at least one --rel=<relative/path>.' );
				return;
			}
		}

		if ( 'commit' === $operation ) {
			$message = $args[2] ?? '';
			if ( '' === trim( $message ) ) {
				WP_CLI::error( 'git commit requires a commit message as the third argument.' );
				return;
			}
			$input['message'] = $message;
		}

		if ( 'push' === $operation ) {
			$input['remote'] = $assoc_args['remote'] ?? 'origin';
			if ( ! empty( $assoc_args['branch'] ) ) {
				$input['branch'] = (string) $assoc_args['branch'];
			}
		}

		if ( 'log' === $operation ) {
			if ( isset( $assoc_args['limit'] ) ) {
				$input['limit'] = (int) $assoc_args['limit'];
			}
		}

		if ( 'diff' === $operation ) {
			if ( isset( $assoc_args['from'] ) ) {
				$input['from'] = (string) $assoc_args['from'];
			}
			if ( isset( $assoc_args['to'] ) ) {
				$input['to'] = (string) $assoc_args['to'];
			}
			if ( ! empty( $assoc_args['staged'] ) ) {
				$input['staged'] = true;
			}
			$diff_paths = $this->collectRepeatableFlag( 'rel' );
			if ( ! empty( $diff_paths ) ) {
				$input['path'] = (string) $diff_paths[0];
			}
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		$this->renderGitOperationResult( $operation, $result, $assoc_args );
	}

	/**
	 * Render CLI output for workspace git operations.
	 *
	 * @param string $operation Git operation.
	 * @param array  $result    Ability result.
	 * @param array  $assoc_args CLI assoc args.
	 */
	private function renderGitOperationResult( string $operation, array $result, array $assoc_args ): void {
		switch ( $operation ) {
			case 'status':
				WP_CLI::log( sprintf( 'Repo:   %s', $result['name'] ?? '-' ) );
				WP_CLI::log( sprintf( 'Path:   %s', $result['path'] ?? '-' ) );
				WP_CLI::log( sprintf( 'Branch: %s', $result['branch'] ?? '-' ) );
				WP_CLI::log( sprintf( 'Remote: %s', $result['remote'] ?? '-' ) );
				WP_CLI::log( sprintf( 'Latest: %s', $result['commit'] ?? '-' ) );
				$dirty = (int) ( $result['dirty'] ?? 0 );
				WP_CLI::log( sprintf( 'Dirty:  %s', 0 === $dirty ? 'no' : "yes ({$dirty} files)" ) );
				if ( ! empty( $result['files'] ) ) {
					WP_CLI::log( '' );
					foreach ( $result['files'] as $file ) {
						WP_CLI::log( (string) $file );
					}
				}
				return;

			case 'log':
				if ( empty( $result['entries'] ) ) {
					WP_CLI::log( 'No commits found.' );
					return;
				}

				$items = array_map(
					fn( $entry ) => array(
						'hash'    => $entry['hash'] ?? '',
						'author'  => $entry['author'] ?? '',
						'date'    => $entry['date'] ?? '',
						'subject' => $entry['subject'] ?? '',
					),
					$result['entries']
				);

				$this->format_items( $items, array( 'hash', 'author', 'date', 'subject' ), $assoc_args, 'hash' );
				return;

			case 'diff':
				WP_CLI::log( (string) ( $result['diff'] ?? '' ) );
				return;

			default:
				WP_CLI::success( $result['message'] ?? 'Workspace git operation completed.' );
				return;
		}
	}

	/**
	 * Manage workspace git worktrees.
	 *
	 * Worktrees let multiple agent sessions work on the same repo without
	 * stepping on each other. Each branch lives in its own directory at
	 * `<workspace>/<repo>@<branch-slug>`. Branch slashes become dashes in
	 * the slug (`fix/foo` → `fix-foo`).
	 *
	 * ## OPTIONS
	 *
	 * <operation>
	 * : Worktree operation: add, list, remove, prune, cleanup, cleanup-artifacts,
	 *   reconcile-metadata, refresh-context, finalize, mark-cleanup-eligible.
	 *
	 * [<repo>]
	 * : Primary repo name (required for add and remove). For refresh-context, finalize,
	 *   and mark-cleanup-eligible,
	 *   pass the full worktree handle (`<repo>@<branch-slug>`) here instead.
	 *
	 * [<branch>]
	 * : Branch name (required for add and remove).
	 *
	 * [--from=<ref>]
	 * : Base ref when creating a branch on add (default origin/HEAD).
	 *
	 * [--base-branch=<branch>]
	 * : Convenience alias for branch-shaped bases. `--base-branch=main`
	 *   maps to `--from=origin/main`. Use `--from` for exact refs.
	 *
	 * [--skip-context-injection]
	 * : Skip injecting the originating site's agent context into a new
	 *   worktree (applies to `add` only). Default behavior is to write
	 *   `.claude/CLAUDE.local.md` and `.opencode/AGENTS.local.md` containing
	 *   the site's MEMORY.md / USER.md / RULES.md snapshot, and add both
	 *   paths to the repository's `info/exclude`. The ability-level input
	 *   is `inject_context=false`; this flag is the CLI shorthand.
	 *
	 * [--skip-bootstrap]
	 * : Skip the default bootstrap pass (applies to `add` only). By default,
	 *   `worktree add` runs detected setup so the checkout is immediately
	 *   test/build-ready:
	 *     - git submodule update --init --recursive (if .gitmodules)
	 *     - pnpm/bun/yarn/npm install (based on root or one-level nested lockfiles)
	 *     - composer install --no-interaction (based on root or one-level nested composer.lock)
	 *   Each step is skipped gracefully when its trigger file or tool is
	 *   missing. Pass `--skip-bootstrap` to create a bare checkout for pure
	 *   read use (faster, no deps installed). The ability-level input is
	 *   `bootstrap=false`; this flag is the CLI shorthand (matches the
	 *   existing `--skip-context-injection` convention).
	 *
	 * [--allow-stale]
	 * : Bypass the staleness gate (applies to `add` only). By default,
	 *   `worktree add` refuses to return a worktree that would be more than
	 *   `datamachine_worktree_stale_threshold` commits (default 50) behind
	 *   upstream — the stale checkout is torn down and a `worktree_stale`
	 *   error is returned with remediation options. Pass `--allow-stale` to
	 *   opt in to a known-stale checkout. The ability-level input is
	 *   `allow_stale=true`.
	 *
	 * [--rebase-base]
	 * : After creating the worktree, rebase onto the upstream tip (applies
	 *   to `add` only). For existing branches this is `@{upstream}`; for
	 *   new branches cut off a local base this is `origin/<base>`. On
	 *   rebase conflicts the rebase is aborted and the worktree stays at
	 *   its pre-rebase state — `--rebase-base` is not a silent
	 *   `--allow-stale` bypass. The ability-level input is
	 *   `rebase_base=true`.
	 *
	 * [--force]
	 * : For `add`, explicitly bypass the disk-budget refusal threshold. For
	 *   `remove`, force-remove a worktree even if it is dirty. For `cleanup`,
	 *   force dirty-worktree removal but not the unpushed-commits safety.
	 *
	 * [--pr=<url-or-number>]
	 * : Attach pull request metadata when finalizing or marking a worktree
	 *   cleanup-eligible.
	 *
	 * [--state=<state>]
	 * : Lifecycle state to record when finalizing a worktree.
	 *
	 * [--dry-run]
	 * : Preview cleanup candidates without removing anything (cleanup only).
	 *
	 * [--apply-plan=<file>]
	 * : Apply a previously reviewed cleanup JSON report. The destructive pass
	 *   revalidates every planned row and removes only exact current matches.
	 *   For reconcile-metadata, applies a reviewed non-destructive metadata plan.
	 *
	 * [--skip-github]
	 * : Skip the GitHub API lookup and rely only on the local `upstream-gone`
	 *   signal (cleanup only). Faster, but misses merged branches where the
	 *   remote branch wasn't auto-deleted.
	 *
	 * [--inventory-only]
	 * : Build a dry-run review from cheap top-level inventory and explicit
	 *   lifecycle cleanup signals only (cleanup only). Avoids full git worktree
	 *   scans, per-worktree status checks, and GitHub lookups.
	 *
	 * [--older-than=<duration>]
	 * : Limit cleanup candidates to worktrees with lifecycle `created_at`
	 *   metadata older than the compact duration (cleanup only, e.g. 7d, 24h).
	 *   Candidate worktrees without valid `created_at` metadata are skipped.
	 *
	 * [--sort=<field>]
	 * : Sort cleanup candidates by reporting field (cleanup only).
	 * ---
	 * options:
	 *   - size
	 *   - age
	 * ---
	 *
	 * [--stale]
	 * : For list, show only worktrees with a stale_reason (old, dirty, or missing metadata).
	 *
	 * [--verbose]
	 * : Show every cleanup row instead of concise samples (cleanup only).
	 *
	 * [--only=<section>]
	 * : Limit cleanup rows to one section or reason code. Supported values:
	 *   candidates, would-remove, would_remove, removed, no_merge_signal, dirty_worktree,
	 *   unpushed_commits, missing_metadata, external_worktree, age_filter, unknown_age.
	 *
	 * [--pr=<url-or-number>]
	 * : Pull request URL or number for `finalize` or `mark-cleanup-eligible`
	 *   metadata. Supplying `--pr` to `finalize` defaults the requested finalizer
	 *   state to `pr_opened` and records the worktree as cleanup-eligible.
	 *
	 * [--state=<state>]
	 * : Requested lifecycle state for `finalize`. Defaults to `pr_opened` when
	 *   `--pr` is supplied, otherwise `active`. PR/completed states are safely
	 *   transitioned to cleanup eligibility metadata.
	 *
	 * [--format=<format>]
	 * : Output format for list and cleanup (table, json, csv, yaml).
	 *
	 * ## EXAMPLES
	 *
	 *     # Create a worktree for fix/foo on data-machine
	 *     wp datamachine-code workspace worktree add data-machine fix/foo
	 *
	 *     # Create off a specific base
	 *     wp datamachine-code workspace worktree add data-machine feat/bar --from=origin/develop
	 *     wp datamachine-code workspace worktree add data-machine feat/bar --base-branch=develop
	 *
	 *     # List all worktrees
	 *     wp datamachine-code workspace worktree list
	 *
	 *     # List worktrees for one repo
	 *     wp datamachine-code workspace worktree list data-machine
	 *
	 *     # Remove a worktree
	 *     wp datamachine-code workspace worktree remove data-machine fix/foo
	 *
	 *     # Force-remove a dirty worktree
	 *     wp datamachine-code workspace worktree remove data-machine fix/foo --force
	 *
	 *     # Prune stale worktree registry entries across all primaries
	 *     wp datamachine-code workspace worktree prune
	 *
	 *     # Preview worktrees that would be removed (upstream gone or PR merged)
	 *     wp datamachine-code workspace worktree cleanup --dry-run
	 *
	 *     # Remove all merged worktrees
	 *     wp datamachine-code workspace worktree cleanup
	 *
	 *     # Review a plan, then apply only those rows after revalidation
	 *     wp datamachine-code workspace worktree cleanup --dry-run --format=json > cleanup-plan.json
	 *     wp datamachine-code workspace worktree cleanup --apply-plan=cleanup-plan.json
	 *
	 *     # Review and apply artifact-only cleanup without removing worktrees
	 *     wp datamachine-code workspace worktree cleanup-artifacts --dry-run --format=json > artifact-plan.json
	 *     wp datamachine-code workspace worktree cleanup-artifacts --apply-plan=artifact-plan.json
	 *
	 *     # Emergency disk-pressure plan: cheap inventory first, artifacts before worktrees
	 *     wp datamachine-code workspace worktree emergency-cleanup --format=json > emergency-plan.json
	 *     wp datamachine-code workspace worktree emergency-cleanup --apply-plan=emergency-plan.json
	 *
	 *     # Local-only detection (no GitHub API call)
	 *     wp datamachine-code workspace worktree cleanup --skip-github
	 *
	 *     # Bounded review on huge workspaces (no per-worktree git probes)
	 *     wp datamachine-code workspace worktree cleanup --dry-run --inventory-only --skip-github --format=json
	 *     wp datamachine-code workspace worktree reconcile-metadata --dry-run --format=json > reconcile-plan.json
	 *     wp datamachine-code workspace worktree reconcile-metadata --apply-plan=reconcile-plan.json
	 *
	 *     # Ignore dirty working-tree safety (caution)
	 *     wp datamachine-code workspace worktree cleanup --force
	 *
	 *     # Create a worktree without injecting site-agent context
	 *     wp datamachine-code workspace worktree add data-machine fix/foo --skip-context-injection
	 *
	 *     # Create a bare worktree (skip the default bootstrap pass)
	 *     wp datamachine-code workspace worktree add data-machine fix/foo --skip-bootstrap
	 *
	 *     # Proceed with a known-stale base (bypass the staleness gate)
	 *     wp datamachine-code workspace worktree add data-machine fix/foo --allow-stale
	 *
	 *     # Auto-rebase onto upstream after creation
	 *     wp datamachine-code workspace worktree add data-machine fix/foo --rebase-base
	 *
	 *     # Re-read the originating site's agent memory into an existing worktree
	 *     wp datamachine-code workspace worktree refresh-context data-machine@fix-foo
	 *
	 *     # Attach PR/finalizer metadata to a worktree
	 *     wp datamachine-code workspace worktree finalize data-machine@fix-foo --pr=https://github.com/org/repo/pull/123
	 *     wp datamachine-code workspace worktree mark-cleanup-eligible data-machine@fix-foo
	 *
	 * @subcommand worktree
	 */
	public function worktree( array $args, array $assoc_args ): void {
		$operation = $args[0] ?? '';

		if ( '' === $operation ) {
			WP_CLI::error( 'Usage: wp datamachine-code workspace worktree <add|list|remove|prune|cleanup|cleanup-artifacts|emergency-cleanup|reconcile-metadata|refresh-context|finalize|mark-cleanup-eligible> [<repo>] [<branch>] [--flags]' );
			return;
		}

		$ability_name = match ( $operation ) {
			'add'                   => 'datamachine/workspace-worktree-add',
			'list'                  => 'datamachine/workspace-worktree-list',
			'remove'                => 'datamachine/workspace-worktree-remove',
			'prune'                 => 'datamachine/workspace-worktree-prune',
			'cleanup'               => 'datamachine/workspace-worktree-cleanup',
			'cleanup-artifacts'     => 'datamachine/workspace-worktree-cleanup-artifacts',
			'emergency-cleanup'     => 'datamachine/workspace-worktree-emergency-cleanup',
			'reconcile-metadata'    => 'datamachine/workspace-worktree-reconcile-metadata',
			'refresh-context'       => 'datamachine/workspace-worktree-refresh-context',
			'finalize'              => 'datamachine/workspace-worktree-finalize',
			'mark-cleanup-eligible' => 'datamachine/workspace-worktree-finalize',
			default                 => '',
		};

		if ( '' === $ability_name ) {
			WP_CLI::error( sprintf( 'Unknown worktree operation: %s', $operation ) );
			return;
		}

		$ability = wp_get_ability( $ability_name );
		if ( ! $ability ) {
			WP_CLI::error( sprintf( 'Worktree ability not available: %s', $ability_name ) );
			return;
		}

		$input = array();

		switch ( $operation ) {
			case 'add':
				if ( empty( $args[1] ) || empty( $args[2] ) ) {
					WP_CLI::error( 'Usage: worktree add <repo> <branch> [--from=<ref>|--base-branch=<branch>] [--skip-context-injection] [--skip-bootstrap] [--allow-stale] [--rebase-base] [--force]' );
					return;
				}
				$input['repo']   = $args[1];
				$input['branch'] = $args[2];
				if ( ! empty( $assoc_args['from'] ) && ! empty( $assoc_args['base-branch'] ) ) {
					WP_CLI::error( 'Use either --from=<ref> or --base-branch=<branch>, not both.' );
					return;
				}
				if ( ! empty( $assoc_args['from'] ) ) {
					$input['from'] = (string) $assoc_args['from'];
				} elseif ( ! empty( $assoc_args['base-branch'] ) ) {
					$input['from'] = self::base_branch_to_ref( (string) $assoc_args['base-branch'] );
				}
				// --skip-context-injection disables the default-on injection step.
				$input['inject_context'] = empty( $assoc_args['skip-context-injection'] );
				// --skip-bootstrap disables the default-on bootstrap step.
				$input['bootstrap'] = empty( $assoc_args['skip-bootstrap'] );
				// --allow-stale opts in to a known-stale worktree (default: gate enforced).
				$input['allow_stale'] = ! empty( $assoc_args['allow-stale'] );
				// --rebase-base auto-rebases onto upstream after creation (default: off).
				$input['rebase_base'] = ! empty( $assoc_args['rebase-base'] );
				// --force is an explicit disk-budget override for add.
				$input['force'] = ! empty( $assoc_args['force'] );
				break;

			case 'refresh-context':
				if ( empty( $args[1] ) ) {
					WP_CLI::error( 'Usage: worktree refresh-context <handle>' );
					return;
				}
				$input['handle'] = (string) $args[1];
				break;

			case 'finalize':
				if ( empty( $args[1] ) ) {
					WP_CLI::error( 'Usage: worktree finalize <handle> [--pr=<url-or-number>] [--state=<state>]' );
					return;
				}
				$input['handle'] = (string) $args[1];
				$input['state']  = isset( $assoc_args['state'] ) && '' !== trim( (string) $assoc_args['state'] ) ? (string) $assoc_args['state'] : ( isset( $assoc_args['pr'] ) ? 'pr_opened' : 'active' );
				if ( isset( $assoc_args['pr'] ) && '' !== trim( (string) $assoc_args['pr'] ) ) {
					$input['pr'] = (string) $assoc_args['pr'];
				}
				break;

			case 'mark-cleanup-eligible':
				if ( empty( $args[1] ) ) {
					WP_CLI::error( 'Usage: worktree mark-cleanup-eligible <handle> [--pr=<url-or-number>]' );
					return;
				}
				$input['handle'] = (string) $args[1];
				$input['state']  = 'cleanup_eligible';
				if ( isset( $assoc_args['pr'] ) && '' !== trim( (string) $assoc_args['pr'] ) ) {
					$input['pr'] = (string) $assoc_args['pr'];
				}
				break;

			case 'list':
				if ( ! empty( $args[1] ) ) {
					$input['repo'] = $args[1];
				}
				if ( isset( $assoc_args['state'] ) && '' !== trim( (string) $assoc_args['state'] ) ) {
					$input['state'] = (string) $assoc_args['state'];
				}
				break;

			case 'remove':
				if ( empty( $args[1] ) || empty( $args[2] ) ) {
					WP_CLI::error( 'Usage: worktree remove <repo> <branch> [--force]' );
					return;
				}
				$input['repo']   = $args[1];
				$input['branch'] = $args[2];
				$input['force']  = ! empty( $assoc_args['force'] );
				break;

			case 'cleanup':
				$input['dry_run']        = ! empty( $assoc_args['dry-run'] );
				$input['force']          = ! empty( $assoc_args['force'] );
				$input['skip_github']    = ! empty( $assoc_args['skip-github'] );
				$input['inventory_only'] = ! empty( $assoc_args['inventory-only'] );
				if ( ! in_array( (string) ( $assoc_args['format'] ?? '' ), array( 'json', 'yaml' ), true ) ) {
					$input['progress_callback'] = function ( array $event ): void {
						$this->render_worktree_cleanup_progress( $event );
					};
				}
				if ( ! empty( $assoc_args['apply-plan'] ) ) {
					if ( ! empty( $assoc_args['force'] ) ) {
						WP_CLI::error( 'Do not combine --apply-plan with --force. Plan application always revalidates and refuses dirty worktrees.' );
						return;
					}
					$input['apply_plan'] = $this->read_worktree_cleanup_plan( (string) $assoc_args['apply-plan'] );
				}
				if ( isset( $assoc_args['older-than'] ) && '' !== trim( (string) $assoc_args['older-than'] ) ) {
					$input['older_than'] = trim( (string) $assoc_args['older-than'] );
				}
				if ( isset( $assoc_args['sort'] ) && '' !== trim( (string) $assoc_args['sort'] ) ) {
					$input['sort'] = trim( (string) $assoc_args['sort'] );
				}
				break;
			case 'reconcile-metadata':
				$input['dry_run'] = ! empty( $assoc_args['dry-run'] );
				if ( ! empty( $assoc_args['apply-plan'] ) ) {
					$input['apply_plan'] = $this->read_worktree_json_plan( (string) $assoc_args['apply-plan'], 'metadata reconciliation' );
				}
				break;

			case 'cleanup-artifacts':
				$input['dry_run'] = ! empty( $assoc_args['dry-run'] );
				$input['force']   = ! empty( $assoc_args['force'] );
				if ( ! empty( $assoc_args['apply-plan'] ) ) {
					$input['apply_plan'] = $this->read_worktree_cleanup_plan( (string) $assoc_args['apply-plan'] );
				}
				break;

			case 'emergency-cleanup':
				$input['dry_run'] = true;
				$input['force']   = ! empty( $assoc_args['force'] );
				if ( ! empty( $assoc_args['apply-plan'] ) ) {
					$input['dry_run']    = false;
					$input['apply_plan'] = $this->read_worktree_json_plan( (string) $assoc_args['apply-plan'], 'emergency cleanup' );
				}
				break;
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		$this->renderWorktreeResult( $operation, $result, $assoc_args );
	}

	/**
	 * Render CLI output for worktree operations.
	 *
	 * @param string $operation  Worktree operation.
	 * @param array  $result     Ability result.
	 * @param array  $assoc_args CLI assoc args.
	 */
	private function renderWorktreeResult( string $operation, array $result, array $assoc_args ): void {
		if ( 'add' === $operation && 'json' === (string) ( $assoc_args['format'] ?? '' ) ) {
			$json = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			WP_CLI::log( false === $json ? '{}' : $json );
			return;
		}

		switch ( $operation ) {
			case 'list':
				$worktrees = $result['worktrees'] ?? array();
				if ( ! empty( $assoc_args['stale'] ) ) {
					$worktrees = array_values( array_filter( $worktrees, fn( $wt ) => ! empty( $wt['stale_reason'] ) ) );
				}
				if ( empty( $worktrees ) ) {
					WP_CLI::log( 'No worktrees found.' );
					return;
				}
				$items  = array_map(
					fn( $wt ) => array(
						'handle'              => $wt['handle'] ?? '',
						'repo'                => $wt['repo'] ?? '',
						'kind'                => ! empty( $wt['is_primary'] ) ? 'primary' : 'worktree',
						'branch'              => $wt['branch'] ?? '-',
						'head'                => isset( $wt['head'] ) ? substr( (string) $wt['head'], 0, 7 ) : '-',
						'dirty'               => (int) ( $wt['dirty'] ?? 0 ),
						'created_at'          => $wt['created_at'] ?? null,
						'state'               => $wt['lifecycle_state'] ?? null,
						'pr'                  => $wt['pr_url'] ?? null,
						'age_days'            => $wt['age_days'] ?? null,
						'size_bytes'          => $wt['size_bytes'] ?? null,
						'size'                => $this->format_bytes( $wt['size_bytes'] ?? null ),
						'artifact_size_bytes' => $wt['artifact_size_bytes'] ?? 0,
						'artifacts'           => $this->format_bytes( $wt['artifact_size_bytes'] ?? 0 ),
						'artifact_paths'      => $wt['artifacts'] ?? array(),
						'stale'               => $wt['stale_reason'] ?? '',
						'metadata'            => $wt['metadata'] ?? null,
						'path'                => $wt['path'] ?? '',
					),
					$worktrees
				);
				$fields = array( 'handle', 'repo', 'kind', 'branch', 'head', 'dirty', 'state', 'created_at', 'pr', 'age_days', 'size', 'artifacts', 'stale', 'path' );
				if ( in_array( (string) ( $assoc_args['format'] ?? '' ), array( 'json', 'yaml' ), true ) ) {
					$fields = array( 'handle', 'repo', 'kind', 'branch', 'head', 'dirty', 'state', 'created_at', 'pr', 'age_days', 'size_bytes', 'artifact_size_bytes', 'artifact_paths', 'stale', 'metadata', 'path' );
				}
				$this->format_items( $items, $fields, $assoc_args, 'handle' );
				return;

			case 'prune':
				$pruned = $result['pruned'] ?? array();
				if ( empty( $pruned ) ) {
					WP_CLI::log( 'Nothing to prune.' );
					return;
				}
				WP_CLI::success( sprintf( 'Pruned worktree registry across: %s', implode( ', ', $pruned ) ) );
				return;

			case 'cleanup':
				$this->render_worktree_cleanup_result( $result, $assoc_args );
				return;
			case 'reconcile-metadata':
				$this->render_worktree_metadata_reconciliation_result( $result, $assoc_args );
				return;

			case 'cleanup-artifacts':
				$this->render_worktree_artifact_cleanup_result( $result, $assoc_args );
				return;

			case 'emergency-cleanup':
				$this->render_worktree_emergency_cleanup_result( $result, $assoc_args );
				return;

			case 'add':
				WP_CLI::success( $result['message'] ?? 'Worktree created.' );
				if ( isset( $result['disk_budget'] ) && is_array( $result['disk_budget'] ) ) {
					$budget = $result['disk_budget'];
					WP_CLI::log( \DataMachineCode\Workspace\WorktreeDiskBudget::format_summary( $budget ) );
					foreach ( (array) ( $budget['warnings'] ?? array() ) as $warning ) {
						WP_CLI::warning( $warning );
					}
					if ( ! empty( $budget['force_override_applied'] ) ) {
						WP_CLI::warning( 'Disk budget override applied because --force was explicit.' );
					}
				}
				if ( ! empty( $result['handle'] ) ) {
					WP_CLI::log( sprintf( 'Handle: %s', $result['handle'] ) );
					WP_CLI::log( sprintf( 'Path:   %s', $result['path'] ?? '-' ) );
					WP_CLI::log( sprintf( 'Branch: %s%s', $result['branch'] ?? '-', ! empty( $result['created_branch'] ) ? ' (created)' : '' ) );
				}
				if ( isset( $result['context_injected'] ) ) {
					if ( ! empty( $result['context_injected'] ) ) {
						$written = $result['context_files'] ?? array();
						WP_CLI::log( sprintf( 'Context: injected (%d file%s)', count( $written ), 1 === count( $written ) ? '' : 's' ) );
						foreach ( $written as $file ) {
							WP_CLI::log( '  - ' . $file );
						}
						if ( ! empty( $result['context_exclude_path'] ) ) {
							WP_CLI::log( sprintf( 'Excluded via: %s', $result['context_exclude_path'] ) );
						}
					} else {
						$reason = $result['context_skip_reason'] ?? 'unknown';
						WP_CLI::log( sprintf( 'Context: not injected (%s)', $reason ) );
					}
				}
				if ( isset( $result['bootstrap'] ) && is_array( $result['bootstrap'] ) ) {
					$bs      = $result['bootstrap'];
					$ok      = ! empty( $bs['success'] );
					$ran_any = ! empty( $bs['ran_any'] );
					$label   = $ok ? ( $ran_any ? 'Bootstrap: ok' : 'Bootstrap: nothing to do' ) : 'Bootstrap: one or more steps FAILED';
					WP_CLI::log( $label );
					WP_CLI::log( \DataMachineCode\Workspace\WorktreeBootstrapper::format( $bs ) );
					if ( ! $ok ) {
						WP_CLI::warning( 'Worktree was created but bootstrap had failures. Re-run the failing step manually, or remove and retry.' );
					}
				}
				$this->render_worktree_freshness( $result );
				return;

			case 'refresh-context':
				WP_CLI::success( $result['message'] ?? 'Worktree context refreshed.' );
				WP_CLI::log( sprintf( 'Handle: %s', $result['handle'] ?? '-' ) );
				WP_CLI::log( sprintf( 'Path:   %s', $result['path'] ?? '-' ) );
				foreach ( (array) ( $result['written'] ?? array() ) as $file ) {
					WP_CLI::log( '  - ' . $file );
				}
				if ( ! empty( $result['exclude_path'] ) ) {
					WP_CLI::log( sprintf( 'Exclude file: %s', $result['exclude_path'] ) );
				}
				if ( ! empty( $result['metadata']['site_url'] ) ) {
					WP_CLI::log( sprintf( 'Originating site: %s', $result['metadata']['site_url'] ) );
				}
				return;

			case 'finalize':
			case 'mark-cleanup-eligible':
				WP_CLI::success( $result['message'] ?? 'Worktree finalized.' );
				WP_CLI::log( sprintf( 'Handle: %s', $result['handle'] ?? '-' ) );
				WP_CLI::log( sprintf( 'State:  %s', $result['lifecycle_state'] ?? '-' ) );
				if ( ! empty( $result['metadata']['pr_url'] ) ) {
					WP_CLI::log( sprintf( 'PR:     %s', $result['metadata']['pr_url'] ) );
				}
				return;

			case 'remove':
			default:
				WP_CLI::success( $result['message'] ?? 'Worktree operation complete.' );
				return;
		}
	}

	/**
	 * Render workspace hygiene report output.
	 *
	 * @param array $report     Hygiene report.
	 * @param array $assoc_args CLI args.
	 * @return void
	 */
	private function render_workspace_hygiene_report( array $report, array $assoc_args ): void {
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			$json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			WP_CLI::log( false === $json ? '{}' : $json );
			return;
		}

		$size            = (array) ( $report['size'] ?? array() );
		$disk            = (array) ( $report['disk'] ?? array() );
		$worktrees       = (array) ( $report['worktrees'] ?? array() );
		$cleanup         = (array) ( $report['cleanup'] ?? array() );
		$cleanup_summary = (array) ( $cleanup['summary'] ?? array() );

		WP_CLI::log( 'Workspace hygiene:' );
		$this->format_items(
			array(
				array(
					'metric' => 'workspace_path',
					'value'  => (string) ( $report['workspace_path'] ?? '' ),
				),
				array(
					'metric' => 'workspace_size',
					'value'  => (string) ( $size['total_human'] ?? '-' ),
				),
				array(
					'metric' => 'size_mode',
					'value'  => (string) ( $size['mode'] ?? '-' ),
				),
				array(
					'metric' => 'size_scan_complete',
					'value'  => ! empty( $size['scan_complete'] ) ? 'yes' : 'no',
				),
				array(
					'metric' => 'disk_free',
					'value'  => (string) ( $disk['free_human'] ?? '-' ),
				),
				array(
					'metric' => 'worktree_status_mode',
					'value'  => (string) ( $report['worktree_status_mode'] ?? '-' ),
				),
				array(
					'metric' => 'worktree_count',
					'value'  => (string) ( $worktrees['worktrees'] ?? 0 ),
				),
				array(
					'metric' => 'artifact_dirs',
					'value'  => (string) ( $worktrees['artifacts'] ?? 0 ),
				),
				array(
					'metric' => 'dirty_protected',
					'value'  => (string) ( $worktrees['protected_dirty'] ?? 0 ),
				),
				array(
					'metric' => 'unpushed_protected',
					'value'  => (string) ( $worktrees['protected_unpushed'] ?? 0 ),
				),
				array(
					'metric' => 'missing_metadata',
					'value'  => (string) ( $worktrees['missing_metadata'] ?? 0 ),
				),
				array(
					'metric' => 'external_worktrees',
					'value'  => (string) ( $worktrees['external'] ?? 0 ),
				),
				array(
					'metric' => 'cleanup_candidates',
					'value'  => (string) ( $cleanup_summary['would_remove'] ?? 0 ),
				),
			),
			array( 'metric', 'value' ),
			array( 'format' => 'table' ),
			'metric'
		);

		$top_size = array_slice( (array) ( $report['top_repos_by_size'] ?? array() ), 0, 10 );
		$by_kind  = array_slice( (array) ( $size['by_kind'] ?? array() ), 0, 10 );
		$entries  = array_slice( (array) ( $size['top_entries'] ?? array() ), 0, 10 );

		if ( array() !== $by_kind ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Workspace size by kind:' );
			$this->format_items(
				array_map(
					fn( $row ) => array(
						'kind'  => $row['kind'] ?? '',
						'size'  => $row['human'] ?? '',
						'bytes' => $row['bytes'] ?? 0,
					),
					$by_kind
				),
				array( 'kind', 'size', 'bytes' ),
				array( 'format' => 'table' ),
				'bytes'
			);
		}

		if ( array() !== $entries ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Top workspace entries by size:' );
			$this->format_items(
				array_map(
					fn( $row ) => array(
						'handle' => $row['handle'] ?? '',
						'kind'   => $row['kind'] ?? '',
						'repo'   => $row['repo'] ?? '',
						'size'   => $row['human'] ?? '',
						'bytes'  => $row['bytes'] ?? 0,
					),
					$entries
				),
				array( 'handle', 'kind', 'repo', 'size', 'bytes' ),
				array( 'format' => 'table' ),
				'bytes'
			);
		}

		if ( array() !== $top_size ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Top repos by size:' );
			$this->format_items(
				array_map(
					fn( $row ) => array(
						'repo'  => $row['repo'] ?? '',
						'size'  => $row['human'] ?? '',
						'bytes' => $row['bytes'] ?? 0,
					),
					$top_size
				),
				array( 'repo', 'size', 'bytes' ),
				array( 'format' => 'table' ),
				'bytes'
			);
		}

		$top_counts = array_slice( (array) ( $report['top_repos_by_worktrees'] ?? array() ), 0, 10 );
		if ( array() !== $top_counts ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Top repos by worktree count:' );
			$this->format_items( $top_counts, array( 'repo', 'worktree_count' ), array( 'format' => 'table' ), 'worktree_count' );
		}

		$candidates = array_slice( (array) ( $cleanup['biggest_candidates'] ?? array() ), 0, 10 );
		if ( array() !== $candidates ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Cleanup candidates:' );
			$this->format_items(
				array_map(
					fn( $row ) => array(
						'handle' => $row['handle'] ?? '',
						'branch' => $row['branch'] ?? '',
						'signal' => $row['signal'] ?? '',
						'size'   => $row['size_human'] ?? '',
					),
					$candidates
				),
				array( 'handle', 'branch', 'signal', 'size' ),
				array( 'format' => 'table' ),
				'handle'
			);
		}

		if ( ! empty( $report['suggested_cleanup_command'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Suggested cleanup review:' );
			WP_CLI::log( (string) $report['suggested_cleanup_command'] );
		}

		foreach ( (array) ( $report['notes'] ?? array() ) as $note ) {
			WP_CLI::log( 'Note: ' . $note );
		}
	}

	/**
	 * Render cleanup output with a machine-safe JSON contract and concise tables.
	 *
	 * @param array $result     Cleanup ability result.
	 * @param array $assoc_args CLI assoc args.
	 * @return void
	 */
	private function render_worktree_cleanup_result( array $result, array $assoc_args ): void {
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$only   = isset( $assoc_args['only'] ) ? $this->normalize_worktree_cleanup_only( (string) $assoc_args['only'] ) : '';
		$report = $this->filter_worktree_cleanup_report( $result, $only );

		if ( 'json' === $format ) {
			$json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			WP_CLI::log( false === $json ? '{}' : $json );
			return;
		}

		$candidates = $report['candidates'] ?? array();
		$removed    = $report['removed'] ?? array();
		$skipped    = $report['skipped'] ?? array();
		$summary    = $report['summary'] ?? array();
		$dry_run    = ! empty( $report['dry_run'] );
		$verbose    = ! empty( $assoc_args['verbose'] );
		$limit      = $verbose ? PHP_INT_MAX : 10;

		if ( empty( $candidates ) && empty( $removed ) && empty( $skipped ) ) {
			WP_CLI::log( 'No worktrees found.' );
			return;
		}

		WP_CLI::log( 'Summary:' );
		$summary_rows = array(
			array(
				'metric' => 'would_remove',
				'count'  => (int) ( $summary['would_remove'] ?? count( $candidates ) ),
			),
			array(
				'metric' => 'removed',
				'count'  => (int) ( $summary['removed'] ?? count( $removed ) ),
			),
			array(
				'metric' => 'skipped',
				'count'  => (int) ( $summary['skipped'] ?? count( $skipped ) ),
			),
		);
		foreach ( (array) ( $summary['skipped_by_reason'] ?? array() ) as $reason_code => $count ) {
			$summary_rows[] = array(
				'metric' => 'skipped:' . $reason_code,
				'count'  => (int) $count,
			);
		}
		if ( isset( $summary['age_filter'] ) && is_array( $summary['age_filter'] ) ) {
			$summary_rows[] = array(
				'metric' => 'age_filter:excluded',
				'count'  => (int) ( $summary['age_filter']['excluded'] ?? 0 ),
			);
			$summary_rows[] = array(
				'metric' => 'age_filter:unknown_age',
				'count'  => (int) ( $summary['age_filter']['unknown_age'] ?? 0 ),
			);
		}
		$summary_rows[] = array(
			'metric' => 'total_size',
			'count'  => $this->format_bytes( $summary['total_size_bytes'] ?? null ),
		);
		$summary_rows[] = array(
			'metric' => 'artifact_size',
			'count'  => $this->format_bytes( $summary['artifact_size_bytes'] ?? null ),
		);
		$this->format_items( $summary_rows, array( 'metric', 'count' ), array( 'format' => 'table' ), 'metric' );

		if ( ! empty( $summary['size_by_repo'] ) && is_array( $summary['size_by_repo'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Top repos by worktree size:' );
			$repo_rows = array();
			foreach ( array_slice( $summary['size_by_repo'], 0, 5, true ) as $repo => $bytes ) {
				$repo_rows[] = array(
					'repo' => (string) $repo,
					'size' => $this->format_bytes( $bytes ),
				);
			}
			$this->format_items( $repo_rows, array( 'repo', 'size' ), array( 'format' => 'table' ), 'size' );
		}

		if ( '' !== $only ) {
			WP_CLI::log( sprintf( 'Filter: %s', $only ) );
		}

		if ( ! empty( $candidates ) && ( '' === $only || 'candidates' === $only ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( $dry_run ? 'Would remove:' : 'Candidates:' );
			$candidate_rows = array_map(
				fn( $c ) => array(
					'handle'      => $c['handle'] ?? '',
					'branch'      => $c['branch'] ?? '',
					'age_days'    => $c['age_days'] ?? '',
					'size'        => $this->format_bytes( $c['size_bytes'] ?? null ),
					'artifacts'   => $this->format_bytes( $c['artifact_size_bytes'] ?? 0 ),
					'signal'      => $c['signal'] ?? '',
					'reason_code' => $c['reason_code'] ?? ( $c['signal'] ?? '' ),
					'reason'      => $c['reason'] ?? '',
				),
				array_slice( $candidates, 0, $limit )
			);
			$fields         = $verbose ? array( 'handle', 'branch', 'age_days', 'size', 'artifacts', 'signal', 'reason' ) : array( 'handle', 'branch', 'age_days', 'size', 'artifacts', 'signal', 'reason_code' );
			$this->format_items( $candidate_rows, $fields, array( 'format' => 'table' ), 'handle' );
			$this->render_cleanup_truncation_hint( count( $candidates ), $limit, 'candidate rows' );
		}

		if ( ! empty( $removed ) && ( '' === $only || 'removed' === $only ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Removed:' );
			$removed_rows = array_map(
				fn( $c ) => array(
					'handle'      => $c['handle'] ?? '',
					'branch'      => $c['branch'] ?? '',
					'age_days'    => $c['age_days'] ?? '',
					'size'        => $this->format_bytes( $c['size_bytes'] ?? null ),
					'artifacts'   => $this->format_bytes( $c['artifact_size_bytes'] ?? 0 ),
					'signal'      => $c['signal'] ?? '',
					'reason_code' => $c['reason_code'] ?? ( $c['signal'] ?? '' ),
					'reason'      => $c['reason'] ?? '',
				),
				array_slice( $removed, 0, $limit )
			);
			$fields       = $verbose ? array( 'handle', 'branch', 'age_days', 'size', 'artifacts', 'signal', 'reason' ) : array( 'handle', 'branch', 'age_days', 'size', 'artifacts', 'signal', 'reason_code' );
			$this->format_items( $removed_rows, $fields, array( 'format' => 'table' ), 'handle' );
			$this->render_cleanup_truncation_hint( count( $removed ), $limit, 'removed rows' );
		}

		if ( ! empty( $skipped ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Skipped:' );
			$skipped_rows = array_map(
				fn( $s ) => array(
					'handle'       => $s['handle'] ?? '',
					'reason_code'  => $s['reason_code'] ?? '',
					'reason'       => $verbose ? ( $s['reason'] ?? '' ) : $this->shorten_cleanup_reason( (string) ( $s['reason'] ?? '' ) ),
					'age_days'     => $s['age_days'] ?? '',
					'size'         => $this->format_bytes( $s['size_bytes'] ?? null ),
					'artifacts'    => $this->format_bytes( $s['artifact_size_bytes'] ?? 0 ),
					'repo'         => $s['repo'] ?? '',
					'branch'       => $s['branch'] ?? '',
					'path'         => $s['path'] ?? '',
					'primary_path' => $s['primary_path'] ?? '',
					'missing'      => implode( ',', (array) ( $s['missing_fields'] ?? array() ) ),
					'hint'         => $s['hint'] ?? '',
				),
				array_slice( $skipped, 0, $limit )
			);
			$fields       = $verbose ? array( 'handle', 'reason_code', 'reason', 'age_days', 'size', 'artifacts', 'repo', 'branch', 'path', 'primary_path', 'missing', 'hint' ) : array( 'handle', 'reason_code', 'age_days', 'size', 'artifacts', 'reason' );
			$this->format_items( $skipped_rows, $fields, array( 'format' => 'table' ), 'handle' );
			$this->render_cleanup_truncation_hint( count( $skipped ), $limit, 'skipped rows' );
		}

		WP_CLI::log( '' );
		if ( $dry_run ) {
			WP_CLI::success( sprintf( '%d worktree(s) would be removed. Re-run without --dry-run to apply.', count( $result['candidates'] ?? array() ) ) );
			return;
		}
		WP_CLI::success( sprintf( 'Removed %d worktree(s); %d skipped.', count( $result['removed'] ?? array() ), count( $result['skipped'] ?? array() ) ) );
	}

	/**
	 * Render one human cleanup progress line.
	 *
	 * @param array $event Progress event.
	 * @return void
	 */
	private function render_worktree_cleanup_progress( array $event ): void {
		$label = (string) ( $event['event'] ?? 'progress' );
		if ( 'start' === $label ) {
			WP_CLI::log( sprintf( 'Cleanup progress: starting scan of %d worktree(s).', (int) ( $event['total'] ?? 0 ) ) );
			return;
		}

		WP_CLI::log(
			sprintf(
				'Cleanup progress: %s%s checked=%d/%d candidates=%d skipped=%d removed=%d elapsed=%.1fs',
				$label,
				(string) ( $event['handle'] ?? '' ) !== '' ? ' handle=' . (string) $event['handle'] : '',
				(int) ( $event['checked'] ?? 0 ),
				(int) ( $event['total'] ?? 0 ),
				(int) ( $event['candidates'] ?? 0 ),
				(int) ( $event['skipped'] ?? 0 ),
				(int) ( $event['removed'] ?? 0 ),
				(float) ( $event['elapsed'] ?? 0 )
			)
		);
	}

	/**
	 * Render metadata reconciliation output.
	 *
	 * @param array $result     Reconciliation ability result.
	 * @param array $assoc_args CLI assoc args.
	 * @return void
	 */
	private function render_worktree_metadata_reconciliation_result( array $result, array $assoc_args ): void {
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			$json = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			WP_CLI::log( false === $json ? '{}' : $json );
			return;
		}

		$summary            = (array) ( $result['summary'] ?? array() );
		$proposals          = (array) ( $result['proposals'] ?? array() );
		$repaired           = (array) ( $result['repaired'] ?? array() );
		$written            = (array) ( $result['written'] ?? array() );
		$skipped            = (array) ( $result['skipped'] ?? array() );
		$still_unsafe       = (array) ( $result['still_unsafe'] ?? array() );
		$external_worktrees = (array) ( $result['external_worktrees'] ?? array() );
		$verbose            = ! empty( $assoc_args['verbose'] );
		$limit              = $verbose ? PHP_INT_MAX : 10;

		WP_CLI::log( 'Summary:' );
		$summary_rows = array(
			array(
				'metric' => 'inspected',
				'count'  => (int) ( $summary['inspected'] ?? 0 ),
			),
			array(
				'metric' => 'proposed',
				'count'  => (int) ( $summary['proposed'] ?? count( $proposals ) ),
			),
			array(
				'metric' => 'written',
				'count'  => (int) ( $summary['written'] ?? count( $written ) ),
			),
			array(
				'metric' => 'repaired',
				'count'  => (int) ( $summary['repaired'] ?? count( $repaired ) ),
			),
			array(
				'metric' => 'skipped',
				'count'  => (int) ( $summary['skipped'] ?? count( $skipped ) ),
			),
		);
		foreach ( (array) ( $summary['skipped_by_reason'] ?? array() ) as $reason_code => $count ) {
			$summary_rows[] = array(
				'metric' => 'skipped:' . $reason_code,
				'count'  => (int) $count,
			);
		}
		foreach ( (array) ( $summary['proposed_by_state'] ?? array() ) as $state => $count ) {
			$summary_rows[] = array(
				'metric' => 'state:' . $state,
				'count'  => (int) $count,
			);
		}
		$this->format_items( $summary_rows, array( 'metric', 'count' ), array( 'format' => 'table' ), 'metric' );

		if ( ! empty( $proposals ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( ! empty( $result['dry_run'] ) ? 'Would write metadata:' : 'Reviewed proposals:' );
			$proposal_rows = array_map(
				fn( $row ) => array(
					'handle'   => $row['handle'] ?? '',
					'branch'   => $row['branch'] ?? '',
					'state'    => $row['proposed_metadata']['lifecycle_state'] ?? '',
					'missing'  => implode( ',', (array) ( $row['missing_fields'] ?? array() ) ),
					'dirty'    => (int) ( $row['dirty'] ?? 0 ),
					'unpushed' => (int) ( $row['unpushed'] ?? 0 ),
				),
				array_slice( $proposals, 0, $limit )
			);
			$this->format_items( $proposal_rows, array( 'handle', 'branch', 'state', 'missing', 'dirty', 'unpushed' ), array( 'format' => 'table' ), 'handle' );
			$this->render_cleanup_truncation_hint( count( $proposals ), $limit, 'proposal rows' );
		}

		if ( ! empty( $written ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Repaired:' );
			$written_rows = array_map(
				fn( $row ) => array(
					'handle'      => $row['handle'] ?? '',
					'branch'      => $row['branch'] ?? '',
					'state'       => $row['metadata']['lifecycle_state'] ?? '',
					'observed_at' => $row['metadata']['observed_at'] ?? '',
				),
				array_slice( $written, 0, $limit )
			);
			$this->format_items( $written_rows, array( 'handle', 'branch', 'state', 'observed_at' ), array( 'format' => 'table' ), 'handle' );
			$this->render_cleanup_truncation_hint( count( $written ), $limit, 'written rows' );
		}

		if ( ! empty( $still_unsafe ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Still unsafe:' );
			$unsafe_rows = array_map(
				fn( $row ) => array(
					'handle'      => $row['handle'] ?? '',
					'reason_code' => $row['reason_code'] ?? '',
					'reason'      => $verbose ? ( $row['reason'] ?? '' ) : $this->shorten_cleanup_reason( (string) ( $row['reason'] ?? '' ) ),
				),
				array_slice( $still_unsafe, 0, $limit )
			);
			$this->format_items( $unsafe_rows, array( 'handle', 'reason_code', 'reason' ), array( 'format' => 'table' ), 'handle' );
			$this->render_cleanup_truncation_hint( count( $still_unsafe ), $limit, 'still-unsafe rows' );
		}

		if ( ! empty( $external_worktrees ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'External worktrees:' );
			$external_rows = array_map(
				fn( $row ) => array(
					'handle' => $row['handle'] ?? '',
					'repo'   => $row['repo'] ?? '',
					'branch' => $row['branch'] ?? '',
					'path'   => $row['path'] ?? '',
				),
				array_slice( $external_worktrees, 0, $limit )
			);
			$this->format_items( $external_rows, array( 'handle', 'repo', 'branch', 'path' ), array( 'format' => 'table' ), 'handle' );
			$this->render_cleanup_truncation_hint( count( $external_worktrees ), $limit, 'external worktree rows' );
		}

		if ( ! empty( $skipped ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Skipped:' );
			$skipped_rows = array_map(
				fn( $row ) => array(
					'handle'      => $row['handle'] ?? '',
					'reason_code' => $row['reason_code'] ?? '',
					'reason'      => $verbose ? ( $row['reason'] ?? '' ) : $this->shorten_cleanup_reason( (string) ( $row['reason'] ?? '' ) ),
				),
				array_slice( $skipped, 0, $limit )
			);
			$this->format_items( $skipped_rows, array( 'handle', 'reason_code', 'reason' ), array( 'format' => 'table' ), 'handle' );
			$this->render_cleanup_truncation_hint( count( $skipped ), $limit, 'skipped rows' );
		}

		WP_CLI::log( '' );
		if ( ! empty( $result['dry_run'] ) ) {
			WP_CLI::success( sprintf( '%d metadata reconciliation proposal(s). Review JSON, then apply with --apply-plan.', count( $proposals ) ) );
			return;
		}
		WP_CLI::success( sprintf( 'Wrote metadata for %d worktree(s); %d skipped.', count( $written ), count( $skipped ) ) );
	}

	/**
	 * Render artifact-only cleanup output.
	 *
	 * @param array $result     Artifact cleanup ability result.
	 * @param array $assoc_args CLI assoc args.
	 * @return void
	 */
	private function render_worktree_artifact_cleanup_result( array $result, array $assoc_args ): void {
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			$json = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			WP_CLI::log( false === $json ? '{}' : $json );
			return;
		}

		$candidates = (array) ( $result['candidates'] ?? array() );
		$removed    = (array) ( $result['removed'] ?? array() );
		$skipped    = (array) ( $result['skipped'] ?? array() );
		$summary    = (array) ( $result['summary'] ?? array() );
		$dry_run    = ! empty( $result['dry_run'] );

		if ( empty( $candidates ) && empty( $removed ) && empty( $skipped ) ) {
			WP_CLI::log( 'No worktree artifacts found.' );
			return;
		}

		WP_CLI::log( 'Artifact cleanup summary:' );
		$this->format_items(
			array(
				array(
					'metric' => 'would_remove_artifacts',
					'count'  => (int) ( $summary['would_remove_artifacts'] ?? 0 ),
				),
				array(
					'metric' => 'removed_artifacts',
					'count'  => (int) ( $summary['removed_artifacts'] ?? 0 ),
				),
				array(
					'metric' => 'skipped_worktrees',
					'count'  => (int) ( $summary['skipped'] ?? count( $skipped ) ),
				),
				array(
					'metric' => 'artifact_size',
					'count'  => $this->format_bytes( $summary['artifact_size_bytes'] ?? null ),
				),
			),
			array( 'metric', 'count' ),
			array( 'format' => 'table' ),
			'metric'
		);

		if ( ! empty( $candidates ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( $dry_run ? 'Would remove artifacts:' : 'Artifact candidates:' );
			$this->format_items( $this->flatten_artifact_cleanup_rows( $candidates ), array( 'handle', 'repo', 'branch', 'artifact', 'size', 'path' ), array( 'format' => 'table' ), 'handle' );
		}

		if ( ! empty( $removed ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Removed artifacts:' );
			$this->format_items( $this->flatten_artifact_cleanup_rows( $removed ), array( 'handle', 'repo', 'branch', 'artifact', 'size', 'path' ), array( 'format' => 'table' ), 'handle' );
		}

		if ( ! empty( $skipped ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Skipped worktrees:' );
			$rows = array_map(
				fn( $row ) => array(
					'handle'      => $row['handle'] ?? '',
					'repo'        => $row['repo'] ?? '',
					'branch'      => $row['branch'] ?? '',
					'artifacts'   => count( (array) ( $row['artifacts'] ?? array() ) ),
					'reason_code' => $row['reason_code'] ?? '',
					'reason'      => $row['reason'] ?? '',
				),
				$skipped
			);
			$this->format_items( $rows, array( 'handle', 'repo', 'branch', 'artifacts', 'reason_code', 'reason' ), array( 'format' => 'table' ), 'handle' );
		}

		WP_CLI::log( '' );
		if ( $dry_run ) {
			WP_CLI::success( sprintf( '%d artifact(s) would be removed. Save JSON and re-run with --apply-plan=<file> to apply.', (int) ( $summary['would_remove_artifacts'] ?? 0 ) ) );
			return;
		}
		WP_CLI::success( sprintf( 'Removed %d artifact(s); %d worktree(s) skipped.', (int) ( $summary['removed_artifacts'] ?? 0 ), count( $skipped ) ) );
	}

	/**
	 * Flatten artifact cleanup worktree rows into table rows.
	 *
	 * @param array<int,array> $rows Worktree artifact rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function flatten_artifact_cleanup_rows( array $rows ): array {
		$flat = array();
		foreach ( $rows as $row ) {
			foreach ( (array) ( $row['artifacts'] ?? array() ) as $artifact ) {
				if ( ! is_array( $artifact ) ) {
					continue;
				}
				$flat[] = array(
					'handle'   => $row['handle'] ?? '',
					'repo'     => $row['repo'] ?? '',
					'branch'   => $row['branch'] ?? '',
					'artifact' => $artifact['path'] ?? '',
					'size'     => $this->format_bytes( $artifact['size_bytes'] ?? null ),
					'path'     => rtrim( (string) ( $row['path'] ?? '' ), '/' ) . '/' . ltrim( (string) ( $artifact['path'] ?? '' ), '/' ),
				);
			}
		}

		return $flat;
	}

	/**
	 * Render emergency cleanup output.
	 *
	 * @param array $result     Emergency cleanup result.
	 * @param array $assoc_args CLI args.
	 * @return void
	 */
	private function render_worktree_emergency_cleanup_result( array $result, array $assoc_args ): void {
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			$json = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			WP_CLI::log( false === $json ? '{}' : $json );
			return;
		}

		$summary             = (array) ( $result['summary'] ?? array() );
		$artifact_candidates = (array) ( $result['artifact_candidates'] ?? array() );
		$worktree_candidates = (array) ( $result['worktree_candidates'] ?? array() );
		$removed_artifacts   = (array) ( $result['removed_artifacts'] ?? array() );
		$removed_worktrees   = (array) ( $result['removed_worktrees'] ?? array() );
		$skipped             = (array) ( $result['skipped'] ?? array() );
		$dry_run             = ! empty( $result['dry_run'] );

		WP_CLI::log( 'Emergency cleanup summary:' );
		$summary_rows = array(
			array(
				'metric' => 'would_remove_artifacts',
				'count'  => (int) ( $summary['would_remove_artifacts'] ?? 0 ),
			),
			array(
				'metric' => 'would_remove_worktrees',
				'count'  => (int) ( $summary['would_remove_worktrees'] ?? 0 ),
			),
			array(
				'metric' => 'removed_artifacts',
				'count'  => (int) ( $summary['removed_artifacts'] ?? 0 ),
			),
			array(
				'metric' => 'removed_worktrees',
				'count'  => (int) ( $summary['removed_worktrees'] ?? 0 ),
			),
			array(
				'metric' => 'artifact_size',
				'count'  => $this->format_bytes( $summary['artifact_size_bytes'] ?? null ),
			),
			array(
				'metric' => 'worktree_size',
				'count'  => $this->format_bytes( $summary['worktree_size_bytes'] ?? null ),
			),
			array(
				'metric' => 'skipped',
				'count'  => (int) ( $summary['skipped'] ?? 0 ),
			),
		);
		$this->format_items( $summary_rows, array( 'metric', 'count' ), array( 'format' => 'table' ), 'metric' );

		if ( ! empty( $artifact_candidates ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Priority 1 - artifact/cache deletion:' );
			$this->format_items( $this->flatten_artifact_cleanup_rows( $artifact_candidates ), array( 'handle', 'repo', 'branch', 'artifact', 'size', 'path' ), array( 'format' => 'table' ), 'handle' );
		}

		if ( ! empty( $worktree_candidates ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Priority 2 - oldest finalized/eligible worktrees:' );
			$rows = array_map(
				fn( $row ) => array(
					'handle'      => $row['handle'] ?? '',
					'branch'      => $row['branch'] ?? '',
					'age_days'    => $row['age_days'] ?? '',
					'size'        => $this->format_bytes( $row['size_bytes'] ?? null ),
					'signal'      => $row['signal'] ?? '',
					'reason_code' => $row['reason_code'] ?? '',
				),
				$worktree_candidates
			);
			$this->format_items( $rows, array( 'handle', 'branch', 'age_days', 'size', 'signal', 'reason_code' ), array( 'format' => 'table' ), 'handle' );
		}

		if ( ! empty( $removed_artifacts ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Removed artifacts:' );
			$this->format_items( $this->flatten_artifact_cleanup_rows( $removed_artifacts ), array( 'handle', 'repo', 'branch', 'artifact', 'size', 'path' ), array( 'format' => 'table' ), 'handle' );
		}

		if ( ! empty( $removed_worktrees ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Removed worktrees:' );
			$this->format_items( $removed_worktrees, array( 'handle', 'repo', 'branch', 'path' ), array( 'format' => 'table' ), 'handle' );
		}

		if ( ! empty( $skipped ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Skipped:' );
			$rows = array_map(
				fn( $row ) => array(
					'handle'      => $row['handle'] ?? '',
					'reason_code' => $row['reason_code'] ?? '',
					'reason'      => $this->shorten_cleanup_reason( (string) ( $row['reason'] ?? '' ) ),
				),
				$skipped
			);
			$this->format_items( $rows, array( 'handle', 'reason_code', 'reason' ), array( 'format' => 'table' ), 'handle' );
		}

		WP_CLI::log( '' );
		if ( $dry_run ) {
			WP_CLI::success( 'Emergency plan generated. Review JSON, then apply with --apply-plan=<file>.' );
			return;
		}
		WP_CLI::success( sprintf( 'Emergency cleanup removed %d artifact group(s) and %d worktree(s); %d skipped.', count( $removed_artifacts ), count( $removed_worktrees ), count( $skipped ) ) );
	}

	/**
	 * Read and decode a cleanup plan file for --apply-plan.
	 *
	 * @param string $path Plan file path.
	 * @return array
	 */
	private function read_worktree_cleanup_plan( string $path ): array {
		return $this->read_worktree_json_plan( $path, 'cleanup' );
	}

	/**
	 * Read and decode a JSON plan file for --apply-plan.
	 *
	 * @param string $path Plan file path.
	 * @param string $label Human label for errors.
	 * @return array
	 */
	private function read_worktree_json_plan( string $path, string $label ): array {
		if ( '' === trim( $path ) ) {
			WP_CLI::error( '--apply-plan requires a file path.' );
			return array();
		}

		if ( ! is_readable( $path ) ) {
			WP_CLI::error( sprintf( '%s plan is not readable: %s', ucfirst( $label ), $path ) );
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			WP_CLI::error( sprintf( 'Failed to read %s plan: %s', $label, $path ) );
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			WP_CLI::error( sprintf( '%s plan must be a JSON object: %s', ucfirst( $label ), json_last_error_msg() ) );
			return array();
		}

		return $decoded;
	}

	/**
	 * Filter cleanup row arrays for --only without changing summary counts.
	 *
	 * @param array  $report Cleanup report.
	 * @param string $only   Section or reason-code filter.
	 * @return array
	 */
	private function filter_worktree_cleanup_report( array $report, string $only ): array {
		$only = $this->normalize_worktree_cleanup_only( $only );

		if ( '' === $only ) {
			return $report;
		}

		if ( 'candidates' !== $only ) {
			$report['candidates'] = array();
		}
		if ( 'removed' !== $only ) {
			$report['removed'] = array();
		}

		if ( ! in_array( $only, array( 'candidates', 'removed' ), true ) ) {
			$report['skipped'] = array_values( array_filter(
				(array) ( $report['skipped'] ?? array() ),
				fn( $row ) => (string) ( $row['reason_code'] ?? '' ) === $only
			) );
		} else {
			$report['skipped'] = array();
		}

		return $report;
	}

	/**
	 * Normalize cleanup section/reason aliases used by --only.
	 *
	 * @param string $only Raw CLI filter value.
	 * @return string Normalized filter value.
	 */
	private function normalize_worktree_cleanup_only( string $only ): string {
		$aliases = array(
			'would-remove'     => 'candidates',
			'would_remove'     => 'candidates',
			'dirty'            => 'dirty_worktree',
			'unpushed'         => 'unpushed_commits',
			'missing-metadata' => 'missing_metadata',
			'external'         => 'external_worktree',
		);

		return $aliases[ $only ] ?? $only;
	}

	/**
	 * Shorten cleanup reasons for compact human tables.
	 *
	 * @param string $reason Full reason text.
	 * @return string Shortened reason text.
	 */
	private function shorten_cleanup_reason( string $reason ): string {
		if ( strlen( $reason ) <= 72 ) {
			return $reason;
		}

		return rtrim( substr( $reason, 0, 69 ) ) . '...';
	}

	/**
	 * Print a concise-output truncation hint.
	 *
	 * @param int    $total Total rows.
	 * @param int    $limit Rendered row limit.
	 * @param string $label Row label.
	 * @return void
	 */
	private function render_cleanup_truncation_hint( int $total, int $limit, string $label ): void {
		if ( $total <= $limit ) {
			return;
		}
		WP_CLI::log( sprintf( 'Showing %d of %d %s. Re-run with --verbose for all rows or --only=<reason_code> to filter.', $limit, $total, $label ) );
	}

	/**
	 * Format a byte count without depending on WordPress helpers in smoke tests.
	 *
	 * @param mixed $bytes Raw byte count.
	 * @return string Human-readable size.
	 */
	private function format_bytes( mixed $bytes ): string {
		if ( null === $bytes || '' === $bytes ) {
			return '-';
		}

		$bytes      = max( 0, (float) $bytes );
		$units      = array( 'B', 'KiB', 'MiB', 'GiB', 'TiB' );
		$unit       = 0;
		$unit_count = count( $units );
		while ( $bytes >= 1024 && $unit < $unit_count - 1 ) {
			$bytes /= 1024;
			++$unit;
		}

		$precision = 0 === $unit ? 0 : 1;
		return number_format( $bytes, $precision ) . ' ' . $units[ $unit ];
	}

	/**
	 * Render the freshness block for `worktree add` results.
	 *
	 * States, in priority order:
	 *   - fetch_failed=true         → `⚠ fetch failed — staleness unknown` (warning)
	 *   - rebase_attempted=true     → success or conflict status (log or warning)
	 *   - stale_commits_behind>0    → `⚠ <N> commits behind <upstream>` (warning + rebase hint)
	 *   - base_stale_commits_behind>0 → `⚠ base was <N> commits behind <base_upstream>` (warning + rebase hint)
	 *   - otherwise                 → `Freshness: up to date` (log)
	 *
	 * When no staleness signal is present at all (no fetch attempt recorded,
	 * no upstream configured, defaults used) the line is elided entirely —
	 * silence beats an ambiguous "up to date" we can't actually vouch for.
	 *
	 * @param array $result Ability result payload.
	 * @return void
	 */
	private function render_worktree_freshness( array $result ): void {
		if ( ! empty( $result['fetch_failed'] ) ) {
			$msg = 'Freshness: ⚠ fetch failed — staleness unknown';
			if ( ! empty( $result['fetch_error'] ) ) {
				$msg .= "\n  " . $result['fetch_error'];
			}
			WP_CLI::warning( $msg );
			return;
		}

		if ( ! empty( $result['rebase_attempted'] ) ) {
			$target = isset( $result['rebase_target'] ) ? (string) $result['rebase_target'] : 'upstream';
			if ( ! empty( $result['rebase_succeeded'] ) ) {
				WP_CLI::log( sprintf( 'Freshness: rebased onto %s', $target ) );
				// Fall through in case either behind-count is still set (e.g. the
				// "other" path's metadata is present but zeroed). Renderer below
				// handles the 0-case correctly.
			} else {
				$msg = sprintf( 'Freshness: ⚠ rebase onto %s failed — worktree stayed at pre-rebase HEAD', $target );
				if ( ! empty( $result['rebase_error'] ) ) {
					$msg .= "\n  " . $result['rebase_error'];
				}
				WP_CLI::warning( $msg );
				// Staleness block below will still fire with the pre-rebase
				// behind-count so the agent sees exactly how stale it is.
			}
		}

		if ( isset( $result['stale_commits_behind'] ) ) {
			$behind   = (int) $result['stale_commits_behind'];
			$upstream = isset( $result['upstream'] ) ? (string) $result['upstream'] : 'upstream';
			if ( $behind > 0 ) {
				WP_CLI::warning( sprintf(
					"Freshness: ⚠ %d commits behind %s\n  Rebase before opening a PR:\n    git -C %s pull --rebase origin %s",
					$behind,
					$upstream,
					$result['path'] ?? '<worktree>',
					$result['branch'] ?? '<branch>'
				) );
				return;
			}
			WP_CLI::log( sprintf( 'Freshness: up to date (vs %s)', $upstream ) );
			return;
		}

		if ( isset( $result['base_stale_commits_behind'] ) ) {
			$behind        = (int) $result['base_stale_commits_behind'];
			$base_upstream = isset( $result['base_upstream'] ) ? (string) $result['base_upstream'] : 'origin';
			if ( $behind > 0 ) {
				WP_CLI::warning( sprintf(
					"Freshness: ⚠ base was %d commits behind %s\n  Rebase before opening a PR:\n    git -C %s pull --rebase origin %s",
					$behind,
					$base_upstream,
					$result['path'] ?? '<worktree>',
					$result['branch'] ?? '<branch>'
				) );
				return;
			}
			WP_CLI::log( sprintf( 'Freshness: up to date (base %s)', $base_upstream ) );
			return;
		}

		// No signal available (default base was origin/HEAD, or no upstream
		// configured for the existing branch). Elide the line rather than
		// print a potentially-misleading "up to date".
	}

	/**
	 * Convert a branch-shaped CLI alias into the exact ref expected downstream.
	 *
	 * `--from` remains the exact-ref escape hatch. `--base-branch` is for the
	 * common branch-name case agents reach for, so bare names become origin refs.
	 *
	 * @param string $base_branch Branch or ref passed to --base-branch.
	 * @return string Ref to pass to the worktree-add ability.
	 */
	private static function base_branch_to_ref( string $base_branch ): string {
		$base_branch = trim( $base_branch );

		if ( preg_match( '/^(?:refs\/|(?:origin|upstream)\/|HEAD$|[a-f0-9]{7,40}$)/i', $base_branch ) ) {
			return $base_branch;
		}

		return 'origin/' . ltrim( $base_branch, '/' );
	}
}
