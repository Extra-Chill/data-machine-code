<?php
/**
 * Plugin Name: Data Machine Code
 * Plugin URI: https://github.com/Extra-Chill/data-machine-code
 * Description: Developer tools extension for Data Machine. GitHub integration, workspace management, git operations, and code tools for WordPress AI agents.
 * Version: 0.10.0
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

define( 'DATAMACHINE_CODE_VERSION', '0.10.0' );
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
	new \DataMachineCode\Abilities\GitSyncAbilities();

	// Load Handlers (they self-register).
	new \DataMachineCode\Handlers\GitHub\GitHub();
	new \DataMachineCode\Handlers\GitHub\GitHubUpsert();

	// Register ability categories on the correct hook (must happen during wp_abilities_api_categories_init).
	add_action( 'wp_abilities_api_categories_init', 'datamachine_code_register_ability_categories' );

	// Register GitHub issue creation ability via SystemAbilities hook.
	add_action( 'wp_abilities_api_init', 'datamachine_code_register_system_abilities' );
}
add_action( 'plugins_loaded', 'datamachine_code_bootstrap', 20 );

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
			'label'       => __( 'Code Workspace', 'data-machine-code' ),
			'description' => __( 'Git workspace management — clone, read, write, edit, and git operations.', 'data-machine-code' ),
		)
	);

	wp_register_ability_category(
		'datamachine-code-github',
		array(
			'label'       => __( 'GitHub', 'data-machine-code' ),
			'description' => __( 'GitHub issue, pull request, and repository operations.', 'data-machine-code' ),
		)
	);

	wp_register_ability_category(
		'datamachine-code-gitsync',
		array(
			'label'       => __( 'GitSync', 'data-machine-code' ),
			'description' => __( 'Bind site-owned directories to remote git repositories with pull/status/list semantics.', 'data-machine-code' ),
		)
	);
}

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
			'category'            => 'datamachine-code-github',
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
					return new \WP_Error( 'scheduler_unavailable', 'TaskScheduler not available.', array( 'status' => 500 ) );
				}

				$scheduler = new \DataMachine\Engine\AI\System\TaskScheduler();
				$job_id    = $scheduler->schedule( 'github_create_issue', $input );

				if ( is_wp_error( $job_id ) ) {
					return $job_id;
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
	\WP_CLI::add_command( 'datamachine-code gitsync', \DataMachineCode\Cli\Commands\GitSyncCommand::class );
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
	$tasks['worktree_cleanup']    = \DataMachineCode\Tasks\WorktreeCleanupTask::class;
	return $tasks;
} );

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
add_filter( 'datamachine_recurring_schedules', function ( array $schedules ): array {
	$schedules['worktree_cleanup'] = array(
		'task_type'        => 'worktree_cleanup',
		'interval'         => 'daily',
		'enabled_setting'  => \DataMachineCode\Tasks\WorktreeCleanupTask::SETTING_KEY,
		'default_enabled'  => false,
		'label'            => 'Daily — cleans up merged worktrees',
		'task_params'      => array( 'source' => 'recurring_schedule' ),
	);
	return $schedules;
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

/*
|--------------------------------------------------------------------------
| AGENTS.md — composable file registration
|--------------------------------------------------------------------------
| data-machine-code owns AGENTS.md as a coding-agent concern. The file is
| registered as composable in the MemoryFileRegistry, and sections are
| contributed by DM core, this plugin, and other extensions (mattic, etc.)
| via SectionRegistry.
|
| Convention copy at ABSPATH/AGENTS.md ensures coding agents (Claude Code,
| OpenCode, etc.) discover it at the expected location.
|
| Registered at plugins_loaded priority 22 (after DM core bootstrap at 20)
| to ensure MemoryFileRegistry and SectionRegistry are available.
*/

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( '\DataMachine\Engine\AI\MemoryFileRegistry' ) ) {
		return;
	}

	\DataMachine\Engine\AI\MemoryFileRegistry::register( 'AGENTS.md', 5, array(
		'layer'           => \DataMachine\Engine\AI\MemoryFileRegistry::LAYER_SHARED,
		'protected'       => true,
		'composable'      => true,
		'convention_path' => 'AGENTS.md',
		'label'           => 'Agent Instructions',
		'description'     => 'Auto-generated from registered sections. Regenerate via: wp datamachine agent compose AGENTS.md',
	) );

	if ( ! class_exists( '\DataMachine\Engine\AI\SectionRegistry' ) ) {
		return;
	}

	$wp = datamachine_code_resolve_wp_cli_cmd();

	// Data Machine — memory, automation, code, system.
	\DataMachine\Engine\AI\SectionRegistry::register( 'AGENTS.md', 'datamachine', 10, function () use ( $wp ) {
		return <<<MD
## Data Machine

Data Machine is your operating layer — memory, automation, and orchestration via WP-CLI.

Discover the full command surface: `{$wp} datamachine --help`. The groups below are the major command families — always run `--help` on any subcommand to see its options.

**Memory & Agents:** Persistent files across sessions plus agent identity management.
- Memory paths / read / write / search: `{$wp} datamachine agent paths|read|write|search`
- Agent management: `{$wp} datamachine agents list|create|access|tokens` — identities, permissions, bearer tokens
- Update MEMORY.md when you learn something persistent — read it first, append new info.

**Automation:** Self-scheduling workflows that run without human intervention.
- Flows: `{$wp} datamachine flow create|run|list` — scheduled or on-demand tasks
- Pipelines: `{$wp} datamachine pipeline create|list` — multi-step processing chains
- Jobs: `{$wp} datamachine jobs list|retry|summary` — monitor queued work
- Discover available step types: `{$wp} datamachine step-types list`
- Discover available handlers: `{$wp} datamachine handlers list`
- Processed items (dedupe): `{$wp} datamachine processed-items`
- Retention policies: `{$wp} datamachine retention`

**Communication:** Chat sessions and email I/O.
- Chat: `{$wp} datamachine chat` — multi-turn agent conversations with tool calling
- Email: `{$wp} datamachine email` — IMAP read / SMTP reply (wired to the site's mail stack)

**Content ops:** Post-level and site-wide content tooling.
- Posts / taxonomy / blocks: `{$wp} datamachine post|taxonomy|block`
- SEO helpers: `{$wp} datamachine alt-text|meta-description|image|link|indexnow`
- Analytics & logs: `{$wp} datamachine analytics|logs`
- Settings & auth: `{$wp} datamachine settings|auth`
- External sites & handler tests: `{$wp} datamachine external|test`

**Code (data-machine-code):** All code changes go through the managed workspace and GitHub — never edit site files directly.
- Workspace: `{$wp} datamachine-code workspace clone|worktree|read|write|edit|ls|list|show|remove|git` — clone repos, create per-branch worktrees, edit files, commit, and push
- GitHub: `{$wp} datamachine-code github issues|pulls|repos|comment` — create PRs, manage issues, comment on reviews
- Git sync: `{$wp} datamachine-code gitsync` — sync workspace repos with remotes
- **Workflow:** clone → `worktree add <repo> <branch>` → edit → commit → push → PR. Operate on the `<repo>@<branch-slug>` handle (e.g. `data-machine@fix-foo-bar`); never branch-switch the primary checkout.
- **Why worktrees:** every parallel session gets its own checkout on disk. Multiple agents can cook features in the same repo without stepping on each other.
- **Primary is read-only by default:** mutating ops on bare `<repo>` handles require `--allow-primary-mutation`. The primary tracks the deployed branch — leave it alone unless you really mean it.
- **Rule:** Never modify files under `wp-content/plugins/` or `wp-content/themes/` directly. Those paths are for **reading source** only. All code changes must go through the workspace so they are tracked in git and reviewed via pull requests.

**System:** `{$wp} datamachine system health|prompts|run` — site health, prompt inspection, diagnostic runs.

Use `--help` on any command to discover options and subcommands.
MD;
	}, array(
		'label'       => 'Data Machine',
		'description' => 'Memory, automation, workspace, and system operations.',
	) );

	// Abilities — WordPress Abilities API discovery.
	\DataMachine\Engine\AI\SectionRegistry::register( 'AGENTS.md', 'abilities', 20, function () use ( $wp ) {
		return <<<MD
## Abilities

WordPress Abilities are the universal tool surface. Plugins register abilities that are automatically available via WP-CLI, REST API, MCP, and chat. Discover what's available: `{$wp} help abilities`

The tool surface grows as plugins are installed — always discover before assuming what's available.
MD;
	}, array(
		'label'       => 'Abilities',
		'description' => 'WordPress Abilities API discovery.',
	) );

	// WordPress Source — read-only reference material.
	\DataMachine\Engine\AI\SectionRegistry::register( 'AGENTS.md', 'wordpress-source', 30, function () {
		return <<<'MD'
## WordPress Source (Read-Only Reference)

These directories are **read-only reference material** — grep and read them to understand code, but never edit them directly. All code changes go through the workspace (see Code above).

- `wp-content/plugins/` — plugin source (read-only)
- `wp-content/themes/` — theme source (read-only)
- `wp-includes/` — WordPress core (read-only)
MD;
	}, array(
		'label'       => 'WordPress Source',
		'description' => 'Pointers to WordPress source directories.',
	) );

	// Multisite — conditional, only on multisite installs.
	if ( is_multisite() ) {
		\DataMachine\Engine\AI\SectionRegistry::register( 'AGENTS.md', 'multisite', 40, function () use ( $wp ) {
			return <<<MD
## Multisite

This is a WordPress multisite. Use `--url` to target specific sites:
```
{$wp} --url=site.example.com <command>
```
Without `--url`, commands default to the main site.
MD;
		}, array(
			'label'       => 'Multisite',
			'description' => 'Multisite-specific WP-CLI guidance.',
		) );
	}
}, 22 );

