<?php
/**
 * GitHub Update Handler
 *
 * Commits files back to GitHub repositories via the Contents API.
 * Used as the final step in automated documentation pipelines:
 * fetch source → AI translate → publish to WordPress → commit docs back to repo.
 *
 * @package DataMachineCode\Handlers\GitHub
 * @since 0.6.0
 */

namespace DataMachineCode\Handlers\GitHub;

use DataMachineCode\Abilities\GitHubAbilities;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachine\Core\Steps\Upsert\Handlers\UpsertHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitHubUpdate extends UpsertHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		self::registerHandler(
			'github_update',
			'upsert',
			self::class,
			'GitHub Update',
			'Commit files to GitHub repositories via the Contents API',
			false,
			null,
			GitHubUpdateSettings::class,
			array( self::class, 'registerTools' )
		);
	}

	/**
	 * Register the AI tool for the update step.
	 *
	 * The AI step in the pipeline calls this tool with the generated content.
	 * Parameters from the AI override handler config defaults.
	 *
	 * @param array  $tools          Registered tools.
	 * @param string $handler_slug   Current handler slug.
	 * @param array  $handler_config Handler configuration from settings.
	 * @return array Tools with GitHub update tool added.
	 */
	public static function registerTools( $tools, $handler_slug, $handler_config ) {
		if ( 'github_update' === $handler_slug ) {
			$tools['github_update'] = array(
				'class'       => self::class,
				'method'      => 'handle_tool_call',
				'handler'     => 'github_update',
				'description' => 'Commit a file to a GitHub repository. Creates the file if it does not exist, or updates it if it does.',
				'parameters'  => array(
					'type'       => 'object',
					'required'   => array( 'content' ),
					'properties' => array(
						'content'        => array(
							'type'        => 'string',
							'description' => 'The file content to commit (markdown, code, etc.).',
						),
						'file_path'      => array(
							'type'        => 'string',
							'description' => 'Path within the repository (e.g., docs/getting-started.md). Overrides handler config if provided.',
						),
						'commit_message' => array(
							'type'        => 'string',
							'description' => 'Commit message. Overrides handler config if provided.',
						),
					),
				),
			);
		}
		return $tools;
	}

	/**
	 * Execute the GitHub file update.
	 *
	 * Merges AI-provided parameters with handler config defaults,
	 * then delegates to GitHubAbilities::createOrUpdateFile().
	 *
	 * @param array $parameters    Tool parameters (job_id, engine, plus AI-provided fields).
	 * @param array $handler_config Handler configuration from settings.
	 * @return array Success/failure response.
	 */
	protected function executeUpsert( array $parameters, array $handler_config ): array {
		$job_id = $parameters['job_id'] ?? null;

		// Resolve repo: AI param > handler config > default repo setting.
		$repo = ! empty( $parameters['repo'] )
			? sanitize_text_field( $parameters['repo'] )
			: ( $handler_config['repo'] ?? '' );

		if ( empty( $repo ) ) {
			$repo = GitHubAbilities::getDefaultRepo();
		}

		if ( empty( $repo ) ) {
			return $this->errorResponse( 'GitHub Update: No repository configured and no default repo set.', array( 'job_id' => $job_id ) );
		}

		if ( ! GitHubAbilities::isConfigured() ) {
			return $this->errorResponse( 'GitHub Update: Personal Access Token not configured.', array( 'job_id' => $job_id ) );
		}

		// Resolve file path: AI param > handler config.
		$file_path = ! empty( $parameters['file_path'] )
			? sanitize_text_field( $parameters['file_path'] )
			: ( $handler_config['file_path'] ?? '' );

		if ( empty( $file_path ) ) {
			return $this->errorResponse( 'GitHub Update: file_path is required (provide via AI tool call or handler config).', array( 'job_id' => $job_id ) );
		}

		// Resolve commit message: AI param > handler config > default.
		$commit_message = ! empty( $parameters['commit_message'] )
			? sanitize_text_field( $parameters['commit_message'] )
			: ( $handler_config['commit_message'] ?? 'docs: update generated content' );

		// Resolve branch: AI param > handler config (empty = repo default).
		$branch = ! empty( $parameters['branch'] )
			? sanitize_text_field( $parameters['branch'] )
			: ( $handler_config['branch'] ?? '' );

		$content = $parameters['content'] ?? '';
		if ( '' === $content ) {
			return $this->errorResponse( 'GitHub Update: content is required.', array( 'job_id' => $job_id ) );
		}

		$input = array(
			'repo'           => $repo,
			'file_path'      => $file_path,
			'content'        => $content,
			'commit_message' => $commit_message,
		);

		if ( ! empty( $branch ) ) {
			$input['branch'] = $branch;
		}

		$result = GitHubAbilities::createOrUpdateFile( $input );

		if ( is_wp_error( $result ) ) {
			return $this->errorResponse(
				'GitHub Update: ' . $result->get_error_message(),
				array(
					'job_id'    => $job_id,
					'repo'      => $repo,
					'file_path' => $file_path,
				)
			);
		}

		return array(
			'success'   => true,
			'data'      => array(
				'repo'        => $repo,
				'file_path'   => $file_path,
				'commit_sha'  => $result['commit']['sha'] ?? '',
				'commit_url'  => $result['commit']['html_url'] ?? '',
				'file_url'    => $result['content']['html_url'] ?? '',
			),
			'tool_name' => 'github_update',
		);
	}

	/**
	 * Get the handler label.
	 *
	 * @return string
	 */
	public static function get_label(): string {
		return __( 'GitHub Update', 'data-machine-code' );
	}
}
