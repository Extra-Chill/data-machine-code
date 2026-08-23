<?php
/**
 * Standalone worktree identity and safety provider.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || defined('DATAMACHINE_CODE_STANDALONE') || exit;

final class StandaloneWorktreeProvider {

	private const IDENTITY_SCHEMA = 'datamachine-code/worktree-identity/v1';
	private const SAFETY_SCHEMA   = 'datamachine-code/worktree-safety/v1';
	private const CONVERGE_SCHEMA = 'datamachine-code/worktree-convergence/v1';
	private const TOKEN_PREFIX    = 'dmc-worktree-v1.';
	private const PROBE_TIMEOUT   = 2.0;
	private const LOCK_TIMEOUT    = 2.0;

	/**
	 * Resolve immutable local identity without loading WordPress or probing safety.
	 *
	 * @return array<string,mixed>
	 */
	public function resolve_identity( string $workspace, string $handle ): array {
		$started        = microtime(true);
		$workspace_real = realpath($workspace);
		$parsed         = WorkspaceHandle::parse($handle)->to_array();

		if ( false === $workspace_real || ! is_dir($workspace_real) ) {
			return $this->error('workspace_not_found', 'The canonical workspace root does not exist.', $started);
		}
		if ( $handle !== $parsed['dir_name'] || '' === $parsed['repo'] ) {
			return $this->not_owned('invalid_handle', $handle, $started);
		}

		$path = $workspace_real . DIRECTORY_SEPARATOR . $parsed['dir_name'];
		$real = realpath($path);
		if ( false === $real || dirname($real) !== $workspace_real || ! file_exists($real . '/.git') ) {
			return $this->not_owned('worktree_not_found', $parsed['dir_name'], $started);
		}

		$branch = $this->read_branch($real);
		if ( null === $branch || '' === $branch ) {
			return $this->not_owned('branch_not_found', $parsed['dir_name'], $started);
		}
		$git_dir = $this->git_directory($real);
		if ( null === $git_dir ) {
			return $this->not_owned('git_directory_not_found', $parsed['dir_name'], $started);
		}

		$identity = array(
			'handle'  => $parsed['dir_name'],
			'path'    => $real,
			'branch'  => $branch,
			'primary' => ! $parsed['is_worktree'],
			'git_dir' => $git_dir,
		);

		return array_merge(
			array(
				'schema'     => self::IDENTITY_SCHEMA,
				'status'     => 'owned',
				'ownership'  => 'owned',
				'token'      => $this->encode_token($identity),
				'latency_ms' => $this->elapsed_ms($started),
			),
			$identity
		);
	}

	/**
	 * Attest mutable local safety for an identity token returned above.
	 *
	 * @return array<string,mixed>
	 */
	public function attest_safety( string $workspace, string $token ): array {
		$started  = microtime(true);
		$identity = $this->decode_token($token);
		if ( null === $identity ) {
			return $this->error('invalid_identity_token', 'The worktree identity token is invalid.', $started);
		}

		$current = $this->resolve_identity($workspace, $identity['handle']);
		$fresh   = $this->identity_is_fresh($identity, $current);
		if ( ! $fresh ) {
			return $this->safety_result($token, false, false, false, $started);
		}

		$status = $this->run_git($identity['path'], array( 'status', '--porcelain=v1', '--branch', '--untracked-files=normal' ));
		if ( ! $status['success'] ) {
			return $this->error($status['timed_out'] ? 'safety_probe_timeout' : 'safety_probe_failed', 'Could not inspect worktree status.', $started);
		}

		$lines    = preg_split('/\r?\n/', trim($status['stdout'])) ?: array();
		$header   = str_starts_with((string) ( $lines[0] ?? '' ), '## ') ? (string) array_shift($lines) : '';
		$dirty    = array_filter($lines, static fn( string $line ): bool => '' !== trim($line)) !== array();
		$unpushed = 0;
		if ( preg_match('/ahead (\d+)/', $header, $match) ) {
			$unpushed = (int) $match[1];
		} else {
			foreach ( array( '@{push}..HEAD', '@{upstream}..HEAD' ) as $range ) {
				$result = $this->run_git($identity['path'], array( 'rev-list', '--count', $range ));
				if ( $result['timed_out'] ) {
					return $this->error('safety_probe_timeout', 'The unpushed commit probe timed out.', $started);
				}
				$count = trim($result['stdout']);
				if ( $result['success'] && '' !== $count && ctype_digit($count) ) {
					$unpushed = (int) $count;
					break;
				}
			}
		}

		return $this->safety_result($token, $dirty, 0 !== $unpushed, true, $started);
	}

	/**
	 * Fast-forward a token-bound linked worktree to an already-local base commit.
	 *
	 * @return array<string,mixed>
	 */
	public function converge( string $workspace, string $token, string $base_sha ): array {
		$started = microtime(true);
		if ( ! preg_match('/^[a-fA-F0-9]{40}$/D', $base_sha) ) {
			return $this->convergence_result('refused', 'invalid_base_sha', $token, $base_sha, null, null, $started);
		}

		$identity = $this->decode_token($token);
		if ( null === $identity ) {
			return $this->convergence_result('refused', 'invalid_identity_token', $token, $base_sha, null, null, $started);
		}
		$current = $this->resolve_identity($workspace, $identity['handle']);
		if ( ! $this->identity_is_fresh($identity, $current) ) {
			return $this->convergence_result('refused', 'identity_drift', $token, $base_sha, null, null, $started);
		}

		$lock = $this->acquire_convergence_lock($identity['git_dir']);
		if ( null === $lock ) {
			return $this->convergence_result('refused', 'convergence_lock_unavailable', $token, $base_sha, null, null, $started);
		}

		try {
			// Tests use this hook to mutate state after lock acquisition but before admission.
			$this->run_convergence_test_hook($identity['path']);
			$validation = $this->validate_convergence($workspace, $token, $base_sha, $started);
			if ( null !== $validation['result'] ) {
				return $validation['result'];
			}

			$merge = $this->run_git($validation['path'], array( 'merge', '--ff-only', $base_sha ));
			if ( ! $merge['success'] ) {
				return $this->failed_merge_result($workspace, $validation['path'], $merge, $token, $base_sha, $validation['head'], $started);
			}
			$after = $this->read_head($validation['path']);
			if ( null === $after ) {
				return $this->convergence_result('error', 'convergence_ambiguous', $token, $base_sha, $validation['head'], null, $started);
			}
			if ( $base_sha !== $after ) {
				return $this->convergence_result('error', 'unexpected_post_merge_head', $token, $base_sha, $validation['head'], $after, $started);
			}
			$post_identity = $this->decode_token($token);
			$current       = null === $post_identity ? array() : $this->resolve_identity($workspace, $post_identity['handle']);
			if ( null === $post_identity || ! $this->identity_is_fresh($post_identity, $current) || $post_identity['primary'] ) {
				return $this->convergence_result('error', 'post_merge_identity_drift', $token, $base_sha, $validation['head'], $after, $started);
			}
			$post_status = $this->run_git($validation['path'], array( 'status', '--porcelain=v1', '--untracked-files=normal' ));
			if ( ! $post_status['success'] || '' !== trim($post_status['stdout']) ) {
				return $this->convergence_result('error', ! $post_status['success'] ? 'post_merge_safety_probe_failed' : 'post_merge_dirty', $token, $base_sha, $validation['head'], $after, $started);
			}

			return $this->convergence_result('converged', null, $token, $base_sha, $validation['head'], $after, $started);
		} finally {
			$this->release_convergence_lock($lock);
		}
	}

	/**
	 * @return array{path:string,head:string,result:array<string,mixed>|null}
	 */
	private function validate_convergence( string $workspace, string $token, string $base_sha, float $started ): array {
		$identity = $this->decode_token($token);
		if ( null === $identity ) {
			return $this->convergence_validation('', '', $this->convergence_result('refused', 'invalid_identity_token', $token, $base_sha, null, null, $started));
		}

		$current = $this->resolve_identity($workspace, $identity['handle']);
		if ( ! $this->identity_is_fresh($identity, $current) ) {
			return $this->convergence_validation('', '', $this->convergence_result('refused', 'identity_drift', $token, $base_sha, null, null, $started));
		}
		if ( $identity['primary'] ) {
			return $this->convergence_validation($identity['path'], '', $this->convergence_result('refused', 'primary_worktree', $token, $base_sha, null, null, $started));
		}

		$head = $this->read_head($identity['path']);
		if ( null === $head ) {
			return $this->convergence_validation($identity['path'], '', $this->convergence_result('error', 'head_probe_failed', $token, $base_sha, null, null, $started));
		}
		$base = $this->run_git($identity['path'], array( 'rev-parse', '--verify', $base_sha . '^{commit}' ));
		if ( ! $base['success'] ) {
			return $this->convergence_validation($identity['path'], $head, $this->convergence_result('refused', 'base_not_found', $token, $base_sha, $head, $head, $started));
		}

		$status = $this->run_git($identity['path'], array( 'status', '--porcelain=v1', '--untracked-files=normal' ));
		if ( ! $status['success'] ) {
			return $this->convergence_validation($identity['path'], $head, $this->convergence_result('error', $status['timed_out'] ? 'safety_probe_timeout' : 'safety_probe_failed', $token, $base_sha, $head, $head, $started));
		}
		if ( '' !== trim($status['stdout']) ) {
			return $this->convergence_validation($identity['path'], $head, $this->convergence_result('refused', 'dirty_worktree', $token, $base_sha, $head, $head, $started));
		}
		if ( $head === $base_sha ) {
			return $this->convergence_validation($identity['path'], $head, $this->convergence_result('converged', null, $token, $base_sha, $head, $head, $started));
		}
		$push_safety = $this->has_unpushed_commits($identity['path']);
		if ( ! $push_safety['proven'] ) {
			return $this->convergence_validation($identity['path'], $head, $this->convergence_result('refused', $push_safety['code'], $token, $base_sha, $head, $head, $started));
		}
		if ( $push_safety['unpushed'] ) {
			return $this->convergence_validation($identity['path'], $head, $this->convergence_result('refused', 'unpushed_commits', $token, $base_sha, $head, $head, $started));
		}

		$behind = $this->run_git($identity['path'], array( 'merge-base', '--is-ancestor', 'HEAD', $base_sha ));
		if ( $behind['success'] ) {
			return $this->convergence_validation($identity['path'], $head, null);
		}
		$ahead = $this->run_git($identity['path'], array( 'merge-base', '--is-ancestor', $base_sha, 'HEAD' ));
		$code  = $ahead['success'] ? 'destination_ahead' : 'destination_diverged';
		return $this->convergence_validation($identity['path'], $head, $this->convergence_result('refused', $code, $token, $base_sha, $head, $head, $started));
	}

	/** @return array{path:string,head:string,result:array<string,mixed>|null} */
	private function convergence_validation( string $path, string $head, ?array $result ): array {
		return array( 'path' => $path, 'head' => $head, 'result' => $result );
	}

	/** @return array{proven:bool,unpushed:bool,code:string} */
	private function has_unpushed_commits( string $path ): array {
		$timed_out = false;
		foreach ( array( '@{push}..HEAD', '@{upstream}..HEAD' ) as $range ) {
			$result = $this->run_git($path, array( 'rev-list', '--count', $range ));
			$count  = trim($result['stdout']);
			if ( $result['success'] && '' !== $count && ctype_digit($count) ) {
				return array( 'proven' => true, 'unpushed' => 0 < (int) $count, 'code' => '' );
			}
			$timed_out = $timed_out || $result['timed_out'];
		}
		return array( 'proven' => false, 'unpushed' => false, 'code' => $timed_out ? 'unpushed_probe_timeout' : 'unpushed_probe_failed' );
	}

	/** @param array<string,mixed> $identity @param array<string,mixed> $current */
	private function identity_is_fresh( array $identity, array $current ): bool {
		return 'owned' === ( $current['status'] ?? '' )
			&& $identity['path'] === ( $current['path'] ?? null )
			&& $identity['branch'] === ( $current['branch'] ?? null )
			&& $identity['primary'] === ( $current['primary'] ?? null )
			&& $identity['git_dir'] === ( $current['git_dir'] ?? null );
	}

	/** @return resource|null */
	private function acquire_convergence_lock( string $git_dir ) {
		$stat = @stat($git_dir);
		if ( false === $stat ) {
			return null;
		}
		$key  = hash('sha256', $git_dir . ':' . $stat['dev'] . ':' . $stat['ino']);
		$file = sys_get_temp_dir() . '/dmc-worktree-converge-' . $key . '.lock';
		$lock = @fopen($file, 'c');
		if ( false === $lock ) {
			return null;
		}
		$started = microtime(true);
		while ( ! flock($lock, LOCK_EX | LOCK_NB) ) {
			if ( microtime(true) - $started >= self::LOCK_TIMEOUT ) {
				fclose($lock);
				return null;
			}
			usleep(10000);
		}
		return $lock;
	}

	/** @param resource $lock */
	private function release_convergence_lock( $lock ): void {
		flock($lock, LOCK_UN);
		fclose($lock);
	}

	/**
	 * A failed merge can have changed the worktree before returning. Report only
	 * freshly observed state rather than reusing the admission snapshot.
	 *
	 * @param array{success:bool,stdout:string,stderr:string,timed_out:bool} $merge
	 * @return array<string,mixed>
	 */
	private function failed_merge_result( string $workspace, string $path, array $merge, string $token, string $base_sha, string $before, float $started ): array {
		$after  = $this->read_head($path);
		$status = $this->run_git($path, array( 'status', '--porcelain=v1', '--untracked-files=normal' ));
		if ( null === $after || ! $status['success'] ) {
			return $this->convergence_result('error', 'convergence_ambiguous', $token, $base_sha, $before, $after, $started);
		}
		$identity    = $this->decode_token($token);
		$current     = null === $identity ? array() : $this->resolve_identity($workspace, $identity['handle']);
		$push_safety = $this->has_unpushed_commits($path);
		if ( null === $identity || ! $this->identity_is_fresh($identity, $current) || ! $push_safety['proven'] ) {
			return $this->convergence_result('error', 'convergence_ambiguous', $token, $base_sha, $before, $after, $started);
		}
		if ( $before !== $after || '' !== trim($status['stdout']) || $push_safety['unpushed'] ) {
			return $this->convergence_result('error', 'convergence_mutated_failure', $token, $base_sha, $before, $after, $started);
		}
		return $this->convergence_result('error', $merge['timed_out'] ? 'convergence_timeout' : 'convergence_failed', $token, $base_sha, $before, $after, $started);
	}

	private function read_head( string $path ): ?string {
		$result = $this->run_git($path, array( 'rev-parse', '--verify', 'HEAD^{commit}' ));
		return $result['success'] ? trim($result['stdout']) : null;
	}

	private function run_convergence_test_hook( string $path ): void {
		$hook = getenv('DMC_WORKTREE_PROVIDER_TEST_CONVERGE_HOOK');
		if ( false !== $hook && '' !== $hook ) {
			$process = proc_open(array( $hook, $path ), array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes);
			if ( is_resource($process) ) {
				proc_close($process);
			}
		}
	}

	private function read_branch( string $path ): ?string {
		$git_dir = $this->git_directory($path);
		if ( null === $git_dir ) {
			return null;
		}
		$head = trim((string) @file_get_contents($git_dir . '/HEAD'));
		return str_starts_with($head, 'ref: refs/heads/') ? substr($head, strlen('ref: refs/heads/')) : null;
	}

	private function git_directory( string $path ): ?string {
		$git_entry = $path . '/.git';
		$git_dir   = $git_entry;
		if ( is_file($git_entry) ) {
			$pointer = trim((string) file_get_contents($git_entry));
			if ( ! str_starts_with($pointer, 'gitdir:') ) {
				return null;
			}
			$target  = trim(substr($pointer, strlen('gitdir:')));
			$git_dir = str_starts_with($target, '/') ? $target : $path . '/' . $target;
		}

		$git_dir = realpath($git_dir);
		return false === $git_dir ? null : $git_dir;
	}

	/**
	 * @param array{handle:string,path:string,branch:string,primary:bool,git_dir:string} $identity
	 */
	private function encode_token( array $identity ): string {
		$payload = json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
		return self::TOKEN_PREFIX . hash('sha256', $payload) . '.' . $encoded;
	}

	/**
	 * @return array{handle:string,path:string,branch:string,primary:bool,git_dir:string}|null
	 */
	private function decode_token( string $token ): ?array {
		if ( ! str_starts_with($token, self::TOKEN_PREFIX) ) {
			return null;
		}
		$parts = explode('.', substr($token, strlen(self::TOKEN_PREFIX)), 2);
		if ( 2 !== count($parts) || 64 !== strlen($parts[0]) ) {
			return null;
		}
		$payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
		if ( false === $payload || ! hash_equals($parts[0], hash('sha256', $payload)) ) {
			return null;
		}
		$decoded = json_decode($payload, true);
		if ( ! is_array($decoded)
			|| ! is_string($decoded['handle'] ?? null)
			|| ! is_string($decoded['path'] ?? null)
			|| ! is_string($decoded['branch'] ?? null)
			|| ! is_bool($decoded['primary'] ?? null)
			|| ! is_string($decoded['git_dir'] ?? null) ) {
			return null;
		}
		return $decoded;
	}

	/**
	 * @param array<int,string> $arguments
	 * @return array{success:bool,stdout:string,stderr:string,timed_out:bool}
	 */
	private function run_git( string $path, array $arguments ): array {
		$command = array_merge(array( 'git', '--no-optional-locks', '-C', $path ), $arguments);
		$process = proc_open($command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes);
		if ( ! is_resource($process) ) {
			return array( 'success' => false, 'stdout' => '', 'stderr' => 'Could not start Git.', 'timed_out' => false );
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
			if ( microtime(true) - $started >= self::PROBE_TIMEOUT ) {
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

		return array( 'success' => ! $timeout && 0 === $exit, 'stdout' => $stdout, 'stderr' => $stderr, 'timed_out' => $timeout );
	}

	/** @return array<string,mixed> */
	private function not_owned( string $reason, string $handle, float $started ): array {
		return array(
			'schema'     => self::IDENTITY_SCHEMA,
			'status'     => 'not_owned',
			'ownership'  => 'not_owned',
			'reason'     => $reason,
			'handle'     => $handle,
			'latency_ms' => $this->elapsed_ms($started),
		);
	}

	/** @return array<string,mixed> */
	private function safety_result( string $token, bool $dirty, bool $unpushed, bool $fresh, float $started ): array {
		return array(
			'schema'         => self::SAFETY_SCHEMA,
			'status'         => 'attested',
			'identity_token' => $token,
			'observed_at'    => gmdate('c'),
			'dirty'          => $dirty,
			'unpushed'       => $unpushed,
			'fresh'          => $fresh,
			'latency_ms'     => $this->elapsed_ms($started),
		);
	}

	/** @return array<string,mixed> */
	private function convergence_result( string $status, ?string $code, string $token, string $base_sha, ?string $before, ?string $after, float $started ): array {
		$result = array(
			'schema'         => self::CONVERGE_SCHEMA,
			'status'         => $status,
			'identity_token' => $token,
			'base_sha'       => $base_sha,
			'before_head'    => $before,
			'after_head'     => $after,
			'changed'        => null !== $before && null !== $after ? $before !== $after : null,
			'latency_ms'     => $this->elapsed_ms($started),
		);
		if ( null !== $code ) {
			$result['code'] = $code;
		}
		return $result;
	}

	/** @return array<string,mixed> */
	private function error( string $code, string $message, float $started ): array {
		return array(
			'schema'     => 'datamachine-code/worktree-provider-error/v1',
			'status'     => 'error',
			'code'       => $code,
			'message'    => $message,
			'latency_ms' => $this->elapsed_ms($started),
		);
	}

	private function elapsed_ms( float $started ): int {
		return (int) round(( microtime(true) - $started ) * 1000);
	}
}
