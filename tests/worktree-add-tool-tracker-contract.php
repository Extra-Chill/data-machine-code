<?php

declare(strict_types=1);

	namespace DataMachine\Engine\AI\Tools {
	class BaseTool {
		private function progressDefinition( array $definition ): array {
			return $definition;
		}

		private function executeAbility( string $ability, string $tool, array $input, array $required ): array {
			return $input;
		}
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/fixtures/');
	}

	final class Worktree_Add_Tool_Tracker_Ability {
		public array $input = array();

		public function execute( array $input ): array {
			$this->input = $input;
			return array( 'success' => true );
		}
	}

	$worktree_add_tool_tracker_ability = new Worktree_Add_Tool_Tracker_Ability();
	function wp_get_ability( string $name ): Worktree_Add_Tool_Tracker_Ability {
		global $worktree_add_tool_tracker_ability;
		return $worktree_add_tool_tracker_ability;
	}

	function is_wp_error( mixed $value ): bool {
		return false;
	}

	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceAliasResolver.php';
	require_once dirname(__DIR__) . '/inc/Tools/WorkspaceTools.php';

	final class Worktree_Add_Tool_Tracker_Contract extends \DataMachineCode\Tools\WorkspaceTools {
		public function __construct() {
		}
	}

	function worktree_add_tool_tracker_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException($message);
		}
	}

	try {
		$tool = new Worktree_Add_Tool_Tracker_Contract();
		$tool->handleWorktreeAdd(
			array(
				'repo'                 => 'example',
				'branch'               => 'feature/tracked',
				'require_task_tracker' => false,
			)
		);

		worktree_add_tool_tracker_assert(true === ( $worktree_add_tool_tracker_ability->input['require_task_tracker'] ?? null ), 'agent tool allowed tracker enforcement to be disabled');
		$definition = $tool->getWorktreeAddDefinition();
		$properties = (array) ( $definition['parameters']['properties'] ?? array() );
		worktree_add_tool_tracker_assert(! array_key_exists('require_task_tracker', $properties), 'agent tool schema exposes a tracker-enforcement override');
		fwrite(STDOUT, "worktree-add-tool-tracker-contract ok\n");
	} catch (\Throwable $e) {
		fwrite(STDERR, $e->getMessage() . "\n");
		exit(1);
	}
}
