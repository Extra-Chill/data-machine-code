<?php
/**
 * Pure-PHP smoke for the GitHub-backed remote workspace backend.
 *
 * Run: php tests/smoke-remote-workspace-backend.php
 */

declare( strict_types=1 );

namespace DataMachineCode\Abilities {
	class GitHubAbilities {
		public static array $files = array(
			'chubes4/example:main:src/example.php' => "<?php\nreturn 'old';\n",
		);
		public static array $commits = array();

		public static function getFileContents( array $input ): array|\WP_Error {
			$repo = (string) ( $input['repo'] ?? '' );
			$path = (string) ( $input['path'] ?? '' );
			$ref  = (string) ( $input['ref'] ?? 'main' );
			$key  = $repo . ':' . $ref . ':' . $path;
			if ( ! isset( self::$files[ $key ] ) ) {
				return new \WP_Error( 'github_not_found', 'Not found.', array( 'status' => 404 ) );
			}
			return array(
				'success' => true,
				'file'    => array(
					'path'    => $path,
					'size'    => strlen( self::$files[ $key ] ),
					'content' => self::$files[ $key ],
				),
			);
		}

		public static function getRepoTree( array $input ): array|\WP_Error {
			return array(
				'success' => true,
				'files'   => array(
					array( 'path' => 'src/example.php', 'type' => 'file', 'size' => 20 ),
				),
			);
		}

		public static function createOrUpdateFile( array $input ): array|\WP_Error {
			$repo = (string) ( $input['repo'] ?? '' );
			$path = (string) ( $input['file_path'] ?? '' );
			$branch = (string) ( $input['branch'] ?? 'main' );
			$sha = 'commit-' . ( count( self::$commits ) + 1 );
			self::$files[ $repo . ':' . $branch . ':' . $path ] = (string) ( $input['content'] ?? '' );
			self::$commits[] = array( 'sha' => $sha, 'message' => (string) ( $input['commit_message'] ?? '' ) );
			return array(
				'success' => true,
				'commit'  => array( 'sha' => $sha, 'html_url' => 'https://github.com/' . $repo . '/commit/' . $sha ),
				'content' => array( 'path' => $path ),
			);
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/dmc-remote-workspace-backend/' );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( private string $code, private string $message, private array $data = array() ) {}

			public function get_error_code(): string { return $this->code; }
			public function get_error_message(): string { return $this->message; }
			public function get_error_data(): array { return $this->data; }
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
	}

	$GLOBALS['dmc_remote_workspace_options'] = array();
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $key, mixed $default = false ): mixed {
			return $GLOBALS['dmc_remote_workspace_options'][ $key ] ?? $default;
		}
	}
	if ( ! function_exists( 'update_option' ) ) {
		function update_option( string $key, mixed $value, bool $autoload = true ): bool {
			unset( $autoload );
			$GLOBALS['dmc_remote_workspace_options'][ $key ] = $value;
			return true;
		}
	}

	require __DIR__ . '/../inc/Support/GitRunner.php';
	require __DIR__ . '/../inc/Workspace/RemoteWorkspaceBackend.php';

	use DataMachineCode\Abilities\GitHubAbilities;
	use DataMachineCode\Workspace\RemoteWorkspaceBackend;

	$failures = array();
	$total    = 0;
	$assert   = function ( string $label, bool $condition ) use ( &$failures, &$total ): void {
		++$total;
		if ( $condition ) {
			echo "  ok {$label}\n";
			return;
		}
		$failures[] = $label;
		echo "  fail {$label}\n";
	};

	echo "Remote workspace backend - smoke\n";

	$backend = new RemoteWorkspaceBackend();
	$clone = $backend->clone_repo( 'https://github.com/chubes4/example.git' );
	$assert( 'clone registers remote repo', ! is_wp_error( $clone ) && 'example' === $clone['name'] && 'github_api' === $clone['backend'] );

	$worktree = $backend->worktree_add( 'example', 'fix/example' );
	$assert( 'worktree add returns DMC handle', ! is_wp_error( $worktree ) && 'example@fix-example' === $worktree['handle'] );

	$read = $backend->read_file( 'example@fix-example', 'src/example.php', 1000000 );
	$assert( 'read falls back to default branch content', ! is_wp_error( $read ) && str_contains( $read['content'], 'old' ) );

	$edit = $backend->edit_file( 'example@fix-example', 'src/example.php', 'old', 'new' );
	$assert( 'edit stages pending content', ! is_wp_error( $edit ) && 1 === $edit['replacements'] );

	$status = $backend->git_status( 'example@fix-example' );
	$assert( 'status reports pending file as dirty', ! is_wp_error( $status ) && 1 === $status['dirty'] && array( 'src/example.php' ) === $status['files'] );

	$commit = $backend->git_commit( 'example@fix-example', 'fix: update example' );
	$assert( 'commit writes via GitHub API', ! is_wp_error( $commit ) && 'commit-1' === $commit['commit'] );
	$assert( 'commit uses requested message', 'fix: update example' === GitHubAbilities::$commits[0]['message'] );

	$push = $backend->git_push( 'example@fix-example' );
	$assert( 'push is successful compatibility no-op', ! is_wp_error( $push ) && 'fix/example' === $push['branch'] );

	if ( ! empty( $failures ) ) {
		echo "\nFAIL: " . count( $failures ) . " assertion(s) failed out of {$total}\n";
		foreach ( $failures as $failure ) {
			echo "  - {$failure}\n";
		}
		exit( 1 );
	}

	echo "\nOK ({$total} assertions)\n";
	exit( 0 );
}
