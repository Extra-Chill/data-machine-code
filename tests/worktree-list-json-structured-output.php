<?php
/**
 * Regression coverage for worktree list JSON serialization.
 */

declare(strict_types=1);

namespace DataMachine\Cli {
	abstract class BaseCommand {
		protected function format_items( array $items, array $fields, array $assoc_args, string $id_field = '' ): void {
		}

	}
}

namespace {
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
			public static array $logs = array();

			public static function line( string $message ): void {
				self::$output .= $message;
			}

			public static function log( string $message ): void {
				self::$logs[] = $message;
			}
		}
	}

	require_once dirname(__DIR__) . '/inc/Cli/CliResponseRenderer.php';
	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	use DataMachineCode\Cli\Commands\WorkspaceCommand;

	$result = array(
		'total' => 1,
		'returned' => 1,
		'next_cursor' => null,
		'status_requested' => false,
		'disk_requested' => false,
		'worktrees' => array(
			array(
				'handle'   => 'repo@task',
				'repo'     => 'repo',
				'branch'   => 'task',
				'head'     => '1234567890abcdef',
				'owner'    => array( 'user' => 'chris', 'agent' => 'franklin' ),
				'session'  => array( 'primary_id' => 'session-1', 'ids' => array( 'session-1', 'session-2' ) ),
				'task'     => array( 'task_url' => 'https://github.com/Extra-Chill/data-machine-code/issues/897' ),
				'metadata' => array(
					'lifecycle_state' => 'active',
					'safety'          => array( 'dirty' => false, 'unpushed' => false ),
				),
			),
		),
	);

	$command = new WorkspaceCommand();
	$method  = new ReflectionMethod($command, 'renderWorktreeResult');
	$method->invoke($command, 'list', $result, array( 'format' => 'json' ));

	$decoded = json_decode(WP_CLI::$output, true, 512, JSON_THROW_ON_ERROR);
	$row     = $decoded[0] ?? null;
	if ( ! is_array($row) ) {
		throw new RuntimeException('Legacy worktree list JSON must remain a row array.');
	}
	WP_CLI::$output = '';
	$method->invoke($command, 'list', $result, array( 'format' => 'json', 'envelope' => true ));
	$decoded = json_decode(WP_CLI::$output, true, 512, JSON_THROW_ON_ERROR);
	if (1 !== ($decoded['total'] ?? null) || 1 !== ($decoded['returned'] ?? null) || ! array_key_exists('next_cursor', $decoded)) {
		throw new RuntimeException('Envelope JSON must retain bounded-page metadata.');
	}
	$row = $decoded['worktrees'][0] ?? null;
	if ( ! is_array($row) ) {
		throw new RuntimeException('Worktree list JSON must render a row.');
	}
	if ( array_key_exists('safety', $row) ) {
		throw new RuntimeException('Worktree list JSON must not require skipped status safety data.');
	}
	if ( array( 'user' => 'chris', 'agent' => 'franklin' ) !== ( $row['owner_full'] ?? null ) ) {
		throw new RuntimeException('Worktree list JSON must preserve nested owner data.');
	}
	if ( array( 'primary_id' => 'session-1', 'ids' => array( 'session-1', 'session-2' ) ) !== ( $row['session_full'] ?? null ) ) {
		throw new RuntimeException('Worktree list JSON must preserve nested session data.');
	}
	if ( array( 'task_url' => 'https://github.com/Extra-Chill/data-machine-code/issues/897' ) !== ( $row['task_full'] ?? null ) ) {
		throw new RuntimeException('Worktree list JSON must preserve nested task data.');
	}
	if ( array( 'lifecycle_state' => 'active', 'safety' => array( 'dirty' => false, 'unpushed' => false ) ) !== ( $row['metadata'] ?? null ) ) {
		throw new RuntimeException('Worktree list JSON must preserve nested lifecycle metadata safety data.');
	}

	$empty_result = array(
		'success'      => true,
		'total'        => 0,
		'returned'     => 0,
		'next_cursor'  => null,
		'worktrees'    => array(),
		'summary'      => array( 'repos' => array() ),
		'filters'      => array( 'task_ref' => 'https://github.com/example/repo/issues/123' ),
	);
	WP_CLI::$output = '';
	$method->invoke($command, 'list', $empty_result, array( 'format' => 'json', 'envelope' => true ));
	$decoded = json_decode(WP_CLI::$output, true, 512, JSON_THROW_ON_ERROR);
	if ( 0 !== ($decoded['total'] ?? null) || 0 !== ($decoded['returned'] ?? null) || array() !== ($decoded['worktrees'] ?? null) || $empty_result['filters'] !== ($decoded['filters'] ?? null) ) {
		throw new RuntimeException('Empty envelope JSON must retain bounded metadata and filters.');
	}

	WP_CLI::$output = '';
	$method->invoke($command, 'list', $empty_result, array( 'format' => 'json' ));
	if ( array() !== json_decode(WP_CLI::$output, true, 512, JSON_THROW_ON_ERROR) ) {
		throw new RuntimeException('Empty legacy JSON must remain a row array.');
	}

	WP_CLI::$logs = array();
	$method->invoke($command, 'list', $empty_result, array());
	if ( array( 'No worktrees found.' ) !== WP_CLI::$logs ) {
		throw new RuntimeException('Empty human output must retain its operator message.');
	}

	echo "worktree-list-json-structured-output: ok\n";
}
