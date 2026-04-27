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

echo "\nAll worktree context injection smoke tests passed.\n";
