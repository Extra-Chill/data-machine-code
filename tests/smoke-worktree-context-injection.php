<?php
/**
 * Smoke test for worktree context rendering.
 *
 *   php tests/smoke-worktree-context-injection.php
 *
 * @package DataMachineCode\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error extends Exception {
		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			parent::__construct( $message );
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $target ): bool {
		return is_dir( $target ) || mkdir( $target, 0777, true );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

require_once __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';

function datamachine_code_context_assert( bool $condition, string $message ): void {
	if ( $condition ) {
		echo "  [PASS] {$message}\n";
		return;
	}
	echo "  [FAIL] {$message}\n";
	exit( 1 );
}

function datamachine_code_pos( string $haystack, string $needle, string $label ): int {
	$pos = strpos( $haystack, $needle );
	datamachine_code_context_assert( false !== $pos, $label );
	return (int) $pos;
}

echo "=== smoke-worktree-context-injection ===\n";

$rules = <<<'MD'
# Site Rules

## General
- Be helpful.

## Minion Session Routing
- All minion sessions for this agent go in the canonical agent channel.
- Never route sessions to per-repo project channels.

## Pull Requests
- Open pull requests from the correct remote.
MD;

$rendered = \DataMachineCode\Workspace\WorktreeContextInjector::render(
	array(
		'site_name'  => 'Test Site',
		'site_url'   => 'https://example.test',
		'agent_slug' => 'agent-one',
		'abspath'    => '/wordpress',
		'timestamp'  => '2026-04-27T00:00:00+00:00',
		'files'      => array(
			'MEMORY.md' => "# Memory\n\n" . str_repeat( "Lots of context.\n", 20 ),
			'USER.md'   => "# User\n",
			'RULES.md'  => $rules,
		),
	)
);

$priority_pos = datamachine_code_pos( $rendered, '## Priority rules from RULES.md', 'priority rules section rendered' );
$memory_pos   = datamachine_code_pos( $rendered, '## MEMORY.md', 'full memory snapshot still rendered' );
$rules_pos    = datamachine_code_pos( $rendered, '## RULES.md', 'full rules snapshot still rendered' );

datamachine_code_context_assert( $priority_pos < $memory_pos, 'priority rules appear before long memory snapshot' );
datamachine_code_context_assert( $memory_pos < $rules_pos, 'full snapshot order stays unchanged' );
datamachine_code_context_assert( str_contains( $rendered, '## Minion Session Routing' ), 'minion routing heading is visible' );
datamachine_code_context_assert( str_contains( $rendered, 'canonical agent channel' ), 'site-provided routing text is preserved' );
$priority_excerpt = substr( $rendered, $priority_pos, $memory_pos - $priority_pos );
datamachine_code_context_assert(
	! str_contains( $priority_excerpt, '## Pull Requests' ),
	'priority excerpt stops before next H2 section'
);

$without_routing = \DataMachineCode\Workspace\WorktreeContextInjector::render(
	array(
		'site_name' => 'Test Site',
		'files'     => array(
			'RULES.md' => "# Site Rules\n\n## General\n- Be helpful.\n",
		),
	)
);

datamachine_code_context_assert(
	! str_contains( $without_routing, '## Priority rules from RULES.md' ),
	'priority section is omitted when source rules do not define routing'
);

$tmp_root        = sys_get_temp_dir() . '/dmc-context-injection-' . uniqid( '', true );
$site_root       = $tmp_root . '/site';
$worktree_root   = $tmp_root . '/worktree';
$existing_root   = $tmp_root . '/existing-worktree';
$virtual_root    = $tmp_root . '/virtual-worktree';
$source_agents   = $site_root . '/AGENTS.md';
$worktree_agents = $worktree_root . '/AGENTS.md';
$virtual_agents  = $virtual_root . '/AGENTS.md';

mkdir( $site_root, 0777, true );
mkdir( $worktree_root, 0777, true );
mkdir( $existing_root, 0777, true );
mkdir( $virtual_root, 0777, true );
file_put_contents( $source_agents, "# Site AGENTS\n" );
file_put_contents( $existing_root . '/AGENTS.md', "# Repo AGENTS\n" );

$injection = \DataMachineCode\Workspace\WorktreeContextInjector::inject(
	$worktree_root,
	array(
		'site_name'      => 'Test Site',
		'agents_md_path' => $source_agents,
		'files'          => array(
			'MEMORY.md' => "# Memory\n",
		),
	)
);

datamachine_code_context_assert( ! is_wp_error( $injection ), 'context injection succeeds' );
datamachine_code_context_assert( is_link( $worktree_agents ), 'root AGENTS.md projection is a symlink' );
datamachine_code_context_assert( readlink( $worktree_agents ) === $source_agents, 'root AGENTS.md points at site AGENTS.md' );
datamachine_code_context_assert( in_array( $worktree_agents, $injection['written'], true ), 'projected AGENTS.md is reported as written' );
datamachine_code_context_assert( trim( file_get_contents( $worktree_root . '/.datamachine/AGENTS.md.source' ) ) === "symlink\n" . $source_agents, 'projection marker records symlink source' );
datamachine_code_context_assert( ! file_exists( $worktree_root . '/.opencode/AGENTS.local.md' ), 'fake OpenCode local snapshot is not written' );
mkdir( $worktree_root . '/.opencode', 0777, true );
file_put_contents( $worktree_root . '/.opencode/AGENTS.local.md', "# Legacy\n" );

$virtual_injection = \DataMachineCode\Workspace\WorktreeContextInjector::inject(
	$virtual_root,
	array(
		'site_name'      => 'Virtual Site',
		'agents_md_path' => '/wordpress/AGENTS.md',
		'files'          => array(
			'MEMORY.md' => "# Virtual Memory\n",
		),
	)
);
datamachine_code_context_assert( ! is_wp_error( $virtual_injection ), 'virtual context injection succeeds' );
datamachine_code_context_assert( ! is_link( $virtual_agents ), 'virtual AGENTS.md projection is not a host symlink' );
datamachine_code_context_assert( is_file( $virtual_agents ), 'virtual AGENTS.md projection is written inline' );
datamachine_code_context_assert( str_contains( (string) file_get_contents( $virtual_agents ), '# Virtual Memory' ), 'inline virtual projection contains rendered context' );
datamachine_code_context_assert( trim( file_get_contents( $virtual_root . '/.datamachine/AGENTS.md.source' ) ) === "inline\n/wordpress/AGENTS.md", 'virtual projection marker records inline source' );

$existing_injection = \DataMachineCode\Workspace\WorktreeContextInjector::inject(
	$existing_root,
	array(
		'site_name'      => 'Test Site',
		'agents_md_path' => $source_agents,
		'files'          => array(),
	)
);
datamachine_code_context_assert( ! is_wp_error( $existing_injection ), 'context injection skips existing root AGENTS.md without error' );
datamachine_code_context_assert( '# Repo AGENTS' === trim( file_get_contents( $existing_root . '/AGENTS.md' ) ), 'repo-owned AGENTS.md is preserved' );
$existing_config = json_decode( (string) file_get_contents( $existing_root . '/.opencode/opencode.json' ), true );
datamachine_code_context_assert( is_array( $existing_config ), 'local OpenCode config is written when repo AGENTS.md exists' );
datamachine_code_context_assert( in_array( $source_agents, $existing_config['instructions'] ?? array(), true ), 'site AGENTS.md is added as an OpenCode instruction' );

$removed = \DataMachineCode\Workspace\WorktreeContextInjector::uninject( $worktree_root );
datamachine_code_context_assert( in_array( $worktree_agents, $removed['removed'], true ), 'uninject removes projected AGENTS.md symlink' );
datamachine_code_context_assert( ! file_exists( $worktree_agents ) && ! is_link( $worktree_agents ), 'projected AGENTS.md is gone after uninject' );
datamachine_code_context_assert( ! file_exists( $worktree_root . '/.datamachine/AGENTS.md.source' ), 'projection marker is gone after uninject' );
datamachine_code_context_assert( ! file_exists( $worktree_root . '/.opencode/AGENTS.local.md' ), 'uninject removes legacy fake OpenCode local snapshot' );
$virtual_removed = \DataMachineCode\Workspace\WorktreeContextInjector::uninject( $virtual_root );
datamachine_code_context_assert( in_array( $virtual_agents, $virtual_removed['removed'], true ), 'uninject removes inline virtual AGENTS.md projection' );
datamachine_code_context_assert( ! file_exists( $virtual_agents ), 'inline virtual projection is gone after uninject' );
$existing_removed = \DataMachineCode\Workspace\WorktreeContextInjector::uninject( $existing_root );
datamachine_code_context_assert( ! file_exists( $existing_root . '/.opencode/opencode.json' ), 'uninject removes DMC-created OpenCode projection config' );
datamachine_code_context_assert( in_array( $existing_root . '/.opencode/opencode.json', $existing_removed['removed'], true ), 'removed OpenCode projection config is reported' );

array_map( 'unlink', glob( $worktree_root . '/.claude/*' ) ?: array() );
array_map( 'unlink', glob( $worktree_root . '/.opencode/*' ) ?: array() );
array_map( 'unlink', glob( $existing_root . '/.claude/*' ) ?: array() );
array_map( 'unlink', glob( $existing_root . '/.opencode/*' ) ?: array() );
array_map( 'unlink', glob( $virtual_root . '/.claude/*' ) ?: array() );
array_map( 'rmdir', array_filter( glob( $worktree_root . '/*' ) ?: array(), 'is_dir' ) );
array_map( 'rmdir', array_filter( glob( $existing_root . '/*' ) ?: array(), 'is_dir' ) );
array_map( 'rmdir', array_filter( glob( $virtual_root . '/*' ) ?: array(), 'is_dir' ) );
unlink( $source_agents );
unlink( $existing_root . '/AGENTS.md' );
rmdir( $worktree_root . '/.claude' );
rmdir( $worktree_root . '/.opencode' );
rmdir( $worktree_root . '/.datamachine' );
rmdir( $existing_root . '/.claude' );
rmdir( $existing_root . '/.opencode' );
rmdir( $existing_root . '/.datamachine' );
rmdir( $virtual_root . '/.claude' );
rmdir( $virtual_root . '/.datamachine' );
rmdir( $worktree_root );
rmdir( $existing_root );
rmdir( $virtual_root );
rmdir( $site_root );
rmdir( $tmp_root );

echo "\nAll worktree context injection smoke tests passed.\n";
