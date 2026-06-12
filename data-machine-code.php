<?php
/**
 * Plugin Name: Data Machine Code
 * Plugin URI: https://github.com/Extra-Chill/data-machine-code
 * Description: Bridge between WordPress and an external coding-agent runtime. Owns AGENTS.md, the workspace area, and the GitHub / workspace / git abilities the runtime calls back into. Activation is the declarative "a coding agent lives here" signal.
 * Version: 0.47.107
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Author: Chris Huber, extrachill
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: data-machine-code
 */

if ( ! defined('WPINC') ) {
	die;
}

define( 'DATAMACHINE_CODE_VERSION', '0.47.107' );
define( 'DATAMACHINE_CODE_PATH', plugin_dir_path( __FILE__ ) );
define( 'DATAMACHINE_CODE_URL', plugin_dir_url( __FILE__ ) );

// PSR-4 Autoloading.
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Install DMC-owned database tables.
 */
function datamachine_code_install_schema(): void {
	if ( class_exists('\DataMachineCode\Storage\CleanupSchema') ) {
		\DataMachineCode\Storage\CleanupSchema::install();
	}
	if ( class_exists('\DataMachineCode\Storage\WorktreeInventoryRepository') ) {
		\DataMachineCode\Storage\WorktreeInventoryRepository::install_schema();
	}
}
register_activation_hook(__FILE__, 'datamachine_code_install_schema');

/**
 * Keep schema current for already-active installs after deploy/update.
 */
function datamachine_code_maybe_upgrade_schema(): void {
	if ( function_exists('wp_installing') && wp_installing() ) {
		return;
	}

	$worktrees_installed = function_exists('get_option') ? (string) get_option('datamachine_code_worktrees_schema_version', '') : '';
	$cleanup_installed   = function_exists('get_option') ? (string) get_option('datamachine_code_cleanup_schema_version', '') : '';
	$cleanup_version     = '20260504-cleanup-runs';

	if ( '1' !== $worktrees_installed || $cleanup_version !== $cleanup_installed ) {
		datamachine_code_install_schema();
		if ( function_exists('update_option') ) {
			update_option('datamachine_code_cleanup_schema_version', $cleanup_version, false);
		}
	}
}
add_action('plugins_loaded', 'datamachine_code_maybe_upgrade_schema', 5);

/**
 * Whether Data Machine-specific integration surfaces are available.
 */
function datamachine_code_has_datamachine_integration(): bool {
	return class_exists('DataMachine\Abilities\PermissionHelper');
}

/**
 * Register DMC-owned bundle artifact hooks.
 *
 * Bundle artifact type discovery can run before the rest of DMC's Data Machine
 * integrations are available. Keep these hooks registered independently so
 * imported agent bundles can materialize DMC-owned artifacts during install.
 */
function datamachine_code_register_bundle_artifacts(): void {
	static $registered = false;

	if ( $registered ) {
		return;
	}

	$registered = true;
	( new \DataMachineCode\Bundle\WorkspacePreloadArtifact() )->register();
}
datamachine_code_register_bundle_artifacts();

/**
 * Register optional Data Machine integrations.
 */
function datamachine_code_register_datamachine_integrations(): void {
	static $registered = false;

	if ( $registered || ! datamachine_code_has_datamachine_integration() ) {
		return;
	}

	$registered = true;

	// Project active workspace identity into Data Machine's engine_data snapshot.
	\DataMachineCode\Runtime\ActiveWorkspaceProjector::register();

	if ( trait_exists('DataMachine\Core\Steps\HandlerRegistrationTrait') ) {
		new \DataMachineCode\Handlers\GitHub\GitHub();
		new \DataMachineCode\Handlers\GitHub\GitHubIssuePublish();
		new \DataMachineCode\Handlers\GitHub\GitHubPullRequestPublish();
		new \DataMachineCode\Handlers\GitHub\GitHubUpsert();
	}
}

/**
 * Bootstrap the plugin after all plugins are loaded.
 *
 * Core workspace/GitHub/Agents API surfaces do not require Data Machine.
 * Data Machine-specific handlers and memory/runtime hooks register through
 * datamachine_code_register_datamachine_integrations() when available.
 */
