<?php
/**
 * GitHub Pull Request Publish Handler Settings.
 *
 * @package DataMachineCode\Handlers\GitHub
 */

namespace DataMachineCode\Handlers\GitHub;

use DataMachine\Core\Steps\Settings\SettingsHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitHubPullRequestPublishSettings extends SettingsHandler {

	/**
	 * Get settings fields for GitHub PR publishing.
	 *
	 * @return array
	 */
	public static function get_fields(): array {
		return array(
			'repo'                  => array(
				'type'        => 'text',
				'label'       => __( 'Repository', 'data-machine-code' ),
				'description' => __( 'GitHub repository in owner/repo format. Falls back to the default repo in settings.', 'data-machine-code' ),
				'required'    => false,
			),
			'base'                  => array(
				'type'        => 'text',
				'label'       => __( 'Base Branch', 'data-machine-code' ),
				'description' => __( 'Default base branch for pull requests. Leave blank for repository default.', 'data-machine-code' ),
				'required'    => false,
			),
			'draft'                 => array(
				'type'        => 'checkbox',
				'label'       => __( 'Open as Draft', 'data-machine-code' ),
				'description' => __( 'Open pull requests as drafts by default.', 'data-machine-code' ),
				'required'    => false,
				'default'     => false,
			),
			'maintainer_can_modify' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Allow Maintainer Edits', 'data-machine-code' ),
				'description' => __( 'Allow maintainers to modify the pull request branch.', 'data-machine-code' ),
				'required'    => false,
				'default'     => true,
			),
		);
	}
}
