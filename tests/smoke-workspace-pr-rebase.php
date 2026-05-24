<?php
/**
 * Smoke test for the composite workspace_pr_rebase ability path.
 *
 * Run: php tests/smoke-workspace-pr-rebase.php
 */

declare( strict_types=1 );

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

namespace DataMachineCode\Abilities {
    class GitHubAbilities
    {
        public static array $requests = array();

        /**
         * @param array<string,mixed> $context 
         */
        public static function getPat( array $context = array() ): string
        {
            return 'fake-token'; 
        }

        /**
         * @param array<string,string> $query 
         */
        public static function apiGet( string $url, array $query = array(), string $pat = '', int $timeout = 0 ): array|\WP_Error
        {
            self::$requests[] = compact('url', 'query', 'pat', 'timeout');
            return array(
            'success' => true,
            'data'    => array(
            'number'          => 410,
            'html_url'        => 'https://github.com/Extra-Chill/demo/pull/410',
            'title'           => 'feat: update daily note',
            'body'            => 'Mock PR body.',
            'mergeable'       => false,
            'mergeable_state' => 'dirty',
            'base'            => array( 'ref' => 'main', 'sha' => 'base-sha' ),
            'head'            => array( 'ref' => 'feature/pr-rebase', 'sha' => 'head-sha' ),
            ),
            );
        }
    }
}

namespace {
    $root = sys_get_temp_dir() . '/dmc-pr-rebase-' . getmypid();
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__ . '/');
    }
    if (! defined('DATAMACHINE_WORKSPACE_PATH') ) {
        define('DATAMACHINE_WORKSPACE_PATH', $root . '/workspace');
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

    function is_wp_error( $thing ): bool
    {
        return $thing instanceof WP_Error; 
    }
    function get_option( string $name, $default_value = null )
    {
        return $default_value; 
    }
    function apply_filters( string $name, $value )
    {
        return $value; 
    }

    include __DIR__ . '/../inc/Support/GitHubRemote.php';
    include __DIR__ . '/../inc/Support/GitRunner.php';
    include __DIR__ . '/../inc/Support/PathSecurity.php';
    include __DIR__ . '/../inc/Workspace/Workspace.php';

    use DataMachineCode\Workspace\Workspace;

    $failures = array();
    $assert = function ( string $label, bool $condition ) use ( &$failures ): void {
        if ($condition ) {
            echo "  ok {$label}\n";
            return;
        }
        $failures[] = $label;
        echo "  fail {$label}\n";
    };
    $run = function ( string $command ) use ( &$failures ): void {
        $output = array();
        $code   = 0;
        exec($command . ' 2>&1', $output, $code);
        if (0 !== $code ) {
            $failures[] = 'command failed: ' . $command . ' :: ' . implode("\n", $output);
        }
    };

    echo "Workspace PR rebase - smoke\n";

    @mkdir(DATAMACHINE_WORKSPACE_PATH . '/demo', 0777, true);
    @mkdir($root . '/remote.git', 0777, true);
    $repo = DATAMACHINE_WORKSPACE_PATH . '/demo';
    $bare = $root . '/remote.git';

    $run('git init --bare ' . escapeshellarg($bare));
    $run('git -C ' . escapeshellarg($repo) . ' init -b main');
    $run('git -C ' . escapeshellarg($repo) . ' config user.email test@example.com');
    $run('git -C ' . escapeshellarg($repo) . ' config user.name Test');
    $run('git -C ' . escapeshellarg($repo) . ' remote add origin ' . escapeshellarg($bare));
    @mkdir($repo . '/memory/daily', 0777, true);
    file_put_contents($repo . '/memory/daily/note.md', "initial\n");
    $run('git -C ' . escapeshellarg($repo) . ' add memory/daily/note.md');
    $run('git -C ' . escapeshellarg($repo) . ' commit -m initial');
    $run('git -C ' . escapeshellarg($repo) . ' push origin main');
    $run('git -C ' . escapeshellarg($repo) . ' checkout -b feature/pr-rebase');
    file_put_contents($repo . '/memory/daily/note.md', "feature\n");
    $run('git -C ' . escapeshellarg($repo) . ' commit -am feature-note');
    $run('git -C ' . escapeshellarg($repo) . ' push origin feature/pr-rebase');
    $run('git -C ' . escapeshellarg($repo) . ' checkout main');
    file_put_contents($repo . '/memory/daily/note.md', "base\n");
    $run('git -C ' . escapeshellarg($repo) . ' commit -am base-note');
    $run('git -C ' . escapeshellarg($repo) . ' push origin main');
    $run('git -C ' . escapeshellarg($repo) . ' checkout feature/pr-rebase');

    $result = ( new Workspace() )->pr_rebase(
        'demo',
        'https://github.com/Extra-Chill/demo/pull/410',
        false,
        array( 'memory/daily/**' ),
        true
    );

    $assert('workspace_pr_rebase succeeds after dropping configured conflict path', is_array($result) && true === ( $result['success'] ?? false ));
    $assert('workspace_pr_rebase reports clean state', is_array($result) && 'clean' === ( $result['state'] ?? '' ));
    $assert('workspace_pr_rebase counts dropped path', is_array($result) && 1 === ( $result['dropped_paths'] ?? null ));
    $assert('workspace_pr_rebase force-with-lease pushed', is_array($result) && true === ( $result['pushed'] ?? false ));
    $assert('drop path kept base content', "base\n" === file_get_contents($repo . '/memory/daily/note.md'));

    $log = array();
    exec('git -C ' . escapeshellarg($bare) . ' log --oneline feature/pr-rebase 2>&1', $log);
    $assert('remote branch was updated', ! empty($log) && str_contains(implode("\n", $log), 'base-note'));

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