function datamachine_code_bootstrap() {
	static $bootstrapped = false;

	if ( $bootstrapped ) {
		return;
	}

	$bootstrapped = true;

	// Load Abilities (they self-register).
	new \DataMachineCode\Abilities\GitHubAbilities();
	new \DataMachineCode\Abilities\WorkspaceAbilities();
	new \DataMachineCode\Abilities\WorkspaceDiffAbilities();
	new \DataMachineCode\Abilities\GitSyncAbilities();
	new \DataMachineCode\Abilities\CodeTaskAbilities();
	new \DataMachineCode\Abilities\WordPressRuntimeAbilities();
	\DataMachineCode\SourceInventory\WorkspaceSourceInventory::register();
	\DataMachineCode\AgentsApi\WorkspaceExecutorAdapter::register();
	datamachine_code_register_datamachine_integrations();

	// Register ability categories on the correct hook (must happen during wp_abilities_api_categories_init).
	add_action('wp_abilities_api_categories_init', 'datamachine_code_register_ability_categories');
}
add_action('plugins_loaded', 'datamachine_code_bootstrap', 20);
add_action(
	'activated_plugin',
	static function (): void {
		datamachine_code_bootstrap();
	},
	20
);
if ( function_exists('did_action') && did_action('plugins_loaded') ) {
	datamachine_code_bootstrap();
}

/**
 * Register DMC-owned webhook verifier modes with Data Machine core.
 */
add_filter(
	'datamachine_webhook_verifier_modes', function ( array $modes ): array {
		$modes['github_pull_request'] = \DataMachineCode\Support\GitHubWebhookValidator::class;
		$modes['github_workflow_run'] = \DataMachineCode\Support\GitHubWebhookValidator::class;
		return $modes;
	}
);

/**
 * Register DMC-owned settings keys with the `datamachine/update-settings`
 * ability so `wp datamachine settings set <key>` flows through the official
 * ability path instead of requiring direct `wp option patch insert` writes.
 *
 * Handles both legacy single-credential keys (back-compat with installs
 * that haven't migrated to profiles yet) and the new profile structure.
 *
 * Migration path:
 *   - Existing installs continue to use github_pat / github_auth_mode /
 *     github_app_*. The credential resolver synthesizes those into an
 *     implicit "default" profile on read.
 *   - New installs and operator-driven migrations populate
 *     github_credential_profiles + github_default_profile_id directly.
 *   - Both shapes coexist; the profile list wins when present.
 *
 * @since 0.30.0
 */
add_filter(
	'datamachine_update_settings', function ( array $filtered, array $input ): array {
		$settings     = $filtered['settings'] ?? array();
		$handled_keys = $filtered['handled_keys'] ?? array();

		if ( isset($input['github_pat']) ) {
			$settings['github_pat'] = sanitize_text_field( (string) $input['github_pat']);
			$handled_keys[]         = 'github_pat';
		}

		if ( isset($input['github_auth_mode']) ) {
			$mode = strtolower(sanitize_text_field( (string) $input['github_auth_mode']));
			if ( in_array($mode, array( 'pat', 'app' ), true) ) {
				$settings['github_auth_mode'] = $mode;
			}
			$handled_keys[] = 'github_auth_mode';
		}

		if ( isset($input['github_app_id']) ) {
			$settings['github_app_id'] = sanitize_text_field( (string) $input['github_app_id']);
			$handled_keys[]            = 'github_app_id';
		}

		if ( isset($input['github_app_installation_id']) ) {
			$settings['github_app_installation_id'] = sanitize_text_field( (string) $input['github_app_installation_id']);
			$handled_keys[]                         = 'github_app_installation_id';
		}

		if ( isset($input['github_app_private_key']) ) {
			// Private keys are multiline PEM blobs — sanitize_text_field would strip newlines.
			// Trim only; the resolver normalizes literal `\n` escapes back to real newlines.
			$settings['github_app_private_key'] = trim( (string) $input['github_app_private_key']);
			$handled_keys[]                     = 'github_app_private_key';
		}

		if ( isset($input['github_default_repo']) ) {
			$settings['github_default_repo'] = sanitize_text_field( (string) $input['github_default_repo']);
			$handled_keys[]                  = 'github_default_repo';
		}

		if ( isset($input['github_credential_profiles']) && is_array($input['github_credential_profiles']) ) {
			$settings['github_credential_profiles'] = \DataMachineCode\Support\GitHubProfileSanitizer::sanitize($input['github_credential_profiles']);
			$handled_keys[]                         = 'github_credential_profiles';
		}

		if ( isset($input['github_default_profile_id']) ) {
			$settings['github_default_profile_id'] = sanitize_text_field( (string) $input['github_default_profile_id']);
			$handled_keys[]                        = 'github_default_profile_id';
		}

		return array(
			'settings'     => $settings,
			'handled_keys' => $handled_keys,
		);
	}, 10, 2
);



/**
 * Register ability categories for data-machine-code.
 *
 * Must be called on `wp_abilities_api_categories_init` — WordPress core
 * enforces that categories are only registered during this action.
 */
