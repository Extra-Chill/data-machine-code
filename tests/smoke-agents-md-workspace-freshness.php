<?php

declare(strict_types=1);

namespace DataMachine\Engine\AI {
	final class MemoryFileRegistry {
		public const LAYER_SHARED = 'shared';

		public static function register( string $file, int $priority, array $metadata ): void {}
	}

	final class SectionRegistry {
		public static array $sections = array();

		public static function register( string $file, string $section, int $priority, callable $callback, array $metadata ): void {
			self::$sections[ $section ] = compact('file', 'section', 'priority', 'callback', 'metadata');
		}
	}
}

namespace DataMachineCode\Workspace {
	final class Workspace {
		public function get_path(): string {
			return '/tmp/dmc-workspace';
		}

		public function list_repos(): array {
			return array(
				'success' => true,
				'path'    => '/tmp/dmc-workspace',
				'repos'   => array(
					array(
						'name'              => 'current-repo',
						'repo'              => 'current-repo',
						'git'               => true,
						'is_worktree'       => false,
						'branch'            => 'main',
						'remote'            => 'https://example.com/current-repo.git',
						'primary_freshness' => array(
							'status'   => 'current',
							'branch'   => 'main',
							'upstream' => 'origin/main',
							'behind'   => 0,
							'ahead'    => 0,
						),
					),
					array(
						'name'              => 'stale-repo',
						'repo'              => 'stale-repo',
						'git'               => true,
						'is_worktree'       => false,
						'branch'            => 'trunk',
						'remote'            => 'https://example.com/stale-repo.git',
						'primary_freshness' => array(
							'status'            => 'stale',
							'branch'            => 'trunk',
							'upstream'          => 'origin/trunk',
							'behind'            => 7,
							'ahead'             => 0,
							'suggested_command' => 'wp datamachine-code workspace git pull stale-repo --allow-primary-refresh',
						),
					),
					array(
						'name'          => 'stale-repo@fix-example',
						'repo'          => 'stale-repo',
						'git'           => true,
						'is_worktree'   => true,
						'branch_slug'   => 'fix-example',
						'branch'        => 'fix/example',
					),
				),
			);
		}
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', '/var/www/html');
	}

	function datamachine_agents_md_enabled(): bool {
		return true;
	}

	function is_multisite(): bool {
		return false;
	}

	function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
		if ( 'datamachine_code_workspace_inventory_mode' === $hook_name && isset($GLOBALS['datamachine_code_test_inventory_mode']) ) {
			return $GLOBALS['datamachine_code_test_inventory_mode'];
		}

		return $value;
	}

	function is_wp_error( mixed $thing ): bool {
		return false;
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
	if ( ! isset($sections['workspace-inventory']) ) {
		throw new RuntimeException('workspace-inventory section was not registered');
	}

	$render = $sections['workspace-inventory']['callback'];
	$rendered = $render();

	assert_contains('Live workspace state is intentionally not embedded here.', $rendered, 'live inventory pointer missing');
	assert_contains('workspace list` for the authoritative inventory', $rendered, 'workspace list pointer missing');
	assert_contains('Snapshot summary: 2 primary checkouts and 1 worktree under `/tmp/dmc-workspace`.', $rendered, 'deterministic workspace summary changed');
	assert_contains('Primary checkout attention: 1 checkout needs inspection.', $rendered, 'bounded primary attention summary missing');
	assert_not_contains('https://example.com/stale-repo.git', $rendered, 'default inventory embedded repository detail');
	if ( strlen($rendered) > 700 ) {
		throw new RuntimeException('default workspace inventory exceeded its compact size budget');
	}

	$GLOBALS['datamachine_code_test_inventory_mode'] = 'full';
	$full = $render();
	assert_contains('Detailed snapshot from cloned repos in `/tmp/dmc-workspace`.', $full, 'detailed snapshot heading missing');
	assert_contains('**Primary Checkout Attention**', $full, 'detailed primary freshness attention block missing');
	assert_contains('- **stale-repo** primary is `stale` (branch `trunk`, upstream `origin/trunk`, behind 7, ahead 0). Refresh: `wp datamachine-code workspace git pull stale-repo --allow-primary-refresh`.', $full, 'detailed stale primary facts missing');
	assert_contains('- **stale-repo** (`trunk`) — https://example.com/stale-repo.git · primary stale, behind 7', $full, 'detailed repository facts missing');

	fwrite(STDOUT, "agents-md workspace freshness smoke passed\n");
}
