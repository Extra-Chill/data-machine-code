<?php
/**
 * Plugin Name: Data Machine Code
 * Plugin URI: https://github.com/Extra-Chill/data-machine-code
 * Description: Developer tools extension for Data Machine. GitHub integration, workspace management, git operations, and code tools for WordPress AI agents.
 * Version: 0.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Requires Plugins: data-machine
 * Author: Chris Huber, extrachill
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: data-machine-code
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'DATAMACHINE_CODE_VERSION', '0.1.0' );
define( 'DATAMACHINE_CODE_PATH', plugin_dir_path( __FILE__ ) );
define( 'DATAMACHINE_CODE_URL', plugin_dir_url( __FILE__ ) );

// PSR-4 Autoloading.
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Bootstrap the plugin after all plugins are loaded.
 *
 * Data Machine core must be active — check at plugins_loaded time
 * (not at plugin load time, since load order is alphabetical and
 * data-machine-code loads before data-machine).
 */
function datamachine_code_bootstrap() {
	if ( ! class_exists( 'DataMachine\Abilities\PermissionHelper' ) ) {
		add_action( 'admin_notices', function () {
			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'Data Machine Code requires Data Machine core plugin to be installed and activated.', 'data-machine-code' ); ?></p>
			</div>
			<?php
		} );
		return;
	}

	// Load Abilities (they self-register).
	new \DataMachineCode\Abilities\GitHubAbilities();
	new \DataMachineCode\Abilities\WorkspaceAbilities();

	// Load Handlers (they self-register).
	new \DataMachineCode\Handlers\GitHub\GitHub();

	// Register GitHub issue creation ability via SystemAbilities hook.
	add_action( 'wp_abilities_api_init', 'datamachine_code_register_system_abilities' );
}
add_action( 'plugins_loaded', 'datamachine_code_bootstrap', 20 );

/**
 * Register system-level abilities (GitHub issue creation).
 */
function datamachine_code_register_system_abilities() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'datamachine/create-github-issue',
		array(
			'label'               => 'Create GitHub Issue',
			'description'         => 'Create a new GitHub issue in a repository.',
			'category'            => 'datamachine',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'title' ),
				'properties' => array(
					'title'  => array(
						'type'        => 'string',
						'description' => 'Issue title.',
					),
					'repo'   => array(
						'type'        => 'string',
						'description' => 'Repository in owner/repo format.',
					),
					'body'   => array(
						'type'        => 'string',
						'description' => 'Issue body (supports GitHub Markdown).',
					),
					'labels' => array(
						'type'        => 'array',
						'description' => 'Labels to apply.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'job_id'  => array( 'type' => 'integer' ),
					'error'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( array $input ) {
				if ( ! class_exists( 'DataMachine\Engine\AI\System\TaskScheduler' ) ) {
					return array(
						'success' => false,
						'error'   => 'TaskScheduler not available.',
					);
				}

				$scheduler = new \DataMachine\Engine\AI\System\TaskScheduler();
				$job_id    = $scheduler->schedule( 'github_create_issue', $input );

				if ( is_wp_error( $job_id ) ) {
					return array(
						'success' => false,
						'error'   => $job_id->get_error_message(),
					);
				}

				return $job_id;
			},
			'permission_callback' => function () {
				return \DataMachine\Abilities\PermissionHelper::can_manage();
			},
			'meta'                => array( 'show_in_rest' => false ),
		)
	);
}

/**
 * Register WP-CLI commands after core is loaded.
 */
function datamachine_code_register_cli_commands() {
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		return;
	}

	if ( ! class_exists( 'DataMachine\Cli\BaseCommand' ) ) {
		return;
	}

	\WP_CLI::add_command( 'datamachine-code github', \DataMachineCode\Cli\Commands\GitHubCommand::class );
	\WP_CLI::add_command( 'datamachine-code workspace', \DataMachineCode\Cli\Commands\WorkspaceCommand::class );
}
add_action( 'plugins_loaded', 'datamachine_code_register_cli_commands', 21 );

/**
 * Register chat tools.
 *
 * Chat tools extend BaseTool from core and self-register via filters.
 * Only load when Data Machine core's AI engine is available.
 */
function datamachine_code_load_chat_tools() {
	if ( ! class_exists( 'DataMachine\Engine\AI\Tools\BaseTool' ) ) {
		return;
	}

	new \DataMachineCode\Tools\GitHubIssueTool();
	new \DataMachineCode\Tools\GitHubTools();
	new \DataMachineCode\Tools\WorkspaceTools();
}
add_action( 'plugins_loaded', 'datamachine_code_load_chat_tools', 25 );

/**
 * Register system tasks.
 */
add_filter( 'datamachine_tasks', function ( array $tasks ): array {
	$tasks['github_create_issue'] = \DataMachineCode\Tasks\GitHubIssueTask::class;
	return $tasks;
} );

/**
 * Register code context memory file.
 *
 * Scaffolds contexts/code.md with GitHub, workspace, and git instructions.
 * The file is written once — after that, the agent owns it.
 */
add_filter( 'datamachine_default_context_files', function ( array $defaults ): array {
	$content = <<<'MD'
# Code Context

This context is active when you have developer tools available — GitHub integration, workspace file operations, and git workflows.

## GitHub Issue Creation

When using create_github_issue: include a clear title and detailed body with context, reproduction steps, and relevant log snippets. Use labels to categorize. Route to the most appropriate repo. Never create duplicates.
MD;

	// Append available repos dynamically.
	if ( class_exists( '\DataMachineCode\Abilities\GitHubAbilities' ) ) {
		$repos = \DataMachineCode\Abilities\GitHubAbilities::getRegisteredRepos();
		if ( ! empty( $repos ) ) {
			$content .= "\n\nAvailable repositories for issue creation:\n";
			foreach ( $repos as $entry ) {
				$content .= '- ' . $entry['owner'] . '/' . $entry['repo'] . ' — ' . $entry['label'] . "\n";
			}
		}
	}

	$defaults['code'] = $content;
	return $defaults;
} );

/**
 * Register GitHub repos for issue creation.
 */
add_filter( 'datamachine_github_issue_repos', function ( array $repos ): array {
	$default_repo = \DataMachineCode\Abilities\GitHubAbilities::getDefaultRepo();
	if ( ! empty( $default_repo ) && str_contains( $default_repo, '/' ) ) {
		$parts   = explode( '/', $default_repo, 2 );
		$repos[] = array(
			'owner' => $parts[0],
			'repo'  => $parts[1],
			'label' => 'Default (from settings)',
		);
	}
	return $repos;
} );
