<?php
/**
 * Worktree Context Injector
 *
 * When a worktree is created from a WordPress site with an active Data Machine
 * agent, snapshot that agent's persistent context (MEMORY.md, USER.md, RULES.md)
 * into the new worktree as runtime-agnostic local-only files.
 *
 * Written files:
 *   <worktree>/.claude/CLAUDE.local.md  — Claude Code convention
 *   <worktree>/.opencode/AGENTS.local.md — OpenCode convention
 *
 * Both files receive the same payload. They are ignored per-checkout via the
 * worktree's `info/exclude` file, so the tracked `.gitignore` is never touched
 * and other worktrees / the primary checkout are unaffected.
 *
 * Per-worktree lifecycle metadata is persisted in the
 * `datamachine_worktree_metadata` site option so later list, cleanup, and
 * `refresh-context` calls can reason about where a worktree came from.
 *
 * Cross-machine federation is explicitly out of scope: the originating site
 * is always the site invoking this code; if that site is not reachable
 * (plugin removed, agent layer absent), injection and refresh become
 * graceful no-ops.
 *
 * @package DataMachineCode\Workspace
 * @since 0.8.0
 */

namespace DataMachineCode\Workspace;

defined( 'ABSPATH' ) || exit;

class WorktreeContextInjector {

	/**
	 * Lifecycle states stored on worktree metadata records.
	 */
	public const STATE_ACTIVE = 'active';
	public const STATE_PR_OPENED = 'pr_opened';
	public const STATE_MERGED = 'merged';
	public const STATE_CLOSED = 'closed';
	public const STATE_ABANDONED = 'abandoned';
	public const STATE_CLEANUP_ELIGIBLE = 'cleanup_eligible';

	/**
	 * Valid worktree lifecycle state values.
	 */
	public const VALID_STATES = array(
		self::STATE_ACTIVE,
		self::STATE_PR_OPENED,
		self::STATE_MERGED,
		self::STATE_CLOSED,
		self::STATE_ABANDONED,
		self::STATE_CLEANUP_ELIGIBLE,
	);

	/**
	 * Liveness classification labels surfaced alongside lifecycle state.
	 *
	 * These describe owner/agent liveness, not lifecycle. They are separate
	 * from {@see self::VALID_STATES} so a worktree can be in `active` lifecycle
	 * but `stale` liveness when its session heartbeat has lapsed.
	 */
	public const LIVENESS_LIVE = 'live';
	public const LIVENESS_STOPPED = 'stopped';
	public const LIVENESS_STALE = 'stale';
	public const LIVENESS_UNKNOWN = 'unknown';

	/**
	 * Default heartbeat TTL for liveness classification, in seconds.
	 *
	 * 24 hours intentionally errs toward "stale, not dead": a heartbeat older
	 * than this only changes the surfaced liveness, never causes deletion.
	 */
	public const DEFAULT_HEARTBEAT_TTL_SECONDS = 86400;

	/**
	 * Site option key used to persist per-worktree metadata.
	 *
	 * Shape: array<string, array<string,mixed>> keyed by workspace handle.
	 * Older records may contain only the original context-injection fields.
	 */
	public const METADATA_OPTION = 'datamachine_worktree_metadata';

	/**
	 * Files injected into the worktree, relative to the worktree root.
	 */
	public const INJECTED_PATHS = array(
		'.claude/CLAUDE.local.md',
		'.opencode/AGENTS.local.md',
	);

	/**
	 * Memory files snapshotted into the payload, in render order.
	 */
	private const MEMORY_FILES = array( 'MEMORY.md', 'USER.md', 'RULES.md' );

	/**
	 * Site-provided sections that should be visible before long memory snapshots.
	 */
	private const PRIORITY_RULE_SECTIONS = array( 'Minion Session Routing' );

	/**
	 * Build best-effort lifecycle metadata for a newly-created worktree.
	 *
	 * @param array $args Worktree creation context.
	 * @return array<string,mixed>
	 */
	public static function build_lifecycle_metadata( array $args = array() ): array {
		$site_url  = function_exists( 'home_url' ) ? (string) home_url() : '';
		$site_name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
		$user      = self::resolve_origin_user();
		$agent     = self::resolve_origin_agent();
		$session   = self::resolve_origin_session();
		$task      = self::resolve_origin_task( $args );

		$created_at = gmdate( 'c' );

		$metadata = array(
			'created_at'       => $created_at,
			'observed_at'      => $created_at,
			'last_seen_at'     => $created_at,
			'lifecycle_state'  => self::STATE_ACTIVE,
			'handle'           => isset( $args['handle'] ) && '' !== (string) $args['handle'] ? (string) $args['handle'] : null,
			'path'             => isset( $args['path'] ) && '' !== (string) $args['path'] ? (string) $args['path'] : null,
			'origin_site'      => '' !== $site_name ? $site_name : ( '' !== $site_url ? $site_url : null ),
			'origin_site_url'  => '' !== $site_url ? $site_url : null,
			'origin_site_name' => '' !== $site_name ? $site_name : null,
			'origin_agent'     => $agent,
			'origin_session'   => $session,
			'origin_user'      => $user,
			'origin_task'      => $task,
			'base_ref'         => isset( $args['base_ref'] ) && '' !== (string) $args['base_ref'] ? (string) $args['base_ref'] : null,
			'base_source'      => isset( $args['base_source'] ) && '' !== (string) $args['base_source'] ? (string) $args['base_source'] : null,
			'branch'           => isset( $args['branch'] ) && '' !== (string) $args['branch'] ? (string) $args['branch'] : null,
			'repo'             => isset( $args['repo'] ) && '' !== (string) $args['repo'] ? (string) $args['repo'] : null,
		);

		// Preserve the original context metadata field names for existing callers.
		$metadata['site_url']   = $metadata['origin_site_url'] ?? '';
		$metadata['site_name']  = $metadata['origin_site_name'] ?? '';
		$metadata['agent_slug'] = is_string( $agent ) ? $agent : '';

		return array_filter( $metadata, fn( $value ) => null !== $value );
	}

