<?php
/**
 * GitHub Fetch Handler Settings
 *
 * Defines settings fields for the GitHub fetch handler UI.
 *
 * @package DataMachineCode\Handlers\GitHub
 * @since 0.1.0
 */

namespace DataMachineCode\Handlers\GitHub;

use DataMachine\Core\Steps\Fetch\Handlers\FetchHandlerSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GitHubSettings extends FetchHandlerSettings {

	/**
	 * Get settings fields for GitHub fetch handler.
	 *
	 * @return array
	 */
	public static function get_fields(): array {
		$fields = array(
			'repo'          => array(
				'type'        => 'text',
				'label'       => __( 'Repository', 'data-machine-code' ),
				'description' => __( 'GitHub repository in owner/repo format (e.g., Extra-Chill/data-machine). Falls back to default repo in settings.', 'data-machine-code' ),
				'required'    => false,
			),
			'data_source'   => array(
				'type'        => 'select',
				'label'       => __( 'Data Source', 'data-machine-code' ),
				'description' => __( 'What to fetch from the repository.', 'data-machine-code' ),
				'required'    => true,
				'default'     => 'issues',
				'options'     => array(
					'issues'              => __( 'Issues', 'data-machine-code' ),
					'pulls'               => __( 'Pull Requests', 'data-machine-code' ),
					'pull_review_context' => __( 'PR Review Context', 'data-machine-code' ),
					'files'               => __( 'Source Files', 'data-machine-code' ),
				),
			),
			'pull_number'   => array(
				'type'        => 'number',
				'label'       => __( 'Pull Request Number', 'data-machine-code' ),
				'description' => __( 'Pull request number to prepare for review context.', 'data-machine-code' ),
				'required'    => false,
			),
			'head_sha'      => array(
				'type'        => 'text',
				'label'       => __( 'Expected Head SHA', 'data-machine-code' ),
				'description' => __( 'Optional guard SHA. If set, the fetch fails when the live PR head differs.', 'data-machine-code' ),
				'required'    => false,
			),
			'max_patch_chars' => array(
				'type'        => 'number',
				'label'       => __( 'Max Patch Characters', 'data-machine-code' ),
				'description' => __( 'Maximum cumulative patch characters included in PR review context. Set 0 for unlimited.', 'data-machine-code' ),
				'required'    => false,
				'default'     => 200000,
			),
			'state'         => array(
				'type'        => 'select',
				'label'       => __( 'State Filter', 'data-machine-code' ),
				'description' => __( 'Filter by issue/PR state.', 'data-machine-code' ),
				'required'    => false,
				'default'     => 'open',
				'options'     => array(
					'open'   => __( 'Open', 'data-machine-code' ),
					'closed' => __( 'Closed', 'data-machine-code' ),
					'all'    => __( 'All', 'data-machine-code' ),
				),
			),
			'labels'        => array(
				'type'        => 'text',
				'label'       => __( 'Label Filter', 'data-machine-code' ),
				'description' => __( 'Comma-separated label names to filter by (issues only).', 'data-machine-code' ),
				'required'    => false,
			),
			'branch'        => array(
				'type'        => 'text',
				'label'       => __( 'Branch', 'data-machine-code' ),
				'description' => __( 'Git branch or ref to read from (files data source). Defaults to the repository default branch.', 'data-machine-code' ),
				'required'    => false,
			),
			'file_path'     => array(
				'type'        => 'text',
				'label'       => __( 'File Path', 'data-machine-code' ),
				'description' => __( 'Directory or file path prefix to filter (files data source). E.g., "inc/" or "src/Commands/". Leave empty for all files.', 'data-machine-code' ),
				'required'    => false,
			),
			'file_pattern'  => array(
				'type'        => 'text',
				'label'       => __( 'File Pattern', 'data-machine-code' ),
				'description' => __( 'Glob pattern to filter filenames (files data source). E.g., "*.php" or "*.php,*.json". Leave empty for all file types.', 'data-machine-code' ),
				'required'    => false,
			),
			'max_file_size' => array(
				'type'        => 'number',
				'label'       => __( 'Max File Size (bytes)', 'data-machine-code' ),
				'description' => __( 'Skip files larger than this size in bytes (files data source). Default: 512000 (500 KB).', 'data-machine-code' ),
				'required'    => false,
				'default'     => 512000,
			),
		);

		return array_merge( $fields, parent::get_common_fields() );
	}
}
