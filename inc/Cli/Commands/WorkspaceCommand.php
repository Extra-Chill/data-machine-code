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
	 *     wp datamachine workspace path
	 *
	 *     # Show path and create if missing
	 *     wp datamachine workspace path --ensure
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
	 *     wp datamachine workspace list
	 *
	 *     # List as JSON
	 *     wp datamachine workspace list --format=json
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
			WP_CLI::log( 'Clone one with: wp datamachine workspace clone <url>' );
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
	 *     wp datamachine workspace clone https://github.com/Extra-Chill/homeboy.git
	 *
	 *     # Clone with custom name
	 *     wp datamachine workspace clone https://github.com/Extra-Chill/homeboy.git --name=homeboy-dev
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
	 *     wp datamachine workspace remove homeboy
	 *
	 *     # Remove without confirmation
	 *     wp datamachine workspace remove homeboy --yes
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
	 *     wp datamachine workspace show homeboy
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
	 *     wp datamachine workspace read homeboy src/main.rs
	 *
	 *     # Read with custom size limit
	 *     wp datamachine workspace read homeboy Cargo.toml --max-size=2097152
	 *
	 *     # Read lines 100-130 from a file
	 *     wp datamachine workspace read extrachill style.css --offset=100 --limit=30
	 *
	 * @subcommand read
	 */
	public function read( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || empty( $args[1] ) ) {
			WP_CLI::error( 'Usage: wp datamachine workspace read <repo> <path>' );
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
	 *     wp datamachine workspace ls homeboy
	 *
	 *     # List subdirectory
	 *     wp datamachine workspace ls homeboy src/commands
	 *
	 *     # List as JSON
	 *     wp datamachine workspace ls homeboy --format=json
	 *
	 * @subcommand ls
	 */
	public function ls( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Usage: wp datamachine workspace ls <repo> [<path>]' );
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
	 *     wp datamachine workspace write homeboy src/new.rs --content="fn main() {}"
	 *
	 *     # Write from a local file (@ syntax)
	 *     wp datamachine workspace write homeboy src/main.rs --content=@/tmp/staged-code.rs
	 *
	 *     # Write from stdin
	 *     cat local-file.rs | wp datamachine workspace write homeboy src/main.rs
	 *
	 * @subcommand write
	 */
	public function write( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || empty( $args[1] ) ) {
			WP_CLI::error( 'Usage: wp datamachine workspace write <repo> <path> --content=<content>' );
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
	 *     wp datamachine workspace edit homeboy src/main.rs --old="old_func" --new="new_func"
	 *
	 *     # Replace using @ file syntax
	 *     wp datamachine workspace edit homeboy src/main.rs --old=@/tmp/old.txt --new=@/tmp/new.txt
	 *
	 *     # Replace all occurrences
	 *     wp datamachine workspace edit homeboy src/main.rs --old="v1" --new="v2" --replace-all
	 *
	 * @subcommand edit
	 */
	public function edit( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || empty( $args[1] ) ) {
			WP_CLI::error( 'Usage: wp datamachine workspace edit <repo> <path> --old=<string> --new=<string>' );
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
	 * [--path=<path>]
	 * : Relative path (repeatable) for add/diff operations.
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
	 *     wp datamachine workspace git status data-machine
	 *
	 *     # Pull latest changes
	 *     wp datamachine workspace git pull data-machine
	 *
	 *     # Stage docs paths
	 *     wp datamachine workspace git add extrachill-docs --path=ec_docs/community/getting-started.md
	 *
	 *     # Commit staged changes
	 *     wp datamachine workspace git commit extrachill-docs "docs: update community guide"
	 *
	 *     # Push current branch to origin
	 *     wp datamachine workspace git push extrachill-docs --remote=origin
	 *
	 *     # Show recent log
	 *     wp datamachine workspace git log data-machine --limit=10
	 *
	 *     # Show diff for a path
	 *     wp datamachine workspace git diff data-machine --path=inc/Core/FilesRepository/Workspace.php
	 *
	 * @subcommand git
	 */
	public function git( array $args, array $assoc_args ): void {
		$operation = $args[0] ?? '';
		$repo      = $args[1] ?? '';

		if ( '' === $operation || '' === $repo ) {
			WP_CLI::error( 'Usage: wp datamachine workspace git <operation> <repo> [<value>] [--flags]' );
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
			$paths = $assoc_args['path'] ?? array();
			if ( ! is_array( $paths ) ) {
				$paths = array( $paths );
			}
			$input['paths'] = array_values( array_filter( array_map( 'strval', $paths ) ) );

			if ( empty( $input['paths'] ) ) {
				WP_CLI::error( 'git add requires at least one --path=<relative/path>.' );
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
			if ( isset( $assoc_args['path'] ) ) {
				$path          = $assoc_args['path'];
				$input['path'] = is_array( $path ) ? (string) reset( $path ) : (string) $path;
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
	 * : Worktree operation: add, list, remove, prune, cleanup, refresh-context.
	 *
	 * [<repo>]
	 * : Primary repo name (required for add and remove). For refresh-context,
	 *   pass the full worktree handle (`<repo>@<branch-slug>`) here instead.
	 *
	 * [<branch>]
	 * : Branch name (required for add and remove).
	 *
	 * [--from=<ref>]
	 * : Base ref when creating a branch on add (default origin/HEAD).
	 *
	 * [--skip-context-injection]
	 * : Skip injecting the originating site's agent context into a new
	 *   worktree (applies to `add` only). Default behavior is to write
	 *   `.claude/CLAUDE.local.md` and `.opencode/AGENTS.local.md` containing
	 *   the site's MEMORY.md / USER.md / RULES.md snapshot, and add both
	 *   paths to the repository's `info/exclude`. The ability-level input
	 *   is `inject_context=false`; this flag is the CLI shorthand.
	 *
	 * [--force]
	 * : Force-remove a worktree even if it is dirty (applies to `remove` and
	 *   `cleanup`). Does NOT override the unpushed-commits safety in cleanup.
	 *
	 * [--dry-run]
	 * : Preview cleanup candidates without removing anything (cleanup only).
	 *
	 * [--skip-github]
	 * : Skip the GitHub API lookup and rely only on the local `upstream-gone`
	 *   signal (cleanup only). Faster, but misses merged branches where the
	 *   remote branch wasn't auto-deleted.
	 *
	 * [--format=<format>]
	 * : Output format for list (table, json, csv, yaml).
	 *
	 * ## EXAMPLES
	 *
	 *     # Create a worktree for fix/foo on data-machine
	 *     wp datamachine workspace worktree add data-machine fix/foo
	 *
	 *     # Create off a specific base
	 *     wp datamachine workspace worktree add data-machine feat/bar --from=origin/develop
	 *
	 *     # List all worktrees
	 *     wp datamachine workspace worktree list
	 *
	 *     # List worktrees for one repo
	 *     wp datamachine workspace worktree list data-machine
	 *
	 *     # Remove a worktree
	 *     wp datamachine workspace worktree remove data-machine fix/foo
	 *
	 *     # Force-remove a dirty worktree
	 *     wp datamachine workspace worktree remove data-machine fix/foo --force
	 *
	 *     # Prune stale worktree registry entries across all primaries
	 *     wp datamachine workspace worktree prune
	 *
	 *     # Preview worktrees that would be removed (upstream gone or PR merged)
	 *     wp datamachine workspace worktree cleanup --dry-run
	 *
	 *     # Remove all merged worktrees
	 *     wp datamachine workspace worktree cleanup
	 *
	 *     # Local-only detection (no GitHub API call)
	 *     wp datamachine workspace worktree cleanup --skip-github
	 *
	 *     # Ignore dirty working-tree safety (caution)
	 *     wp datamachine workspace worktree cleanup --force
	 *
	 *     # Create a worktree without injecting site-agent context
	 *     wp datamachine workspace worktree add data-machine fix/foo --skip-context-injection
	 *
	 *     # Re-read the originating site's agent memory into an existing worktree
	 *     wp datamachine workspace worktree refresh-context data-machine@fix-foo
	 *
	 * @subcommand worktree
	 */
	public function worktree( array $args, array $assoc_args ): void {
		$operation = $args[0] ?? '';

		if ( '' === $operation ) {
			WP_CLI::error( 'Usage: wp datamachine workspace worktree <add|list|remove|prune|cleanup|refresh-context> [<repo>] [<branch>] [--flags]' );
			return;
		}

		$ability_name = match ( $operation ) {
			'add'             => 'datamachine/workspace-worktree-add',
			'list'            => 'datamachine/workspace-worktree-list',
			'remove'          => 'datamachine/workspace-worktree-remove',
			'prune'           => 'datamachine/workspace-worktree-prune',
			'cleanup'         => 'datamachine/workspace-worktree-cleanup',
			'refresh-context' => 'datamachine/workspace-worktree-refresh-context',
			default           => '',
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
					WP_CLI::error( 'Usage: worktree add <repo> <branch> [--from=<ref>] [--skip-context-injection]' );
					return;
				}
				$input['repo']   = $args[1];
				$input['branch'] = $args[2];
				if ( ! empty( $assoc_args['from'] ) ) {
					$input['from'] = (string) $assoc_args['from'];
				}
				// --skip-context-injection disables the default-on injection step.
				$input['inject_context'] = empty( $assoc_args['skip-context-injection'] );
				break;

			case 'refresh-context':
				if ( empty( $args[1] ) ) {
					WP_CLI::error( 'Usage: worktree refresh-context <handle>' );
					return;
				}
				$input['handle'] = (string) $args[1];
				break;

			case 'list':
				if ( ! empty( $args[1] ) ) {
					$input['repo'] = $args[1];
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
				$input['dry_run']     = ! empty( $assoc_args['dry-run'] );
				$input['force']       = ! empty( $assoc_args['force'] );
				$input['skip_github'] = ! empty( $assoc_args['skip-github'] );
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
		switch ( $operation ) {
			case 'list':
				$worktrees = $result['worktrees'] ?? array();
				if ( empty( $worktrees ) ) {
					WP_CLI::log( 'No worktrees found.' );
					return;
				}
				$items = array_map(
					fn( $wt ) => array(
						'handle' => $wt['handle'] ?? '',
						'repo'   => $wt['repo'] ?? '',
						'kind'   => ! empty( $wt['is_primary'] ) ? 'primary' : 'worktree',
						'branch' => $wt['branch'] ?? '-',
						'head'   => isset( $wt['head'] ) ? substr( (string) $wt['head'], 0, 7 ) : '-',
						'dirty'  => (int) ( $wt['dirty'] ?? 0 ),
						'path'   => $wt['path'] ?? '',
					),
					$worktrees
				);
				$this->format_items( $items, array( 'handle', 'repo', 'kind', 'branch', 'head', 'dirty', 'path' ), $assoc_args, 'handle' );
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
				$candidates = $result['candidates'] ?? array();
				$removed    = $result['removed'] ?? array();
				$skipped    = $result['skipped'] ?? array();
				$dry_run    = ! empty( $result['dry_run'] );

				if ( empty( $candidates ) && empty( $skipped ) ) {
					WP_CLI::log( 'No worktrees found.' );
					return;
				}

				if ( ! empty( $candidates ) ) {
					WP_CLI::log( $dry_run ? 'Would remove:' : 'Removed:' );
					$rows = array_map(
						fn( $c ) => array(
							'handle' => $c['handle'] ?? '',
							'branch' => $c['branch'] ?? '',
							'signal' => $c['signal'] ?? '',
							'reason' => $c['reason'] ?? '',
						),
						$candidates
					);
					$this->format_items( $rows, array( 'handle', 'branch', 'signal', 'reason' ), $assoc_args, 'handle' );
				}

				if ( ! empty( $skipped ) ) {
					WP_CLI::log( '' );
					WP_CLI::log( 'Skipped:' );
					$rows = array_map(
						fn( $s ) => array(
							'handle' => $s['handle'] ?? '',
							'reason' => $s['reason'] ?? '',
						),
						$skipped
					);
					$this->format_items( $rows, array( 'handle', 'reason' ), $assoc_args, 'handle' );
				}

				if ( $dry_run ) {
					WP_CLI::log( '' );
					WP_CLI::success( sprintf( '%d worktree(s) would be removed. Re-run without --dry-run to apply.', count( $candidates ) ) );
				} else {
					WP_CLI::success( sprintf( 'Removed %d worktree(s); %d skipped.', count( $removed ), count( $skipped ) ) );
				}
				return;

			case 'add':
				WP_CLI::success( $result['message'] ?? 'Worktree created.' );
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

			case 'remove':
			default:
				WP_CLI::success( $result['message'] ?? 'Worktree operation complete.' );
				return;
		}
	}
}
