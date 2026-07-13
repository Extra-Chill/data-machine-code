<?php
/**
 * Regression coverage for lossless JSON worktree list serialization.
 */

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

if ( ! function_exists('wp_json_encode') ) {
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode($value, $flags, $depth);
	}
}

if ( ! class_exists('WP_CLI') ) {
	class WP_CLI {
		public static string $output = '';

		public static function line( string $message ): void {
			self::$output .= $message;
		}
	}
}

require_once dirname(__DIR__) . '/inc/Cli/CliResponseRenderer.php';

use DataMachineCode\Cli\CliResponseRenderer;

$items = array(
	array(
		'handle'     => 'repo@task',
		'safety'     => null,
		'owner_full' => array( 'user' => 'chris', 'agent' => 'franklin' ),
		'session_full' => array( 'primary_id' => 'session-1', 'ids' => array( 'session-1', 'session-2' ) ),
		'task_full'  => array( 'task_url' => 'https://github.com/Extra-Chill/data-machine-code/issues/897' ),
		'metadata'   => array(
			'lifecycle_state' => 'active',
			'safety'          => array( 'dirty' => false, 'unpushed' => false ),
		),
	),
);

( new CliResponseRenderer() )->json($items);
$decoded = json_decode(WP_CLI::$output, true, 512, JSON_THROW_ON_ERROR);

if ( $items !== $decoded ) {
	throw new RuntimeException('Worktree list JSON must preserve nested lifecycle fields without field validation.');
}

$command_source = file_get_contents(dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php');
if ( false === $command_source || ! str_contains($command_source, "if ( 'json' === (string) ( \$assoc_args['format'] ?? '' ) ) {\n\t\t\t\t\t\$this->renderer()->json(\$items);") ) {
	throw new RuntimeException('Worktree list JSON must bypass WP-CLI field formatting.');
}

echo "worktree-list-json-structured-output: ok\n";
