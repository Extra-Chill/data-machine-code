<?php
/**
 * Typed Homeboy promotion patch adapter.
 *
 * @package DataMachineCode\Cli
 */

namespace DataMachineCode\Cli;

use DataMachineCode\Workspace\Workspace;
use DataMachineCode\Workspace\WorkspaceWriter;

defined('ABSPATH') || exit;

final class HomeboyPromotionApplyAdapter {

	private const REQUEST_SCHEMA = 'homeboy/agent-task-promotion-apply-request/v1';
	private const RESPONSE_SCHEMA = 'homeboy/agent-task-promotion-apply-response/v1';
	private const EVIDENCE_LIMIT = 1024;

	public function __construct( private object $workspace, private object $writer ) {
	}

	public static function create(): self {
		$workspace = new Workspace();
		return new self($workspace, new WorkspaceWriter($workspace));
	}

	/**
	 * @param array<string,mixed> $request
	 * @return array<string,mixed>|\WP_Error
	 */
	public function execute( string $expected_handle, array $request ): array|\WP_Error {
		$expected_handle = trim($expected_handle);
		if ( '' === $expected_handle ) {
			return $this->error('missing_expected_handle', 'The expected managed worktree handle is required.');
		}
		if ( self::REQUEST_SCHEMA !== ( $request['schema'] ?? null ) ) {
			return $this->error('invalid_promotion_schema', 'Request schema must be ' . self::REQUEST_SCHEMA . '.');
		}
		if ( $expected_handle !== ( $request['to_workspace'] ?? null ) ) {
			return $this->error('promotion_handle_mismatch', 'Request to_workspace must exactly match the expected handle.');
		}
		if ( ! is_string($request['patch_path'] ?? null) || '' === trim($request['patch_path']) ) {
			return $this->error('invalid_patch_path', 'Request patch_path must be a non-empty string.');
		}
		if ( ! is_array($request['changed_files'] ?? null) || empty($request['changed_files']) || array_filter($request['changed_files'], static fn( $path ): bool => ! is_string($path) || '' === trim($path)) ) {
			return $this->error('invalid_changed_files', 'Request changed_files must be a non-empty list of paths.');
		}
		if ( ! is_bool($request['dry_run'] ?? null) ) {
			return $this->error('invalid_dry_run', 'Request dry_run must be a boolean.');
		}

		$target = $this->resolve_target($expected_handle, $request);
		if ( is_wp_error($target) ) {
			return $target;
		}
		$patch = $this->resolve_patch($request);
		if ( is_wp_error($patch) ) {
			return $patch;
		}

		$preflight = $this->writer->apply_patch($expected_handle, $patch, false, true);
		if ( is_wp_error($preflight) ) {
			return $preflight;
		}
		$expected_files = $this->normalized_paths($request['changed_files']);
		$actual_files   = $this->normalized_paths((array) ( $preflight['changed_files'] ?? array() ));
		if ( $expected_files !== $actual_files ) {
			return $this->error('changed_files_mismatch', 'Request changed_files must exactly match the patch paths.');
		}
		$result = $preflight;
		if ( ! $request['dry_run'] ) {
			$result = $this->writer->apply_patch($expected_handle, $patch);
			if ( is_wp_error($result) ) {
				return $result;
			}
		}

		return array(
			'schema'           => self::RESPONSE_SCHEMA,
			'workspace_path'   => (string) $target['path'],
			'command_evidence' => $this->evidence((bool) $request['dry_run'], (string) ( $result['check_output'] ?? '' ), (string) ( $result['apply_output'] ?? '' )),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function resolve_target( string $handle, array $request ): array|\WP_Error {
		$parsed = $this->workspace->parse_handle($handle);
		if ( empty($parsed['is_worktree']) ) {
			return $this->error('primary_worktree', 'Promotion apply only permits managed non-primary worktrees.');
		}
		$target = $this->workspace->managed_worktree_get($handle);
		if ( is_wp_error($target) ) {
			return $target;
		}
		if ( $handle !== ( $target['handle'] ?? null ) || empty($target['is_worktree']) || ! empty($target['is_primary']) || ! empty($target['external']) || ! is_string($target['path'] ?? null) ) {
			return $this->error('unmanaged_worktree', 'The requested handle is not an exact DMC-managed worktree registry entry.');
		}
		if ( 0 !== (int) ( $target['dirty'] ?? -1 ) ) {
			return $this->error('dirty_worktree', 'Promotion apply refuses a dirty destination worktree.');
		}
		if ( (int) ( $target['unpushed'] ?? 0 ) > 0 && ! $this->is_trusted_unpushed_target($target, $request['trusted_unpushed_candidate_destination'] ?? null) ) {
			return $this->error('untrusted_unpushed_target', 'Promotion apply refuses unpushed commits unless the request attests to this exact destination path and HEAD.');
		}

		return $target;
	}

	/** @param array<string,mixed> $target */
	private function is_trusted_unpushed_target( array $target, mixed $candidate ): bool {
		return is_array($candidate)
			&& ( $target['path'] ?? null ) === ( $candidate['path'] ?? null )
			&& ( $target['head'] ?? null ) === ( $candidate['head'] ?? null );
	}

	/** @param array<string,mixed> $request */
	private function resolve_patch( array $request ): string|\WP_Error {
		if ( isset($request['patch']) ) {
			if ( ! is_string($request['patch']) || '' === trim($request['patch']) ) {
				return $this->error('invalid_patch', 'Request patch must be a non-empty unified diff when supplied.');
			}
			return $request['patch'];
		}
		$patch_path = realpath($request['patch_path']);
		if ( false === $patch_path || ! is_file($patch_path) || ! is_readable($patch_path) ) {
			return $this->error('invalid_patch_path', 'Request patch_path must resolve to a readable regular file when patch is omitted.');
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$patch = file_get_contents($patch_path);
		return false === $patch || '' === trim($patch) ? $this->error('invalid_patch_path', 'Request patch_path did not contain a patch.') : $patch;
	}

	/** @param array<int,mixed> $paths @return array<int,string> */
	private function normalized_paths( array $paths ): array {
		$paths = array_map(static fn( $path ): string => ltrim(str_replace('\\', '/', trim((string) $path)), '/'), $paths);
		$paths = array_values(array_unique(array_filter($paths)));
		sort($paths);
		return $paths;
	}

	/** @return array<int,array<string,mixed>> */
	private function evidence( bool $dry_run, string $check_output, string $apply_output ): array {
		$evidence = array( $this->command_evidence(array( 'git', 'apply', '--check', '--whitespace=nowarn', '<patch>' ), $check_output) );
		if ( ! $dry_run ) {
			$evidence[] = $this->command_evidence(array( 'git', 'apply', '--whitespace=nowarn', '<patch>' ), $apply_output);
		}
		return $evidence;
	}

	/** @param array<int,string> $command @return array<string,mixed> */
	private function command_evidence( array $command, string $stdout ): array {
		return array( 'command' => $command, 'exit_code' => 0, 'stdout' => substr($stdout, 0, self::EVIDENCE_LIMIT), 'stderr' => '' );
	}

	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error($code, $message, array( 'status' => 400 ));
	}
}
