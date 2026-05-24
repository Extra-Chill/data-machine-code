<?php
/**
 * Pure-PHP smoke test for WordPress runtime inspection abilities and tools.
 *
 * Run: php tests/smoke-wordpress-runtime-inspection.php
 */

declare( strict_types=1 );

namespace DataMachine\Abilities {
    class PermissionHelper
    {
        public static function can_manage(): bool
        {
            return true;
        }
    }
}

namespace DataMachine\Engine\AI\Tools {
    class BaseTool
    {
        public array $registered = array();

        protected function registerTool( string $name, array $definition_callback, array $contexts, array $options = array() ): void
        {
            $this->registered[ $name ] = array(
            'definition_callback' => $definition_callback,
            'contexts'            => $contexts,
            'options'             => $options,
            );
        }

        protected function buildErrorResponse( string $message, string $tool_name ): array
        {
            return array(
            'success'   => false,
            'error'     => $message,
            'tool_name' => $tool_name,
            );
        }
    }
}

namespace {
    $root = sys_get_temp_dir() . '/dmc-runtime-inspection-' . getmypid();
    mkdir($root . '/wp-content/plugins/sample', 0777, true);
    mkdir($root . '/wp-content/themes/twentytwentyfour', 0777, true);
    mkdir($root . '/wp-content/uploads', 0777, true);
    mkdir($root . '/wp-includes', 0777, true);
    mkdir($root . '/wp-admin', 0777, true);
    file_put_contents($root . '/wp-content/plugins/sample/sample.php', "<?php\n// sample plugin\nfunction sample_plugin() {}\n");
    file_put_contents($root . '/wp-content/plugins/sample/.env', "SECRET=value\n");
    file_put_contents($root . '/wp-content/plugins/sample/large.php', str_repeat('a', 128));
    file_put_contents($root . '/wp-content/plugins/sample/binary.dat', "abc\0def");
    file_put_contents($root . '/wp-config.php', "<?php\ndefine('DB_PASSWORD', 'secret');\n");
    file_put_contents($root . '/wp-content/uploads/leak.txt', 'upload');

    if (! defined('ABSPATH') ) {
        define('ABSPATH', $root . '/');
    }
    define('WP_CONTENT_DIR', $root . '/wp-content');
    define('WP_PLUGIN_DIR', $root . '/wp-content/plugins');
    define('WPMU_PLUGIN_DIR', $root . '/wp-content/mu-plugins');
    define('WP_DEBUG', true);

    class WP_Ability
    {
    }