	/**
	 * Update the heartbeat (`last_seen_at`) for a worktree handle.
	 *
	 * Bounded helper used by lifecycle write paths (`refresh-context`,
	 * `finalize`) so listing surfaces can distinguish live vs stale ownership
	 * without touching destructive flows.
	 *
	 * @param string      $handle Workspace handle.
	 * @param string|null $now    ISO-8601 timestamp; defaults to current time.
	 * @return string|null The persisted heartbeat timestamp, or null when no
	 *                     metadata record exists for the handle.
	 */
	public static function record_heartbeat( string $handle, ?string $now = null ): ?string {
		$existing = self::get_metadata( $handle );
		if ( null === $existing ) {
			return null;
		}
		$timestamp = $now ?? gmdate( 'c' );
		self::store_lifecycle_metadata( $handle, array( 'last_seen_at' => $timestamp ) );
		return $timestamp;
	}

	/**
	 * Classify the liveness of a worktree from its persisted lifecycle metadata.
	 *
	 * Returns a stable shape the listing/hygiene surfaces can render directly.
	 * The classification is intentionally non-destructive: callers must not
	 * derive cleanup actions from `liveness` alone — combine with dirtiness
	 * and unpushed-commit checks before acting.
	 *
	 * Reason codes:
	 *   - `metadata_missing`     — no metadata record at all
	 *   - `lifecycle_pr_opened`  — PR has been opened (live owner irrelevant)
	 *   - `lifecycle_finalized`  — merged/closed/abandoned/cleanup_eligible
	 *   - `heartbeat_fresh`      — last_seen within TTL
	 *   - `heartbeat_stale`      — last_seen older than TTL
	 *   - `heartbeat_unknown`    — no last_seen recorded
	 *
	 * @param array<string,mixed>|null $metadata Worktree lifecycle metadata.
	 * @param int|null                 $now      Override "now" for tests. Unix timestamp.
	 * @param int                      $ttl      Heartbeat TTL in seconds.
	 * @return array{liveness:string,reason:string,heartbeat_age_seconds:?int,last_seen_at:?string}
	 */
	public static function classify_liveness( ?array $metadata, ?int $now = null, int $ttl = self::DEFAULT_HEARTBEAT_TTL_SECONDS ): array {
		if ( ! is_array( $metadata ) || empty( $metadata ) ) {
			return array(
				'liveness'              => self::LIVENESS_UNKNOWN,
				'reason'                => 'metadata_missing',
				'heartbeat_age_seconds' => null,
				'last_seen_at'          => null,
			);
		}

		$state = isset( $metadata['lifecycle_state'] ) ? self::normalize_state( (string) $metadata['lifecycle_state'] ) : null;
		if ( self::STATE_PR_OPENED === $state ) {
			return array(
				'liveness'              => self::LIVENESS_STOPPED,
				'reason'                => 'lifecycle_pr_opened',
				'heartbeat_age_seconds' => null,
				'last_seen_at'          => isset( $metadata['last_seen_at'] ) ? (string) $metadata['last_seen_at'] : null,
			);
		}
		if ( null !== $state && in_array( $state, array( self::STATE_MERGED, self::STATE_CLOSED, self::STATE_ABANDONED, self::STATE_CLEANUP_ELIGIBLE ), true ) ) {
			return array(
				'liveness'              => self::LIVENESS_STOPPED,
				'reason'                => 'lifecycle_finalized',
				'heartbeat_age_seconds' => null,
				'last_seen_at'          => isset( $metadata['last_seen_at'] ) ? (string) $metadata['last_seen_at'] : null,
			);
		}

		$last_seen_raw = isset( $metadata['last_seen_at'] ) ? (string) $metadata['last_seen_at'] : '';
		if ( '' === $last_seen_raw ) {
			$last_seen_raw = isset( $metadata['created_at'] ) ? (string) $metadata['created_at'] : '';
		}

		if ( '' === $last_seen_raw ) {
			return array(
				'liveness'              => self::LIVENESS_UNKNOWN,
				'reason'                => 'heartbeat_unknown',
				'heartbeat_age_seconds' => null,
				'last_seen_at'          => null,
			);
		}

		$last_seen_ts = strtotime( $last_seen_raw );
		if ( false === $last_seen_ts ) {
			return array(
				'liveness'              => self::LIVENESS_UNKNOWN,
				'reason'                => 'heartbeat_unknown',
				'heartbeat_age_seconds' => null,
				'last_seen_at'          => $last_seen_raw,
			);
		}

		$now_ts = null === $now ? time() : $now;
		$age    = max( 0, $now_ts - $last_seen_ts );

		if ( $age <= max( 0, $ttl ) ) {
			return array(
				'liveness'              => self::LIVENESS_LIVE,
				'reason'                => 'heartbeat_fresh',
				'heartbeat_age_seconds' => $age,
				'last_seen_at'          => $last_seen_raw,
			);
		}

		return array(
			'liveness'              => self::LIVENESS_STALE,
			'reason'                => 'heartbeat_stale',
			'heartbeat_age_seconds' => $age,
			'last_seen_at'          => $last_seen_raw,
		);
	}

