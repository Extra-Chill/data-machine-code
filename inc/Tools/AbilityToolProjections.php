<?php
/**
 * Ability-native model tool projections.
 *
 * @package DataMachineCode\Tools
 */

namespace DataMachineCode\Tools;

defined('ABSPATH') || exit;

class AbilityToolProjections {

	/**
	 * Register DMC abilities as model-facing Data Machine tools.
	 */
	public static function register(): bool {
		if ( ! function_exists('datamachine_register_ability_tool') ) {
			return false;
		}

		foreach ( self::projected_tools() as $tool_name => $declaration ) {
			datamachine_register_ability_tool($tool_name, $declaration);
		}

		return true;
	}

	/**
	 * Whether a tool name is registered through Data Machine's ability projection helper.
	 */
	public static function is_projected( string $tool_name ): bool {
		return function_exists('datamachine_register_ability_tool') && isset(self::projected_tools()[ $tool_name ]);
	}

	/**
	 * Model-facing tool names mapped to canonical ability slugs.
	 *
	 * Tool names intentionally preserve the existing DMC model contract while
	 * schema and execution now come from the registered WordPress abilities.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function projected_tools(): array {
		return array(
			'workspace_path'                       => self::workspace('datamachine-code/workspace-path'),
			'workspace_capabilities'               => self::workspace('datamachine-code/workspace-capabilities'),
			'workspace_list'                       => self::workspace('datamachine-code/workspace-list'),
			'workspace_show'                       => self::workspace('datamachine-code/workspace-show'),
			'workspace_ls'                         => self::workspace('datamachine-code/workspace-ls'),
			'workspace_read'                       => self::workspace('datamachine-code/workspace-read'),
			'workspace_grep'                       => self::workspace('datamachine-code/workspace-grep'),

			'workspace_write'                      => self::workspace_write('datamachine-code/workspace-write'),
			'workspace_edit'                       => self::workspace_write('datamachine-code/workspace-edit'),
			'workspace_apply_patch'                => self::workspace_write('datamachine-code/workspace-apply-patch'),
			'workspace_delete'                     => self::workspace_write('datamachine-code/workspace-delete'),
			'workspace_git_status'                 => self::workspace_write('datamachine-code/workspace-git-status'),
			'workspace_git_log'                    => self::workspace_write('datamachine-code/workspace-git-log'),
			'workspace_git_diff'                   => self::workspace_write('datamachine-code/workspace-git-diff'),
			'workspace_git_pull'                   => self::workspace_write('datamachine-code/workspace-git-pull'),
			'workspace_git_add'                    => self::workspace_write('datamachine-code/workspace-git-add'),
			'workspace_git_commit'                 => self::workspace_write('datamachine-code/workspace-git-commit'),
			'workspace_git_push'                   => self::workspace_write('datamachine-code/workspace-git-push'),
			'workspace_run_runner_command'          => self::workspace_write('datamachine-code/run-runner-workspace-command'),
			'workspace_git_rebase'                 => self::workspace_write('datamachine-code/workspace-git-rebase'),
			'workspace_git_reset'                  => self::workspace_write('datamachine-code/workspace-git-reset'),
			'workspace_worktree_add'               => self::workspace_write('datamachine-code/workspace-worktree-add'),
			'workspace_pr_status'                  => self::workspace_write('datamachine-code/workspace-pr-status'),
			'workspace_pr_rebase'                  => self::workspace_write('datamachine-code/workspace-pr-rebase'),

			'list_github_issues'                   => self::github('datamachine-code/list-github-issues'),
			'get_github_issue'                     => self::github('datamachine-code/get-github-issue'),
			'list_github_pulls'                    => self::github('datamachine-code/list-github-pulls'),
			'get_github_pull'                      => self::github('datamachine-code/get-github-pull'),
			'get_github_pull_files'                => self::github('datamachine-code/list-github-pull-files'),
			'get_github_check_runs'                => self::github('datamachine-code/get-github-check-runs'),
			'get_github_commit_statuses'           => self::github('datamachine-code/get-github-commit-statuses'),
			'get_github_actions_artifact'          => self::github('datamachine-code/get-github-actions-artifact'),
			'get_github_pull_review_context'       => self::github('datamachine-code/get-github-pull-review-context'),
			'github_repo_review_profile'           => self::github('datamachine-code/get-github-repo-review-profile'),
			'github_pr_documentation_impact'       => self::github('datamachine-code/get-github-pr-documentation-impact'),
			'list_github_tree'                     => self::github('datamachine-code/list-github-tree'),
			'get_github_file'                      => self::github('datamachine-code/get-github-file'),
			'list_github_repos'                    => self::github('datamachine-code/list-github-repos'),
		);
	}

	/**
	 * Build a workspace projection declaration.
	 *
	 * @return array<string,mixed>
	 */
	private static function workspace( string $ability ): array {
		return array(
			'ability' => $ability,
			'modes'   => array( 'chat', 'pipeline' ),
		);
	}

	/**
	 * Build a mutating workspace projection declaration.
	 *
	 * Write/git workspace tools require explicit opt-in (via `allow_only` or an
	 * allow-mode tool policy) before they are exposed to a model request, so a
	 * read-only inspection task never receives file-mutating tools by default.
	 *
	 * @return array<string,mixed>
	 */
	private static function workspace_write( string $ability ): array {
		return array(
			'ability'         => $ability,
			'modes'           => array( 'chat', 'pipeline' ),
			'requires_opt_in' => true,
		);
	}

	/**
	 * Build a GitHub projection declaration.
	 *
	 * @return array<string,mixed>
	 */
	private static function github( string $ability ): array {
		return array(
			'ability'      => $ability,
			'access_level' => 'editor',
			'modes'        => array( 'chat', 'pipeline' ),
		);
	}

}
