<?php
/**
 * Core workspace path, security, and git utility helpers.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachine\Core\FilesRepository\FilesystemHelper;
use DataMachineCode\Support\GitRunner;
use DataMachineCode\Support\PathSecurity;
use DataMachineCode\Storage\WorktreeInventoryRepository;

defined('ABSPATH') || exit;

trait WorkspaceCoreUtilities {

	/**
	 * Worktree inventory repository factory.
	 */
	private function worktree_inventory(): WorktreeInventoryRepository {
		if ( ! class_exists(WorktreeInventoryRepository::class) ) {
			include_once dirname(__DIR__) . '/Storage/WorktreeInventoryRepository.php';
		}

		return new WorktreeInventoryRepository();
	}

	/**
	 * Resolve the workspace directory path.
	 *
	 * Priority:
	 * 1. DATAMACHINE_WORKSPACE_PATH constant (if defined)
	 * 2. /var/lib/datamachine/workspace (if writable — typical on VPS)
	 * 3. $HOME/.datamachine/workspace (local/macOS fallback)
	 * 4. sys_get_temp_dir()/datamachine/workspace (ephemeral CI/Playground fallback)
	 * 5. Empty string (no workspace available)
	 *
	 * @return string Workspace path or empty string if unavailable.
	 */
	private static function resolve_workspace_directory(): string {
		if ( defined('DATAMACHINE_WORKSPACE_PATH') ) {
			return rtrim(DATAMACHINE_WORKSPACE_PATH, '/');
		}

		$system_path   = '/var/lib/datamachine/workspace';
		$system_base   = dirname($system_path);
		$fs            = FilesystemHelper::get();
		$base_writable = $fs
		? $fs->is_writable($system_base)
		: is_writable($system_base); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable

		$parent_writable = ! $base_writable && ! file_exists($system_base) && (
		$fs
		? $fs->is_writable(dirname($system_base))
		: is_writable(dirname($system_base)) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		);

		if ( $base_writable || $parent_writable ) {
			return $system_path;
		}

		// Local/macOS fallback: $HOME/.datamachine/workspace.
		// Matches the path setup.sh uses in --local mode.
		$home = getenv('HOME');
		if ( false !== $home && '' !== $home ) {
			$home_path = rtrim($home, '/') . '/.datamachine/workspace';
			$home_base = dirname($home_path);

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			if ( is_dir($home_base) && is_writable($home_base) ) {
				return $home_path;
			}

			// Base doesn't exist yet — check if $HOME/.datamachine can be created.
         // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			if ( ! file_exists($home_base) && is_writable(dirname($home_base)) ) {
				return $home_path;
			}
		}

		$temp_path = rtrim(sys_get_temp_dir(), '/') . '/datamachine/workspace';
		$temp_base = dirname($temp_path);
		$web_root  = defined('ABSPATH') ? realpath(ABSPATH) : false;
		$temp_root = realpath(sys_get_temp_dir());

		if ( false !== $temp_root
			&& ( false === $web_root || 0 !== strpos($temp_root . '/', rtrim($web_root, '/') . '/') )
		) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			if ( is_dir($temp_base) && is_writable($temp_base) ) {
				return $temp_path;
			}

         // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			if ( ! file_exists($temp_base) && is_writable(dirname($temp_base)) ) {
				return $temp_path;
			}
		}

		return '';
	}

	/**
	 * Get the workspace base path.
	 *
	 * @return string
	 */
	public function get_path(): string {
		return $this->workspace_path;
	}

	/**
	 * Inspect whether the configured workspace root is visible to PHP.
	 *
	 * @return array{path: string, configured: bool, is_dir: bool, is_readable: bool, scandir: string, readable: bool}
	 */
	public function inspect_workspace_path(): array {
		$path        = $this->workspace_path;
		$is_dir      = '' !== $path && is_dir($path);
		$is_readable = '' !== $path && is_readable($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable
		$entries     = ( $is_dir && $is_readable ) ? scandir($path) : false;

		return array(
			'path'        => $path,
			'configured'  => defined('DATAMACHINE_WORKSPACE_PATH'),
			'is_dir'      => $is_dir,
			'is_readable' => $is_readable,
			'scandir'     => false === $entries ? 'failed' : 'ok',
			'readable'    => $is_dir && false !== $entries,
		);
	}

	/**
	 * Require the configured workspace root to be visible before substrate checks.
	 *
	 * @return \WP_Error|null
	 */
	private function require_workspace_visible(): ?\WP_Error {
		$diagnostic = $this->inspect_workspace_path();

		if ( '' === $diagnostic['path'] ) {
			return new \WP_Error(
				'workspace_unavailable',
				'Workspace unavailable: no writable path outside the web root. Define DATAMACHINE_WORKSPACE_PATH in wp-config.php, ensure /var/lib/datamachine/ is writable, ensure $HOME is set, or ensure the system temporary directory is writable.',
				$this->workspace_visibility_error_data($diagnostic)
			);
		}

		if ( ! $diagnostic['is_dir'] ) {
			if ( empty($diagnostic['configured']) ) {
				return null;
			}

			return new \WP_Error(
				'workspace_path_invisible',
				$this->format_workspace_visibility_message($diagnostic),
				$this->workspace_visibility_error_data($diagnostic)
			);
		}

		if ( ! $diagnostic['readable'] ) {
			return new \WP_Error(
				'workspace_path_unreadable',
				$this->format_workspace_visibility_message($diagnostic),
				$this->workspace_visibility_error_data($diagnostic)
			);
		}

		return null;
	}

	/**
	 * Build error data for workspace visibility failures.
	 *
	 * @param  array<string,mixed> $diagnostic Path visibility details.
	 * @return array{status: int, workspace: array<string,mixed>}
	 */
	private function workspace_visibility_error_data( array $diagnostic ): array {
		return array(
			'status'    => 500,
			'workspace' => $diagnostic,
		);
	}

	/**
	 * Format a workspace visibility diagnostic for CLI/API callers.
	 *
	 * @param  array<string,mixed> $diagnostic Path visibility details.
	 * @return string
	 */
	private function format_workspace_visibility_message( array $diagnostic ): string {
		return sprintf(
			'Workspace path is not accessible from PHP: %s (is_dir=%s, is_readable=%s, scandir=%s). If this is a Studio/local host path, ensure the path is mounted into the PHP runtime or update DATAMACHINE_WORKSPACE_PATH to a PHP-visible workspace.',
			(string) ( $diagnostic['path'] ?? '' ),
			! empty($diagnostic['is_dir']) ? 'true' : 'false',
			! empty($diagnostic['is_readable']) ? 'true' : 'false',
			(string) ( $diagnostic['scandir'] ?? 'failed' )
		);
	}

	/**
	 * Get the full path to a workspace handle.
	 *
	 * Handles can be either a primary checkout (`<repo>`) or a worktree
	 * (`<repo>@<branch-slug>`). The directory name on disk equals the handle.
	 *
	 * @param  string $handle Workspace handle (`<repo>` or `<repo>@<branch-slug>`).
	 * @return string Full filesystem path.
	 */
	public function get_repo_path( string $handle ): string {
		$parsed = $this->parse_handle($handle);
		return $this->workspace_path . '/' . $parsed['dir_name'];
	}

	/**
	 * Parse a workspace handle into its components.
	 *
	 * Accepts either:
	 *   - `<repo>`           → primary checkout
	 *   - `<repo>@<slug>`    → worktree (slug = slugified branch name)
	 *
	 * @param  string $handle Workspace handle.
	 * @return array{repo: string, branch_slug: string|null, is_worktree: bool, dir_name: string}
	 */
	public function parse_handle( string $handle ): array {
		$handle = trim($handle);

		if ( str_contains($handle, '@') ) {
			$parts = explode('@', $handle, 2);
			$repo  = $this->sanitize_name($parts[0]);
			$slug  = $this->sanitize_slug($parts[1]);

			if ( '' !== $repo && '' !== $slug ) {
				return array(
					'repo'        => $repo,
					'branch_slug' => $slug,
					'is_worktree' => true,
					'dir_name'    => $repo . '@' . $slug,
				);
			}
		}

		$repo = $this->sanitize_name($handle);

		return array(
			'repo'        => $repo,
			'branch_slug' => null,
			'is_worktree' => false,
			'dir_name'    => $repo,
		);
	}

	/**
	 * Convert a branch name to a filesystem-safe slug.
	 *
	 * Slashes become dashes (`fix/foo-bar` → `fix-foo-bar`). Anything else
	 * outside [A-Za-z0-9._-] is stripped.
	 *
	 * @param  string $branch Branch name.
	 * @return string Slug (empty if branch is invalid).
	 */
	public function slugify_branch( string $branch ): string {
		$branch = trim($branch);
		if ( '' === $branch ) {
			return '';
		}

		$slug = str_replace('/', '-', $branch);
		return $this->sanitize_slug($slug);
	}

	/**
	 * Recover blank worktree identity fields from trusted stored metadata.
	 *
	 * @param  array<string,mixed> $wt Worktree row.
	 * @return array<string,mixed>
	 */
	private function recover_worktree_identity_from_metadata( array $wt ): array {
		$handle   = (string) ( $wt['handle'] ?? '' );
		$repo     = (string) ( $wt['repo'] ?? '' );
		$branch   = (string) ( $wt['branch'] ?? '' );
		$path     = (string) ( $wt['path'] ?? '' );
		$metadata = is_array($wt['metadata'] ?? null) ? (array) $wt['metadata'] : array();
		$parsed   = '' !== $handle ? $this->parse_handle($handle) : array(
			'repo'        => '',
			'branch_slug' => null,
			'is_worktree' => false,
			'dir_name'    => '',
		);

		$stored    = array(
			'repo'   => isset($metadata['repo']) ? trim( (string) $metadata['repo'] ) : '',
			'branch' => isset($metadata['branch']) ? trim( (string) $metadata['branch'] ) : '',
			'path'   => isset($metadata['path']) ? rtrim(trim( (string) $metadata['path'] ), '/') : '',
		);
		$conflicts = array();
		$hydrated  = array();

		if ( '' === $repo && '' !== $stored['repo'] ) {
			if ( $parsed['repo'] === $stored['repo'] ) {
				$repo       = $stored['repo'];
				$hydrated[] = 'repo';
			} else {
				$conflicts['repo'] = array(
					'reason'      => 'metadata_repo_does_not_match_handle',
					'handle_repo' => $parsed['repo'],
					'metadata'    => $stored['repo'],
				);
			}
		} elseif ( '' !== $repo && '' !== $stored['repo'] && $repo !== $stored['repo'] ) {
			$conflicts['repo'] = array(
				'reason'   => 'metadata_repo_does_not_match_row',
				'row'      => $repo,
				'metadata' => $stored['repo'],
			);
		}

		if ( '' === $branch && '' !== $stored['branch'] ) {
			$branch_slug = (string) ( $parsed['branch_slug'] ?? '' );
			if ( '' !== $branch_slug && $this->slugify_branch($stored['branch']) === $branch_slug ) {
				$branch     = $stored['branch'];
				$hydrated[] = 'branch';
			} else {
				$conflicts['branch'] = array(
					'reason'        => 'metadata_branch_does_not_match_handle_slug',
					'handle_slug'   => $branch_slug,
					'metadata'      => $stored['branch'],
					'metadata_slug' => $this->slugify_branch($stored['branch']),
				);
			}
		} elseif ( '' !== $branch && '' !== $stored['branch'] && $branch !== $stored['branch'] ) {
			$conflicts['branch'] = array(
				'reason'   => 'metadata_branch_does_not_match_row',
				'row'      => $branch,
				'metadata' => $stored['branch'],
			);
		}

		if ( '' === $path && '' !== $stored['path'] ) {
			$stored_basename = basename($stored['path']);
			$stored_real     = realpath($stored['path']);
			$stored_real     = false !== $stored_real ? $stored_real : $stored['path'];
			$workspace_real  = realpath($this->workspace_path);
			$workspace_real  = false !== $workspace_real ? $workspace_real : $this->workspace_path;
			if ( $stored_basename === $handle && str_starts_with(rtrim($stored_real, '/'), rtrim($workspace_real, '/') . '/') ) {
				$path       = $stored['path'];
				$hydrated[] = 'path';
			} else {
				$conflicts['path'] = array(
					'reason'            => 'metadata_path_does_not_match_workspace_handle',
					'handle'            => $handle,
					'metadata'          => $stored['path'],
					'metadata_basename' => $stored_basename,
				);
			}
		} elseif ( '' !== $path && '' !== $stored['path'] ) {
			$row_path      = rtrim($path, '/');
			$metadata_path = rtrim($stored['path'], '/');
			$row_real      = realpath($row_path);
			$row_real      = false !== $row_real ? $row_real : $row_path;
			$metadata_real = realpath($metadata_path);
			$metadata_real = false !== $metadata_real ? $metadata_real : $metadata_path;
			if ( rtrim($row_real, '/') !== rtrim($metadata_real, '/') ) {
				$conflicts['path'] = array(
					'reason'   => 'metadata_path_does_not_match_row',
					'row'      => $row_path,
					'metadata' => $metadata_path,
				);
			}
		}

		return array(
			'repo'            => $repo,
			'branch'          => $branch,
			'path'            => $path,
			'hydrated_fields' => $hydrated,
			'conflicts'       => $conflicts,
			'stored_identity' => array_filter($stored, fn( $value ) => '' !== $value),
			'detached_branch' => '' === (string) ( $wt['branch'] ?? '' ) && in_array('branch', $hydrated, true),
		);
	}

	/**
	 * Sanitize a branch slug. Allows alphanumerics, dots, dashes, underscores.
	 *
	 * @param  string $slug Raw slug.
	 * @return string
	 */
	private function sanitize_slug( string $slug ): string {
		$slug = preg_replace('/[^a-zA-Z0-9._-]/', '', $slug);
		// Collapse runs of dashes for readability.
		$slug = preg_replace('/-{2,}/', '-', (string) $slug);
		return trim( (string) $slug, '-.');
	}

	/**
	 * Get the primary checkout path for a repo.
	 *
	 * @param  string $repo Repository name (no @-suffix).
	 * @return string
	 */
	public function get_primary_path( string $repo ): string {
		$resolved = $this->resolve_primary_repo_name($repo);
		if ( is_wp_error($resolved) ) {
			return $this->workspace_path . '/' . $this->sanitize_name($repo);
		}

		return $this->workspace_path . '/' . $resolved;
	}

	/**
	 * Resolve a primary repo argument to the canonical workspace directory name.
	 *
	 * @param  string $repo Primary handle, git URL, or local checkout path.
	 * @return string|\WP_Error Canonical primary handle or validation error.
	 */
	public function resolve_primary_repo_name( string $repo ): string|\WP_Error {
		$repo = trim($repo);
		if ( '' === $repo ) {
			return new \WP_Error('invalid_repo', 'Repository name is required.', array( 'status' => 400 ));
		}

		if ( str_contains($repo, '@') && ! $this->looks_like_git_url($repo) ) {
			return new \WP_Error('invalid_repo', 'Worktree handles cannot be used where a primary repository is required.', array( 'status' => 400 ));
		}

		if ( $this->looks_like_git_url($repo) ) {
			$existing = $this->find_primary_by_remote($repo);
			if ( null !== $existing ) {
				return $existing['name'];
			}

			return new \WP_Error('unsupported_workspace_repo_argument', sprintf('Repository URL "%s" does not match an existing local primary checkout. Use a registered primary handle or run workspace clone first.', $repo), array( 'status' => 404 ));
		}

		if ( $this->looks_like_path_argument($repo) ) {
			return $this->resolve_primary_repo_name_from_path($repo);
		}

		if ( str_contains($repo, '/') || str_contains($repo, '\\') ) {
			return new \WP_Error('unsupported_workspace_repo_argument', sprintf('Repository argument "%s" is not a primary workspace handle. Use the local primary handle, or pass a URL/path that matches an existing local primary checkout.', $repo), array( 'status' => 400 ));
		}

		$sanitized = $this->sanitize_name($repo);
		if ( '' === $sanitized ) {
			return new \WP_Error('invalid_repo', sprintf('Repository argument "%s" did not produce a valid workspace handle.', $repo), array( 'status' => 400 ));
		}

		return $sanitized;
	}

	/**
	 * Resolve a local path argument to an existing primary handle.
	 *
	 * @param  string $path Local checkout path.
	 * @return string|\WP_Error Canonical primary handle or validation error.
	 */
	private function resolve_primary_repo_name_from_path( string $path ): string|\WP_Error {
		$path          = rtrim($path, '/');
		$expanded_path = str_starts_with($path, '~/') ? rtrim( (string) getenv('HOME'), '/') . substr($path, 1) : $path;
		$real_path     = realpath($expanded_path);
		$workspace     = realpath($this->workspace_path);
		$resolved_path = false !== $real_path ? $real_path : $expanded_path;

		if ( false !== $workspace && str_starts_with(rtrim($resolved_path, '/') . '/', rtrim($workspace, '/') . '/') ) {
			$name = basename($resolved_path);
			if ( '' !== $name && ! str_contains($name, '@') && is_dir($resolved_path) && ( is_dir($resolved_path . '/.git') || is_file($resolved_path . '/.git') ) ) {
				return $name;
			}
		}

		$remote = ( is_dir($resolved_path) && ( is_dir($resolved_path . '/.git') || is_file($resolved_path . '/.git') ) ) ? $this->git_get_remote($resolved_path) : null;
		if ( null !== $remote ) {
			$existing = $this->find_primary_by_remote($remote);
			if ( null !== $existing ) {
				return $existing['name'];
			}
		}

		return new \WP_Error('unsupported_workspace_repo_argument', sprintf('Repository path "%s" does not resolve to an existing local primary checkout. Use a registered primary handle or run workspace clone/adopt first.', $path), array( 'status' => 404 ));
	}

	/**
	 * Whether a repo argument looks like a git URL.
	 */
	private function looks_like_git_url( string $value ): bool {
		return (bool) preg_match('#^(?:https?|ssh|git)://#i', $value) || (bool) preg_match('/^[^@\s]+@[^:\s]+:.+$/', $value);
	}

	/**
	 * Whether a repo argument looks like a filesystem path.
	 */
	private function looks_like_path_argument( string $value ): bool {
		return str_starts_with($value, '/') || str_starts_with($value, './') || str_starts_with($value, '../') || str_starts_with($value, '~/');
	}

	/**
	 * Normalize a git remote URL for same-repository comparisons.
	 *
	 * @param  string $url Git remote URL.
	 * @return string Normalized URL-ish key.
	 */
	private function normalize_git_remote_url( string $url ): string {
		$url = trim($url);
		$url = rtrim($url, '/');
		$url = preg_replace('/\.git$/', '', $url) ?? $url;

		if ( preg_match('/^([^@\s]+)@([^:\s]+):(.+)$/', $url, $matches) ) {
			$url = 'ssh://' . $matches[2] . '/' . $matches[3];
		}

		$parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
		if ( is_array($parts) && ! empty($parts['host']) ) {
			$host = strtolower( (string) $parts['host']);
			$path = trim( (string) ( $parts['path'] ?? '' ), '/');
			$path = preg_replace('/\.git$/', '', $path) ?? $path;
			return strtolower($host . '/' . $path);
		}

		return strtolower($url);
	}

	/**
	 * Find an existing primary checkout whose origin remote matches the URL.
	 *
	 * @param  string $url          Git remote URL to match.
	 * @param  string $exclude_name Optional primary name to ignore.
	 * @return array{name: string, path: string, remote: string}|null
	 */
	private function find_primary_by_remote( string $url, string $exclude_name = '' ): ?array {
		$needle = $this->normalize_git_remote_url($url);
		if ( '' === $needle || ! is_dir($this->workspace_path) ) {
			return null;
		}

		$entries = scandir($this->workspace_path);
		if ( ! is_array($entries) ) {
			return null;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || str_contains($entry, '@') || $entry === $exclude_name ) {
				continue;
			}

			$path = $this->workspace_path . '/' . $entry;
			if ( ! is_dir($path) || ! file_exists($path . '/.git') ) {
				continue;
			}

			$remote = $this->git_get_remote($path);
			if ( null !== $remote && $needle === $this->normalize_git_remote_url($remote) ) {
				return array(
					'name'   => $entry,
					'path'   => $path,
					'remote' => $remote,
				);
			}
		}

		return null;
	}

	/**
	 * Build local-ref freshness metadata for a primary checkout.
	 *
	 * This intentionally does not fetch. Read-only status/list/hygiene calls should
	 * reveal stale local remote refs without mutating repo state or requiring auth.
	 *
	 * @param  string $repo_path Primary checkout path.
	 * @param  string $handle    Workspace primary handle.
	 * @return array<string,mixed>|null
	 */
	private function build_primary_freshness_report( string $repo_path, string $handle ): ?array {
		if ( ! file_exists($repo_path . '/.git') ) {
			return null;
		}

		$status_result = $this->run_git($repo_path, 'status --porcelain=v1 --branch --untracked-files=no');
		if ( is_wp_error($status_result) ) {
			return array(
				'status'        => 'unknown',
				'branch'        => null,
				'upstream'      => null,
				'behind'        => null,
				'ahead'         => null,
				'detached'      => false,
				'local_refs'    => true,
				'fetch_checked' => false,
			);
		}

		$header   = strtok((string) ( $status_result['output'] ?? '' ), "\n");
		$header   = false === $header ? '' : trim($header);
		$branch   = null;
		$detached = false;
		$upstream      = null;
		$behind   = 0;
		$ahead    = 0;
		$status   = 'unknown';

		if ( preg_match('/^## HEAD \(no branch\)/', $header) ) {
			$detached = true;
			$status = 'detached';
		} elseif ( preg_match('/^## (.+?)(?:\.\.\.([^\s\[]+))?(?: \[(.+)\])?$/', $header, $matches) ) {
			$branch   = trim((string) $matches[1]);
			$upstream = isset($matches[2]) && '' !== $matches[2] ? trim((string) $matches[2]) : null;
			$divergence = isset($matches[3]) ? (string) $matches[3] : '';

			if ( preg_match('/behind (\d+)/', $divergence, $behind_match) ) {
				$behind = (int) $behind_match[1];
			}
			if ( preg_match('/ahead (\d+)/', $divergence, $ahead_match) ) {
				$ahead = (int) $ahead_match[1];
			}

			if ( null === $upstream ) {
				$status = 'no_upstream';
			} elseif ( $behind > 0 && $ahead > 0 ) {
				$status = 'diverged';
			} elseif ( $behind > 0 ) {
				$status = 'stale';
			} elseif ( $ahead > 0 ) {
				$status = 'ahead';
			} else {
				$status = 'current';
			}
		}

		$report = array(
			'status'        => $status,
			'branch'        => $branch,
			'upstream'      => $upstream,
			'behind'        => null === $upstream ? null : $behind,
			'ahead'         => null === $upstream ? null : $ahead,
			'detached'      => $detached,
			'local_refs'    => true,
			'fetch_checked' => false,
		);

		if ( $this->primary_freshness_needs_refresh($status) ) {
			$report['suggested_command'] = $this->primary_refresh_command($handle);
		}

		return $report;
	}

	/**
	 * Build the canonical command for refreshing a primary checkout.
	 *
	 * @param  string $handle Primary workspace handle.
	 * @return string WP-CLI command.
	 */
	private function primary_refresh_command( string $handle ): string {
		return sprintf('wp datamachine-code workspace git pull %s --allow-primary-mutation', $handle);
	}

	/**
	 * Whether a primary freshness status needs a pull/reconciliation refresh.
	 *
	 * @param  string $status Freshness status.
	 * @return bool True when refresh guidance should be shown.
	 */
	private function primary_freshness_needs_refresh( string $status ): bool {
		return in_array($status, array( 'stale', 'diverged' ), true);
	}

	/**
	 * Whether a primary freshness status should be surfaced in hygiene attention.
	 *
	 * @param  string $status Freshness status.
	 * @return bool True when attention should be shown.
	 */
	private function primary_freshness_needs_attention( string $status ): bool {
		return in_array($status, array( 'stale', 'diverged', 'detached', 'no_upstream', 'unknown' ), true);
	}

	/**
	 * Ensure the workspace directory exists with correct permissions.
	 *
	 * @return array{success: bool, path: string, created?: bool}|\WP_Error
	 */
	public function ensure_exists(): array|\WP_Error {
		$path = $this->workspace_path;

		if ( '' === $path ) {
			$visible = $this->require_workspace_visible();
			return null !== $visible ? $visible : new \WP_Error('workspace_unavailable', 'Workspace unavailable: no writable path outside the web root.', array( 'status' => 500 ));
		}

		if ( is_dir($path) ) {
			$visible = $this->require_workspace_visible();
			if ( null !== $visible ) {
				return $visible;
			}

			return array(
				'success' => true,
				'path'    => $path,
				'created' => false,
			);
		}

     // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		$created = wp_mkdir_p($path);

		if ( ! $created ) {
			return new \WP_Error('workspace_create_failed', sprintf('Failed to create workspace directory: %s', $path), array( 'status' => 500 ));
		}

		// Set permissions for multi-user access (web server group).
		$this->ensure_group_permissions($path);

		// Add .htaccess to block web access if inside web root.
		$this->protect_directory($path);

		return array(
			'success' => true,
			'path'    => $path,
			'created' => true,
		);
	}


	/**
	 * Validate that a target path is contained within a parent directory.
	 *
	 * Public security primitive — used by WorkspaceReader and WorkspaceWriter
	 * to enforce path containment before (and after) file I/O. Uses realpath()
	 * for symlink-safe resolution, so the target must exist on disk.
	 *
	 * For pre-write validation of non-existent files, use has_traversal()
	 * checks on the relative path first, then call this method post-write
	 * to verify the file landed where expected.
	 *
	 * @param  string $target    Path to validate.
	 * @param  string $container Parent directory that must contain the target.
	 * @return array{valid: bool, real_path?: string, message?: string}
	 */
	public function validate_containment( string $target, string $container ): array {
		return PathSecurity::validateContainment($target, $container);
	}

	/**
	 * Derive a repo name from a git URL.
	 *
	 * @param  string $url Git URL.
	 * @return string|null Derived name or null.
	 */
	protected function derive_repo_name( string $url ): ?string {
		// Handle https://github.com/org/repo.git and git@github.com:org/repo.git
		$name = basename($url);
		$name = preg_replace('/\.git$/', '', $name);
		$name = $this->sanitize_name($name);

		return ( '' !== $name ) ? $name : null;
	}

	/**
	 * Sanitize a directory name for use in the workspace.
	 *
	 * @param  string $name Raw name.
	 * @return string Sanitized name (alphanumeric, hyphens, underscores, dots).
	 */
	private function sanitize_name( string $name ): string {
		return preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
	}

	/**
	 * Run a git command in a repository.
	 *
	 * @param  string $repo_path       Resolved repository path.
	 * @param  string $git_args        Git arguments (without leading "git").
	 * @param  int    $timeout_seconds Optional timeout in seconds.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function run_git( string $repo_path, string $git_args, int $timeout_seconds = 0 ): array|\WP_Error {
		return GitRunner::run($repo_path, $git_args, $timeout_seconds);
	}

	/**
	 * Determine whether a git result is a timeout error.
	 *
	 * @param  mixed $result Git result.
		 * @return bool
		 */
	private function is_git_timeout_error( mixed $result ): bool {
		if ( ! is_wp_error($result) ) {
			return false;
		}

		$code = method_exists($result, 'get_error_code') ? $result->get_error_code() : ( $result->code ?? '' );
		return 'git_command_timeout' === $code;
	}

	/**
	 * Count commits on HEAD that the upstream branch hasn't seen.
	 *
	 * Used by `worktree_cleanup_merged()` as a hard stop against data loss:
	 * if a worktree has local commits ahead of upstream, we refuse to delete
	 * it even when the "branch merged" signal fires. This catches the case
	 * where someone manually deleted a remote branch (triggering
	 * upstream-gone) without first merging the local commits.
	 *
	 * Returns 0 when:
	 *   - Upstream is configured and HEAD is at or behind it.
	 *   - Upstream is `gone` but no local commits exist above its last-known
	 *     commit (`@{push}` or `@{upstream}` can still resolve for a gone
	 *     ref in some git versions).
	 *   - Any git command error (fail-open-ish by returning 0, since the
	 *     dirty-count and merge-signal checks still apply). The intent is
	 *     to avoid blocking cleanup on exotic repo states where we can't
	 *     definitively say commits would be lost.
	 *
	 * Returns >0 when the worktree has unambiguously unpushed commits.
	 *
	 * @param  string $wt_path         Worktree path.
	 * @param  int    $timeout_seconds Optional git probe timeout in seconds.
	 * @return int|\WP_Error Number of unpushed commits (0 when safe or indeterminate), or timeout error.
	 */
	private function count_unpushed_commits( string $wt_path, int $timeout_seconds = 0 ): int|\WP_Error {
		if ( '' === $wt_path || ! is_dir($wt_path) ) {
			return 0;
		}

		// Prefer `@{push}` (respects push.default / push.remote mapping); fall
		// back to `@{upstream}` for the common case where they're the same.
		// Both expand to the tracked remote ref; if that ref is gone, this
		// returns non-zero exit and we can't compute unpushed — treat as 0
		// and let dirty / merge-signal checks handle it.
		$commands = array(
			'rev-list --count @{push}..HEAD',
			'rev-list --count @{upstream}..HEAD',
		);

		foreach ( $commands as $command ) {
			$result = $this->run_git($wt_path, $command, $timeout_seconds);
			if ( is_wp_error($result) ) {
				if ( $this->is_git_timeout_error($result) ) {
					return $result;
				}
				continue;
			}

			$output = trim( (string) ( $result['output'] ?? '' ));
			if ( '' !== $output && ctype_digit($output) ) {
				return (int) $output;
			}
		}

		return 0;
	}

	/**
	 * Ensure the workspace directory is group-writable.
	 *
	 * Sets group ownership to www-data (or the web server's group) and
	 * permissions to 775 so that non-root users (e.g., coding agents) can
	 * write to the workspace.
	 *
	 * @param string $path Directory path.
	 */
	private function ensure_group_permissions( string $path ): void {
		// Determine the web server group. Try common groups in order of likelihood.
		$groups    = array( 'www-data', 'apache', 'nginx', 'http' );
		$web_group = null;

		foreach ( $groups as $group ) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			$exists = exec(sprintf('getent group %s >/dev/null 2>&1 && echo 1 || echo 0', escapeshellarg($group)));
			$exists = false === $exists ? '' : $exists;
			if ( '1' === trim($exists) ) {
				$web_group = $group;
				break;
			}
		}

		if ( null === $web_group ) {
			return;
		}

		// Set group ownership.
     // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec(sprintf('chgrp %s %s 2>/dev/null', escapeshellarg($web_group), escapeshellarg($path)));

		// Set permissions to 775 (rwxrwrx).
     // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec(sprintf('chmod 775 %s 2>/dev/null', escapeshellarg($path)));
	}

	/**
	 * Add .htaccess protection if the workspace is inside the web root.
	 *
	 * @param string $path Directory path.
	 */
	private function protect_directory( string $path ): void {
		$fs = FilesystemHelper::get();
		// Only needed if path is under ABSPATH (web root).
		$abspath = rtrim(ABSPATH, '/');
		if ( 0 !== strpos($path, $abspath) ) {
			return;
		}

		$htaccess = $path . '/.htaccess';
		if ( ! file_exists($htaccess) ) {
			$fs->put_contents($htaccess, "Deny from all\n");
		}

		$index = $path . '/index.php';
		if ( ! file_exists($index) ) {
			$fs->put_contents($index, "<?php\n// Silence is golden.\n");
		}
	}

	/**
	 * Fire the shared workspace-lifecycle action after a successful mutation.
	 *
	 * Listeners use this signal to refresh derived state (e.g. invalidating
	 * the composable AGENTS.md so its workspace-inventory section reflects the
	 * change). Only emitted on success, AFTER the on-disk change is durable.
	 * The payload mirrors the workspace `name`/`repo` taxonomy used elsewhere
	 * in this class:
	 *
	 *   - `op`:   one of `clone`, `adopt`, `remove`, `worktree_add`, `worktree_remove`.
	 *   - `repo`: bare repository name (no `@<slug>` suffix).
	 *   - `name`: workspace entry name on disk (`<repo>` for primaries,
	 *             `<repo>@<slug>` for worktrees).
	 *   - `path`: absolute path of the workspace entry that changed.
	 *
	 * @since 0.31.0
	 *
	 * @param  string $op   Mutation kind.
	 * @param  string $repo Bare repo name.
	 * @param  string $name Workspace entry name (`<repo>` or `<repo>@<slug>`).
	 * @param  string $path Absolute path of the entry that changed.
	 * @return void
	 */
	protected function emit_workspace_changed( string $op, string $repo, string $name, string $path ): void {
		/**
		 * Fires after a successful Workspace mutation lands on disk.
		 *
		 * @since 0.31.0
		 *
		 * @param array{op: string, repo: string, name: string, path: string} $payload {
		 *     @type  string $op   One of clone|adopt|remove|worktree_add|worktree_remove.
		 *     @type  string $repo Bare repository name (no @-suffix).
		 *     @type  string $name Workspace entry name (<repo> or <repo>@<slug>).
		 *     @type  string $path Absolute path of the workspace entry.
		 * }
		 */
		if ( ! function_exists('do_action') ) {
			return;
		}

		do_action(
			'datamachine_code_workspace_changed',
			array(
				'op'   => $op,
				'repo' => $repo,
				'name' => $name,
				'path' => $path,
			)
		);
	}
}
