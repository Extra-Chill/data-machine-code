<?php
/**
 * Pure-PHP smoke for worktree prune when the workspace is visible but git is unavailable.
 *
 * Run: php tests/smoke-worktree-prune-no-git.php
 */

declare( strict_types=1 );

namespace DataMachine\Core\FilesRepository {
    class FilesystemHelper
    {
        public static function get(): ?self
        {
            return null;
        }
    }
}

namespace {
    $tmp = sys_get_temp_dir() . '/dmc-worktree-prune-no-git-' . getmypid();
    if (! defined('ABSPATH') ) {
        define('ABSPATH', $tmp . '/wp/');
    }
	if (! defined('DATAMACHINE_WORKSPACE_PATH') ) {
		define('DATAMACHINE_WORKSPACE_PATH', $tmp . '/workspace');
	}
	if (! defined('ARRAY_A') ) {
		define('ARRAY_A', 'ARRAY_A');
	}

	if (! function_exists('current_time') ) {
		function current_time( string $type, bool $gmt = false ): string  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		{
			return '2026-06-14 00:00:00';
		}
	}
	if (! function_exists('wp_json_encode') ) {
		function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false
		{
			return json_encode($value, $flags, $depth);
		}
	}

	class DatamachineCodePruneFakeWpdb
	{
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public int $rows_affected = 0;
		public string $last_error = '';

		/**
		 * @var array<string,array<string,mixed>>
		 */
		public array $rows = array();
		public array $lock_rows = array();

		public function get_var( string $query ): string|int|null
		{
			if ( str_contains($query, 'SHOW TABLES LIKE') ) {
				return $this->prefix . 'datamachine_code_locks';
			}

			if ( str_contains($query, 'COUNT(*)') ) {
				return 0;
			}

			return null;
		}

		public function query( string $query ): int
		{
			$this->rows_affected = 0;
			return 0;
		}

		public function replace( string $table, array $data ): int  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		{
			$this->rows[ (string) $data['handle'] ] = $data;
			return 1;
		}

		public function insert( string $table, array $data, ?array $format = null ): int  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		{
			++$this->insert_id;
			$data['id'] = $this->insert_id;
			$this->lock_rows[ $this->insert_id ] = $data;
			return 1;
		}

		public function delete( string $table, array $where ): int  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		{
			unset($this->rows[ (string) $where['handle'] ]);
			return 1;
		}

		public function update( string $table, array $data, array $where, ?array $format = null, ?array $where_format = null ): int  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		{
			if ( str_contains($table, 'datamachine_code_locks') ) {
				$id = (int) ( $where['id'] ?? 0 );
				if ( isset($this->lock_rows[ $id ]) ) {
					$this->lock_rows[ $id ] = array_merge($this->lock_rows[ $id ], $data);
					return 1;
				}
				return 0;
			}

			$handle = (string) ( $where['handle'] ?? '' );
			if ( ! isset($this->rows[ $handle ]) ) {
				return 0;
			}

			$this->rows[ $handle ] = array_merge($this->rows[ $handle ], $data);
			return 1;
		}

		public function get_results( string $sql, string $output ): array  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		{
			$rows = array_values($this->rows);
			usort($rows, fn( array $a, array $b ): int => strcmp((string) $a['handle'], (string) $b['handle']));
			return $rows;
		}

		public function prepare( string $query, mixed ...$args ): string
		{
			foreach ( $args as $arg ) {
				$query = preg_replace('/%s/', "'" . addslashes((string) $arg) . "'", $query, 1);
			}
			return $query;
		}
	}

    if (! class_exists('WP_Error') ) {
        class WP_Error
        {
            public function __construct( private string $code, private string $message, private array $data = array() )
            {
            }

            public function get_error_code(): string
            {
                return $this->code;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }

            public function get_error_data(): array
            {
                return $this->data;
            }
        }
    }

    if (! function_exists('is_wp_error') ) {
        function is_wp_error( $value ): bool
        {
            return $value instanceof WP_Error;
        }
    }

    $failures = array();
    $total    = 0;
    $assert   = function ( string $label, bool $condition ) use ( &$failures, &$total ): void {
        ++$total;
        if ($condition ) {
            echo "  ok {$label}\n";
            return;
        }

        $failures[] = $label;
        echo "  fail {$label}\n";
    };

