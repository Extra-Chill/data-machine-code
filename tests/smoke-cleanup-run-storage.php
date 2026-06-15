<?php
/**
 * Smoke test for DB-backed cleanup run storage/service.
 *
 * Run: php tests/smoke-cleanup-run-storage.php
 */

if (! defined('ABSPATH') ) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (! defined('ARRAY_A') ) {
    define('ARRAY_A', 'ARRAY_A');
}

if (! class_exists('WP_Error') ) {
    class WP_Error
    {
        public function __construct( private string $code = '', private string $message = '', private mixed $data = null )
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
        public function get_error_data(): mixed
        {
            return $this->data; 
        }
    }
}

if (! function_exists('is_wp_error') ) {
    function is_wp_error( mixed $thing ): bool
    {
        return $thing instanceof WP_Error; 
    }
}

if (! function_exists('wp_json_encode') ) {
    function wp_json_encode( mixed $value, int $flags = 0 ): string|false
    {
        return json_encode($value, $flags); 
    }
}

if (! function_exists('wp_generate_password') ) {
    function wp_generate_password( int $length = 12 ): string
    {
        static $counter = 0;
        ++$counter;
        return substr(str_repeat((string) $counter, $length), 0, $length);
    }
}

require __DIR__ . '/../vendor/autoload.php';

class DataMachineCodeCleanupRunFakeWpdb
{
    public string $prefix = 'wp_';
    public array $runs = array();
    public array $items = array();
    private int $run_id = 0;
    private int $item_id = 0;

    public function insert( string $table, array $data, array $format = array() ): int|false
    {
        if (str_contains($table, 'cleanup_runs') ) {
            $data['id'] = ++$this->run_id;
            $this->runs[ $data['run_id'] ] = $data;
            return 1;
        }
        if (str_contains($table, 'cleanup_items') ) {
            $data['id'] = ++$this->item_id;
            $this->items[] = $data;
            return 1;
        }
        return false;
    }

    public function update( string $table, array $data, array $where ): int|false
    {
        if (str_contains($table, 'cleanup_runs') ) {
            $key = (string) ( $where['run_id'] ?? '' );
            $this->runs[ $key ] = array_merge($this->runs[ $key ] ?? array(), $data);
            return 1;
        }
        if (str_contains($table, 'cleanup_items') ) {
            $id = (int) ( $where['id'] ?? 0 );
            foreach ( $this->items as &$item ) {
                if ((int) $item['id'] === $id ) {
                    $item = array_merge($item, $data);
                    return 1;
                }
            }
        }
        return false;
    }

    public function prepare( string $query, mixed ...$args ): string
    {
        foreach ( $args as $arg ) {
            $query = preg_replace('/%s|%d/', (string) $arg, $query, 1);
        }
        return $query;
    }

    public function get_row( string $query, string $output = ARRAY_A ): ?array
    {
        if (preg_match('/run_id = ([^\s]+)/', $query, $matches) ) {
            return $this->runs[ $matches[1] ] ?? null;
        }
        return null;
    }

    public function get_results( string $query, string $output = ARRAY_A ): array
    {
        if (! preg_match('/run_id = ([^\s]+)/', $query, $matches) ) {
            return array();
        }
        return array_values(array_filter($this->items, fn( $item ) => (string) $item['run_id'] === $matches[1]));
    }
}

class DataMachineCodeCleanupRunFakeWorkspace extends \DataMachineCode\Workspace\Workspace
{
    public array $artifact_calls = array();
    public array $worktree_calls = array();

