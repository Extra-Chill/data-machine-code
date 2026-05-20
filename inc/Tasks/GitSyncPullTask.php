<?php
/**
 * GitSync Pull System Task.
 *
 * @package DataMachineCode\Tasks
 */

namespace DataMachineCode\Tasks;

use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachineCode\GitSync\GitSync;

defined( 'ABSPATH' ) || exit;

class GitSyncPullTask extends SystemTask {

	public function getTaskType(): string {
		return 'gitsync_pull';
	}

	/**
	 * Task metadata for Data Machine system-task surfaces.
	 *
	 * @return array<string,mixed>
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'GitSync Pull',
			'description'     => 'Pull a registered GitSync binding from GitHub into its site-owned local directory. Designed for webhook-triggered flows and managed hosts.',
			'setting_key'     => null,
			'default_enabled' => true,
			'trigger'         => 'Manual, scheduled, or webhook-triggered Data Machine system task',
			'trigger_type'    => 'manual_or_background',
			'supports_run'    => true,
		);
	}

	/**
	 * Pull one GitSync binding.
	 *
	 * Params:
	 * - slug: required GitSync binding slug.
	 * - allow_dirty: optional bool to override conflict policy for this pull.
	 *
	 * @param int   $jobId  Data Machine job ID.
	 * @param array $params Task params.
	 */
	public function executeTask( int $jobId, array $params ): void {
		$slug = sanitize_key( (string) ( $params['slug'] ?? '' ) );
		if ( '' === $slug ) {
			$this->failJob( $jobId, 'gitsync_pull requires a slug parameter.' );
			return;
		}

		$args = array();
		if ( array_key_exists( 'allow_dirty', $params ) ) {
			$args['allow_dirty'] = self::truthy( $params['allow_dirty'] );
		}

		$result = ( new GitSync() )->pull( $slug, $args );
		if ( $result instanceof \WP_Error ) {
			do_action(
				'datamachine_log',
				'error',
				'GitSync pull failed',
				array(
					'task'  => $this->getTaskType(),
					'jobId' => $jobId,
					'slug'  => $slug,
					'error' => $result->get_error_message(),
					'code'  => $result->get_error_code(),
				)
			);
			$this->failJob( $jobId, $result->get_error_message() );
			return;
		}

		do_action(
			'datamachine_log',
			'info',
			'GitSync pull completed',
			array(
				'task'   => $this->getTaskType(),
				'jobId'  => $jobId,
				'slug'   => $slug,
				'result' => $result,
			)
		);

		$this->completeJob( $jobId, $result );
	}

	private static function truthy( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