	$old_path = getenv('PATH');
	putenv('PATH=/nonexistent-dmc-no-git');

	mkdir(DATAMACHINE_WORKSPACE_PATH . '/demo/.git', 0777, true);
	mkdir(DATAMACHINE_WORKSPACE_PATH . '/demo@stale-marker', 0777, true);
	file_put_contents(DATAMACHINE_WORKSPACE_PATH . '/demo@stale-marker/.git', 'gitdir: ' . DATAMACHINE_WORKSPACE_PATH . '/demo/.git/worktrees/demo@stale-marker' . "\n");

	$GLOBALS['wpdb'] = new DatamachineCodePruneFakeWpdb();
	$GLOBALS['wpdb']->rows['demo@missing-path'] = array(
		'handle'        => 'demo@missing-path',
		'repo'          => 'demo',
		'branch'        => 'missing-path',
		'path'          => DATAMACHINE_WORKSPACE_PATH . '/demo@missing-path',
		'primary_path'  => DATAMACHINE_WORKSPACE_PATH . '/demo',
		'is_primary'    => 0,
		'is_worktree'   => 1,
		'missing_path'  => 1,
		'metadata'      => array(),
		'updated_at'    => '2026-06-13 00:00:00',
	);
	$GLOBALS['wpdb']->rows['demo@stale-marker'] = array(
		'handle'        => 'demo@stale-marker',
		'repo'          => 'demo',
		'branch'        => 'stale-marker',
		'path'          => DATAMACHINE_WORKSPACE_PATH . '/demo@stale-marker',
		'primary_path'  => DATAMACHINE_WORKSPACE_PATH . '/demo',
		'is_primary'    => 0,
		'is_worktree'   => 1,
		'missing_path'  => 0,
		'metadata'      => array(),
		'updated_at'    => '2026-06-13 00:00:00',
	);

	require __DIR__ . '/../inc/Support/RuntimeCapabilities.php';
	require __DIR__ . '/../inc/Support/ProcessRunner.php';
	require __DIR__ . '/../inc/Support/GitRunner.php';
	require __DIR__ . '/../inc/Support/PathSecurity.php';
    require __DIR__ . '/../inc/Workspace/WorktreeContextInjector.php';
    require __DIR__ . '/../inc/Workspace/WorkspaceMutationLock.php';
    require __DIR__ . '/../inc/Workspace/Workspace.php';

    echo "Worktree prune without git - smoke\n";

    $workspace = new DataMachineCode\Workspace\Workspace();
    $result    = $workspace->worktree_prune();

    $assert('prune returns success instead of git-unavailable error', ! is_wp_error($result) && true === ( $result['success'] ?? false ));
	$assert('prune records skipped primary', ! is_wp_error($result) && 'demo' === ( $result['skipped'][0]['repo'] ?? '' ));
	$assert('prune returns host git command', ! is_wp_error($result) && str_contains((string) ( $result['next_commands'][0] ?? '' ), 'git -C'));
	$assert('inventory refresh still runs', ! is_wp_error($result) && isset($result['inventory']['summary']));
	$assert('prune removes missing-path inventory artifact', ! is_wp_error($result) && 'demo@missing-path' === ( $result['stale_inventory'][0]['handle'] ?? '' ) && ! isset($GLOBALS['wpdb']->rows['demo@missing-path']));
	$assert('prune reports path-present stale marker blocker', ! is_wp_error($result) && 'stale_worktree_marker' === ( $result['stale_marker_blockers'][0]['reason_code'] ?? '' ));
	$assert('prune leaves path-present stale marker row for review', isset($GLOBALS['wpdb']->rows['demo@stale-marker']));

    putenv(false === $old_path ? 'PATH' : 'PATH=' . $old_path);

    if (is_dir($tmp) ) {
        exec('rm -rf ' . escapeshellarg($tmp));
    }

    if (! empty($failures) ) {
        echo "\nFAIL: " . count($failures) . " assertion(s) failed out of {$total}\n";
        foreach ( $failures as $failure ) {
            echo "  - {$failure}\n";
        }
        exit(1);
    }

    echo "\nOK ({$total} assertions)\n";
    exit(0);
}