	/**
	 * Detect duplicate task ownership across a worktree listing.
	 *
	 * Two worktrees are duplicates when they share a normalized task key:
	 *   - origin_task.task_url (preferred, e.g. https://github.com/o/r/issues/N)
	 *   - origin_task.task_ref
	 *   - pr_url
	 *   - pr_repo:pr_number
	 *
	 * Bounded by listing size; intentionally returns groups rather than
	 * deletion candidates so callers can decide whether to act.
	 *
	 * @param array<int,array<string,mixed>> $worktrees Worktree rows. Each row
	 *        must expose `handle` and `metadata` (or top-level pr_url/pr_number/
	 *        origin_task fields).
	 * @return array<int,array{key:string,kind:string,handles:array<int,string>}>
	 */
	public static function find_duplicate_task_ownership( array $worktrees ): array {
		$buckets = array();

		foreach ( $worktrees as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$handle = (string) ( $row['handle'] ?? '' );
			if ( '' === $handle ) {
				continue;
			}
			$metadata = is_array( $row['metadata'] ?? null ) ? $row['metadata'] : array();

			$keys = self::extract_task_keys( $row, $metadata );
			foreach ( $keys as $kind => $key ) {
				$bucket_key                       = $kind . '|' . $key;
				$buckets[ $bucket_key ]['kind']   = $kind;
				$buckets[ $bucket_key ]['key']    = $key;
				$buckets[ $bucket_key ]['handles'][] = $handle;
			}
		}

		$duplicates = array();
		foreach ( $buckets as $bucket ) {
			$handles = array_values( array_unique( (array) ( $bucket['handles'] ?? array() ) ) );
			if ( count( $handles ) < 2 ) {
				continue;
			}
			$duplicates[] = array(
				'kind'    => (string) $bucket['kind'],
				'key'     => (string) $bucket['key'],
				'handles' => $handles,
			);
		}

		return $duplicates;
	}

	/**
	 * Summarize the owner side of persisted metadata for listing surfaces.
	 *
	 * Returns a stable shape with `unknown` defaults so renderers don't need
	 * null-coalescing for every field. Sensitive user fields are excluded.
	 *
	 * @param array<string,mixed>|null $metadata Persisted metadata.
	 * @return array{site:string,site_url:?string,agent:string,user:string}
	 */
	public static function summarize_owner( ?array $metadata ): array {
		$site     = 'unknown';
		$site_url = null;
		$agent    = 'unknown';
		$user     = 'unknown';

		if ( is_array( $metadata ) ) {
			$origin_site = trim( (string) ( $metadata['origin_site'] ?? $metadata['site_name'] ?? '' ) );
			if ( '' !== $origin_site ) {
				$site = $origin_site;
			}

			$origin_site_url = trim( (string) ( $metadata['origin_site_url'] ?? $metadata['site_url'] ?? '' ) );
			if ( '' !== $origin_site_url ) {
				$site_url = $origin_site_url;
			}

			$origin_agent = trim( (string) ( $metadata['origin_agent'] ?? $metadata['agent_slug'] ?? '' ) );
			if ( '' !== $origin_agent ) {
				$agent = $origin_agent;
			}

			$origin_user = is_array( $metadata['origin_user'] ?? null ) ? $metadata['origin_user'] : array();
			$display     = trim( (string) ( $origin_user['display_name'] ?? '' ) );
			$login       = trim( (string) ( $origin_user['login'] ?? '' ) );
			if ( '' !== $login ) {
				$user = $login;
			} elseif ( '' !== $display ) {
				$user = $display;
			}
		}

		return array(
			'site'     => $site,
			'site_url' => $site_url,
			'agent'    => $agent,
			'user'     => $user,
		);
	}

	/**
	 * Summarize the session side of persisted metadata for listing surfaces.
	 *
	 * Returns the recorded runtime IDs along with a single stable
	 * `primary_id` field renderers can show in narrow tables.
	 *
	 * @param array<string,mixed>|null $metadata Persisted metadata.
	 * @return array{primary_id:?string,kimaki_session_id:?string,kimaki_thread_id:?string,kimaki_thread_url:?string,opencode_session_id:?string,opencode_run_id:?string}
	 */
	public static function summarize_session( ?array $metadata ): array {
		$session = is_array( $metadata['origin_session'] ?? null ) ? $metadata['origin_session'] : array();

		$kimaki_session_id   = isset( $session['kimaki_session_id'] ) ? (string) $session['kimaki_session_id'] : null;
		$kimaki_thread_id    = isset( $session['kimaki_thread_id'] ) ? (string) $session['kimaki_thread_id'] : null;
		$kimaki_thread_url   = isset( $session['kimaki_thread_url'] ) ? (string) $session['kimaki_thread_url'] : null;
		$opencode_session_id = isset( $session['opencode_session_id'] ) ? (string) $session['opencode_session_id'] : null;
		$opencode_run_id     = isset( $session['opencode_run_id'] ) ? (string) $session['opencode_run_id'] : null;

		// Pick the most descriptive identifier as the renderer-friendly primary.
		$primary = $kimaki_session_id ?? $opencode_session_id ?? $opencode_run_id ?? $kimaki_thread_id;

		return array(
			'primary_id'         => '' === $primary ? null : $primary,
			'kimaki_session_id'  => '' === $kimaki_session_id ? null : $kimaki_session_id,
			'kimaki_thread_id'   => '' === $kimaki_thread_id ? null : $kimaki_thread_id,
			'kimaki_thread_url'  => '' === $kimaki_thread_url ? null : $kimaki_thread_url,
			'opencode_session_id' => '' === $opencode_session_id ? null : $opencode_session_id,
			'opencode_run_id'    => '' === $opencode_run_id ? null : $opencode_run_id,
		);
	}

