<?php
/**
 * Managed distribution release-state inspection.
 *
 * @package DataMachineCode\Runtime
 */

namespace DataMachineCode\Runtime;

defined('ABSPATH') || exit;

final class ManagedReleaseDrift {

	private string $installed_version;

	public function __construct( ?string $installed_version = null ) {
		$this->installed_version = (string) ( $installed_version ?? ( defined('DATAMACHINE_CODE_VERSION') ? DATAMACHINE_CODE_VERSION : '' ) );
	}

	/** @return array<string,mixed> */
	public function status(): array {
		$channel  = $this->channel();
		$observed = $this->observed_version($channel);
		if ( isset($observed['error']) ) {
			return $this->result('observation_failed', $observed['version'], $channel, array( 'state' => 'failed', 'message' => $observed['error'] ));
		}

		$installed_version = $observed['version'];
		if ( '' === $channel['id'] || '' === $channel['latest_version'] ) {
			return $this->result('unavailable_channel', $installed_version, $channel, array( 'state' => 'unavailable' ));
		}
		if ( ! $this->is_version($installed_version) || ! $this->is_version($channel['latest_version']) ) {
			return $this->result('invalid_version', $installed_version, $channel, $this->verify($channel, $installed_version));
		}
		if ( $this->is_prerelease($installed_version) || $this->is_prerelease($channel['latest_version']) ) {
			return $this->result('prerelease', $installed_version, $channel, $this->verify($channel, $installed_version));
		}

		$comparison = version_compare($installed_version, $channel['latest_version']);
		$state      = 0 === $comparison ? 'current' : ( $comparison > 0 ? 'ahead' : 'drifted' );
		return $this->result($state, $installed_version, $channel, $this->verify($channel, $installed_version));
	}

	/** @return array<string,mixed> */
	public function converge(): array {
		$before  = $this->status();
		$channel = $this->channel();
		if ( 'drifted' !== $before['state'] || ! $this->can_converge($channel) ) {
			$before['convergence'] = array( 'state' => 'drifted' === $before['state'] ? 'handoff_required' : 'not_required' );
			return $before;
		}

		try {
			$result = call_user_func($channel['converge'], $before);
		} catch ( \Throwable $error ) {
			return $this->convergence_failure($before, 'Managed convergence callback failed: ' . $error->getMessage());
		}
		if ( ! is_array($result) || empty($result['success']) ) {
			return $this->convergence_failure($before, is_array($result) ? (string) ( $result['message'] ?? 'Managed convergence failed.' ) : 'Managed convergence returned an invalid result.');
		}

		// The updater's result is not evidence of what is installed. Re-read it.
		$after = $this->status();
		if ( 'current' !== $after['state'] ) {
			return $this->convergence_failure($after, 'Managed convergence did not produce the channel release.');
		}
		if ( 'verified' !== ( $after['verification']['state'] ?? '' ) ) {
			return $this->convergence_failure($after, 'Managed convergence did not verify the installed release contract.');
		}

		$after['convergence'] = array( 'state' => 'converged' );
		return $after;
	}

	/** @return array<string,mixed> */
	private function result( string $state, string $installed_version, array $channel, array $verification ): array {
		$available = '' !== $channel['id'] && '' !== $channel['latest_version'];
		return array(
			'state'             => $state,
			'installed_version' => $installed_version,
			'latest_version'    => $available ? $channel['latest_version'] : null,
			'channel'           => $available ? array( 'id' => $channel['id'] ) : null,
			'action'            => in_array($state, array( 'drifted', 'unavailable_channel' ), true) ? $channel['action'] : null,
			'verification'      => $verification,
		);
	}

	/** @return array<string,mixed> */
	private function convergence_failure( array $result, string $message ): array {
		$result['state']       = 'convergence_failed';
		$result['convergence'] = array( 'state' => 'failed', 'message' => $message );
		return $result;
	}

	/** @return array{id:string,latest_version:string,action:array<string,mixed>,converge:mixed,read_installed_version:mixed,verify:mixed} */
	private function channel(): array {
		$channel = function_exists('apply_filters') ? apply_filters('datamachine_code_managed_release_channel', array(), $this->installed_version) : array();
		$channel = is_array($channel) ? $channel : array();
		$action  = is_array($channel['action'] ?? null) ? $channel['action'] : array();
		if ( empty($action['type']) ) {
			$action = array( 'type' => 'handoff', 'code' => 'managed_release_convergence_required', 'message' => 'Use the owning managed release channel to converge this install.' );
		}

		return array(
			'id'                     => is_string($channel['id'] ?? null) ? $channel['id'] : '',
			'latest_version'         => is_string($channel['latest_version'] ?? null) ? $channel['latest_version'] : '',
			'action'                 => $action,
			'converge'               => $channel['converge'] ?? null,
			'read_installed_version' => $channel['read_installed_version'] ?? null,
			'verify'                 => $channel['verify'] ?? null,
		);
	}

	/** @return array{version:string,error?:string} */
	private function observed_version( array $channel ): array {
		if ( ! is_callable($channel['read_installed_version']) ) {
			return array( 'version' => $this->installed_version );
		}
		try {
			$version = call_user_func($channel['read_installed_version']);
		} catch ( \Throwable $error ) {
			return array( 'version' => '', 'error' => 'Managed installed-version inspection failed: ' . $error->getMessage() );
		}
		return is_string($version) ? array( 'version' => $version ) : array( 'version' => '', 'error' => 'Managed installed-version inspection returned an invalid value.' );
	}

	private function can_converge( array $channel ): bool {
		return 'command' === ( $channel['action']['type'] ?? '' )
			&& is_string($channel['action']['command'] ?? null)
			&& '' !== trim($channel['action']['command'])
			&& true === ( $channel['action']['authorize_callback'] ?? false )
			&& is_callable($channel['converge'])
			&& is_callable($channel['read_installed_version']);
	}

	/** @return array<string,mixed> */
	private function verify( array $channel, string $installed_version ): array {
		if ( ! is_callable($channel['verify']) ) {
			return array( 'state' => 'not_provided' );
		}
		try {
			$verification = call_user_func($channel['verify'], $installed_version);
		} catch ( \Throwable $error ) {
			return array( 'state' => 'failed', 'message' => 'Managed release verification failed: ' . $error->getMessage() );
		}
		return is_array($verification) ? $verification : array( 'state' => 'invalid' );
	}

	private function is_version( string $version ): bool {
		return 1 === preg_match('/^\d+(?:\.\d+){1,3}(?:-[0-9A-Za-z.-]+)?$/', $version);
	}

	private function is_prerelease( string $version ): bool {
		return str_contains($version, '-');
	}
}
