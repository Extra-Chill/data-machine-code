<?php
/**
 * Smoke test for bundled workspace maintenance pipeline templates.
 *
 *   php tests/smoke-workspace-maintenance-pipeline-templates.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	function sanitize_key( string $key ): string {
		$key = strtolower( $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '';
	}

	require_once dirname( __DIR__ ) . '/inc/Pipelines/WorkspaceMaintenancePipelineTemplates.php';

	function datamachine_code_pipeline_templates_assert( bool $condition, string $message ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}
		echo "  [FAIL] {$message}\n";
		exit( 1 );
	}

	echo "=== smoke-workspace-maintenance-pipeline-templates ===\n";

	$templates = \DataMachineCode\Pipelines\WorkspaceMaintenancePipelineTemplates::templates();

	echo "\n[1] Bundled template set\n";
	datamachine_code_pipeline_templates_assert( 5 === count( $templates ), 'five workspace maintenance templates are bundled' );
	foreach ( array( 'workspace-inventory', 'workspace-metadata-repair', 'workspace-artifact-cleanup', 'workspace-retention-cleanup', 'workspace-emergency-cleanup' ) as $key ) {
		datamachine_code_pipeline_templates_assert( isset( $templates[ $key ] ), "template {$key} exists" );
		datamachine_code_pipeline_templates_assert( str_starts_with( (string) $templates[ $key ]['slug'], 'dmc-' ), "template {$key} has DMC portable slug" );
		datamachine_code_pipeline_templates_assert( ! empty( $templates[ $key ]['inputs'] ), "template {$key} exposes configurable inputs" );
	}

	echo "\n[2] Persisted pipeline_config shape\n";
	$reflection = new \ReflectionClass( \DataMachineCode\Pipelines\WorkspaceMaintenancePipelineTemplates::class );
	$method     = $reflection->getMethod( 'pipeline_config' );
	foreach ( $templates as $key => $template ) {
		$config = $method->invoke( null, $template );
		datamachine_code_pipeline_templates_assert( ! empty( $config ), "template {$key} builds pipeline steps" );
		foreach ( $config as $step_id => $step ) {
			datamachine_code_pipeline_templates_assert( $step_id === ( $step['pipeline_step_id'] ?? '' ), "template {$key} uses stable step IDs" );
			datamachine_code_pipeline_templates_assert( ! empty( $step['step_type'] ), "template {$key} step has step_type" );
			datamachine_code_pipeline_templates_assert( isset( $step['execution_order'] ), "template {$key} step has execution order" );
			datamachine_code_pipeline_templates_assert( ( $step['template_meta']['slug'] ?? '' ) === $template['slug'], "template {$key} keeps template metadata inspectable" );
		}
	}

	echo "\nAll workspace maintenance pipeline template smoke tests passed.\n";
}