	/**
	 * Extract normalized task keys for duplicate detection from a worktree row.
	 *
	 * @param array<string,mixed> $row      Listing row.
	 * @param array<string,mixed> $metadata Persisted metadata.
	 * @return array<string,string> Map of kind => normalized key.
	 */
	private static function extract_task_keys( array $row, array $metadata ): array {
		$keys = array();

		$task = is_array( $metadata['origin_task'] ?? null ) ? $metadata['origin_task'] : array();
		$task_url = trim( (string) ( $task['task_url'] ?? '' ) );
		if ( '' !== $task_url ) {
			$keys['task_url'] = strtolower( $task_url );
		}
		$task_ref = trim( (string) ( $task['task_ref'] ?? '' ) );
		if ( '' === $task_url && '' !== $task_ref ) {
			$keys['task_ref'] = strtolower( $task_ref );
		}

		$pr_url = trim( (string) ( $row['pr_url'] ?? $metadata['pr_url'] ?? '' ) );
		if ( '' !== $pr_url ) {
			$keys['pr_url'] = strtolower( $pr_url );
		}

		$pr_repo   = trim( (string) ( $metadata['pr_repo'] ?? '' ) );
		$pr_number = (int) ( $row['pr_number'] ?? $metadata['pr_number'] ?? 0 );
		if ( '' !== $pr_repo && $pr_number > 0 ) {
			$keys['pr_ref'] = strtolower( $pr_repo . '#' . $pr_number );
		}

		return $keys;
	}

	/**
	 * Validate a lifecycle state string.
	 *
	 * @param string $state Raw lifecycle state.
	 * @return string|null Normalized state, or null when invalid.
	 */
	public static function normalize_state( string $state ): ?string {
		$state = strtolower( trim( $state ) );
		return in_array( $state, self::VALID_STATES, true ) ? $state : null;
	}

	/**
	 * Build metadata fields for finalizing a worktree lifecycle.
	 *
	 * @param string      $state Lifecycle state.
	 * @param string|null $pr    Optional PR URL or number.
	 * @return array<string,mixed>
	 */
	public static function build_finalizer_metadata( string $state, ?string $pr = null ): array {
		$normalized = self::normalize_state( $state );
		if ( null === $normalized ) {
			$normalized = self::STATE_ACTIVE;
		}

		$metadata = array(
			'lifecycle_state' => $normalized,
			'finalized_at'    => gmdate( 'c' ),
		);

		$pr_metadata = self::parse_pr_reference( $pr );
		if ( ! empty( $pr_metadata ) ) {
			$metadata = array_merge( $metadata, $pr_metadata );
		}

		if ( self::should_mark_cleanup_eligible( $normalized, $pr_metadata ) ) {
			$metadata['finalized_state']     = $normalized;
			$metadata['lifecycle_state']     = self::STATE_CLEANUP_ELIGIBLE;
			$metadata['cleanup_eligible_at'] = $metadata['finalized_at'];
		}

		return $metadata;
	}

	/**
	 * Determine whether a finalizer transition is safe to expose as a cleanup signal.
	 *
	 * @param string              $state       Normalized requested lifecycle state.
	 * @param array<string,mixed> $pr_metadata Parsed PR metadata.
	 * @return bool
	 */
	private static function should_mark_cleanup_eligible( string $state, array $pr_metadata ): bool {
		if ( self::STATE_CLEANUP_ELIGIBLE === $state ) {
			return true;
		}

		if ( in_array( $state, array( self::STATE_MERGED, self::STATE_CLOSED, self::STATE_ABANDONED ), true ) ) {
			return true;
		}

		return self::STATE_PR_OPENED === $state && ! empty( $pr_metadata );
	}

	/**
	 * Determine whether persisted lifecycle metadata exposes a cleanup signal.
	 *
	 * This accepts both current records (`lifecycle_state=cleanup_eligible`) and
	 * older PR-finalized records that were stored before the cleanup transition
	 * was automatic.
	 *
	 * @param array<string,mixed> $metadata Worktree lifecycle metadata.
	 * @return bool
	 */
	public static function has_cleanup_signal( array $metadata ): bool {
		$state = isset( $metadata['lifecycle_state'] ) ? self::normalize_state( (string) $metadata['lifecycle_state'] ) : null;
		if ( null !== $state && self::should_mark_cleanup_eligible( $state, self::extract_pr_metadata( $metadata ) ) ) {
			return true;
		}

		$finalized_state = isset( $metadata['finalized_state'] ) ? self::normalize_state( (string) $metadata['finalized_state'] ) : null;
		return null !== $finalized_state && self::should_mark_cleanup_eligible( $finalized_state, self::extract_pr_metadata( $metadata ) );
	}

	/**
	 * Extract PR-like fields from a persisted metadata record.
	 *
	 * @param array<string,mixed> $metadata Worktree lifecycle metadata.
	 * @return array<string,mixed>
	 */
	private static function extract_pr_metadata( array $metadata ): array {
		return array_filter(
			array(
				'pr_ref'    => $metadata['pr_ref'] ?? null,
				'pr_url'    => $metadata['pr_url'] ?? null,
				'pr_number' => $metadata['pr_number'] ?? null,
				'pr_repo'   => $metadata['pr_repo'] ?? null,
			),
			fn( $value ) => null !== $value && '' !== $value
		);
	}

	/**
	 * Parse a GitHub PR reference into stable metadata fields.
	 *
	 * @param string|null $pr PR URL or number.
	 * @return array<string,mixed>
	 */
	public static function parse_pr_reference( ?string $pr ): array {
		$pr = trim( (string) $pr );
		if ( '' === $pr ) {
			return array();
		}

		$metadata = array( 'pr_ref' => $pr );
		if ( preg_match( '~^https?://github\.com/([^/]+)/([^/]+)/pull/(\d+)(?:[/?#].*)?$~', $pr, $matches ) ) {
			$metadata['pr_url']    = sprintf( 'https://github.com/%s/%s/pull/%d', $matches[1], $matches[2], (int) $matches[3] );
			$metadata['pr_number'] = (int) $matches[3];
			$metadata['pr_repo']   = $matches[1] . '/' . $matches[2];
			return $metadata;
		}

		if ( preg_match( '/^#?(\d+)$/', $pr, $matches ) ) {
			$metadata['pr_number'] = (int) $matches[1];
		}

		return $metadata;
	}

