<?php
/**
 * Pure-PHP smoke test for the generic CLI channel transport runtime.
 *
 * Exercises CliChannelRegistry validation/substitution and
 * CliChannelTransport dispatch in both detached and synchronous modes
 * using stub commands (/bin/echo, /bin/true, /bin/false, /bin/sleep).
 *
 *   php tests/smoke-cli-channel-transport.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace {
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__ . '/');
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
            return $thing instanceof \WP_Error; 
        }
    }

    // Filter / option shims. The transport reads channel config via these.
    if (! function_exists('apply_filters') ) {
        function apply_filters( string $hook, mixed $value, ...$args ): mixed
        {
            global $datamachine_code_test_filters;
            if (! is_array($datamachine_code_test_filters) ) {
                return $value;
            }
            if (! isset($datamachine_code_test_filters[ $hook ]) ) {
                return $value;
            }
            foreach ( $datamachine_code_test_filters[ $hook ] as $callback ) {
                $value = $callback($value, ...$args);
            }
            return $value;
        }
    }

    if (! function_exists('add_filter') ) {
        function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void
        {
            global $datamachine_code_test_filters;
            if (! is_array($datamachine_code_test_filters) ) {
                $datamachine_code_test_filters = array();
            }
            $datamachine_code_test_filters[ $hook ][] = $callback;
            unset($priority, $accepted_args);
        }
    }

    if (! function_exists('do_action') ) {
        function do_action( string $hook, ...$args ): void
        {
            unset($hook, $args);
        }
    }

    if (! function_exists('get_option') ) {
        function get_option( string $key, mixed $default_value = false ): mixed
        {
            global $datamachine_code_test_options;
            if (! is_array($datamachine_code_test_options) ) {
                return $default_value;
            }
            return $datamachine_code_test_options[ $key ] ?? $default_value;
        }
    }

    include __DIR__ . '/../inc/Environment.php';
    include __DIR__ . '/../inc/Support/SecretRedactor.php';
    include __DIR__ . '/../inc/Channels/CliChannelRegistry.php';
    include __DIR__ . '/../inc/Channels/CliChannelTransport.php';

    $failures = array();
    $assert   = static function ( string $label, bool $condition ) use ( &$failures ): void {
        if ($condition ) {
            echo "  [PASS] {$label}\n";
            return;
        }
        $failures[] = $label;
        echo "  [FAIL] {$label}\n";
    };

    echo "=== smoke-cli-channel-transport ===\n";

    // Resolve standard stub binaries. Bail with a clear diagnostic if
    // the host is missing them — the runtime needs real subprocess capability.
    $resolve_bin = static function ( array $candidates ): ?string {
        foreach ( $candidates as $candidate ) {
            if (is_executable($candidate) ) {
                return $candidate;
            }
        }
        return null;
    };
    $echo_bin  = $resolve_bin(array( '/bin/echo', '/usr/bin/echo' ));
    $true_bin  = $resolve_bin(array( '/bin/true', '/usr/bin/true' ));
    $false_bin = $resolve_bin(array( '/bin/false', '/usr/bin/false' ));
    $sleep_bin = $resolve_bin(array( '/bin/sleep', '/usr/bin/sleep' ));
    $php_bin = PHP_BINARY;
    foreach ( array( $echo_bin, $true_bin, $false_bin, $sleep_bin, $php_bin ) as $candidate ) {
        if (! is_string($candidate) || ! is_executable($candidate) ) {
            echo "  [SKIP] required stub binary not present; smoke cannot run on this host\n";
            exit(0);
        }
    }

    // ---------------------------------------------------------------
    // CliChannelRegistry: normalization + lookup
    // ---------------------------------------------------------------

    global $datamachine_code_test_options, $datamachine_code_test_filters;
    $datamachine_code_test_options = array();
    $datamachine_code_test_filters = array();

    $valid_entry = array(
    'command' => $echo_bin,
    'args'    => array( '--', '{recipient}', '{message}' ),
    'detach'  => false,
    'timeout' => 5,
    );
    $normalized  = \DataMachineCode\Channels\CliChannelRegistry::normalize_entry($valid_entry);
    $assert('valid entry normalizes', is_array($normalized) && $normalized['command'] === $echo_bin);
    $assert('normalized entry has args array', is_array($normalized['args'] ?? null) && count($normalized['args']) === 3);
    $assert('normalized entry preserves detach false', false === ( $normalized['detach'] ?? null ));
    $assert('normalized entry preserves timeout', 5 === ( $normalized['timeout'] ?? null ));

    $bad_no_command = \DataMachineCode\Channels\CliChannelRegistry::normalize_entry(array( 'args' => array() ));
    $assert('missing command is rejected', null === $bad_no_command);

    $bad_args_type = \DataMachineCode\Channels\CliChannelRegistry::normalize_entry(
        array(
        'command' => $echo_bin,
        'args'    => array( 'ok', 123 ),
        ) 
    );
    $assert('non-string arg is rejected', null === $bad_args_type);

    $datamachine_code_test_options['datamachine_code_cli_channels'] = array(
    'option-channel' => array(
    'command' => $echo_bin,
    'args'    => array( 'from-option' ),
    ),
    'bogus-entry'    => 'not-an-array',
    ''               => array( 'command' => $echo_bin, 'args' => array() ),
    );

    add_filter(
        'datamachine_code_cli_channels', static function ( array $existing ): array {
            $existing['filter-channel'] = array(
            'command' => '/bin/echo',
            'args'    => array( 'from-filter' ),
            );
            return $existing;
        } 
    );

    $channels = \DataMachineCode\Channels\CliChannelRegistry::get_channels();
    $assert('option-defined channel is present', isset($channels['option-channel']));
    $assert('filter-defined channel is present', isset($channels['filter-channel']));
    $assert('malformed entries are dropped', ! isset($channels['bogus-entry']) && ! isset($channels['']));

    $lookup_hit  = \DataMachineCode\Channels\CliChannelRegistry::lookup('option-channel');
    $lookup_miss = \DataMachineCode\Channels\CliChannelRegistry::lookup('does-not-exist');
    $assert('lookup returns config for known channel', is_array($lookup_hit));
    $assert('lookup returns null for unknown channel', null === $lookup_miss);

    // ---------------------------------------------------------------
    // Token substitution: tokens are replaced inside args, no shell interp
    // ---------------------------------------------------------------

    $substituted = \DataMachineCode\Channels\CliChannelRegistry::substitute_tokens(
        array( '--to', '{recipient}', '--msg', '{message}', '--conv', '{conversation_id}', '--ch', '{channel}', 'literal' ),
        array(
        'recipient'       => 'user-123',
        'message'         => 'hello $(rm -rf /) world',
        'conversation_id' => 'conv-abc',
        'channel'         => 'fixture-channel',
        )
    );
    $assert('recipient token substituted', $substituted[1] === 'user-123');
    $assert('message token substituted verbatim (no shell interp)', $substituted[3] === 'hello $(rm -rf /) world');
    $assert('conversation_id token substituted', $substituted[5] === 'conv-abc');
    $assert('channel token substituted', $substituted[7] === 'fixture-channel');
    $assert('literal arg untouched', $substituted[8] === 'literal');

    $partial = \DataMachineCode\Channels\CliChannelRegistry::substitute_tokens(
        array( '--user={recipient}' ),
        array( 'recipient' => 'alice' )
    );
    $assert('token substituted inside compound arg', $partial[0] === '--user=alice');

    $missing_input = \DataMachineCode\Channels\CliChannelRegistry::substitute_tokens(
        array( '{recipient}', '{message}' ),
        array()
    );
    $assert('missing input substitutes empty string', $missing_input === array( '', '' ));

    // ---------------------------------------------------------------
    // Transport claim semantics
    // ---------------------------------------------------------------

    // Reset registry to a single known channel.
    $datamachine_code_test_options['datamachine_code_cli_channels'] = array(
    'sync-echo' => array(
    'command' => $echo_bin,
    'args'    => array( '{recipient}:{message}' ),
    'detach'  => false,
    'timeout' => 5,
    ),
    'sync-true' => array(
    'command' => $true_bin,
    'args'    => array(),
    'detach'  => false,
    'timeout' => 5,
    ),
    'sync-false' => array(
    'command' => $false_bin,
    'args'    => array(),
    'detach'  => false,
    'timeout' => 5,
    ),
    'sync-sleep' => array(
    'command' => $sleep_bin,
    'args'    => array( '5' ),
    'detach'  => false,
    'timeout' => 1,
    ),
    'detached-true' => array(
    'command' => $true_bin,
    'args'    => array(),
    'detach'  => true,
    ),
    );
    $datamachine_code_test_filters = array();

    $claim_known = \DataMachineCode\Channels\CliChannelTransport::maybe_claim(
        null,
        array( 'channel' => 'sync-true', 'recipient' => 'r', 'message' => 'm' )
    );
    $assert('claims registered channel', is_callable($claim_known));

    $claim_unknown = \DataMachineCode\Channels\CliChannelTransport::maybe_claim(
        null,
        array( 'channel' => 'nope', 'recipient' => 'r', 'message' => 'm' )
    );
    $assert('declines unknown channel (returns existing null)', null === $claim_unknown);

    $existing_callable = static function () {
        return new \WP_Error('prior', 'prior handler');
    };
    $claim_existing = \DataMachineCode\Channels\CliChannelTransport::maybe_claim(
        $existing_callable,
        array( 'channel' => 'sync-true', 'recipient' => 'r', 'message' => 'm' )
    );
    $assert('preserves prior handler at filter chain', $claim_existing === $existing_callable);

    $claim_empty_channel = \DataMachineCode\Channels\CliChannelTransport::maybe_claim(
        null,
        array( 'channel' => '', 'recipient' => 'r', 'message' => 'm' )
    );
    $assert('declines empty channel name', null === $claim_empty_channel);

    // ---------------------------------------------------------------
    // Sync dispatch: success path
    // ---------------------------------------------------------------

    $ok = \DataMachineCode\Channels\CliChannelTransport::execute(
        array(
        'channel'   => 'sync-echo',
        'recipient' => 'user-1',
        'message'   => 'hi-there',
        ) 
    );
    $assert('sync success returns array', is_array($ok) && ! ( $ok instanceof \WP_Error ));
    if (is_array($ok) ) {
        $assert('sync success sent=true', true === ( $ok['sent'] ?? null ));
        $assert('sync success channel echoes input', 'sync-echo' === ( $ok['channel'] ?? null ));
        $assert('sync success recipient echoes input', 'user-1' === ( $ok['recipient'] ?? null ));
        $metadata = $ok['metadata'] ?? array();
        $assert('sync success metadata mode=sync', 'sync' === ( $metadata['mode'] ?? null ));
        $assert('sync success exit_code 0', 0 === ( $metadata['exit_code'] ?? null ));
        $assert(
            'sync success captures substituted stdout',
            isset($metadata['stdout']) && trim((string) $metadata['stdout']) === 'user-1:hi-there'
        );
    }

    $ok_true = \DataMachineCode\Channels\CliChannelTransport::execute(
        array(
        'channel'   => 'sync-true',
        'recipient' => 'noop',
        'message'   => '',
        ) 
    );
    $assert('sync /bin/true succeeds', is_array($ok_true) && true === ( $ok_true['sent'] ?? false ));

    // ---------------------------------------------------------------
    // Sync dispatch: failure paths
    // ---------------------------------------------------------------

    $fail = \DataMachineCode\Channels\CliChannelTransport::execute(
        array(
        'channel'   => 'sync-false',
        'recipient' => 'x',
        'message'   => 'x',
        ) 
    );
    $assert('nonzero exit returns WP_Error', $fail instanceof \WP_Error);
    if ($fail instanceof \WP_Error ) {
        $assert(
            'nonzero exit error code is machine-readable',
            'datamachine_code_cli_dispatch_nonzero_exit' === $fail->get_error_code()
        );
        $data = $fail->get_error_data();
        $assert('nonzero exit error data carries exit_code', is_array($data) && 1 === ( $data['exit_code'] ?? null ));
    }

    $timed = \DataMachineCode\Channels\CliChannelTransport::execute(
        array(
        'channel'   => 'sync-sleep',
        'recipient' => 'x',
        'message'   => 'x',
        ) 
    );
    $assert('timeout returns WP_Error', $timed instanceof \WP_Error);
    if ($timed instanceof \WP_Error ) {
        $assert(
            'timeout error code is machine-readable',
            'datamachine_code_cli_dispatch_timeout' === $timed->get_error_code()
        );
    }

    $unknown = \DataMachineCode\Channels\CliChannelTransport::execute(
        array(
        'channel'   => 'nope',
        'recipient' => 'x',
        'message'   => 'x',
        ) 
    );
    $assert('unknown channel from execute returns WP_Error', $unknown instanceof \WP_Error);

    // ---------------------------------------------------------------
    // Detached dispatch
    // ---------------------------------------------------------------

    $detached = \DataMachineCode\Channels\CliChannelTransport::execute(
        array(
        'channel'   => 'detached-true',
        'recipient' => 'r',
        'message'   => 'fire-and-forget',
        ) 
    );
    $assert('detached returns array', is_array($detached) && ! ( $detached instanceof \WP_Error ));
    if (is_array($detached) ) {
        $assert('detached sent=true', true === ( $detached['sent'] ?? null ));
        $assert(
            'detached message_id is numeric PID string',
            is_string($detached['message_id'] ?? null) && ctype_digit((string) $detached['message_id'])
        );
        $metadata = $detached['metadata'] ?? array();
        $assert('detached metadata mode=detached', 'detached' === ( $metadata['mode'] ?? null ));
    }

    // ---------------------------------------------------------------
    // WP AI Gateway env contract: gateway mode maps to OpenAI-compatible
    // child env, strips upstream secrets, and redacts captured output.
    // ---------------------------------------------------------------

    putenv('WP_AI_GATEWAY_BASE_URL=https://gateway.example/v1');
    putenv('WP_AI_GATEWAY_TOKEN=gateway-token-1234567890');
    $datamachine_code_test_options['datamachine_code_cli_channels'] = array(
    'gateway-env'     => array(
    'command' => $php_bin,
    'args'    => array(
    '-r',
    '$ok = getenv("OPENAI_BASE_URL") === "https://gateway.example/v1" && getenv("OPENAI_API_KEY") === "gateway-token-1234567890" && false === getenv("CODEX_REFRESH_TOKEN") && false === getenv("CODEX_ACCESS_TOKEN") && false === getenv("OPENCODE_GO_KEY") && false === getenv("WP_AUTH_COOKIE") && false === getenv("WP_NONCE") && false === getenv("WP_AI_GATEWAY_TOKEN"); echo "OPENAI_BASE_URL=" . getenv("OPENAI_BASE_URL") . "\n"; echo "OPENAI_API_KEY=" . getenv("OPENAI_API_KEY") . "\n"; exit($ok ? 0 : 7);',
    ),
    'detach'  => false,
    'timeout' => 5,
    'env'     => array(
    'OPENAI_BASE_URL'     => 'https://raw-openai.example/v1',
    'OPENAI_API_KEY'      => 'raw-openai-key',
    'CODEX_REFRESH_TOKEN' => 'codex-refresh-token',
    'CODEX_ACCESS_TOKEN'  => 'codex-access-token',
    'OPENCODE_GO_KEY'     => 'opencode-go-key',
    'WP_AUTH_COOKIE'      => 'wordpress-cookie',
    'WP_NONCE'            => 'wordpress-nonce',
    'WP_AI_GATEWAY_TOKEN' => 'configured-gateway-token',
    ),
    ),
    'nongateway-env'  => array(
    'command' => $php_bin,
    'args'    => array(
    '-r',
    'exit(getenv("OPENAI_API_KEY") === "raw-openai-key" ? 0 : 9);',
    ),
    'detach'  => false,
    'timeout' => 5,
    'env'     => array(
    'OPENAI_API_KEY' => 'raw-openai-key',
    ),
    ),
    );

    $gateway = \DataMachineCode\Channels\CliChannelTransport::execute(
        array(
        'channel'   => 'gateway-env',
        'recipient' => 'r',
        'message'   => 'm',
        )
    );
    $assert('gateway env dispatch succeeds', is_array($gateway) && true === ( $gateway['sent'] ?? false ));
    if (is_array($gateway) ) {
        $stdout = (string) ( $gateway['metadata']['stdout'] ?? '' );
        $assert('gateway base URL is passed as OPENAI_BASE_URL', str_contains($stdout, 'OPENAI_BASE_URL=https://gateway.example/v1'));
        $assert('gateway token is redacted from captured stdout', str_contains($stdout, 'OPENAI_API_KEY: [redacted]') && ! str_contains($stdout, 'gateway-token-1234567890'));
    }

    putenv('WP_AI_GATEWAY_BASE_URL');
    putenv('WP_AI_GATEWAY_TOKEN');
    $nongateway = \DataMachineCode\Channels\CliChannelTransport::execute(
        array(
        'channel'   => 'nongateway-env',
        'recipient' => 'r',
        'message'   => 'm',
        )
    );
    $assert('non-gateway configured env continues to work', is_array($nongateway) && true === ( $nongateway['sent'] ?? false ));

    // ---------------------------------------------------------------
    // Summary
    // ---------------------------------------------------------------

    if (! empty($failures) ) {
        echo "\nFAIL: " . count($failures) . " assertion(s)\n";
        foreach ( $failures as $failure ) {
            echo "  - {$failure}\n";
        }
        exit(1);
    }

    echo "\nOK\n";
    exit(0);
}
