<?php

declare(strict_types=1);

namespace DataMachine\Cli {
	class BaseCommand {}
}

namespace {
	final class ManagedReleaseCliFailure extends \RuntimeException {}
	final class WP_CLI {
		public static array $lines = array();
		public static function error( string $message ): void { throw new ManagedReleaseCliFailure($message); }
		public static function log( string $message ): void {}
		public static function line( string $message ): void { self::$lines[] = $message; }
	}
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode($value, $flags); }
	define('ABSPATH', __DIR__ . '/');
	$GLOBALS['managed_release_command_channel'] = array();
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		return 'datamachine_code_managed_release_channel' === $hook ? $GLOBALS['managed_release_command_channel'] : $value;
	}
	require_once dirname(__DIR__) . '/inc/Runtime/ManagedReleaseDrift.php';
	require_once dirname(__DIR__) . '/inc/Cli/CliResponseRenderer.php';
	require_once dirname(__DIR__) . '/inc/Cli/Commands/RuntimeCommand.php';

	function managed_release_command_assert( bool $condition, string $message ): void {
		if ( ! $condition ) { throw new \RuntimeException($message); }
	}
	function managed_release_command_failure( array $channel ): array {
		$GLOBALS['managed_release_command_channel'] = $channel;
		try {
			( new \DataMachineCode\Cli\Commands\RuntimeCommand() )->release(array(), array( 'apply' => true, 'format' => 'json' ));
		} catch ( ManagedReleaseCliFailure $error ) {
			$payload = json_decode($error->getMessage(), true);
			managed_release_command_assert(is_array($payload), 'WP-CLI apply errors must preserve JSON diagnostics.');
			return $payload;
		}
		throw new \RuntimeException('Expected apply command to fail.');
	}

	$GLOBALS['managed_release_command_calls'] = 0;
	$handoff = managed_release_command_failure(array(
		'id' => 'fixture-channel', 'latest_version' => '0.57.4', 'read_installed_version' => static fn(): string => '0.57.1',
		'action' => array( 'type' => 'handoff' ),
		'converge' => static function (): array { ++$GLOBALS['managed_release_command_calls']; return array( 'success' => true ); },
	));
	managed_release_command_assert('handoff_required' === ( $handoff['convergence']['state'] ?? '' ), 'Handoff apply must return a structured failure.');
	managed_release_command_assert(0 === $GLOBALS['managed_release_command_calls'], 'CLI handoff must not invoke a mutation callback.');

	$failed = managed_release_command_failure(array(
		'id' => 'fixture-channel', 'latest_version' => '0.57.4', 'read_installed_version' => static fn(): string => '0.57.1',
		'action' => array( 'type' => 'command', 'command' => 'managed-plugin update data-machine-code', 'authorize_callback' => true ),
		'converge' => static fn(): array => array( 'success' => false, 'message' => 'fixture convergence failure' ),
	));
	managed_release_command_assert('convergence_failed' === $failed['state'], 'Failed callback apply must return a structured non-zero error.');

	$observation = managed_release_command_failure(array(
		'id' => 'fixture-channel', 'latest_version' => '0.57.4',
		'read_installed_version' => static function (): string { throw new \RuntimeException('fixture reader failure'); },
	));
	managed_release_command_assert('observation_failed' === $observation['state'], 'Observation failure apply must return a structured non-zero error.');

	$invalid = managed_release_command_failure(array(
		'id' => 'fixture-channel', 'latest_version' => 'invalid', 'read_installed_version' => static fn(): string => '0.57.1',
	));
	managed_release_command_assert('invalid_version' === $invalid['state'], 'Invalid version apply must return a structured non-zero error.');

	$prerelease = managed_release_command_failure(array(
		'id' => 'fixture-channel', 'latest_version' => '0.57.4', 'read_installed_version' => static fn(): string => '0.57.4-beta.1',
	));
	managed_release_command_assert('prerelease' === $prerelease['state'], 'Prerelease apply must return a structured non-zero error.');

	$unavailable = managed_release_command_failure(array());
	managed_release_command_assert('unavailable_channel' === $unavailable['state'], 'Unavailable channel apply must return a structured non-zero error.');

	$GLOBALS['managed_release_command_channel'] = array(
		'id' => 'fixture-channel', 'latest_version' => 'invalid', 'read_installed_version' => static fn(): string => '0.57.1',
	);
	( new \DataMachineCode\Cli\Commands\RuntimeCommand() )->release(array(), array( 'format' => 'json' ));
	managed_release_command_assert(1 === count(WP_CLI::$lines), 'Read-only status must continue to render diagnostics without a CLI error.');
	managed_release_command_assert('invalid_version' === ( json_decode(WP_CLI::$lines[0], true)['state'] ?? '' ), 'Read-only diagnostics must preserve the reported state.');

	echo "managed-release-runtime-command: ok\n";
}
