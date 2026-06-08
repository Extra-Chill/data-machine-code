<?php
/**
 * Pure-PHP smoke test for DMC bundle workspace preload artifacts.
 *
 * Run: php tests/smoke-workspace-preload-artifact.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace DataMachineCode\Abilities {
    class GitHubAbilities
    {
    }
}

namespace {
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__ . '/');
    }

    if (! class_exists('WP_Error') ) {
        class WP_Error
        {
            public function __construct( private string $code, private string $message, private mixed $data = array() )
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
        function is_wp_error( mixed $value ): bool
        {
            return $value instanceof WP_Error; 
        }
    }

    if (! function_exists('wp_parse_url') ) {
        function wp_parse_url( string $url, int $component = -1 ): mixed
        {
            return -1 === $component ? parse_url($url) : parse_url($url, $component);
        }
    }

    $GLOBALS['dmc_remote_workspace_options'] = array();

    if (! function_exists('get_option') ) {
        function get_option( string $key, mixed $default_value = false ): mixed
        {
            return $GLOBALS['dmc_remote_workspace_options'][ $key ] ?? $default_value;
        }
    }

    if (! function_exists('update_option') ) {
        function update_option( string $key, mixed $value, bool $autoload = true ): bool
        {
            unset($autoload);
            $GLOBALS['dmc_remote_workspace_options'][ $key ] = $value;
            return true;
        }
    }

    include __DIR__ . '/../inc/Workspace/RemoteWorkspaceBackend.php';
    include __DIR__ . '/../inc/Bundle/WorkspacePreloadArtifact.php';

    use DataMachineCode\Bundle\WorkspacePreloadArtifact;

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

    echo "Workspace preload artifact - smoke\n";

    $clones   = array();
    $artifact = new WorkspacePreloadArtifact(
        static function ( array $input ) use ( &$clones ): array {
            $clones[] = $input;
            return array(
            'success' => true,
            'name'    => $input['name'] ?? basename((string) $input['url'], '.git'),
            'path'    => '/workspace/' . ( $input['name'] ?? basename((string) $input['url'], '.git') ),
            );
        }
    );

    $types = $artifact->register_artifact_type(array( 'agent' ));
    $assert('registers DMC workspace preload artifact type', in_array(WorkspacePreloadArtifact::ARTIFACT_TYPE, $types, true));

    $ignored = $artifact->apply_artifact(null, array( 'artifact_type' => 'other/plugin', 'artifact_id' => 'noop' ));
    $assert('ignores other artifact types', null === $ignored);

    $invalid = $artifact->apply_artifact(
        null,
        array(
        'artifact_type' => WorkspacePreloadArtifact::ARTIFACT_TYPE,
        'artifact_id'   => 'bad',
        'payload'       => array( 'repositories' => array() ),
        )
    );
    $assert('rejects empty repositories list', is_wp_error($invalid) && 'datamachine_code_workspace_preload_invalid_payload' === $invalid->get_error_code());

    $applied = $artifact->apply_artifact(
        null,
        array(
        'artifact_type' => WorkspacePreloadArtifact::ARTIFACT_TYPE,
        'artifact_id'   => 'transformer-repos',
        'payload'       => array(
                'repositories' => array(
                    array(
                        'name' => 'static-site-importer',
                        'url'  => 'https://github.com/chubes4/static-site-importer.git',
                    ),
                    array(
                        'name' => 'block-format-bridge',
                        'url'  => 'git@github.com:chubes4/block-format-bridge.git',
                        'full' => true,
                    ),
                ),
        ),
        )
    );
    $assert('applies valid preload artifact', ! is_wp_error($applied) && true === ( $applied['success'] ?? false ));
    $assert('calls clone path for each repository', 2 === count($clones));
    $assert('passes name and full option to clone path', true === ( $clones[1]['full'] ?? false ) && 'block-format-bridge' === ( $clones[1]['name'] ?? '' ));
    $assert('returns per-repo results', ! is_wp_error($applied) && 2 === count($applied['repositories'] ?? array()));

    $exists_artifact = new WorkspacePreloadArtifact(
        static function ( array $input ): WP_Error {
            return new WP_Error(
                'repo_exists',
                'Clone target already exists.',
                array(
                'state' => 'existing checkout',
                'path'  => '/workspace/' . ( $input['name'] ?? 'repo' ),
                )
            );
        }
    );
    $exists = $exists_artifact->apply_artifact(
        null,
        array(
        'artifact_type' => WorkspacePreloadArtifact::ARTIFACT_TYPE,
        'artifact_id'   => 'existing',
        'payload'       => array(
                'repositories' => array(
                    array(
                        'name' => 'static-site-importer',
                        'url'  => 'https://github.com/chubes4/static-site-importer.git',
                    ),
                ),
        ),
        )
    );
    $assert('treats existing checkout as idempotent success', ! is_wp_error($exists) && true === ( $exists['repositories'][0]['already_exists'] ?? false ));

    $remote_fallback_artifact = new WorkspacePreloadArtifact(
        static function (): WP_Error {
            return new WP_Error(
                'datamachine_workspace_git_unavailable',
                'Clone workspace repository cannot run with the current workspace backend.',
                array( 'status' => 500 )
            );
        }
    );
    $remote_fallback = $remote_fallback_artifact->apply_artifact(
        null,
        array(
        'artifact_type' => WorkspacePreloadArtifact::ARTIFACT_TYPE,
        'artifact_id'   => 'remote-fallback',
        'payload'       => array(
                'repositories' => array(
                    array(
                        'name' => 'static-site-importer',
                        'url'  => 'https://github.com/chubes4/static-site-importer.git',
                    ),
                ),
        ),
        )
    );
    $assert('falls back to remote backend when local git clone is unavailable', ! is_wp_error($remote_fallback) && 'github_api' === ( $remote_fallback['repositories'][0]['result']['backend'] ?? '' ));
    $assert(
        'remote preload state keeps remote backend active for later tools',
        \DataMachineCode\Workspace\RemoteWorkspaceBackend::has_registered_state()
        && false === \DataMachineCode\Workspace\RemoteWorkspaceBackend::should_handle_for_local_capabilities(true, true)
        && \DataMachineCode\Workspace\RemoteWorkspaceBackend::should_handle()
    );

    if (array() !== $failures ) {
        echo "\nFailures:\n";
        foreach ( $failures as $failure ) {
            echo " - {$failure}\n";
        }
    }

    echo "\nResult: " . ( $total - count($failures) ) . "/{$total} passed\n";
    exit(array() === $failures ? 0 : 1);
}