    public function __construct()
    {
    }
    public function workspace_cleanup_plan( array $opts = array() ): array|WP_Error
    {
        return array(
        'success'       => true,
        'mode'          => 'cleanup_plan',
        'plan_id'       => 'cleanup-plan-test',
        'safety_policy' => array( 'destructive_rows_need_review' => true ),
        'rows'          => array(
        'artifact_cleanup' => array(
        array( 'handle' => 'demo@old', 'row_type' => 'artifact_cleanup', 'reason_code' => 'profile_artifact', 'artifact_size_bytes' => 15 ),
        array( 'handle' => 'demo@stale-artifact', 'row_type' => 'artifact_cleanup', 'reason_code' => 'profile_artifact', 'artifact_size_bytes' => 9 ),
        ),
        'worktree_removal' => array(
                    array( 'handle' => 'demo@merged', 'repo' => 'demo', 'branch' => 'merged', 'path' => '/tmp/demo@merged', 'row_type' => 'worktree_removal', 'reason_code' => 'cleanup_eligible', 'size_bytes' => 20 ),
                    array( 'handle' => 'demo@dirty', 'repo' => 'demo', 'branch' => 'dirty', 'path' => '/tmp/demo@dirty', 'row_type' => 'worktree_removal', 'reason_code' => 'cleanup_eligible', 'size_bytes' => 30 ),
        ),
        'resolver' => array(
        array( 'handle' => 'demo@needs-metadata-1', 'row_type' => 'resolver', 'reason_code' => 'needs_metadata_reconcile', 'reason' => 'missing metadata' ),
        array( 'handle' => 'demo@needs-metadata-2', 'row_type' => 'resolver', 'reason_code' => 'needs_metadata_reconcile', 'reason' => 'missing metadata' ),
        array( 'handle' => 'demo@needs-metadata-3', 'row_type' => 'resolver', 'reason_code' => 'needs_metadata_reconcile', 'reason' => 'missing metadata' ),
        array( 'handle' => 'demo@needs-metadata-4', 'row_type' => 'resolver', 'reason_code' => 'needs_metadata_reconcile', 'reason' => 'missing metadata' ),
        ),
        ),
		'summary'       => array(
		'total_rows'       => 8,
		'total_size_bytes' => 74,
		'recommended_commands' => array(
		array(
		'label'   => 'apply_reviewed_plan',
		'risk'    => 'reviewed_destructive',
		'command' => 'studio wp datamachine-code workspace cleanup apply <run-id>',
		),
		),
		),
		);
	}
    public function worktree_cleanup_artifacts( array $opts = array() ): array|WP_Error
    {
        $this->artifact_calls[] = $opts;
        $candidates = (array) ( $opts['apply_plan']['candidates'] ?? array() );
        $removed = array();
        $skipped = array();
        foreach ( $candidates as $candidate ) {
            $handle = (string) ( is_array($candidate) ? ( $candidate['handle'] ?? '' ) : '' );
            if ('demo@old' === $handle ) {
                $removed[] = array( 'handle' => 'demo@old', 'artifact_size_bytes' => 15 );
            } elseif ('demo@stale-artifact' === $handle ) {
                $skipped[] = array( 'handle' => 'demo@stale-artifact', 'reason_code' => 'plan_mismatch', 'reason' => 'artifact plan no longer matches', 'artifact_size_bytes' => 9 );
            }
        }
        return array(
        'removed' => $removed,
        'skipped' => $skipped,
        'summary' => array(),
        );
    }
    public function worktree_cleanup_merged( array $opts = array() ): array|WP_Error
    {
        $this->worktree_calls[] = $opts;
        $candidates = (array) ( $opts['apply_plan']['candidates'] ?? array() );
        $removed = array();
        $skipped = array();
        foreach ( $candidates as $candidate ) {
            $handle = (string) ( is_array($candidate) ? ( $candidate['handle'] ?? '' ) : '' );
            if ('demo@merged' === $handle ) {
                $removed[] = array( 'handle' => 'demo@merged', 'size_bytes' => 20 );
            } elseif ('demo@dirty' === $handle ) {
                $skipped[] = array( 'handle' => 'demo@dirty', 'reason_code' => 'dirty_worktree', 'reason' => 'working tree is dirty' );
            }
        }
        return array(
        'removed' => $removed,
        'skipped' => $skipped,
        'summary' => array(),
        );
    }
}

class DataMachineCodeCleanupUntilEmptyWorkspace extends DataMachineCodeCleanupRunFakeWorkspace
{
    public int $plan_calls = 0;
    public bool $repeat = false;

