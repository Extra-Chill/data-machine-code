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

	require_once dirname(__DIR__) . '/inc/Runtime/AgentsMdSections.php';

	\DataMachineCode\Runtime\AgentsMdSections::register();

	$sections = \DataMachine\Engine\AI\SectionRegistry::$sections;
	if ( ! isset($sections['datamachine-code']) ) {
		throw new RuntimeException('datamachine-code section was not registered');
	}
	if ( isset($sections['workspace-inventory']) ) {
		throw new RuntimeException('workspace-inventory section should not be registered');
	}
	if ( isset($sections['abilities']) || isset($sections['wordpress-source']) ) {
		throw new RuntimeException('generic WordPress guidance must be registered by wp-coding-agents');
	}
	if ( 20 !== $sections['datamachine-code']['priority'] ) {
		throw new RuntimeException('datamachine-code section priority must be 20');
	}

	$render = $sections['datamachine-code']['callback'];
	$default = $render();

	assert_contains(
		'Data Machine Code provides repository, primary-checkout, worktree, and GitHub workspace management.',
		$default,
		'DMC standalone capabilities missing'
	);
	assert_contains(
		'When using Data Machine Code to manage code changes, work in a Data Machine Code worktree. The controller workspace root is `unavailable; run datamachine-code workspace path to diagnose`.',
		$default,
		'default workspace policy intro changed'
	);
	assert_not_contains('Homeboy', $default, 'DMC guidance must not couple to Homeboy');
	assert_not_contains('All code changes happen', $default, 'DMC guidance must not claim universal routing authority');
	assert_not_contains(
		'Homeboy',
		json_encode($sections['datamachine-code']['metadata'], JSON_THROW_ON_ERROR),
		'DMC section metadata must not couple to Homeboy'
	);
	assert_contains(
		'Data Machine Code workspace lifecycle, GitHub, and safety guidance.',
		$sections['datamachine-code']['metadata']['description'],
		'DMC section metadata must describe DMC only'
	);
	assert_contains(
		'- **Primary is read-only.** Never edit `<workspace>/<repo>` (no `@slug`).',
		$default,
		'default workspace policy section missing'
	);
	assert_contains('**Default routing**', $default, 'DMC default routing missing');
	assert_contains('workspace worktree add <repo> <branch> --from=origin/<base>', $default, 'worktree creation route missing');
	assert_contains('workspace worktree finalize <repo@slug> --pr=<url>', $default, 'worktree finalization route missing');
	assert_contains('**Discovery**', $default, 'DMC discovery guidance missing');
	assert_not_contains('adopt|clone|list|show|path|hygiene', $default, 'enumerated workspace commands returned');
	assert_not_contains('wp-content/plugins/', $default, 'DMC duplicated WordPress source guidance');
	assert_not_contains('Snapshot summary:', $default, 'DMC embedded workspace inventory state');

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
	assert_contains('Use local project policy for `unavailable; run datamachine-code workspace path to diagnose`.', $filtered, 'workspace policy intro filter was not applied');
	assert_contains('- **Local policy:** caller-owned workspace rules.', $filtered, 'workspace policy section filter was not applied');
	assert_not_contains('- **Primary is read-only.** Never edit `<workspace>/<repo>` (no `@slug`).', $filtered, 'default policy section remained after filter override');
	assert_contains('**Default routing**', $filtered, 'DMC routing changed after policy filter');

	fwrite(STDOUT, "agents-md sections smoke passed\n");
}