/**
 * Resolve the WP-CLI command prefix for the current environment.
 *
 * Builds a default prefix (e.g. "wp --allow-root --path=/var/www/example.com")
 * then passes it through the `datamachine_wp_cli_cmd` filter so that
 * environment-specific plugins can override it (e.g. Studio → "studio wp").
 *
 * @since 0.3.0
 * @since 0.4.0 Added `datamachine_wp_cli_cmd` filter. Removed hardcoded Studio detection.
 *
 * @return string WP-CLI command prefix.
 */
function datamachine_code_resolve_wp_cli_cmd(): string {
	$parts = array( 'wp' );

	// Server environments need --allow-root when running as root.
	if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
		$parts[] = '--allow-root';
	}

	// Add --path when ABSPATH isn't the default WordPress location.
	$abspath = rtrim( ABSPATH, '/' );
	if ( '/var/www/html' !== $abspath ) {
		$parts[] = '--path=' . $abspath;
	}

	$default = implode( ' ', $parts );

	/**
	 * Filter the WP-CLI command prefix used in AGENTS.md and other agent-facing output.
	 *
	 * Environment-specific plugins should hook this to provide the correct
	 * command. For example, a Studio environment plugin would return "studio wp".
	 *
	 * @since 0.4.0
	 *
	 * @param string $wp_cli_cmd The default WP-CLI command prefix.
	 */
	return apply_filters( 'datamachine_wp_cli_cmd', $default );
}
