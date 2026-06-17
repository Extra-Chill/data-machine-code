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
use DataMachineCode\Support\RunArtifactBundleFileWriter;
use DataMachineCode\Support\RunArtifactPrSectionRenderer;

if ( ! defined('ABSPATH') ) {
	exit;
}

class GitHubPullRequestPublish extends PublishHandler {



	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct('github_pull_request');

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
	 * @param  array  $tools          Registered tools.
	 * @param  string $handler_slug   Current handler slug.
	 * @param  array  $handler_config Handler configuration.
	 * @return array Tools with GitHub PR publish tool added.
	 */
	public static function registerTools( array $tools, string $handler_slug, array $handler_config ): array {
		if ( 'github_pull_request' !== $handler_slug ) {
			return $tools;
		}

		$tools['github_pull_request_publish'] = array(
			'class'                   => self::class,
			'client_context_bindings' => array( 'job_id' ),
			'method'                  => 'handle_tool_call',
			'handler'                 => 'github_pull_request',
			'description'             => 'Publish generated files to a GitHub branch and open a pull request as the publish step for this pipeline item. Call exactly once when the item is ready to publish.',
			'parameters'              => array(
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
						'description' => 'Optional files to commit to the head branch before opening the pull request. Data Machine run artifacts with bundle-file egress are appended automatically when job_id is available.',
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
					'run_artifacts'         => array(
						'type'        => 'object',
						'description' => 'Optional Data Machine job artifact payload. Normally discovered from job_id; provided here for tests or external callers.',
					),
					'bundle_root'           => array(
						'type'        => 'string',
						'description' => 'Repository-relative bundle root for bundle-file artifacts. Defaults to bundles/{agent_slug}. Supports {agent_slug}, {bundle_slug}, and date placeholders.',
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

		$input = array(
			'repo'                  => $repo,
			'title'                 => $parameters['title'] ?? '',
			'head'                  => $parameters['head'] ?? '',
			'base'                  => $parameters['base'] ?? ( $handler_config['base'] ?? '' ),
			'body'                  => $parameters['body'] ?? '',
			'draft'                 => $this->resolveBool($parameters['draft'] ?? null, $handler_config['draft'] ?? false),
			'maintainer_can_modify' => $this->resolveBool($parameters['maintainer_can_modify'] ?? null, $handler_config['maintainer_can_modify'] ?? true),
		);

		$run_artifact_attachment = $this->prepareRunArtifactAttachment($parameters, $handler_config, $input['body']);
		if ( ! empty($run_artifact_attachment['body']) ) {
			$input['body'] = (string) $run_artifact_attachment['body'];
		}

		$committed_files = array();
		$files           = is_array($parameters['files'] ?? null) ? $parameters['files'] : array();
		$artifact_files  = $this->bundleFileArtifactsForPublish($parameters, $handler_config);
		$files           = array_merge($files, $artifact_files);
		foreach ( $files as $file ) {
			if ( ! is_array($file) ) {
				return $this->errorResponse('GitHub Pull Request: each files item must be an object.', array( 'job_id' => $parameters['job_id'] ?? null ));
			}

			$file_result = GitHubAbilities::createOrUpdateFile(
				array(
					'repo'           => $repo,
					'file_path'      => $file['file_path'] ?? '',
					'content'        => $file['content'] ?? '',
					'commit_message' => $file['commit_message'] ?? ( $parameters['commit_message'] ?? $input['title'] ),
					'branch'         => $input['head'],
				)
			);

			if ( is_wp_error($file_result) ) {
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

		$result = GitHubAbilities::createPullRequest($input);
		if ( is_wp_error($result) ) {
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

		$labels         = $this->resolveListParameter($parameters['labels'] ?? null, $handler_config['labels'] ?? '');
		$labeling       = is_array($result['labeling'] ?? null) ? $result['labeling'] : null;
		$applied_labels = is_array($labeling['applied_labels'] ?? null) ? $labeling['applied_labels'] : array();
		$label_error    = is_array($labeling) && false === ( $labeling['success'] ?? true ) ? (string) ( $labeling['error'] ?? '' ) : null;

		if ( null !== $label_error ) {
			$this->log(
				'warning',
				'GitHub Pull Request opened but failed to apply labels: ' . $label_error,
				array(
					'job_id'      => $parameters['job_id'] ?? null,
					'repo'        => $repo,
					'pull_number' => $pull_number,
					'labels'      => $labeling['labels'] ?? $labels,
				)
			);
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

		if ( ! empty($run_artifact_attachment['comment_body']) && $pull_number > 0 ) {
			$comment_result = GitHubAbilities::upsertPullReviewComment(
				array(
					'repo'        => $repo,
					'pull_number' => $pull_number,
					'body'        => $run_artifact_attachment['comment_body'],
					'marker'      => 'datamachine-run-artifacts',
				)
			);

			if ( is_wp_error($comment_result) ) {
				$response_data['run_artifact_comment_error'] = $comment_result->get_error_message();
				$this->log(
					'warning',
					'GitHub Pull Request opened but failed to attach run artifact comment: ' . $comment_result->get_error_message(),
					array(
						'job_id'      => $parameters['job_id'] ?? null,
						'repo'        => $repo,
						'pull_number' => $pull_number,
					)
				);
			} else {
				$response_data['run_artifact_comment'] = array(
					'action'     => $comment_result['action'] ?? '',
					'comment_id' => $comment_result['comment_id'] ?? 0,
					'html_url'   => $comment_result['html_url'] ?? '',
				);
			}
		}

		return $this->successResponse($response_data);
	}

	/**
	 * Prepare optional run artifact PR body/comment content from Data Machine policy.
	 *
	 * @param  array  $parameters     Tool parameters.
	 * @param  array  $handler_config Handler configuration.
	 * @param  string $body           Existing PR body.
	 * @return array{body?: string, comment_body?: string}
	 */
	private function prepareRunArtifactAttachment( array $parameters, array $handler_config, string $body ): array {
		$job_id = (int) ( $parameters['job_id'] ?? 0 );
		if ( $job_id <= 0 ) {
			return array();
		}

		$policy = $this->resolveRunArtifactPolicy($parameters);
		if ( empty($policy) ) {
			return array();
		}

		$artifacts = $this->jobArtifactsForPublish($job_id);
		if ( empty($artifacts) ) {
			return array();
		}

		$artifacts = $this->filterRunArtifactsForTarget($artifacts, $policy, 'pr-body');
		if ( empty($artifacts) ) {
			return array();
		}

		$mode = $this->resolveRunArtifactAttachmentMode($handler_config);
		if ( RunArtifactPrSectionRenderer::MODE_COMMENT === $mode ) {
			return array( 'comment_body' => RunArtifactPrSectionRenderer::renderForMode($mode, $artifacts) );
		}

		return array( 'body' => RunArtifactPrSectionRenderer::renderForMode(RunArtifactPrSectionRenderer::MODE_BODY_SECTION, $artifacts, $body) );
	}

	/**
	 * Resolve Data Machine run artifacts that should be written into the PR branch.
	 *
	 * @param  array $parameters     Tool parameters.
	 * @param  array $handler_config Handler configuration.
	 * @return array<int,array<string,mixed>> File records accepted by the normal files loop.
	 */
	private function bundleFileArtifactsForPublish( array $parameters, array $handler_config ): array {
		$artifacts = is_array($parameters['run_artifacts'] ?? null) ? $parameters['run_artifacts'] : $this->jobArtifactsForPublish( (int) ( $parameters['job_id'] ?? 0 ));
		if ( empty($artifacts) ) {
			return array();
		}

		$policy = is_array($parameters['run_artifact_egress_policy'] ?? null) ? $parameters['run_artifact_egress_policy'] : $this->resolveRunArtifactPolicy($parameters);
		if ( empty($policy) && is_array($artifacts['run_artifact_egress_policy'] ?? null) ) {
			$policy = $artifacts['run_artifact_egress_policy'];
		}

		$bundle_root = (string) ( $parameters['bundle_root'] ?? $handler_config['bundle_root'] ?? '' );

		return RunArtifactBundleFileWriter::fileWritesFromArtifacts($artifacts, $policy, $bundle_root);
	}

	/**
	 * @param  array $parameters Tool parameters.
	 * @return array<string, array<string, mixed>>
	 */
	private function resolveRunArtifactPolicy( array $parameters ): array {
		$engine = $parameters['engine'] ?? null;
		if ( is_object($engine) && method_exists($engine, 'get') ) {
			$policy = $engine->get('run_artifact_egress_policy', array());
			if ( is_array($policy) && ! empty($policy) ) {
				return $policy;
			}
		}

		return $this->jobRunArtifactEgressPolicy( (int) ( $parameters['job_id'] ?? 0 ));
	}

	/**
	 * @param  array<string, mixed>                $artifacts Data Machine job artifact payload.
	 * @param  array<string, array<string, mixed>> $policy    Run artifact egress policy.
	 * @return array<string, mixed>
	 */
	private function filterRunArtifactsForTarget( array $artifacts, array $policy, string $target ): array {
		$filtered = array();

		if ( $this->policyAllowsTarget($policy, 'daily_memory', $target) && ! empty($artifacts['daily_memory_artifacts']) ) {
			$filtered['daily_memory_artifacts'] = $artifacts['daily_memory_artifacts'];
		}

		if ( $this->policyAllowsTarget($policy, 'completion_assertions', $target) ) {
			foreach ( array( 'required_tool_names', 'satisfied_tool_names', 'successful_tool_calls' ) as $key ) {
				if ( array_key_exists($key, $artifacts) ) {
					$filtered[ $key ] = $artifacts[ $key ];
				}
			}

			$satisfied = is_array($filtered['satisfied_tool_names'] ?? null) ? $filtered['satisfied_tool_names'] : array();
			foreach ( array( 'github_pull_request_publish', 'create_github_pull_request' ) as $tool_name ) {
				if ( ! in_array($tool_name, $satisfied, true) ) {
					$satisfied[] = $tool_name;
				}
			}
			$filtered['satisfied_tool_names'] = $satisfied;
		}

		if ( $this->policyAllowsTarget($policy, 'transcript_summary', $target) && ! empty($artifacts['transcript']) ) {
			$filtered['transcript'] = $artifacts['transcript'];
		}

		return $filtered;
	}

	/**
	 * @param array<string, array<string, mixed>> $policy Run artifact egress policy.
	 */
	private function policyAllowsTarget( array $policy, string $source, string $target ): bool {
		$egress = $policy[ $source ]['egress'] ?? array();
		return is_array($egress) && in_array($target, $egress, true);
	}

	/**
	 * Resolve PR artifact attachment mode. Body sections are the default for Data
	 * Machine's generic `pr-body` egress target; handler config may route that
	 * same managed section to a comment without changing bundle policy.
	 */
	private function resolveRunArtifactAttachmentMode( array $handler_config ): string {
		$config = is_array($handler_config['github_pr_artifacts'] ?? null) ? $handler_config['github_pr_artifacts'] : array();
		$mode   = sanitize_text_field( (string) ( $config['mode'] ?? $handler_config['run_artifact_attachment_mode'] ?? RunArtifactPrSectionRenderer::MODE_BODY_SECTION ));

		return RunArtifactPrSectionRenderer::MODE_COMMENT === $mode ? RunArtifactPrSectionRenderer::MODE_COMMENT : RunArtifactPrSectionRenderer::MODE_BODY_SECTION;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function jobArtifactsForPublish( int $job_id ): array {
		if ( $job_id <= 0 || ! class_exists('\\DataMachine\\Core\\JobArtifacts') ) {
			return array();
		}

		$result = ( new \DataMachine\Core\JobArtifacts() )->get($job_id);
		if ( empty($result['success']) || ! is_array($result['artifacts'] ?? null) ) {
			return array();
		}

		return $result['artifacts'];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function jobRunArtifactEgressPolicy( int $job_id ): array {
		if ( $job_id <= 0 || ! class_exists('\\DataMachine\\Core\\Database\\Jobs\\Jobs') ) {
			return array();
		}

		$jobs = new \DataMachine\Core\Database\Jobs\Jobs();
		if ( ! method_exists($jobs, 'retrieve_engine_data') ) {
			return array();
		}

		$engine_data = $jobs->retrieve_engine_data($job_id);
		return is_array($engine_data['run_artifact_egress_policy'] ?? null) ? $engine_data['run_artifact_egress_policy'] : array();
	}

	/**
	 * Resolve a list supplied by tool parameters or comma-separated handler config.
	 *
	 * @param  mixed  $parameter Tool parameter value.
	 * @param  string $fallback  Comma-separated handler config fallback.
	 * @return array<int, string> Sanitized list.
	 */
	private function resolveListParameter( mixed $parameter, string $fallback ): array {
		$items = array();
		if ( is_array($parameter) ) {
			$items = $parameter;
		} elseif ( is_string($parameter) && '' !== $parameter ) {
			$items = array_map('trim', explode(',', $parameter));
		}

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

	/**
	 * Resolve boolean handler values from parameters/config.
	 *
	 * @param  mixed $parameter Tool parameter override.
	 * @param  mixed $fallback  Handler config fallback.
	 * @return bool Resolved boolean.
	 */
	private function resolveBool( mixed $parameter, mixed $fallback ): bool {
		$value = null !== $parameter ? $parameter : $fallback;
		return filter_var($value, FILTER_VALIDATE_BOOLEAN);
	}
}
