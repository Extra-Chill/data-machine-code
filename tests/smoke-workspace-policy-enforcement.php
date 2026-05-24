<?php
/**
 * Smoke test for workspace git policy enforcement and attestation.
 *
 * Run: php tests/smoke-workspace-policy-enforcement.php
 */

declare( strict_types=1 );

if (! defined('ABSPATH') ) {
    define('ABSPATH', sys_get_temp_dir() . '/dmc-workspace-policy-enforcement-abspath/');
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
    function is_wp_error( mixed $value ): bool
    {
        return $value instanceof WP_Error;
    }
}

if (! function_exists('get_option') ) {
    function get_option( string $name, mixed $default = false ): mixed
    {
        return $GLOBALS['dmc_workspace_policy_options'][ $name ] ?? $default;
    }
}

if (! function_exists('apply_filters') ) {
    function apply_filters( string $tag, mixed $value ): mixed
    {
        return $value;
    }
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../inc/Workspace/Workspace.php';

use DataMachineCode\Workspace\Workspace;

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

$run = static function ( string $command, string $cwd ): string {
    $output = array();
    $code   = 0;
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
    exec(sprintf('cd %s && %s 2>&1', escapeshellarg($cwd), $command), $output, $code);
    if (0 !== $code ) {
        throw new RuntimeException(sprintf("Command failed (%d): %s\n%s", $code, $command, implode("\n", $output)));
    }
    return implode("\n", $output);
};

$write = static function ( string $path, string $content ): void {
    $dir = dirname($path);
    if (! is_dir($dir) ) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, $content);
};

echo "Workspace policy enforcement - smoke\n";

$tmp = sys_get_temp_dir() . '/dmc-workspace-policy-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
if (! defined('DATAMACHINE_WORKSPACE_PATH') ) {
    define('DATAMACHINE_WORKSPACE_PATH', $tmp);
}

$repo = $tmp . '/demo';
mkdir($repo, 0777, true);
$run('git init -q', $repo);
$run('git config user.email tester@example.com', $repo);
$run('git config user.name Tester', $repo);

$write($repo . '/README.md', "base\n");
$write($repo . '/work/allowed.txt', "allowed\n");
$write($repo . '/work/hidden/secret.txt', "secret\n");
$write($repo . '/.gitignore', "work/ignored.txt\n");
$run('git add README.md work/allowed.txt work/hidden/secret.txt .gitignore', $repo);
$run('git commit -q -m initial', $repo);
$run('git branch -M main', $repo);
$remote = $tmp . '/remote.git';
$run('git init -q --bare ' . escapeshellarg($remote), $tmp);
$run('git remote add origin ' . escapeshellarg($remote), $repo);
$run('git push -q -u origin main', $repo);

$GLOBALS['dmc_workspace_policy_options'] = array(
    'datamachine_workspace_git_policies' => array(
        'repos' => array(
            'demo' => array(
                'writable_roots' => array( 'work' ),
                'hidden_paths'   => array( 'work/hidden' ),
            ),
        ),
    ),
);

$workspace = new Workspace();

$write($repo . '/work/allowed.txt', "allowed update\n");
$status = $workspace->git_status('demo');
$assert('git_status emits policy attestation', ! is_wp_error($status) && isset($status['workspace_policy']['policy_hash']));
$assert('attestation includes changed file', ! is_wp_error($status) && in_array('work/allowed.txt', $status['workspace_policy']['changed_files'] ?? array(), true));
$assert('allowed change has no violation', ! is_wp_error($status) && array() === ( $status['workspace_policy']['violations'] ?? array() ));

$add = $workspace->git_add('demo', array( 'work/allowed.txt' ), true);
$assert('git_add allows writable root change', ! is_wp_error($add) && isset($add['workspace_policy']['policy_hash']));
$run('git reset -q -- work/allowed.txt && git checkout -q -- work/allowed.txt', $repo);

$write($repo . '/README.md', "outside\n");
$outside = $workspace->git_add('demo', array( 'README.md' ), true);
$assert('git_add rejects outside writable_roots', is_wp_error($outside) && 'path_not_allowed' === $outside->get_error_code());
$run('git checkout -q -- README.md', $repo);

$write($repo . '/work/hidden/secret.txt', "changed secret\n");
$hidden = $workspace->git_add('demo', array( 'work/hidden/secret.txt' ), true);
$assert('git_add rejects hidden path changes', is_wp_error($hidden) && 'workspace_policy_violation' === $hidden->get_error_code());
$assert('hidden path violation is machine-readable', is_wp_error($hidden) && 'hidden_path' === ( $hidden->get_error_data()['workspace_violations'][0]['reason'] ?? '' ));
$run('git checkout -q -- work/hidden/secret.txt', $repo);

