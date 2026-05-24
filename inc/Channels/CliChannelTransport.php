<?php
/**
 * Generic CLI transport runtime for `agents/dispatch-message`.
 *
 * This runtime claims the `wp_agent_dispatch_message_handler` filter when
 * the host can spawn subprocesses (`Environment::has_shell()`) and the
 * dispatched `channel` matches a registered config entry in
 * {@see CliChannelRegistry}. It then executes the configured command via
 * `proc_open`, substituting the canonical tokens (`{recipient}`,
 * `{message}`, `{conversation_id}`, `{channel}`) into the args array.
 *
 * The runtime has zero per-transport knowledge. Adding a new outbound
 * channel is a configuration entry, not a code change.
 *
 * Security:
 *  - The command and args template come from registered configuration
 *    only. They are never user-controlled at dispatch time.
 *  - Args are passed to `proc_open` as an array. There is no shell
 *    interpolation, so message bodies containing shell metacharacters are
 *    delivered to the child process untouched as a single argv entry.
 *  - Environment is passed explicitly; the child does not inherit the
 *    web user's full environment beyond the configured `env` map and the
 *    parent PATH (required to resolve PATH-relative commands).
 *
 * @package DataMachineCode\Channels
 * @since   0.43.0
 */

namespace DataMachineCode\Channels;

use DataMachineCode\Environment;
use WP_Error;

defined('ABSPATH') || exit;

class CliChannelTransport
{

    /**
     * Default synchronous timeout, in seconds, when a config entry omits
     * `timeout`. Matched by {@see CliChannelRegistry::normalize_entry()}.
     *
     * @var int
     */
    private const DEFAULT_TIMEOUT_SECONDS = 30;

    /**
     * Register the transport against the agents-api dispatch filter.
     *
     * The handler is attached to `wp_agent_dispatch_message_handler` at
     * priority 20, which leaves room for higher-precedence runtimes to win
     * the filter first.
     *
     * Registration is idempotent.
     *
     * @since 0.43.0
     */
    public static function register(): void
    {
        static $registered = false;
        if ($registered ) {
            return;
        }
        $registered = true;

        if (! function_exists('add_filter') ) {
            return;
        }

        add_filter(
            'wp_agent_dispatch_message_handler',
            array( self::class, 'maybe_claim' ),
            20,
            2
        );
    }

    /**
     * Filter callback. Decide whether this runtime claims the dispatch.
     *
     * Decline (i.e. return `$existing`) when:
     *  - a prior handler already claimed the filter,
     *  - the host cannot run subprocesses,
     *  - the requested channel is not registered.
     *
     * Claim by returning `[ self::class, 'execute' ]` — the substrate will
     * then invoke `execute()` with the canonical input.
     *
     * @since 0.43.0
     *
     * @param  callable|null        $existing Currently registered handler.
     * @param  array<string, mixed> $input    Canonical dispatch-message input.
     * @return callable|null
     */
    public static function maybe_claim( $existing, $input )
    {
        if (null !== $existing && is_callable($existing) ) {
            return $existing;
        }

        if (! is_array($input) ) {
            return $existing;
        }

        if (! Environment::has_shell() ) {
            self::log_debug('cli_transport_declined', array( 'reason' => 'no_shell' ));
            return $existing;
        }

        $channel = isset($input['channel']) && is_string($input['channel']) ? $input['channel'] : '';
        if ('' === $channel ) {
            return $existing;
        }

        $config = CliChannelRegistry::lookup($channel);
        if (null === $config ) {
            self::log_debug(
                'cli_transport_declined',
                array(
                'reason'  => 'unknown_channel',
                'channel' => $channel,
                )
            );
            return $existing;
        }

        return array( self::class, 'execute' );
    }

    /**
     * Execute a registered CLI dispatch.
     *
     * Returns the canonical output shape on success, or `WP_Error` on
     * failure. The substrate fires `agents_dispatch_message_failed` for
     * the latter.
     *
     * @since 0.43.0
     *
     * @param  array<string, mixed> $input Canonical dispatch-message input.
     * @return array<string, mixed>|WP_Error
     */
    public static function execute( array $input )
    {
        $channel = isset($input['channel']) && is_string($input['channel']) ? $input['channel'] : '';
        if ('' === $channel ) {
            return new WP_Error(
                'datamachine_code_cli_dispatch_invalid_input',
                'agents/dispatch-message input is missing a channel identifier.'
            );
        }

        $config = CliChannelRegistry::lookup($channel);
        if (null === $config ) {
            return new WP_Error(
                'datamachine_code_cli_dispatch_unknown_channel',
                sprintf('No CLI channel registered for "%s".', $channel)
            );
        }

        $recipient = isset($input['recipient']) && is_scalar($input['recipient']) ? (string) $input['recipient'] : '';

        $command_args = CliChannelRegistry::substitute_tokens($config['args'], $input);
        array_unshift($command_args, $config['command']);

        $detach  = (bool) ( $config['detach'] ?? true );
        $timeout = isset($config['timeout']) && is_int($config['timeout']) ? $config['timeout'] : self::DEFAULT_TIMEOUT_SECONDS;
        $cwd     = isset($config['cwd']) && is_string($config['cwd']) && '' !== $config['cwd'] ? $config['cwd'] : null;
        $env     = self::build_env_map(isset($config['env']) && is_array($config['env']) ? $config['env'] : array());

        if ($detach ) {
            return self::dispatch_detached($channel, $recipient, $command_args, $cwd, $env);
        }

        return self::dispatch_sync($channel, $recipient, $command_args, $cwd, $env, $timeout);
    }

