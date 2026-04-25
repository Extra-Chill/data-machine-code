<?php
/**
 * Worktree Context Injector
 *
 * When a worktree is created from a WordPress site with an active Data Machine
 * agent, snapshot that agent's persistent context (MEMORY.md, USER.md, RULES.md)
 * into the new worktree as runtime-agnostic local-only files.
 *
 * Written files:
 *   <worktree>/.claude/CLAUDE.local.md  — Claude Code convention
 *   <worktree>/.opencode/AGENTS.local.md — OpenCode convention
 *
 * Both files receive the same payload. They are ignored per-checkout via the
 * worktree's `info/exclude` file, so the tracked `.gitignore` is never touched
 * and other worktrees / the primary checkout are unaffected.
 *
 * Per-worktree metadata (`created_from_site`) is persisted in the
 * `datamachine_worktree_metadata` site option so later `refresh-context`
 * calls can resolve back to the originating site.
 *
 * Cross-machine federation is explicitly out of scope: the originating site
 * is always the site invoking this code; if that site is not reachable
 * (plugin removed, agent layer absent), injection and refresh become
 * graceful no-ops.
 *
 * @package DataMachineCode\Workspace
 * @since 0.8.0
 */

namespace DataMachineCode\Workspace;

defined( 'ABSPATH' ) || exit;

class WorktreeContextInjector {

	/**
	 * Site option key used to persist per-worktree metadata.
	 *
	 * Shape: array<string, array{
	 *   site_url: string,
	 *   site_name: string,
	 *   agent_slug: string,
	 *   abspath: string,
	 *   created_at: string,
	 * }> keyed by workspace handle.
	 */
	public const METADATA_OPTION = 'datamachine_worktree_metadata';

	/**
	 * Files injected into the worktree, relative to the worktree root.
	 */
	public const INJECTED_PATHS = array(
		'.claude/CLAUDE.local.md',
		'.opencode/AGENTS.local.md',
	);

	/**
	 * Memory files snapshotted into the payload, in render order.
	 */
	private const MEMORY_FILES = array( 'MEMORY.md', 'USER.md', 'RULES.md' );

	/**
	 * Build a payload capturing the originating site's agent context.
	 *
	 * Returns null when Data Machine's agent memory layer is unavailable
	 * (plugin inactive, running outside a WordPress context, etc.). Callers
	 * should treat null as "nothing to inject" — never as an error.
	 *
	 * @return array{
	 *     site_url: string,
	 *     site_name: string,
	 *     agent_slug: string,
	 *     abspath: string,
	 *     files: array<string, string>,
	 *     timestamp: string,
	 * }|null
	 */
	public static function build_payload(): ?array {
		if ( ! class_exists( '\\DataMachine\\Core\\FilesRepository\\AgentMemory' ) ) {
			return null;
		}
		if ( ! class_exists( '\\DataMachine\\Core\\FilesRepository\\DirectoryManager' ) ) {
			return null;
		}

		$dm         = new \DataMachine\Core\FilesRepository\DirectoryManager();
		$user_id    = $dm->get_effective_user_id( 0 );
		$agent_slug = $dm->resolve_agent_slug( array( 'user_id' => $user_id ) );

		$files = array();
		foreach ( self::MEMORY_FILES as $filename ) {
			$memory = new \DataMachine\Core\FilesRepository\AgentMemory( $user_id, 0, $filename );
			$result = $memory->get_all();
			if ( ! empty( $result['success'] ) && is_string( $result['content'] ?? null ) && '' !== trim( $result['content'] ) ) {
				$files[ $filename ] = $result['content'];
			}
		}

		return array(
			'site_url'   => function_exists( 'home_url' ) ? (string) home_url() : '',
			'site_name'  => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '',
			'agent_slug' => $agent_slug,
			'abspath'    => defined( 'ABSPATH' ) ? rtrim( ABSPATH, '/' ) : '',
			'files'      => $files,
			'timestamp'  => gmdate( 'c' ),
		);
	}

