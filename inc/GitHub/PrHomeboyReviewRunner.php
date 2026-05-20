<?php
/**
 * Checkout-backed Homeboy PR review runner.
 *
 * @package DataMachineCode\GitHub
 */

namespace DataMachineCode\GitHub;

use DataMachineCode\Abilities\GitHubAbilities;
use DataMachineCode\Homeboy;
use DataMachineCode\Workspace\Workspace;

defined( 'ABSPATH' ) || exit;

final class PrHomeboyReviewRunner {

	private const SCHEMA = 'data-machine-code/pr-homeboy-review/v1';

	/** @var array<string, callable> */
	private array $callbacks;

	/**
	 * @param array<string, callable> $callbacks Test seams for pure-PHP smoke coverage.
	 */
	public function __construct( array $callbacks = array() ) {
		$this->callbacks = $callbacks;
	}

	/**
	 * Run a checkout-backed Homeboy review for one pull request.
	 *
	 * @return array|\WP_Error
	 */
	public function run( array $input ): array|\WP_Error {
		$repo        = $this->clean_repo( (string) ( $input['repo'] ?? '' ) );
		$pull_number = (int) ( $input['pull_number'] ?? $input['pr_number'] ?? 0 );
		$head_sha    = strtolower( trim( (string) ( $input['head_sha'] ?? '' ) ) );

		if ( '' === $repo || $pull_number <= 0 || '' === $head_sha ) {
			return new \WP_Error( 'missing_params', 'repo, pull_number, and head_sha are required.', array( 'status' => 400 ) );
		}

		$pull = $this->resolve_pull( $repo, $pull_number );
		if ( is_wp_error( $pull ) ) {
			return $pull;
		}

		$actual_head_sha = strtolower( (string) ( $pull['head_sha'] ?? '' ) );
		if ( '' === $actual_head_sha || $actual_head_sha !== $head_sha ) {
			return new \WP_Error(
				'head_sha_mismatch',
				sprintf( 'GitHub reports PR head %s, not requested head %s.', '' !== $actual_head_sha ? $actual_head_sha : '(missing)', $head_sha ),
				array( 'status' => 409 )
			);
		}

		$base_ref  = trim( (string) ( $input['base_ref'] ?? $pull['base_ref'] ?? 'main' ) );
		$base_ref  = '' !== $base_ref ? $base_ref : 'main';
		$repo_name = $this->repo_name( $repo );
		$branch    = sprintf( 'pr-%d-homeboy-review', $pull_number );
		$handle    = $repo_name . '@' . $branch;

		$worktree = $this->ensure_worktree( $repo, $repo_name, $branch, $base_ref );
		if ( is_wp_error( $worktree ) ) {
			return $worktree;
		}

		$path = (string) ( $worktree['path'] ?? '' );
		if ( '' === $path ) {
			return new \WP_Error( 'worktree_path_missing', 'Workspace did not return a worktree path.', array( 'status' => 500 ) );
		}

		$checkout = $this->checkout_head( $path, $pull_number, $head_sha, $base_ref );
		if ( is_wp_error( $checkout ) ) {
			return $checkout;
		}

		$commands = $this->run_homeboy_commands( $repo_name, $path, $base_ref );
		$status   = $this->overall_status( $commands );

		return array(
			'success'     => true,
			'schema'      => self::SCHEMA,
			'repo'        => $repo,
			'pull_number' => $pull_number,
			'head_sha'    => $head_sha,
			'base_ref'    => $base_ref,
			'status'      => $status,
			'commands'    => $commands,
			'worktree'    => array(
				'created' => ! empty( $worktree['created'] ),
				'path'    => $path,
				'handle'  => (string) ( $worktree['handle'] ?? $handle ),
			),
			'checkout'    => $checkout,
		);
	}

	private function clean_repo( string $repo ): string {
		$repo = trim( $repo );
		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : '';
	}

	private function repo_name( string $repo ): string {
		$normalized = preg_replace( '/[^A-Za-z0-9_.-]+/', '-', basename( $repo ) );
		return strtolower( is_string( $normalized ) && '' !== $normalized ? $normalized : basename( $repo ) );
	}

	private function resolve_pull( string $repo, int $pull_number ): array|\WP_Error {
		if ( isset( $this->callbacks['pull_resolver'] ) ) {
			$result = ( $this->callbacks['pull_resolver'] )( $repo, $pull_number );
			return is_array( $result ) && isset( $result['pull'] ) ? $result['pull'] : $result;
		}

		$result = GitHubAbilities::getPull(
			array(
				'repo'        => $repo,
				'pull_number' => $pull_number,
			)
		);

		return is_wp_error( $result ) ? $result : (array) ( $result['pull'] ?? array() );
	}

