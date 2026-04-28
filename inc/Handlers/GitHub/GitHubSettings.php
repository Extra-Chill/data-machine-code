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
			'repo'                    => array(
				'type'        => 'text',
				'label'       => __( 'Repository', 'data-machine-code' ),
				'description' => __( 'GitHub repository in owner/repo format (e.g., Extra-Chill/data-machine). Falls back to default repo in settings.', 'data-machine-code' ),
				'required'    => false,
			),
			'data_source'             => array(
				'type'        => 'select',
				'label'       => __( 'Data Source', 'data-machine-code' ),
				'description' => __( 'What to fetch from the repository.', 'data-machine-code' ),
				'required'    => true,
				'default'     => 'issues',
				'options'     => array(
					'issues'                         => __( 'Issues', 'data-machine-code' ),
					'pulls'                          => __( 'Pull Requests', 'data-machine-code' ),
					'pull_review_context'            => __( 'PR Review Context', 'data-machine-code' ),
					'github_pr_documentation_impact' => __( 'PR Documentation Impact', 'data-machine-code' ),
					'check_runs'                     => __( 'Check Runs', 'data-machine-code' ),
					'commit_statuses'                => __( 'Commit Statuses', 'data-machine-code' ),
					'homeboy_ci_results'             => __( 'Homeboy CI Results', 'data-machine-code' ),
					'files'                          => __( 'Source Files', 'data-machine-code' ),
				),
			),
			'sha'                     => array(
				'type'        => 'text',
				'label'       => __( 'Commit SHA or Ref', 'data-machine-code' ),
				'description' => __( 'Commit SHA, branch, or tag ref for check runs and commit statuses. Falls back to Expected Head SHA.', 'data-machine-code' ),
				'required'    => false,
			),
			'pull_number'             => array(
				'type'        => 'number',
				'label'       => __( 'Pull Request Number', 'data-machine-code' ),
				'description' => __( 'Pull request number to prepare for review context.', 'data-machine-code' ),
				'required'    => false,
			),
			'head_sha'                => array(
				'type'        => 'text',
				'label'       => __( 'Expected Head SHA', 'data-machine-code' ),
				'description' => __( 'Optional guard SHA. If set, the fetch fails when the live PR head differs.', 'data-machine-code' ),
				'required'    => false,
			),
			'max_patch_chars'         => array(
				'type'        => 'number',
				'label'       => __( 'Max Patch Characters', 'data-machine-code' ),
				'description' => __( 'Maximum cumulative patch characters included in PR review context. Set 0 for unlimited.', 'data-machine-code' ),
				'required'    => false,
				'default'     => 200000,
			),
			'include_file_contents'   => array(
				'type'        => 'checkbox',
				'label'       => __( 'Include Changed File Contents', 'data-machine-code' ),
				'description' => __( 'Opt in to bounded full-file contents for changed files in PR review context.', 'data-machine-code' ),
				'required'    => false,
				'default'     => false,
			),
			'include_base_contents'   => array(
				'type'        => 'checkbox',
				'label'       => __( 'Include Base File Contents', 'data-machine-code' ),
				'description' => __( 'When changed file contents are enabled, also include bounded base-branch contents for comparison.', 'data-machine-code' ),
				'required'    => false,
				'default'     => false,
			),
			'context_paths'           => array(
				'type'        => 'textarea',
				'label'       => __( 'Additional Context Paths', 'data-machine-code' ),
				'description' => __( 'Optional comma- or newline-separated repository paths to include from the PR head ref.', 'data-machine-code' ),
				'required'    => false,
			),
			'max_file_content_chars'  => array(
				'type'        => 'number',
				'label'       => __( 'Max File Content Characters', 'data-machine-code' ),
				'description' => __( 'Maximum characters included per expanded file content block.', 'data-machine-code' ),
				'required'    => false,
				'default'     => 20000,
			),
			'max_context_files'       => array(
				'type'        => 'number',
				'label'       => __( 'Max Context Files', 'data-machine-code' ),
				'description' => __( 'Maximum number of files included in expanded PR review context.', 'data-machine-code' ),
				'required'    => false,
				'default'     => 10,
			),
			'max_total_context_chars' => array(
				'type'        => 'number',
				'label'       => __( 'Max Total Context Characters', 'data-machine-code' ),
				'description' => __( 'Maximum cumulative characters included across all expanded PR review context files.', 'data-machine-code' ),
				'required'    => false,
				'default'     => 100000,
			),
			'include_checks'          => array(
				'type'        => 'checkbox',
				'label'       => __( 'Include Check Runs', 'data-machine-code' ),
				'description' => __( 'Include GitHub check runs in PR review context.', 'data-machine-code' ),
				'required'    => false,
				'default'     => false,
			),
			'include_statuses'        => array(
				'type'        => 'checkbox',
				'label'       => __( 'Include Commit Statuses', 'data-machine-code' ),
				'description' => __( 'Include legacy commit statuses in PR review context.', 'data-machine-code' ),
				'required'    => false,
				'default'     => false,
			),
			'max_check_runs'          => array(
				'type'        => 'number',
				'label'       => __( 'Max Check Runs', 'data-machine-code' ),
				'description' => __( 'Maximum check runs included for check-run fetches and PR review context.', 'data-machine-code' ),
				'required'    => false,
				'default'     => 30,
			),
			'include_check_output'    => array(
				'type'        => 'checkbox',
				'label'       => __( 'Include Check Output', 'data-machine-code' ),
				'description' => __( 'Include bounded check output summaries and text.', 'data-machine-code' ),
				'required'    => false,
				'default'     => false,
			),
			'include_homeboy_ci'      => array(
				'type'        => 'checkbox',
				'label'       => __( 'Include Homeboy CI Results', 'data-machine-code' ),
				'description' => __( 'Include parsed homeboy-ci-results artifact data in PR review context.', 'data-machine-code' ),
				'required'    => false,
				'default'     => false,
			),
			'artifact_name'           => array(
				'type'        => 'text',
				'label'       => __( 'Artifact Name', 'data-machine-code' ),
				'description' => __( 'GitHub Actions artifact name for Homeboy CI results. Default: homeboy-ci-results.', 'data-machine-code' ),
				'required'    => false,
				'default'     => 'homeboy-ci-results',
			),
			'base_ref'                => array(
				'type'        => 'text',
				'label'       => __( 'Base Ref', 'data-machine-code' ),
				'description' => __( 'Optional base ref used for documentation impact tree lookups. Defaults to the PR base ref.', 'data-machine-code' ),
				'required'    => false,
			),
			'docs_paths'              => array(
				'type'        => 'textarea',
				'label'       => __( 'Documentation Path Allow-List', 'data-machine-code' ),
				'description' => __( 'Optional comma- or newline-separated docs paths to consider when suggesting likely stale docs.', 'data-machine-code' ),
				'required'    => false,
			),
			'max_artifact_bytes'      => array(
				'type'        => 'number',
				'label'       => __( 'Max Artifact Bytes', 'data-machine-code' ),
				'description' => __( 'Maximum Homeboy CI artifact ZIP bytes to download.', 'data-machine-code' ),
				'required'    => false,
				'default'     => 2000000,
			),
			'state'                   => array(
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
			'labels'                  => array(
				'type'        => 'text',
				'label'       => __( 'Label Filter', 'data-machine-code' ),
				'description' => __( 'Comma-separated label names to filter by (issues only).', 'data-machine-code' ),
				'required'    => false,
			),
			'branch'                  => array(
				'type'        => 'text',
				'label'       => __( 'Branch', 'data-machine-code' ),
				'description' => __( 'Git branch or ref to read from (files data source). Defaults to the repository default branch.', 'data-machine-code' ),
				'required'    => false,
			),
			'file_path'               => array(
				'type'        => 'text',
				'label'       => __( 'File Path', 'data-machine-code' ),
				'description' => __( 'Directory or file path prefix to filter (files data source). E.g., "inc/" or "src/Commands/". Leave empty for all files.', 'data-machine-code' ),
				'required'    => false,
			),
			'file_pattern'            => array(
				'type'        => 'text',
				'label'       => __( 'File Pattern', 'data-machine-code' ),
				'description' => __( 'Glob pattern to filter filenames (files data source). E.g., "*.php" or "*.php,*.json". Leave empty for all file types.', 'data-machine-code' ),
				'required'    => false,
			),
			'max_file_size'           => array(
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