    public function workspace_cleanup_plan( array $opts = array() ): array|WP_Error
    {
        ++$this->plan_calls;
        if (! $this->repeat && $this->plan_calls > 2 ) {
            return array(
            'success' => true,
            'mode'    => 'cleanup_plan',
            'plan_id' => 'cleanup-plan-empty-' . $this->plan_calls,
            'rows'    => array( 'artifact_cleanup' => array(), 'worktree_removal' => array(), 'resolver' => array() ),
            'summary' => array( 'total_rows' => 0, 'recommended_commands' => array() ),
            );
        }

        return array(
        'success' => true,
        'mode'    => 'cleanup_plan',
        'plan_id' => 'cleanup-plan-until-' . $this->plan_calls,
        'rows'    => array(
        'artifact_cleanup' => array(
        array(
        'handle'              => 'demo@artifact-' . ( $this->repeat ? 'repeat' : $this->plan_calls ),
        'repo'                => 'demo',
        'branch'              => 'main',
        'path'                => '/tmp/demo@artifact',
        'row_type'            => 'artifact_cleanup',
        'reason_code'         => 'profile_artifact',
        'artifact_size_bytes' => 10,
        'artifacts'           => array( array( 'path' => 'target', 'size_bytes' => 10 ) ),
        ),
        ),
        'worktree_removal' => array(),
        'resolver'         => array(),
        ),
        'summary' => array( 'total_rows' => 1, 'recommended_commands' => array() ),
        );
    }

    public function worktree_cleanup_artifacts( array $opts = array() ): array|WP_Error
    {
        $this->artifact_calls[] = $opts;
        $removed = array();
        foreach ( (array) ( $opts['apply_plan']['candidates'] ?? array() ) as $candidate ) {
            $removed[] = is_array($candidate) ? $candidate : array();
        }
        return array( 'removed' => $removed, 'skipped' => array(), 'summary' => array() );
    }
}

class DataMachineCodeCleanupUntilEmptyBlockedWorkspace extends DataMachineCodeCleanupUntilEmptyWorkspace
{
    public function workspace_cleanup_plan( array $opts = array() ): array|WP_Error
    {
        ++$this->plan_calls;
        if ($this->plan_calls > 3 ) {
            return array(
            'success' => true,
            'mode'    => 'cleanup_plan',
            'plan_id' => 'cleanup-plan-empty-' . $this->plan_calls,
            'rows'    => array( 'artifact_cleanup' => array(), 'worktree_removal' => array(), 'resolver' => array() ),
            'summary' => array( 'total_rows' => 0, 'recommended_commands' => array() ),
            );
        }

        $blocked = 3 === $this->plan_calls;
        return array(
        'success' => true,
        'mode'    => 'cleanup_plan',
        'plan_id' => 'cleanup-plan-until-blocked-' . $this->plan_calls,
        'rows'    => array(
        'artifact_cleanup' => array(
        array(
        'handle'              => $blocked ? 'demo@blocked-artifact' : 'demo@artifact-' . $this->plan_calls,
        'repo'                => 'demo',
        'branch'              => 'main',
        'path'                => '/tmp/demo@artifact-' . $this->plan_calls,
        'row_type'            => 'artifact_cleanup',
        'reason_code'         => 'profile_artifact',
        'artifact_size_bytes' => 10,
        'artifacts'           => array( array( 'path' => 'target', 'size_bytes' => 10 ) ),
        ),
        ),
        'worktree_removal' => array(),
        'resolver'         => array(),
        ),
        'summary' => array( 'total_rows' => 1, 'recommended_commands' => array() ),
        );
    }

    public function worktree_cleanup_artifacts( array $opts = array() ): array|WP_Error
    {
        $this->artifact_calls[] = $opts;
        $removed = array();
        $skipped = array();
        foreach ( (array) ( $opts['apply_plan']['candidates'] ?? array() ) as $candidate ) {
            if (is_array($candidate) && 'demo@blocked-artifact' === (string) ( $candidate['handle'] ?? '' ) ) {
                $skipped[] = array(
                'handle'      => 'demo@blocked-artifact',
                'reason_code' => 'dirty_worktree',
                'reason'      => 'working tree is dirty',
                );
                continue;
            }
            $removed[] = is_array($candidate) ? $candidate : array();
        }
        return array( 'removed' => $removed, 'skipped' => $skipped, 'summary' => array() );
    }
}

