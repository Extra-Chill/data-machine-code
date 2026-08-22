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
	private const TOKEN_PREFIX    = 'dmc-worktree-v1.';
	private const PROBE_TIMEOUT   = 2.0;

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

		$identity = array(
			'handle'  => $parsed['dir_name'],
			'path'    => $real,
			'branch'  => $branch,
			'primary' => ! $parsed['is_worktree'],
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
		$fresh   = 'owned' === ( $current['status'] ?? '' )
			&& $identity['path'] === ( $current['path'] ?? null )
			&& $identity['branch'] === ( $current['branch'] ?? null )
			&& $identity['primary'] === ( $current['primary'] ?? null );
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

	private function read_branch( string $path ): ?string {
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
		if ( false === $git_dir ) {
			return null;
		}
		$head = trim((string) @file_get_contents($git_dir . '/HEAD'));
		return str_starts_with($head, 'ref: refs/heads/') ? substr($head, strlen('ref: refs/heads/')) : null;
	}

	/**
	 * @param array{handle:string,path:string,branch:string,primary:bool} $identity
	 */
	private function encode_token( array $identity ): string {
		$payload = json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
		return self::TOKEN_PREFIX . hash('sha256', $payload) . '.' . $encoded;
	}

	/**
	 * @return array{handle:string,path:string,branch:string,primary:bool}|null
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
			|| ! is_bool($decoded['primary'] ?? null) ) {
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
