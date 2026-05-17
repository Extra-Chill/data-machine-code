<?php
/**
 * Agent bundle workspace preload artifact support.
 *
 * @package DataMachineCode\Bundle
 */

namespace DataMachineCode\Bundle;

use DataMachineCode\Abilities\WorkspaceAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Applies plugin-owned bundle artifacts that preload DMC workspaces.
 */
final class WorkspacePreloadArtifact {

	public const ARTIFACT_TYPE = 'datamachine-code/workspace_preload';

	/**
	 * @var callable|null
	 */
	private $clone_callback;

	/**
	 * @param callable|null $clone_callback Optional clone callback for tests.
	 */
	public function __construct( ?callable $clone_callback = null ) {
		$this->clone_callback = $clone_callback;
	}

	/**
	 * Register Data Machine bundle extension hooks.
	 */
	public function register(): void {
		add_filter( 'datamachine_agent_bundle_artifact_types', array( $this, 'register_artifact_type' ), 10, 1 );
		add_filter( 'datamachine_agent_bundle_apply_artifact', array( $this, 'apply_artifact' ), 10, 4 );
	}

	/**
	 * Register the DMC workspace preload artifact type.
	 *
	 * @param string[] $types Registered bundle artifact types.
	 * @return string[]
	 */
	public function register_artifact_type( array $types ): array {
		$types[] = self::ARTIFACT_TYPE;
		return $types;
	}

	/**
	 * Apply a workspace preload artifact.
	 *
	 * @param mixed               $result   Existing plugin result, or null.
	 * @param array<string,mixed> $artifact Artifact envelope.
	 * @param array<string,mixed> $agent    Agent row.
	 * @param array<string,mixed> $context  Apply context.
	 * @return array<string,mixed>|\WP_Error|null
	 */
	public function apply_artifact( mixed $result, array $artifact, array $agent = array(), array $context = array() ): array|\WP_Error|null {
		unset( $agent, $context );

		if ( self::ARTIFACT_TYPE !== (string) ( $artifact['artifact_type'] ?? '' ) ) {
			return $result;
		}

		$repositories = $this->validate_repositories( $artifact['payload'] ?? null );
		if ( is_wp_error( $repositories ) ) {
			return $repositories;
		}

		$results = array();
		foreach ( $repositories as $repository ) {
			$clone_result = $this->clone_repository( $repository );
			if ( is_wp_error( $clone_result ) ) {
				$results[] = $this->error_result( $repository, $clone_result );
				return new \WP_Error(
					'datamachine_code_workspace_preload_failed',
					sprintf( 'Failed to preload workspace repository %s: %s', $repository['url'], $clone_result->get_error_message() ),
					array(
						'status'        => 500,
						'artifact_type' => self::ARTIFACT_TYPE,
						'artifact_id'   => (string) ( $artifact['artifact_id'] ?? '' ),
						'repositories'  => $results,
					)
				);
			}

			$results[] = $this->success_result( $repository, $clone_result );
		}

		return array(
			'success'       => true,
			'artifact_type' => self::ARTIFACT_TYPE,
			'artifact_id'   => (string) ( $artifact['artifact_id'] ?? '' ),
			'repositories'  => $results,
		);
	}

