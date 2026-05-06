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
			'description' => 'Publish generated files to a GitHub branch and open a pull request as the publish step for this pipeline item. Call exactly once when the item is ready to publish.',
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
					'files'                 => array(
						'type'        => 'array',
						'description' => 'Optional files to commit to the head branch before opening the pull request.',
						'items'       => array(
							'type'       => 'object',
							'required'   => array( 'file_path', 'content' ),
							'properties' => array(
								'file_path'      => array(
									'type'        => 'string',
									'description' => 'Path within the repository.',
								),
								'content'        => array(
									'type'        => 'string',
									'description' => 'File content to commit.',
								),
								'commit_message' => array(
									'type'        => 'string',
									'description' => 'Commit message for this file. Defaults to the publish commit_message or PR title.',
								),
							),
						),
					),
					'commit_message'        => array(
						'type'        => 'string',
						'description' => 'Default commit message for files committed during publish.',
					),
					'labels'                => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Labels to apply to the pull request after it is opened. Overrides handler config when provided.',
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

		$committed_files = array();
		$files           = is_array( $parameters['files'] ?? null ) ? $parameters['files'] : array();
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				return $this->errorResponse( 'GitHub Pull Request: each files item must be an object.', array( 'job_id' => $parameters['job_id'] ?? null ) );
			}

			$file_result = GitHubAbilities::createOrUpdateFile( array(
				'repo'           => $repo,
				'file_path'      => $file['file_path'] ?? '',
				'content'        => $file['content'] ?? '',
				'commit_message' => $file['commit_message'] ?? ( $parameters['commit_message'] ?? $input['title'] ),
				'branch'         => $input['head'],
			) );

			if ( is_wp_error( $file_result ) ) {
				return $this->errorResponse(
					'GitHub Pull Request: failed to commit file before opening PR: ' . $file_result->get_error_message(),
					array(
						'job_id'    => $parameters['job_id'] ?? null,
						'repo'      => $repo,
						'head'      => $input['head'],
						'file_path' => $file['file_path'] ?? '',
					)
				);
			}

			$committed_files[] = array(
				'file_path'  => $file_result['content']['path'] ?? ( $file['file_path'] ?? '' ),
				'commit_sha' => $file_result['commit']['sha'] ?? '',
				'file_url'   => $file_result['content']['html_url'] ?? '',
			);
		}

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

		$pull_number = (int) ( $result['pull_number'] ?? 0 );

		$labels         = $this->resolveListParameter( $parameters['labels'] ?? null, $handler_config['labels'] ?? '' );
		$applied_labels = array();
		$label_error    = null;

		if ( ! empty( $labels ) && $pull_number > 0 ) {
			$label_result = GitHubAbilities::addLabels( array(
				'repo'        => $repo,
				'pull_number' => $pull_number,
				'labels'      => $labels,
			) );

			if ( is_wp_error( $label_result ) ) {
				$label_error = $label_result->get_error_message();
				$this->log(
					'warning',
					'GitHub Pull Request opened but failed to apply labels: ' . $label_error,
					array(
						'job_id'      => $parameters['job_id'] ?? null,
						'repo'        => $repo,
						'pull_number' => $pull_number,
						'labels'      => $labels,
					)
				);
			} else {
				$applied_labels = $label_result['applied_labels'] ?? array();
			}
		}

		$response_data = array(
			'repo'           => $repo,
			'pull_number'    => $pull_number,
			'html_url'       => $result['html_url'] ?? '',
			'title'          => $input['title'],
			'head'           => $input['head'],
			'base'           => $input['base'],
			'files'          => $committed_files,
			'labels'         => $labels,
			'applied_labels' => $applied_labels,
		);

		if ( null !== $label_error ) {
			$response_data['label_error'] = $label_error;
		}

		return $this->successResponse( $response_data );
	}

	/**
	 * Resolve a list supplied by tool parameters or comma-separated handler config.
	 *
	 * @param mixed  $parameter Tool parameter value.
	 * @param string $fallback  Comma-separated handler config fallback.
	 * @return array<int, string> Sanitized list.
	 */
	private function resolveListParameter( mixed $parameter, string $fallback ): array {
		$items = array();
		if ( is_array( $parameter ) ) {
			$items = $parameter;
		} elseif ( is_string( $parameter ) && '' !== $parameter ) {
			$items = array_map( 'trim', explode( ',', $parameter ) );
		}

		if ( empty( $items ) && '' !== $fallback ) {
			$items = array_map( 'trim', explode( ',', $fallback ) );
		}

		return array_values(
			array_filter(
				array_map( 'sanitize_text_field', $items ),
				static fn( string $item ): bool => '' !== $item
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
