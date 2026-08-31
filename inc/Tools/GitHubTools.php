<?php
/**
 * Bespoke model tools that compose or adapt GitHub abilities.
 *
 * One-to-one GitHub tools are registered by AbilityToolProjections.
 *
 * @package DataMachineCode\Tools
 */

namespace DataMachineCode\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachineCode\Abilities\GitHubAbilities;

defined('ABSPATH') || exit;

final class GitHubTools extends BaseTool {

	public function __construct() {
		$contexts = array( 'chat', 'pipeline' );
		$this->registerTool('manage_github_issue', array( $this, 'getManageIssueDefinition' ), $contexts, array( 'access_level' => 'editor' ));
		$this->registerTool('add_label_to_issue', array( $this, 'getAddLabelToIssueDefinition' ), $contexts, array( 'access_level' => 'editor' ));
	}

	/** Dispatch a bespoke tool definition to its handler. */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$method = $tool_def['method'] ?? 'handleManageIssue';
		if ( method_exists($this, $method) ) {
			return $this->{$method}($parameters, $tool_def);
		}
		return $this->buildErrorResponse('Unknown method: ' . $method, 'github_tools');
	}

	public function check_configuration( mixed $configured, mixed $tool_id ): bool {
		if ( ! in_array($tool_id, array( 'manage_github_issue', 'add_label_to_issue' ), true) ) {
			return (bool) $configured;
		}
		return self::is_configured();
	}

	public static function is_configured(): bool {
		return GitHubAbilities::isConfigured();
	}

	public function getToolDefinition(): array {
		return $this->getManageIssueDefinition();
	}

	/** Compose issue update, close, and comment abilities behind one stable tool. */
	public function handleManageIssue( array $parameters, array $tool_def = array() ): array {
		$action = $parameters['action'] ?? '';
		if ( 'comment' === $action ) {
			$input = array(
				'repo'         => $parameters['repo'] ?? '',
				'issue_number' => $parameters['issue_number'] ?? 0,
				'body'         => $parameters['body'] ?? '',
			);
			if ( array_key_exists('allow_repeat_automation_comment', $parameters) ) {
				$input['allow_repeat_automation_comment'] = $parameters['allow_repeat_automation_comment'];
			}
			return $this->executeGitHubAbility('datamachine-code/comment-github-issue', 'manage_github_issue', $input);
		}
		if ( 'close' === $action ) {
			$parameters['state'] = 'closed';
		}
		return $this->executeGitHubAbility('datamachine-code/update-github-issue', 'manage_github_issue', $parameters);
	}

	public function getManageIssueDefinition(): array {
		return $this->progressDefinition(array(
			'class'       => self::class,
			'method'      => 'handleManageIssue',
			'description' => 'Update, close, or comment on a GitHub issue. Use action "update" to change title, body, or the full labels set; "close" to close it; or "comment" to add a comment.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'repo'         => array( 'type' => 'string', 'description' => 'Repository in owner/repo format.' ),
					'issue_number' => array( 'type' => 'integer', 'description' => 'Issue number.' ),
					'action'       => array( 'type' => 'string', 'enum' => array( 'update', 'close', 'comment' ) ),
					'title'        => array( 'type' => 'string', 'description' => 'New issue title for update.' ),
					'body'         => array( 'type' => 'string', 'description' => 'New issue body or comment text.' ),
					'labels'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Replacement labels set for update.' ),
					'allow_repeat_automation_comment' => array( 'type' => 'boolean', 'description' => 'Allow a repeated automation comment. Default false.' ),
				),
				'required'   => array( 'repo', 'issue_number', 'action' ),
			),
		));
	}

	/** Adapt one model-facing label to the canonical collection input. */
	public function handleAddLabelToIssue( array $parameters, array $tool_def = array() ): array {
		return $this->executeGitHubAbility(
			'datamachine-code/add-github-labels',
			'add_label_to_issue',
			array(
				'repo'         => $parameters['repo'] ?? '',
				'issue_number' => $parameters['issue_number'] ?? 0,
				'labels'       => array( $parameters['label'] ?? '' ),
			)
		);
	}

	public function getAddLabelToIssueDefinition(): array {
		return array(
			'class'       => self::class,
			'method'      => 'handleAddLabelToIssue',
			'description' => 'Add one label to a GitHub issue or pull request without replacing its other labels.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'repo'         => array( 'type' => 'string', 'description' => 'Repository in owner/repo format.' ),
					'issue_number' => array( 'type' => 'integer', 'description' => 'Issue or pull request number.' ),
					'label'        => array( 'type' => 'string', 'description' => 'Single label name to add.' ),
				),
				'required'   => array( 'repo', 'issue_number', 'label' ),
			),
		);
	}

	private function executeGitHubAbility( string $ability_name, string $tool_name, array $parameters ): array {
		$ability = function_exists('wp_get_ability') ? wp_get_ability($ability_name) : null;
		if ( ! $ability ) {
			return $this->buildErrorResponse(sprintf('GitHub ability %s is not available.', $ability_name), $tool_name);
		}

		$result = $ability->execute($parameters);
		if ( is_wp_error($result) ) {
			return $this->buildErrorResponse($result->get_error_message(), $tool_name);
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => $tool_name,
		);
	}

	protected function buildErrorResponse( string $message, string $tool_name ): array {
		return array(
			'success'   => false,
			'error'     => $message,
			'tool_name' => $tool_name,
		);
	}
}
