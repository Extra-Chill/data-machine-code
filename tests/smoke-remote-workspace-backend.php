<?php
/**
 * Pure-PHP smoke for the GitHub-backed remote workspace backend.
 *
 * Run: php tests/smoke-remote-workspace-backend.php
 */

declare( strict_types=1 );

namespace DataMachineCode\Abilities {
    class GitHubAbilities
    {
        public static array $files = array(
        'chubes4/example:main:src/example.php' => array(
        'content' => "<?php\nreturn 'old';\n",
        'sha'     => 'file-sha-main-example',
        ),
        );
        public static array $commits = array();

        public static function getFileContents( array $input ): array|\WP_Error
        {
            $repo = (string) ( $input['repo'] ?? '' );
            $path = (string) ( $input['path'] ?? '' );
            $ref  = (string) ( $input['ref'] ?? 'main' );
            $key  = $repo . ':' . $ref . ':' . $path;
            if (! isset(self::$files[ $key ]) ) {
                return array(
                'success' => false,
                'files'   => array(),
                'errors'  => array(
                array(
                'path'    => $path,
                'code'    => 'github_not_found',
                'message' => 'No commit found for the ref ' . $ref,
                'status'  => 404,
                ),
                ),
                'count'   => 0,
                );
            }
            $file = self::$files[ $key ];
            return array(
            'success' => true,
            'files'   => array(
            array(
                        'path'    => $path,
                        'size'    => strlen($file['content']),
                        'sha'     => $file['sha'],
                        'content' => $file['content'],
            ),
            ),
            'errors'  => array(),
            'count'   => 1,
            );
        }

        public static function getRepoTree( array $input ): array|\WP_Error
        {
            return array(
            'success' => true,
            'files'   => array(
            array( 'path' => 'src/example.php', 'type' => 'file', 'size' => 20 ),
            ),
            );
        }

        public static function createOrUpdateFile( array $input ): array|\WP_Error
        {
            $repo   = (string) ( $input['repo'] ?? '' );
            $path   = (string) ( $input['file_path'] ?? '' );
            $branch = (string) ( $input['branch'] ?? 'main' );
            $key        = $repo . ':' . $branch . ':' . $path;
            $source_key = $repo . ':main:' . $path;
            $existing   = self::$files[ $key ] ?? self::$files[ $source_key ] ?? null;
            if (null !== $existing && (string) ( $input['sha'] ?? '' ) !== $existing['sha'] ) {
                return new \WP_Error('github_sha_required', 'Invalid request. "sha" wasn\'t supplied.', array( 'status' => 422 ));
            }
            $sha = 'commit-' . ( count(self::$commits) + 1 );
            self::$files[ $key ] = array(
            'content' => (string) ( $input['content'] ?? '' ),
            'sha'     => 'file-sha-' . $sha,
            );
            self::$commits[] = array( 'sha' => $sha, 'message' => (string) ( $input['commit_message'] ?? '' ), 'input_sha' => (string) ( $input['sha'] ?? '' ) );
            return array(
            'success' => true,
            'commit'  => array( 'sha' => $sha, 'html_url' => 'https://github.com/' . $repo . '/commit/' . $sha ),
            'content' => array( 'path' => $path ),
            );
        }
    }
}

