<?php

declare(strict_types=1);

namespace DataMachine\Cli {
	class BaseCommand {}
}

namespace {
	final class WP_CLI {
		public static string $output = '';

		public static function line( string $message ): void {
			self::$output .= $message;
		}
	}

	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode($value, $flags, $depth);
	}

	define('ABSPATH', __DIR__ . '/fixtures/');
	require_once dirname(__DIR__) . '/inc/Cli/CliResponseRenderer.php';
	require_once dirname(__DIR__) . '/inc/Cli/WorkspaceCompactOutput.php';
	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	$command = new \DataMachineCode\Cli\Commands\WorkspaceCommand();
	$method  = new \ReflectionMethod($command, 'renderWorktreeResult');
	$result  = array(
		'success'      => true,
		'mode'         => 'cleanup_eligible_drain',
		'applied'      => false,
		'pass_results' => array(
			array(
				'pass'           => 1,
				'candidate_rows' => array( array( 'handle' => 'repo@candidate', 'evidence' => array( 'fresh' => true ) ) ),
			),
		),
		'summary'      => array( 'passes' => 1, 'planned' => 1, 'stop_reason' => 'preview' ),
	);

	$method->invoke($command, 'cleanup-eligible-drain', $result, array( 'format' => 'json' ));
	$compact = json_decode(WP_CLI::$output, true, 512, JSON_THROW_ON_ERROR);
	if ( isset($compact['pass_results']) || ! str_contains((string) ( $compact['full_detail_hint'] ?? '' ), '--verbose --format=json') ) {
		throw new \RuntimeException('Drain JSON must be compact by default and explain how to request full detail.');
	}

	WP_CLI::$output = '';
	$method->invoke($command, 'cleanup-eligible-drain', $result, array( 'format' => 'json', 'verbose' => true ));
	$verbose = json_decode(WP_CLI::$output, true, 512, JSON_THROW_ON_ERROR);
	if ( 'repo@candidate' !== ( $verbose['pass_results'][0]['candidate_rows'][0]['handle'] ?? null ) ) {
		throw new \RuntimeException('Drain --verbose JSON must retain full pass-row evidence.');
	}

	echo "worktree-cleanup-eligible-drain-output: ok\n";
}
