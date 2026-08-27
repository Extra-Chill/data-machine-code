<?php

declare(strict_types=1);

namespace DataMachine\Engine\AI\Tools {
	abstract class BaseTool {
		protected function registerTool( string $tool_id, callable $definition_callback, array $contexts, array $options = array() ): void {
			$GLOBALS['dmc_bespoke_tools'][ $tool_id ] = array(
				'contexts' => $contexts,
				'options'  => $options,
			);
		}

		protected function progressDefinition( array $definition ): array {
			return $definition;
		}
	}
}

namespace {
	require_once __DIR__ . '/support/bootstrap.php';

	$GLOBALS['dmc_projected_tools'] = array();
	$GLOBALS['dmc_bespoke_tools']   = array();

	function datamachine_register_ability_tool( string $tool_name, array $declaration ): void {
		$GLOBALS['dmc_projected_tools'][ $tool_name ] = $declaration;
	}

	$GLOBALS['dmc_projection_abilities'] = array();

	final class ProjectionTestAbility {
		/** @var array<int,array<string,mixed>> */
		public array $calls = array();

		public function execute( array $input ): array {
			$this->calls[] = $input;
			return array( 'success' => true );
		}
	}

	function wp_get_ability( string $slug ): ?ProjectionTestAbility {
		return $GLOBALS['dmc_projection_abilities'][ $slug ] ?? null;
	}

	function is_wp_error( mixed $value ): bool {
		return $value instanceof \WP_Error;
	}

	function projection_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException($message);
		}
	}

	require_once dirname(__DIR__) . '/inc/Tools/AbilityToolProjections.php';
	require_once dirname(__DIR__) . '/inc/Tools/GitHubTools.php';

	use DataMachineCode\Tools\AbilityToolProjections;
	use DataMachineCode\Tools\GitHubTools;

	projection_assert(AbilityToolProjections::register(), 'Ability projection registration should require the Data Machine helper.');

	$expected = array(
		'workspace_path'                    => 'datamachine-code/workspace-path',
		'workspace_worktree_plan'           => 'datamachine-code/workspace-worktree-plan',
		'workspace_worktree_add'            => 'datamachine-code/workspace-worktree-add',
		'workspace_publish_runner'          => 'datamachine-code/publish-runner-workspace',
		'list_github_issues'                => 'datamachine-code/list-github-issues',
		'remove_label_from_issue'           => 'datamachine-code/remove-github-label',
		'comment_github_pull_request'       => 'datamachine-code/comment-github-pull-request',
		'upsert_github_pull_review_comment' => 'datamachine-code/upsert-github-pull-review-comment',
		'merge_github_pull_request'         => 'datamachine-code/merge-github-pull-request',
		'cleanup_github_pull_request'       => 'datamachine-code/cleanup-github-pull-request',
		'create_or_update_github_file'      => 'datamachine-code/create-or-update-github-file',
	);

	foreach ( $expected as $tool_name => $ability_slug ) {
		$declaration = $GLOBALS['dmc_projected_tools'][ $tool_name ] ?? null;
		projection_assert(is_array($declaration), sprintf('Projected model tool is missing: %s', $tool_name));
		projection_assert($ability_slug === ( $declaration['ability'] ?? null ), sprintf('Projected model tool does not target its canonical ability: %s', $tool_name));

	}

	$github_tools = new GitHubTools();
	projection_assert(
		array( 'manage_github_issue', 'add_label_to_issue' ) === array_keys($GLOBALS['dmc_bespoke_tools']),
		'Only composed or input-adapting GitHub wrappers should remain bespoke.'
	);

	$comment_ability = new ProjectionTestAbility();
	$label_ability   = new ProjectionTestAbility();
	$GLOBALS['dmc_projection_abilities']['datamachine-code/comment-github-issue'] = $comment_ability;
	$GLOBALS['dmc_projection_abilities']['datamachine-code/add-github-labels']    = $label_ability;

	$github_tools->handleManageIssue(array(
		'repo'         => 'Extra-Chill/data-machine-code',
		'issue_number' => 547,
		'action'       => 'comment',
		'body'         => 'Projection contract',
	));
	projection_assert('Projection contract' === ( $comment_ability->calls[0]['body'] ?? null ), 'Composed issue management should route comments through the canonical comment ability.');

	$github_tools->handleAddLabelToIssue(array(
		'repo'         => 'Extra-Chill/data-machine-code',
		'issue_number' => 547,
		'label'        => 'status:review',
	));
	projection_assert(array( 'status:review' ) === ( $label_ability->calls[0]['labels'] ?? null ), 'The bespoke label wrapper should adapt one label to the canonical collection input.');

	echo "ability-tool-projections ok\n";
}
