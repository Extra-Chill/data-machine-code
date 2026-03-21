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
			'repo'        => array(
				'type'        => 'text',
				'label'       => __( 'Repository', 'data-machine-code' ),
				'description' => __( 'GitHub repository in owner/repo format (e.g., Extra-Chill/data-machine). Falls back to default repo in settings.', 'data-machine-code' ),
				'required'    => false,
			),
			'data_source' => array(
				'type'        => 'select',
				'label'       => __( 'Data Source', 'data-machine-code' ),
				'description' => __( 'What to fetch from the repository.', 'data-machine-code' ),
				'required'    => true,
				'default'     => 'issues',
				'options'     => array(
					'issues' => __( 'Issues', 'data-machine-code' ),
					'pulls'  => __( 'Pull Requests', 'data-machine-code' ),
				),
			),
			'state'       => array(
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
			'labels'      => array(
				'type'        => 'text',
				'label'       => __( 'Label Filter', 'data-machine-code' ),
				'description' => __( 'Comma-separated label names to filter by (issues only).', 'data-machine-code' ),
				'required'    => false,
			),
		);

		return array_merge( $fields, parent::get_common_fields() );
	}
}
