<?php
/**
 * Pure-PHP smoke for checkout-backed Homeboy PR review runner.
 *
 * Run: php tests/smoke-pr-homeboy-review-runner.php
 */

declare( strict_types=1 );

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/dmc-pr-homeboy-review/' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public string $code;
			public string $message;
			public array $data;

			public function __construct( string $code = '', string $message = '', array $data = array() ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}

			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool { return $thing instanceof \WP_Error; }
	}

	require __DIR__ . '/../inc/GitHub/PrHomeboyReviewRunner.php';

	use DataMachineCode\GitHub\PrHomeboyReviewRunner;

	$failures = 0;
	$total    = 0;
	$assert   = function ( bool $cond, string $message ) use ( &$failures, &$total ): void {
		$total++;
		if ( $cond ) {
			echo "  ✓ {$message}\n";
			return;
		}
		$failures++;
		echo "  ✗ {$message}\n";
	};

	echo "PR Homeboy review runner — smoke\n\n";

	$command_log = array();
	$runner      = new PrHomeboyReviewRunner(
		array(
			'pull_resolver'      => fn() => array(
				'number'   => 123,
				'head_sha' => 'abc123',
				'base_ref' => 'main',
			),
			'worktree_resolver'  => fn() => array(
				'success' => true,
				'created' => true,
				'handle'  => 'data-machine-code@pr-123-homeboy-review',
				'path'    => '/tmp/dmc-pr-review',
			),
			'homeboy_available' => fn() => true,
			'command_runner'    => function ( string $command, string $path ) use ( &$command_log ): array {
				$command_log[] = $command;
				if ( str_contains( $command, 'rev-parse HEAD' ) ) {
					return array( 'exit_code' => 0, 'output' => 'abc123' );
				}
				if ( str_contains( $command, 'homeboy test' ) ) {
					return array( 'exit_code' => 1, 'output' => 'test failure' );
				}
				return array( 'exit_code' => 0, 'output' => 'ok' );
			},
		)
	);

	$result = $runner->run(
		array(
			'repo'        => 'Extra-Chill/data-machine-code',
			'pull_number' => 123,
			'head_sha'    => 'abc123',
		)
	);

	$assert( ! is_wp_error( $result ), 'happy path returns a result array' );
	$assert( true === $result['success'], 'success flag is included for ability/tool callers' );
	$assert( 'data-machine-code/pr-homeboy-review/v1' === $result['schema'], 'schema is pinned' );
	$assert( 'Extra-Chill/data-machine-code' === $result['repo'], 'repo is included' );
	$assert( 123 === $result['pull_number'], 'pull number is included' );
	$assert( 'abc123' === $result['head_sha'], 'head SHA is included' );
	$assert( 'main' === $result['base_ref'], 'base ref defaults from GitHub pull metadata' );
	$assert( 'partial' === $result['status'], 'mixed command statuses roll up to partial' );
	$assert( true === $result['worktree']['created'], 'worktree created flag is carried through' );
	$assert( '/tmp/dmc-pr-review' === $result['worktree']['path'], 'worktree path is carried through' );
	$assert( 'abc123' === $result['checkout']['head_sha'], 'checkout records verified head SHA' );
	$assert( array( 'lint', 'test', 'audit' ) === array_column( $result['commands'], 'name' ), 'constrained Homeboy command set is lint/test/audit' );
	$assert( array( 'passed', 'failed', 'passed' ) === array_column( $result['commands'], 'status' ), 'command exit codes normalize to statuses' );
	$assert( str_contains( implode( "\n", $command_log ), 'pull/123/head' ), 'git fetches the pull request ref' );
	$assert( str_contains( implode( "\n", $command_log ), 'checkout --detach' ), 'git checks out detached exact head' );
	$assert( str_contains( implode( "\n", $command_log ), 'homeboy audit' ) && str_contains( implode( "\n", $command_log ), '--changed-since' ), 'audit command includes changed-since base' );

	$skipped = ( new PrHomeboyReviewRunner(
		array(
			'pull_resolver'      => fn() => array( 'head_sha' => 'abc123', 'base_ref' => 'trunk' ),
			'worktree_resolver'  => fn() => array( 'created' => false, 'handle' => 'repo@pr-1-homeboy-review', 'path' => '/tmp/repo' ),
			'homeboy_available' => fn() => false,
			'command_runner'    => fn( string $command, string $path ) => str_contains( $command, 'rev-parse HEAD' )
				? array( 'exit_code' => 0, 'output' => 'abc123' )
				: array( 'exit_code' => 0, 'output' => '' ),
		)
	) )->run(
		array(
			'repo'        => 'Extra-Chill/repo',
			'pull_number' => 1,
			'head_sha'    => 'abc123',
		)
	);

	$assert( ! is_wp_error( $skipped ), 'homeboy-unavailable path returns a result array' );
	$assert( 'skipped' === $skipped['status'], 'homeboy-unavailable rolls up to skipped' );
	$assert( 'homeboy_unavailable' === $skipped['commands'][0]['reason'], 'skip reason is explicit' );

	$mismatch = ( new PrHomeboyReviewRunner(
		array(
			'pull_resolver' => fn() => array( 'head_sha' => 'def456', 'base_ref' => 'main' ),
		)
	) )->run(
		array(
			'repo'        => 'Extra-Chill/data-machine-code',
			'pull_number' => 123,
			'head_sha'    => 'abc123',
		)
	);

	$assert( is_wp_error( $mismatch ), 'GitHub head mismatch fails closed' );
	$assert( is_wp_error( $mismatch ) && 'head_sha_mismatch' === $mismatch->get_error_code(), 'GitHub head mismatch uses explicit error code' );

	$checkout_mismatch = ( new PrHomeboyReviewRunner(
		array(
			'pull_resolver'      => fn() => array( 'head_sha' => 'abc123', 'base_ref' => 'main' ),
			'worktree_resolver'  => fn() => array( 'created' => false, 'handle' => 'repo@pr-2-homeboy-review', 'path' => '/tmp/repo' ),
			'homeboy_available' => fn() => true,
			'command_runner'    => fn( string $command, string $path ) => str_contains( $command, 'rev-parse HEAD' )
				? array( 'exit_code' => 0, 'output' => '000000' )
				: array( 'exit_code' => 0, 'output' => '' ),
		)
	) )->run(
		array(
			'repo'        => 'Extra-Chill/data-machine-code',
			'pull_number' => 2,
			'head_sha'    => 'abc123',
		)
	);

	$assert( is_wp_error( $checkout_mismatch ), 'local checkout mismatch fails closed' );
	$assert( is_wp_error( $checkout_mismatch ) && 'checkout_head_mismatch' === $checkout_mismatch->get_error_code(), 'local checkout mismatch uses explicit error code' );

	if ( $failures > 0 ) {
		echo "\nFAIL: {$failures}/{$total} assertion(s) failed\n";
		exit( 1 );
	}

	echo "\nOK ({$total} assertions)\n";
	exit( 0 );
}