function datamachine_code_register_ability_categories() {
	wp_register_ability_category(
		'datamachine-code-workspace',
		array(
			'label'       => __('Code Workspace', 'data-machine-code'),
			'description' => __('Git workspace management — clone, read, write, edit, and git operations.', 'data-machine-code'),
		)
	);

	wp_register_ability_category(
		'datamachine-code-github',
		array(
			'label'       => __('GitHub', 'data-machine-code'),
			'description' => __('GitHub issue, pull request, and repository operations.', 'data-machine-code'),
		)
	);

	wp_register_ability_category(
		'datamachine-code-gitsync',
		array(
			'label'       => __('GitSync', 'data-machine-code'),
			'description' => __('Bind site-owned directories to remote git repositories with pull/status/list semantics.', 'data-machine-code'),
		)
	);

	wp_register_ability_category(
		'datamachine-code-code-task',
		array(
			'label'       => __('Code Tasks', 'data-machine-code'),
			'description' => __('Create isolated coding tasks from structured source evidence packets.', 'data-machine-code'),
		)
	);

	wp_register_ability_category(
		'datamachine-code-runtime',
		array(
			'label'       => __('WordPress Runtime', 'data-machine-code'),
			'description' => __('Read-only inspection of the live WordPress runtime and allowlisted source roots.', 'data-machine-code'),
		)
	);
}

/**
 * Register WP-CLI commands after core is loaded.
 */
function datamachine_code_register_cli_commands() {
	if ( ! class_exists('\WP_CLI') ) {
		return;
	}

	if ( ! class_exists('DataMachine\Cli\BaseCommand') ) {
		return;
	}

	\WP_CLI::add_command('datamachine-code github', \DataMachineCode\Cli\Commands\GitHubCommand::class);
	\WP_CLI::add_command('datamachine-code workspace', \DataMachineCode\Cli\Commands\WorkspaceCommand::class);
	\WP_CLI::add_command('datamachine-code gitsync', \DataMachineCode\Cli\Commands\GitSyncCommand::class);
	\WP_CLI::add_command('datamachine-code code-task', \DataMachineCode\Cli\Commands\CodeTaskCommand::class);
}
add_action('plugins_loaded', 'datamachine_code_register_cli_commands', 21);

/**
 * Register chat tools.
 *
 * Chat tools extend BaseTool from core and self-register via filters.
 * Only load when Data Machine core's AI engine is available.
 */
function datamachine_code_load_chat_tools() {
	if ( ! class_exists('DataMachine\Engine\AI\Tools\BaseTool') ) {
		return;
	}

	\DataMachineCode\Tools\AbilityToolProjections::register();

	new \DataMachineCode\Tools\GitHubIssueTool();
	new \DataMachineCode\Tools\GitHubPullRequestTool();
	new \DataMachineCode\Tools\GitHubTools();
	new \DataMachineCode\Tools\WorkspaceTools();
	new \DataMachineCode\Tools\WorkspaceDiffTools();
	new \DataMachineCode\Tools\WordPressRuntimeTools();
}
add_action('plugins_loaded', 'datamachine_code_load_chat_tools', 25);

/**
 * Register system tasks.
 */
add_filter(
	'datamachine_tasks', function ( array $tasks ): array {
		$tasks['github_create_issue']              = \DataMachineCode\Tasks\GitHubIssueTask::class;
		$tasks['github_update_issue_labels']       = \DataMachineCode\Tasks\GitHubIssueLabelsTask::class;
		$tasks['worktree_cleanup_chunk']           = \DataMachineCode\Tasks\WorktreeCleanupChunkTask::class;
		$tasks['worktree_cleanup']                 = \DataMachineCode\Tasks\WorktreeCleanupTask::class;
		$tasks['workspace_disk_emergency_cleanup'] = \DataMachineCode\Tasks\WorkspaceDiskEmergencyCleanupTask::class;
		$tasks['workspace_retention_cleanup']      = \DataMachineCode\Tasks\WorkspaceRetentionCleanupTask::class;
		$tasks['workspace_hygiene_report']         = \DataMachineCode\Tasks\WorkspaceHygieneReportTask::class;
		return $tasks;
	}
);

/**
 * Register recurring schedules for DM-code system tasks.
 *
 * DM core's RecurringScheduleRegistry iterates this filter on
 * action_scheduler_init and wires one AS hook per schedule that dispatches
 * into TaskScheduler::schedule(). No bespoke scheduling glue needed —
 * declare the cadence + setting_key here and everything else (scheduling,
 * idempotent reschedule, stagger, persistence verify, unschedule-on-disable)
 * is provided by the shared RecurringScheduler primitive.
 *
 * @see https://github.com/Extra-Chill/data-machine/pull/1117
 */
