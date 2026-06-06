<?php
/**
 * Smoke test for workspace edit failure context.
 *
 * Run: php tests/smoke-workspace-edit-context.php
 */

declare( strict_types=1 );

namespace {
    $workspace_root = sys_get_temp_dir() . '/dmc-workspace-edit-context';
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
}

namespace DataMachine\Core\FilesRepository {
    class FilesystemHelper
    {
        public static function get(): self
        {
            return new self(); 
        }
        public function is_writable( string $path ): bool
        {
            return is_writable($path); 
        }
    }
}

namespace {
    include __DIR__ . '/../inc/Support/PathSecurity.php';
    include __DIR__ . '/../inc/Workspace/Workspace.php';
    include __DIR__ . '/../inc/Workspace/WorkspaceWriter.php';

    use DataMachineCode\Workspace\Workspace;
    use DataMachineCode\Workspace\WorkspaceWriter;

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

    echo "Workspace edit context - smoke\n";

    @mkdir(DATAMACHINE_WORKSPACE_PATH . '/example/src', 0777, true);
    file_put_contents(DATAMACHINE_WORKSPACE_PATH . '/example/src/example.php', "<?php\nfunction target() {\n\treturn 'needle';\n}\n");

    $writer = new WorkspaceWriter(new Workspace());
    $edit   = $writer->edit_file('example', 'src/example.php', "function target() {\n\treturn 'missing';\n}", "function target() {\n\treturn 'replacement';\n}");
    $data   = is_wp_error($edit) ? $edit->get_error_data() : array();

    $assert('edit fails when exact old_string is missing', is_wp_error($edit) && 'string_not_found' === $edit->get_error_code());
    $assert('edit failure includes path and suggestions', ! empty($data['path']) && ! empty($data['suggestions'][0]['preview']));

    $traversal = $writer->write_file('example', '..\\escaped.txt', 'nope');
    $assert('write rejects backslash traversal components', is_wp_error($traversal) && 'path_traversal' === $traversal->get_error_code());

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
