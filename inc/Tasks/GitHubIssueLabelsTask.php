<?php
/**
 * GitHub issue label update system task.
 *
 * @package DataMachineCode\Tasks
 */

namespace DataMachineCode\Tasks;

use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachineCode\Abilities\GitHubAbilities;

defined('ABSPATH') || exit;

class GitHubIssueLabelsTask extends SystemTask {

	/**
	 * Task type identifier.
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return 'github_update_issue_labels';
	}

	/**
	 * Task metadata for Data Machine system-task surfaces.
	 *
	 * @return array<string,mixed>
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'GitHub Issue Label Update',
			'description'     => 'Deterministically add and remove GitHub issue labels without replacing the full label set.',
			'setting_key'     => null,
			'default_enabled' => true,
			'supports_run'    => false,
			'mutates'         => true,
			'params_schema'   => array(
				'type'       => 'object',
				'required'   => array( 'repo', 'issue_number' ),
				'properties' => array(
					'repo'          => array( 'type' => 'string' ),
					'issue_number'  => array( 'type' => 'integer' ),
					'add_labels'    => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'remove_labels' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
		);
	}

	/**
	 * This task can infer the fetched GitHub issue when embedded after a fetch step.
	 *
	 * @return bool
	 */
	public function needsPipelineContext(): bool {
		return true;
	}

	/**
	 * Execute surgical GitHub issue label updates.
	 *
	 * @param int   $jobId  Job ID.
	 * @param array $params Task params.
	 * @return void
	 */
	public function executeTask( int $jobId, array $params ): void {
		$params        = $this->withFetchedGitHubIssueContext($params);
		$repo          = trim( (string) ( $params['repo'] ?? '' ) );
		$issue_number  = (int) ( $params['issue_number'] ?? 0 );
		$add_labels    = $this->normalizeLabels($params['add_labels'] ?? array());
		$remove_labels = $this->normalizeLabels($params['remove_labels'] ?? array());

		if ( '' === $repo || $issue_number <= 0 ) {
			$this->failJob($jobId, 'GitHub issue label update requires repo and issue_number.');
			return;
		}

		if ( array() === $add_labels && array() === $remove_labels ) {
			$this->failJob($jobId, 'GitHub issue label update requires at least one add_labels or remove_labels entry.');
			return;
		}

		$removed = array();
		$results = array();

		foreach ( $remove_labels as $label ) {
			$result = GitHubAbilities::removeLabel(
				array(
					'repo'         => $repo,
					'issue_number' => $issue_number,
					'label'        => $label,
				)
			);
			if ( is_wp_error($result) ) {
				$this->failJob($jobId, $result->get_error_message());
				return;
			}
			$removed[] = $label;
			$results[] = array(
				'action' => 'remove',
				'label'  => $label,
				'result' => $result,
			);
		}

		$added = array();
		if ( array() !== $add_labels ) {
			$result = GitHubAbilities::addLabels(
				array(
					'repo'         => $repo,
					'issue_number' => $issue_number,
					'labels'       => $add_labels,
				)
			);
			if ( is_wp_error($result) ) {
				$this->failJob($jobId, $result->get_error_message());
				return;
			}
			$added     = $add_labels;
			$results[] = array(
				'action' => 'add',
				'labels' => $add_labels,
				'result' => $result,
			);
		}

		$this->completeJob(
			$jobId,
			array(
				'success'        => true,
				'repo'           => $repo,
				'issue_number'   => $issue_number,
				'added_labels'   => $added,
				'removed_labels' => $removed,
				'results'        => $results,
			)
		);
	}

	/**
	 * Normalize a scalar or array of labels to a de-duplicated list.
	 *
	 * @param mixed $labels Raw labels.
	 * @return array<int,string>
	 */
	private function normalizeLabels( mixed $labels ): array {
		if ( is_string($labels) ) {
			$labels = array( $labels );
		}
		if ( ! is_array($labels) ) {
			return array();
		}

		$normalized = array();
		foreach ( $labels as $label ) {
			$label = trim( (string) $label );
			if ( '' !== $label ) {
				$normalized[] = $label;
			}
		}

		return array_values(array_unique($normalized));
	}

	/**
	 * Fill repo and issue_number from the first GitHub issue packet when omitted.
	 *
	 * @param array<string,mixed> $params Raw params.
	 * @return array<string,mixed>
	 */
	private function withFetchedGitHubIssueContext( array $params ): array {
		$repo_missing   = '' === trim( (string) ( $params['repo'] ?? '' ) );
		$number_missing = (int) ( $params['issue_number'] ?? 0 ) <= 0;
		if ( ! $repo_missing && ! $number_missing ) {
			return $params;
		}

		$data_packets = is_array($params['data_packets'] ?? null) ? $params['data_packets'] : array();
		foreach ( $data_packets as $packet ) {
			if ( ! is_array($packet) ) {
				continue;
			}
			$metadata = is_array($packet['metadata'] ?? null) ? $packet['metadata'] : array();
			if ( 'github' !== (string) ( $metadata['source_type'] ?? '' ) || 'issues' !== (string) ( $metadata['github_type'] ?? '' ) ) {
				continue;
			}

			if ( $repo_missing && ! empty($metadata['github_repo']) ) {
				$params['repo'] = (string) $metadata['github_repo'];
			}
			if ( $number_missing && (int) ( $metadata['github_number'] ?? 0 ) > 0 ) {
				$params['issue_number'] = (int) $metadata['github_number'];
			}
			return $params;
		}

		return $params;
	}
}
