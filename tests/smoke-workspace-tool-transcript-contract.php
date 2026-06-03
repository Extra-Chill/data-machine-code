<?php
/**
 * Smoke test for DMC workspace tool payloads in Data Machine transcripts.
 *
 * Run: php tests/smoke-workspace-tool-transcript-contract.php
 */

declare( strict_types=1 );

namespace {
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__ . '/');
    }

    $GLOBALS['dmc_workspace_transcript_ability_name'] = '';

    function wp_json_encode( $data, int $flags = 0, int $depth = 512 ): string|false
    {
        return json_encode($data, $flags, $depth);
    }

    function add_action( string $hook, callable $callback, int $priority = 10 ): void
    {
    }

    function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void
    {
    }

    function apply_filters( string $tag, $value )
    {
        return $value;
    }

    function get_option( string $name, $default = false )
    {
        return $default;
    }

    function doing_action( string $hook ): bool
    {
        return 'wp_abilities_api_init' === $hook;
    }

    function is_wp_error( $value ): bool
    {
        return $value instanceof WP_Error;
    }

    function wp_get_ability( string $name )
    {
        $GLOBALS['dmc_workspace_transcript_ability_name'] = $name;
        return new DataMachineCodeWorkspaceTranscriptFakeAbility($name);
    }

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

    class DataMachineCodeWorkspaceTranscriptFakeAbility
    {
        public function __construct( private string $name )
        {
        }

        public function execute( array $input ): array
        {
            return match ( $this->name ) {
                'datamachine-code/workspace-ls' => array(
                    'success' => true,
                    'repo'    => $input['repo'] ?? '',
                    'path'    => $input['path'] ?? '',
                    'entries' => array(
                        array(
                            'name' => 'README.md',
                            'type' => 'file',
                            'size' => 128,
                        ),
                    ),
                ),
                'datamachine-code/workspace-read' => array(
                    'success' => true,
                    'repo'    => $input['repo'] ?? '',
                    'path'    => $input['path'] ?? '',
                    'content' => "# Demo\nworkspace_read_visible_anchor\n",
                    'lines'   => 2,
                ),
                'datamachine-code/workspace-grep' => array(
                    'success' => true,
                    'repo'    => $input['repo'] ?? '',
                    'pattern' => $input['pattern'] ?? '',
                    'count'   => 1,
                    'matches' => array(
                        array(
                            'path'    => 'README.md',
                            'line'    => 2,
                            'preview' => 'workspace_grep_visible_anchor',
                        ),
                    ),
                ),
                'datamachine-code/workspace-write' => array(
                    'success' => true,
                    'repo'    => $input['repo'] ?? '',
                    'path'    => $input['path'] ?? '',
                    'bytes'   => strlen((string) ( $input['content'] ?? '' )),
                    'message' => 'Wrote workspace_write_visible_anchor.txt',
                ),
                'datamachine-code/workspace-edit' => array(
                    'success'      => true,
                    'repo'         => $input['repo'] ?? '',
                    'path'         => $input['path'] ?? '',
                    'replacements' => 1,
                    'diff'         => "--- a/README.md\n+++ b/README.md\n@@\n-workspace_edit_old_anchor\n+workspace_edit_visible_anchor\n",
                ),
                default => array(
                    'success' => true,
                ),
            };
        }
    }

    function dmc_workspace_transcript_find_data_machine_core(): ?string
    {
        $candidates = array_filter(
            array(
                getenv('DATAMACHINE_CORE_PATH') ?: null,
                dirname(__DIR__, 2) . '/data-machine',
                dirname(__DIR__) . '/../data-machine',
            )
        );

        foreach ( $candidates as $candidate ) {
            $path = rtrim((string) $candidate, '/') . '/inc/Engine/AI/ConversationManager.php';
            if (is_file($path) ) {
                return $path;
            }
        }

        return null;
    }
}

namespace AgentsAPI\AI {
    class WP_Agent_Message
    {
        public static function text( string $role, $content, array $metadata = array() ): array
        {
            return compact('role', 'content') + array( 'metadata' => $metadata );
        }

        public static function toolCall( string $content, string $tool_name, array $parameters, int $turn, array $metadata = array() ): array
        {
            return array(
                'role'     => 'assistant',
                'type'     => 'tool_call',
                'content'  => $content,
                'payload'  => compact('tool_name', 'parameters', 'turn'),
                'metadata' => $metadata,
            );
        }