function datamachine_code_cleanup_run_assert( bool $condition, string $message ): void
{
    if (! $condition ) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "ok - {$message}\n";
}

$GLOBALS['wpdb'] = new DataMachineCodeCleanupRunFakeWpdb();
$repo = new \DataMachineCode\Storage\CleanupRunRepository();
$service = new \DataMachineCode\Workspace\CleanupRunService($repo, new DataMachineCodeCleanupRunFakeWorkspace());

$plan = $service->plan(array( 'mode' => 'retention' ));
datamachine_code_cleanup_run_assert(! is_wp_error($plan), 'plan succeeds');
datamachine_code_cleanup_run_assert(isset($plan['run_id']), 'plan returns run_id');
datamachine_code_cleanup_run_assert(8 === (int) ( $plan['cleanup_storage']['item_count'] ?? 0 ), 'plan persists cleanup items');
datamachine_code_cleanup_run_assert(str_contains((string) ( $plan['summary']['recommended_commands'][0]['command'] ?? '' ), (string) $plan['run_id']), 'plan materializes recommended command run id');
datamachine_code_cleanup_run_assert(! str_contains((string) ( $plan['summary']['recommended_commands'][0]['command'] ?? '' ), '<run-id>'), 'plan recommended commands are directly executable');

$status = $service->status($plan['run_id']);
datamachine_code_cleanup_run_assert(8 === (int) ( $status['summary']['total_items'] ?? 0 ), 'status aggregates items');
datamachine_code_cleanup_run_assert(4 === (int) ( $status['summary']['items_by_status']['pending'] ?? 0 ), 'status tracks pending items');
datamachine_code_cleanup_run_assert(4 === (int) ( $status['remaining_work_summary']['blocked_resolvers_by_reason']['needs_metadata_reconcile']['count'] ?? 0 ), 'status groups blocked resolver rows by reason');
datamachine_code_cleanup_run_assert(3 === count($status['remaining_work_summary']['blocked_resolvers_by_reason']['needs_metadata_reconcile']['examples'] ?? array()), 'blocked resolver examples are truncated');
datamachine_code_cleanup_run_assert(24 === (int) ( $status['remaining_work_summary']['remaining_reclaimable_artifact_bytes'] ?? 0 ), 'status reports remaining reclaimable artifact bytes');
datamachine_code_cleanup_run_assert(2 === (int) ( $status['remaining_work_summary']['remaining_safely_removable_worktrees'] ?? 0 ), 'status reports remaining safely removable worktrees');
datamachine_code_cleanup_run_assert(false === (bool) ( $status['progress']['resumable'] ?? true ), 'planned status is not resumable before apply starts');

$apply = $service->apply($plan['run_id']);
datamachine_code_cleanup_run_assert(! is_wp_error($apply), 'apply succeeds');
datamachine_code_cleanup_run_assert('completed' === (string) ( $apply['status'] ?? '' ), 'apply drains worktree rows up to the default limit');
datamachine_code_cleanup_run_assert(4 === (int) ( $apply['processed'] ?? 0 ), 'apply reports processed row count');
datamachine_code_cleanup_run_assert(2 === (int) ( $apply['applied'] ?? 0 ), 'apply reports applied row count');
datamachine_code_cleanup_run_assert(2 === (int) ( $apply['skipped'] ?? 0 ), 'apply reports skipped row count');
datamachine_code_cleanup_run_assert(null === ( $apply['next_command'] ?? null ), 'completed apply omits next command');

