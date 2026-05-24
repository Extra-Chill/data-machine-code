<?php
/**
 * GitHub Issue Publish Handler.
 *
 * @package DataMachineCode\Handlers\GitHub
 */

namespace DataMachineCode\Handlers\GitHub;

use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachineCode\Abilities\GitHubAbilities;

if ( ! defined('ABSPATH') ) {
	exit;
}

class GitHubIssuePublish extends PublishHandler {



	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct('github_issue');

		self::registerHandler(
			'github_issue',
			'publish',
			self::class,
			'GitHub Issue',
			'Create GitHub issues from AI-generated pipeline packets',
			false,
			null,
			GitHubIssuePublishSettings::class,
			array( self::class, 'registerTools' )
		);
	}

	/**
	 * Register the AI-visible publish handler tool.
	 *
	 * @param  array  $tools          Registered tools.
	 * @param  string $handler_slug   Current handler slug.
	 * @param  array  $handler_config Handler configuration.
	 * @return array Tools with GitHub issue publish tool added.
	 */
	public static function registerTools( array $tools, string $handler_slug, array $handler_config ): array {
		if ( 'github_issue' !== $handler_slug ) {
			return $tools;
		}

		$tools['github_issue_publish'] = array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'handler'     => 'github_issue',
			'description' => 'Create a GitHub issue as the publish step for this pipeline item. Call exactly once with the final issue title and body.',
			'parameters'  => array(
				'type'       => 'object',
				'required'   => array( 'title', 'body' ),
				'properties' => array(
					'title'     => array(
						'type'        => 'string',
						'description' => 'GitHub issue title.',
					),
					'body'      => array(
						'type'        => 'string',
						'description' => 'GitHub issue body in Markdown.',
					),
					'repo'      => array(
						'type'        => 'string',
						'description' => 'Repository in owner/repo format. Overrides handler config when provided.',
					),
					'labels'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Labels to apply to the issue. Overrides handler config when provided.',
					),
					'assignees' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'GitHub usernames to assign.',
					),
					'milestone' => array(
						'type'        => 'integer',
						'description' => 'Milestone number to assign.',
					),
				),
			),
		);

		return $tools;
	}

	/**
	 * Create the GitHub issue.
	 *
	 * @param  array $parameters     Tool parameters.
	 * @param  array $handler_config Handler configuration.
	 * @return array Publish result.
	 */
	protected function executePublish( array $parameters, array $handler_config ): array {
		$repo = ! empty($parameters['repo'])
		? sanitize_text_field($parameters['repo'])
		: (string) ( $handler_config['repo'] ?? '' );

		if ( '' === $repo ) {
			$repo = GitHubAbilities::getDefaultRepo();
		}

		$labels = $this->resolveListParameter($parameters['labels'] ?? null, $handler_config['labels'] ?? '');

		$input = array(
			'repo'      => $repo,
			'title'     => $parameters['title'] ?? '',
			'body'      => $parameters['body'] ?? '',
			'labels'    => $labels,
			'assignees' => $this->resolveListParameter($parameters['assignees'] ?? null, $handler_config['assignees'] ?? ''),
		);

		if ( isset($parameters['milestone']) && '' !== $parameters['milestone'] ) {
			$input['milestone'] = (int) $parameters['milestone'];
		} elseif ( isset($handler_config['milestone']) && '' !== $handler_config['milestone'] ) {
			$input['milestone'] = (int) $handler_config['milestone'];
		}

		$result = GitHubAbilities::createIssue($input);
		if ( is_wp_error($result) ) {
			return $this->errorResponse(
				'GitHub Issue: ' . $result->get_error_message(),
				array(
					'job_id' => $parameters['job_id'] ?? null,
					'repo'   => $repo,
				)
			);
		}

		return $this->successResponse(
			array(
				'repo'         => $repo,
				'issue_url'    => $result['issue_url'] ?? '',
				'issue_number' => $result['issue_number'] ?? 0,
				'html_url'     => $result['html_url'] ?? '',
				'title'        => $input['title'],
				'labels'       => $labels,
			)
		);
	}

	/**
	 * Resolve a list supplied by tool parameters or comma-separated handler config.
	 *
	 * @param  mixed  $parameter Tool parameter value.
	 * @param  string $fallback  Comma-separated handler config fallback.
	 * @return array<int, string> Sanitized list.
	 */
	private function resolveListParameter( mixed $parameter, string $fallback ): array {
		$items = is_array($parameter) ? $parameter : array();
		if ( empty($items) && '' !== $fallback ) {
			$items = array_map('trim', explode(',', $fallback));
		}

		return array_values(
			array_filter(
				array_map('sanitize_text_field', $items),
				static fn( string $item ): bool => '' !== $item
			)
		);
	}
}
