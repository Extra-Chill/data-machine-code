<?php
/**
 * Plugin Name: Data Machine Code
 * Plugin URI: https://github.com/Extra-Chill/data-machine-code
 * Description: Bridge between WordPress and an external coding-agent runtime (Claude Code, OpenCode, kimaki, etc.). Owns AGENTS.md, the workspace area, and the GitHub / workspace / git abilities the runtime calls back into. Activation is the declarative "a coding agent lives here" signal.
 * Version: 0.37.0
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

define( 'DATAMACHINE_CODE_VERSION', '0.37.0' );
define( 'DATAMACHINE_CODE_PATH', plugin_dir_path( __FILE__ ) );
define( 'DATAMACHINE_CODE_URL', plugin_dir_url( __FILE__ ) );

// PSR-4 Autoloading.
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Install DMC-owned database tables.
 */
function datamachine_code_install_schema(): void {
	if ( class_exists( '\DataMachineCode\Storage\CleanupSchema' ) ) {
		\DataMachineCode\Storage\CleanupSchema::install();
	}
	if ( class_exists( '\DataMachineCode\Storage\WorktreeInventoryRepository' ) ) {
		\DataMachineCode\Storage\WorktreeInventoryRepository::install_schema();
	}
}
register_activation_hook( __FILE__, 'datamachine_code_install_schema' );

/**
 * Keep schema current for already-active installs after deploy/update.
 */
function datamachine_code_maybe_upgrade_schema(): void {
	if ( function_exists( 'wp_installing' ) && wp_installing() ) {
		return;
	}

	$worktrees_installed = function_exists( 'get_option' ) ? (string) get_option( 'datamachine_code_worktrees_schema_version', '' ) : '';
	$cleanup_installed   = function_exists( 'get_option' ) ? (string) get_option( 'datamachine_code_cleanup_schema_version', '' ) : '';
	$cleanup_version     = '20260504-cleanup-runs';

	if ( '1' !== $worktrees_installed || $cleanup_version !== $cleanup_installed ) {
		datamachine_code_install_schema();
		if ( function_exists( 'update_option' ) ) {
			update_option( 'datamachine_code_cleanup_schema_version', $cleanup_version, false );
		}
	}
}
add_action( 'plugins_loaded', 'datamachine_code_maybe_upgrade_schema', 5 );

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
	new \DataMachineCode\Abilities\WorkspaceDiffAbilities();
	new \DataMachineCode\Abilities\GitSyncAbilities();
	new \DataMachineCode\Abilities\CodeTaskAbilities();
	new \DataMachineCode\Abilities\WordPressRuntimeAbilities();
	( new \DataMachineCode\Bundle\WorkspacePreloadArtifact() )->register();

	// Load Handlers (they self-register).
	new \DataMachineCode\Handlers\GitHub\GitHub();
	new \DataMachineCode\Handlers\GitHub\GitHubIssuePublish();
	new \DataMachineCode\Handlers\GitHub\GitHubPullRequestPublish();
	new \DataMachineCode\Handlers\GitHub\GitHubUpsert();

	// Register ability categories on the correct hook (must happen during wp_abilities_api_categories_init).
	add_action( 'wp_abilities_api_categories_init', 'datamachine_code_register_ability_categories' );
}
add_action( 'plugins_loaded', 'datamachine_code_bootstrap', 20 );

/**
 * Register DMC-owned webhook verifier modes with Data Machine core.
 */
