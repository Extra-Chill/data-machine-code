<?php
/**
 * Database-independent safe refresh for authoritative primary checkouts.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || defined('DATAMACHINE_CODE_STANDALONE') || exit;

if ( ! class_exists(WorktreeFreshnessEvidence::class) ) {
	require_once __DIR__ . '/WorktreeFreshnessEvidence.php';
}
if ( ! class_exists(StandaloneFileLock::class) ) {
	require_once __DIR__ . '/StandaloneFileLock.php';
}
if ( ! class_exists(WorktreeBranchHolder::class) ) {
	require_once __DIR__ . '/WorktreeBranchHolder.php';
}

final class StandalonePrimaryRefresher {

	public const SCHEMA = 'datamachine-code/primary-refresh/v1';

	private const COMMAND_TIMEOUT = 30.0;
	private const LOCK_TIMEOUT    = 30.0;

	/** @return array<string,mixed> */
	public function refresh( string $workspace, string $repo, string $remote = 'origin' ): array {
		$started        = microtime(true);
		$workspace_real = realpath($workspace);
		$repo           = trim($repo);
		$remote         = trim($remote);
		$context        = $this->context($repo, '', $remote);

		if ( false === $workspace_real || ! is_dir($workspace_real) ) {
			return $this->result('refused', 'workspace_not_found', $context, $started);
		}
		$parsed = WorkspaceHandle::parse($repo)->to_array();
		if ( '' === $repo || $repo !== $parsed['dir_name'] || $parsed['is_worktree'] ) {
			return $this->result('refused', 'invalid_primary_handle', $context, $started);
		}
		if ( 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $remote) ) {
			return $this->result('refused', 'invalid_remote', $context, $started);
		}
		if ( 'origin' !== $remote ) {
			return $this->result('refused', 'unsupported_freshness_remote', $context, $started);
		}

		$path = $workspace_real . DIRECTORY_SEPARATOR . $repo;
		$real = realpath($path);
		$context['path'] = false === $real ? $path : $real;
		if ( false === $real || dirname($real) !== $workspace_real || basename($real) !== $repo || ! is_dir($real . '/.git') ) {
			return $this->result('refused', 'authoritative_primary_not_found', $context, $started);
		}
		$top = $this->git($real, array( 'rev-parse', '--show-toplevel' ));
		if ( ! $top['success'] || realpath(trim($top['stdout'])) !== $real ) {
			return $this->result('refused', 'authoritative_primary_invalid', $context, $started);
		}
		if ( ! $this->git($real, array( 'remote', 'get-url', $remote ))['success'] ) {
			return $this->result('refused', 'remote_not_configured', $context, $started);
		}

		$lock = $this->acquire_lock($workspace_real, $repo);
		if ( null === $lock ) {
			return $this->result('refused', 'primary_refresh_lock_unavailable', $context, $started);
		}

		try {
			return $this->refresh_locked($workspace_real, $real, $repo, $remote, $context, $started);
		} finally {
			StandaloneFileLock::release($lock);
		}
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private function refresh_locked( string $workspace, string $path, string $repo, string $remote, array $context, float $started ): array {
		$top = $this->git($path, array( 'rev-parse', '--show-toplevel' ));
		if ( ! $top['success'] || realpath(trim($top['stdout'])) !== $path || ! is_dir($path . '/.git') ) {
			return $this->result('refused', 'primary_identity_changed', $context, $started);
		}

		$status = $this->git($path, array( 'status', '--porcelain=v1', '--untracked-files=normal' ));
		if ( ! $status['success'] ) {
			return $this->result('error', $status['timed_out'] ? 'primary_status_timeout' : 'primary_status_failed', $context, $started);
		}
		if ( '' !== trim($status['stdout']) ) {
			return $this->result('refused', 'dirty_working_tree', $context, $started);
		}

		$before = $this->head($path);
		if ( null === $before ) {
			return $this->result('error', 'primary_head_unknown', $context, $started);
		}
		$context['old_sha'] = $before;
		$context['new_sha'] = $before;
		$branch             = $this->branch($path);

		if ( null === $branch ) {
			$repair = $this->repair_detached($path, $repo, $remote);
			if ( ! $repair['success'] ) {
				$context['fetched'] = $repair['fetched'];
				return $this->result('refused', $repair['reason'], $context, $started);
			}
			$branch                    = $repair['branch'];
			$context['branch']          = $branch;
			$context['upstream']        = $remote . '/' . $branch;
			$context['detached_repair'] = $repair['receipt'];
			$context['fetched']         = $repair['fetched'];
		} else {
			$context['branch'] = $branch;
			$default           = $this->verified_default($path, $remote, 'primary');
			if ( ! $default['success'] ) {
				return $this->result('refused', $default['reason'], $context, $started);
			}
			$context['fetched'] = $default['fetched'];
			if ( $branch === $default['branch'] ) {
				$counts = $this->ahead_behind($path, $before, $default['sha']);
				if ( null === $counts ) {
					return $this->result('error', 'primary_divergence_unverified', $context, $started);
				}
				if ( 0 < $counts['ahead'] && 0 < $counts['behind'] ) {
					$recovery = $this->recover_divergence($workspace, $path, $repo, $remote, $branch, $before, $default, $counts);
					$context['recovery'] = $recovery['recovery'];
					$context['fetched']  = $recovery['fetched'];
					if ( ! $recovery['success'] ) {
						return $this->result('refused', $recovery['reason'], $context, $started);
					}
					$context['new_sha'] = $recovery['sha'];
				}
			}
		}

		$upstream = $this->ensure_upstream($path, $remote, $branch);
		if ( ! $upstream['success'] ) {
			return $this->result('refused', $upstream['reason'], $context, $started);
		}
		$context['upstream'] = $upstream['upstream'];

		$fetch = $this->git($path, array( 'fetch', '--no-tags', '--prune', $remote ));
		if ( ! $fetch['success'] ) {
			return $this->result('refused', $fetch['timed_out'] ? 'primary_refresh_fetch_timeout' : 'primary_refresh_fetch_failed', $context, $started);
		}
		$context['fetched'] = array_merge($context['fetched'], $this->remote_evidence($path, $remote));

		$upstream_sha = $this->rev_parse($path, '@{upstream}^{commit}');
		$current      = $this->head($path);
		if ( null === $upstream_sha || null === $current ) {
			return $this->result('refused', 'primary_refresh_upstream_unavailable', $context, $started);
		}
		$counts = $this->ahead_behind($path, $current, $upstream_sha);
		if ( null === $counts ) {
			return $this->result('error', 'primary_refresh_state_unverified', $context, $started);
		}
		$context['fetched']['ahead']  = $counts['ahead'];
		$context['fetched']['behind'] = $counts['behind'];
		if ( 0 < $counts['ahead'] ) {
			$reason = 0 < $counts['behind'] ? 'primary_refresh_diverged' : 'primary_refresh_ahead';
			return $this->result('refused', $reason, $context, $started);
		}

		$pull = $this->git($path, array( 'pull', '--ff-only' ));
		if ( ! $pull['success'] ) {
			return $this->result('error', $pull['timed_out'] ? 'primary_refresh_timeout' : 'primary_refresh_failed', $context, $started);
		}

		$after       = $this->head($path);
		$post_branch = $this->branch($path);
		$post_status = $this->git($path, array( 'status', '--porcelain=v1', '--untracked-files=normal' ));
		$post_target = $this->rev_parse($path, '@{upstream}^{commit}');
		$context['new_sha'] = $after;
		if ( null === $after || $branch !== $post_branch || null === $post_target || $after !== $post_target || ! $post_status['success'] || '' !== trim($post_status['stdout']) ) {
			return $this->result('error', 'primary_refresh_postcondition_failed', $context, $started);
		}

		$origin = $this->remote_evidence($path, 'origin');
		if ( ! isset($origin['remote_refs_digest'], $origin['ref_heads']) ) {
			return $this->result('error', 'freshness_evidence_unavailable', $context, $started);
		}
		$evidence = array(
			'version'            => 2,
			'remote_refs_digest' => $origin['remote_refs_digest'],
			'ref_heads'          => $origin['ref_heads'],
			'observed_at'        => gmdate('c'),
		);
		if ( ! WorktreeFreshnessEvidence::store($path, $evidence) ) {
			return $this->result('error', 'freshness_evidence_store_failed', $context, $started);
		}
		$context['freshness_evidence'] = WorktreeFreshnessEvidence::read($path);
		$context['changed']            = $before !== $after;

		return $this->result($context['changed'] ? 'refreshed' : 'current', null, $context, $started);
	}

	/** @return array{success:bool,reason:?string,branch:string,fetched:array<string,mixed>,receipt:?array<string,mixed>} */
	private function repair_detached( string $path, string $repo, string $remote ): array {
		$before  = $this->head($path);
		$default = $this->detached_default($path, $remote);
		if ( null === $before ) {
			return $this->repair_failure('detached_primary_head_unknown');
		}
		if ( ! $default['success'] ) {
			return $this->repair_failure($default['reason'], $default['fetched']);
		}

		$fetch = $this->fetch_ref($path, $remote, $default['branch']);
		if ( ! $fetch['success'] ) {
			return $this->repair_failure($fetch['timed_out'] ? 'detached_primary_fetch_timeout' : 'detached_primary_fetch_failed', $default['fetched']);
		}
		$target = $this->rev_parse($path, $default['remote_ref'] . '^{commit}');
		if ( null === $target ) {
			return $this->repair_failure('detached_primary_default_ref_missing', $default['fetched']);
		}
		$fetched = array_merge($default['fetched'], array(
			'default_branch' => $default['branch'],
			'default_ref'    => $default['remote_ref'],
			'default_sha'    => $target,
		));

		$preservation = null;
		if ( ! $this->is_ancestor($path, $before, $target) ) {
			$preserved = $this->find_detached_preservation($path, $remote, $before);
			if ( ! $preserved['success'] ) {
				return $this->repair_failure($preserved['reason'], $fetched);
			}
			$preservation = $preserved['preservation'];
			if ( null === $preservation ) {
				return $this->repair_failure('detached_primary_diverged', $fetched);
			}
		}

		$local_ref = 'refs/heads/' . $default['branch'];
		$local     = $this->rev_parse($path, $local_ref . '^{commit}');
		$repointed = false;
		if ( null !== $local && $before !== $local ) {
			if ( ! $this->is_ancestor($path, $local, $target) ) {
				return $this->repair_failure('detached_primary_local_branch_diverged', $fetched);
			}
			$holder = $this->branch_holder($path, $default['branch']);
			if ( null !== $holder ) {
				$fetched['holder'] = $holder;
				return $this->repair_failure('detached_primary_default_branch_held', $fetched);
			}
			$update = $this->git($path, array( 'update-ref', $local_ref, $target, $local ));
			if ( ! $update['success'] ) {
				return $this->repair_failure('detached_primary_local_branch_repoint_failed', $fetched);
			}
			$repointed = true;
		}

		$checkout = null === $local
			? $this->git($path, array( 'checkout', '--track', '-b', $default['branch'], $remote . '/' . $default['branch'] ))
			: $this->git($path, array( 'checkout', $default['branch'] ));
		if ( ! $checkout['success'] ) {
			return $this->repair_failure('detached_primary_checkout_failed', $fetched);
		}

		return array(
			'success' => true,
			'reason'  => null,
			'branch'  => $default['branch'],
			'fetched' => $fetched,
			'receipt' => array(
				'head_before'            => $before,
				'head_after'             => $this->head($path),
				'branch'                 => $default['branch'],
				'upstream'               => $remote . '/' . $default['branch'],
				'branch_repointed'       => $repointed,
				'default_branch_sources' => $default['sources'],
				'preservation'           => $preservation,
			),
		);
	}

	/** @return array{success:bool,reason:?string,branch:string,remote_ref:string,sources:array<int,array<string,string>>,fetched:array<string,mixed>} */
	private function detached_default( string $path, string $remote ): array {
		$sources    = array();
		$prefix     = 'refs/remotes/' . $remote . '/';
		$remote_ref = $this->git($path, array( 'symbolic-ref', '--quiet', 'refs/remotes/' . $remote . '/HEAD' ));
		$ref        = $remote_ref['success'] ? trim($remote_ref['stdout']) : '';
		if ( '' !== $ref && str_starts_with($ref, $prefix) ) {
			$branch = substr($ref, strlen($prefix));
			if ( '' !== $branch && $this->valid_branch($path, $branch) && null !== $this->rev_parse($path, $ref . '^{commit}') ) {
				$sources[] = array( 'source' => 'local_symbolic_ref', 'status' => 'validated' );
				return array( 'success' => true, 'reason' => null, 'branch' => $branch, 'remote_ref' => $ref, 'sources' => $sources, 'fetched' => array() );
			}
			$sources[] = array( 'source' => 'local_symbolic_ref', 'status' => 'stale' );
		} else {
			$sources[] = array( 'source' => 'local_symbolic_ref', 'status' => '' === $ref ? 'unavailable' : 'malformed' );
		}

		$live = $this->git($path, array( 'ls-remote', '--symref', $remote, 'HEAD' ));
		if ( ! $live['success'] ) {
			$sources[] = array( 'source' => 'remote_symref', 'status' => 'unavailable' );
			return array( 'success' => false, 'reason' => 'detached_primary_default_branch_unavailable', 'branch' => '', 'remote_ref' => '', 'sources' => $sources, 'fetched' => array( 'default_branch_sources' => $sources ) );
		}
		if ( 1 !== preg_match('/^ref: refs\/heads\/([^\s]+)\s+HEAD$/m', $live['stdout'], $matches) || ! $this->valid_branch($path, $matches[1]) ) {
			$sources[] = array( 'source' => 'remote_symref', 'status' => 'ambiguous' );
			return array( 'success' => false, 'reason' => 'detached_primary_default_branch_ambiguous', 'branch' => '', 'remote_ref' => '', 'sources' => $sources, 'fetched' => array( 'default_branch_sources' => $sources ) );
		}
		$sources[] = array( 'source' => 'remote_symref', 'status' => 'validated' );
		return array(
			'success'    => true,
			'reason'     => null,
			'branch'     => $matches[1],
			'remote_ref' => $prefix . $matches[1],
			'sources'    => $sources,
			'fetched'    => array(),
		);
	}

	/** @return array{success:bool,reason:?string,branch:string,sha:string,fetched:array<string,mixed>} */
	private function verified_default( string $path, string $remote, string $prefix ): array {
		$live = $this->git($path, array( 'ls-remote', '--symref', $remote, 'HEAD' ));
		if ( ! $live['success'] ) {
			return array( 'success' => false, 'reason' => $prefix . '_default_branch_unavailable', 'branch' => '', 'sha' => '', 'fetched' => array() );
		}
		if ( 1 !== preg_match('/^ref: refs\/heads\/([^\s]+)\s+HEAD$/m', $live['stdout'], $matches) || ! $this->valid_branch($path, $matches[1]) ) {
			return array( 'success' => false, 'reason' => $prefix . '_default_branch_ambiguous', 'branch' => '', 'sha' => '', 'fetched' => array() );
		}
		$branch = $matches[1];
		$fetch  = $this->fetch_ref($path, $remote, $branch);
		if ( ! $fetch['success'] ) {
			return array( 'success' => false, 'reason' => $prefix . '_fetch_failed', 'branch' => $branch, 'sha' => '', 'fetched' => array( 'default_branch' => $branch ) );
		}
		$ref = 'refs/remotes/' . $remote . '/' . $branch;
		$sha = $this->rev_parse($path, $ref . '^{commit}');
		if ( null === $sha ) {
			return array( 'success' => false, 'reason' => $prefix . '_default_ref_missing', 'branch' => $branch, 'sha' => '', 'fetched' => array( 'default_branch' => $branch, 'default_ref' => $ref ) );
		}
		return array(
			'success' => true,
			'reason'  => null,
			'branch'  => $branch,
			'sha'     => $sha,
			'fetched' => array( 'default_branch' => $branch, 'default_ref' => $ref, 'default_sha' => $sha ),
		);
	}

	/**
	 * @param array<string,mixed> $default
	 * @param array{ahead:int,behind:int} $counts
	 * @return array{success:bool,reason:?string,sha:string,recovery:?array<string,mixed>,fetched:array<string,mixed>}
	 */
	private function recover_divergence( string $workspace, string $path, string $repo, string $remote, string $branch, string $local_sha, array $default, array $counts ): array {
		$holder = $this->branch_holder($path, $branch);
		if ( null !== $holder ) {
			return array( 'success' => false, 'reason' => 'primary_divergence_default_branch_held', 'sha' => $local_sha, 'recovery' => array( 'holder' => $holder ), 'fetched' => $default['fetched'] );
		}
		$preserved = $this->preserve_divergence($workspace, $path, $repo, $branch, $local_sha);
		if ( ! $preserved['success'] ) {
			return array( 'success' => false, 'reason' => $preserved['reason'], 'sha' => $local_sha, 'recovery' => $preserved['recovery'], 'fetched' => $default['fetched'] );
		}

		$recovery = array(
			'branch'       => $branch,
			'local_sha'    => $local_sha,
			'remote_sha'   => $default['sha'],
			'ahead'        => $counts['ahead'],
			'behind'       => $counts['behind'],
			'preservation' => $preserved['recovery'],
		);
		$verified = $this->verified_default($path, $remote, 'primary_divergence');
		if ( ! $verified['success'] ) {
			return array( 'success' => false, 'reason' => 'primary_divergence_verification_failed', 'sha' => $local_sha, 'recovery' => $recovery, 'fetched' => $verified['fetched'] );
		}
		$status = $this->git($path, array( 'status', '--porcelain=v1', '--untracked-files=normal' ));
		if ( $verified['branch'] !== $branch || $verified['sha'] !== $default['sha'] || $this->head($path) !== $local_sha || ! $status['success'] || '' !== trim($status['stdout']) ) {
			return array( 'success' => false, 'reason' => 'primary_divergence_changed_during_recovery', 'sha' => $local_sha, 'recovery' => $recovery, 'fetched' => $verified['fetched'] );
		}

		$update = $this->git($path, array( 'update-ref', 'refs/heads/' . $branch, $verified['sha'], $local_sha ));
		if ( ! $update['success'] ) {
			return array( 'success' => false, 'reason' => 'primary_divergence_changed_during_recovery', 'sha' => $local_sha, 'recovery' => $recovery, 'fetched' => $verified['fetched'] );
		}
		$reset = $this->git($path, array( 'reset', '--hard', $verified['sha'] ));
		if ( ! $reset['success'] ) {
			return array( 'success' => false, 'reason' => 'primary_divergence_refresh_failed', 'sha' => $this->head($path) ?? $local_sha, 'recovery' => $recovery, 'fetched' => $verified['fetched'] );
		}

		return array( 'success' => true, 'reason' => null, 'sha' => $verified['sha'], 'recovery' => $recovery, 'fetched' => $verified['fetched'] );
	}

	/** @return array{success:bool,reason:?string,recovery:?array<string,mixed>} */
	private function preserve_divergence( string $workspace, string $primary, string $repo, string $default_branch, string $sha ): array {
		$slug     = 'primary-recovery-' . substr($sha, 0, 12);
		$branch   = 'recovery/' . $slug;
		$ref      = 'refs/heads/' . $branch;
		$handle   = $repo . '@' . $slug;
		$path     = $workspace . '/' . $handle;
		$existing = $this->rev_parse($primary, $ref . '^{commit}');
		$created  = false;
		$partial  = array( 'branch' => $branch, 'ref' => $ref, 'commit' => $sha, 'handle' => $handle, 'path' => $path );

		if ( null !== $existing && $existing !== $sha ) {
			return array( 'success' => false, 'reason' => 'primary_divergence_recovery_branch_conflict', 'recovery' => $partial );
		}
		if ( null === $existing ) {
			$create = $this->git($primary, array( 'branch', $branch, $sha ));
			if ( ! $create['success'] ) {
				return array( 'success' => false, 'reason' => 'primary_divergence_unpreservable', 'recovery' => $partial );
			}
			$created = true;
		}

		$listed = $this->recovery_worktree($primary, $path, $ref);
		if ( 'conflict' === $listed || ( 'missing' === $listed && is_dir($path) ) ) {
			if ( $created ) {
				$this->git($primary, array( 'branch', '-D', $branch ));
			}
			$partial['rolled_back'] = $created;
			return array( 'success' => false, 'reason' => 'primary_divergence_recovery_path_conflict', 'recovery' => $partial );
		}
		if ( 'missing' === $listed ) {
			$add = $this->git($primary, array( 'worktree', 'add', $path, $branch ));
			if ( ! $add['success'] ) {
				if ( $created ) {
					$this->git($primary, array( 'branch', '-D', $branch ));
				}
				$partial['rolled_back'] = $created;
				return array( 'success' => false, 'reason' => 'primary_divergence_unpreservable', 'recovery' => $partial );
			}
			$listed = $this->recovery_worktree($primary, $path, $ref);
		}
		if ( 'expected' !== $listed || $this->head($path) !== $sha ) {
			return array( 'success' => false, 'reason' => 'primary_divergence_recovery_unverified', 'recovery' => $partial );
		}
		$metadata = $this->store_recovery_metadata($path, array(
			'repo'           => $repo,
			'handle'         => $handle,
			'path'           => $path,
			'branch'         => $branch,
			'base_ref'       => $default_branch,
			'commit'         => $sha,
			'state'          => 'preserved',
			'cleanup_policy' => 'manual',
		));
		if ( null === $metadata ) {
			return array( 'success' => false, 'reason' => 'primary_divergence_recovery_metadata_failed', 'recovery' => $partial );
		}

		$partial['status'] = 'preserved';
		$partial['reused'] = ! $created;
		$partial['metadata'] = $metadata;
		return array( 'success' => true, 'reason' => null, 'recovery' => $partial );
	}

	/** @param array<string,mixed> $metadata @return array<string,mixed>|null */
	private function store_recovery_metadata( string $path, array $metadata ): ?array {
		$git_dir = $this->git($path, array( 'rev-parse', '--absolute-git-dir' ));
		$git_dir = $git_dir['success'] ? realpath(trim($git_dir['stdout'])) : false;
		if ( false === $git_dir || ! is_dir($git_dir) ) {
			return null;
		}
		$record = array_merge(
			array(
				'schema'      => 'datamachine-code/primary-recovery/v1',
				'observed_at' => gmdate('c'),
			),
			$metadata
		);
		$encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ( ! is_string($encoded) ) {
			return null;
		}
		$target = $git_dir . '/datamachine-code-primary-recovery.json';
		$temp   = $target . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
		if ( false === @file_put_contents($temp, $encoded, LOCK_EX) || ! @rename($temp, $target) ) {
			@unlink($temp);
			return null;
		}
		return $record;
	}

	private function recovery_worktree( string $primary, string $expected_path, string $expected_ref ): string {
		$listing = $this->git($primary, array( 'worktree', 'list', '--porcelain' ));
		if ( ! $listing['success'] ) {
			return 'conflict';
		}
		foreach ( preg_split('/\n\n+/', trim($listing['stdout'])) ?: array() as $block ) {
			$path = null;
			$ref  = null;
			foreach ( explode("\n", $block) as $line ) {
				if ( str_starts_with($line, 'worktree ') ) {
					$path = substr($line, 9);
				} elseif ( str_starts_with($line, 'branch ') ) {
					$ref = substr($line, 7);
				}
			}
			if ( $path === $expected_path ) {
				return $ref === $expected_ref ? 'expected' : 'conflict';
			}
			if ( $ref === $expected_ref ) {
				return 'conflict';
			}
		}
		return 'missing';
	}

	/** @return array{success:bool,reason:?string,preservation:?array<string,string>} */
	private function find_detached_preservation( string $path, string $remote, string $head ): array {
		$fetch = $this->git($path, array( 'fetch', '--no-tags', '--prune', $remote ));
		if ( ! $fetch['success'] ) {
			return array( 'success' => false, 'reason' => 'detached_primary_preservation_fetch_failed', 'preservation' => null );
		}
		$refs = $this->git($path, array( 'for-each-ref', '--format=%(refname) %(objectname)', 'refs/remotes/' . $remote ));
		if ( ! $refs['success'] ) {
			return array( 'success' => false, 'reason' => 'detached_primary_preservation_refs_unavailable', 'preservation' => null );
		}
		foreach ( explode("\n", trim($refs['stdout'])) as $line ) {
			if ( 1 !== preg_match('#^(refs/remotes/' . preg_quote($remote, '#') . '/(.+)) ([a-f0-9]{40,64})$#i', $line, $matches) || str_ends_with($matches[1], '/HEAD') || ! $this->is_ancestor($path, $head, $matches[1]) ) {
				continue;
			}
			$live = $this->git($path, array( 'ls-remote', '--heads', $remote, $matches[2] ));
			if ( ! $live['success'] || 1 !== preg_match('/^' . preg_quote($matches[3], '/') . '\s+refs\/heads\/' . preg_quote($matches[2], '/') . '$/mi', $live['stdout']) ) {
				return array( 'success' => false, 'reason' => 'detached_primary_preservation_ref_changed', 'preservation' => null );
			}
			return array( 'success' => true, 'reason' => null, 'preservation' => array( 'remote' => $remote, 'ref' => 'refs/heads/' . $matches[2], 'commit' => $head ) );
		}

		$tags = $this->git($path, array( 'ls-remote', '--tags', $remote ));
		if ( ! $tags['success'] ) {
			return array( 'success' => false, 'reason' => 'detached_primary_preservation_tags_unavailable', 'preservation' => null );
		}
		foreach ( explode("\n", trim($tags['stdout'])) as $line ) {
			if ( 1 !== preg_match('/^([a-f0-9]{40,64})\s+(refs\/tags\/.+)$/i', $line, $matches) || str_ends_with($matches[2], '^{}') ) {
				continue;
			}
			$fetched = $this->git($path, array( 'fetch', '--no-tags', $remote, $matches[2] ));
			$commit  = $fetched['success'] ? $this->rev_parse($path, 'FETCH_HEAD^{commit}') : null;
			if ( $commit !== $head ) {
				continue;
			}
			$live = $this->git($path, array( 'ls-remote', '--tags', $remote, $matches[2] ));
			if ( ! $live['success'] || ! str_contains($live['stdout'], $matches[2]) ) {
				return array( 'success' => false, 'reason' => 'detached_primary_preservation_ref_changed', 'preservation' => null );
			}
			return array( 'success' => true, 'reason' => null, 'preservation' => array( 'remote' => $remote, 'ref' => $matches[2], 'commit' => $head ) );
		}
		return array( 'success' => true, 'reason' => null, 'preservation' => null );
	}

	/** @return array{success:bool,reason:?string,upstream:string} */
	private function ensure_upstream( string $path, string $remote, string $branch ): array {
		$upstream = $this->git($path, array( 'rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{upstream}' ));
		if ( $upstream['success'] && '' !== trim($upstream['stdout']) ) {
			return array( 'success' => true, 'reason' => null, 'upstream' => trim($upstream['stdout']) );
		}
		$live = $this->git($path, array( 'ls-remote', '--heads', $remote, $branch ));
		if ( ! $live['success'] || 1 !== preg_match('/^[a-f0-9]{40,64}\s+refs\/heads\/' . preg_quote($branch, '/') . '$/mi', $live['stdout']) ) {
			return array( 'success' => false, 'reason' => 'primary_refresh_upstream_missing', 'upstream' => '' );
		}
		$fetch = $this->fetch_ref($path, $remote, $branch);
		$set   = $fetch['success'] ? $this->git($path, array( 'branch', '--set-upstream-to=' . $remote . '/' . $branch, $branch )) : $fetch;
		return $set['success']
			? array( 'success' => true, 'reason' => null, 'upstream' => $remote . '/' . $branch )
			: array( 'success' => false, 'reason' => 'primary_refresh_upstream_setup_failed', 'upstream' => '' );
	}

	/** @return array<string,mixed>|null */
	private function branch_holder( string $primary, string $branch ): ?array {
		$listing = $this->git($primary, array( 'worktree', 'list', '--porcelain' ));
		if ( ! $listing['success'] ) {
			return array( 'path' => null, 'branch' => $branch, 'unverified' => true );
		}
		$path = WorktreeBranchHolder::find($listing['stdout'], $primary, $branch);
		return null === $path ? null : array( 'path' => $path, 'handle' => basename($path), 'branch' => $branch );
	}

	/** @return array{ahead:int,behind:int}|null */
	private function ahead_behind( string $path, string $left, string $right ): ?array {
		$result = $this->git($path, array( 'rev-list', '--left-right', '--count', $left . '...' . $right ));
		if ( ! $result['success'] || 1 !== preg_match('/^(\d+)\s+(\d+)$/D', trim($result['stdout']), $matches) ) {
			return null;
		}
		return array( 'ahead' => (int) $matches[1], 'behind' => (int) $matches[2] );
	}

	/** @return array<string,mixed> */
	private function remote_evidence( string $path, string $remote ): array {
		$refs = $this->git($path, array( 'for-each-ref', '--format=%(refname) %(objectname)', 'refs/remotes/' . $remote ));
		if ( ! $refs['success'] ) {
			return array();
		}
		$heads = array();
		foreach ( explode("\n", $refs['stdout']) as $line ) {
			$parts = preg_split('/\s+/', trim($line), 2);
			if ( 2 === count($parts) && preg_match('/^[0-9a-f]{40,64}$/i', $parts[1]) ) {
				$heads[ $parts[0] ] = $parts[1];
			}
		}
		return array( 'remote_refs_digest' => hash('sha256', rtrim($refs['stdout'], "\r\n")), 'ref_heads' => $heads );
	}

	/** @return array{success:bool,stdout:string,stderr:string,timed_out:bool,exit_code:int} */
	private function fetch_ref( string $path, string $remote, string $branch ): array {
		return $this->git($path, array( 'fetch', '--no-tags', $remote, 'refs/heads/' . $branch . ':refs/remotes/' . $remote . '/' . $branch ));
	}

	private function valid_branch( string $path, string $branch ): bool {
		return $this->git($path, array( 'check-ref-format', 'refs/heads/' . $branch ))['success'];
	}

	private function is_ancestor( string $path, string $ancestor, string $descendant ): bool {
		return $this->git($path, array( 'merge-base', '--is-ancestor', $ancestor, $descendant ))['success'];
	}

	private function branch( string $path ): ?string {
		$result = $this->git($path, array( 'symbolic-ref', '--quiet', '--short', 'HEAD' ));
		$branch = trim($result['stdout']);
		return $result['success'] && '' !== $branch ? $branch : null;
	}

	private function head( string $path ): ?string {
		return $this->rev_parse($path, 'HEAD^{commit}');
	}

	private function rev_parse( string $path, string $ref ): ?string {
		$result = $this->git($path, array( 'rev-parse', '--verify', $ref ));
		$sha    = trim($result['stdout']);
		return $result['success'] && 1 === preg_match('/^[0-9a-f]{40,64}$/D', $sha) ? $sha : null;
	}

	/** @return resource|null */
	private function acquire_lock( string $workspace, string $repo ) {
		$directory = $workspace . '/.locks';
		if ( is_link($directory) || ( ! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory) ) ) {
			return null;
		}
		return StandaloneFileLock::acquire($directory . '/worktree-' . $repo . '.lock', self::LOCK_TIMEOUT, 100000);
	}

	/** @return array{success:bool,stdout:string,stderr:string,timed_out:bool,exit_code:int} */
	private function git( string $path, array $arguments ): array {
		$command = array_merge(array( 'git', '--no-optional-locks', '-C', $path ), $arguments);
		$env     = getenv();
		$env     = is_array($env) ? $env : array();
		$env['GIT_TERMINAL_PROMPT'] = '0';
		$process = proc_open($command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env);
		if ( ! is_resource($process) ) {
			return array( 'success' => false, 'stdout' => '', 'stderr' => 'Could not start Git.', 'timed_out' => false, 'exit_code' => -1 );
		}
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$started = microtime(true);
		$stdout  = '';
		$stderr  = '';
		$exit    = -1;
		$timeout = false;
		while ( true ) {
			$stdout .= stream_get_contents($pipes[1]);
			$stderr .= stream_get_contents($pipes[2]);
			$status  = proc_get_status($process);
			if ( ! $status['running'] ) {
				$exit = (int) $status['exitcode'];
				break;
			}
			if ( microtime(true) - $started >= self::COMMAND_TIMEOUT ) {
				$timeout = true;
				proc_terminate($process, 15);
				usleep(50000);
				$status = proc_get_status($process);
				if ( $status['running'] ) {
					proc_terminate($process, 9);
				}
				break;
			}
			usleep(10000);
		}
		$stdout .= stream_get_contents($pipes[1]);
		$stderr .= stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);
		return array( 'success' => ! $timeout && 0 === $exit, 'stdout' => $stdout, 'stderr' => $stderr, 'timed_out' => $timeout, 'exit_code' => $exit );
	}

	/** @return array<string,mixed> */
	private function context( string $repo, string $path, string $remote ): array {
		return array(
			'repo'               => $repo,
			'path'               => $path,
			'remote'             => $remote,
			'old_sha'            => null,
			'new_sha'            => null,
			'branch'             => null,
			'upstream'           => null,
			'changed'            => false,
			'fetched'            => array(),
			'recovery'           => null,
			'detached_repair'     => null,
			'freshness_evidence' => null,
		);
	}

	/** @param array<string,mixed> $context @return array<string,mixed> */
	private function result( string $status, ?string $reason, array $context, float $started ): array {
		return array_merge(
			array(
				'schema'     => self::SCHEMA,
				'status'     => $status,
				'reason'     => $reason,
				'latency_ms' => (int) round(( microtime(true) - $started ) * 1000),
			),
			$context
		);
	}

	/** @param array<string,mixed> $fetched @return array{success:bool,reason:string,branch:string,fetched:array<string,mixed>,receipt:null} */
	private function repair_failure( string $reason, array $fetched = array() ): array {
		return array( 'success' => false, 'reason' => $reason, 'branch' => '', 'fetched' => $fetched, 'receipt' => null );
	}
}
