<?php
/**
 * Smoke test for workspace worktree base-ref CLI aliases.
 *
 *   php tests/smoke-worktree-base-branch-cli.php
 *
 * @package DataMachineCode\Tests
 */

namespace {
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__);
    }

    class WP_CLI
    {
        public static array $logs = array();

        public static function reset_logs(): void
        {
            self::$logs = array();
        }

        public static function get_logs(): array
        {
            return self::$logs;
        }

        public static function error( string $message ): void
        {
            throw new RuntimeException($message);
        }

        public static function success( string $message ): void
        {
            self::$logs[] = 'success: ' . $message;
        }

        public static function log( string $message ): void
        {
            self::$logs[] = $message;
        }

        public static function warning( string $message ): void
        {
            self::$logs[] = 'warning: ' . $message;
        }
    }

    function is_wp_error( $value ): bool
    {
        return false;
    }

    function wp_get_ability( string $name )
    {
        return $GLOBALS['__abilities'][ $name ] ?? null;
    }

    function wp_json_encode( $data, int $flags = 0 )
    {
        return json_encode($data, $flags);
    }
}

namespace DataMachine\Cli {
    class BaseCommand
    {
        protected function format_items( array $items, array $fields, array $assoc_args, string $default_sort = '' ): void
        {
        }
    }
}

namespace {
    include_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';
    include_once dirname(__DIR__) . '/inc/Workspace/WorktreeDiskBudget.php';

    function datamachine_code_assert( bool $condition, string $message ): void
    {
        if ($condition ) {
            echo "  [PASS] {$message}\n";
            return;
        }
        echo "  [FAIL] {$message}\n";
        exit(1);
    }

    class FakeWorktreeAddAbility
    {
        public array $last_input = array();

        public function execute( array $input ): array
        {
            $this->last_input = $input;
            return array(
            'success'        => true,
            'message'        => 'created',
            'handle'         => 'data-machine@feat-test',
            'path'           => '/tmp/worktree',
            'branch'         => $input['branch'] ?? '',
            'created_branch' => true,
            'disk_budget'    => array(
            'status'                  => 'warning',
            'free_gib'                => 4.5,
            'worktree_count'          => 123,
            'warnings'                => array( 'low disk' ),
            'force_override_applied'  => ! empty($input['force']),
            'cleanup_dry_run_command' => 'studio wp datamachine-code workspace worktree cleanup --dry-run',
            ),
            );
        }
    }

    echo "=== smoke-worktree-base-branch-cli ===\n";

    $ability = new FakeWorktreeAddAbility();
    $GLOBALS['__abilities'] = array(
    'datamachine-code/workspace-worktree-add' => $ability,
    );
    $command = new \DataMachineCode\Cli\Commands\WorkspaceCommand();

    echo "\n[1] bare base branch maps to origin ref\n";
    $command->worktree(
        array( 'add', 'data-machine', 'feat/test' ),
        array( 'base-branch' => 'main', 'skip-bootstrap' => true, 'skip-context-injection' => true )
    );
    datamachine_code_assert('origin/main' === $ability->last_input['from'], 'main maps to origin/main');
    datamachine_code_assert(false === $ability->last_input['bootstrap'], 'skip-bootstrap still forwarded');
    datamachine_code_assert(false === $ability->last_input['inject_context'], 'skip-context-injection still forwarded');
    datamachine_code_assert(false === $ability->last_input['force'], 'force defaults false for add');

    echo "\n[2] branch names with slashes still map to origin refs\n";
    $command->worktree(
        array( 'add', 'data-machine', 'feat/test' ),
        array( 'base-branch' => 'release/next' )
    );
    datamachine_code_assert('origin/release/next' === $ability->last_input['from'], 'release/next maps to origin/release/next');

    echo "\n[3] full ref is preserved\n";
    $command->worktree(
        array( 'add', 'data-machine', 'feat/test' ),
        array( 'base-branch' => 'upstream/develop' )
    );
    datamachine_code_assert('upstream/develop' === $ability->last_input['from'], 'remote ref preserved');

    echo "\n[4] --base aliases exact --from refs\n";
    $command->worktree(
        array( 'add', 'data-machine', 'feat/test' ),
        array( 'base' => 'origin/main' )
    );
    datamachine_code_assert('origin/main' === $ability->last_input['from'], '--base forwards exact refs as from');

    echo "\n[5] --from and --base reject ambiguous exact-base input\n";
    try {
        $command->worktree(
            array( 'add', 'data-machine', 'feat/test' ),
            array( 'from' => 'origin/main', 'base' => 'upstream/develop' )
        );
        datamachine_code_assert(false, 'ambiguous exact-base flags should throw');
    } catch ( RuntimeException $e ) {
        datamachine_code_assert(str_contains($e->getMessage(), 'only one'), 'ambiguous exact-base flags fail clearly');
    }

    echo "\n[6] --base-ref aliases exact --from refs\n";
    $command->worktree(
        array( 'add', 'data-machine', 'feat/test' ),
        array( 'base-ref' => 'origin/trunk' )
    );
    datamachine_code_assert('origin/trunk' === $ability->last_input['from'], '--base-ref forwards exact refs as from');

    echo "\n[7] --base-ref rejects other exact-base aliases\n";
    try {
        $command->worktree(
            array( 'add', 'data-machine', 'feat/test' ),
            array( 'base' => 'origin/main', 'base-ref' => 'upstream/develop' )
        );
        datamachine_code_assert(false, 'ambiguous base-ref flags should throw');
    } catch ( RuntimeException $e ) {
        datamachine_code_assert(str_contains($e->getMessage(), 'only one'), 'ambiguous base-ref flags fail clearly');
    }

    echo "\n[8] exact base flags reject ambiguous base-branch input\n";
    try {
        $command->worktree(
            array( 'add', 'data-machine', 'feat/test' ),
            array( 'base-ref' => 'origin/main', 'base-branch' => 'develop' )
        );
        datamachine_code_assert(false, 'ambiguous flags should throw');
    } catch ( RuntimeException $e ) {
        datamachine_code_assert(str_contains($e->getMessage(), 'not both'), 'ambiguous flags fail clearly');
    }

    echo "\n[9] --force is forwarded for add and JSON output keeps disk budget\n";
    \WP_CLI::reset_logs();
    $command->worktree(
        array( 'add', 'data-machine', 'feat/test' ),
        array( 'force' => true, 'format' => 'json' )
    );
    datamachine_code_assert(true === $ability->last_input['force'], 'force forwarded for add');
    $logs    = \WP_CLI::get_logs();
    $decoded = json_decode($logs[0] ?? '', true);
    datamachine_code_assert(is_array($decoded), 'JSON output decodes');
    datamachine_code_assert('warning' === ( $decoded['disk_budget']['status'] ?? '' ), 'JSON output includes disk budget status');
    datamachine_code_assert(true === ( $decoded['disk_budget']['force_override_applied'] ?? false ), 'JSON output includes explicit force override');

    echo "\nAll worktree base-branch CLI smoke tests passed.\n";
}
