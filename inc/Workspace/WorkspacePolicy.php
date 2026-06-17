<?php
/**
 * Workspace policy service.
 *
 * Centralizes workspace writable-root policy loading, path normalization,
 * enforcement, and attestation construction for local and remote backends.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Support\PathSecurity;

defined('ABSPATH') || exit;

final class WorkspacePolicy {



	/**
	 * Return writable roots for a repository.
	 *
	 * `writable_roots` is the runner-facing contract. `allowed_paths` remains
	 * supported as the existing workspace git policy name and fallback.
	 *
	 * @param  string $repo_name Repository name.
	 * @return array<int,string>
	 */
	public function writable_roots_for_repo( string $repo_name ): array {
		$policies = $this->workspace_git_policies();
		$repo     = $policies['repos'][ $repo_name ] ?? array();
		$paths    = $repo['writable_roots'] ?? $repo['allowed_paths'] ?? array();

		return $this->normalize_paths($paths);
	}

	/**
	 * Return hidden roots for a repository.
	 *
	 * @param  string $repo_name Repository name.
	 * @return array<int,string>
	 */
	public function hidden_paths_for_repo( string $repo_name ): array {
		$policies = $this->workspace_git_policies();
		$repo     = $policies['repos'][ $repo_name ] ?? array();

		return $this->normalize_paths($repo['hidden_paths'] ?? array());
	}

	/**
	 * Normalize one repo-relative path.
	 */
	public function normalize_path( string $path ): string {
		return rtrim(ltrim(str_replace('\\', '/', trim($path)), '/'), '/');
	}

	/**
	 * Normalize repo-relative path lists.
	 *
	 * @param  mixed $paths Raw policy/path value.
	 * @return array<int,string>
	 */
	public function normalize_paths( mixed $paths ): array {
		if ( ! is_array($paths) ) {
			return array();
		}

		$clean = array();
		foreach ( $paths as $path ) {
			$normalized = $this->normalize_path( (string) $path );
			if ( '' !== $normalized ) {
				$clean[] = $normalized;
			}
		}

		return array_values(array_unique($clean));
	}

	/**
	 * Enforce configured writable roots for repo-relative paths.
	 *
	 * @param  string            $repo_name Repository name.
	 * @param  array<int,string> $paths     Repo-relative paths.
	 * @return true|\WP_Error
	 */
	public function assert_paths_writable( string $repo_name, array $paths ): true|\WP_Error {
		$writable_roots = $this->writable_roots_for_repo($repo_name);
		if ( empty($writable_roots) ) {
			return true;
		}

		$rejected = array();
		foreach ( $paths as $path ) {
			$relative = $this->normalize_path($path);
			if ( '' !== $relative && ! PathSecurity::isPathAllowed($relative, $writable_roots) ) {
				$rejected[] = $relative;
			}
		}

		$rejected = array_values(array_unique($rejected));
		if ( empty($rejected) ) {
			return true;
		}

		return new \WP_Error(
			'path_not_allowed',
			sprintf(
				'Path(s) outside configured writable_roots: %s. Allowed writable_roots: %s.',
				implode(', ', $rejected),
				implode(', ', $writable_roots)
			),
			array(
				'status'         => 403,
				'rejected_paths' => $rejected,
				'writable_roots' => $writable_roots,
			)
		);
	}

	/**
	 * Build a machine-readable workspace policy attestation.
	 *
	 * @param  string            $repo_name     Repository name.
	 * @param  string            $repo_path     Repository path.
	 * @param  array<int,string> $changed_files Changed repo-relative paths.
	 * @param  array<int,string> $ignored_files Ignored repo-relative paths.
	 * @param  callable|null     $git_stage     Optional callback returning `git ls-files --stage` output for one path.
	 * @return array<string,mixed>|null|\WP_Error
	 */
	public function attest_changed_paths( string $repo_name, string $repo_path, array $changed_files, array $ignored_files = array(), ?callable $git_stage = null ): array|null|\WP_Error {
		$writable_roots = $this->writable_roots_for_repo($repo_name);
		$hidden_paths   = $this->hidden_paths_for_repo($repo_name);

		if ( empty($writable_roots) && empty($hidden_paths) ) {
			return null;
		}

		$changed_files = array_values(array_unique(array_merge($this->normalize_paths($changed_files), $this->normalize_paths($ignored_files))));
		$ignored_files = $this->normalize_paths($ignored_files);
		sort($changed_files);

		$policy = array(
			'writable_roots' => $writable_roots,
			'hidden_paths'   => $hidden_paths,
		);

		$real_repo = realpath($repo_path);
		if ( false === $real_repo ) {
			return new \WP_Error('repo_path_unavailable', 'Repository path cannot be resolved for workspace policy attestation.', array( 'status' => 500 ));
		}

		$violations = array();
		foreach ( $changed_files as $path ) {
			$violations = array_merge($violations, $this->path_violations($repo_path, $real_repo, $path, $writable_roots, $hidden_paths, $ignored_files, $git_stage));
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- fallback for standalone smoke tests outside WordPress.
		$policy_json = function_exists('wp_json_encode') ? wp_json_encode($policy) : json_encode($policy);

		return array(
			'policy_hash'      => hash('sha256', (string) $policy_json),
			'checked_roots'    => array(
				'repository'     => $real_repo,
				'writable_roots' => $writable_roots,
				'hidden_paths'   => $hidden_paths,
			),
			'changed_files'    => $changed_files,
			'violations'       => $violations,
			'supported_checks' => array(
				'writable_roots',
				'hidden_paths',
				'symlink',
				'gitlink',
				'nested_git',
				'non_regular',
				'outside_realpath',
				'ignored',
				'hardlink',
			),
		);
	}

	/**
	 * @return array{repos: array<string, array<string, mixed>>}
	 */
	private function workspace_git_policies(): array {
		$defaults = array( 'repos' => array() );
		$settings = function_exists('get_option') ? get_option('datamachine_workspace_git_policies', $defaults) : $defaults;
		if ( ! is_array($settings) ) {
			$settings = $defaults;
		}
		if ( ! isset($settings['repos']) || ! is_array($settings['repos']) ) {
			$settings['repos'] = array();
		}
		if ( function_exists('apply_filters') ) {
			$settings = apply_filters('datamachine_workspace_git_policies', $settings);
		}

		return isset($settings['repos']) && is_array($settings['repos']) ? $settings : $defaults;
	}

	/**
	 * Build all policy violations for a single relative path.
	 *
	 * @param  string            $repo_path      Repository path.
	 * @param  string            $real_repo      Real repository path.
	 * @param  string            $path           Relative path.
	 * @param  array<int,string> $writable_roots Writable roots.
	 * @param  array<int,string> $hidden_paths   Hidden paths.
	 * @param  array<int,string> $ignored_files  Ignored files.
	 * @return array<int,array<string,string>>
	 */
	private function path_violations( string $repo_path, string $real_repo, string $path, array $writable_roots, array $hidden_paths, array $ignored_files, ?callable $git_stage = null ): array {
		$violations = array();
		$path       = ltrim(str_replace('\\', '/', $path), '/');
		$absolute   = $repo_path . '/' . $path;

		$add_violation = static function ( string $reason, string $detail = '' ) use ( &$violations, $path ): void {
			$violation = array(
				'path'   => $path,
				'reason' => $reason,
			);
			if ( '' !== $detail ) {
				$violation['detail'] = $detail;
			}
			$violations[] = $violation;
		};

		if ( ! empty($writable_roots) && ! PathSecurity::isPathAllowed($path, $writable_roots) ) {
			$add_violation('outside_writable_roots', 'Changed path is outside configured writable_roots.');
		}

		if ( ! empty($hidden_paths) && PathSecurity::isPathAllowed($path, $hidden_paths) ) {
			$add_violation('hidden_path', 'Changed path is under configured hidden_paths.');
		}

		if ( '.git' === $path || str_starts_with($path, '.git/') || str_contains($path, '/.git/') || str_ends_with($path, '/.git') ) {
			$add_violation('nested_git', 'Changed path includes a .git directory or file.');
		}

		if ( in_array($path, $ignored_files, true) ) {
			$add_violation('ignored', 'Changed path is ignored by git exclude rules.');
		}

		if ( is_link($absolute) ) {
			$add_violation('symlink', 'Changed path is a symlink.');
			$target = readlink($absolute);
			if ( false !== $target ) {
				$target_path = str_starts_with($target, '/') ? $target : dirname($absolute) . '/' . $target;
				$real_target = realpath($target_path);
				if ( false === $real_target || ( $real_target !== $real_repo && ! str_starts_with($real_target, $real_repo . '/') ) ) {
					$add_violation('outside_realpath', 'Symlink target resolves outside the repository.');
				}
				foreach ( $hidden_paths as $hidden_path ) {
					$hidden_real = realpath($repo_path . '/' . $hidden_path);
					if ( false !== $hidden_real && false !== $real_target && ( $real_target === $hidden_real || str_starts_with($real_target, $hidden_real . '/') ) ) {
						$add_violation('hidden_path_exposure', 'Symlink target resolves under hidden_paths.');
					}
				}
			}
		} elseif ( file_exists($absolute) ) {
			$real_path = realpath($absolute);
			if ( false === $real_path || ( $real_path !== $real_repo && ! str_starts_with($real_path, $real_repo . '/') ) ) {
				$add_violation('outside_realpath', 'Changed path resolves outside the repository.');
			}

			if ( ! is_file($absolute) && ! is_dir($absolute) ) {
				$add_violation('non_regular', 'Changed path is neither a regular file nor a directory.');
			}

			$stat = @lstat($absolute); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array($stat) && is_file($absolute) && (int) $stat[3] > 1 ) {
				$add_violation('hardlink', 'Changed file has more than one hardlink.');
			}
		}

		$stage_output = null !== $git_stage ? $git_stage($path) : '';
		if ( is_string($stage_output) && preg_match('/^160000\s/', trim($stage_output)) ) {
			$add_violation('gitlink', 'Changed path is a gitlink/submodule entry.');
		}

		return $violations;
	}
}
