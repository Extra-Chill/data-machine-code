<?php
/**
 * Pure-PHP smoke test for Environment shell capability probes.
 *
 * Run: php tests/smoke-environment-shell.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

if (! defined('ABSPATH') ) {
    define('ABSPATH', __DIR__ . '/');
}

require __DIR__ . '/../inc/Environment.php';

use DataMachineCode\Environment;

$failures = array();
$assert   = function ( string $label, bool $cond ) use ( &$failures ): void {
    if ($cond ) {
        echo "  [PASS] {$label}\n";
        return;
    }

    $failures[] = $label;
    echo "  [FAIL] {$label}\n";
};

$evaluate = new ReflectionMethod(Environment::class, 'evaluate_shell_capability');

$run_evaluation = static function ( callable $function_exists, string $disabled_functions, callable $runner ) use ( $evaluate ): array {
    return $evaluate->invoke(null, $function_exists, $disabled_functions, $runner);
};

echo "Environment shell capability -- smoke\n";

$available_function = static function ( string $function_name ): bool {
    return in_array($function_name, array( 'exec', 'shell_exec' ), true);
};

$success_runner = static function ( string $command ): array {
    unset($command);

    return array(
    'output'    => array( '__datamachine_code_shell_ok__' ),
    'exit_code' => 0,
    );
};

$success = $run_evaluation($available_function, '', $success_runner);
$assert('probe success reports ok', true === $success['ok']);
$assert('probe success reason is ok', 'ok' === $success['reason']);
$assert('probe success records exit code', 0 === $success['exit_code']);

$missing = $run_evaluation(
    static function ( string $function_name ): bool {
        return 'exec' !== $function_name;
    },
    '',
    $success_runner
);
$assert('missing exec reports unavailable', false === $missing['ok']);
$assert('missing exec reason is machine-readable', 'exec_missing' === $missing['reason']);

$disabled = $run_evaluation($available_function, 'shell_exec, passthru', $success_runner);
$assert('disabled shell_exec reports unavailable', false === $disabled['ok']);
$assert('disabled shell_exec reason is machine-readable', 'shell_exec_disabled' === $disabled['reason']);

$failed = $run_evaluation(
    $available_function,
    '',
    static function ( string $command ): array {
        unset($command);

        return array(
        'output'    => array( '' ),
        'exit_code' => 1,
        );
    }
);
$assert('failed command reports unavailable', false === $failed['ok']);
$assert('failed command reason is machine-readable', 'probe_failed' === $failed['reason']);
$assert('failed command records exit code', 1 === $failed['exit_code']);

$bad_output = $run_evaluation(
    $available_function,
    '',
    static function ( string $command ): array {
        unset($command);

        return array(
        'output'    => array( 'unexpected' ),
        'exit_code' => 0,
        );
    }
);
$assert('wrong output reports unavailable', false === $bad_output['ok']);
$assert('wrong output reports probe failure', 'probe_failed' === $bad_output['reason']);

if (Environment::has_shell() ) {
    $live = Environment::shell_diagnostic();
    $assert('live diagnostic agrees with has_shell true', true === $live['ok']);
} else {
    $live = Environment::shell_diagnostic();
    $assert('live diagnostic agrees with has_shell false', false === $live['ok']);
}

if (! empty($failures) ) {
    echo "\nFAIL: " . count($failures) . " assertion(s)\n";
    foreach ( $failures as $failure ) {
        echo "  - {$failure}\n";
    }
    exit(1);
}

echo "\nOK\n";
exit(0);
