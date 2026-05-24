<?php
/**
 * Smoke test for Data Machine Code AI tool schemas.
 *
 * Run: php tests/smoke-tool-schemas.php
 */

declare( strict_types=1 );

namespace {
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__ . '/');
    }
}

namespace DataMachine\Engine\AI\Tools {
    class BaseTool
    {
        /**
         * @param array<int,string> $contexts Context names. @param array<string,mixed> $options Tool options. 
         */
        protected function registerTool( string $tool_id, callable $definition_callback, array $contexts = array(), array $options = array() ): void
        {
        }
    }
}

namespace DataMachineCode\Abilities {
    class GitHubAbilities
    {
        public static function getRegisteredRepos(): array
        {
            return array();
        }
    }
}

namespace {
    $failures = 0;
    $total    = 0;

    $assert = function ( bool $condition, string $message ) use ( &$failures, &$total ): void {
        $total++;
        if ($condition ) {
            echo "  ✓ {$message}\n";
            return;
        }

        $failures++;
        echo "  ✗ {$message}\n";
    };

    $assert_schema = function ( array $schema, string $path ) use ( &$assert, &$assert_schema ): void {
        if (array_key_exists('required', $schema) ) {
            $assert(is_array($schema['required']), $path . ' required is an object-level array');
        }

        if (( $schema['type'] ?? null ) === 'array' ) {
            $assert(isset($schema['items']) && is_array($schema['items']), $path . ' array schema defines items');
        }

        foreach ( $schema['properties'] ?? array() as $property => $property_schema ) {
            if (! is_array($property_schema) ) {
                $assert(false, $path . '.properties.' . $property . ' schema is an array');
                continue;
            }

            $assert(! array_key_exists('required', $property_schema) || is_array($property_schema['required']), $path . '.properties.' . $property . ' avoids property-level required flags');
            $assert_schema($property_schema, $path . '.properties.' . $property);
        }

        if (isset($schema['items']) && is_array($schema['items']) ) {
            $assert_schema($schema['items'], $path . '.items');
        }
    };

    foreach ( glob(__DIR__ . '/../inc/Tools/*.php') ?: array() as $file ) {
        include_once $file;
    }

    $classes = array(
    \DataMachineCode\Tools\GitHubIssueTool::class,
    \DataMachineCode\Tools\GitHubPullRequestTool::class,
    \DataMachineCode\Tools\GitHubTools::class,
    \DataMachineCode\Tools\WordPressRuntimeTools::class,
    \DataMachineCode\Tools\WorkspaceDiffTools::class,
    \DataMachineCode\Tools\WorkspaceTools::class,
    );

    echo "Data Machine Code tool schemas\n";

    foreach ( $classes as $class ) {
        $instance = new $class();
        $methods  = array_filter(
            get_class_methods($class),
            static fn( string $method ): bool => str_starts_with($method, 'get') && str_ends_with($method, 'Definition')
        );

        foreach ( $methods as $method ) {
            $definition = $instance->{$method}();
            if (! array_key_exists('parameters', $definition) ) {
                continue;
            }

            $path = $class . '::' . $method . '.parameters';
            $assert(is_array($definition['parameters']), $path . ' is an array');
            if (! is_array($definition['parameters']) ) {
                continue;
            }

            $assert('object' === ( $definition['parameters']['type'] ?? null ), $path . ' uses canonical object schema');
            $assert(isset($definition['parameters']['properties']) && is_array($definition['parameters']['properties']), $path . ' declares properties');
            $assert_schema($definition['parameters'], $path);
        }
    }

    if ($failures > 0 ) {
        echo "\n{$failures} of {$total} assertions failed.\n";
        exit(1);
    }

    echo "\nAll {$total} DMC tool schema assertions passed.\n";
}