    if (! class_exists('WP_Error') ) {
        class WP_Error
        {
            public function __construct( private string $code = '', private string $message = '', private array $data = array() )
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
        function is_wp_error( $thing ): bool
        {
            return $thing instanceof WP_Error; 
        }
    }

    $GLOBALS['dmc_registered_abilities'] = array();
    $GLOBALS['dmc_executed_abilities']   = array();

    function wp_register_ability( string $name, array $definition ): void
    {
        $GLOBALS['dmc_registered_abilities'][ $name ] = $definition;
    }

    function doing_action( string $hook ): bool
    {
        return 'wp_abilities_api_init' === $hook; 
    }
    function add_action( string $hook, callable $callback ): void
    {
    }
    function get_bloginfo( string $show ): string
    {
        return 'version' === $show ? '6.9-test' : ''; 
    }
    function is_multisite(): bool
    {
        return false; 
    }
    function get_template(): string
    {
        return 'twentytwentyfour'; 
    }
    function get_stylesheet(): string
    {
        return 'twentytwentyfour'; 
    }
    function get_option( string $name, mixed $default = null ): mixed
    {
        return 'active_plugins' === $name ? array( 'sample/sample.php' ) : $default; 
    }
    function is_plugin_active( string $plugin ): bool
    {
        return 'sample/sample.php' === $plugin; 
    }
    function is_plugin_active_for_network( string $plugin ): bool
    {
        return 'network/network.php' === $plugin; 
    }
    function get_plugins(): array
    {
        return array( 'sample/sample.php' => array( 'Name' => 'Sample Plugin', 'Version' => '1.2.3' ) ); 
    }
    function get_mu_plugins(): array
    {
        return array( 'loader.php' => array( 'Name' => 'MU Loader', 'Version' => '0.1.0' ) ); 
    }
    function get_dropins(): array
    {
        return array( 'db.php' => array( 'Name' => 'SQLite integration', 'Description' => 'Database drop-in' ) ); 
    }
    function wp_get_theme(): object
    {
        return new class() {
            public function get( string $key ): string
            {
                return array( 'Name' => 'Twenty Twenty-Four', 'Version' => '1.0' )[ $key ] ?? ''; 
            }
        };
    }
    function wp_get_themes(): array
    {
        return array(
        'twentytwentyfour' => new class() {
            public function get( string $key ): string
            {
                return array( 'Name' => 'Twenty Twenty-Four', 'Version' => '1.0' )[ $key ] ?? ''; 
            }
        },
        );
    }

    function wp_get_ability( string $name )
    {
        if (! isset($GLOBALS['dmc_registered_abilities'][ $name ]) ) {
            return null;
        }

        return new class( $name ) {
            public function __construct( private string $name )
            {
            }
            public function execute( array $parameters ): array|WP_Error
            {
                $GLOBALS['dmc_executed_abilities'][ $this->name ] = $parameters;
                $callback = $GLOBALS['dmc_registered_abilities'][ $this->name ]['execute_callback'];
                return call_user_func($callback, $parameters);
            }
        };
    }

    include __DIR__ . '/../inc/Runtime/WordPressRuntimeInspector.php';
    include __DIR__ . '/../inc/Abilities/WordPressRuntimeAbilities.php';
    include __DIR__ . '/../inc/Tools/WordPressRuntimeTools.php';

    use DataMachineCode\Abilities\WordPressRuntimeAbilities;
    use DataMachineCode\Runtime\WordPressRuntimeInspector;
    use DataMachineCode\Tools\WordPressRuntimeTools;

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

    echo "WordPress runtime inspection - smoke\n";

    new WordPressRuntimeAbilities();
    $tools     = new WordPressRuntimeTools();
    $inspector = new WordPressRuntimeInspector();

    foreach ( array(
    'wordpress_runtime_inventory' => 'datamachine-code/wordpress-runtime-inventory',
    'wordpress_runtime_ls'        => 'datamachine-code/wordpress-runtime-ls',
    'wordpress_runtime_read'      => 'datamachine-code/wordpress-runtime-read',
    ) as $tool_name => $ability_name ) {
        $tool = $tools->registered[ $tool_name ] ?? null;
        $assert("{$ability_name} ability registered", isset($GLOBALS['dmc_registered_abilities'][ $ability_name ]));
        $assert("{$tool_name} tool registered", null !== $tool);
        $assert("{$tool_name} available in chat", in_array('chat', $tool['contexts'] ?? array(), true));
        $assert("{$tool_name} available in pipeline", in_array('pipeline', $tool['contexts'] ?? array(), true));
        $assert("{$tool_name} is policy gated", 'editor' === ( $tool['options']['access_level'] ?? '' ));
        $assert("{$tool_name} records ability", $ability_name === ( $tool['options']['ability'] ?? '' ));
    }

    $inventory = $inspector->inventory();
    $assert('inventory succeeds', true === ( $inventory['success'] ?? false ));
    $assert('inventory exposes wp version', '6.9-test' === ( $inventory['wordpress']['version'] ?? '' ));
    $assert('inventory exposes active theme', 'twentytwentyfour' === ( $inventory['theme']['stylesheet'] ?? '' ));
    $assert('inventory exposes active plugin', true === ( $inventory['plugins'][0]['active'] ?? false ));
    $assert('inventory exposes mu plugins', 'MU Loader' === ( $inventory['mu_plugins'][0]['name'] ?? '' ));
    $assert('inventory exposes drop-ins', 'db.php' === ( $inventory['drop_ins'][0]['file'] ?? '' ));
    $assert('inventory exposes allowed roots', in_array('wp-content/plugins', $inventory['policy']['allowed_roots'] ?? array(), true));

    $ls = $inspector->ls(array( 'path' => 'wp-content/plugins/sample' ));
    $assert('allowlisted ls succeeds', ! is_wp_error($ls) && true === ( $ls['success'] ?? false ));
    $assert('allowlisted ls returns file', ! is_wp_error($ls) && in_array('sample.php', array_column($ls['entries'], 'name'), true));

    $read = $inspector->read(array( 'path' => 'wp-content/plugins/sample/sample.php', 'offset' => 2, 'limit' => 1 ));
    $assert('allowlisted read succeeds', ! is_wp_error($read) && true === ( $read['success'] ?? false ));
    $assert('allowlisted read honors line bounds', ! is_wp_error($read) && '// sample plugin' === ( $read['content'] ?? '' ));

    $sensitive = $inspector->read(array( 'path' => 'wp-content/plugins/sample/.env' ));
    $assert('sensitive path denied', is_wp_error($sensitive) && 'datamachine_runtime_path_denied' === $sensitive->get_error_code());

    $wp_config = $inspector->read(array( 'path' => 'wp-config.php' ));
    $assert('wp-config denied before arbitrary read', is_wp_error($wp_config) && 'datamachine_runtime_path_denied' === $wp_config->get_error_code());

    $uploads = $inspector->read(array( 'path' => 'wp-content/uploads/leak.txt' ));
    $assert('uploads denied by default', is_wp_error($uploads) && 'datamachine_runtime_path_denied' === $uploads->get_error_code());

    $traversal = $inspector->ls(array( 'path' => 'wp-content/plugins/../themes' ));
    $assert('path traversal rejected', is_wp_error($traversal) && 'datamachine_runtime_path_traversal' === $traversal->get_error_code());

    $oversized = $inspector->read(array( 'path' => 'wp-content/plugins/sample/large.php', 'max_size' => 10 ));
    $assert('oversized file rejected', is_wp_error($oversized) && 'datamachine_runtime_file_too_large' === $oversized->get_error_code());

    $binary = $inspector->read(array( 'path' => 'wp-content/plugins/sample/binary.dat' ));
    $assert('binary file rejected', is_wp_error($binary) && 'datamachine_runtime_binary_file' === $binary->get_error_code());

    $tool_response = $tools->handle_tool_call(array( 'path' => 'wp-content/plugins/sample/sample.php' ), $tools->getReadDefinition());
    $assert('tool delegates to ability', true === ( $tool_response['success'] ?? false ) && isset($GLOBALS['dmc_executed_abilities']['datamachine-code/wordpress-runtime-read']));

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
