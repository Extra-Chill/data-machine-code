<?php

declare(strict_types=1);

namespace DataMachine\Engine\AI {
	final class MemoryFileRegistry {
		public const LAYER_SHARED = 'shared';

		public static array $files = array();

		public static function register( string $file, int $priority, array $metadata ): void {
			self::$files[] = compact('file', 'priority', 'metadata');
		}
	}

	final class SectionRegistry {
		public static array $sections = array();

		public static function register( string $file, string $section, int $priority, callable $callback, array $metadata ): void {
			self::$sections[ $section ] = compact('file', 'section', 'priority', 'callback', 'metadata');
		}
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', '/var/www/html');
	}

	$GLOBALS['datamachine_code_test_filters'] = array();

	function datamachine_agents_md_enabled(): bool {
		return true;
	}

	function is_multisite(): bool {
		return false;
	}

	function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
		$filters = $GLOBALS['datamachine_code_test_filters'][ $hook_name ] ?? array();
		foreach ( $filters as $filter ) {
			$value = $filter($value, ...$args);
		}

		return $value;
	}

	function add_test_filter( string $hook_name, callable $callback ): void {
		$GLOBALS['datamachine_code_test_filters'][ $hook_name ][] = $callback;
	}

	function assert_contains( string $needle, string $haystack, string $message ): void {
		if ( ! str_contains($haystack, $needle) ) {
			throw new RuntimeException($message);
		}
	}

	function assert_not_contains( string $needle, string $haystack, string $message ): void {
		if ( str_contains($haystack, $needle) ) {
			throw new RuntimeException($message);
		}
	}

	require_once dirname(__DIR__) . '/inc/Runtime/CommandIntrospector.php';
	require_once dirname(__DIR__) . '/inc/Runtime/AgentsMdSections.php';

	\DataMachineCode\Runtime\AgentsMdSections::register();

	$sections = \DataMachine\Engine\AI\SectionRegistry::$sections;
	if ( ! isset($sections['datamachine-code']) ) {
		throw new RuntimeException('datamachine-code section was not registered');
	}

	$render = $sections['datamachine-code']['callback'];
	$default = $render();

	assert_contains(
		'All code changes happen in Data Machine Code worktrees under `unavailable; run datamachine-code workspace path to diagnose`. DMC owns workspace lifecycle',
		$default,
		'default workspace policy intro changed'
	);
	assert_contains(
		'- **Primary is read-only.** Never edit `<workspace>/<repo>` (no `@slug`).',
		$default,
		'default workspace policy section missing'
	);
	assert_contains(
		'- **Workspace:** `wp datamachine-code workspace adopt|clone|list|show|path|hygiene|remove|worktree|read|write|grep|edit|git|patch|ls`',
		$default,
		'DMC workspace command facts missing'
	);

	add_test_filter(
		'datamachine_code_workspace_policy_intro',
		static function ( string $default, string $workspace_path ): string {
			return "Use local project policy for `{$workspace_path}`. ";
		}
	);
	add_test_filter(
		'datamachine_code_workspace_policy_section',
		static function (): string {
			return '- **Local policy:** caller-owned workspace rules.';
		}
	);

	$filtered = $render();
	assert_contains('Use local project policy for `unavailable; run datamachine-code workspace path to diagnose`. DMC owns workspace lifecycle', $filtered, 'workspace policy intro filter was not applied');
	assert_contains('- **Local policy:** caller-owned workspace rules.', $filtered, 'workspace policy section filter was not applied');
	assert_not_contains('- **Primary is read-only.** Never edit `<workspace>/<repo>` (no `@slug`).', $filtered, 'default policy section remained after filter override');
	assert_contains('- **Workspace:** `wp datamachine-code workspace adopt|clone|list|show|path|hygiene|remove|worktree|read|write|grep|edit|git|patch|ls`', $filtered, 'DMC command facts changed after policy filter');

	fwrite(STDOUT, "agents-md sections smoke passed\n");
}