$evidence = $service->evidence($plan['run_id']);
datamachine_code_cleanup_run_assert(2 === (int) ( $evidence['summary']['items_by_status']['applied'] ?? 0 ), 'evidence reflects applied rows');
datamachine_code_cleanup_run_assert(2 === (int) ( $evidence['summary']['items_by_status']['skipped'] ?? 0 ), 'evidence reflects skipped rows from bounded batch');
datamachine_code_cleanup_run_assert(35 === (int) ( $evidence['summary']['bytes_reclaimed'] ?? 0 ), 'evidence aggregates reclaimed bytes');
datamachine_code_cleanup_run_assert(1 === (int) ( $evidence['remaining_work_summary']['applied_by_type']['artifact_cleanup']['count'] ?? 0 ), 'evidence groups applied artifact rows');
datamachine_code_cleanup_run_assert(15 === (int) ( $evidence['remaining_work_summary']['applied_by_type']['artifact_cleanup']['bytes_reclaimed'] ?? 0 ), 'evidence reports artifact bytes reclaimed');
datamachine_code_cleanup_run_assert(1 === (int) ( $evidence['remaining_work_summary']['skipped_by_reason']['plan_mismatch']['count'] ?? 0 ), 'evidence groups skipped plan mismatches');
datamachine_code_cleanup_run_assert('demo@stale-artifact' === (string) ( $evidence['remaining_work_summary']['skipped_by_reason']['plan_mismatch']['examples'][0]['handle'] ?? '' ), 'skipped examples include representative handle');
datamachine_code_cleanup_run_assert(4 === (int) ( $evidence['remaining_work_summary']['blocked_resolvers_by_reason']['needs_metadata_reconcile']['count'] ?? 0 ), 'evidence keeps blocked resolver bucket');
datamachine_code_cleanup_run_assert(str_contains((string) wp_json_encode($evidence['remaining_work_summary']['recommended_commands']), 'workspace worktree reconcile-metadata --dry-run --limit=25 --offset=0 --until-budget=30s --format=json'), 'summary recommends bounded next DMC commands');
datamachine_code_cleanup_run_assert(str_contains((string) wp_json_encode($evidence['remaining_work_summary']['recommended_commands']), 'apply_destructive'), 'summary labels destructive apply commands separately from review commands');

$bounded_workspace = new DataMachineCodeCleanupRunFakeWorkspace();
$bounded_service = new \DataMachineCode\Workspace\CleanupRunService($repo, $bounded_workspace);
$bounded_plan = $bounded_service->plan(array( 'mode' => 'retention' ));
datamachine_code_cleanup_run_assert(! is_wp_error($bounded_plan), 'bounded plan succeeds');

$bounded_apply = $bounded_service->apply($bounded_plan['run_id'], array( 'limit' => 1 ));
datamachine_code_cleanup_run_assert('needs_resume' === (string) ( $bounded_apply['status'] ?? '' ), 'bounded apply pauses when rows remain');
datamachine_code_cleanup_run_assert(1 === (int) ( $bounded_apply['batch']['processed_rows'] ?? 0 ), 'bounded apply processes one row');
datamachine_code_cleanup_run_assert(str_contains((string) ( $bounded_apply['next']['resume_command'] ?? '' ), 'workspace cleanup resume'), 'bounded apply returns resume command');
datamachine_code_cleanup_run_assert(str_contains((string) wp_json_encode($bounded_apply['remaining_work_summary']['recommended_commands'] ?? array()), 'current_run_resume'), 'bounded apply recommends resuming the current reviewed run');
datamachine_code_cleanup_run_assert(1 === count($bounded_workspace->artifact_calls), 'bounded apply only runs artifact cleanup first');
datamachine_code_cleanup_run_assert(0 === count($bounded_workspace->worktree_calls), 'bounded apply defers worktree cleanup until artifacts drain');

