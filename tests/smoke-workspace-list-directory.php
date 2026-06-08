<?php
/**
 * Smoke test for local workspace directory listing contract.
 *
 * Run: php tests/smoke-workspace-list-directory.php
 */

declare( strict_types=1 );

namespace {
    $workspace_root = sys_get_temp_dir() . '/dmc-workspace-list-directory';
    if (! defined('ABSPATH') ) {
        define('ABSPATH', $workspace_root . '/site/');
    }
    if (! defined('DATAMACHINE_WORKSPACE_PATH') ) {
        define('DATAMACHINE_WORKSPACE_PATH', $workspace_root . '/workspace');
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

    if (! function_exists('size_format') ) {
        function size_format( $bytes ): string
        {
            return (string) $bytes . ' B';
        }
    }

    include __DIR__ . '/../inc/Support/PathSecurity.php';
    include __DIR__ . '/../inc/Workspace/Workspace.php';
    include __DIR__ . '/../inc/Workspace/WorkspaceReader.php';

    use DataMachineCode\Workspace\Workspace;
    use DataMachineCode\Workspace\WorkspaceReader;

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

    echo "Workspace list directory - smoke\n";

    @mkdir(DATAMACHINE_WORKSPACE_PATH . '/example/src', 0777, true);
    file_put_contents(DATAMACHINE_WORKSPACE_PATH . '/example/README.md', "hello\n");

    $reader = new WorkspaceReader(new Workspace());
    $list   = $reader->list_directory('example');

    $entries = array();
    if (! is_wp_error($list) ) {
        foreach ( $list['entries'] as $entry ) {
            $entries[$entry['name']] = $entry;
        }
    }

    $assert('list directory succeeds', ! is_wp_error($list) && true === $list['success']);
    $assert('directory entry includes integer size', isset($entries['src']) && 'directory' === $entries['src']['type'] && 0 === $entries['src']['size']);
    $assert('file entry includes integer byte size', isset($entries['README.md']) && 'file' === $entries['README.md']['type'] && 6 === $entries['README.md']['size']);
    $assert('every entry exposes schema-safe integer size', ! is_wp_error($list) && array_reduce($list['entries'], fn( bool $carry, array $entry ): bool => $carry && array_key_exists('size', $entry) && is_int($entry['size']), true));

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
