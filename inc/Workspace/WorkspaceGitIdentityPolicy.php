<?php
/**
 * Repository-local Git identity policy for managed workspaces.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Support\GitHubRemote;

defined('ABSPATH') || exit;

trait WorkspaceGitIdentityPolicy {

	/**
	 * Require the effective identity to match a configured host policy.
	 *
	 * Integrations can return `array{ name: string, email: string }` from the
	 * `datamachine_code_git_identity_policy` filter. The parsed GitHub descriptor
	 * and original remote URL let integrations decide which repositories need it.
	 */
	private function enforce_repository_git_identity( string $repo_path ): ?\WP_Error {
		$identity = $this->repository_git_identity_policy($repo_path);
		if ( is_wp_error($identity) ) {
			return $identity;
		}
		if ( null === $identity ) {
			return null;
		}

		$name_result  = $this->run_git($repo_path, 'config --get user.name');
		$email_result = $this->run_git($repo_path, 'config --get user.email');
		$name         = $this->git_config_value($name_result);
		$email        = $this->git_config_value($email_result);
		if ( $identity['name'] !== $name || $identity['email'] !== $email ) {
			return new \WP_Error(
				'repository_git_identity_mismatch',
				'Refusing to commit because the effective Git user.name and user.email do not satisfy this repository host identity policy.',
				array( 'status' => 409 )
			);
		}

		return null;
	}

	/**
	 * Configure a managed worktree when its remote-specific policy provides identity.
	 */
	private function configure_repository_git_identity( string $repo_path ): ?\WP_Error {
		$identity = $this->repository_git_identity_policy($repo_path);
		if ( is_wp_error($identity) ) {
			return $identity;
		}
		if ( null === $identity ) {
			return null;
		}

		// Worktree config prevents an identity selected for one branch from changing its primary checkout.
		foreach ( array(
			'config extensions.worktreeConfig true',
			'config --worktree user.name ' . escapeshellarg($identity['name']),
			'config --worktree user.email ' . escapeshellarg($identity['email']),
		) as $command ) {
			$result = $this->run_git($repo_path, $command);
			if ( is_wp_error($result) ) {
				return $result;
			}
		}

		return null;
	}

	/**
	 * Resolve a configured identity policy for the repository's origin host.
	 *
	 * @return array{name:string,email:string}|null|\WP_Error
	 */
	private function repository_git_identity_policy( string $repo_path ): array|null|\WP_Error {
		$remote_result = $this->run_git($repo_path, 'remote get-url origin');
		if ( is_wp_error($remote_result) ) {
			return null;
		}

		$remote     = trim((string) ($remote_result['output'] ?? ''));
		$descriptor = GitHubRemote::descriptor($remote);
		if ( null === $descriptor || ! function_exists('apply_filters') ) {
			return null;
		}

		$identity = apply_filters('datamachine_code_git_identity_policy', null, $descriptor, $remote);
		if ( null === $identity ) {
			return null;
		}
		if ( ! is_array($identity) ) {
			return new \WP_Error('invalid_git_identity_policy', 'Git identity policy must return an array with non-empty name and email values.', array( 'status' => 500 ));
		}

		$name  = trim((string) ($identity['name'] ?? ''));
		$email = trim((string) ($identity['email'] ?? ''));
		if ( '' === $name || '' === $email ) {
			return new \WP_Error('invalid_git_identity_policy', 'Git identity policy must provide non-empty name and email values.', array( 'status' => 500 ));
		}

		return array( 'name' => $name, 'email' => $email );
	}

	/**
	 * Extract the effective Git config value.
	 */
	private function git_config_value( array|\WP_Error $result ): string {
		if ( is_wp_error($result) ) {
			return '';
		}

		return trim((string) ($result['output'] ?? ''));
	}
}