$multi_workspace = new DataMachineCodeCleanupRunFakeWorkspace();
$multi_service = new \DataMachineCode\Workspace\CleanupRunService($repo, $multi_workspace);
$multi_plan = $multi_service->plan(array( 'mode' => 'retention' ));
datamachine_code_cleanup_run_assert(! is_wp_error($multi_plan), 'multi-row resume plan succeeds');
$multi_apply = $multi_service->apply($multi_plan['run_id'], array( 'limit' => 2 ));
datamachine_code_cleanup_run_assert('needs_resume' === (string) ( $multi_apply['status'] ?? '' ), 'multi-row apply pauses after artifact rows when worktrees remain');
datamachine_code_cleanup_run_assert(2 === (int) ( $multi_apply['processed'] ?? 0 ), 'multi-row apply reports processed artifact rows');
datamachine_code_cleanup_run_assert(str_contains((string) ( $multi_apply['next_command'] ?? '' ), 'workspace cleanup resume'), 'multi-row apply exposes top-level next command');
$multi_resume = $multi_service->resume($multi_plan['run_id'], array( 'limit' => 2 ));
datamachine_code_cleanup_run_assert('completed' === (string) ( $multi_resume['status'] ?? '' ), 'resume drains multiple worktree rows up to the requested limit');
datamachine_code_cleanup_run_assert('worktree_removal' === (string) ( $multi_resume['batch']['type'] ?? '' ), 'multi-row resume reports worktree batch type');
datamachine_code_cleanup_run_assert(2 === (int) ( $multi_resume['processed'] ?? 0 ), 'multi-row resume reports processed worktree rows');
datamachine_code_cleanup_run_assert(1 === (int) ( $multi_resume['applied'] ?? 0 ), 'multi-row resume reports applied worktree rows');
datamachine_code_cleanup_run_assert(1 === (int) ( $multi_resume['skipped'] ?? 0 ), 'multi-row resume reports skipped worktree rows');
datamachine_code_cleanup_run_assert(null === ( $multi_resume['next_command'] ?? null ), 'multi-row resume omits next command when no rows remain');
datamachine_code_cleanup_run_assert(1 === count($multi_workspace->worktree_calls), 'multi-row resume applies worktree rows in one safety-revalidated call');
datamachine_code_cleanup_run_assert(2 === count($multi_workspace->worktree_calls[0]['apply_plan']['candidates'] ?? array()), 'multi-row resume sends both eligible worktree candidates');

$applying_repo = new \DataMachineCode\Storage\CleanupRunRepository();
$applying_plan = ( new \DataMachineCode\Workspace\CleanupRunService($applying_repo, new DataMachineCodeCleanupRunFakeWorkspace()) )->plan(array( 'mode' => 'retention' ));
datamachine_code_cleanup_run_assert(! is_wp_error($applying_plan), 'applying status plan succeeds');
$GLOBALS['wpdb']->runs[ $applying_plan['run_id'] ]['status'] = 'applying';
$GLOBALS['wpdb']->runs[ $applying_plan['run_id'] ]['started_at'] = gmdate('Y-m-d H:i:s', time() - 120);
foreach ( $GLOBALS['wpdb']->items as &$item ) {
    if ((string) ( $item['run_id'] ?? '' ) === (string) $applying_plan['run_id'] && 'worktree_removal' === (string) ( $item['item_type'] ?? '' ) ) {
        $item['status'] = 'applying';
        break;
    }
}
unset($item);
$applying_status = ( new \DataMachineCode\Workspace\CleanupRunService($applying_repo, new DataMachineCodeCleanupRunFakeWorkspace()) )->status((string) $applying_plan['run_id']);
datamachine_code_cleanup_run_assert(1 === (int) ( $applying_status['summary']['items_by_status']['applying'] ?? 0 ), 'status reports interrupted applying rows');
datamachine_code_cleanup_run_assert(true === (bool) ( $applying_status['progress']['resumable'] ?? false ), 'applying rows are resumable');
datamachine_code_cleanup_run_assert(2 === (int) ( $applying_status['remaining_work_summary']['remaining_safely_removable_worktrees'] ?? 0 ), 'applying worktree rows still count as remaining removable worktrees');

$bounded_service->resume($bounded_plan['run_id'], array( 'limit' => 1 ));
$bounded_service->resume($bounded_plan['run_id'], array( 'limit' => 1 ));
$bounded_done = $bounded_service->resume($bounded_plan['run_id'], array( 'limit' => 1 ));
datamachine_code_cleanup_run_assert('completed' === (string) ( $bounded_done['status'] ?? '' ), 'bounded resume completes final batch');
datamachine_code_cleanup_run_assert(2 === count($bounded_workspace->artifact_calls), 'bounded resume drains artifact batches before worktrees');
datamachine_code_cleanup_run_assert(2 === count($bounded_workspace->worktree_calls), 'bounded resume drains worktree batches after artifacts');