add_filter( 'datamachine_webhook_verifier_modes', function ( array $modes ): array {
	$modes['github_pull_request'] = \DataMachineCode\Support\GitHubWebhookValidator::class;
	$modes['github_workflow_run'] = \DataMachineCode\Support\GitHubWebhookValidator::class;
	return $modes;
} );

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
add_filter( 'datamachine_update_settings', function ( array $filtered, array $input ): array {
	$settings     = $filtered['settings'] ?? array();
	$handled_keys = $filtered['handled_keys'] ?? array();

	if ( isset( $input['github_pat'] ) ) {
		$settings['github_pat'] = sanitize_text_field( (string) $input['github_pat'] );
		$handled_keys[]         = 'github_pat';
	}

	if ( isset( $input['github_auth_mode'] ) ) {
		$mode = strtolower( sanitize_text_field( (string) $input['github_auth_mode'] ) );
		if ( in_array( $mode, array( 'pat', 'app' ), true ) ) {
			$settings['github_auth_mode'] = $mode;
		}
		$handled_keys[] = 'github_auth_mode';
	}

	if ( isset( $input['github_app_id'] ) ) {
		$settings['github_app_id'] = sanitize_text_field( (string) $input['github_app_id'] );
		$handled_keys[]            = 'github_app_id';
	}

	if ( isset( $input['github_app_installation_id'] ) ) {
		$settings['github_app_installation_id'] = sanitize_text_field( (string) $input['github_app_installation_id'] );
		$handled_keys[]                         = 'github_app_installation_id';
	}

	if ( isset( $input['github_app_private_key'] ) ) {
		// Private keys are multiline PEM blobs — sanitize_text_field would strip newlines.
		// Trim only; the resolver normalizes literal `\n` escapes back to real newlines.
		$settings['github_app_private_key'] = trim( (string) $input['github_app_private_key'] );
		$handled_keys[]                     = 'github_app_private_key';
	}

	if ( isset( $input['github_default_repo'] ) ) {
		$settings['github_default_repo'] = sanitize_text_field( (string) $input['github_default_repo'] );
		$handled_keys[]                  = 'github_default_repo';
	}

	if ( isset( $input['github_credential_profiles'] ) && is_array( $input['github_credential_profiles'] ) ) {
		$settings['github_credential_profiles'] = \DataMachineCode\Support\GitHubProfileSanitizer::sanitize( $input['github_credential_profiles'] );
		$handled_keys[]                         = 'github_credential_profiles';
	}

	if ( isset( $input['github_default_profile_id'] ) ) {
		$settings['github_default_profile_id'] = sanitize_text_field( (string) $input['github_default_profile_id'] );
		$handled_keys[]                        = 'github_default_profile_id';
	}

	return array(
		'settings'     => $settings,
		'handled_keys' => $handled_keys,
	);
}, 10, 2 );



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

	wp_register_ability_category(
		'datamachine-code-code-task',
		array(
			'label'       => __( 'Code Tasks', 'data-machine-code' ),
			'description' => __( 'Create isolated coding tasks from structured source evidence packets.', 'data-machine-code' ),
		)
	);

	wp_register_ability_category(
		'datamachine-code-runtime',
		array(
			'label'       => __( 'WordPress Runtime', 'data-machine-code' ),
			'description' => __( 'Read-only inspection of the live WordPress runtime and allowlisted source roots.', 'data-machine-code' ),
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
	\WP_CLI::add_command( 'datamachine-code code-task', \DataMachineCode\Cli\Commands\CodeTaskCommand::class );
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
	new \DataMachineCode\Tools\GitHubPullRequestTool();
	new \DataMachineCode\Tools\GitHubTools();
	new \DataMachineCode\Tools\WorkspaceTools();
	new \DataMachineCode\Tools\WorkspaceDiffTools();
	new \DataMachineCode\Tools\WordPressRuntimeTools();
}
add_action( 'plugins_loaded', 'datamachine_code_load_chat_tools', 25 );

/**
 * Register system tasks.
 */
add_filter( 'datamachine_tasks', function ( array $tasks ): array {
	$tasks['github_create_issue']              = \DataMachineCode\Tasks\GitHubIssueTask::class;
	$tasks['worktree_cleanup_chunk']           = \DataMachineCode\Tasks\WorktreeCleanupChunkTask::class;
	$tasks['worktree_cleanup']                 = \DataMachineCode\Tasks\WorktreeCleanupTask::class;
	$tasks['workspace_disk_emergency_cleanup'] = \DataMachineCode\Tasks\WorkspaceDiskEmergencyCleanupTask::class;
	$tasks['workspace_retention_cleanup']      = \DataMachineCode\Tasks\WorkspaceRetentionCleanupTask::class;
	$tasks['workspace_hygiene_report']         = \DataMachineCode\Tasks\WorkspaceHygieneReportTask::class;
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
		'description'     => 'Auto-generated from registered sections. Regenerate via: wp datamachine memory compose AGENTS.md',
	) );

	if ( ! class_exists( '\DataMachine\Engine\AI\SectionRegistry' ) ) {
		return;
	}

	$wp = datamachine_code_resolve_wp_cli_cmd();

	// Auto-generated marker — emitted as the first lines of the regenerated file.
	// Lives as a priority-0 section per the precedent set by data-machine#1127:
	// "If a future composable file genuinely needs a [top-of-file insert], the
	// right mechanism is a priority-0 section via SectionRegistry — not a special
	// metadata slot on MemoryFileRegistry."
	//
	// Renders as HTML comments so the convention copy reads as a regular Markdown
	// document (no styled heading, no negative directive in the agent's system
	// prompt), while still signalling to any tool or agent that opens AGENTS.md
	// from disk that edits belong in the section registrars, not this file.
	\DataMachine\Engine\AI\SectionRegistry::register( 'AGENTS.md', 'auto-generated-marker', 0, function () use ( $wp ) {
		return <<<MD
<!-- regenerated by: {$wp} datamachine memory compose AGENTS.md -->
<!-- edit registered sections in their owning plugins, not this file -->
MD;
	}, array(
		'label'       => 'Auto-generated marker',
		'description' => 'HTML-comment header signalling that AGENTS.md is composed from registered sections.',
	) );

	// Data Machine — memory, automation, code, system.
	\DataMachine\Engine\AI\SectionRegistry::register( 'AGENTS.md', 'datamachine', 10, function () use ( $wp ) {
		$workspace_path = datamachine_code_resolve_workspace_path_for_agents_md();

		return <<<MD
## Data Machine

Data Machine is your operating layer — memory, automation, and orchestration via WP-CLI.

Discover the full command surface: `{$wp} datamachine --help`. The groups below are the major command families — always run `--help` on any subcommand to see its options.

**Memory & Agents:** Persistent files across sessions plus agent identity management.
- Memory paths / read / write / search / compose: `{$wp} datamachine memory paths|read|write|search|compose`
- Agent management: `{$wp} datamachine agent list|create|access|token|installed|install|diff` — identities, permissions, bearer tokens, portable bundles
- Update MEMORY.md when you learn something persistent — read it first, append new info.

**Automation:** Self-scheduling workflows that run without human intervention.
- Flows: `{$wp} datamachine flow create|run|list` — scheduled or on-demand tasks
- Pipelines: `{$wp} datamachine pipeline create|list` — multi-step processing chains
- Jobs / pending actions: `{$wp} datamachine jobs list|retry|summary`, `{$wp} datamachine pending-actions` — monitor queued work and approval gates
- Drain due work: `{$wp} datamachine drain` — run due actions until empty or budgeted
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

**Code (data-machine-code):** All code changes happen in Data Machine Code worktrees under `{$workspace_path}`. DMC owns workspace lifecycle, evidence capture, GitHub workflow glue, and GitSync; file CRUD inside a worktree uses whatever tool is fastest.
- Workspace root: `{$workspace_path}`
- **Workspace lifecycle:** `{$wp} datamachine-code workspace adopt|clone|list|show|path|hygiene|remove|worktree` — keeps the on-disk registry consistent and enforces the `<repo>@<slug>` handle convention.
- **Worktrees:** `{$wp} datamachine-code workspace worktree add|list|remove|prune|cleanup|cleanup-artifacts|reconcile-metadata|refresh-context|finalize|mark-cleanup-eligible` — create isolated branches, refresh agent context, attach lifecycle metadata, and clean up safely.
- **GitHub:** `{$wp} datamachine-code github issues|pulls|repos|status|view|close|review-flow|comment` — list/read GitHub state, manage issues, install review flows, and comment on reviews.
- **Git sync:** `{$wp} datamachine-code gitsync bind|list|status|pull|submit|push|policy|unbind` — bind site-owned directories to remotes; `submit` opens or updates the PR path, while `push` writes directly to the configured branch.
- **Editing inside a worktree:** any tool. Local agents on the same disk should use native file I/O and raw `git`; routing edits through workspace abilities is ceremony, not safety.
- **Workflow:** `workspace clone <repo>` → `worktree add <repo> <branch>` → edit files in the worktree with any tool → commit → push → PR.
- **Why worktrees:** parallel-session isolation on disk. Multiple agents cook features in the same repo without stepping on each other.
- **Primary is read-only.** Never edit `<workspace>/<repo>` (no `@slug`). Mutating ops on bare `<repo>` handles via the CLI require `--allow-primary-mutation`. The primary tracks the deployed branch — operate on a worktree.
- **Rule:** Never modify files under `wp-content/plugins/` or `wp-content/themes/` directly. Those paths are **read-only reference**. All code changes go through the workspace so they are tracked in git and reviewed via pull requests.

**System:** `{$wp} datamachine system health|prompts|run` — site health, prompt inspection, diagnostic runs.

Use `--help` on any command to discover options and subcommands.
MD;
	}, array(
		'label'       => 'Data Machine',
		'description' => 'Memory, automation, workspace, and system operations.',
	) );

	// Workspace Inventory — live snapshot of cloned repos + active worktrees.
	// Sits between datamachine (10) and abilities (20) so the agent reads
	// "what's in my workspace right now" immediately after the WP-CLI surface
	// description. The section is regenerated whenever
	// `datamachine_code_workspace_changed` fires (debounced by
	// ComposableFileInvalidation's 60-second transient).
	\DataMachine\Engine\AI\SectionRegistry::register( 'AGENTS.md', 'workspace-inventory', 15, function () use ( $wp ) {
		return datamachine_code_render_workspace_inventory_section( $wp );
	}, array(
		'label'       => 'Workspace Inventory',
		'description' => 'Live snapshot of cloned repos + active worktrees in the workspace.',
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

	// Homeboy — conditional, only on hosts where the `homeboy` CLI is
	// callable from PATH. Mirrors the house style of other AGENTS.md
	// sections: lead with a one-line definition, group with bold
	// sub-labels, end with a discoverability hint. `homeboy --help`
	// is the canonical verb list; this section just surfaces the
	// verbs agents reach for and the repo-level rules.
	if ( \DataMachineCode\Homeboy::is_available() ) {
		\DataMachine\Engine\AI\SectionRegistry::register( 'AGENTS.md', 'homeboy', 35, function () {
			return <<<'MD'
## Homeboy

`homeboy` is a Rust CLI on this host. Every verb runs the same locally as in CI.

**Quality:** `homeboy audit | lint | test | review | refactor`; use `homeboy review --changed-since --report=pr-comment` for PR-style review loops, and `--baseline` / `--ratchet` when a command supports observation baselines. For unregistered checkouts pass both `--path <repo>` and `--extension <id>` (e.g. `nodejs`) so resolution skips the missing-component error.

**Git:** prefer `homeboy changes | status` and `homeboy git status|commit|push|pull|tag|rebase|cherry-pick|pr|issue` — structured output, component/worktree awareness, safer write verbs. Use `--path <checkout>` when operating outside a registered component or overriding component resolution. One-off reads (`git diff`, `git show`, `git blame`) stay on raw `git`.

**Perf + envs:** `homeboy bench` for pinned iterations/runs/concurrency, baselines, rig/profile comparisons; `homeboy trace` and `homeboy observe` for behavioral evidence; `homeboy rig install|update|up|check|down|repair|status` for reproducible multi-component dev environments.

**Reports + observations:** `homeboy triage` surfaces issue/PR/check attention; `homeboy report` renders structured Homeboy output artifacts; `homeboy runs list|show|artifacts|findings|export|import|reconcile` inspects persisted observation runs before rerunning expensive bench/trace/rig checks.

**Stacks:** `homeboy stack list|show|apply|rebase|status|sync|push|diff|inspect` for combined-fixes branches built from upstream PRs.

**Deps:** `homeboy deps` for dependency update workflows when a component declares them.

**Repo rules** (when `homeboy.json` is present):
- **NEVER edit `CHANGELOG.md`** — generated from conventional commits at release time.
- **NEVER hand-bump version strings** — `feat:`/`fix:`/`BREAKING CHANGE` drive semver; Homeboy rewrites version targets in `homeboy.json`.

Run `homeboy --help` for the full verb list. Operator verbs (`release`, `deploy`, `fleet`, `ssh`) only on explicit ask.
MD;
		}, array(
			'label'       => 'Homeboy',
			'description' => 'Homeboy CLI — verbs agents reach for + repo rules.',
		) );
	}

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

/**
 * Resolve the live workspace path for agent-facing instructions.
 *
 * AGENTS.md can be recomposed long after setup. Resolve from Workspace at
 * compose time so custom DATAMACHINE_WORKSPACE_PATH installs do not regress to
 * generic/default workspace guidance after invalidation.
 *
 * @return string Resolved workspace path or a diagnostic fallback.
 */
function datamachine_code_resolve_workspace_path_for_agents_md(): string {
	if ( class_exists( '\DataMachineCode\Workspace\Workspace' ) ) {
		$workspace_path = ( new \DataMachineCode\Workspace\Workspace() )->get_path();

		if ( '' !== $workspace_path ) {
			return $workspace_path;
		}
	}

	return 'unavailable; run datamachine-code workspace path to diagnose';
}

/**
 * Hook the workspace-lifecycle action into the composable AGENTS.md
 * regenerator so the inventory section refreshes whenever a clone, adopt,
 * remove, or worktree add/remove lands. Regeneration is debounced by
 * ComposableFileInvalidation's 60-second transient.
 *
 * @since 0.31.0
 */
add_filter( 'datamachine_composable_invalidation_hooks', function ( array $hooks ): array {
	$hooks[] = 'datamachine_code_workspace_changed';
	return $hooks;
} );

/**
 * Render the workspace-inventory section for AGENTS.md.
 *
 * Pulls live state from `Workspace::list_repos()` — the same source the
 * `wp datamachine-code workspace list` CLI uses — so the agent sees an
 * accurate snapshot of cloned repos and active worktrees without probing.
 *
 * Render shape (compact, default):
 *
 *   - **<repo>** (`<branch>`) — N worktrees · <remote>
 *
 * Render shape (full, opt-in via filter):
 *
 *   - **<repo>** (`<branch>`) — <remote>
 *     - `<branch-slug>` (`<branch>`)
 *
 * Returns an empty string when the Workspace class is missing or no git
 * repos are cloned, which causes the composer to skip the section entirely
 * (no stub heading).
 *
 * @since 0.31.0
 *
 * @param string $wp WP-CLI command prefix for the current environment.
 * @return string Markdown for the section, or '' to skip.
 */
function datamachine_code_render_workspace_inventory_section( string $wp ): string {
	if ( ! class_exists( '\DataMachineCode\Workspace\Workspace' ) ) {
		return '';
	}

	$workspace = new \DataMachineCode\Workspace\Workspace();
	$listing   = $workspace->list_repos();

	if ( is_wp_error( $listing ) || empty( $listing['repos'] ) ) {
		return '';
	}

	// Group entries by primary repo. Skip non-git directories (`.locks`,
	// `_bench-evidence`, etc) — `list_repos()` exposes the `git` flag for
	// exactly this distinction.
	$by_repo = array();
	foreach ( $listing['repos'] as $entry ) {
		if ( empty( $entry['git'] ) ) {
			continue;
		}

		$repo = $entry['repo'] ?? $entry['name'] ?? '';
		if ( '' === $repo ) {
			continue;
		}

		if ( ! isset( $by_repo[ $repo ] ) ) {
			$by_repo[ $repo ] = array(
				'primary'   => null,
				'worktrees' => array(),
			);
		}

		if ( ! empty( $entry['is_worktree'] ) ) {
			$by_repo[ $repo ]['worktrees'][] = $entry;
		} else {
			$by_repo[ $repo ]['primary'] = $entry;
		}
	}

	if ( empty( $by_repo ) ) {
		return '';
	}

	ksort( $by_repo, SORT_NATURAL | SORT_FLAG_CASE );

	$workspace_path = $listing['path'] ?? $workspace->get_path();

	/**
	 * Filter the workspace-inventory render mode.
	 *
	 * - `compact` (default): one bullet per repo with branch + worktree count + remote.
	 * - `full`: also list each worktree's slug + branch beneath its primary.
	 *
	 * @since 0.31.0
	 *
	 * @param string $mode One of `compact`|`full`.
	 */
	$mode = apply_filters( 'datamachine_code_workspace_inventory_mode', 'compact' );
	if ( ! in_array( $mode, array( 'compact', 'full' ), true ) ) {
		$mode = 'compact';
	}

	$lines = array();
	foreach ( $by_repo as $repo => $bucket ) {
		$primary    = $bucket['primary'];
		$worktrees  = $bucket['worktrees'];
		$wt_count   = count( $worktrees );
		$branch     = $primary['branch'] ?? null;
		$remote     = $primary['remote'] ?? null;
		$branch_str = ( null !== $branch && '' !== $branch ) ? sprintf( ' (`%s`)', $branch ) : '';

		if ( 'compact' === $mode ) {
			$suffix_parts   = array();
			$suffix_parts[] = sprintf( '%d %s', $wt_count, 1 === $wt_count ? 'worktree' : 'worktrees' );
			if ( null !== $remote && '' !== $remote ) {
				$suffix_parts[] = $remote;
			}
			$lines[] = sprintf( '- **%s**%s — %s', $repo, $branch_str, implode( ' · ', $suffix_parts ) );
			continue;
		}

		// full mode.
		$header = sprintf( '- **%s**%s', $repo, $branch_str );
		if ( null !== $remote && '' !== $remote ) {
			$header .= ' — ' . $remote;
		}
		$lines[] = $header;

		usort( $worktrees, function ( $a, $b ) {
			return strnatcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
		} );
		foreach ( $worktrees as $wt ) {
			$slug      = $wt['branch_slug'] ?? '';
			$wt_branch = $wt['branch'] ?? null;
			$wt_label  = '' !== $slug ? sprintf( '`%s`', $slug ) : sprintf( '`%s`', $wt['name'] ?? '?' );
			if ( null !== $wt_branch && '' !== $wt_branch && $wt_branch !== $slug ) {
				$wt_label .= sprintf( ' (`%s`)', $wt_branch );
			}
			$lines[] = '  - ' . $wt_label;
		}
	}

	$body = implode( "\n", $lines );

	return <<<MD
## Workspace Inventory

Live snapshot of cloned repos in `{$workspace_path}`. Run `{$wp} datamachine-code workspace list` to drill into worktrees.

{$body}
MD;
}
