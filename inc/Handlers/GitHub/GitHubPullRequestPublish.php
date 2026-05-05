<?php
/**
 * GitHub Pull Request Publish Handler.
 *
 * @package DataMachineCode\Handlers\GitHub
 */

namespace DataMachineCode\Handlers\GitHub;

use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachine\Core\Steps\Publish\Handlers\PublishHandler;
use DataMachineCode\Abilities\GitHubAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitHubPullRequestPublish extends PublishHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'github_pull_request' );

		self::registerHandler(
			'github_pull_request',
			'publish',
			self::class,
			'GitHub Pull Request',
			'Open GitHub pull requests from AI-generated pipeline packets',
			false,
			null,
			GitHubPullRequestPublishSettings::class,
			array( self::class, 'registerTools' )
		);
	}

	/**
	 * Register the AI-visible PR publish handler tool.
	 *
	 * @param array  $tools          Registered tools.
	 * @param string $handler_slug   Current handler slug.
	 * @param array  $handler_config Handler configuration.
	 * @return array Tools with GitHub PR publish tool added.
	 */
	public static function registerTools( array $tools, string $handler_slug, array $handler_config ): array {
		if ( 'github_pull_request' !== $handler_slug ) {
			return $tools;
		}

		$tools['github_pull_request_publish'] = array(
			'class'       => self::class,
			'method'      => 'handle_tool_call',
			'handler'     => 'github_pull_request',
			'description' => 'Open a GitHub pull request as the publish step for this pipeline item. Call exactly once after all files are committed to the head branch.',
			'parameters'  => array(
				'type'       => 'object',
				'required'   => array( 'title', 'head' ),
				'properties' => array(
					'repo'                  => array(
						'type'        => 'string',
						'description' => 'Repository in owner/repo format. Overrides handler config when provided.',
					),
					'title'                 => array(
						'type'        => 'string',
						'description' => 'Pull request title.',
					),
					'head'                  => array(
						'type'        => 'string',
						'description' => 'Head branch where changes were committed.',
					),
					'base'                  => array(
						'type'        => 'string',
						'description' => 'Base branch. Defaults to handler config or repository default.',
					),
					'body'                  => array(
						'type'        => 'string',
						'description' => 'Pull request body in GitHub Markdown.',
					),
					'draft'                 => array(
						'type'        => 'boolean',
						'description' => 'Whether to open as draft. Defaults to false.',
					),
					'maintainer_can_modify' => array(
						'type'        => 'boolean',
						'description' => 'Whether maintainers can modify the PR. Defaults to true.',
					),
				),
			),
		);

		return $tools;
	}

	/**
	 * Open the GitHub pull request.
	 *
	 * @param array $parameters     Tool parameters.
	 * @param array $handler_config Handler configuration.
	 * @return array Publish result.
	 */
	protected function executePublish( array $parameters, array $handler_config ): array {
		$repo = ! empty( $parameters['repo'] )
			? sanitize_text_field( $parameters['repo'] )
			: (string) ( $handler_config['repo'] ?? '' );

		if ( '' === $repo ) {
			$repo = GitHubAbilities::getDefaultRepo();
		}

		$input = array(
			'repo'                  => $repo,
			'title'                 => $parameters['title'] ?? '',
			'head'                  => $parameters['head'] ?? '',
			'base'                  => $parameters['base'] ?? ( $handler_config['base'] ?? '' ),
			'body'                  => $parameters['body'] ?? '',
			'draft'                 => $this->resolveBool( $parameters['draft'] ?? null, $handler_config['draft'] ?? false ),
			'maintainer_can_modify' => $this->resolveBool( $parameters['maintainer_can_modify'] ?? null, $handler_config['maintainer_can_modify'] ?? true ),
		);

		$result = GitHubAbilities::createPullRequest( $input );
		if ( is_wp_error( $result ) ) {
			return $this->errorResponse(
				'GitHub Pull Request: ' . $result->get_error_message(),
				array(
					'job_id' => $parameters['job_id'] ?? null,
					'repo'   => $repo,
					'head'   => $input['head'],
				)
			);
		}

		return $this->successResponse(
			array(
				'repo'        => $repo,
				'pull_number' => $result['pull_number'] ?? 0,
				'html_url'    => $result['html_url'] ?? '',
				'title'       => $input['title'],
				'head'        => $input['head'],
				'base'        => $input['base'],
			)
		);
	}

	/**
	 * Resolve boolean handler values from parameters/config.
	 *
	 * @param mixed $parameter Tool parameter override.
	 * @param mixed $fallback  Handler config fallback.
	 * @return bool Resolved boolean.
	 */
	private function resolveBool( mixed $parameter, mixed $fallback ): bool {
		$value = null !== $parameter ? $parameter : $fallback;
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}
}