	/**
	 * Render a payload into the markdown body written to both injected files.
	 *
	 * @param array $payload Payload from {@see self::build_payload()}.
	 * @return string Markdown document.
	 */
	public static function render( array $payload ): string {
		$site_name  = trim( (string) ( $payload['site_name'] ?? '' ) );
		$site_url   = (string) ( $payload['site_url'] ?? '' );
		$agent_slug = (string) ( $payload['agent_slug'] ?? '' );
		$abspath    = (string) ( $payload['abspath'] ?? '' );
		$timestamp  = (string) ( $payload['timestamp'] ?? gmdate( 'c' ) );
		$files      = is_array( $payload['files'] ?? null ) ? $payload['files'] : array();

		$heading = '' !== $site_name ? $site_name : ( '' !== $site_url ? $site_url : 'the originating site' );

		$out  = "# Injected context from {$heading}\n\n";
		$out .= "This worktree was created from the {$heading} WordPress site\n";
		$out .= "on {$timestamp}. The agent that created it has the following\n";
		$out .= "persistent context snapshotted below.\n\n";

		foreach ( self::MEMORY_FILES as $filename ) {
			if ( empty( $files[ $filename ] ) ) {
				continue;
			}
			$body = rtrim( (string) $files[ $filename ], "\n" );
			$out .= "## {$filename}\n\n{$body}\n\n";
		}

		$out .= "## Fetching fresher context\n\n";
		$out .= "The source site has `studio wp` available. Run:\n\n";
		$out .= "    studio wp datamachine agent read MEMORY.md\n";
		$out .= "    studio wp datamachine agent search <term>\n\n";
		$out .= "to pull updates that accumulated after this worktree was created.\n";
		$out .= "You can also rewrite these injected files in-place via:\n\n";
		$out .= "    studio wp datamachine-code workspace worktree refresh-context <handle>\n\n";

		$out .= "## Source site\n\n";
		$out .= '- Slug: ' . ( '' !== $agent_slug ? $agent_slug : '(unresolved)' ) . "\n";
		$out .= '- Site URL: ' . ( '' !== $site_url ? $site_url : '(unknown)' ) . "\n";
		$out .= '- Studio path: ' . ( '' !== $abspath ? $abspath : '(unknown)' ) . "\n";

		return $out;
	}