$write($repo . '/work/hidden/secret.txt', "committed secret\n");
$run('git add work/hidden/secret.txt && git commit -q -m hidden-commit', $repo);
$push_hidden = $workspace->git_push('demo', 'origin', 'main', true);
$push_hidden_reasons = is_wp_error($push_hidden) ? array_column($push_hidden->get_error_data()['workspace_violations'] ?? array(), 'reason') : array();
$assert('git_push rejects already-committed hidden path changes', in_array('hidden_path', $push_hidden_reasons, true));
$run('git reset -q --hard origin/main', $repo);

$write($repo . '/work/ignored.txt', "ignored\n");
$ignored_status = $workspace->git_status('demo');
$ignored_reasons = array_column($ignored_status['workspace_policy']['violations'] ?? array(), 'reason');
$assert('git_status reports ignored file violation', in_array('ignored', $ignored_reasons, true));
unlink($repo . '/work/ignored.txt');

symlink('../work/hidden/secret.txt', $repo . '/work/secret-link');
$symlink = $workspace->git_add('demo', array( 'work/secret-link' ), true);
$symlink_reasons = is_wp_error($symlink) ? array_column($symlink->get_error_data()['workspace_violations'] ?? array(), 'reason') : array();
$assert('git_add rejects symlink escape to hidden path', in_array('symlink', $symlink_reasons, true) && in_array('hidden_path_exposure', $symlink_reasons, true));
unlink($repo . '/work/secret-link');

$outside_target = $tmp . '/outside.txt';
$write($outside_target, "outside\n");
symlink($outside_target, $repo . '/work/outside-link');
$outside_symlink = $workspace->git_add('demo', array( 'work/outside-link' ), true);
$outside_symlink_reasons = is_wp_error($outside_symlink) ? array_column($outside_symlink->get_error_data()['workspace_violations'] ?? array(), 'reason') : array();
$assert('git_add rejects outside realpath symlink', in_array('outside_realpath', $outside_symlink_reasons, true));
unlink($repo . '/work/outside-link');

$write($repo . '/work/hardlink-source.txt', "linked\n");
link($repo . '/work/hardlink-source.txt', $repo . '/work/hardlink-copy.txt');
$hardlink = $workspace->git_add('demo', array( 'work/hardlink-copy.txt' ), true);
$hardlink_reasons = is_wp_error($hardlink) ? array_column($hardlink->get_error_data()['workspace_violations'] ?? array(), 'reason') : array();
$assert('git_add rejects hardlink changes', in_array('hardlink', $hardlink_reasons, true));
unlink($repo . '/work/hardlink-source.txt');
unlink($repo . '/work/hardlink-copy.txt');

if (function_exists('posix_mkfifo') ) {
    posix_mkfifo($repo . '/work/fifo', 0600);
    $fifo = $workspace->git_add('demo', array( 'work/fifo' ), true);
    $fifo_reasons = is_wp_error($fifo) ? array_column($fifo->get_error_data()['workspace_violations'] ?? array(), 'reason') : array();
    $assert('git_add rejects non-regular files when supported', in_array('non_regular', $fifo_reasons, true));
    unlink($repo . '/work/fifo');
}

$nested = $repo . '/work/nested';
mkdir($nested, 0777, true);
$run('git init -q', $nested);
$write($nested . '/nested.txt', "nested\n");
$run('git add nested.txt && git -c user.email=tester@example.com -c user.name=Tester commit -q -m nested', $nested);
$nested_git = $workspace->git_add('demo', array( 'work/nested/.git/config' ), true);
$nested_git_reasons = is_wp_error($nested_git) ? array_column($nested_git->get_error_data()['workspace_violations'] ?? array(), 'reason') : array();
$assert('git_add rejects nested .git paths', in_array('nested_git', $nested_git_reasons, true));
$gitlink = $workspace->git_add('demo', array( 'work/nested' ), true);
$gitlink_reasons = is_wp_error($gitlink) ? array_column($gitlink->get_error_data()['workspace_violations'] ?? array(), 'reason') : array();
$assert('git_add rejects gitlink changes', in_array('gitlink', $gitlink_reasons, true));

if (! empty($failures) ) {
    echo "\nFAIL: " . count($failures) . " assertion(s) failed out of {$total}\n";
    foreach ( $failures as $failure ) {
        echo "  - {$failure}\n";
    }
    exit(1);
}

echo "\nOK ({$total} assertions)\n";
exit(0);