namespace {
    if (! defined('ABSPATH') ) {
        define('ABSPATH', sys_get_temp_dir() . '/dmc-remote-workspace-backend/');
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
        function is_wp_error( $value ): bool
        {
            return $value instanceof WP_Error; 
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

    include __DIR__ . '/../inc/Support/GitRunner.php';
    include __DIR__ . '/../inc/Workspace/WorkspaceAliasResolver.php';
    include __DIR__ . '/../inc/Workspace/RemoteWorkspaceBackend.php';

    use DataMachineCode\Abilities\GitHubAbilities;
    use DataMachineCode\Workspace\RemoteWorkspaceBackend;

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

    echo "Remote workspace backend - smoke\n";

    $backend = new RemoteWorkspaceBackend();
    $clone = $backend->clone_repo('https://github.com/chubes4/example.git');
    $assert('clone registers remote repo', ! is_wp_error($clone) && 'example' === $clone['name'] && 'github_api' === $clone['backend']);
    $assert('clone backend result omits model-facing guidance', ! is_wp_error($clone) && ! array_key_exists('next_required_tool', $clone) && ! array_key_exists('next_required_args', $clone));

    $worktree = $backend->worktree_add('example', 'fix/example');
    $assert('worktree add returns DMC handle', ! is_wp_error($worktree) && 'example@fix-example' === $worktree['handle']);
    $assert('worktree add backend result omits model-facing guidance', ! is_wp_error($worktree) && ! array_key_exists('next_required_tool', $worktree) && ! array_key_exists('next_required_args', $worktree));

    update_option(
        'datamachine_code_workspace_aliases',
        array(
            'current-project' => array(
                'target' => 'example@fix-example',
                'root'   => '.agent-workspace/current-project',
            ),
        )
    );

    $read = $backend->read_file('example@fix-example', 'src/example.php', 1000000);
    $assert('read falls back to default branch content', ! is_wp_error($read) && str_contains($read['content'], 'old'));

    $alias_read = $backend->read_file('current-project', 'src/example.php', 1000000);
    $assert('read resolves current-project alias to remote worktree', ! is_wp_error($alias_read) && str_contains($alias_read['content'], 'old'));

    $primary_grep = $backend->grep('example', 'old', 'src', '*.php', 10, 1);
    $assert('grep searches registered primary before worktree edits', ! is_wp_error($primary_grep) && 1 === $primary_grep['count'] && 'src/example.php' === $primary_grep['matches'][0]['path'] && 2 === $primary_grep['matches'][0]['line'] && ! empty($primary_grep['matches'][0]['context']));

    $edit = $backend->edit_file('example@fix-example', 'src/example.php', 'old', 'new');
    $assert('edit stages pending content', ! is_wp_error($edit) && 1 === $edit['replacements']);
    $assert('edit backend result omits model-facing guidance', ! is_wp_error($edit) && ! array_key_exists('next_required_tool', $edit) && ! array_key_exists('next_required_args', $edit));

    $show = $backend->show('example@fix-example');
    $assert('show supports remote worktree handles', ! is_wp_error($show) && 'github_api' === $show['backend'] && 1 === $show['dirty'] && 'fix/example' === $show['branch']);

    $diff = $backend->git_diff('example@fix-example', path: 'src/example.php');
    $assert('diff supports remote pending changes', ! is_wp_error($diff) && str_contains($diff['diff'], '-return \'old\';') && str_contains($diff['diff'], '+return \'new\';'));
    $assert('diff emits real unified hunk header', ! is_wp_error($diff) && str_contains($diff['diff'], '@@ -1,2 +1,2 @@'));
    $assert('diff includes unchanged lines as context, not as -/+ pairs', ! is_wp_error($diff) && str_contains($diff['diff'], "\n <?php\n") && ! str_contains($diff['diff'], "-<?php"));

    $worktree_grep = $backend->grep('example@fix-example', 'new', null, '*.php');
    $assert('grep searches pending worktree content', ! is_wp_error($worktree_grep) && 1 === $worktree_grep['count'] && str_contains($worktree_grep['matches'][0]['text'], 'new'));

    $status = $backend->git_status('example@fix-example');
    $assert('status reports pending file as dirty', ! is_wp_error($status) && 1 === $status['dirty'] && array( 'src/example.php' ) === $status['files']);
    $assert('status backend result omits model-facing guidance', ! is_wp_error($status) && ! array_key_exists('next_required_tool', $status) && ! array_key_exists('next_required_args', $status));

    $alias_status = $backend->git_status('current-project');
    $assert('status resolves current-project alias to remote worktree', ! is_wp_error($alias_status) && 1 === $alias_status['dirty'] && array( 'src/example.php' ) === $alias_status['files']);

    $commit = $backend->git_commit('example@fix-example', 'fix: update example');
    $assert('commit writes via GitHub API', ! is_wp_error($commit) && 'commit-1' === $commit['commit']);
    $assert('commit backend result omits model-facing guidance', ! is_wp_error($commit) && ! array_key_exists('next_required_tool', $commit) && ! array_key_exists('next_required_args', $commit));
    $assert('commit uses requested message', 'fix: update example' === GitHubAbilities::$commits[0]['message']);
    $assert('commit supplies current file sha', 'file-sha-main-example' === GitHubAbilities::$commits[0]['input_sha']);

    $push = $backend->git_push('example@fix-example');
    $assert('push is successful compatibility no-op', ! is_wp_error($push) && 'fix/example' === $push['branch']);
    $assert('push backend result omits model-facing guidance', ! is_wp_error($push) && ! array_key_exists('next_required_tool', $push) && ! array_key_exists('next_required_args', $push));

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
