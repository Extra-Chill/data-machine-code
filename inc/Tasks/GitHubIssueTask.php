<?php
/**
 * GitHub Issue Creation Task for System Agent.
 *
 * Creates GitHub issues via GitHubAbilities for centralized API handling.
 *
 * @package DataMachineCode\Tasks
 * @since 0.1.0
 */

namespace DataMachineCode\Tasks;

use DataMachine\Engine\AI\System\Tasks\SystemTask;
use DataMachineCode\Abilities\GitHubAbilities;

defined( 'ABSPATH' ) || exit;

class GitHubIssueTask extends SystemTask {

	/**
	 * Execute GitHub issue creation.
	 *
	 * Delegates to GitHubAbilities::createIssue() for centralized API handling.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $jobId  Job ID from DM Jobs table.
	 * @param array $params Task parameters from engine_data.
	 */
	public function execute( int $jobId, array $params ): void {
		$result = GitHubAbilities::createIssue( $params );

		if ( is_wp_error( $result ) ) {
			$this->failJob( $jobId, $result->get_error_message() );
			return;
		}

		$this->completeJob( $jobId, array(
			'issue_url'    => $result['issue_url'] ?? '',
			'issue_number' => $result['issue_number'] ?? 0,
			'html_url'     => $result['html_url'] ?? '',
			'repo'         => $result['issue']['repo'] ?? '',
			'title'        => $result['issue']['title'] ?? '',
			'message'      => $result['message'] ?? '',
		) );
	}

	/**
	 * Get the task type identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function getTaskType(): string {
		return 'github_create_issue';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function getTaskMeta(): array {
		return array(
			'label'           => 'GitHub Issue Creation',
			'description'     => 'Create GitHub issues via the GitHub REST API.',
			'setting_key'     => null,
			'default_enabled' => true,
			'trigger'         => 'AI tool call',
			'trigger_type'    => 'tool',
			'supports_run'    => false,
		);
	}
}
