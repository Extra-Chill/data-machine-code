<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$GLOBALS['managed_release_channel'] = array();
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	return 'datamachine_code_managed_release_channel' === $hook ? $GLOBALS['managed_release_channel'] : $value;
}

require_once dirname(__DIR__) . '/inc/Runtime/ManagedReleaseDrift.php';

use DataMachineCode\Runtime\ManagedReleaseDrift;

function managed_release_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

function managed_release_channel( string $latest, callable $read, callable $verify, array $extra = array() ): array {
	return array_merge(array(
		'id'                     => 'fixture-channel',
		'latest_version'         => $latest,
		'read_installed_version' => $read,
		'verify'                 => $verify,
	), $extra);
}

$GLOBALS['managed_release_observed'] = '0.57.4';
$GLOBALS['managed_release_channel'] = managed_release_channel(
	'0.57.4',
	static fn(): string => $GLOBALS['managed_release_observed'],
	static fn( string $version ): array => array( 'state' => 'verified', 'cli_contract_present' => '0.57.4' === $version )
);
$current = ( new ManagedReleaseDrift('0.57.1') )->status();
managed_release_assert('current' === $current['state'], 'The observed installed release must define current state.');
managed_release_assert(true === ( $current['verification']['cli_contract_present'] ?? false ), 'Current release must report installed contract verification.');

$GLOBALS['managed_release_observed'] = '0.57.1';
$GLOBALS['managed_release_channel'] = managed_release_channel(
	'0.57.4',
	static fn(): string => $GLOBALS['managed_release_observed'],
	static fn( string $version ): array => array( 'state' => 'verified', 'cli_contract_present' => '0.57.4' === $version ),
	array(
		'action' => array( 'type' => 'command', 'command' => 'managed-plugin update data-machine-code', 'authorize_callback' => true ),
		'converge' => static function (): array {
			$GLOBALS['managed_release_observed'] = '0.57.4';
			return array( 'success' => true, 'installed_version' => '0.0.1' );
		},
	)
);
$drifted = new ManagedReleaseDrift('0.57.1');
managed_release_assert('drifted' === $drifted->status()['state'], 'A newer managed release must be reported without WordPress.org metadata.');
$after = $drifted->converge();
managed_release_assert('converged' === ( $after['convergence']['state'] ?? '' ), 'Observed post-update version must converge despite a false callback claim.');
managed_release_assert('0.57.4' === $after['installed_version'], 'Convergence must report the observed version, not callback data.');

$GLOBALS['managed_release_observed'] = '0.57.1';
$GLOBALS['managed_release_callback_calls'] = 0;
$GLOBALS['managed_release_channel'] = managed_release_channel(
	'0.57.4',
	static fn(): string => $GLOBALS['managed_release_observed'],
	static fn(): array => array( 'state' => 'verified' ),
	array(
		'action' => array( 'type' => 'handoff', 'code' => 'operator_required' ),
		'converge' => static function (): array {
			++$GLOBALS['managed_release_callback_calls'];
			return array( 'success' => true );
		},
	)
);
$handoff = ( new ManagedReleaseDrift() )->converge();
managed_release_assert('handoff_required' === ( $handoff['convergence']['state'] ?? '' ), 'Handoff convergence must remain a handoff.');
managed_release_assert(0 === $GLOBALS['managed_release_callback_calls'], 'Handoff must never invoke a mutation callback.');

$GLOBALS['managed_release_channel']['action'] = array( 'type' => 'command', 'command' => 'managed-plugin update data-machine-code' );
$unauthorized = ( new ManagedReleaseDrift() )->converge();
managed_release_assert('handoff_required' === ( $unauthorized['convergence']['state'] ?? '' ), 'A command callback requires explicit channel authorization.');
managed_release_assert(0 === $GLOBALS['managed_release_callback_calls'], 'An unauthorized command callback must not mutate.');

$GLOBALS['managed_release_channel'] = managed_release_channel(
	'0.57.4',
	static fn(): string => $GLOBALS['managed_release_observed'],
	static fn(): array => array( 'state' => 'verified' ),
	array(
		'action' => array( 'type' => 'command', 'command' => 'managed-plugin update data-machine-code', 'authorize_callback' => true ),
		'converge' => static fn(): array => array( 'success' => true, 'installed_version' => '0.57.4' ),
	)
);
$false_claim = ( new ManagedReleaseDrift() )->converge();
managed_release_assert('convergence_failed' === $false_claim['state'], 'A callback claim without an observed update must fail convergence.');
managed_release_assert('0.57.1' === $false_claim['installed_version'], 'False callback claims must not overwrite observed state.');

$GLOBALS['managed_release_channel']['converge'] = static function (): array {
	throw new RuntimeException('fixture callback exception');
};
$exception = ( new ManagedReleaseDrift() )->converge();
managed_release_assert('convergence_failed' === $exception['state'], 'Callback exceptions must become typed convergence failures.');
managed_release_assert(str_contains((string) ( $exception['convergence']['message'] ?? '' ), 'fixture callback exception'), 'Callback exception diagnostics must be preserved.');

foreach ( array(
	array( '0.57.5', '0.57.4', 'ahead' ),
	array( 'invalid', '0.57.4', 'invalid_version' ),
	array( '0.57.4', 'invalid', 'invalid_version' ),
	array( '0.57.4-beta.1', '0.57.4', 'prerelease' ),
	array( '0.57.4', '0.57.5-rc.1', 'prerelease' ),
) as $version_case ) {
	list( $installed, $latest, $expected ) = $version_case;
	$GLOBALS['managed_release_observed'] = $installed;
	$GLOBALS['managed_release_channel'] = managed_release_channel($latest, static fn(): string => $GLOBALS['managed_release_observed'], static fn(): array => array( 'state' => 'verified' ));
	managed_release_assert($expected === ( new ManagedReleaseDrift() )->status()['state'], sprintf('Expected %s semantics for %s and %s.', $expected, $installed, $latest));
}

$GLOBALS['managed_release_channel'] = array();
$unavailable = ( new ManagedReleaseDrift('0.57.1') )->status();
managed_release_assert('unavailable_channel' === $unavailable['state'], 'Missing managed channel must not be reported as update none.');
managed_release_assert('handoff' === ( $unavailable['action'] ?? array() )['type'], 'Unavailable channel must return a typed handoff.');

echo "managed-release-drift: ok\n";