    /**
     * Fire-and-forget dispatch.
     *
     * @param  string                     $channel   Channel id.
     * @param  string                     $recipient Recipient id.
     * @param  array<int, string>         $argv      Command + args.
     * @param  string|null                $cwd       Working directory.
     * @param  array<string, string>|null $env       Environment map.
     * @return array<string, mixed>|WP_Error
     */
    private static function dispatch_detached( string $channel, string $recipient, array $argv, ?string $cwd, ?array $env )
    {
        $descriptors = array(
        0 => array( 'file', '/dev/null', 'r' ),
        1 => array( 'file', '/dev/null', 'w' ),
        2 => array( 'file', '/dev/null', 'w' ),
        );

        $started_at = microtime(true);

        $process = self::open_process($argv, $descriptors, $cwd, $env, true);
        if ($process instanceof WP_Error ) {
            return $process;
        }

        $pid    = null;
        $status = proc_get_status($process);
        if (is_array($status) && isset($status['pid']) ) {
            $pid = (int) $status['pid'];
        }

        // Release the handle without waiting. The child keeps running in
        // its own session because proc_open was given start_new_session.
        proc_close($process);

        $duration_ms = (int) round(( microtime(true) - $started_at ) * 1000);

        return array(
        'sent'       => true,
        'channel'    => $channel,
        'recipient'  => $recipient,
        'message_id' => null !== $pid ? (string) $pid : null,
        'metadata'   => array(
        'mode'        => 'detached',
        'pid'         => $pid,
        'duration_ms' => $duration_ms,
        ),
        );
    }