	/**
	 * Inject a rendered payload into the worktree filesystem.
	 *
	 * Idempotent: reruns overwrite the injected files and deduplicate the
	 * `info/exclude` entries.
	 *
	 * @param string $worktree_path Absolute path to the worktree directory.
	 * @param array  $payload       Payload from {@see self::build_payload()}.
	 * @return array{success: bool, written: string[], exclude_path: ?string, message?: string}|\WP_Error
	 */
	public static function inject( string $worktree_path, array $payload ): array|\WP_Error {
		if ( '' === $worktree_path || ! is_dir( $worktree_path ) ) {
			return new \WP_Error(
				'worktree_not_found',
				sprintf( 'Worktree path does not exist: %s', $worktree_path ),
				array( 'status' => 404 )
			);
		}

		$body    = self::render( $payload );
		$written = array();

		foreach ( self::INJECTED_PATHS as $relative ) {
			$abs = rtrim( $worktree_path, '/' ) . '/' . $relative;
			$dir = dirname( $abs );
			if ( ! is_dir( $dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
				if ( ! wp_mkdir_p( $dir ) ) {
					return new \WP_Error(
						'mkdir_failed',
						sprintf( 'Failed to create directory: %s', $dir ),
						array( 'status' => 500 )
					);
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$bytes = file_put_contents( $abs, $body );
			if ( false === $bytes ) {
				return new \WP_Error(
					'write_failed',
					sprintf( 'Failed to write injected file: %s', $abs ),
					array( 'status' => 500 )
				);
			}
			$written[] = $abs;
		}

		$exclude_path = self::append_exclude_entries( $worktree_path, self::INJECTED_PATHS );

		return array(
			'success'      => true,
			'written'      => $written,
			'exclude_path' => $exclude_path,
		);
	}

	/**
	 * Remove injected files from a worktree and best-effort strip the
	 * per-checkout exclude entries. Used by `--no-inject-context` reruns
	 * and by future un-inject flows.
	 *
	 * @param string $worktree_path Worktree directory.
	 * @return array{success: bool, removed: string[]}
	 */
	public static function uninject( string $worktree_path ): array {
		$removed = array();

		foreach ( self::INJECTED_PATHS as $relative ) {
			$abs = rtrim( $worktree_path, '/' ) . '/' . $relative;
			if ( is_file( $abs ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes DMC-injected local-only context files from a worktree.
				unlink( $abs );
				$removed[] = $abs;
			}
		}

		return array(
			'success' => true,
			'removed' => $removed,
		);
	}

	/**
	 * Persist `created_from_site` metadata for a worktree handle.
	 *
	 * @param string $handle  Workspace handle.
	 * @param array  $payload Payload from {@see self::build_payload()}.
	 */
	public static function store_metadata( string $handle, array $payload ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		$all = get_option( self::METADATA_OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}

		$all[ $handle ] = array(
			'site_url'   => (string) ( $payload['site_url'] ?? '' ),
			'site_name'  => (string) ( $payload['site_name'] ?? '' ),
			'agent_slug' => (string) ( $payload['agent_slug'] ?? '' ),
			'abspath'    => (string) ( $payload['abspath'] ?? '' ),
			'created_at' => (string) ( $payload['timestamp'] ?? gmdate( 'c' ) ),
		);

		update_option( self::METADATA_OPTION, $all, false );
	}

	/**
	 * Fetch persisted metadata for a handle, or null if none stored.
	 *
	 * @param string $handle Workspace handle.
	 * @return array|null
	 */
	public static function get_metadata( string $handle ): ?array {
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}
		$all = get_option( self::METADATA_OPTION, array() );
		if ( ! is_array( $all ) || empty( $all[ $handle ] ) || ! is_array( $all[ $handle ] ) ) {
			return null;
		}
		return $all[ $handle ];
	}

	/**
	 * Drop persisted metadata for a handle. Called when a worktree is removed.
	 *
	 * @param string $handle Workspace handle.
	 */
	public static function forget_metadata( string $handle ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		$all = get_option( self::METADATA_OPTION, array() );
		if ( ! is_array( $all ) || ! array_key_exists( $handle, $all ) ) {
			return;
		}
		unset( $all[ $handle ] );
		update_option( self::METADATA_OPTION, $all, false );
	}

	/**
	 * Resolve the `info/` directory used by git for this worktree's exclude
	 * file.
	 *
	 * Important subtlety: git's `info/exclude` is ALWAYS read from the
	 * repository's *common* git directory, not the per-worktree private
	 * gitdir. A worktree's `.git` file points at `<primary>/.git/worktrees/
	 * <slug>/` but exclude patterns are resolved from `<primary>/.git/info/`.
	 * Writing to the per-worktree `info/exclude` is a silent no-op — git
	 * never reads it. See `man git-config` under GIT_COMMON_DIR.
	 *
	 * Consequence: the injected exclude entries are technically visible to
	 * every worktree + the primary checkout. In practice this is harmless —
	 * the patterns (`.claude/CLAUDE.local.md`, `.opencode/AGENTS.local.md`)
	 * only match files we deliberately create in worktrees via injection.
	 * No injected files exist in non-injected checkouts, so there is no
	 * behavior change there.
	 *
	 * @param string $worktree_path Worktree directory.
	 * @return string|null Info directory path, or null if the common git dir
	 *                     cannot be resolved.
	 */
	private static function resolve_info_dir( string $worktree_path ): ?string {
		$common_dir = self::resolve_common_git_dir( $worktree_path );
		if ( null === $common_dir ) {
			return null;
		}
		return $common_dir . '/info';
	}

	/**
	 * Resolve the repository's common git directory from a worktree path.
	 *
	 * For a worktree:
	 *   `.git` is a file containing `gitdir: <primary>/.git/worktrees/<slug>`,
	 *   and that per-worktree gitdir contains a `commondir` file with the
	 *   path (relative or absolute) to the common `.git` dir.
	 *
	 * For a primary checkout:
	 *   `.git` is itself a directory and is the common dir.
	 *
	 * @param string $worktree_path Worktree directory.
	 * @return string|null Absolute path to the common git directory.
	 */
	private static function resolve_common_git_dir( string $worktree_path ): ?string {
		$dot_git = rtrim( $worktree_path, '/' ) . '/.git';

		if ( is_dir( $dot_git ) ) {
			$real = realpath( $dot_git );
			return false === $real ? null : $real;
		}

		if ( ! is_file( $dot_git ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $dot_git );
		if ( false === $content ) {
			return null;
		}

		if ( ! preg_match( '/^gitdir:\s*(.+)$/m', $content, $matches ) ) {
			return null;
		}

		$gitdir = trim( $matches[1] );
		if ( '' === $gitdir ) {
			return null;
		}

		if ( ! str_starts_with( $gitdir, '/' ) ) {
			$gitdir = rtrim( $worktree_path, '/' ) . '/' . $gitdir;
		}

		$gitdir_real = realpath( $gitdir );
		if ( false === $gitdir_real ) {
			return null;
		}

		// Per git layout, worktree gitdirs contain a `commondir` file with
		// the path (often relative) to the repository's shared .git dir.
		$commondir_file = $gitdir_real . '/commondir';
		if ( ! is_file( $commondir_file ) ) {
			// Older git versions may omit this file; fall back to the
			// conventional layout: <primary>/.git/worktrees/<slug> → <primary>/.git.
			return dirname( dirname( $gitdir_real ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$commondir = trim( (string) file_get_contents( $commondir_file ) );
		if ( '' === $commondir ) {
			return null;
		}

		if ( ! str_starts_with( $commondir, '/' ) ) {
			$commondir = $gitdir_real . '/' . $commondir;
		}

		$real = realpath( $commondir );
		return false === $real ? null : $real;
	}

	/**
	 * Append the injected paths to the worktree's per-checkout `info/exclude`,
	 * creating the file if needed. Deduplicates existing entries.
	 *
	 * @param string   $worktree_path Worktree directory.
	 * @param string[] $paths         Relative paths to ensure are excluded.
	 * @return string|null The `info/exclude` path touched, or null when the
	 *                     worktree's gitdir could not be resolved.
	 */
	private static function append_exclude_entries( string $worktree_path, array $paths ): ?string {
		$info_dir = self::resolve_info_dir( $worktree_path );
		if ( null === $info_dir ) {
			return null;
		}

		if ( ! is_dir( $info_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			if ( ! wp_mkdir_p( $info_dir ) ) {
				return null;
			}
		}

		$exclude = $info_dir . '/exclude';
		$current = '';
		if ( is_file( $exclude ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$current = (string) file_get_contents( $exclude );
		}

		$existing = array_filter( array_map( 'trim', explode( "\n", $current ) ), static fn( string $line ): bool => '' !== $line );
		$missing  = array();
		foreach ( $paths as $path ) {
			$needle = trim( $path );
			if ( '' === $needle ) {
				continue;
			}
			if ( ! in_array( $needle, $existing, true ) ) {
				$missing[] = $needle;
			}
		}

		if ( empty( $missing ) ) {
			return $exclude;
		}

		$append = '';
		if ( '' !== $current && ! str_ends_with( $current, "\n" ) ) {
			$append .= "\n";
		}
		$append .= "# Data Machine: injected worktree context (per-checkout)\n";
		$append .= implode( "\n", $missing ) . "\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = file_put_contents( $exclude, $append, FILE_APPEND );
		if ( false === $result ) {
			return null;
		}

		return $exclude;
	}
}
