<?php
/**
 * GitHub Upsert Handler Settings
 *
 * Defines settings fields for the GitHub upsert handler UI.
 * Configures repository, branch, file path, and commit message
 * for committing generated content back to GitHub repositories.
 *
 * @package DataMachineCode\Handlers\GitHub
 * @since 0.6.0
 */

namespace DataMachineCode\Handlers\GitHub;

use DataMachine\Core\Steps\Settings\SettingsHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitHubUpsertSettings extends SettingsHandler {

	/**
	 * Get settings fields for GitHub upsert handler.
	 *
	 * @return array
	 */
	public static function get_fields(): array {
		return array(
			'repo'           => array(
				'type'        => 'text',
				'label'       => __( 'Repository', 'data-machine-code' ),
				'description' => __( 'GitHub repository in owner/repo format (e.g., Extra-Chill/data-machine). Falls back to default repo in settings.', 'data-machine-code' ),
				'required'    => false,
			),
			'branch'         => array(
				'type'        => 'text',
				'label'       => __( 'Branch', 'data-machine-code' ),
				'description' => __( 'Target branch for the commit. Defaults to the repository\'s default branch.', 'data-machine-code' ),
				'required'    => false,
			),
			'file_path'      => array(
				'type'        => 'text',
				'label'       => __( 'File Path', 'data-machine-code' ),
				'description' => __( 'Path within the repository (e.g., docs/getting-started.md). Can be overridden by the AI step.', 'data-machine-code' ),
				'required'    => false,
			),
			'commit_message' => array(
				'type'        => 'text',
				'label'       => __( 'Commit Message', 'data-machine-code' ),
				'description' => __( 'Default commit message template. Can be overridden by the AI step.', 'data-machine-code' ),
				'required'    => false,
				'default'     => 'docs: update generated content',
			),
		);
	}
}
