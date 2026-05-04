<?php
/**
 * Pure-PHP smoke for DMC agent maintenance flow provisioning.
 *
 * Run: php tests/smoke-agent-maintenance-flow-provisioner.php
 */

declare( strict_types=1 );

namespace DataMachineCode\Workspace {
	class Workspace {
		public function get_path(): string {
			return '/tmp/dmc-workspace';
		}
	}
}

namespace DataMachine\Core\Database\Agents {
	class Agents {
		public function get_agent( int $agent_id ): ?array {
			return 42 === $agent_id ? array( 'agent_id' => 42, 'agent_slug' => 'code-agent', 'agent_name' => 'Code Agent' ) : null;
		}

		public function get_by_slug( string $agent_slug ): ?array {
			return 'code-agent' === $agent_slug ? array( 'agent_id' => 42, 'agent_slug' => 'code-agent', 'agent_name' => 'Code Agent' ) : null;
		}
	}
}

namespace DataMachine\Core\Database\Pipelines {
	class Pipelines {
		public static array $rows = array();
		public static int $next_id = 100;

		public function get_by_portable_slug( int $agent_id, string $portable_slug ): ?array {
			foreach ( self::$rows as $row ) {
				if ( $agent_id === (int) $row['agent_id'] && $portable_slug === $row['portable_slug'] ) {
					return $row;
				}
			}
			return null;
		}

		public function create_pipeline( array $pipeline_data ): int|false {
			$id                             = ++self::$next_id;
			self::$rows[ $id ]              = array_merge( $pipeline_data, array( 'pipeline_id' => $id ) );
			self::$rows[ $id ]['agent_id']  = (int) ( $pipeline_data['agent_id'] ?? 0 );
			return $id;
		}

		public function get_pipeline( int $pipeline_id ): ?array {
			return self::$rows[ $pipeline_id ] ?? null;
		}

		public function update_pipeline( int $pipeline_id, array $pipeline_data ): bool {
			self::$rows[ $pipeline_id ] = array_merge( self::$rows[ $pipeline_id ] ?? array(), $pipeline_data );
			return true;
		}
	}
}

namespace DataMachine\Core\Database\Flows {
	class Flows {
		public static array $rows = array();
		public static int $next_id = 200;

		public function get_by_portable_slug( int $pipeline_id, string $portable_slug ): ?array {
			foreach ( self::$rows as $row ) {
				if ( $pipeline_id === (int) $row['pipeline_id'] && $portable_slug === $row['portable_slug'] ) {
					return $row;
				}
			}
			return null;
		}

		public function create_flow( array $flow_data ): int|false {
			$id                        = ++self::$next_id;
			self::$rows[ $id ]         = array_merge( $flow_data, array( 'flow_id' => $id ) );
			self::$rows[ $id ]['agent_id'] = (int) ( $flow_data['agent_id'] ?? 0 );
			return $id;
		}

		public function get_flow( int $flow_id ): ?array {
			return self::$rows[ $flow_id ] ?? null;
		}

		public function update_flow( int $flow_id, array $flow_data ): bool {
			self::$rows[ $flow_id ] = array_merge( self::$rows[ $flow_id ] ?? array(), $flow_data );
			return true;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}

	function sanitize_title( $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '-', trim( (string) $value ) ) ); }
	function sanitize_key( $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
	function get_bloginfo( string $show ): string { return 'name' === $show ? 'Test Site' : ''; }
	function home_url(): string { return 'https://example.test'; }

	require __DIR__ . '/../inc/Pipelines/WorkspaceMaintenancePipelineTemplates.php';
	require __DIR__ . '/../inc/Maintenance/MaintenanceFlowProvisioner.php';

	use DataMachine\Core\Database\Flows\Flows;
	use DataMachine\Core\Database\Pipelines\Pipelines;
	use DataMachineCode\Maintenance\MaintenanceFlowProvisioner;

	$failures = 0;
	$total    = 0;
	$assert   = function ( bool $condition, string $message ) use ( &$failures, &$total ): void {
		++$total;
		if ( $condition ) {
			echo "  ok {$message}\n";
			return;
		}
		++$failures;
		echo "  fail {$message}\n";
	};

	echo "DMC agent maintenance flow provisioner - smoke\n";

	$provisioner = new MaintenanceFlowProvisioner();
	$result      = $provisioner->provision( array( 'agent' => 'code-agent' ) );
	$assert( ! $result instanceof WP_Error, 'provision succeeds for agent slug' );
	$assert( 5 === count( $result['flows'] ?? array() ), 'five maintenance flows are provisioned' );
	$assert( 5 === count( Pipelines::$rows ), 'five agent-owned pipelines are created' );
	$assert( 5 === count( Flows::$rows ), 'five agent-owned flows are created' );
	$assert( array( 'created' ) === array_values( array_unique( array_column( $result['flows'], 'status' ) ) ), 'first run reports created statuses' );

	$flow = reset( Flows::$rows );
	$assert( 42 === (int) ( $flow['agent_id'] ?? 0 ), 'flow carries agent_id for agent-scoped listing' );
	$assert( isset( $flow['scheduling_config']['data_machine_code_agent_maintenance'] ), 'scheduling config carries maintenance marker' );
	$assert( '/tmp/dmc-workspace' === ( $flow['scheduling_config']['data_machine_code_agent_maintenance']['workspace_root'] ?? '' ), 'marker carries workspace root' );
	$assert( '2026-05-04' === ( $flow['scheduling_config']['data_machine_code_agent_maintenance']['template_version'] ?? '' ), 'marker carries source template version' );

	$artifact = null;
	foreach ( Flows::$rows as $row ) {
		if ( 'dmc-workspace-artifact-cleanup-flow' === ( $row['portable_slug'] ?? '' ) ) {
			$artifact = $row;
			break;
		}
	}
	$artifact_step = array_values( $artifact['flow_config'] ?? array() )[0] ?? array();
	$assert( 'every_4_hours' === ( $artifact['scheduling_config']['interval'] ?? '' ), 'artifact cleanup defaults to frequent schedule' );
	$assert( false === ( $artifact_step['handler_config']['params']['dry_run'] ?? true ), 'artifact cleanup flow performs background work' );
	$assert( 'agent_maintenance_flow' === ( $artifact_step['handler_config']['params']['source'] ?? '' ), 'flow task params carry agent maintenance source' );

	$second = $provisioner->provision( array( 'agent_id' => 42 ) );
	$assert( ! $second instanceof WP_Error, 'provision succeeds for numeric agent id' );
	$assert( 5 === count( Pipelines::$rows ), 'second run does not duplicate pipelines' );
	$assert( 5 === count( Flows::$rows ), 'second run does not duplicate flows' );
	$assert( array( 'unchanged' ) === array_values( array_unique( array_column( $second['flows'], 'status' ) ) ), 'second run reports unchanged statuses' );

	$artifact['scheduling_config']['interval'] = 'manual';
	Flows::$rows[ $artifact['flow_id'] ]       = $artifact;
	$third = $provisioner->provision( array( 'agent_id' => 42 ) );
	$assert( ! $third instanceof WP_Error, 'repair run succeeds' );
	$assert( in_array( 'updated', array_column( $third['flows'], 'status' ), true ), 'outdated flow is updated in place' );

	echo "\nAssertions: {$total}, Failures: {$failures}\n";
	exit( $failures > 0 ? 1 : 0 );
}