	/**
	 * Build a payload capturing the originating site's agent context.
	 *
	 * Returns null when Data Machine's agent memory layer is unavailable
	 * (plugin inactive, running outside a WordPress context, etc.). Callers
	 * should treat null as "nothing to inject" — never as an error.
	 *
	 * @return array{
	 *     site_url: string,
	 *     site_name: string,
	 *     agent_slug: string,
	 *     abspath: string,
	 *     files: array<string, string>,
	 *     timestamp: string,
	 * }|null
	 */
	public static function build_payload(): ?array {
		if ( ! class_exists( '\\DataMachine\\Core\\FilesRepository\\AgentMemory' ) ) {
			return null;
		}
		if ( ! class_exists( '\\DataMachine\\Core\\FilesRepository\\DirectoryManager' ) ) {
			return null;
		}

		$dm         = new \DataMachine\Core\FilesRepository\DirectoryManager();
		$user_id    = $dm->get_effective_user_id( 0 );
		$agent_slug = $dm->resolve_agent_slug( array( 'user_id' => $user_id ) );

		$files = array();
		foreach ( self::MEMORY_FILES as $filename ) {
			$memory = new \DataMachine\Core\FilesRepository\AgentMemory( $user_id, 0, $filename );
			$result = $memory->get_all();
			if ( ! empty( $result['success'] ) && is_string( $result['content'] ?? null ) && '' !== trim( $result['content'] ) ) {
				$files[ $filename ] = $result['content'];
			}
		}

		return array(
			'site_url'   => function_exists( 'home_url' ) ? (string) home_url() : '',
			'site_name'  => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '',
			'agent_slug' => $agent_slug,
			'abspath'    => defined( 'ABSPATH' ) ? rtrim( ABSPATH, '/' ) : '',
			'files'      => $files,
			'timestamp'  => gmdate( 'c' ),
		);
	}

	/**
	 * Render a payload into the markdown body written to both injected files.
	 *
	 * @param array $payload Payload from {@see self::build_payload()}.
	 * @return string Markdown document.
	 */
	public static function render( array $payload ): string {
		$site_name  = trim( (string) ( $payload['site_name'] ?? '' ) );
		$site_url   = (string) ( $payload['site_url'] ?? '' );
		$agent_slug = (string) ( $payload['agent_slug'] ?? '' );
		$abspath    = (string) ( $payload['abspath'] ?? '' );
		$timestamp  = (string) ( $payload['timestamp'] ?? gmdate( 'c' ) );
		$files      = is_array( $payload['files'] ?? null ) ? $payload['files'] : array();

		$heading = '' !== $site_name ? $site_name : ( '' !== $site_url ? $site_url : 'the originating site' );

		$out  = "# Injected context from {$heading}\n\n";
		$out .= "This worktree was created from the {$heading} WordPress site\n";
		$out .= "on {$timestamp}. The agent that created it has the following\n";
		$out .= "persistent context snapshotted below.\n\n";

		$priority_rules = self::extract_priority_rules( (string) ( $files['RULES.md'] ?? '' ) );
		if ( '' !== $priority_rules ) {
			$out .= "## Priority rules from RULES.md\n\n";
			$out .= "These site-provided rules are repeated here before the full context snapshot\n";
			$out .= "so spawned agents see them before long memory sections.\n\n";
			$out .= $priority_rules . "\n\n";
		}

		foreach ( self::MEMORY_FILES as $filename ) {
			if ( empty( $files[ $filename ] ) ) {
				continue;
			}
			$body = rtrim( (string) $files[ $filename ], "\n" );
			$out .= "## {$filename}\n\n{$body}\n\n";
		}

		$out .= "## Fetching fresher context\n\n";
		$out .= "The source site has `studio wp` available. Run:\n\n";
		$out .= "    studio wp datamachine agent read MEMORY.md\n";
		$out .= "    studio wp datamachine agent search <term>\n\n";
		$out .= "to pull updates that accumulated after this worktree was created.\n";
		$out .= "You can also rewrite these injected files in-place via:\n\n";
		$out .= "    studio wp datamachine-code workspace worktree refresh-context <handle>\n\n";

		$out .= "## Source site\n\n";
		$out .= '- Slug: ' . ( '' !== $agent_slug ? $agent_slug : '(unresolved)' ) . "\n";
		$out .= '- Site URL: ' . ( '' !== $site_url ? $site_url : '(unknown)' ) . "\n";
		$out .= '- Studio path: ' . ( '' !== $abspath ? $abspath : '(unknown)' ) . "\n";

		return $out;
	}

	/**
	 * Extract high-priority site rules that need to survive long memory payloads.
	 *
	 * @param string $rules_md Full RULES.md body from the originating site.
	 * @return string Markdown containing matched rule sections, or empty string.
	 */
	private static function extract_priority_rules( string $rules_md ): string {
		$rules_md = trim( $rules_md );
		if ( '' === $rules_md ) {
			return '';
		}

		$sections = array();
		foreach ( self::PRIORITY_RULE_SECTIONS as $section_title ) {
			$section = self::extract_markdown_h2_section( $rules_md, $section_title );
			if ( '' !== $section ) {
				$sections[] = $section;
			}
		}

		return implode( "\n\n", $sections );
	}