    /**
     * Synchronous dispatch with stdout/stderr capture and timeout.
     *
     * @param  string                     $channel   Channel id.
     * @param  string                     $recipient Recipient id.
     * @param  array<int, string>         $argv      Command + args.
     * @param  string|null                $cwd       Working directory.
     * @param  array<string, string>|null $env       Environment map.
     * @param  int                        $timeout   Timeout in seconds.
     * @return array<string, mixed>|WP_Error
     */
    private static function dispatch_sync( string $channel, string $recipient, array $argv, ?string $cwd, ?array $env, int $timeout )
    {
        $descriptors = array(
        0 => array( 'pipe', 'r' ),
        1 => array( 'pipe', 'w' ),
        2 => array( 'pipe', 'w' ),
        );

        $pipes      = array();
        $started_at = microtime(true);

        $process = self::open_process($argv, $descriptors, $cwd, $env, false, $pipes);
        if ($process instanceof WP_Error ) {
            return $process;
        }

        // Close stdin so the child doesn't block on read.
        if (isset($pipes[0]) && is_resource($pipes[0]) ) {
            fclose($pipes[0]);
        }

        // Non-blocking reads on stdout/stderr so we can enforce the timeout.
        if (isset($pipes[1]) && is_resource($pipes[1]) ) {
            stream_set_blocking($pipes[1], false);
        }
        if (isset($pipes[2]) && is_resource($pipes[2]) ) {
            stream_set_blocking($pipes[2], false);
        }

        $stdout    = '';
        $stderr    = '';
        $timed_out = false;
        $deadline  = $started_at + max(1, $timeout);

        while ( true ) {
            $status = proc_get_status($process);

            if (isset($pipes[1]) && is_resource($pipes[1]) ) {
                $chunk = stream_get_contents($pipes[1]);
                if (is_string($chunk) && '' !== $chunk ) {
                    $stdout .= $chunk;
                }
            }
            if (isset($pipes[2]) && is_resource($pipes[2]) ) {
                $chunk = stream_get_contents($pipes[2]);
                if (is_string($chunk) && '' !== $chunk ) {
                    $stderr .= $chunk;
                }
            }

            if (! is_array($status) || false === $status['running'] ) {
                break;
            }

            if (microtime(true) >= $deadline ) {
                $timed_out = true;
                proc_terminate($process, 15); // SIGTERM
                // Give the child a brief grace window to flush.
                usleep(100000);
                $status = proc_get_status($process);
                if (is_array($status) && true === $status['running'] ) {
                    proc_terminate($process, 9); // SIGKILL
                }
                break;
            }

            usleep(20000);
        }

        // Drain any remaining output.
        foreach ( array( 1, 2 ) as $fd ) {
            if (! isset($pipes[ $fd ]) || ! is_resource($pipes[ $fd ]) ) {
                continue;
            }
            $chunk = stream_get_contents($pipes[ $fd ]);
            if (is_string($chunk) && '' !== $chunk ) {
                if (1 === $fd ) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
            fclose($pipes[ $fd ]);
        }

        $exit_code   = proc_close($process);
        $duration_ms = (int) round(( microtime(true) - $started_at ) * 1000);

        if ($timed_out ) {
            return new WP_Error(
                'datamachine_code_cli_dispatch_timeout',
                sprintf('CLI channel "%s" exceeded the %d second timeout.', $channel, $timeout),
                array(
                'channel'     => $channel,
                'recipient'   => $recipient,
                'stdout'      => self::truncate_output($stdout),
                'stderr'      => self::truncate_output($stderr),
                'duration_ms' => $duration_ms,
                )
            );
        }

        if (0 !== $exit_code ) {
            return new WP_Error(
                'datamachine_code_cli_dispatch_nonzero_exit',
                sprintf('CLI channel "%s" exited with code %d.', $channel, $exit_code),
                array(
                'channel'     => $channel,
                'recipient'   => $recipient,
                'exit_code'   => $exit_code,
                'stdout'      => self::truncate_output($stdout),
                'stderr'      => self::truncate_output($stderr),
                'duration_ms' => $duration_ms,
                )
            );
        }

        return array(
        'sent'       => true,
        'channel'    => $channel,
        'recipient'  => $recipient,
        'message_id' => null,
        'metadata'   => array(
        'mode'        => 'sync',
        'exit_code'   => $exit_code,
        'duration_ms' => $duration_ms,
        'stdout'      => self::truncate_output($stdout),
        'stderr'      => self::truncate_output($stderr),
        ),
        );
    }

    /**
     * Open a child process. Wraps `proc_open` to handle the array-argv vs
     * string-command preference and detached-session option, and to
     * surface a typed failure.
     *
     * @param  array<int, string>         $argv        Command argv (index 0 is the program).
     * @param  array<int, mixed>          $descriptors Descriptor spec for proc_open.
     * @param  string|null                $cwd         Working directory.
     * @param  array<string, string>|null $env         Environment map (null inherits parent).
     * @param  bool                       $detached    Whether to start a new session.
     * @param  array<int, resource>       $pipes       Output pipes (by reference).
     * @return resource|WP_Error
     */
    private static function open_process( array $argv, array $descriptors, ?string $cwd, ?array $env, bool $detached, array &$pipes = array() )
    {
        if (! function_exists('proc_open') ) {
            return new WP_Error(
                'datamachine_code_cli_dispatch_no_proc_open',
                'proc_open is not available on this host.'
            );
        }

        $options = array();
        if ($detached ) {
            // `start_new_session` detaches the child into its own process
            // group so it survives PHP request teardown.
            $options['start_new_session'] = true;
        }

        // Hand argv to proc_open as an array so PHP bypasses the shell.
        $process = @proc_open($argv, $descriptors, $pipes, $cwd, $env, $options);

        if (! is_resource($process) ) {
            return new WP_Error(
                'datamachine_code_cli_dispatch_spawn_failed',
                sprintf('Failed to spawn CLI process "%s".', $argv[0] ?? '')
            );
        }

        return $process;
    }

    /**
     * Build the environment map passed to the child process.
     *
     * The parent's PATH is forwarded so PATH-relative commands resolve,
     * but no other inherited variables leak. Configured `env` overrides
     * the inherited PATH if it provides one.
     *
     * @param  array<string, string> $configured Configured env map.
     * @return array<string, string>
     */
    private static function build_env_map( array $configured ): array
    {
        $env = array();

        $parent_path = getenv('PATH');
        if (is_string($parent_path) && '' !== $parent_path ) {
            $env['PATH'] = $parent_path;
        }

        foreach ( $configured as $key => $value ) {
            $env[ $key ] = $value;
        }

        return $env;
    }

    /**
     * Cap captured output so a runaway child cannot blow up the response.
     *
     * @param  string $output Captured output.
     * @return string Truncated output.
     */
    private static function truncate_output( string $output ): string
    {
        $limit = 8192;
        if (strlen($output) <= $limit ) {
            return $output;
        }
        return substr($output, 0, $limit) . "\n[...truncated]";
    }

    /**
     * Emit a debug-level log entry, if the host has logging hooks wired.
     *
     * @param string               $event   Event slug.
     * @param array<string, mixed> $context Structured context.
     */
    private static function log_debug( string $event, array $context ): void
    {
        if (function_exists('do_action') ) {
            do_action('datamachine_code_cli_transport_debug', $event, $context);
        }
    }
}
