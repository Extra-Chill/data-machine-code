<?php

declare(strict_types=1);

namespace DataMachine\Engine\AI\Tools {
	class BaseTool {}
}

namespace {
	define('ABSPATH', __DIR__ . '/fixtures/');

	final class WP_Error {
		public function __construct(private string $code, private string $message, private array $data) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}

	final class Workspace_Tool_Error_Ability {
		public function execute( array $input ): WP_Error {
			unset($input);
			return new WP_Error('worktree_handoff_freshness_unverified', 'handoff pending', array(
				'partial_success'    => true,
				'mutation_committed' => true,
				'continuation'       => array( 'ability' => 'datamachine-code/workspace-worktree-handoff-resume' ),
			));
		}
	}

	function wp_get_ability( string $name ): Workspace_Tool_Error_Ability {
		unset($name);
		return new Workspace_Tool_Error_Ability();
	}

	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

	require_once dirname(__DIR__) . '/inc/Workspace/WorkspaceAliasResolver.php';
	require_once dirname(__DIR__) . '/inc/Workspace/WorktreeContextInjector.php';
	require_once dirname(__DIR__) . '/inc/Tools/WorkspaceTools.php';

	final class Workspace_Tool_Error_Envelope_Harness extends \DataMachineCode\Tools\WorkspaceTools {
		public function __construct() {}
	}

	$result = ( new Workspace_Tool_Error_Envelope_Harness() )->handleWorktreeAdd(array(
		'repo'     => 'example',
		'branch'   => 'fix/1205',
		'task_ref' => 'Extra-Chill/data-machine-code#1205',
	));
	if ( 'worktree_handoff_freshness_unverified' !== ( $result['error_code'] ?? null )
		|| true !== ( $result['error_data']['mutation_committed'] ?? false )
		|| 'datamachine-code/workspace-worktree-handoff-resume' !== ( $result['error_data']['continuation']['ability'] ?? null )
	) {
		throw new RuntimeException('Workspace tool discarded the committed mutation boundary or exact continuation.');
	}

	fwrite(STDOUT, "workspace-tool-error-envelope: ok\n");
}