add_filter(
	'datamachine_recurring_schedules', function ( array $schedules ): array {
		$schedules['worktree_cleanup']                 = array(
			'task_type'       => 'worktree_cleanup',
			'interval'        => 'daily',
			'enabled_setting' => \DataMachineCode\Tasks\WorktreeCleanupTask::SETTING_KEY,
			'default_enabled' => false,
			'label'           => 'Daily — cleans up merged worktrees',
			'task_params'     => array( 'source' => 'recurring_schedule' ),
		);
		$schedules['workspace_retention_cleanup']      = array(
			'task_type'       => 'workspace_retention_cleanup',
			'interval'        => 'daily',
			'enabled_setting' => \DataMachineCode\Tasks\WorkspaceRetentionCleanupTask::SETTING_KEY,
			'default_enabled' => true,
			'label'           => 'Daily — applies workspace retention cleanup',
			'task_params'     => array(
				'source'              => 'recurring_schedule',
				'worktree_older_than' => '14d',
				'skip_github'         => true,
				'artifact_cleanup'    => true,
			),
		);
		$schedules['workspace_disk_emergency_cleanup'] = array(
			'task_type'       => 'workspace_disk_emergency_cleanup',
			'interval'        => 'hourly',
			'enabled_setting' => \DataMachineCode\Tasks\WorkspaceDiskEmergencyCleanupTask::SETTING_KEY,
			'default_enabled' => true,
			'label'           => 'Hourly — triggers artifact-first emergency cleanup under disk pressure',
			'task_params'     => array(
				'source'              => 'recurring_schedule',
				'artifact_chunk_size' => 10,
			),
		);
		$schedules['workspace_hygiene_report']         = array(
			'task_type'       => 'workspace_hygiene_report',
			'interval'        => 'weekly',
			'enabled_setting' => \DataMachineCode\Tasks\WorkspaceHygieneReportTask::SETTING_KEY,
			'default_enabled' => false,
			'label'           => 'Weekly — reports workspace disk hygiene',
			'task_params'     => array(
				'source'          => 'recurring_schedule',
				'include_cleanup' => true,
				'include_sizes'   => true,
				'size_limit'      => 200,
			),
		);
		return $schedules;
	}
);

/**
 * Register code context memory file.
 *
 * Scaffolds contexts/code.md with GitHub, workspace, and git instructions.
 * The file is written once — after that, the agent owns it.
 */
add_filter(
	'datamachine_default_context_files', function ( array $defaults ): array {
		$content = <<<'MD'
# Code Context

This context is active when you have developer tools available — GitHub integration, workspace file operations, and git workflows.

## GitHub Issue Creation

When using create_github_issue: include a clear title and detailed body with context, reproduction steps, and relevant log snippets. Use labels to categorize. Route to the most appropriate repo. Never create duplicates.
MD;

		// Append available repos dynamically.
		if ( class_exists('\DataMachineCode\Abilities\GitHubAbilities') ) {
			$repos = \DataMachineCode\Abilities\GitHubAbilities::getRegisteredRepos();
			if ( ! empty($repos) ) {
				$content .= "\n\nAvailable repositories for issue creation:\n";
				foreach ( $repos as $entry ) {
					$content .= '- ' . $entry['owner'] . '/' . $entry['repo'] . ' — ' . $entry['label'] . "\n";
				}
			}
		}

		$defaults['code'] = $content;
		return $defaults;
	}
);

/**
 * Register GitHub repos for issue creation.
 */
add_filter(
	'datamachine_github_issue_repos', function ( array $repos ): array {
		$default_repo = \DataMachineCode\Abilities\GitHubAbilities::getDefaultRepo();
		if ( ! empty($default_repo) && str_contains($default_repo, '/') ) {
			$parts   = explode('/', $default_repo, 2);
			$repos[] = array(
				'owner' => $parts[0],
				'repo'  => $parts[1],
				'label' => 'Default (from settings)',
			);
		}
		return $repos;
	}
);

/*
|--------------------------------------------------------------------------
| AGENTS.md — composable file registration
|--------------------------------------------------------------------------
|
| The entrypoint only wires AGENTS.md registration. Section composition lives
| in DataMachineCode\Runtime\AgentsMdSections.
*/
add_action('plugins_loaded', array( \DataMachineCode\Runtime\AgentsMdSections::class, 'register' ), 22);
add_filter('datamachine_composable_invalidation_hooks', array( \DataMachineCode\Runtime\AgentsMdSections::class, 'register_invalidation_hooks' ));
