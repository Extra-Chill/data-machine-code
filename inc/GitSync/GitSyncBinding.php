<?php
/**
 * GitSync Binding
 *
 * Value object for a single binding between a site-owned local directory
 * (relative to ABSPATH) and a GitHub repository.
 *
 * Storage: serialized inside the `datamachine_gitsync_bindings` option.
 * Validation lives here so the shape has one authoritative definition.
 *
 * @package DataMachineCode\GitSync
 * @since   0.7.0
 */

namespace DataMachineCode\GitSync;

defined( 'ABSPATH' ) || exit;

final class GitSyncBinding {

	/**
	 * Default policy for freshly-created bindings.
	 *
	 * Deliberately conservative: read-only until writes are explicitly
	 * enabled; direct push to the pinned branch requires its own second
	 * key (`safe_direct_push`) on top of `push_enabled`; conflicts fail
	 * instead of silently overwriting.
	 *
	 * @var array<string, mixed>
	 */
	public const DEFAULT_POLICY = array(
		// Gates submit (propose changes as a PR) and push (direct commit
		// to pinned branch). Neither ability is reachable without this
		// flipped to true.
		'write_enabled'    => false,
		// Second key: even with write_enabled=true, direct push to the
		// pinned branch is blocked unless this is also true. submit()
		// always opens a PR, so it doesn't need this flag.
		'safe_direct_push' => false,
		// Staging allowlist. Every file uploaded by submit or push must
		// live under one of these path prefixes. Empty = nothing uploadable.
		'allowed_paths'    => array(),
		// Conflict strategy when local state diverges from upstream during
		// a pull: fail (abort), upstream_wins (overwrite local), manual
		// (surface the conflict; leave files alone).
		'conflict'         => 'fail',
		// Scheduled-sync hints, ignored until a future task consumes them.
		// Kept on the shape so bindings created today don't need migration
		// when scheduling lands.
		'auto_pull'        => false,
		'pull_interval'    => 'hourly',
	);

	/**
	 * Conflict strategies allowed on `policy.conflict`.
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
	 * Relative paths this binding pulled at least once. Used to detect
	 * upstream deletions: on pull, any path in this list not in the
	 * remote tree is removed from disk and from this list. Paths not
	 * in the list are assumed consumer-owned and left alone.
	 *
	 * @var string[]
	 */
	public array $pulled_paths;

	/**
	 * @param array<string, mixed> $data
	 */
	private function __construct( array $data ) {
		$this->slug         = (string) $data['slug'];
		$this->local_path   = (string) $data['local_path'];
		$this->remote_url   = (string) $data['remote_url'];
		$this->branch       = (string) ( $data['branch'] ?? 'main' );
		$this->policy       = is_array( $data['policy'] ?? null ) ? $data['policy'] : array();
		$this->policy       = array_merge( self::DEFAULT_POLICY, $this->policy );
		$this->created_at   = (string) ( $data['created_at'] ?? gmdate( 'c' ) );
		$this->last_pulled  = isset( $data['last_pulled'] ) ? (string) $data['last_pulled'] : null;
		$this->last_commit  = isset( $data['last_commit'] ) ? (string) $data['last_commit'] : null;
		$this->pulled_paths = is_array( $data['pulled_paths'] ?? null ) ? array_values( array_filter( array_map( 'strval', $data['pulled_paths'] ) ) ) : array();
	}

	/**
	 * Build a binding from raw input, validating each field.
	 *
	 * @param array<string, mixed> $input
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

		// API-first GitSync speaks GitHub only — accept https://github.com/...
		// and git@github.com:... shapes. Non-GitHub remotes are rejected at
		// bind time so nothing with an unsupported backend gets into storage.
		if ( ! preg_match( '#^(https://github\.com/|git@github\.com:)#', $remote ) ) {
			return new \WP_Error(
				'invalid_remote_url',
				'remote_url must be a https://github.com/... or git@github.com:... URL. GitSync talks to GitHub\'s Contents API — non-GitHub backends are not yet supported.',
				array( 'status' => 400 )
			);
		}

		$branch = trim( (string) ( $input['branch'] ?? 'main' ) );
		if ( '' === $branch || ! preg_match( '#^[A-Za-z0-9._/\-]+$#', $branch ) ) {
			return new \WP_Error( 'invalid_branch', 'Branch name is invalid.', array( 'status' => 400 ) );
		}

		$policy_in = is_array( $input['policy'] ?? null ) ? $input['policy'] : array();
		$policy    = array_merge( self::DEFAULT_POLICY, $policy_in );

		$validation = self::validatePolicy( $policy );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		return new self(
			array(
				'slug'         => $slug,
				'local_path'   => $local,
				'remote_url'   => $remote,
				'branch'       => $branch,
				'policy'       => $policy,
				'created_at'   => gmdate( 'c' ),
				'pulled_paths' => array(),
			)
		);
	}

	/**
	 * Validate a policy array. Shared by create() and updatePolicy().
	 *
	 * @param array<string, mixed> $policy
	 * @return true|\WP_Error
	 */
	public static function validatePolicy( array $policy ): true|\WP_Error {
		if ( ! in_array( $policy['conflict'] ?? 'fail', self::CONFLICT_STRATEGIES, true ) ) {
			return new \WP_Error(
				'invalid_conflict_strategy',
				sprintf( 'conflict must be one of: %s.', implode( ', ', self::CONFLICT_STRATEGIES ) ),
				array( 'status' => 400 )
			);
		}
		if ( ! is_array( $policy['allowed_paths'] ?? null ) ) {
			return new \WP_Error( 'invalid_allowed_paths', 'allowed_paths must be an array.', array( 'status' => 400 ) );
		}
		if ( ! empty( $policy['safe_direct_push'] ) && empty( $policy['write_enabled'] ) ) {
			return new \WP_Error(
				'policy_conflict',
				'safe_direct_push=true requires write_enabled=true.',
				array( 'status' => 400 )
			);
		}
		return true;
	}

	public static function fromArray( array $data ): self {
		return new self( $data );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'slug'         => $this->slug,
			'local_path'   => $this->local_path,
			'remote_url'   => $this->remote_url,
			'branch'       => $this->branch,
			'policy'       => $this->policy,
			'created_at'   => $this->created_at,
			'last_pulled'  => $this->last_pulled,
			'last_commit'  => $this->last_commit,
			'pulled_paths' => $this->pulled_paths,
		);
	}

	/**
	 * Resolve `local_path` to an absolute filesystem path under ABSPATH.
	 *
	 * `local_path` is stored leading-slash (e.g. `/wp-content/uploads/wiki/`)
	 * and interpreted relative to ABSPATH. Keeping the stored form portable
	 * lets a binding move between installs with different ABSPATHs without
	 * a migration step.
	 *
	 * @return string Absolute path with no trailing slash.
	 */
	public function resolveAbsolutePath(): string {
		$relative = ltrim( str_replace( '\\', '/', $this->local_path ), '/' );
		$abspath  = rtrim( ABSPATH, '/' );
		return $abspath . '/' . rtrim( $relative, '/' );
	}
}
