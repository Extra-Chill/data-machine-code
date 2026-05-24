<?php
/**
 * WordPress runtime inspection abilities.
 *
 * @package DataMachineCode\Abilities
 */

namespace DataMachineCode\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachineCode\Runtime\WordPressRuntimeInspector;

defined('ABSPATH') || exit;

class WordPressRuntimeAbilities
{

    private static bool $registered = false;

    public function __construct()
    {
        if (self::$registered ) {
            return;
        }

        if (! function_exists('wp_register_ability') ) {
            add_action(
                'wp_abilities_api_init', function (): void {
                    if (self::$registered || ! function_exists('wp_register_ability') ) {
                        return;
                    }

                    $this->registerAbilities();
                    self::$registered = true;
                } 
            );
            return;
        }

        $this->registerAbilities();
        self::$registered = true;
    }

    private function registerAbilities(): void
    {
        $register_callback = function () {
            wp_register_ability(
                'datamachine-code/wordpress-runtime-inventory',
                array(
                'label'               => 'Inspect WordPress Runtime Inventory',
                'description'         => 'Read-only inventory of the live WordPress runtime: versions, active theme, installed plugins/themes, mu-plugins, drop-ins, and safe source-root metadata.',
                'category'            => 'datamachine-code-runtime',
                'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(),
                    ),
                    'output_schema'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'success'    => array( 'type' => 'boolean' ),
                            'wordpress'  => array( 'type' => 'object' ),
                            'theme'      => array( 'type' => 'object' ),
                            'themes'     => array( 'type' => 'array' ),
                            'plugins'    => array( 'type' => 'array' ),
                            'mu_plugins' => array( 'type' => 'array' ),
                            'drop_ins'   => array( 'type' => 'array' ),
                            'roots'      => array( 'type' => 'array' ),
                            'policy'     => array( 'type' => 'object' ),
                    ),
                    ),
                    'execute_callback'    => array( self::class, 'inventory' ),
                    'permission_callback' => fn() => PermissionHelper::can_manage(),
                    'meta'                => array( 'show_in_rest' => true ),
                )
            );

            wp_register_ability(
                'datamachine-code/wordpress-runtime-ls',
                array(
                'label'               => 'List WordPress Runtime Directory',
                'description'         => 'Read-only directory listing under allowlisted WordPress source roots only.',
                'category'            => 'datamachine-code-runtime',
                'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                'path'        => array(
                                'type'        => 'string',
                                'description' => 'Runtime path relative to ABSPATH. Must be under wp-content/plugins, wp-content/themes, wp-includes, or wp-admin.',
                ),
                'max_entries' => array(
                'type'        => 'integer',
                'description' => 'Maximum entries to return (default 200, max 1000).',
                ),
                ),
                    ),
                    'output_schema'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'success'   => array( 'type' => 'boolean' ),
                            'path'      => array( 'type' => 'string' ),
                            'root'      => array( 'type' => 'string' ),
                            'entries'   => array( 'type' => 'array' ),
                            'count'     => array( 'type' => 'integer' ),
                            'truncated' => array( 'type' => 'boolean' ),
                    ),
                    ),
                    'execute_callback'    => array( self::class, 'ls' ),
                    'permission_callback' => fn() => PermissionHelper::can_manage(),
                    'meta'                => array( 'show_in_rest' => true ),
                )
            );

            wp_register_ability(
                'datamachine-code/wordpress-runtime-read',
                array(
                'label'               => 'Read WordPress Runtime File',
                'description'         => 'Read a bounded text file under allowlisted WordPress source roots only. Denies sensitive paths, traversal, oversized files, and binary files.',
                'category'            => 'datamachine-code-runtime',
                'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                'path'     => array(
                                'type'        => 'string',
                                'description' => 'Runtime file path relative to ABSPATH. Must be under an allowlisted source root.',
                ),
                'max_size' => array(
                'type'        => 'integer',
                'description' => 'Maximum file size in bytes (default/max 1MB).',
                ),
                'offset'   => array(
                'type'        => 'integer',
                'description' => 'Line number to start reading from (1-indexed).',
                ),
                'limit'    => array(
                'type'        => 'integer',
                'description' => 'Maximum number of lines to return (default 500, max 2000).',
                ),
                ),
                'required'   => array( 'path' ),
                    ),
                    'output_schema'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'success'     => array( 'type' => 'boolean' ),
                            'path'        => array( 'type' => 'string' ),
                            'root'        => array( 'type' => 'string' ),
                            'size'        => array( 'type' => 'integer' ),
                            'offset'      => array( 'type' => 'integer' ),
                            'limit'       => array( 'type' => 'integer' ),
                            'lines_read'  => array( 'type' => 'integer' ),
                            'total_lines' => array( 'type' => 'integer' ),
                            'truncated'   => array( 'type' => 'boolean' ),
                            'content'     => array( 'type' => 'string' ),
                    ),
                    ),
                    'execute_callback'    => array( self::class, 'read' ),
                    'permission_callback' => fn() => PermissionHelper::can_manage(),
                    'meta'                => array( 'show_in_rest' => true ),
                )
            );
        };

        if (function_exists('doing_action') && doing_action('wp_abilities_api_init') ) {
            $register_callback();
            return;
        }

        add_action('wp_abilities_api_init', $register_callback);
    }

    /**
     * @param array<string,mixed> $input Input parameters. @return array<string,mixed> 
     */
    public static function inventory( array $input ): array  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
    {
        return ( new WordPressRuntimeInspector() )->inventory();
    }

    /**
     * @param array<string,mixed> $input Input parameters. @return array<string,mixed>|\WP_Error 
     */
    public static function ls( array $input ): array|\WP_Error
    {
        return ( new WordPressRuntimeInspector() )->ls($input);
    }

    /**
     * @param array<string,mixed> $input Input parameters. @return array<string,mixed>|\WP_Error 
     */
    public static function read( array $input ): array|\WP_Error
    {
        return ( new WordPressRuntimeInspector() )->read($input);
    }
}
