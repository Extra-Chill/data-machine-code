<?php
/**
 * GitSync Binding
 *
 * Value object representing a single binding between a site-owned local
 * directory (relative to ABSPATH) and a remote git repository.
 *
 * Bindings are stored serialized inside the `datamachine_gitsync_bindings`
 * option by `GitSyncRegistry`. This class exists so the serialization
 * shape has one canonical source of truth and validation lives alongside
 * the data it validates.
 *
 * @package DataMachineCode\GitSync
 * @since   0.7.0
 */

namespace DataMachineCode\GitSync;

defined( 'ABSPATH' ) || exit;

final class GitSyncBinding {

	/**
	 * Default policy shape applied to freshly created bindings.
	 *
	 * Deliberately conservative: no writes, no push, no auto-pull. The
	 * caller has to explicitly opt each of those in. `conflict = fail`
	 * means a dirty pull aborts instead of destroying local state.
	 *
	 * @var array<string, mixed>
	 */
	public const DEFAULT_POLICY = array(
		'auto_pull'        => false,
		'pull_interval'    => 'hourly',
		'write_enabled'    => false,
		'push_enabled'     => false,
		// Second key for direct push to the pinned branch. Even with
		// push_enabled=true, pushes straight to the tracked branch are
		// refused unless this is also true. submit() pushes to a feature
		// branch and does NOT require this flag — PR flow is the
		// intended default for bindings that allow writes.
		'safe_direct_push' => false,
		'allowed_paths'    => array(),
		'conflict'         => 'fail',
	);

	/**
	 * Allowed conflict-resolution strategies.
	 *
	 * @var string[]
	 */
	public const CONFLICT_STRATEGIES = array( 'fail', 'upstream_wins', 'manual' );

	public string $slug;
	public string $local_path;
	public string $remote_url;
	public string $branch;

	/**
	 * @var array<string, mixed>
	 */
	public array $policy;

	public string $created_at;
	public ?string $last_pulled;
	public ?string $last_commit;

	/**
	 * @param array<string, mixed> $data
	 */
	private function __construct( array $data ) {
		$this->slug        = (string) $data['slug'];
		$this->local_path  = (string) $data['local_path'];
		$this->remote_url  = (string) $data['remote_url'];
		$this->branch      = (string) ( $data['branch'] ?? 'main' );
		$this->policy      = is_array( $data['policy'] ?? null ) ? $data['policy'] : array();
		$this->policy      = array_merge( self::DEFAULT_POLICY, $this->policy );
		$this->created_at  = (string) ( $data['created_at'] ?? gmdate( 'c' ) );
		$this->last_pulled = isset( $data['last_pulled'] ) ? (string) $data['last_pulled'] : null;
		$this->last_commit = isset( $data['last_commit'] ) ? (string) $data['last_commit'] : null;
	}

	/**
	 * Build a binding from a raw input array, validating each field.
	 *
	 * @param array<string, mixed> $input Raw user input.
	 * @return self|\WP_Error
	 */
	public static function create( array $input ): self|\WP_Error {
		$slug = strtolower( trim( (string) ( $input['slug'] ?? '' ) ) );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $slug ) ) {
			return new \WP_Error(
				'invalid_slug',
				'Slug must start with a letter/digit and contain only lowercase letters, digits, underscore, and hyphen.',
				array( 'status' => 400 )
			);
		}

		$local = trim( (string) ( $input['local_path'] ?? '' ) );
		if ( '' === $local ) {
			return new \WP_Error( 'missing_local_path', 'local_path is required.', array( 'status' => 400 ) );
		}

		$remote = trim( (string) ( $input['remote_url'] ?? '' ) );
		if ( '' === $remote ) {
			return new \WP_Error( 'missing_remote_url', 'remote_url is required.', array( 'status' => 400 ) );
		}

		// Accept https://, http:// (for self-hosted git), git@ SSH, and
		// file:// (useful for local bare-repo testing). Anything outside
		// these schemes is refused — guards against accidental bindings
		// to filesystem paths that git's URL-sniffing might still accept.
		if ( ! preg_match( '#^(https?://|git@|file://)#', $remote ) ) {
			return new \WP_Error(
				'invalid_remote_url',
				'remote_url must be an https://, http://, git@, or file:// URL.',
				array( 'status' => 400 )
			);
		}

		$branch = trim( (string) ( $input['branch'] ?? 'main' ) );
		if ( '' === $branch || ! preg_match( '#^[A-Za-z0-9._/\-]+$#', $branch ) ) {
			return new \WP_Error( 'invalid_branch', 'Branch name is invalid.', array( 'status' => 400 ) );
		}

		$policy_in = is_array( $input['policy'] ?? null ) ? $input['policy'] : array();
		$policy    = array_merge( self::DEFAULT_POLICY, $policy_in );

		if ( ! in_array( $policy['conflict'] ?? 'fail', self::CONFLICT_STRATEGIES, true ) ) {
			return new \WP_Error(
				'invalid_conflict_strategy',
				sprintf( 'conflict must be one of: %s.', implode( ', ', self::CONFLICT_STRATEGIES ) ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_array( $policy['allowed_paths'] ?? null ) ) {
			$policy['allowed_paths'] = array();
		}

		return new self(
			array(
				'slug'       => $slug,
				'local_path' => $local,
				'remote_url' => $remote,
				'branch'     => $branch,
				'policy'     => $policy,
				'created_at' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Restore a binding from its stored array form.
	 *
	 * Unlike `create()` this does NOT re-validate — callers trust the
	 * option store. If storage is ever migrated to a stricter format,
	 * add validation here.
	 *
	 * @param array<string, mixed> $data
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self( $data );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'slug'        => $this->slug,
			'local_path'  => $this->local_path,
			'remote_url'  => $this->remote_url,
			'branch'      => $this->branch,
			'policy'      => $this->policy,
			'created_at'  => $this->created_at,
			'last_pulled' => $this->last_pulled,
			'last_commit' => $this->last_commit,
		);
	}

	/**
	 * Resolve `local_path` to an absolute filesystem path under ABSPATH.
	 *
	 * `local_path` convention: leading-slash string, interpreted relative
	 * to ABSPATH (e.g. `/wp-content/uploads/markdown/wiki/` →
	 * `ABSPATH/wp-content/uploads/markdown/wiki/`). This matches the shape
	 * documented in Extra-Chill/data-machine-code#38 and keeps bindings
	 * portable across installs with different ABSPATHs.
	 *
	 * @return string Absolute path with no trailing slash.
	 */
	public function resolveAbsolutePath(): string {
		$relative = ltrim( str_replace( '\\', '/', $this->local_path ), '/' );
		$abspath  = rtrim( ABSPATH, '/' );
		return $abspath . '/' . rtrim( $relative, '/' );
	}
}
