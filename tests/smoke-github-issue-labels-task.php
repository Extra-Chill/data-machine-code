<?php
/**
 * Pure-PHP smoke test for the GitHub issue labels system task.
 *
 * Run: php tests/smoke-github-issue-labels-task.php
 */

declare( strict_types=1 );

namespace DataMachine\Engine\AI\System\Tasks {
    abstract class SystemTask
    {
        abstract public function getTaskType(): string;

        protected function completeJob( int $jobId, array $data ): void
        {
            $GLOBALS['dmc_completed_jobs'][ $jobId ] = $data;
        }

        protected function failJob( int $jobId, string $message ): void
        {
            $GLOBALS['dmc_failed_jobs'][ $jobId ] = $message;
        }
    }
}

namespace DataMachineCode\Abilities {
    class GitHubAbilities
    {
        public static array $addLabelsCalls = array();
        public static array $removeLabelCalls = array();
        public static ?\WP_Error $nextAddError = null;
        public static ?\WP_Error $nextRemoveError = null;

        public static function addLabels( array $input ): array|\WP_Error
        {
            self::$addLabelsCalls[] = $input;
            if (null !== self::$nextAddError ) {
                $error              = self::$nextAddError;
                self::$nextAddError = null;
                return $error;
            }
            return array(
                'success'        => true,
                'applied_labels' => $input['labels'] ?? array(),
            );
        }

        public static function removeLabel( array $input ): array|\WP_Error
        {
            self::$removeLabelCalls[] = $input;
            if (null !== self::$nextRemoveError ) {
                $error                 = self::$nextRemoveError;
                self::$nextRemoveError = null;
                return $error;
            }
            return array(
                'success'       => true,
                'removed_label' => $input['label'] ?? '',
            );
        }
    }
}

namespace {
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__ . '/');
    }

    class WP_Error
    {
        public function __construct( private string $code, private string $message )
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
    }

    function is_wp_error( $value ): bool
    {
        return $value instanceof WP_Error;
    }

    require_once __DIR__ . '/../inc/Tasks/GitHubIssueLabelsTask.php';

    $assert = static function ( string $message, bool $condition ): void {
        if (! $condition ) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };

    $plugin = file_get_contents(__DIR__ . '/../data-machine-code.php');
    $assert('plugin registers github_update_issue_labels task', is_string($plugin) && str_contains($plugin, "'github_update_issue_labels'") && str_contains($plugin, 'GitHubIssueLabelsTask::class'));

    $task = new \DataMachineCode\Tasks\GitHubIssueLabelsTask();
    $assert('task type is github_update_issue_labels', 'github_update_issue_labels' === $task->getTaskType());
    $assert('task requests pipeline context for fetched GitHub packet inference', true === $task->needsPipelineContext());

    $meta = \DataMachineCode\Tasks\GitHubIssueLabelsTask::getTaskMeta();
    $assert('task meta marks mutation', true === ( $meta['mutates'] ?? null ));
    $assert('task meta exposes required params', array( 'repo', 'issue_number' ) === ( $meta['params_schema']['required'] ?? array() ));

    $GLOBALS['dmc_completed_jobs'] = array();
    $GLOBALS['dmc_failed_jobs']    = array();

    $task->executeTask(
        101,
        array(
            'repo'          => 'chubes4/wp-site-generator',
            'issue_number'  => 505,
            'remove_labels' => array( ' status:idea-ready ', 'status:idea-ready', '' ),
            'add_labels'    => array( 'status:design-ready', 'status:design-ready' ),
        )
    );

    $result = $GLOBALS['dmc_completed_jobs'][101] ?? array();
    $assert('task completes successfully', true === ( $result['success'] ?? false ));
    $assert('task records removed label', array( 'status:idea-ready' ) === ( $result['removed_labels'] ?? array() ));
    $assert('task records added label', array( 'status:design-ready' ) === ( $result['added_labels'] ?? array() ));
    $assert('remove is called once before add', 1 === count(\DataMachineCode\Abilities\GitHubAbilities::$removeLabelCalls) && 1 === count(\DataMachineCode\Abilities\GitHubAbilities::$addLabelsCalls));
    $assert('remove forwards repo and issue number', 505 === (int) ( \DataMachineCode\Abilities\GitHubAbilities::$removeLabelCalls[0]['issue_number'] ?? 0 ) && 'chubes4/wp-site-generator' === ( \DataMachineCode\Abilities\GitHubAbilities::$removeLabelCalls[0]['repo'] ?? '' ));
    $assert('add forwards de-duped labels array', array( 'status:design-ready' ) === ( \DataMachineCode\Abilities\GitHubAbilities::$addLabelsCalls[0]['labels'] ?? array() ));

    $task->executeTask(
        102,
        array(
            'repo'          => 'chubes4/wp-site-generator',
            'issue_number'  => 506,
            'remove_labels' => 'status:idea-ready',
        )
    );
    $assert('scalar remove label is accepted', array( 'status:idea-ready' ) === ( $GLOBALS['dmc_completed_jobs'][102]['removed_labels'] ?? array() ));

    $task->executeTask(
        105,
        array(
            'remove_labels' => array( 'status:idea-ready' ),
            'add_labels'    => array( 'status:design-ready' ),
            'data_packets'  => array(
                array(
                    'metadata' => array(
                        'source_type'   => 'github',
                        'github_type'   => 'issues',
                        'github_repo'   => 'chubes4/wp-site-generator',
                        'github_number' => 508,
                    ),
                ),
            ),
        )
    );
    $assert('task infers repo from fetched GitHub issue packet', 'chubes4/wp-site-generator' === ( $GLOBALS['dmc_completed_jobs'][105]['repo'] ?? '' ));
    $assert('task infers issue number from fetched GitHub issue packet', 508 === (int) ( $GLOBALS['dmc_completed_jobs'][105]['issue_number'] ?? 0 ));

    $task->executeTask(103, array( 'repo' => 'chubes4/wp-site-generator' ));
    $assert('missing issue number fails', str_contains((string) ( $GLOBALS['dmc_failed_jobs'][103] ?? '' ), 'repo and issue_number'));

    \DataMachineCode\Abilities\GitHubAbilities::$nextRemoveError = new WP_Error('github_api_error', 'Remove failed');
    $task->executeTask(
        104,
        array(
            'repo'          => 'chubes4/wp-site-generator',
            'issue_number'  => 507,
            'remove_labels' => array( 'status:idea-ready' ),
        )
    );
    $assert('ability remove error fails job', 'Remove failed' === ( $GLOBALS['dmc_failed_jobs'][104] ?? '' ));

    fwrite(STDOUT, "PASS: GitHub issue labels task smoke test\n");
}