        public static function toolResult( string $content, string $tool_name, array $payload, array $metadata = array() ): array
        {
            $payload['tool_name'] = $tool_name;

            return array(
                'role'     => 'user',
                'type'     => 'tool_result',
                'content'  => $content,
                'payload'  => $payload,
                'metadata' => $metadata,
            );
        }
    }
}

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
        protected function registerTool( string $tool_id, callable $definition_callback, array $contexts = array(), array $options = array() ): void
        {
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
    include __DIR__ . '/../inc/Workspace/WorkspaceAliasResolver.php';
    include __DIR__ . '/../inc/Tools/WorkspaceTools.php';

    $core_formatter = dmc_workspace_transcript_find_data_machine_core();
    if (null === $core_formatter ) {
        echo "SKIP: Data Machine core checkout not found. Set DATAMACHINE_CORE_PATH to run this contract smoke.\n";
        exit(0);
    }

    require_once $core_formatter;

    $failures = array();
    $passes   = 0;
    $assert   = function ( string $label, bool $condition ) use ( &$failures, &$passes ): void {
        if ($condition ) {
            ++$passes;
            echo "  ok {$label}\n";
            return;
        }

        $failures[] = $label;
        echo "  fail {$label}\n";
    };

    $tools = new \DataMachineCode\Tools\WorkspaceTools();

    $cases = array(
        'workspace_ls'    => array(
            'result'  => $tools->handleLs(array( 'repo' => 'demo', 'path' => '.' )),
            'params'  => array( 'repo' => 'demo', 'path' => '.' ),
            'markers' => array( 'README.md', 'entries' ),
        ),
        'workspace_read'  => array(
            'result'  => $tools->handleRead(array( 'repo' => 'demo', 'path' => 'README.md' )),
            'params'  => array( 'repo' => 'demo', 'path' => 'README.md' ),
            'markers' => array( 'workspace_read_visible_anchor', 'content' ),
        ),
        'workspace_grep'  => array(
            'result'  => $tools->handleGrep(array( 'repo' => 'demo', 'pattern' => 'workspace_grep_visible_anchor' )),
            'params'  => array( 'repo' => 'demo', 'pattern' => 'workspace_grep_visible_anchor' ),
            'markers' => array( 'workspace_grep_visible_anchor', 'matches' ),
        ),
        'workspace_write' => array(
            'result'  => $tools->handleWrite(array( 'repo' => 'demo', 'path' => 'workspace_write_visible_anchor.txt', 'content' => 'hello' )),
            'params'  => array( 'repo' => 'demo', 'path' => 'workspace_write_visible_anchor.txt' ),
            'markers' => array( 'workspace_write_visible_anchor.txt', 'bytes' ),
        ),
        'workspace_edit'  => array(
            'result'  => $tools->handleEdit(
                array(
                    'repo'       => 'demo',
                    'path'       => 'README.md',
                    'old_string' => 'workspace_edit_old_anchor',
                    'new_string' => 'workspace_edit_visible_anchor',
                )
            ),
            'params'  => array( 'repo' => 'demo', 'path' => 'README.md' ),
            'markers' => array( 'workspace_edit_visible_anchor', 'diff' ),
        ),
    );

    echo "Workspace tool transcript contract - smoke\n";

    foreach ( $cases as $tool_name => $case ) {
        $result  = $case['result'];
        $message = \DataMachine\Engine\AI\ConversationManager::formatToolResultMessage(
            $tool_name,
            $result,
            $case['params'],
            false,
            1
        );

        $content = (string) ( $message['content'] ?? '' );
        $payload = is_array($message['payload'] ?? null) ? $message['payload'] : array();

        $assert("{$tool_name} direct wrapper succeeds", true === ( $result['success'] ?? false ));
        $assert("{$tool_name} transcript is a tool result", 'tool_result' === ( $message['type'] ?? null ));
        $assert("{$tool_name} transcript keeps success metadata", true === ( $payload['success'] ?? false ));
        $assert("{$tool_name} transcript keeps tool_data payload", ( $result['data'] ?? null ) === ( $payload['tool_data'] ?? null ));

        foreach ( $case['markers'] as $marker ) {
            $assert("{$tool_name} model-facing content includes {$marker}", str_contains($content, $marker));
        }

        $assert("{$tool_name} model-facing content is not generic success only", trim($content) !== 'TOOL RESPONSE (Turn 1): SUCCESS.');
    }

    if ($failures ) {
        echo "\nFAIL: " . count($failures) . " assertion(s) failed\n";
        foreach ( $failures as $failure ) {
            echo "  - {$failure}\n";
        }
        exit(1);
    }

    echo "\nOK ({$passes} assertions)\n";
    exit(0);
}
