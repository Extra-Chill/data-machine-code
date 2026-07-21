<?php
/**
 * Worktree Context Injector
 *
 * When a worktree is created from a WordPress site with an active Data Machine
 * agent, expose the originating site's AGENTS.md and snapshot that agent's
 * persistent context (MEMORY.md, USER.md, RULES.md) into the new worktree as
 * runtime-agnostic local-only files.
 *
 * Integrations register local context projections for their runtime through
 * the projection and cleanup filters below. DMC builds the payload, executes
 * those registrations, and manages their per-checkout exclude entries without
 * modifying the tracked `.gitignore` or another checkout.
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
 * == Session-attribution layering ==
 *
 * Captured session identifiers live in a runtime-agnostic envelope:
 *
 *   array(
 *       'primary_id' => '<opaque or null>',
 *       'ids'        => array(
 *           '<runtime-id>' => array(
 *               'session_id' => '<opaque or null>',
 *               'thread_id'  => '<opaque or null>',
 *               'thread_url' => '<opaque or null>',
 *               'run_id'     => '<opaque or null>',
 *               // ...integration-defined subkeys
 *           ),
 *       ),
 *   )
 *
 * DMC does NOT enumerate runtime IDs and does NOT hardcode any vendor-specific
 * field names. Integration layers (e.g. wp-coding-agents) describe which env
 * vars to sniff and how to project them into the envelope through the
 * `datamachine_code_worktree_runtime_signatures` filter:
 *
 *   add_filter( 'datamachine_code_worktree_runtime_signatures', function ( array $signatures ): array {
 *       $signatures['<runtime-id>'] = array(
 *           'session_id' => '<ENV_VAR_NAME>',
 *           'thread_id'  => '<ENV_VAR_NAME>',
 *           'thread_url' => '<ENV_VAR_NAME>',
 *           'run_id'     => '<ENV_VAR_NAME>',
 *           // ...integration-defined subkey => env var
 *       );
 *       return $signatures;
 *   } );
 *
 * `primary_id` resolution scans registered runtimes in registration order and
 * picks the first non-empty `session_id`, falling back to the first non-empty
 * value of any subkey within each runtime. The integration layer therefore
 * controls precedence by registration order.
 *
 * @package DataMachineCode\Workspace
 * @since   0.8.0
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Storage\SqliteBusyRetry;

use DataMachineCode\Support\GitHubRemote;

defined('ABSPATH') || exit;

require_once __DIR__ . '/WorkspaceHandle.php';

class WorktreeContextInjector {



	/**
	 * Lifecycle states stored on worktree metadata records.
	 */
	public const STATE_ACTIVE           = 'active';
	public const STATE_PR_OPENED        = 'pr_opened';
	public const STATE_MERGED           = 'merged';
	public const STATE_CLOSED           = 'closed';
	public const STATE_ABANDONED        = 'abandoned';
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
	public const LIVENESS_LIVE    = 'live';
	public const LIVENESS_STOPPED = 'stopped';
	public const LIVENESS_STALE   = 'stale';
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

	public const ORIGIN_SESSION_LEGACY_MIGRATED_OPTION = 'datamachine_code_worktree_attribution_legacy_migrated_v2';

	/**
	 * Memory files snapshotted into the payload, in render order.
	 */
	private const MEMORY_FILES = array( 'MEMORY.md', 'USER.md', 'RULES.md' );

	/**
	 * Filterable registry hook for worktree context projection targets.
	 */
	public const PROJECTION_TARGETS_FILTER = 'datamachine_code_worktree_context_projection_targets';

	/**
	 * Filterable registry hook for projection cleanup markers.
	 */
	public const PROJECTION_CLEANUP_FILTER = 'datamachine_code_worktree_context_projection_cleanup';

	/**
	 * Site-provided sections that should be visible before long memory snapshots.
	 */
	private const PRIORITY_RULE_SECTIONS = array( 'Minion Session Routing' );

	/**
	 * Build best-effort lifecycle metadata for a newly-created worktree.
	 *
	 * @param  array $args Worktree creation context.
	 * @return array<string,mixed>
	 */
	public static function build_lifecycle_metadata( array $args = array() ): array {
		$site_url  = function_exists('home_url') ? (string) home_url() : '';
		$site_name = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '';
		$user      = self::resolve_origin_user();
		$agent     = self::resolve_origin_agent();
		$session   = self::resolve_origin_session();
		$task      = self::resolve_task_metadata($args);

		$created_at = gmdate('c');

		$metadata = array(
			'created_at'       => $created_at,
			'observed_at'      => $created_at,
			'last_seen_at'     => $created_at,
			'lifecycle_state'  => self::STATE_ACTIVE,
			'handle'           => isset($args['handle']) && '' !== (string) $args['handle'] ? (string) $args['handle'] : null,
			'path'             => isset($args['path']) && '' !== (string) $args['path'] ? (string) $args['path'] : null,
			'origin_site'      => '' !== $site_name ? $site_name : ( '' !== $site_url ? $site_url : null ),
			'origin_site_url'  => '' !== $site_url ? $site_url : null,
			'origin_site_name' => '' !== $site_name ? $site_name : null,
			'origin_agent'     => $agent,
			'origin_session'   => $session,
			'origin_user'      => $user,
			'origin_task'      => $task,
			'base_ref'         => isset($args['base_ref']) && '' !== (string) $args['base_ref'] ? (string) $args['base_ref'] : null,
			'base_source'      => isset($args['base_source']) && '' !== (string) $args['base_source'] ? (string) $args['base_source'] : null,
			'branch'           => isset($args['branch']) && '' !== (string) $args['branch'] ? (string) $args['branch'] : null,
			'repo'             => isset($args['repo']) && '' !== (string) $args['repo'] ? (string) $args['repo'] : null,
		);

		// Preserve the original context metadata field names for existing callers.
		$metadata['site_url']   = $metadata['origin_site_url'] ?? '';
		$metadata['site_name']  = $metadata['origin_site_name'] ?? '';
		$metadata['agent_slug'] = is_string($agent) ? $agent : '';

		return array_filter($metadata, fn( $value ) => null !== $value);
	}

	/**
	 * Update the heartbeat (`last_seen_at`) for a worktree handle.
	 *
	 * Bounded helper used by lifecycle write paths (`refresh-context`,
	 * `finalize`) so listing surfaces can distinguish live vs stale ownership
	 * without touching destructive flows.
	 *
	 * @param  string      $handle Workspace handle.
	 * @param  string|null $now    ISO-8601 timestamp; defaults to current time.
	 * @return string|null The persisted heartbeat timestamp, or null when no
	 *                     metadata record exists for the handle.
	 */
	public static function record_heartbeat( string $handle, ?string $now = null ): ?string {
		$existing = self::get_metadata($handle);
		if ( null === $existing ) {
			return null;
		}
		$timestamp = $now ?? gmdate('c');
		self::store_lifecycle_metadata($handle, array( 'last_seen_at' => $timestamp ));
		return $timestamp;
	}

	/**
	 * Classify the liveness of a worktree from its persisted lifecycle metadata.
	 *
	 * Returns a stable shape the listing/hygiene surfaces can render directly.
	 * The classification is intentionally non-destructive: `live` may protect a
	 * worktree, but non-live states never prove deletion safety. Callers must
	 * still combine them with dirtiness, unpushed-commit, and lifecycle checks.
	 *
	 * Reason codes:
	 *   - `metadata_missing`     — no metadata record at all
	 *   - `lifecycle_pr_opened`  — PR has been opened (live owner irrelevant)
	 *   - `lifecycle_finalized`  — merged/closed/abandoned/cleanup_eligible
	 *   - `heartbeat_fresh`      — last_seen within TTL
	 *   - `heartbeat_stale`      — last_seen older than TTL
	 *   - `heartbeat_unknown`    — no last_seen recorded
	 *
	 * @param  array<string,mixed>|null $metadata Worktree lifecycle metadata.
	 * @param  int|null                 $now      Override "now" for tests. Unix timestamp.
	 * @param  int                      $ttl      Heartbeat TTL in seconds.
	 * @return array{liveness:string,reason:string,heartbeat_age_seconds:?int,last_seen_at:?string}
	 */
	public static function classify_liveness( ?array $metadata, ?int $now = null, int $ttl = self::DEFAULT_HEARTBEAT_TTL_SECONDS ): array {
		if ( ! is_array($metadata) || empty($metadata) ) {
			return array(
				'liveness'              => self::LIVENESS_UNKNOWN,
				'reason'                => 'metadata_missing',
				'heartbeat_age_seconds' => null,
				'last_seen_at'          => null,
			);
		}

		$state = isset($metadata['lifecycle_state']) ? self::normalize_state( (string) $metadata['lifecycle_state']) : null;
		if ( self::STATE_PR_OPENED === $state ) {
			return array(
				'liveness'              => self::LIVENESS_STOPPED,
				'reason'                => 'lifecycle_pr_opened',
				'heartbeat_age_seconds' => null,
				'last_seen_at'          => isset($metadata['last_seen_at']) ? (string) $metadata['last_seen_at'] : null,
			);
		}
		if ( null !== $state && in_array($state, array( self::STATE_MERGED, self::STATE_CLOSED, self::STATE_ABANDONED, self::STATE_CLEANUP_ELIGIBLE ), true) ) {
			return array(
				'liveness'              => self::LIVENESS_STOPPED,
				'reason'                => 'lifecycle_finalized',
				'heartbeat_age_seconds' => null,
				'last_seen_at'          => isset($metadata['last_seen_at']) ? (string) $metadata['last_seen_at'] : null,
			);
		}

		$last_seen_raw = isset($metadata['last_seen_at']) ? (string) $metadata['last_seen_at'] : '';
		if ( '' === $last_seen_raw ) {
			$last_seen_raw = isset($metadata['created_at']) ? (string) $metadata['created_at'] : '';
		}

		if ( '' === $last_seen_raw ) {
			return array(
				'liveness'              => self::LIVENESS_UNKNOWN,
				'reason'                => 'heartbeat_unknown',
				'heartbeat_age_seconds' => null,
				'last_seen_at'          => null,
			);
		}

		$last_seen_ts = strtotime($last_seen_raw);
		if ( false === $last_seen_ts ) {
			return array(
				'liveness'              => self::LIVENESS_UNKNOWN,
				'reason'                => 'heartbeat_unknown',
				'heartbeat_age_seconds' => null,
				'last_seen_at'          => $last_seen_raw,
			);
		}

		$now_ts = null === $now ? time() : $now;
		$age    = max(0, $now_ts - $last_seen_ts);

		if ( $age <= max(0, $ttl) ) {
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
	 * @param  array<int,array<string,mixed>> $worktrees Worktree rows. Each row
	 *                                                   must expose `handle` and `metadata` (or top-level pr_url/pr_number/
	 *                                                   origin_task fields).
	 * @return array<int,array{key:string,kind:string,handles:array<int,string>}>
	 */
	public static function find_duplicate_task_ownership( array $worktrees ): array {
		$buckets = array();

		foreach ( $worktrees as $row ) {
			$handle = (string) ( $row['handle'] ?? '' );
			if ( '' === $handle ) {
				continue;
			}
			$metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : array();

			$keys = self::extract_task_keys($row, $metadata);
			foreach ( $keys as $kind => $key ) {
				$bucket_key                          = $kind . '|' . $key;
				$buckets[ $bucket_key ]['kind']      = $kind;
				$buckets[ $bucket_key ]['key']       = $key;
				$buckets[ $bucket_key ]['handles'][] = $handle;
			}
		}

		$duplicates = array();
		foreach ( $buckets as $bucket ) {
			$handles = array_values(array_unique($bucket['handles']));
			if ( count($handles) < 2 ) {
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
	 * @param  array<string,mixed>|null $metadata Persisted metadata.
	 * @return array{site:string,site_url:?string,agent:string,user:string}
	 */
	public static function summarize_owner( ?array $metadata ): array {
		$site     = 'unknown';
		$site_url = null;
		$agent    = 'unknown';
		$user     = 'unknown';

		if ( is_array($metadata) ) {
			$origin_site = trim( (string) ( $metadata['origin_site'] ?? $metadata['site_name'] ?? '' ));
			if ( '' !== $origin_site ) {
				$site = $origin_site;
			}

			$origin_site_url = trim( (string) ( $metadata['origin_site_url'] ?? $metadata['site_url'] ?? '' ));
			if ( '' !== $origin_site_url ) {
				$site_url = $origin_site_url;
			}

			$origin_agent = trim( (string) ( $metadata['origin_agent'] ?? $metadata['agent_slug'] ?? '' ));
			if ( '' !== $origin_agent ) {
				$agent = $origin_agent;
			}

			$origin_user = is_array($metadata['origin_user'] ?? null) ? $metadata['origin_user'] : array();
			$display     = trim( (string) ( $origin_user['display_name'] ?? '' ));
			$login       = trim( (string) ( $origin_user['login'] ?? '' ));
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
	 * Returns the runtime-agnostic envelope (`primary_id` + `ids`) described
	 * in this file's header. `primary_id` resolution:
	 *
	 *   1. If `origin_session.primary_id` is already set, use it.
	 *   2. Otherwise, scan registered runtimes (in registration order via the
	 *      `datamachine_code_worktree_runtime_signatures` filter) and pick the
	 *      first non-empty `session_id` for any registered runtime.
	 *   3. If no runtime declares `session_id`, fall back to the first
	 *      non-empty subkey of the first registered runtime that has data.
	 *
	 * Legacy rows stored under brand-named top-level keys (pre-#416) are
	 * transparently normalized into the new envelope on read via
	 * {@see self::migrate_legacy_origin_session()}.
	 *
	 * @param  array<string,mixed>|null $metadata Persisted metadata.
	 * @return array{primary_id:?string,ids:array<string,array<string,?string>>}
	 */
	public static function summarize_session( ?array $metadata ): array {
		$session = is_array($metadata['origin_session'] ?? null) ? (array) $metadata['origin_session'] : array();
		$session = self::migrate_legacy_origin_session($session);

		$ids = array();
		if ( isset($session['ids']) && is_array($session['ids']) ) {
			foreach ( $session['ids'] as $runtime_id => $entry ) {
				if ( ! is_string($runtime_id) || '' === $runtime_id || ! is_array($entry) ) {
					continue;
				}
				$normalized = array();
				foreach ( $entry as $subkey => $value ) {
					if ( ! is_string($subkey) || '' === $subkey ) {
						continue;
					}
					$normalized[ $subkey ] = self::normalize_session_value($value);
				}
				if ( ! empty($normalized) ) {
					$ids[ $runtime_id ] = $normalized;
				}
			}
		}

		$primary = self::resolve_primary_id($session, $ids);

		return array(
			'primary_id' => $primary,
			'ids'        => $ids,
		);
	}

	/**
	 * Backfill legacy origin_session metadata into the canonical ids envelope.
	 *
	 * @return array<string,mixed>
	 */
	public static function backfill_legacy_origin_sessions( bool $apply = false ): array {
		if ( ! function_exists('get_option') ) {
			return array(
				'success' => false,
				'applied' => false,
				'message' => 'Options API is unavailable; cannot backfill worktree metadata.',
			);
		}

		$all = get_option(self::METADATA_OPTION, array());
		if ( ! is_array($all) ) {
			$all = array();
		}

		$planned = array();
		foreach ( $all as $handle => $metadata ) {
			if ( ! is_string($handle) || ! is_array($metadata) || ! is_array($metadata['origin_session'] ?? null) ) {
				continue;
			}
			$session = (array) $metadata['origin_session'];
			if ( ! self::origin_session_has_legacy_keys($session) ) {
				continue;
			}

			$canonical = self::summarize_session($metadata);
			$planned[] = array(
				'handle'         => $handle,
				'primary_id'     => $canonical['primary_id'],
				'runtime_ids'    => array_keys($canonical['ids']),
				'origin_session' => $canonical,
			);

			if ( $apply ) {
				self::store_lifecycle_metadata($handle, array( 'origin_session' => $canonical ));
			}
		}

		if ( $apply && function_exists('update_option') ) {
			update_option(self::ORIGIN_SESSION_LEGACY_MIGRATED_OPTION, true, false);
		}

		return array(
			'success'       => true,
			'applied'       => $apply,
			'planned_count' => count($planned),
			'planned'       => $planned,
			'migrated'      => $apply ? (bool) get_option(self::ORIGIN_SESSION_LEGACY_MIGRATED_OPTION, false) : (bool) get_option(self::ORIGIN_SESSION_LEGACY_MIGRATED_OPTION, false),
			'message'       => $apply ? 'Backfilled legacy worktree origin_session metadata.' : 'Dry run only; pass apply to rewrite worktree metadata.',
		);
	}

	/**
	 * Resolve the renderer-friendly primary identifier from a normalized
	 * session envelope.
	 *
	 * Precedence:
	 *  1. Explicit `primary_id` on the stored envelope (already chosen by a
	 *     caller or persisted from an earlier resolution).
	 *  2. First non-empty `session_id` across registered runtimes, in the
	 *     order returned by the `datamachine_code_worktree_runtime_signatures`
	 *     filter.
	 *  3. First non-empty value of any subkey across registered runtimes, in
	 *     registration order, in array iteration order within a runtime.
	 *  4. Null when nothing resolves.
	 *
	 * @param array<string,mixed>                 $session Raw envelope (may contain `primary_id`).
	 * @param array<string,array<string,?string>> $ids     Normalized ids map.
	 */
	private static function resolve_primary_id( array $session, array $ids ): ?string {
		$explicit = isset($session['primary_id']) ? self::normalize_session_value($session['primary_id']) : null;
		if ( null !== $explicit ) {
			return $explicit;
		}

		$signatures = self::runtime_signatures();

		// Pass 1: session_id across registered runtimes, in registration order.
		foreach ( array_keys($signatures) as $runtime_id ) {
			if ( isset($ids[ $runtime_id ]['session_id']) ) {
				return $ids[ $runtime_id ]['session_id'];
			}
		}

		// Pass 2: any subkey across registered runtimes, in registration order.
		foreach ( array_keys($signatures) as $runtime_id ) {
			if ( ! isset($ids[ $runtime_id ]) ) {
				continue;
			}
			foreach ( $ids[ $runtime_id ] as $value ) {
				if ( null !== $value ) {
					return $value;
				}
			}
		}

		// Pass 3: no registered runtimes — fall back to any captured runtime.
		foreach ( $ids as $entry ) {
			foreach ( $entry as $value ) {
				if ( null !== $value ) {
					return $value;
				}
			}
		}

		return null;
	}

	/**
	 * Normalize a captured session value: strings are trimmed, empty values
	 * become null, non-strings are coerced via string cast then re-checked.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function normalize_session_value( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		if ( is_string($value) ) {
			$trimmed = trim($value);
			return '' === $trimmed ? null : $trimmed;
		}
		if ( is_scalar($value) ) {
			$str = trim( (string) $value);
			return '' === $str ? null : $str;
		}
		return null;
	}

	/**
	 * Migrate legacy brand-named top-level keys into the generic envelope.
	 *
	 * Legacy migration only — pre-#416 rows persisted vendor-specific fields
	 * (`<runtime>_<subkey>`) directly on `origin_session`. We infer the runtime
	 * ID from the prefix and the subkey from the suffix so existing inventory
	 * rows keep rendering correctly after the schema generalization.
	 *
	 * This helper is the single approved location where legacy brand-shaped
	 * keys are referenced. The mapping is structural (prefix/suffix split on
	 * the first underscore), not an enumerated allowlist, so adding a new
	 * runtime never requires touching this code. The block can be deleted in
	 * a follow-up once all known stores are confirmed migrated; gate the
	 * deletion on the `datamachine_code_worktree_attribution_legacy_migrated_v2`
	 * site option (set true once a backfill task completes).
	 *
	 * @param  array<string,mixed> $session Raw stored envelope (may be legacy shape).
	 * @return array<string,mixed> Envelope guaranteed to expose `primary_id` and `ids`.
	 */
	private static function migrate_legacy_origin_session( array $session ): array {
		$ids = array();
		if ( isset($session['ids']) && is_array($session['ids']) ) {
			$ids = (array) $session['ids'];
		}

		// legacy migration only: split top-level `<runtime>_<subkey>` keys into
		// the runtime-keyed envelope. Skip canonical envelope keys.
		$canonical_top_level = array(
			'primary_id' => true,
			'ids'        => true,
		);
		foreach ( $session as $key => $value ) {
			if ( isset($canonical_top_level[ $key ]) ) {
				continue;
			}
			$underscore = strpos($key, '_');
			if ( false === $underscore || 0 === $underscore || strlen($key) - 1 === $underscore ) {
				continue;
			}
			$runtime_id = substr($key, 0, $underscore);
			$subkey     = substr($key, $underscore + 1);
			if ( ! isset($ids[ $runtime_id ]) || ! is_array($ids[ $runtime_id ]) ) {
				$ids[ $runtime_id ] = array();
			}
			// Don't overwrite a value already present in the canonical envelope.
			if ( ! array_key_exists($subkey, $ids[ $runtime_id ]) ) {
				$ids[ $runtime_id ][ $subkey ] = $value;
			}
		}

		$session['ids'] = $ids;
		return $session;
	}

	private static function origin_session_has_legacy_keys( array $session ): bool {
		$canonical_top_level = array(
			'primary_id' => true,
			'ids'        => true,
		);
		foreach ( $session as $key => $value ) {
			unset($value);
			if ( ! is_string($key) || isset($canonical_top_level[ $key ]) ) {
				continue;
			}
			$underscore = strpos($key, '_');
			if ( false !== $underscore && 0 !== $underscore && strlen($key) - 1 !== $underscore ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve the registered runtime signatures.
	 *
	 * @return array<string,array<string,string>> Map of runtime-id => { subkey => env-var-name }.
	 */
	private static function runtime_signatures(): array {
		if ( ! function_exists('apply_filters') ) {
			return array();
		}
		$signatures = apply_filters('datamachine_code_worktree_runtime_signatures', array());
		if ( ! is_array($signatures) ) {
			return array();
		}

		$out = array();
		foreach ( $signatures as $runtime_id => $entry ) {
			if ( ! is_string($runtime_id) || '' === $runtime_id || ! is_array($entry) ) {
				continue;
			}
			$subkeys = array();
			foreach ( $entry as $subkey => $env_var ) {
				if ( ! is_string($subkey) || '' === $subkey || ! is_string($env_var) || '' === $env_var ) {
					continue;
				}
				$subkeys[ $subkey ] = $env_var;
			}
			if ( ! empty($subkeys) ) {
				$out[ $runtime_id ] = $subkeys;
			}
		}
		return $out;
	}

	/**
	 * Extract normalized task keys for duplicate detection from a worktree row.
	 *
	 * @param  array<string,mixed> $row      Listing row.
	 * @param  array<string,mixed> $metadata Persisted metadata.
	 * @return array<string,string> Map of kind => normalized key.
	 */
	private static function extract_task_keys( array $row, array $metadata ): array {
		$keys = array();

		$task     = is_array($metadata['origin_task'] ?? null) ? $metadata['origin_task'] : array();
		$task_url = trim( (string) ( $task['task_url'] ?? '' ));
		if ( '' !== $task_url ) {
			$keys['task_url'] = strtolower($task_url);
		}
		$task_ref = trim( (string) ( $task['task_ref'] ?? '' ));
		if ( '' === $task_url && '' !== $task_ref ) {
			$keys['task_ref'] = strtolower($task_ref);
		}

		$pr_url = trim( (string) ( $row['pr_url'] ?? $metadata['pr_url'] ?? '' ));
		if ( '' !== $pr_url ) {
			$keys['pr_url'] = strtolower($pr_url);
		}

		$pr_repo   = trim( (string) ( $metadata['pr_repo'] ?? '' ));
		$pr_number = (int) ( $row['pr_number'] ?? $metadata['pr_number'] ?? 0 );
		if ( '' !== $pr_repo && $pr_number > 0 ) {
			$keys['pr_ref'] = strtolower($pr_repo . '#' . $pr_number);
		}

		return $keys;
	}

	/**
	 * Validate a lifecycle state string.
	 *
	 * @param  string $state Raw lifecycle state.
	 * @return string|null Normalized state, or null when invalid.
	 */
	public static function normalize_state( string $state ): ?string {
		$state = strtolower(trim($state));
		return in_array($state, self::VALID_STATES, true) ? $state : null;
	}

	/**
	 * Build metadata fields for finalizing a worktree lifecycle.
	 *
	 * @param  string      $state Lifecycle state.
	 * @param  string|null $pr    Optional PR URL or number.
	 * @return array<string,mixed>
	 */
	public static function build_finalizer_metadata( string $state, ?string $pr = null ): array {
		$normalized = self::normalize_state($state);
		if ( null === $normalized ) {
			$normalized = self::STATE_ACTIVE;
		}

		$metadata = array(
			'lifecycle_state' => $normalized,
			'finalized_at'    => gmdate('c'),
		);

		$pr_metadata = self::parse_pr_reference($pr);
		if ( ! empty($pr_metadata) ) {
			$metadata = array_merge($metadata, $pr_metadata);
		}

		if ( self::should_mark_cleanup_eligible($normalized, $pr_metadata) ) {
			$metadata['finalized_state']     = $normalized;
			$metadata['lifecycle_state']     = self::STATE_CLEANUP_ELIGIBLE;
			$metadata['cleanup_eligible_at'] = $metadata['finalized_at'];
		}

		return $metadata;
	}

	/**
	 * Determine whether a finalizer transition is safe to expose as a cleanup signal.
	 *
	 * @param  string              $state       Normalized requested lifecycle state.
	 * @param  array<string,mixed> $pr_metadata Parsed PR metadata.
	 * @return bool
	 */
	private static function should_mark_cleanup_eligible( string $state, array $pr_metadata ): bool {
		if ( self::STATE_CLEANUP_ELIGIBLE === $state ) {
			return true;
		}

		if ( in_array($state, array( self::STATE_MERGED, self::STATE_CLOSED, self::STATE_ABANDONED ), true) ) {
			return true;
		}

		return self::STATE_PR_OPENED === $state && ! empty($pr_metadata);
	}

	/**
	 * Determine whether persisted lifecycle metadata exposes a cleanup signal.
	 *
	 * This accepts both current records (`lifecycle_state=cleanup_eligible`) and
	 * older PR-finalized records that were stored before the cleanup transition
	 * was automatic.
	 *
	 * @param  array<string,mixed> $metadata Worktree lifecycle metadata.
	 * @return bool
	 */
	public static function has_cleanup_signal( array $metadata ): bool {
		$state = isset($metadata['lifecycle_state']) ? self::normalize_state( (string) $metadata['lifecycle_state']) : null;
		if ( null !== $state && self::should_mark_cleanup_eligible($state, self::extract_pr_metadata($metadata)) ) {
			return true;
		}

		$finalized_state = isset($metadata['finalized_state']) ? self::normalize_state( (string) $metadata['finalized_state']) : null;
		return null !== $finalized_state && self::should_mark_cleanup_eligible($finalized_state, self::extract_pr_metadata($metadata));
	}

	/**
	 * Extract PR-like fields from a persisted metadata record.
	 *
	 * @param  array<string,mixed> $metadata Worktree lifecycle metadata.
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
	 * @param  string|null $pr PR URL or number.
	 * @return array<string,mixed>
	 */
	public static function parse_pr_reference( ?string $pr ): array {
		$pr = trim( (string) $pr);
		if ( '' === $pr ) {
			return array();
		}

		$metadata = array( 'pr_ref' => $pr );
		if ( preg_match('~^https?://([^/]+)/([^/]+)/([^/]+)/pull/(\d+)(?:[/?#].*)?$~', $pr, $matches) ) {
			$descriptor = GitHubRemote::descriptor(sprintf('https://%s/%s/%s', $matches[1], $matches[2], $matches[3]));
			if ( null === $descriptor ) {
				return $metadata;
			}

			$metadata['pr_url']    = $descriptor['web_url'] . '/pull/' . (int) $matches[4];
			$metadata['pr_number'] = (int) $matches[4];
			$metadata['pr_repo']   = $descriptor['slug'];
			return $metadata;
		}

		if ( preg_match('/^#?(\d+)$/', $pr, $matches) ) {
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
	 *     agents_md_path?: string,
	 *     timestamp: string,
	 * }|null
	 */
	public static function build_payload(): ?array {
		if ( ! class_exists('\\DataMachine\\Core\\FilesRepository\\AgentMemory') ) {
			return null;
		}
		if ( ! class_exists('\\DataMachine\\Core\\FilesRepository\\DirectoryManager') ) {
			return null;
		}

		$dm         = new \DataMachine\Core\FilesRepository\DirectoryManager();
		$user_id    = $dm->get_effective_user_id(0);
		$agent_slug = $dm->resolve_agent_slug(array( 'user_id' => $user_id ));

		$files        = array();
		$memory_class = '\\DataMachine\\Core\\FilesRepository\\AgentMemory';
		foreach ( self::MEMORY_FILES as $filename ) {
			$memory  = new $memory_class($user_id, 0, $filename);
			$content = null;
			if ( is_callable(array( $memory, 'get_all' )) ) {
				/** @var callable(): mixed $get_all */
				$get_all = array( $memory, 'get_all' );
				$result  = $get_all();
				$content = is_array($result) && ! empty($result['success']) && is_string($result['content'] ?? null) ? $result['content'] : null;
			} elseif ( is_callable(array( $memory, 'get_file_path' )) ) {
				/** @var callable(): mixed $get_file_path */
				$get_file_path = array( $memory, 'get_file_path' );
				$file_path     = $get_file_path();
				if ( is_string($file_path) && is_readable($file_path) ) {
					$content = file_get_contents($file_path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- AgentMemory returns a validated local file path, not a remote URL.
				}
			}

			if ( is_string($content) && '' !== trim($content) ) {
				$files[ $filename ] = $content;
			}
		}

		$abspath = defined('ABSPATH') ? rtrim(ABSPATH, '/') : '';
		$payload = array(
			'site_url'   => function_exists('home_url') ? (string) home_url() : '',
			'site_name'  => function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '',
			'agent_slug' => $agent_slug,
			'abspath'    => $abspath,
			'files'      => $files,
			'timestamp'  => gmdate('c'),
		);

		$agents_md_path = '' !== $abspath ? $abspath . '/AGENTS.md' : '';
		if ( '' !== $agents_md_path && is_file($agents_md_path) ) {
			$payload['agents_md_path'] = $agents_md_path;
		}

		return $payload;
	}

	/**
	 * Render a payload into the markdown body written to both injected files.
	 *
	 * @param  array $payload Payload from {@see self::build_payload()}.
	 * @return string Markdown document.
	 */
	public static function render( array $payload ): string {
		$site_name  = trim( (string) ( $payload['site_name'] ?? '' ));
		$site_url   = (string) ( $payload['site_url'] ?? '' );
		$agent_slug = (string) ( $payload['agent_slug'] ?? '' );
		$abspath    = (string) ( $payload['abspath'] ?? '' );
		$timestamp  = (string) ( $payload['timestamp'] ?? gmdate('c') );
		$files      = is_array($payload['files'] ?? null) ? $payload['files'] : array();

		$heading = '' !== $site_name ? $site_name : ( '' !== $site_url ? $site_url : 'the originating site' );

		$out  = "# Injected context from {$heading}\n\n";
		$out .= "This worktree was created from the {$heading} WordPress site\n";
		$out .= "on {$timestamp}. The agent that created it has the following\n";
		$out .= "persistent context snapshotted below.\n\n";

		$priority_rules = self::extract_priority_rules( (string) ( $files['RULES.md'] ?? '' ));
		if ( '' !== $priority_rules ) {
			$out .= "## Priority rules from RULES.md\n\n";
			$out .= "These site-provided rules are repeated here before the full context snapshot\n";
			$out .= "so spawned agents see them before long memory sections.\n\n";
			$out .= $priority_rules . "\n\n";
		}

		foreach ( self::MEMORY_FILES as $filename ) {
			if ( empty($files[ $filename ]) ) {
				continue;
			}
			$body = rtrim( (string) $files[ $filename ], "\n");
			$out .= "## {$filename}\n\n{$body}\n\n";
		}

		$out      .= "## Fetching fresher context\n\n";
		$out      .= "The source site has `studio wp` available. Run:\n\n";
		$agent_arg = '' !== $agent_slug ? ' --agent=' . $agent_slug : '';
		$out      .= "    studio wp datamachine memory read MEMORY.md{$agent_arg}\n";
		$out      .= "    studio wp datamachine memory search <term>{$agent_arg}\n\n";
		$out      .= "to pull updates that accumulated after this worktree was created.\n";
		if ( '' === $agent_slug ) {
			$out .= "On multi-agent sites, add `--agent=<slug>` to avoid ambiguous memory resolution.\n";
		}
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
	 * @param  string $rules_md Full RULES.md body from the originating site.
	 * @return string Markdown containing matched rule sections, or empty string.
	 */
	private static function extract_priority_rules( string $rules_md ): string {
		$rules_md = trim($rules_md);
		if ( '' === $rules_md ) {
			return '';
		}

		$sections = array();
		foreach ( self::PRIORITY_RULE_SECTIONS as $section_title ) {
			$section = self::extract_markdown_h2_section($rules_md, $section_title);
			if ( '' !== $section ) {
				$sections[] = $section;
			}
		}

		return implode("\n\n", $sections);
	}

	/**
	 * Extract one H2 markdown section by exact title.
	 *
	 * @param  string $markdown      Markdown document.
	 * @param  string $section_title H2 title without the leading `##`.
	 * @return string Section markdown including its H2 heading, or empty string.
	 */
	private static function extract_markdown_h2_section( string $markdown, string $section_title ): string {
		$lines = preg_split('/\r\n|\r|\n/', $markdown);
		if ( false === $lines ) {
			return '';
		}
		$capturing = false;
		$captured  = array();
		$wanted_h2 = '## ' . $section_title;

		foreach ( $lines as $line ) {
			$is_h2 = str_starts_with($line, '## ') && ! str_starts_with($line, '### ');
			if ( $is_h2 ) {
				if ( $capturing ) {
					break;
				}
				if ( trim($line) === $wanted_h2 ) {
					$capturing = true;
				}
			}

			if ( $capturing ) {
				$captured[] = $line;
			}
		}

		return trim(implode("\n", $captured));
	}

	/**
	 * Inject a rendered payload into the worktree filesystem.
	 *
	 * Idempotent: reruns overwrite the injected files and deduplicate the
	 * `info/exclude` entries.
	 *
	 * @param  string $worktree_path Absolute path to the worktree directory.
	 * @param  array  $payload       Payload from {@see self::build_payload()}.
	 * @return array{success: bool, written: string[], exclude_path: ?string, message?: string}|\WP_Error
	 */
	public static function inject( string $worktree_path, array $payload ): array|\WP_Error {
		if ( '' === $worktree_path || ! is_dir($worktree_path) ) {
			return new \WP_Error(
				'worktree_not_found',
				sprintf('Worktree path does not exist: %s', $worktree_path),
				array( 'status' => 404 )
			);
		}

		$written         = array();
		$exclude_entries = array();

		foreach ( self::get_projection_targets($payload) as $target ) {
			$result = self::project_context_target($worktree_path, $payload, $target);
			if ( is_wp_error($result) ) {
				return $result;
			}

			$target_written = $result['written'];
			$written        = array_merge($written, $target_written);
			if ( ! empty($result['exclude']) ) {
				$exclude_entries = array_merge($exclude_entries, $result['exclude']);
			}
		}

		$exclude_path = self::append_exclude_entries($worktree_path, $exclude_entries);

		return array(
			'success'      => true,
			'written'      => $written,
			'exclude_path' => $exclude_path,
		);
	}

	/**
	 * Return registered worktree context projection targets.
	 *
	 * Registry entry shape:
	 * - `path`: relative file path for rendered payload writes.
	 * - `renderer`: optional callable receiving payload, worktree path, and entry;
	 *   returns the file body. Defaults to {@see self::render()}.
	 * - `projector`: optional callable receiving worktree path, payload, and entry;
	 *   returns absolute written paths.
	 * - `exclude`: true to exclude `path`, or a relative path list to exclude.
	 * - `exclude_paths`: relative path list added when a projector writes files.
	 *
	 * Integrations own their target paths, schemas, and projectors. Every target
	 * that writes local state must have a matching cleanup registration.
	 *
	 * @param  array $payload Payload from {@see self::build_payload()}.
	 * @return array<string,array<string,mixed>> Projection target registry.
	 */
	public static function get_projection_targets( array $payload = array() ): array {
		$targets = array();

		if ( function_exists('apply_filters') ) {
			$targets = apply_filters(self::PROJECTION_TARGETS_FILTER, $targets, $payload);
		}

		return is_array($targets) ? $targets : array();
	}

	/**
	 * Return integration-registered cleanup handlers and paths.
	 *
	 * Registry entry shape:
	 * - `cleanup`: optional callable receiving worktree path and entry; returns
	 *   absolute removed paths.
	 * - `paths`: optional relative path list DMC removes directly.
	 *
	 * The integration that registers a projection owns its cleanup behavior,
	 * including restoration of pre-existing local configuration.
	 *
	 * @return array<string,array<string,mixed>> Projection cleanup registry.
	 */
	public static function get_projection_cleanup_registry(): array {
		$cleanup = array();

		if ( function_exists('apply_filters') ) {
			$cleanup = apply_filters(self::PROJECTION_CLEANUP_FILTER, $cleanup);
		}

		return is_array($cleanup) ? $cleanup : array();
	}

	/**
	 * Apply one projection target from the registry.
	 *
	 * @param  string $worktree_path Absolute path to the worktree directory.
	 * @param  array  $payload       Payload from {@see self::build_payload()}.
	 * @param  array  $target        Projection target configuration.
	 * @return array{written:string[],exclude:string[]}|\WP_Error Projection result.
	 */
	private static function project_context_target( string $worktree_path, array $payload, array $target ): array|\WP_Error {
		$written = array();
		$exclude = array();

		if ( isset($target['path']) && is_string($target['path']) && '' !== trim($target['path']) ) {
			$renderer = $target['renderer'] ?? array( self::class, 'render' );
			if ( ! is_callable($renderer) ) {
				return new \WP_Error('context_projection_renderer_invalid', 'Context projection renderer is not callable.', array( 'status' => 500 ));
			}

			$body = call_user_func($renderer, $payload, $worktree_path, $target);
			if ( ! is_string($body) ) {
				return new \WP_Error('context_projection_renderer_invalid', 'Context projection renderer must return a string.', array( 'status' => 500 ));
			}

			$result = self::write_projection_file($worktree_path, $target['path'], $body);
			if ( is_wp_error($result) ) {
				return $result;
			}
			$written[] = $result;

			if ( true === ( $target['exclude'] ?? false ) ) {
				$exclude[] = $target['path'];
			}
		}

		if ( isset($target['projector']) ) {
			if ( ! is_callable($target['projector']) ) {
				return new \WP_Error('context_projection_projector_invalid', 'Context projection projector is not callable.', array( 'status' => 500 ));
			}

			$result = call_user_func($target['projector'], $worktree_path, $payload, $target);
			if ( is_wp_error($result) ) {
				return $result;
			}
			if ( ! is_array($result) ) {
				return new \WP_Error('context_projection_projector_invalid', 'Context projection projector must return an array of written paths.', array( 'status' => 500 ));
			}
			$written = array_merge($written, array_values($result));

			if ( ! empty($result) && ! empty($target['exclude_paths']) && is_array($target['exclude_paths']) ) {
				$exclude = array_merge($exclude, array_values($target['exclude_paths']));
			}
		}

		if ( isset($target['exclude']) && is_array($target['exclude']) ) {
			$exclude = array_merge($exclude, array_values($target['exclude']));
		}

		return array(
			'written' => $written,
			'exclude' => $exclude,
		);
	}

	/**
	 * Write a generated projection file under a worktree.
	 *
	 * @param  string $worktree_path Absolute path to the worktree directory.
	 * @param  string $relative      Relative projection path.
	 * @param  string $body          Projection content.
	 * @return string|\WP_Error Absolute written path or error.
	 */
	private static function write_projection_file( string $worktree_path, string $relative, string $body ): string|\WP_Error {
		$abs = rtrim($worktree_path, '/') . '/' . ltrim($relative, '/');
		$dir = dirname($abs);
		if ( ! is_dir($dir) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			if ( ! wp_mkdir_p($dir) ) {
				return new \WP_Error('mkdir_failed', sprintf('Failed to create directory: %s', $dir), array( 'status' => 500 ));
			}
		}

     // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$bytes = file_put_contents($abs, $body);
		if ( false === $bytes ) {
			return new \WP_Error('write_failed', sprintf('Failed to write injected file: %s', $abs), array( 'status' => 500 ));
		}

		return $abs;
	}

	/**
	 * Remove injected files from a worktree and best-effort strip the
	 * per-checkout exclude entries. Used by `--no-inject-context` reruns
	 * and by future un-inject flows.
	 *
	 * @param  string $worktree_path Worktree directory.
	 * @return array{success: bool, removed: string[]}
	 */
	public static function uninject( string $worktree_path ): array {
		$removed = array();

		foreach ( self::get_projection_cleanup_registry() as $entry ) {
			if ( isset($entry['cleanup']) && is_callable($entry['cleanup']) ) {
				$result = call_user_func($entry['cleanup'], $worktree_path, $entry);
				if ( is_array($result) ) {
					$removed = array_merge($removed, array_values($result));
				}
			}

			if ( ! empty($entry['paths']) && is_array($entry['paths']) ) {
				$removed = array_merge($removed, self::remove_projection_paths($worktree_path, $entry['paths']));
			}
		}

		return array(
			'success' => true,
			'removed' => $removed,
		);
	}

	/**
	 * Remove relative projection paths from a worktree.
	 *
	 * @param  string   $worktree_path Worktree directory.
	 * @param  string[] $paths         Relative paths to remove.
	 * @return string[] Removed absolute paths.
	 */
	private static function remove_projection_paths( string $worktree_path, array $paths ): array {
		$removed = array();
		foreach ( $paths as $relative ) {
			$abs = rtrim($worktree_path, '/') . '/' . $relative;
			if ( is_file($abs) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes DMC-injected local-only context files from a worktree.
				unlink($abs);
				$removed[] = $abs;
			}
		}

		return $removed;
	}

	/**
	 * Persist lifecycle metadata for a worktree handle.
	 *
	 * @param string $handle   Workspace handle.
	 * @param array  $metadata Metadata fields.
	 */
	public static function store_lifecycle_metadata( string $handle, array $metadata ): bool|\WP_Error {
		if ( ! function_exists('get_option') || ! function_exists('update_option') ) {
			$existing = self::get_inventory_metadata($handle) ?? array();
			return self::upsert_inventory_metadata($handle, array_merge($existing, $metadata));
		}

		$stored_metadata = array();
		$updated         = SqliteBusyRetry::run(
			'worktree_lifecycle_metadata_option',
			static function () use ( $handle, $metadata, &$stored_metadata ): bool {
				$all = get_option( self::METADATA_OPTION, array() );
				if ( ! is_array( $all ) ) {
					$all = array();
				}

				$existing        = isset( $all[ $handle ] ) && is_array( $all[ $handle ] ) ? $all[ $handle ] : self::get_inventory_metadata( $handle ) ?? array();
				$stored_metadata = array_merge( $existing, $metadata );
				$all[ $handle ]  = $stored_metadata;

				// A false return also means the value was already identical, which is
				// idempotent. SQLite busy errors are identified through $wpdb->last_error.
				update_option( self::METADATA_OPTION, $all, false );
				return true;
			}
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return self::upsert_inventory_metadata($handle, $stored_metadata);
	}

	/**
	 * Persist context-injection metadata for a worktree handle.
	 *
	 * @param string $handle  Workspace handle.
	 * @param array  $payload Payload from {@see self::build_payload()}.
	 */
	public static function store_metadata( string $handle, array $payload ): bool|\WP_Error {
		return self::store_lifecycle_metadata(
			$handle, array(
				'site_url'         => (string) ( $payload['site_url'] ?? '' ),
				'site_name'        => (string) ( $payload['site_name'] ?? '' ),
				'agent_slug'       => (string) ( $payload['agent_slug'] ?? '' ),
				'origin_site'      => '' !== (string) ( $payload['site_name'] ?? '' ) ? (string) $payload['site_name'] : (string) ( $payload['site_url'] ?? '' ),
				'origin_site_url'  => (string) ( $payload['site_url'] ?? '' ),
				'origin_site_name' => (string) ( $payload['site_name'] ?? '' ),
				'origin_agent'     => (string) ( $payload['agent_slug'] ?? '' ),
				'abspath'          => (string) ( $payload['abspath'] ?? '' ),
				'created_at'       => (string) ( $payload['timestamp'] ?? gmdate('c') ),
			)
		);
	}

	/**
	 * Resolve a non-sensitive current user summary.
	 *
	 * @return array{id:int,login:string,display_name:string}|null
	 */
	private static function resolve_origin_user(): ?array {
		if ( ! function_exists('get_current_user_id') ) {
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

		if ( function_exists('get_userdata') ) {
			$user = get_userdata($user_id);
			if ( is_object($user) ) {
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
		if ( ! class_exists('\DataMachine\Core\FilesRepository\DirectoryManager') ) {
			return null;
		}

		try {
			$dm         = new \DataMachine\Core\FilesRepository\DirectoryManager();
			$user_id    = $dm->get_effective_user_id(0);
			$agent_slug = $dm->resolve_agent_slug(array( 'user_id' => $user_id ));
			return '' !== (string) $agent_slug ? (string) $agent_slug : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Resolve non-sensitive runtime/session hints from the creating process.
	 *
	 * Reads identifiers exposed by the surrounding agent runtime via the
	 * `datamachine_code_worktree_runtime_signatures` filter (see this file's
	 * header for the contract). DMC enumerates no runtime IDs and no env-var
	 * names; integration layers (e.g. wp-coding-agents) declare both.
	 *
	 * Cross-machine federation is intentionally out of scope: only env vars
	 * the creator process exposes are captured. Missing fields stay missing
	 * — never invent IDs.
	 *
	 * The resulting envelope:
	 *
	 *   array(
	 *       'primary_id' => '<opaque or null>',
	 *       'ids'        => array(
	 *           '<runtime-id>' => array( '<subkey>' => '<opaque or null>', ... ),
	 *       ),
	 *   )
	 *
	 * Subkeys named `thread_url` (or any subkey ending in `_url`) are
	 * validated as `http(s)://...` URLs; non-conforming values are dropped.
	 * No other subkey-specific validation is performed.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function resolve_origin_session(): ?array {
		$signatures = self::runtime_signatures();
		if ( empty($signatures) ) {
			return null;
		}

		$ids = array();
		foreach ( $signatures as $runtime_id => $subkeys ) {
			$entry = array();
			foreach ( $subkeys as $subkey => $env_var ) {
				$raw = getenv($env_var);
				if ( ! is_string($raw) ) {
					continue;
				}
				$trimmed = trim($raw);
				if ( '' === $trimmed ) {
					continue;
				}
				// Generic URL-shape validation for url-suffixed subkeys.
				if ( self::is_url_subkey($subkey) && ! preg_match('#^https?://#i', $trimmed) ) {
					continue;
				}
				$entry[ $subkey ] = $trimmed;
			}
			if ( ! empty($entry) ) {
				$ids[ $runtime_id ] = $entry;
			}
		}

		if ( empty($ids) ) {
			return null;
		}

		$session               = array(
			'primary_id' => null,
			'ids'        => $ids,
		);
		$session['primary_id'] = self::resolve_primary_id($session, $ids);

		return $session;
	}

	/**
	 * Whether the given subkey conventionally holds a URL. Generic suffix check
	 * — does not enumerate runtimes.
	 */
	private static function is_url_subkey( string $subkey ): bool {
		return str_ends_with($subkey, '_url') || 'url' === $subkey;
	}

	/**
	 * Resolve valid task metadata for the worktree, when available.
	 *
	 * Sources, in order:
	 *   1. Caller-supplied `task_url` / `task_ref` in `$args` (explicit wins).
	 *   2. `DATAMACHINE_TASK_URL` / `DATAMACHINE_TASK_REF` env vars exposed
	 *      by the surrounding orchestrator.
	 *
	 * Cross-machine inference is intentionally out of scope.
	 *
	 * @param  array<string,mixed> $args Worktree creation context.
	 * @return array<string,mixed>|null
	 */
	public static function resolve_task_metadata( array $args = array() ): ?array {
		$task = array();

		$task_url = isset($args['task_url']) && '' !== trim( (string) $args['task_url']) ? trim( (string) $args['task_url']) : '';
		if ( '' === $task_url ) {
			$env_task_url = getenv('DATAMACHINE_TASK_URL');
			if ( is_string($env_task_url) && '' !== trim($env_task_url) ) {
				$task_url = trim($env_task_url);
			}
		}
		$task_url_parts = '' !== $task_url ? parse_url($task_url) : false;
		if ( is_array($task_url_parts) && isset($task_url_parts['host'], $task_url_parts['scheme']) && in_array(strtolower((string) $task_url_parts['scheme']), array( 'http', 'https' ), true) ) {
			$task['task_url'] = $task_url;
		}

		$task_ref = isset($args['task_ref']) && '' !== trim( (string) $args['task_ref']) ? trim( (string) $args['task_ref']) : '';
		if ( '' === $task_ref ) {
			$env_task_ref = getenv('DATAMACHINE_TASK_REF');
			if ( is_string($env_task_ref) && '' !== trim($env_task_ref) ) {
				$task_ref = trim($env_task_ref);
			}
		}
		$normalized_task_ref = strtolower($task_ref);
		if ( '' !== $normalized_task_ref && ! preg_match('/\s/', $normalized_task_ref) ) {
			$task['task_ref'] = $task_ref;
		}

		return empty($task) ? null : $task;
	}

	/**
	 * Fetch persisted metadata for a handle, or null if none stored.
	 *
	 * @param  string $handle Workspace handle.
	 * @return array|null
	 */
	public static function get_metadata( string $handle ): ?array {
		$db_metadata = self::get_inventory_metadata($handle);

		if ( ! function_exists('get_option') ) {
			return $db_metadata;
		}
		$all = get_option(self::METADATA_OPTION, array());
		if ( ! is_array($all) || empty($all[ $handle ]) || ! is_array($all[ $handle ]) ) {
			return $db_metadata;
		}

		return is_array($db_metadata) ? array_merge($db_metadata, $all[ $handle ]) : $all[ $handle ];
	}

	/**
	 * Drop persisted metadata for a handle. Called when a worktree is removed.
	 *
	 * @param string $handle Workspace handle.
	 */
	public static function forget_metadata( string $handle ): void {
		self::delete_inventory_metadata($handle);

		if ( ! function_exists('get_option') || ! function_exists('update_option') ) {
			return;
		}
		$all = get_option(self::METADATA_OPTION, array());
		if ( ! is_array($all) || ! array_key_exists($handle, $all) ) {
			return;
		}
		unset($all[ $handle ]);
		update_option(self::METADATA_OPTION, $all, false);
	}

	/**
	 * Persist lifecycle metadata into the DB-backed inventory repository.
	 *
	 * @param string              $handle   Workspace handle.
	 * @param array<string,mixed> $metadata Lifecycle metadata.
	 */
	private static function upsert_inventory_metadata( string $handle, array $metadata ): bool|\WP_Error {
		$repository = self::inventory_repository();
		if ( null === $repository ) {
			return true;
		}

		$workspace_handle = WorkspaceHandle::parse($handle);
		$task             = is_array($metadata['origin_task'] ?? null) ? (array) $metadata['origin_task'] : array();
		$session          = is_array($metadata['origin_session'] ?? null) ? self::summarize_session($metadata) : array();

		$stored = $repository->upsert(
			array(
				'handle'          => $handle,
				'repo'            => (string) ( $metadata['repo'] ?? $workspace_handle->repo() ),
				'branch'          => $metadata['branch'] ?? $workspace_handle->branch_slug(),
				'path'            => (string) ( $metadata['path'] ?? '' ),
				'primary_path'    => $metadata['primary_path'] ?? null,
				'is_primary'      => ! $workspace_handle->is_worktree(),
				'lifecycle_state' => $metadata['lifecycle_state'] ?? null,
				'origin_site'     => $metadata['origin_site'] ?? null,
				'origin_agent'    => $metadata['origin_agent'] ?? null,
				'session'         => $session,
				'task'            => $task,
				'task_url'        => $task['task_url'] ?? null,
				'task_ref'        => $task['task_ref'] ?? null,
				'pr_url'          => $metadata['pr_url'] ?? null,
				'pr_number'       => $metadata['pr_number'] ?? null,
				'created_at'      => $metadata['created_at'] ?? null,
				'last_seen_at'    => $metadata['last_seen_at'] ?? null,
				'metadata'        => $metadata,
			)
		);

		return $stored ? true : $repository->last_error() ?? new \WP_Error('worktree_inventory_persist_failed', 'Failed to persist worktree lifecycle metadata.', array( 'status' => 500 ));
	}

	/**
	 * Fetch metadata from the DB-backed inventory repository.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function get_inventory_metadata( string $handle ): ?array {
		$repository = self::inventory_repository();
		if ( null === $repository ) {
			return null;
		}

		$row = $repository->get($handle);
		if ( is_array($row) && is_array($row['metadata'] ?? null) ) {
			return (array) $row['metadata'];
		}

		return null;
	}

	/**
	 * Delete metadata from the DB-backed inventory repository.
	 */
	private static function delete_inventory_metadata( string $handle ): void {
		$repository = self::inventory_repository();
		if ( null !== $repository ) {
			$repository->delete($handle);
		}
	}

	/**
	 * Resolve the DB-backed inventory repository if it is available.
	 */
	private static function inventory_repository(): ?\DataMachineCode\Storage\WorktreeInventoryRepository {
		if ( ! class_exists('\\DataMachineCode\\Storage\\WorktreeInventoryRepository') ) {
			$path = dirname(__DIR__) . '/Storage/WorktreeInventoryRepository.php';
			if ( is_file($path) ) {
				include_once $path;
			}
		}

		return class_exists('\\DataMachineCode\\Storage\\WorktreeInventoryRepository') ? new \DataMachineCode\Storage\WorktreeInventoryRepository() : null;
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
	 * every worktree + the primary checkout. In practice this is harmless for
	 * the local snapshot files; AGENTS.md is only excluded when this injector
	 * created a root projection, and tracked repo-owned AGENTS.md files remain
	 * tracked regardless of exclude rules.
	 *
	 * @param  string $worktree_path Worktree directory.
	 * @return string|null Info directory path, or null if the common git dir
	 *                     cannot be resolved.
	 */
	private static function resolve_info_dir( string $worktree_path ): ?string {
		$common_dir = self::resolve_common_git_dir($worktree_path);
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
	 * @param  string $worktree_path Worktree directory.
	 * @return string|null Absolute path to the common git directory.
	 */
	private static function resolve_common_git_dir( string $worktree_path ): ?string {
		$dot_git = rtrim($worktree_path, '/') . '/.git';

		if ( is_dir($dot_git) ) {
			$real = realpath($dot_git);
			return false === $real ? null : $real;
		}

		if ( ! is_file($dot_git) ) {
			return null;
		}

     // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents($dot_git);
		if ( false === $content ) {
			return null;
		}

		if ( ! preg_match('/^gitdir:\s*(.+)$/m', $content, $matches) ) {
			return null;
		}

		$gitdir = trim($matches[1]);
		if ( '' === $gitdir ) {
			return null;
		}

		if ( ! str_starts_with($gitdir, '/') ) {
			$gitdir = rtrim($worktree_path, '/') . '/' . $gitdir;
		}

		$gitdir_real = realpath($gitdir);
		if ( false === $gitdir_real ) {
			return null;
		}

		// Per git layout, worktree gitdirs contain a `commondir` file with
		// the path (often relative) to the repository's shared .git dir.
		$commondir_file = $gitdir_real . '/commondir';
		if ( ! is_file($commondir_file) ) {
			// Older git versions may omit this file; fall back to the
			// conventional layout: <primary>/.git/worktrees/<slug> → <primary>/.git.
			return dirname(dirname($gitdir_real));
		}

     // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$commondir = trim( (string) file_get_contents($commondir_file));
		if ( '' === $commondir ) {
			return null;
		}

		if ( ! str_starts_with($commondir, '/') ) {
			$commondir = $gitdir_real . '/' . $commondir;
		}

		$real = realpath($commondir);
		return false === $real ? null : $real;
	}

	/**
	 * Append the injected paths to the worktree's per-checkout `info/exclude`,
	 * creating the file if needed. Deduplicates existing entries.
	 *
	 * @param  string   $worktree_path Worktree directory.
	 * @param  string[] $paths         Relative paths to ensure are excluded.
	 * @return string|null The `info/exclude` path touched, or null when the
	 *                     worktree's gitdir could not be resolved.
	 */
	private static function append_exclude_entries( string $worktree_path, array $paths ): ?string {
		$info_dir = self::resolve_info_dir($worktree_path);
		if ( null === $info_dir ) {
			return null;
		}

		if ( ! is_dir($info_dir) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			if ( ! wp_mkdir_p($info_dir) ) {
				return null;
			}
		}

		$exclude = $info_dir . '/exclude';
		$current = '';
		if ( is_file($exclude) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$current = (string) file_get_contents($exclude);
		}

		$existing = array_filter(array_map('trim', explode("\n", $current)), static fn( string $line ): bool => '' !== $line);
		$missing  = array();
		foreach ( $paths as $path ) {
			$needle = trim($path);
			if ( '' === $needle ) {
				continue;
			}
			if ( ! in_array($needle, $existing, true) ) {
				$missing[] = $needle;
			}
		}

		if ( empty($missing) ) {
			return $exclude;
		}

		$append = '';
		if ( '' !== $current && ! str_ends_with($current, "\n") ) {
			$append .= "\n";
		}
		$append .= "# Data Machine: injected worktree context (per-checkout)\n";
		$append .= implode("\n", $missing) . "\n";

     // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = file_put_contents($exclude, $append, FILE_APPEND);
		if ( false === $result ) {
			return null;
		}

		return $exclude;
	}
}