	/**
	 * Validate and normalize artifact repositories.
	 *
	 * @param mixed $payload Artifact payload.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function validate_repositories( mixed $payload ): array|\WP_Error {
		if ( ! is_array( $payload ) || ! isset( $payload['repositories'] ) || ! is_array( $payload['repositories'] ) || array() === $payload['repositories'] || ! array_is_list( $payload['repositories'] ) ) {
			return new \WP_Error( 'datamachine_code_workspace_preload_invalid_payload', 'Workspace preload payload.repositories must be a non-empty list.', array( 'status' => 400 ) );
		}

		$repositories = array();
		foreach ( $payload['repositories'] as $index => $repository ) {
			if ( ! is_array( $repository ) ) {
				return new \WP_Error( 'datamachine_code_workspace_preload_invalid_repository', sprintf( 'Workspace preload repository at index %d must be an object.', (int) $index ), array( 'status' => 400 ) );
			}

			$url = trim( (string) ( $repository['url'] ?? '' ) );
			if ( '' === $url || ! $this->is_valid_git_source( $url ) ) {
				return new \WP_Error( 'datamachine_code_workspace_preload_invalid_url', sprintf( 'Workspace preload repository at index %d must declare a valid git URL or local git source.', (int) $index ), array( 'status' => 400 ) );
			}

			$normalized = array( 'url' => $url );
			if ( isset( $repository['name'] ) ) {
				$name = trim( (string) $repository['name'] );
				if ( '' === $name ) {
					return new \WP_Error( 'datamachine_code_workspace_preload_invalid_name', sprintf( 'Workspace preload repository at index %d has an empty name.', (int) $index ), array( 'status' => 400 ) );
				}
				$normalized['name'] = $name;
			}

			if ( isset( $repository['full'] ) ) {
				$normalized['full'] = (bool) $repository['full'];
			}

			$repositories[] = $normalized;
		}

		return $repositories;
	}

	/**
	 * Validate a git source string before passing it to the clone ability.
	 */
	private function is_valid_git_source( string $source ): bool {
		if ( preg_match( '/[\x00-\x1F\x7F]/', $source ) || str_starts_with( $source, '-' ) ) {
			return false;
		}

		$scheme = wp_parse_url( $source, PHP_URL_SCHEME );
		if ( is_string( $scheme ) && '' !== $scheme ) {
			return in_array( strtolower( $scheme ), array( 'https', 'ssh', 'git', 'file' ), true ) && false !== filter_var( $source, FILTER_VALIDATE_URL );
		}

		if ( preg_match( '/^[A-Za-z0-9._-]+@[A-Za-z0-9._-]+:.+$/', $source ) ) {
			return true;
		}

		return ! str_contains( $source, '..' ) && file_exists( $source );
	}

	/**
	 * Clone or register one repository via the DMC workspace ability.
	 *
	 * @param array<string,mixed> $repository Repository config.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function clone_repository( array $repository ): array|\WP_Error {
		$input = array( 'url' => $repository['url'] );
		if ( isset( $repository['name'] ) ) {
			$input['name'] = $repository['name'];
		}
		if ( isset( $repository['full'] ) ) {
			$input['full'] = $repository['full'];
		}

		$callback = $this->clone_callback ? $this->clone_callback : array( WorkspaceAbilities::class, 'cloneRepo' );
		$result   = call_user_func( $callback, $input );

		if ( is_wp_error( $result ) && 'repo_exists' === $result->get_error_code() ) {
			$data = (array) $result->get_error_data();
			if ( 'existing checkout' === (string) ( $data['state'] ?? '' ) ) {
				return array(
					'success'        => true,
					'already_exists' => true,
					'name'           => $input['name'] ?? null,
					'path'           => $data['path'] ?? null,
					'message'        => $result->get_error_message(),
				);
			}
		}

		return $result;
	}

	/**
	 * Build a normalized successful per-repo result.
	 *
	 * @param array<string,mixed> $repository Repository config.
	 * @param array<string,mixed> $result     Clone result.
	 * @return array<string,mixed>
	 */
	private function success_result( array $repository, array $result ): array {
		return array(
			'success'        => true,
			'url'            => $repository['url'],
			'name'           => $result['name'] ?? $repository['name'] ?? null,
			'path'           => $result['path'] ?? null,
			'already_exists' => (bool) ( $result['already_exists'] ?? false ),
			'result'         => $result,
		);
	}

	/**
	 * Build a normalized failed per-repo result.
	 *
	 * @param array<string,mixed> $repository Repository config.
	 * @param \WP_Error           $error      Clone error.
	 * @return array<string,mixed>
	 */
	private function error_result( array $repository, \WP_Error $error ): array {
		return array(
			'success' => false,
			'url'     => $repository['url'],
			'name'    => $repository['name'] ?? null,
			'error'   => array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => $error->get_error_data(),
			),
		);
	}
}
