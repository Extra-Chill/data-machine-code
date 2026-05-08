<?php
/**
 * Pure-PHP smoke test for workspace clone UX helpers.
 *
 * Run: php tests/smoke-workspace-clone-ux.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace {
	$root      = sys_get_temp_dir() . '/dmc-clone-ux-' . getmypid();
	$workspace = $root . '/workspace';
	mkdir( $workspace, 0755, true );

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	if ( ! defined( 'DATAMACHINE_WORKSPACE_PATH' ) ) {
		define( 'DATAMACHINE_WORKSPACE_PATH', $workspace );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			private string $code;
			private string $message;
			private $data;

			public function __construct( string $code = '', string $message = '', $data = array() ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}

			public function get_error_data() {
				return $this->data;
			}
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool {
			return $thing instanceof \WP_Error;
		}
	}

	if ( ! function_exists( 'wp_mkdir_p' ) ) {
		function wp_mkdir_p( string $path ): bool {
			return is_dir( $path ) || mkdir( $path, 0755, true );
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $_name, $default = false ) {
			return $default;
		}
	}

	if ( ! function_exists( 'size_format' ) ) {
		function size_format( $bytes ): string {
			return (string) $bytes;
		}
	}

	if ( ! function_exists( 'do_action' ) ) {
		function do_action( string $_hook, array $_payload ): void {}
	}

	require __DIR__ . '/../inc/Support/GitRunner.php';
	require __DIR__ . '/../inc/Workspace/Workspace.php';

	$failures = 0;
	$total    = 0;

	$assert = function ( bool $condition, string $message ) use ( &$failures, &$total ): void {
		$total++;
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}

		$failures++;
		echo "  [FAIL] {$message}\n";
	};

	$assert_same = function ( $expected, $actual, string $message ) use ( &$failures, &$total ): void {
		$total++;
		if ( $expected === $actual ) {
			echo "  [PASS] {$message}\n";
			return;
		}

		$failures++;
		echo "  [FAIL] {$message}\n";
		echo '    expected: ' . var_export( $expected, true ) . "\n";
		echo '    actual:   ' . var_export( $actual, true ) . "\n";
	};

	$run = function ( string $command ): void {
		exec( $command . ' 2>&1', $output, $exit_code );
		if ( 0 !== $exit_code ) {
			throw new \RuntimeException( sprintf( "Command failed (%d): %s\n%s", $exit_code, $command, implode( "\n", $output ) ) );
		}
	};

	$rm_rf = function ( string $path ) use ( &$rm_rf ): void {
		if ( ! file_exists( $path ) ) {
			return;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			unlink( $path );
			return;
		}

		foreach ( scandir( $path ) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$rm_rf( $path . '/' . $entry );
		}

		rmdir( $path );
	};

	try {
		echo "=== smoke-workspace-clone-ux ===\n";

		$workspace_object = new \DataMachineCode\Workspace\Workspace();
		$reflection       = new \ReflectionClass( $workspace_object );
		$build_command    = $reflection->getMethod( 'build_clone_command' );
		$partial_decision = $reflection->getMethod( 'should_use_partial_clone' );

		echo "\n[1] Clone command construction\n";
		$remote_command = $build_command->invoke( $workspace_object, 'https://github.com/Extra-Chill/data-machine-code.git', $workspace . '/remote', true );
		$local_command  = $build_command->invoke( $workspace_object, $root . '/upstream', $workspace . '/local', false );
		$assert( str_contains( $remote_command, '--progress' ), 'remote command requests git progress' );
		$assert( str_contains( $remote_command, '--filter=blob:none' ), 'remote command uses blobless partial clone' );
		$assert( ! str_contains( $local_command, '--filter=blob:none' ), 'local command omits partial clone filter' );
		$assert_same( true, $partial_decision->invoke( $workspace_object, 'git@github.com:Extra-Chill/data-machine-code.git' ), 'ssh remotes use partial clone' );
		$assert_same( false, $partial_decision->invoke( $workspace_object, $root . '/upstream' ), 'local paths skip partial clone' );

		echo "\n[2] Existing partial target recovery guidance\n";
		mkdir( $workspace . '/partial-target', 0755, true );
		$exists = $workspace_object->clone_repo( 'https://github.com/Extra-Chill/data-machine-code.git', 'partial-target' );
		$assert( is_wp_error( $exists ), 'clone_repo reports existing target as error' );
		$assert_same( 'repo_exists', $exists->get_error_code(), 'existing target uses repo_exists code' );
		$assert( str_contains( $exists->get_error_message(), 'partial or non-git directory' ), 'existing target message describes partial state' );
		$assert( str_contains( $exists->get_error_message(), 'workspace remove partial-target' ), 'existing target message includes cleanup command' );
		$assert( is_array( $exists->get_error_data()['next_steps'] ?? null ), 'existing target includes structured next steps' );

		echo "\n[3] Local clone streams progress events\n";
		$upstream = $root . '/upstream';
		mkdir( $upstream, 0755, true );
		$run( 'git -C ' . escapeshellarg( $upstream ) . ' init -q' );
		file_put_contents( $upstream . '/README.md', "# fixture\n" );
		$run( 'git -C ' . escapeshellarg( $upstream ) . ' add README.md' );
		$run( 'git -C ' . escapeshellarg( $upstream ) . ' -c user.name=Test -c user.email=test@example.com commit -q -m init' );

		$events = array();
		$clone  = $workspace_object->clone_repo(
			$upstream,
			'fixture-clone',
			array(
				'progress_callback' => function ( array $event ) use ( &$events ): void {
					$events[] = $event;
				},
			)
		);

		$assert( ! is_wp_error( $clone ), 'local clone succeeds' );
		$assert( is_dir( $workspace . '/fixture-clone/.git' ), 'clone target contains .git directory' );
		$assert( count( $events ) > 0, 'progress callback receives events' );
		$assert_same( 'start', $events[0]['phase'] ?? null, 'first progress event has start phase' );
		$assert( str_contains( $events[0]['message'] ?? '', 'Cloning' ), 'start progress includes clone context' );

		$remote_url = getenv( 'REMOTE_CLONE_URL' );
		if ( is_string( $remote_url ) && '' !== trim( $remote_url ) ) {
			echo "\n[4] Remote clone smoke\n";
			$remote_events = array();
			$remote_clone  = $workspace_object->clone_repo(
				trim( $remote_url ),
				'remote-fixture-clone',
				array(
					'progress_callback' => function ( array $event ) use ( &$remote_events ): void {
						$remote_events[] = $event;
					},
				)
			);

			$assert( ! is_wp_error( $remote_clone ), 'remote clone succeeds' );
			$assert( is_dir( $workspace . '/remote-fixture-clone/.git' ), 'remote clone target contains .git directory' );
			$assert( count( $remote_events ) > 1, 'remote clone emits progress events' );
			$assert( str_contains( $remote_events[0]['message'] ?? '', '--filter=blob:none' ), 'remote clone start event documents partial clone' );
		}
	} finally {
		$rm_rf( $root );
	}

	echo "\nResult: " . ( $total - $failures ) . "/{$total} passed\n";
	exit( $failures > 0 ? 1 : 0 );
}
