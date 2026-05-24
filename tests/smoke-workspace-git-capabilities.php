<?php
/**
 * Pure-PHP smoke for explicit workspace git runtime capability diagnostics.
 *
 * Run: php tests/smoke-workspace-git-capabilities.php
 */

declare( strict_types=1 );

if (! defined('ABSPATH') ) {
    define('ABSPATH', sys_get_temp_dir() . '/dmc-workspace-git-capabilities/');
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

require __DIR__ . '/../inc/Support/GitRunner.php';

use DataMachineCode\Support\GitRunner;

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

echo "Workspace git capabilities - smoke\n";

$diagnostic = GitRunner::diagnose();
$assert('diagnostic identifies backend', 'local_git' === ( $diagnostic['backend'] ?? '' ));
$assert('diagnostic reports exec availability', array_key_exists('exec_available', $diagnostic) && is_bool($diagnostic['exec_available']));
$assert('diagnostic reports proc_open availability', array_key_exists('proc_open_available', $diagnostic) && is_bool($diagnostic['proc_open_available']));
$assert('diagnostic reports git availability', array_key_exists('git_available', $diagnostic) && is_bool($diagnostic['git_available']));

$error = GitRunner::unavailable_error('Test workspace operation', true);
$assert('unavailable error has stable code', 'datamachine_workspace_git_unavailable' === $error->get_error_code());
$assert('unavailable error names operation', str_contains($error->get_error_message(), 'Test workspace operation'));
$assert('unavailable error carries remediation', str_contains($error->get_error_message(), 'workspace backend'));
$assert('unavailable error data carries backend', 'local_git' === ( $error->get_error_data()['backend'] ?? '' ));

if (! empty($failures) ) {
    echo "\nFAIL: " . count($failures) . " assertion(s) failed out of {$total}\n";
    foreach ( $failures as $failure ) {
        echo "  - {$failure}\n";
    }
    exit(1);
}

echo "\nOK ({$total} assertions)\n";
exit(0);
