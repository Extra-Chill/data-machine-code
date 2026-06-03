<?php
/**
 * Smoke test for DMC ability-native model tool projections.
 *
 * Run: php tests/smoke-ability-tool-projections.php
 */

declare( strict_types=1 );

namespace AgentsAPI\AI {
	class WP_Agent_Message {
		public static function text( string $role, $content, array $metadata = array() ): array {
			return compact('role', 'content') + array( 'metadata' => $metadata );
		}

		public static function toolCall( string $content, string $tool_name, array $parameters, int $turn, array $metadata = array() ): array {
			return array(
				'role'     => 'assistant',
				'type'     => 'tool_call',
				'content'  => $content,
				'payload'  => compact('tool_name', 'parameters', 'turn'),
				'metadata' => $metadata,
			);
		}

		public static function toolResult( string $content, string $tool_name, array $payload, array $metadata = array() ): array {
			$payload['tool_name'] = $tool_name;

			return array(
				'role'     => 'user',
				'type'     => 'tool_result',
				'content'  => $content,
				'payload'  => $payload,
				'metadata' => $metadata,
			);
		}
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__ . '/');
	}

	$GLOBALS['dmc_projection_registered_tools'] = array();

	function datamachine_register_ability_tool( string $tool_name, array $declaration ): bool {
		$GLOBALS['dmc_projection_registered_tools'][ $tool_name ] = $declaration;
		return true;
	}

	function dmc_projection_normalize_tool_result( array $ability_result, string $tool_name, string $ability_slug ): array {
		$result              = $ability_result;
		$result['tool_name'] = $result['tool_name'] ?? $tool_name;
		$result['metadata']  = array_merge(
			array( 'ability' => $ability_slug ),
			is_array($result['metadata'] ?? null) ? $result['metadata'] : array()
		);

		if ( ! array_key_exists('success', $result) ) {
			$result['success'] = true;
		}

		if ( $result['success'] && ! array_key_exists('result', $result) ) {
			$payload = $result;
			unset($payload['success'], $payload['tool_name'], $payload['metadata']);
			$result['result'] = $payload;
		}

		return $result;
	}

	function dmc_projection_find_data_machine_conversation_manager(): ?string {
		$candidates = array_filter(
			array(
				getenv('DATAMACHINE_CORE_PATH') ?: null,
				dirname(__DIR__, 2) . '/data-machine',
				dirname(__DIR__) . '/../data-machine',
			)
		);

		foreach ( $candidates as $candidate ) {
			$path = rtrim((string) $candidate, '/') . '/inc/Engine/AI/ConversationManager.php';
			if ( is_file($path) ) {
				return $path;
			}
		}

		return null;
	}

	include __DIR__ . '/../inc/Tools/AbilityToolProjections.php';

	$failures = array();
	$passes   = 0;
	$assert   = function ( string $label, bool $condition ) use ( &$failures, &$passes ): void {
		if ( $condition ) {
			++$passes;
			echo "  ok {$label}\n";
			return;
		}

		$failures[] = $label;
		echo "  fail {$label}\n";
	};

	echo "Ability tool projections - smoke\n";

	$registered = \DataMachineCode\Tools\AbilityToolProjections::register();
	$tools      = $GLOBALS['dmc_projection_registered_tools'];

	$expected = array(
		'workspace_list'                 => 'datamachine-code/workspace-list',
		'workspace_read'                 => 'datamachine-code/workspace-read',
		'workspace_grep'                 => 'datamachine-code/workspace-grep',
		'list_github_issues'             => 'datamachine-code/list-github-issues',
		'list_github_pulls'              => 'datamachine-code/list-github-pulls',
		'list_github_repos'              => 'datamachine-code/list-github-repos',
		'get_github_pull'                => 'datamachine-code/get-github-pull',
		'get_github_file'                => 'datamachine-code/get-github-file',
		'list_github_tree'               => 'datamachine-code/list-github-tree',
		'get_github_pull_review_context' => 'datamachine-code/get-github-pull-review-context',
	);

	$assert('projection helper was used', true === $registered);

	foreach ( $expected as $tool_name => $ability_slug ) {
		$declaration = $tools[ $tool_name ] ?? array();
		$assert("{$tool_name} preserves model-facing name", isset($tools[ $tool_name ]));
		$assert("{$tool_name} points at canonical ability", $ability_slug === ( $declaration['ability'] ?? '' ));
		$assert("{$tool_name} is available to chat", in_array('chat', $declaration['modes'] ?? array(), true));
		$assert("{$tool_name} is available to pipeline", in_array('pipeline', $declaration['modes'] ?? array(), true));
		$assert("{$tool_name} does not duplicate parameter schema", ! array_key_exists('parameters', $declaration));
	}

	$workspace_result = dmc_projection_normalize_tool_result(
		array(
			'success' => true,
			'content' => "# Demo\nworkspace_read_projected_visible_anchor\n",
			'path'    => 'README.md',
		),
		'workspace_read',
		'datamachine-code/workspace-read'
	);

	$github_result = dmc_projection_normalize_tool_result(
		array(
			'success' => true,
			'files'   => array(
				array(
					'path'    => 'README.md',
					'content' => 'github_projected_visible_anchor',
				),
			),
			'count'   => 1,
		),
		'get_github_file',
		'datamachine-code/get-github-file'
	);

	$assert('projected workspace execution records model tool name', 'workspace_read' === ( $workspace_result['tool_name'] ?? '' ));
	$assert('projected workspace execution records canonical ability', 'datamachine-code/workspace-read' === ( $workspace_result['metadata']['ability'] ?? '' ));
	$assert('projected workspace execution exposes payload under result for model visibility', str_contains((string) ( $workspace_result['result']['content'] ?? '' ), 'workspace_read_projected_visible_anchor'));
	$assert('projected GitHub execution records model tool name', 'get_github_file' === ( $github_result['tool_name'] ?? '' ));
	$assert('projected GitHub execution records canonical ability', 'datamachine-code/get-github-file' === ( $github_result['metadata']['ability'] ?? '' ));
	$assert('projected GitHub execution keeps file payload visible', 'github_projected_visible_anchor' === ( $github_result['result']['files'][0]['content'] ?? '' ));

	$conversation_manager = dmc_projection_find_data_machine_conversation_manager();
	if ( null !== $conversation_manager && str_contains((string) file_get_contents($conversation_manager), 'modelFacingToolData') ) {
		require_once $conversation_manager;

		$workspace_message = \DataMachine\Engine\AI\ConversationManager::formatToolResultMessage(
			'workspace_read',
			$workspace_result,
			array( 'repo' => 'demo', 'path' => 'README.md' ),
			false,
			1
		);
		$github_message    = \DataMachine\Engine\AI\ConversationManager::formatToolResultMessage(
			'get_github_file',
			$github_result,
			array( 'repo' => 'Extra-Chill/data-machine-code', 'path' => 'README.md' ),
			false,
			1
		);

		$assert('projected workspace transcript includes payload content', str_contains((string) ( $workspace_message['content'] ?? '' ), 'workspace_read_projected_visible_anchor'));
		$assert('projected GitHub transcript includes payload content', str_contains((string) ( $github_message['content'] ?? '' ), 'github_projected_visible_anchor'));
		$assert('projected transcript payload keeps workspace tool_data', ( $workspace_result['result'] ?? null ) === ( $workspace_message['payload']['tool_data'] ?? null ));
		$assert('projected transcript payload keeps GitHub tool_data', ( $github_result['result'] ?? null ) === ( $github_message['payload']['tool_data'] ?? null ));
	} else {
		echo "  skip projected transcript formatter assertions (updated Data Machine formatter not found)\n";
	}

	if ( ! empty($failures) ) {
		echo "\nFAIL: " . count($failures) . " assertion(s)\n";
		foreach ( $failures as $failure ) {
			echo "  - {$failure}\n";
		}
		exit(1);
	}

	echo "\nOK ({$passes} assertions)\n";
	exit(0);
}
