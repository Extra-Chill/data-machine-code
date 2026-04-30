<?php
/**
 * Smoke test for evidence-packet code-task scaffolding.
 *
 *   php tests/smoke-code-task-evidence-packet.php
 *
 * @package DataMachineCode\Tests
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ );
	}

	class WP_Error {
		private string $code;
		private string $message;
		private array $data;

		public function __construct( string $code, string $message, array $data = array() ) {
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

		public function get_error_data(): array {
			return $this->data;
		}
	}

	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	function sanitize_text_field( $value ): string {
		return trim( preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( (string) $value ) ) );
	}

	function sanitize_textarea_field( $value ): string {
		return trim( wp_strip_all_tags( (string) $value ) );
	}

	function sanitize_key( $value ): string {
		$value = strtolower( (string) $value );
		return preg_replace( '/[^a-z0-9_-]/', '', $value );
	}

	function esc_url_raw( $value ): string {
		return (string) $value;
	}

	function wp_http_validate_url( $url ): string|false {
		$parts = parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		return in_array( $parts['scheme'], array( 'http', 'https' ), true ) ? (string) $url : false;
	}

	function wp_strip_all_tags( $value ): string {
		return strip_tags( (string) $value );
	}

	function wp_json_encode( $data, int $flags = 0 ): string|false {
		return json_encode( $data, $flags );
	}
}

namespace DataMachineCode\Workspace {
	class Workspace {}
	class WorkspaceWriter {}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/CodeTask/EvidencePacket.php';
	require_once dirname( __DIR__ ) . '/inc/CodeTask/CodeTaskWorkspaceInterface.php';
	require_once dirname( __DIR__ ) . '/inc/CodeTask/CodeTaskEvidenceWriterInterface.php';
	require_once dirname( __DIR__ ) . '/inc/CodeTask/CodeTaskCreator.php';

	function datamachine_code_assert( bool $condition, string $message ): void {
		if ( $condition ) {
			echo "  [PASS] {$message}\n";
			return;
		}

		echo "  [FAIL] {$message}\n";
		exit( 1 );
	}

	function datamachine_code_packet(): array {
		return array(
			'source'          => 'wporg/trac',
			'source_url'      => 'https://core.trac.wordpress.org/ticket/50781',
			'title'           => '500 error caused by customize_changeset_uuid for non-authenticated users',
			'summary'         => 'Unauthenticated requests with customize_changeset_uuid can trigger a 500.',
			'classification'  => array( 'high_confidence_bug', 'has_repro', 'code_cookable' ),
			'repo'            => 'WordPress/wordpress-develop',
			'suggested_tests' => array( 'request/query-param regression test' ),
			'constraints'     => array( 'do not change public behavior beyond invalid unauthenticated changeset handling' ),
		);
	}

	class FakeCodeTaskWorkspace implements \DataMachineCode\CodeTask\CodeTaskWorkspaceInterface {
		public string $primary_path;
		public array $clones = array();
		public array $worktrees = array();

		public function __construct( string $primary_path ) {
			$this->primary_path = $primary_path;
		}

		public function workspace(): \DataMachineCode\Workspace\Workspace {
			return new \DataMachineCode\Workspace\Workspace();
		}

		public function get_primary_path( string $repo ): string {
			return $this->primary_path;
		}

		public function clone_repo( string $url, string $name ): array {
			$this->clones[] = compact( 'url', 'name' );
			mkdir( $this->primary_path, 0755, true );
			return array( 'success' => true, 'name' => $name, 'path' => $this->primary_path );
		}

		public function worktree_add( string $repo, string $branch, ?string $from, bool $inject_context, bool $bootstrap, bool $allow_stale, bool $rebase_base, bool $force ): array {
			$this->worktrees[] = compact( 'repo', 'branch', 'from', 'inject_context', 'bootstrap', 'allow_stale', 'rebase_base', 'force' );
			return array(
				'success' => true,
				'handle'  => $repo . '@' . str_replace( '/', '-', $branch ),
				'path'    => dirname( $this->primary_path ) . '/' . $repo . '@' . str_replace( '/', '-', $branch ),
				'branch'  => $branch,
			);
		}

		public function get_repo_path( string $handle ): string {
			return dirname( $this->primary_path ) . '/' . $handle;
		}
	}

	class FakeCodeTaskWriter implements \DataMachineCode\CodeTask\CodeTaskEvidenceWriterInterface {
		public array $writes = array();

		public function write_file( string $handle, string $path, string $content ): array {
			$this->writes[] = compact( 'handle', 'path', 'content' );
			return array( 'success' => true, 'path' => $path, 'size' => strlen( $content ), 'created' => true );
		}
	}

	echo "=== smoke-code-task-evidence-packet ===\n";

	echo "\n[1] packet parsing normalizes required fields\n";
	$packet = \DataMachineCode\CodeTask\EvidencePacket::from_array( datamachine_code_packet() );
	datamachine_code_assert( ! is_wp_error( $packet ), 'valid packet parses' );
	datamachine_code_assert( 'WordPress/wordpress-develop' === $packet->repo(), 'repo field preserved' );
	datamachine_code_assert( array( 'high_confidence_bug', 'has_repro', 'code_cookable' ) === $packet->classification(), 'classification list preserved' );

	echo "\n[2] invalid packets fail safely\n";
	$missing = datamachine_code_packet();
	unset( $missing['repo'] );
	$error = \DataMachineCode\CodeTask\EvidencePacket::from_array( $missing );
	datamachine_code_assert( is_wp_error( $error ), 'missing repo returns WP_Error' );
	datamachine_code_assert( 'missing_packet_field' === $error->get_error_code(), 'missing field error code is stable' );
	$bad_repo = \DataMachineCode\CodeTask\CodeTaskCreator::resolve_repo( 'ssh://github.com/WordPress/wordpress-develop' );
	datamachine_code_assert( is_wp_error( $bad_repo ), 'arbitrary protocols are rejected' );
	datamachine_code_assert( 'invalid_repo' === $bad_repo->get_error_code(), 'invalid repo error code is stable' );

	echo "\n[3] repo and branch output are deterministic\n";
	$repo = \DataMachineCode\CodeTask\CodeTaskCreator::resolve_repo( 'https://github.com/WordPress/wordpress-develop.git' );
	datamachine_code_assert( ! is_wp_error( $repo ), 'GitHub https repo resolves' );
	datamachine_code_assert( 'WordPress/wordpress-develop' === $repo['slug'], 'resolved slug strips .git' );
	datamachine_code_assert( 'wordpress-develop' === $repo['name'], 'workspace repo name uses basename' );
	datamachine_code_assert( 'https://github.com/WordPress/wordpress-develop.git' === $repo['url'], 'clone URL is normalized' );
	$branch = \DataMachineCode\CodeTask\CodeTaskCreator::build_branch_name( $packet );
	datamachine_code_assert( str_starts_with( $branch, 'code-task/wporg-trac-500-error-caused-by-customize-changeset-uuid' ), 'branch includes source and title slug' );
	datamachine_code_assert( $branch === \DataMachineCode\CodeTask\CodeTaskCreator::build_branch_name( $packet ), 'branch is deterministic' );
	datamachine_code_assert( ! str_contains( $branch, ' ' ), 'branch contains no spaces' );

	echo "\n[4] prompt is deterministic and source-linked\n";
	$prompt = \DataMachineCode\CodeTask\CodeTaskCreator::render_prompt( $packet, $repo, $branch );
	datamachine_code_assert( str_contains( $prompt, '# Code Task Evidence' ), 'prompt has fixed title' );
	datamachine_code_assert( str_contains( $prompt, 'https://core.trac.wordpress.org/ticket/50781' ), 'prompt preserves source URL' );
	datamachine_code_assert( str_contains( $prompt, '- request/query-param regression test' ), 'prompt includes suggested tests' );
	datamachine_code_assert( $prompt === \DataMachineCode\CodeTask\CodeTaskCreator::render_prompt( $packet, $repo, $branch ), 'prompt rendering is deterministic' );

	echo "\n[5] creator clones missing repos, creates worktree, writes evidence files\n";
	$root      = sys_get_temp_dir() . '/dmc-code-task-' . uniqid( '', true );
	$workspace = new FakeCodeTaskWorkspace( $root . '/wordpress-develop' );
	$writer    = new FakeCodeTaskWriter();
	$creator   = new \DataMachineCode\CodeTask\CodeTaskCreator( $workspace, $writer );
	$result    = $creator->create( $packet, array( 'base_ref' => 'origin/trunk', 'allow_stale' => true, 'force' => true ) );
	datamachine_code_assert( ! is_wp_error( $result ), 'creator succeeds with fake workspace' );
	datamachine_code_assert( true === $result['cloned'], 'missing primary repo is cloned' );
	datamachine_code_assert( 'https://github.com/WordPress/wordpress-develop.git' === $workspace->clones[0]['url'], 'clone uses validated GitHub URL' );
	datamachine_code_assert( 'origin/trunk' === $workspace->worktrees[0]['from'], 'base ref forwarded to worktree add' );
	datamachine_code_assert( true === $workspace->worktrees[0]['allow_stale'], 'allow_stale forwarded' );
	datamachine_code_assert( true === $workspace->worktrees[0]['force'], 'force forwarded' );
	datamachine_code_assert( 2 === count( $writer->writes ), 'prompt and packet files are written' );
	datamachine_code_assert( '.datamachine-code/code-task.md' === $writer->writes[0]['path'], 'prompt path is deterministic' );
	datamachine_code_assert( '.datamachine-code/evidence-packet.json' === $writer->writes[1]['path'], 'packet path is deterministic' );
	datamachine_code_assert( str_contains( $writer->writes[1]['content'], '"source_url": "https://core.trac.wordpress.org/ticket/50781"' ), 'packet JSON preserves source URL' );
	datamachine_code_assert( str_contains( $result['pr_body_seed'], '## Source evidence' ), 'result includes PR body seed' );

	echo "\nAll code-task evidence packet smoke tests passed.\n";
}