	/**
	 * Extract one H2 markdown section by exact title.
	 *
	 * @param string $markdown      Markdown document.
	 * @param string $section_title H2 title without the leading `##`.
	 * @return string Section markdown including its H2 heading, or empty string.
	 */
	private static function extract_markdown_h2_section( string $markdown, string $section_title ): string {
		$lines = preg_split( '/\r\n|\r|\n/', $markdown );
		if ( false === $lines ) {
			return '';
		}
		$capturing = false;
		$captured  = array();
		$wanted_h2 = '## ' . $section_title;

		foreach ( $lines as $line ) {
			$is_h2 = str_starts_with( $line, '## ' ) && ! str_starts_with( $line, '### ' );
			if ( $is_h2 ) {
				if ( $capturing ) {
					break;
				}
				if ( trim( $line ) === $wanted_h2 ) {
					$capturing = true;
				}
			}

			if ( $capturing ) {
				$captured[] = $line;
			}
		}

		return trim( implode( "\n", $captured ) );
	}

	/**
	 * Inject a rendered payload into the worktree filesystem.
	 *
	 * Idempotent: reruns overwrite the injected files and deduplicate the
	 * `info/exclude` entries.
	 *
	 * @param string $worktree_path Absolute path to the worktree directory.
	 * @param array  $payload       Payload from {@see self::build_payload()}.
	 * @return array{success: bool, written: string[], exclude_path: ?string, message?: string}|\WP_Error
	 */
	public static function inject( string $worktree_path, array $payload ): array|\WP_Error {
		if ( '' === $worktree_path || ! is_dir( $worktree_path ) ) {
			return new \WP_Error(
				'worktree_not_found',
				sprintf( 'Worktree path does not exist: %s', $worktree_path ),
				array( 'status' => 404 )
			);
		}

		$body    = self::render( $payload );
		$written = array();

		foreach ( self::INJECTED_PATHS as $relative ) {
			$abs = rtrim( $worktree_path, '/' ) . '/' . $relative;
			$dir = dirname( $abs );
			if ( ! is_dir( $dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
				if ( ! wp_mkdir_p( $dir ) ) {
					return new \WP_Error(
						'mkdir_failed',
						sprintf( 'Failed to create directory: %s', $dir ),
						array( 'status' => 500 )
					);
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$bytes = file_put_contents( $abs, $body );
			if ( false === $bytes ) {
				return new \WP_Error(
					'write_failed',
					sprintf( 'Failed to write injected file: %s', $abs ),
					array( 'status' => 500 )
				);
			}
			$written[] = $abs;
		}

		$exclude_path = self::append_exclude_entries( $worktree_path, self::INJECTED_PATHS );

		return array(
			'success'      => true,
			'written'      => $written,
			'exclude_path' => $exclude_path,
		);
	}

	/**
	 * Remove injected files from a worktree and best-effort strip the
	 * per-checkout exclude entries. Used by `--no-inject-context` reruns
	 * and by future un-inject flows.
	 *
	 * @param string $worktree_path Worktree directory.
	 * @return array{success: bool, removed: string[]}
	 */
	public static function uninject( string $worktree_path ): array {
		$removed = array();

		foreach ( self::INJECTED_PATHS as $relative ) {
			$abs = rtrim( $worktree_path, '/' ) . '/' . $relative;
			if ( is_file( $abs ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes DMC-injected local-only context files from a worktree.
				unlink( $abs );
				$removed[] = $abs;
			}
		}

		return array(
			'success' => true,
			'removed' => $removed,
		);
	}

	/**
	 * Persist lifecycle metadata for a worktree handle.
	 *
	 * @param string $handle   Workspace handle.
	 * @param array  $metadata Metadata fields.
	 */
	public static function store_lifecycle_metadata( string $handle, array $metadata ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		$all = get_option( self::METADATA_OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}

		$existing       = isset( $all[ $handle ] ) && is_array( $all[ $handle ] ) ? $all[ $handle ] : array();
		$all[ $handle ] = array_merge( $existing, $metadata );

		update_option( self::METADATA_OPTION, $all, false );
	}

	/**
	 * Persist context-injection metadata for a worktree handle.
	 *
	 * @param string $handle  Workspace handle.
	 * @param array  $payload Payload from {@see self::build_payload()}.
	 */
	public static function store_metadata( string $handle, array $payload ): void {
		self::store_lifecycle_metadata( $handle, array(
			'site_url'         => (string) ( $payload['site_url'] ?? '' ),
			'site_name'        => (string) ( $payload['site_name'] ?? '' ),
			'agent_slug'       => (string) ( $payload['agent_slug'] ?? '' ),
			'origin_site'      => '' !== (string) ( $payload['site_name'] ?? '' ) ? (string) $payload['site_name'] : (string) ( $payload['site_url'] ?? '' ),
			'origin_site_url'  => (string) ( $payload['site_url'] ?? '' ),
			'origin_site_name' => (string) ( $payload['site_name'] ?? '' ),
			'origin_agent'     => (string) ( $payload['agent_slug'] ?? '' ),
			'abspath'          => (string) ( $payload['abspath'] ?? '' ),
			'created_at'       => (string) ( $payload['timestamp'] ?? gmdate( 'c' ) ),
		) );
	}

	/**
	 * Resolve a non-sensitive current user summary.
	 *
	 * @return array{id:int,login:string,display_name:string}|null
	 */
	private static function resolve_origin_user(): ?array {
		if ( ! function_exists( 'get_current_user_id' ) ) {
			return null;
		}
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return null;
		}

		$summary = array(
			'id'           => $user_id,
			'login'        => '',
			'display_name' => '',
		);

		if ( function_exists( 'get_userdata' ) ) {
			$user = get_userdata( $user_id );
			if ( is_object( $user ) ) {
				$summary['login']        = (string) ( $user->user_login ?? '' );
				$summary['display_name'] = (string) ( $user->display_name ?? '' );
			}
		}

		return $summary;
	}

	/**
	 * Resolve the active Data Machine agent slug when available.
	 */
	private static function resolve_origin_agent(): ?string {
		if ( ! class_exists( '\DataMachine\Core\FilesRepository\DirectoryManager' ) ) {
			return null;
		}

		try {
			$dm         = new \DataMachine\Core\FilesRepository\DirectoryManager();
			$user_id    = method_exists( $dm, 'get_effective_user_id' ) ? $dm->get_effective_user_id( 0 ) : 0;
			$agent_slug = method_exists( $dm, 'resolve_agent_slug' ) ? $dm->resolve_agent_slug( array( 'user_id' => $user_id ) ) : '';
			return '' !== (string) $agent_slug ? (string) $agent_slug : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Resolve non-sensitive runtime/session hints from the creating process.
	 *
	 * Reads identifiers exposed by the surrounding agent runtime (OpenCode,
	 * Kimaki/Discord) and the originating site URL. Cross-machine federation
	 * is intentionally out of scope: only the env that the creator process
	 * exposes is captured. Missing fields stay missing — never invent IDs.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function resolve_origin_session(): ?array {
		$session = array();

		$opencode_run_id = getenv( 'OPENCODE_RUN_ID' );
		if ( is_string( $opencode_run_id ) && '' !== trim( $opencode_run_id ) ) {
			$session['opencode_run_id'] = trim( $opencode_run_id );
		}

		$opencode_session_id = getenv( 'OPENCODE_SESSION_ID' );
		if ( is_string( $opencode_session_id ) && '' !== trim( $opencode_session_id ) ) {
			$session['opencode_session_id'] = trim( $opencode_session_id );
		}

		$opencode_pid = getenv( 'OPENCODE_PID' );
		if ( is_string( $opencode_pid ) && ctype_digit( $opencode_pid ) ) {
			$session['opencode_pid'] = (int) $opencode_pid;
		}

		$kimaki_session_id = getenv( 'KIMAKI_SESSION_ID' );
		if ( is_string( $kimaki_session_id ) && '' !== trim( $kimaki_session_id ) ) {
			$session['kimaki_session_id'] = trim( $kimaki_session_id );
		}

		$kimaki_thread_id = getenv( 'KIMAKI_THREAD_ID' );
		if ( is_string( $kimaki_thread_id ) && '' !== trim( $kimaki_thread_id ) ) {
			$session['kimaki_thread_id'] = trim( $kimaki_thread_id );
		}

		$kimaki_channel_id = getenv( 'KIMAKI_CHANNEL_ID' );
		if ( is_string( $kimaki_channel_id ) && '' !== trim( $kimaki_channel_id ) ) {
			$session['kimaki_channel_id'] = trim( $kimaki_channel_id );
		}

		$kimaki_guild_id = getenv( 'KIMAKI_GUILD_ID' );
		if ( is_string( $kimaki_guild_id ) && '' !== trim( $kimaki_guild_id ) ) {
			$session['kimaki_guild_id'] = trim( $kimaki_guild_id );
		}

		$kimaki_thread_url = getenv( 'KIMAKI_THREAD_URL' );
		if ( is_string( $kimaki_thread_url ) && preg_match( '#^https?://#', (string) $kimaki_thread_url ) ) {
			$session['kimaki_thread_url'] = trim( $kimaki_thread_url );
		}

		return empty( $session ) ? null : $session;
	}

	/**
	 * Resolve a task/issue reference for the worktree, when available.
	 *
	 * Sources, in order:
	 *   1. Caller-supplied `task_url` / `task_ref` in `$args` (explicit wins).
	 *   2. `DATAMACHINE_TASK_URL` / `DATAMACHINE_TASK_REF` env vars exposed
	 *      by the surrounding orchestrator.
	 *
	 * Cross-machine inference is intentionally out of scope.
	 *
	 * @param array<string,mixed> $args Worktree creation context.
	 * @return array<string,mixed>|null
	 */
	private static function resolve_origin_task( array $args = array() ): ?array {
		$task = array();

		$task_url = isset( $args['task_url'] ) && '' !== trim( (string) $args['task_url'] ) ? trim( (string) $args['task_url'] ) : '';
		if ( '' === $task_url ) {
			$env_task_url = getenv( 'DATAMACHINE_TASK_URL' );
			if ( is_string( $env_task_url ) && '' !== trim( $env_task_url ) ) {
				$task_url = trim( $env_task_url );
			}
		}
		if ( '' !== $task_url && preg_match( '#^https?://#', $task_url ) ) {
			$task['task_url'] = $task_url;
		}

		$task_ref = isset( $args['task_ref'] ) && '' !== trim( (string) $args['task_ref'] ) ? trim( (string) $args['task_ref'] ) : '';
		if ( '' === $task_ref ) {
			$env_task_ref = getenv( 'DATAMACHINE_TASK_REF' );
			if ( is_string( $env_task_ref ) && '' !== trim( $env_task_ref ) ) {
				$task_ref = trim( $env_task_ref );
			}
		}
		if ( '' !== $task_ref ) {
			$task['task_ref'] = $task_ref;
		}

		return empty( $task ) ? null : $task;
	}

	/**
	 * Fetch persisted metadata for a handle, or null if none stored.
	 *
	 * @param string $handle Workspace handle.
	 * @return array|null
	 */
	public static function get_metadata( string $handle ): ?array {
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}
		$all = get_option( self::METADATA_OPTION, array() );
		if ( ! is_array( $all ) || empty( $all[ $handle ] ) || ! is_array( $all[ $handle ] ) ) {
			return null;
		}
		return $all[ $handle ];
	}

	/**
	 * Drop persisted metadata for a handle. Called when a worktree is removed.
	 *
	 * @param string $handle Workspace handle.
	 */
	public static function forget_metadata( string $handle ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		$all = get_option( self::METADATA_OPTION, array() );
		if ( ! is_array( $all ) || ! array_key_exists( $handle, $all ) ) {
			return;
		}
		unset( $all[ $handle ] );
		update_option( self::METADATA_OPTION, $all, false );
	}

	/**
	 * Resolve the `info/` directory used by git for this worktree's exclude
	 * file.
	 *
	 * Important subtlety: git's `info/exclude` is ALWAYS read from the
	 * repository's *common* git directory, not the per-worktree private
	 * gitdir. A worktree's `.git` file points at `<primary>/.git/worktrees/
	 * <slug>/` but exclude patterns are resolved from `<primary>/.git/info/`.
	 * Writing to the per-worktree `info/exclude` is a silent no-op — git
	 * never reads it. See `man git-config` under GIT_COMMON_DIR.
	 *
	 * Consequence: the injected exclude entries are technically visible to
	 * every worktree + the primary checkout. In practice this is harmless —
	 * the patterns (`.claude/CLAUDE.local.md`, `.opencode/AGENTS.local.md`)
	 * only match files we deliberately create in worktrees via injection.
	 * No injected files exist in non-injected checkouts, so there is no
	 * behavior change there.
	 *
	 * @param string $worktree_path Worktree directory.
	 * @return string|null Info directory path, or null if the common git dir
	 *                     cannot be resolved.
	 */
	private static function resolve_info_dir( string $worktree_path ): ?string {
		$common_dir = self::resolve_common_git_dir( $worktree_path );
		if ( null === $common_dir ) {
			return null;
		}
		return $common_dir . '/info';
	}

	/**
	 * Resolve the repository's common git directory from a worktree path.
	 *
	 * For a worktree:
	 *   `.git` is a file containing `gitdir: <primary>/.git/worktrees/<slug>`,
	 *   and that per-worktree gitdir contains a `commondir` file with the
	 *   path (relative or absolute) to the common `.git` dir.
	 *
	 * For a primary checkout:
	 *   `.git` is itself a directory and is the common dir.
	 *
	 * @param string $worktree_path Worktree directory.
	 * @return string|null Absolute path to the common git directory.
	 */
	private static function resolve_common_git_dir( string $worktree_path ): ?string {
		$dot_git = rtrim( $worktree_path, '/' ) . '/.git';

		if ( is_dir( $dot_git ) ) {
			$real = realpath( $dot_git );
			return false === $real ? null : $real;
		}

		if ( ! is_file( $dot_git ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $dot_git );
		if ( false === $content ) {
			return null;
		}

		if ( ! preg_match( '/^gitdir:\s*(.+)$/m', $content, $matches ) ) {
			return null;
		}

		$gitdir = trim( $matches[1] );
		if ( '' === $gitdir ) {
			return null;
		}

		if ( ! str_starts_with( $gitdir, '/' ) ) {
			$gitdir = rtrim( $worktree_path, '/' ) . '/' . $gitdir;
		}

		$gitdir_real = realpath( $gitdir );
		if ( false === $gitdir_real ) {
			return null;
		}

		// Per git layout, worktree gitdirs contain a `commondir` file with
		// the path (often relative) to the repository's shared .git dir.
		$commondir_file = $gitdir_real . '/commondir';
		if ( ! is_file( $commondir_file ) ) {
			// Older git versions may omit this file; fall back to the
			// conventional layout: <primary>/.git/worktrees/<slug> → <primary>/.git.
			return dirname( dirname( $gitdir_real ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$commondir = trim( (string) file_get_contents( $commondir_file ) );
		if ( '' === $commondir ) {
			return null;
		}

		if ( ! str_starts_with( $commondir, '/' ) ) {
			$commondir = $gitdir_real . '/' . $commondir;
		}

		$real = realpath( $commondir );
		return false === $real ? null : $real;
	}

	/**
	 * Append the injected paths to the worktree's per-checkout `info/exclude`,
	 * creating the file if needed. Deduplicates existing entries.
	 *
	 * @param string   $worktree_path Worktree directory.
	 * @param string[] $paths         Relative paths to ensure are excluded.
	 * @return string|null The `info/exclude` path touched, or null when the
	 *                     worktree's gitdir could not be resolved.
	 */
	private static function append_exclude_entries( string $worktree_path, array $paths ): ?string {
		$info_dir = self::resolve_info_dir( $worktree_path );
		if ( null === $info_dir ) {
			return null;
		}

		if ( ! is_dir( $info_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			if ( ! wp_mkdir_p( $info_dir ) ) {
				return null;
			}
		}

		$exclude = $info_dir . '/exclude';
		$current = '';
		if ( is_file( $exclude ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$current = (string) file_get_contents( $exclude );
		}

		$existing = array_filter( array_map( 'trim', explode( "\n", $current ) ), static fn( string $line ): bool => '' !== $line );
		$missing  = array();
		foreach ( $paths as $path ) {
			$needle = trim( $path );
			if ( '' === $needle ) {
				continue;
			}
			if ( ! in_array( $needle, $existing, true ) ) {
				$missing[] = $needle;
			}
		}

		if ( empty( $missing ) ) {
			return $exclude;
		}

		$append = '';
		if ( '' !== $current && ! str_ends_with( $current, "\n" ) ) {
			$append .= "\n";
		}
		$append .= "# Data Machine: injected worktree context (per-checkout)\n";
		$append .= implode( "\n", $missing ) . "\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = file_put_contents( $exclude, $append, FILE_APPEND );
		if ( false === $result ) {
			return null;
		}

		return $exclude;
	}
}
