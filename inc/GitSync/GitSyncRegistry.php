<?php
/**
 * GitSync Registry
 *
 * Option-backed persistence for GitSync bindings.
 *
 * Storage shape:
 *
 *   datamachine_gitsync_bindings = array(
 *       '<slug>' => array(  // GitSyncBinding::toArray()
 *           'slug'        => 'intelligence-wiki',
 *           'local_path'  => '/wp-content/uploads/markdown/wiki/',
 *           'remote_url'  => 'https://github.com/...',
 *           'branch'      => 'main',
 *           'policy'      => array( ... ),
 *           'created_at'  => '2026-04-20T12:00:00+00:00',
 *           'last_pulled' => null|'2026-04-20T12:15:00+00:00',
 *           'last_commit' => null|'abc1234',
 *       ),
 *       ...
 *   )
 *
 * v1 sticks to an option; if usage grows past ~50 bindings the whole
 * registry can move to a custom table without changing the public API.
 *
 * @package DataMachineCode\GitSync
 * @since   0.7.0
 */

namespace DataMachineCode\GitSync;

defined( 'ABSPATH' ) || exit;

final class GitSyncRegistry {

	public const OPTION_KEY = 'datamachine_gitsync_bindings';

	/**
	 * Load all bindings keyed by slug.
	 *
	 * @return array<string, GitSyncBinding>
	 */
	public function all(): array {
		$raw = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $slug => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			// Tolerate missing slug key by falling back to the array key.
			if ( empty( $data['slug'] ) ) {
				$data['slug'] = (string) $slug;
			}
			$out[ (string) $slug ] = GitSyncBinding::fromArray( $data );
		}

		return $out;
	}

	public function get( string $slug ): ?GitSyncBinding {
		$all = $this->all();
		return $all[ $slug ] ?? null;
	}

	public function exists( string $slug ): bool {
		return null !== $this->get( $slug );
	}

	/**
	 * Persist a binding (insert or update).
	 */
	public function save( GitSyncBinding $binding ): void {
		$all                     = $this->loadRawArray();
		$all[ $binding->slug ]   = $binding->toArray();
		update_option( self::OPTION_KEY, $all, false );
	}

	public function delete( string $slug ): bool {
		$all = $this->loadRawArray();
		if ( ! array_key_exists( $slug, $all ) ) {
			return false;
		}
		unset( $all[ $slug ] );
		update_option( self::OPTION_KEY, $all, false );
		return true;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function loadRawArray(): array {
		$raw = get_option( self::OPTION_KEY, array() );
		return is_array( $raw ) ? $raw : array();
	}
}