	private function ensure_worktree( string $repo, string $repo_name, string $branch, string $base_ref ): array|\WP_Error {
		if ( isset( $this->callbacks['worktree_resolver'] ) ) {
			return ( $this->callbacks['worktree_resolver'] )( $repo, $repo_name, $branch, $base_ref );
		}

		$workspace = new Workspace();
		$handle    = $repo_name . '@' . $branch;
		$path      = $workspace->get_repo_path( $handle );

		if ( is_dir( $path ) ) {
			return array(
				'success' => true,
				'created' => false,
				'handle'  => $handle,
				'path'    => $path,
			);
		}

		$primary_path = $workspace->get_repo_path( $repo_name );
		if ( ! is_dir( $primary_path ) ) {
			$clone = $workspace->clone_repo( 'https://github.com/' . $repo . '.git', $repo_name );
			if ( is_wp_error( $clone ) ) {
				return $clone;
			}
		}

		$created = $workspace->worktree_add( $repo_name, $branch, $base_ref, false, true, true );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$created['created'] = true;
		return $created;
	}

	private function checkout_head( string $path, int $pull_number, string $head_sha, string $base_ref ): array|\WP_Error {
		$steps = array(
			array( 'fetch_pr', sprintf( 'git fetch origin %s', escapeshellarg( 'pull/' . $pull_number . '/head' ) ) ),
			array( 'fetch_base', sprintf( 'git fetch origin %s', escapeshellarg( 'refs/heads/' . $base_ref . ':refs/remotes/origin/' . $base_ref ) ) ),
			array( 'checkout_head', sprintf( 'git checkout --detach %s', escapeshellarg( $head_sha ) ) ),
		);

		foreach ( $steps as $step ) {
			$result = $this->run_git( $path, $step[1] );
			if ( 0 !== (int) ( $result['exit_code'] ?? 1 ) ) {
				return new \WP_Error( 'git_' . $step[0] . '_failed', sprintf( 'Git %s failed: %s', $step[0], trim( (string) ( $result['output'] ?? '' ) ) ), array( 'status' => 500 ) );
			}
		}

		$actual = $this->run_git( $path, 'git rev-parse HEAD' );
		$sha    = strtolower( trim( (string) ( $actual['output'] ?? '' ) ) );
		if ( 0 !== (int) ( $actual['exit_code'] ?? 1 ) || $sha !== $head_sha ) {
			return new \WP_Error( 'checkout_head_mismatch', sprintf( 'Checked out %s, not requested head %s.', '' !== $sha ? $sha : '(missing)', $head_sha ), array( 'status' => 409 ) );
		}

		return array(
			'head_sha' => $sha,
			'base_ref' => $base_ref,
		);
	}

	private function run_homeboy_commands( string $repo_name, string $path, string $base_ref ): array {
		if ( ! $this->homeboy_available() ) {
			return array(
				array(
					'name'   => 'homeboy',
					'status' => 'skipped',
					'reason' => 'homeboy_unavailable',
				),
			);
		}

		$commands = array(
			array( 'lint', sprintf( 'homeboy lint %s --path %s', escapeshellarg( $repo_name ), escapeshellarg( $path ) ) ),
			array( 'test', sprintf( 'homeboy test %s --path %s', escapeshellarg( $repo_name ), escapeshellarg( $path ) ) ),
			array( 'audit', sprintf( 'homeboy audit %s --path %s --changed-since %s', escapeshellarg( $repo_name ), escapeshellarg( $path ), escapeshellarg( 'origin/' . $base_ref ) ) ),
		);

		$results = array();
		foreach ( $commands as $command ) {
			$result    = $this->run_command( $command[1], $path );
			$exit_code = (int) ( $result['exit_code'] ?? 1 );
			$results[] = array(
				'name'      => $command[0],
				'command'   => $command[1],
				'status'    => 0 === $exit_code ? 'passed' : 'failed',
				'exit_code' => $exit_code,
				'output'    => $this->bound_output( (string) ( $result['output'] ?? '' ) ),
				'truncated' => strlen( (string) ( $result['output'] ?? '' ) ) > 4000,
			);
		}

		return $results;
	}

	private function homeboy_available(): bool {
		if ( isset( $this->callbacks['homeboy_available'] ) ) {
			return (bool) ( $this->callbacks['homeboy_available'] )();
		}

		return Homeboy::is_available();
	}

	private function overall_status( array $commands ): string {
		if ( empty( $commands ) ) {
			return 'skipped';
		}

		$statuses = array_column( $commands, 'status' );
		if ( ! in_array( 'failed', $statuses, true ) && ! in_array( 'skipped', $statuses, true ) ) {
			return 'passed';
		}

		if ( in_array( 'failed', $statuses, true ) && in_array( 'passed', $statuses, true ) ) {
			return 'partial';
		}

		if ( in_array( 'failed', $statuses, true ) ) {
			return 'failed';
		}

		return 'skipped';
	}

	private function run_git( string $path, string $command ): array {
		return $this->run_command( sprintf( 'git -C %s %s', escapeshellarg( $path ), preg_replace( '/^git\s+/', '', $command ) ), $path );
	}

	private function run_command( string $command, string $path ): array {
		if ( isset( $this->callbacks['command_runner'] ) ) {
			return ( $this->callbacks['command_runner'] )( $command, $path );
		}

		$output = array();
		$exit   = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Required host-side checkout/test execution primitive.
		exec( $command . ' 2>&1', $output, $exit );

		return array(
			'exit_code' => $exit,
			'output'    => implode( "\n", $output ),
		);
	}

	private function bound_output( string $output ): string {
		if ( strlen( $output ) <= 4000 ) {
			return $output;
		}

		return substr( $output, 0, 4000 );
	}
}