$until_workspace = new DataMachineCodeCleanupUntilEmptyWorkspace();
$until_service = new \DataMachineCode\Workspace\CleanupRunService(new \DataMachineCode\Storage\CleanupRunRepository(), $until_workspace);
$until_result = $until_service->until_empty(array( 'mode' => 'artifacts', 'limit' => 1, 'max_passes' => 5 ));
datamachine_code_cleanup_run_assert(! is_wp_error($until_result), 'until-empty succeeds');
datamachine_code_cleanup_run_assert('completed' === (string) ( $until_result['state'] ?? '' ), 'until-empty stops when artifact plan is empty');
datamachine_code_cleanup_run_assert(2 === (int) ( $until_result['pass_count'] ?? 0 ), 'until-empty records two cleanup passes before empty plan');
datamachine_code_cleanup_run_assert(2 === (int) ( $until_result['applied'] ?? 0 ), 'until-empty aggregates applied rows');
datamachine_code_cleanup_run_assert(20 === (int) ( $until_result['bytes_reclaimed'] ?? 0 ), 'until-empty aggregates reclaimed bytes');

$repeat_workspace = new DataMachineCodeCleanupUntilEmptyWorkspace();
$repeat_workspace->repeat = true;
$repeat_service = new \DataMachineCode\Workspace\CleanupRunService(new \DataMachineCode\Storage\CleanupRunRepository(), $repeat_workspace);
$repeat_result = $repeat_service->until_empty(array( 'mode' => 'artifacts', 'limit' => 1, 'max_passes' => 5 ));
datamachine_code_cleanup_run_assert('repeated_candidates' === (string) ( $repeat_result['state'] ?? '' ), 'until-empty stops when candidate fingerprint repeats');
datamachine_code_cleanup_run_assert(false === (bool) ( $repeat_result['success'] ?? true ), 'repeated candidate loop is not reported as clean success');
datamachine_code_cleanup_run_assert(1 === (int) ( $repeat_result['pass_count'] ?? 0 ), 'repeat detection stops before applying the repeated pass');

$GLOBALS['wpdb'] = new DataMachineCodeCleanupRunFakeWpdb();
$blocked_workspace = new DataMachineCodeCleanupUntilEmptyBlockedWorkspace();
$blocked_service = new \DataMachineCode\Workspace\CleanupRunService(new \DataMachineCode\Storage\CleanupRunRepository(), $blocked_workspace);
$blocked_result = $blocked_service->until_empty(array( 'mode' => 'artifacts', 'limit' => 1, 'max_passes' => 5 ));
datamachine_code_cleanup_run_assert('completed_with_skips' === (string) ( $blocked_result['state'] ?? '' ), 'until-empty reports completed_with_skips after earlier progress and final blocked skips');
datamachine_code_cleanup_run_assert(true === (bool) ( $blocked_result['success'] ?? false ), 'completed_with_skips is reported as top-level success');
datamachine_code_cleanup_run_assert(3 === (int) ( $blocked_result['pass_count'] ?? 0 ), 'completed_with_skips includes the blocked final pass');
datamachine_code_cleanup_run_assert(2 === (int) ( $blocked_result['applied'] ?? 0 ), 'completed_with_skips preserves cumulative applied rows');
datamachine_code_cleanup_run_assert(20 === (int) ( $blocked_result['bytes_reclaimed'] ?? 0 ), 'completed_with_skips preserves cumulative reclaimed bytes');
datamachine_code_cleanup_run_assert(1 === (int) ( $blocked_result['remaining_blocked_count'] ?? 0 ), 'completed_with_skips reports remaining blocked count');
datamachine_code_cleanup_run_assert(1 === (int) ( $blocked_result['remaining_blocked_reasons']['dirty_worktree']['count'] ?? 0 ), 'completed_with_skips reports blocked reasons from final skipped rows');

echo "DB-backed cleanup run storage smoke passed.\n";
