<?php
/**
 * Standalone coverage for the documented-synchronous `cleanup safe --dry-run` preview path.
 */

declare(strict_types=1);

namespace DataMachineCode\Workspace {
	if ( ! class_exists( Workspace::class ) ) {
		class Workspace {}
	}
}

namespace DataMachine\Cli {
	if ( ! class_exists( BaseCommand::class ) ) {
		abstract class BaseCommand {
			protected function format_items( array $items, array $fields, array $assoc_args, string $id_field = '' ): void {}
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/fixtures/' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public string $code;
			public string $message;
			public array $data;

			public function __construct( string $code = '', string $message = '', array $data = array() ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}

			public function get_error_message(): string {
				return $this->message;
			}
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( mixed $thing ): bool {
			return $thing instanceof WP_Error;
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
			return json_encode( $value, $flags, $depth );
		}
	}

	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {
			public static string $output = '';

			public static function line( string $message ): void {
				self::$output .= $message;
			}

			public static function log( string $message ): void {
				self::$output .= $message . "\n";
			}

			public static function error( string $message ): void {
				throw new RuntimeException( 'WP_CLI::error: ' . $message );
			}
		}
	}

	final class SafeCleanupPreviewAbility {
		public int $calls     = 0;
		/** @var array<int,array<string,mixed>> */
		public array $inputs  = array();

		/** @param array<string,mixed> $input */
		public function execute( array $input ): array {
			++$this->calls;
			$this->inputs[] = $input;

			return array(
				'success'     => true,
				'mode'        => 'safe_workspace_cleanup',
				'run_id'      => 'cleanup-run-preview-sync-1',
				'state'       => 'complete',
				'applied'     => false,
				'destructive' => false,
				'summary'     => array(
					'cycles'              => 1,
					'planned'             => 2,
					'would_remove'        => 2,
					'would_reclaim_bytes' => 8192,
					'removed'             => 0,
				),
				'steps'       => array(
					'artifact_cleanup' => array(
						'mode'      => 'artifacts',
						'applied'   => false,
						'planned'   => 2,
						'rows'      => array(
							array( 'handle' => 'repo@preview-a', 'reason' => 'artifact_reclaimable' ),
							array( 'handle' => 'repo@preview-b', 'reason' => 'artifact_reclaimable' ),
						),
						'state'     => 'planned',
					),
				),
				'blockers'    => array(),
				'commands'    => array(
					'status' => 'studio wp datamachine-code workspace cleanup status cleanup-run-preview-sync-1 --format=json',
				),
				'continuation' => array( 'run_id' => 'cleanup-run-preview-sync-1' ),
			);
		}
	}

	final class SafeCleanupQueuedScheduleAbility {
		public int $calls = 0;

		public function execute( array $input ): array {
			++$this->calls;

			return array(
				'success'    => true,
				'state'      => 'queued',
				'run_id'     => 'cleanup-run-request-queueddryrun',
				'job_id'     => 296025,
				'mode'       => 'safe_workspace_cleanup',
				'preview'    => ! empty( $input['dry_run'] ),
				'steps'      => array(),
			);
		}
	}

	$cleanup_safe_preview_ability = new SafeCleanupPreviewAbility();
	$cleanup_safe_queued_ability  = new SafeCleanupQueuedScheduleAbility();
	if ( ! function_exists( 'wp_get_ability' ) ) {
		function wp_get_ability( string $name ): mixed {
			return match ( $name ) {
				'datamachine-code/workspace-cleanup-safe' => $GLOBALS['cleanup_safe_preview_ability'],
				'datamachine-code/workspace-cleanup-safe-run' => $GLOBALS['cleanup_safe_queued_ability'],
				default => null,
			};
		}
	}

	function safe_dry_run_assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException( sprintf( "%s\nExpected: %s\nActual: %s", $message, var_export( $expected, true ), var_export( $actual, true ) ) );
		}
	}

	require_once dirname( __DIR__ ) . '/inc/Cli/CliResponseRenderer.php';
	require_once dirname( __DIR__ ) . '/inc/Cli/Commands/WorkspaceCommand.php';

	$command = new DataMachineCode\Cli\Commands\WorkspaceCommand();

	WP_CLI::$output = '';
	$command->cleanup(
		array( 'safe' ),
		array(
			'dry-run'    => true,
			'format'     => 'json',
			'request-id' => 'preview-caller-1',
		)
	);
	$json_output = json_decode( WP_CLI::$output, true, 512, JSON_THROW_ON_ERROR );

	safe_dry_run_assert_same( 1, $cleanup_safe_preview_ability->calls, 'Safe cleanup dry-run must execute the synchronous preview ability in-process.' );
	safe_dry_run_assert_same( 0, $cleanup_safe_queued_ability->calls, 'Safe cleanup dry-run must perform no durable job scheduling.' );
	safe_dry_run_assert_same( 'complete', $json_output['state'] ?? null, 'Safe cleanup dry-run must return a terminal preview state, never queued.' );
	safe_dry_run_assert_same( false, array_key_exists( 'job_id', $json_output ), 'Safe cleanup dry-run must not return a job_id.' );
	safe_dry_run_assert_same( false, array_key_exists( 'preview', $json_output ), 'Safe cleanup dry-run is the preview itself and must not carry a queued-preview marker.' );
	safe_dry_run_assert_same( 2, $json_output['summary']['would_remove'] ?? null, 'Safe cleanup dry-run must return populated preview counts.' );
	safe_dry_run_assert_same( 2, count( (array) ( $json_output['steps']['artifact_cleanup']['rows'] ?? array() ) ), 'Safe cleanup dry-run must return populated preview rows.' );
	safe_dry_run_assert_same( true, ! empty( $cleanup_safe_preview_ability->inputs[0]['dry_run'] ), 'Synchronous preview ability must receive the dry_run flag.' );
	safe_dry_run_assert_same( 'preview-caller-1', $cleanup_safe_preview_ability->inputs[0]['request_id'] ?? null, 'Synchronous preview must preserve request correlation.' );

	WP_CLI::$output = '';
	$command->cleanup(
		array( 'safe' ),
		array( 'format' => 'json' )
	);
	$destructive_output = json_decode( WP_CLI::$output, true, 512, JSON_THROW_ON_ERROR );

	safe_dry_run_assert_same( 1, $cleanup_safe_preview_ability->calls, 'Destructive safe cleanup must not consume the synchronous preview path.' );
	safe_dry_run_assert_same( 1, $cleanup_safe_queued_ability->calls, 'Destructive safe cleanup JSON must keep scheduling the durable run.' );
	safe_dry_run_assert_same( 'queued', $destructive_output['state'] ?? null, 'Destructive safe cleanup JSON must keep returning the durable queued envelope.' );

	WP_CLI::$output = '';
	$command->cleanup(
		array( 'safe' ),
		array( 'dry-run' => true )
	);

	safe_dry_run_assert_same( 2, $cleanup_safe_preview_ability->calls, 'Non-JSON safe cleanup dry-run must keep using the synchronous preview ability.' );
	safe_dry_run_assert_same( 1, $cleanup_safe_queued_ability->calls, 'Non-JSON dry-run must not schedule a durable job.' );

	echo "cleanup safe dry-run synchronous test passed.\n";
}
