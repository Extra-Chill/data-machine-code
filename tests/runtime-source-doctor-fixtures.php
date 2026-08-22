<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/fixtures/');
require_once dirname(__DIR__) . '/vendor/autoload.php';

use DataMachineCode\Runtime\RuntimeSourceDoctor;

function runtime_doctor_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$source = array( 'available' => true, 'path' => '/source', 'deployment' => 'git_checkout', 'version' => '1.2.0', 'head' => 'source' );
$fixtures = array(
	'checkout'              => array( array( 'available' => true, 'path' => '/runtime', 'deployment' => 'git_checkout', 'version' => '1.1.0', 'head' => 'runtime' ), 'runtime_source_drift' ),
	'symlink'               => array( array( 'available' => true, 'path' => '/runtime', 'deployment' => 'symlink', 'version' => '1.1.0', 'head' => '' ), 'runtime_source_drift' ),
	'copied_deploy'         => array( array( 'available' => true, 'path' => '/runtime', 'deployment' => 'copied_deploy', 'version' => '1.1.0', 'head' => '' ), 'runtime_source_drift' ),
	'aligned'               => array( array( 'available' => true, 'path' => '/runtime', 'deployment' => 'git_checkout', 'version' => '1.2.0', 'head' => 'source' ), 'aligned' ),
	'behind'                => array( array( 'available' => true, 'path' => '/runtime', 'deployment' => 'git_checkout', 'version' => '1.1.0', 'head' => 'runtime' ), 'runtime_behind_source' ),
	'diverged'              => array( array( 'available' => true, 'path' => '/runtime', 'deployment' => 'git_checkout', 'version' => '1.1.0', 'head' => 'runtime' ), 'runtime_diverged_source' ),
	'unavailable_source'    => array( array( 'available' => true, 'path' => '/runtime', 'deployment' => 'copied_deploy', 'version' => '1.1.0', 'head' => '' ), 'source_unavailable' ),
	'command_contract_drift'=> array( array( 'available' => true, 'path' => '/runtime', 'deployment' => 'copied_deploy', 'version' => '1.2.0', 'head' => '' ), 'command_contract_drift' ),
);

foreach ( $fixtures as $name => [ $runtime, $expected ] ) {
	$fixture_source = 'unavailable_source' === $name ? array( 'available' => false, 'reason' => 'source_path_not_configured' ) : $source;
	if ( in_array($name, array( 'behind', 'diverged' ), true) ) {
		$fixture_source['relation'] = $name;
	}
	$contract = 'command_contract_drift' === $name ? array( 'drift' => true ) : array( 'drift' => false );
	$result = RuntimeSourceDoctor::classify($runtime, $fixture_source, $contract);
	runtime_doctor_assert($expected === $result['classification'], sprintf('%s fixture classified as %s.', $name, $result['classification']));
}

echo "runtime-source-doctor-fixtures: ok\n";
