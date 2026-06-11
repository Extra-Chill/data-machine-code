<?php
/**
 * Canonical runner-owned workspace publication orchestration.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Abilities\GitHubAbilities;
use DataMachineCode\Abilities\WorkspaceAbilities;

defined('ABSPATH') || exit;

class RunnerWorkspacePublisher {

	/**
	 * Publish runner-owned workspace changes to a branch and pull request.
	 *
	 * @param array<string,mixed> $input Publication input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function publish( array $input ): array|\WP_Error {
		$handle = trim( (string) ( $input['workspace_handle'] ?? $input['name'] ?? $input['repo'] ?? '' ) );
		if ( '' === $handle ) {
			return new \WP_Error('runner_workspace_publish_missing_handle', 'workspace_handle is required.', array( 'status' => 400 ));
		}

		$target_repo = trim( (string) ( $input['target_repo'] ?? $input['github_repo'] ?? $input['repository'] ?? '' ) );
		if ( '' === $target_repo ) {
			return new \WP_Error('runner_workspace_publish_missing_target_repo', 'target_repo in owner/repo format is required.', array( 'status' => 400 ));
		}

		$commit_message = trim( (string) ( $input['commit_message'] ?? '' ) );
		if ( '' === $commit_message ) {
			return new \WP_Error('runner_workspace_publish_missing_commit_message', 'commit_message is required.', array( 'status' => 400 ));
		}

		$pr_title = trim( (string) ( $input['pr_title'] ?? $input['title'] ?? '' ) );
		if ( '' === $pr_title ) {
			return new \WP_Error('runner_workspace_publish_missing_pr_title', 'pr_title is required.', array( 'status' => 400 ));
		}

		$branch = $this->resolve_head_branch($input, $handle);
		if ( is_wp_error($branch) ) {
			return $branch;
		}

		$base = trim( (string) ( $input['base'] ?? $input['base_branch'] ?? $input['base_ref'] ?? '' ) );
		$body = $this->build_pull_request_body( (string) ( $input['pr_body'] ?? $input['body'] ?? '' ), $input );

		$status = WorkspaceAbilities::gitStatus(array( 'name' => $handle ));
		if ( is_wp_error($status) ) {
			return $this->publication_error('runner_workspace_publish_status_failed', 'Could not inspect workspace status.', $status);
		}

		$commit = null;
		if ( (int) ( $status['dirty'] ?? 0 ) > 0 ) {
			$add = WorkspaceAbilities::gitAdd(
				array(
					'name'                   => $handle,
					'paths'                  => $this->normalize_paths($input['paths'] ?? array( '.' )),
					'allow_primary_mutation' => ! empty($input['allow_primary_mutation']),
				)
			);
			if ( is_wp_error($add) ) {
				return $this->publication_error('runner_workspace_publish_stage_failed', 'Could not stage workspace changes.', $add);
			}

			$commit = WorkspaceAbilities::gitCommit(
				array(
					'name'                   => $handle,
					'message'                => $commit_message,
					'allow_primary_mutation' => ! empty($input['allow_primary_mutation']),
				)
			);
			if ( is_wp_error($commit) ) {
				return $this->publication_error('runner_workspace_publish_commit_failed', 'Could not commit workspace changes.', $commit);
			}
		}

		$push_input = array(
			'name'                   => $handle,
			'branch'                 => $branch,
			'allow_primary_mutation' => ! empty($input['allow_primary_mutation']),
			'force_with_lease'       => ! empty($input['force_with_lease']),
		);
		foreach ( array( 'remote', 'expected_sha' ) as $key ) {
			if ( isset($input[ $key ]) && '' !== trim( (string) $input[ $key ] ) ) {
				$push_input[ $key ] = $input[ $key ];
			}
		}

		$push = WorkspaceAbilities::gitPush($push_input);
		if ( is_wp_error($push) ) {
			return $this->publication_error('runner_workspace_publish_push_failed', 'Could not push workspace branch.', $push);
		}

		$pr_input = array(
			'repo'  => $target_repo,
			'title' => $pr_title,
			'head'  => $this->resolve_pr_head($input, $branch),
			'body'  => $body,
		);
		if ( '' !== $base ) {
			$pr_input['base'] = $base;
		}
		foreach ( array( 'draft', 'maintainer_can_modify' ) as $key ) {
			if ( array_key_exists($key, $input) ) {
				$pr_input[ $key ] = (bool) $input[ $key ];
			}
		}
		if ( isset($input['labels']) && is_array($input['labels']) ) {
			$pr_input['labels'] = array_values(array_filter(array_map('strval', $input['labels'])));
		}
		foreach ( array( 'run_artifacts', 'run_artifact_policy', 'artifact_context', 'evidence_context' ) as $key ) {
			if ( array_key_exists($key, $input) ) {
				$pr_input[ $key ] = $input[ $key ];
			}
		}

		$pull = GitHubAbilities::createPullRequest($pr_input);
		if ( is_wp_error($pull) ) {
			return $this->publication_error('runner_workspace_publish_pr_failed', 'Could not open or reuse pull request.', $pull);
		}

		return array(
			'success'      => true,
			'kind'         => 'runner_workspace_publication',
			'workspace'    => array(
				'handle'  => $handle,
				'backend' => (string) ( $push['backend'] ?? ( $status['backend'] ?? 'local_git' ) ),
			),
			'branch'       => array(
				'name'   => (string) ( $push['branch'] ?? $branch ),
				'base'   => '' !== $base ? $base : null,
				'head'   => (string) $pr_input['head'],
				'remote' => (string) ( $push['remote'] ?? 'origin' ),
				'url'    => $push['html_url'] ?? $push['url'] ?? null,
				'push'   => $push,
			),
			'commit'       => array(
				'created' => null !== $commit,
				'sha'     => $this->extract_commit_sha($commit, $push),
				'result'  => $commit,
			),
			'pull_request' => array(
				'repo'   => (string) ( $pull['repo'] ?? $target_repo ),
				'number' => (int) ( $pull['pull_number'] ?? $pull['number'] ?? 0 ),
				'url'    => (string) ( $pull['html_url'] ?? $pull['url'] ?? '' ),
				'reused' => ! empty($pull['reused']),
				'opened' => empty($pull['reused']),
				'result' => $pull,
			),
			'evidence'     => array(
				'context' => $input['evidence_context'] ?? $input['artifact_context'] ?? null,
			),
			'message'      => ! empty($pull['reused']) ? 'Runner workspace publication reused an existing pull request.' : 'Runner workspace publication opened a pull request.',
		);
	}

	/**
	 * @param array<string,mixed> $input
	 */
	private function resolve_head_branch( array $input, string $handle ): string|\WP_Error {
		foreach ( array( 'head', 'head_branch', 'head_ref', 'branch' ) as $key ) {
			$branch = trim( (string) ( $input[ $key ] ?? '' ) );
			if ( '' !== $branch ) {
				return str_contains($branch, ':') ? substr($branch, (int) strpos($branch, ':') + 1) : $branch;
			}
		}

		if ( str_contains($handle, '@') ) {
			$slug = substr($handle, (int) strpos($handle, '@') + 1);
			if ( '' !== $slug ) {
				return $slug;
			}
		}

		return new \WP_Error('runner_workspace_publish_missing_head_branch', 'A head branch/ref or branch context is required.', array( 'status' => 400 ));
	}

	/**
	 * @param array<string,mixed> $input
	 */
	private function resolve_pr_head( array $input, string $branch ): string {
		$head = trim( (string) ( $input['head'] ?? $input['head_ref'] ?? '' ) );
		return '' !== $head ? $head : $branch;
	}

	/**
	 * @param mixed $paths
	 * @return array<int,string>
	 */
	private function normalize_paths( mixed $paths ): array {
		if ( ! is_array($paths) ) {
			$paths = array( $paths );
		}

		$normalized = array_values(array_filter(array_map(static fn( mixed $path ): string => trim( (string) $path ), $paths)));
		return empty($normalized) ? array( '.' ) : $normalized;
	}

	/**
	 * @param array<string,mixed> $input
	 */
	private function build_pull_request_body( string $body, array $input ): string {
		$context = $input['evidence_context'] ?? $input['artifact_context'] ?? null;
		if ( empty($context) ) {
			return $body;
		}

		if ( function_exists('wp_json_encode') ) {
			$encoded = wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- fallback for standalone smoke tests outside WordPress.
			$encoded = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		}

		if ( ! is_string($encoded) ) {
			return $body;
		}

		$section = "\n\n## Evidence context\n```json\n" . $encoded . "\n```\n";
		return rtrim($body) . $section;
	}

	/**
	 * @param array<string,mixed>|null $commit
	 * @param array<string,mixed>      $push
	 */
	private function extract_commit_sha( ?array $commit, array $push ): ?string {
		if ( is_array($commit) && ! empty($commit['commit']) ) {
			if ( preg_match('/\b[0-9a-f]{7,40}\b/i', (string) $commit['commit'], $matches) ) {
				return $matches[0];
			}
		}

		return isset($push['commit']) && '' !== (string) $push['commit'] ? (string) $push['commit'] : null;
	}

	private function publication_error( string $code, string $message, \WP_Error $previous ): \WP_Error {
		$data = $previous->get_error_data();
		return new \WP_Error(
			$code,
			$message . ' ' . $previous->get_error_message(),
			array(
				'success'        => false,
				'failure_type'   => $code,
				'previous_code'  => $previous->get_error_code(),
				'previous_error' => $previous->get_error_message(),
				'previous_data'  => is_array($data) ? $data : array(),
				'status'         => is_array($data) ? ( $data['status'] ?? 500 ) : 500,
			)
		);
	}
}
