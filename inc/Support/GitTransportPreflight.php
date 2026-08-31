<?php
/**
 * Git transport diagnostics for actionable, non-mutating SSH failures.
 *
 * @package DataMachineCode\Support
 */

namespace DataMachineCode\Support;

defined('ABSPATH') || exit;

final class GitTransportPreflight {

	/**
	 * Inspect the current SSH agent before a Git operation uses an SSH remote.
	 *
	 * @return array<string,mixed>|null Null when the remote is not SSH.
	 */
	public static function diagnose( string $remote_url ): ?array {
		if ( ! self::is_ssh_remote($remote_url) ) {
			return null;
		}

		$socket = getenv('SSH_AUTH_SOCK');
		if ( ! is_string($socket) || '' === $socket || 'socket' !== @filetype($socket) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A vanished forwarded socket is an expected preflight state.
			return self::classify($remote_url, false, null);
		}

		$probe = ProcessRunner::run('ssh-add -l >/dev/null 2>&1', array( 'error_as_result' => true ));
		$exit  = is_array($probe) ? (int) ( $probe['exit_code'] ?? 1 ) : 2;

		return self::classify($remote_url, true, $exit);
	}

	/**
	 * Classify a probe result without exposing agent paths, identities, or credentials.
	 *
	 * @return array<string,mixed>|null Null when the remote is not SSH.
	 */
	public static function classify( string $remote_url, bool $socket_usable, ?int $ssh_add_exit_code ): ?array {
		if ( ! self::is_ssh_remote($remote_url) ) {
			return null;
		}

		$fallback = GitHubRemote::cloneUrl($remote_url, 'https');
		if ( null === $fallback ) {
			return null;
		}
		$result     = array(
			'transport'                => 'ssh',
			'https_alternative'        => $fallback,
			'https_authenticated'      => 'unverified',
			'fallback_is_non_mutating' => true,
		);
		$descriptor = GitHubRemote::descriptor($remote_url);
		if ( null !== $descriptor && null !== $descriptor['ssh_port'] ) {
			$result['ssh_port'] = $descriptor['ssh_port'];
		}

		if ( ! $socket_usable ) {
			return array_merge(
				$result,
				array(
					'ready'       => false,
					'code'        => 'ssh_agent_unavailable',
					'remediation' => 'Start or forward an SSH agent with an authorized identity, or explicitly use the HTTPS alternative after confirming its authentication.',
				)
			);
		}

		if ( 0 === $ssh_add_exit_code ) {
			return array_merge($result, array(
				'ready' => true,
				'code'  => 'ssh_agent_ready',
			));
		}

		return array_merge(
			$result,
			array(
				'ready'       => false,
				'code'        => 1 === $ssh_add_exit_code ? 'ssh_agent_no_identities' : 'ssh_agent_unavailable',
				'remediation' => 'Load an authorized SSH identity into the active agent, or explicitly use the HTTPS alternative after confirming its authentication.',
			)
		);
	}

	/**
	 * Recognize an SSH-agent signing refusal returned by Git after it starts.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function signing_failure( string $remote_url, string $output ): ?array {
		if ( ! self::is_ssh_remote($remote_url) || ! preg_match('/sign_and_send_pubkey.*agent refused operation/i', $output) ) {
			return null;
		}

		$result = self::classify($remote_url, true, 0);
		if ( null === $result ) {
			return null;
		}
		$result['ready']       = false;
		$result['code']        = 'ssh_agent_signing_refused';
		$result['remediation'] = 'Authorize the SSH-agent signing request for this session, or explicitly use the HTTPS alternative after confirming its authentication.';
		return $result;
	}

	private static function is_ssh_remote( string $remote_url ): bool {
		return 1 === preg_match('#^(?:ssh://)?git@[A-Za-z0-9.-]+(?::\d+)?(?:/|:)#i', trim($remote_url))
			&& null !== GitHubRemote::descriptor($remote_url);
	}
}
