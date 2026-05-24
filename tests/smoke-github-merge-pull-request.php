<?php
/**
 * Pure-PHP smoke test for the GitHub pull request merge ability and tool.
 *
 * Run: php tests/smoke-github-merge-pull-request.php
 */

declare( strict_types=1 );

namespace DataMachine\Abilities {
    class PermissionHelper
    {
        public static bool $can_manage_result = true;

        public static function can_manage(): bool
        {
            return self::$can_manage_result;
        }
    }
}

namespace DataMachine\Core {
    class PluginSettings
    {
        public static function get( string $key, $default_value = '' )
        {
            return $default_value;
        }
    }
}

namespace DataMachine\Engine\AI\Tools {
    class BaseTool
    {
        public array $registered = array();

        protected function registerTool( string $name, array $definition_callback, array $contexts, array $options = array() ): void
        {
            $this->registered[ $name ] = array(
            'definition_callback' => $definition_callback,
            'contexts'            => $contexts,
            'options'             => $options,
            );
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

namespace DataMachineCode\Support {
    class GitHubCredentialResolver
    {
        public static array $selectors = array();
        public static $resolution = null;

        public static function resolve( ?callable $http_request = null, ?int $now = null, ?array $selector = null )
        {
            self::$selectors[] = $selector;
            if (null !== self::$resolution ) {
                return self::$resolution;
            }

            return array(
            'mode'          => 'pat',
            'token'         => 'test-token',
            'authorization' => 'token test-token',
            'profile_id'    => 'default',
            );
        }

        public static function isConfigured(): bool
        {
            return true;
        }

        public static function status(): array
        {
            return array( 'configured' => true );
        }

        public static function mode(): string
        {
            return 'pat';
        }
    }
}

namespace DataMachineCode\Workspace {
    class Workspace
    {
        public static array $cleanup_calls = array();

        public function cleanup_merged_pr_worktree( string $repo, string $branch, ?string $pr_url = null ): array
        {
            self::$cleanup_calls[] = compact('repo', 'branch', 'pr_url');
            return array(
            'success' => true,
            'found'   => true,
            'handle'  => 'repo@feature-test',
            );
        }
    }
}

namespace DataMachineCode\GitHub {
    class PrReviewEscalationPolicy
    {
    }
    class PrHomeboyReviewRunner
    {
    }
}

namespace {
    if (! defined('ABSPATH') ) {
        define('ABSPATH', __DIR__ . '/');
    }

    class WP_Ability
    {
    }

    if (! class_exists('WP_Error') ) {
        class WP_Error
        {
            public array $error_data;
            public function __construct( private string $code = '', private string $message = '', array $data = array() )
            {
                $this->error_data = $data;
            }
            public function get_error_message(): string
            {
                return $this->message; 
            }
            public function get_error_code(): string
            {
                return $this->code; 
            }
            public function get_error_data()
            {
                return $this->error_data; 
            }
        }
    }

    if (! function_exists('is_wp_error') ) {
        function is_wp_error( $thing ): bool
        {
            return $thing instanceof WP_Error; 
        }
    }

    if (! function_exists('sanitize_text_field') ) {
        function sanitize_text_field( $value ): string
        {
            return is_string($value) ? trim($value) : '';
        }
    }

    if (! function_exists('wp_json_encode') ) {
        function wp_json_encode( $data, int $flags = 0 ): string
        {
            return json_encode($data, $flags); 
        }
    }

    if (! function_exists('add_query_arg') ) {
        function add_query_arg( array $args, string $url ): string
        {
            $separator = str_contains($url, '?') ? '&' : '?';
            return $url . $separator . http_build_query($args);
        }
    }

    if (! function_exists('apply_filters') ) {
        function apply_filters( string $hook, $value )
        {
            return $value; 
        }
    }

    $GLOBALS['dmc_registered_abilities'] = array();
    $GLOBALS['dmc_tool_ability_calls']   = array();

    function wp_register_ability( string $name, array $definition ): void
    {
        $GLOBALS['dmc_registered_abilities'][ $name ] = $definition;
    }

    function doing_action( string $hook ): bool
    {
        return 'wp_abilities_api_init' === $hook; 
    }
    function did_action( string $hook ): int
    {
        return 0; 
    }
    function add_action( string $hook, callable $callback ): void
    {
    }

    class DMC_Test_Ability
    {
        public function __construct( private string $name )
        {
        }

        public function execute( array $input ): array
        {
            $GLOBALS['dmc_tool_ability_calls'][] = array(
            'name'  => $this->name,
            'input' => $input,
            );

            return array(
            'success'     => true,
            'pull_number' => $input['pull_number'] ?? 0,
            'merged'      => true,
            );
        }
    }

    function wp_get_ability( string $name ): ?DMC_Test_Ability
    {
        if (in_array($name, array( 'datamachine/merge-github-pull-request', 'datamachine/cleanup-github-pull-request' ), true) ) {
            return new DMC_Test_Ability($name);
        }

        return null;
    }

    include __DIR__ . '/../inc/Abilities/GitHubAbilities.php';
    include __DIR__ . '/../inc/Tools/GitHubTools.php';

    use DataMachine\Abilities\PermissionHelper;
    use DataMachineCode\Abilities\GitHubAbilities;
    use DataMachineCode\Support\GitHubCredentialResolver;
    use DataMachineCode\Tools\GitHubTools;
    use DataMachineCode\Workspace\Workspace;

    $failures = array();
    $assert   = function ( string $label, bool $cond ) use ( &$failures ): void {
        if ($cond ) {
            echo "  ok {$label}\n";
            return;
        }

        $failures[] = $label;
        echo "  fail {$label}\n";
    };

    $open_pull = static function ( string $sha = 'abc123' ): array {
        return array(
        'success' => true,
        'data'    => array(
        'number'   => 42,
        'state'    => 'open',
        'html_url' => 'https://github.com/owner/repo/pull/42',
        'head'     => array(
        'ref'  => 'feature/test',
        'sha'  => $sha,
        'repo' => array( 'full_name' => 'owner/repo' ),
        ),
        ),
        );
    };

    $merged_pull = static function ( string $head_repo = 'owner/repo', string $head_ref = 'feature/test' ): array {
        return array(
        'success' => true,
        'data'    => array(
        'number'    => 42,
        'state'     => 'closed',
        'merged_at' => '2026-05-12T15:14:37Z',
        'html_url'  => 'https://github.com/owner/repo/pull/42',
        'head'      => array(
        'ref'  => $head_ref,
        'repo' => array( 'full_name' => $head_repo ),
        ),
        ),
        );
    };

    $merge_response = array(
    'success' => true,
    'data'    => array(
    'merged'  => true,
    'sha'     => 'merge-sha',
    'message' => 'Pull Request successfully merged',
    ),
    );

    echo "GitHub merge pull request - smoke\n";

    new GitHubAbilities();
    $ability = $GLOBALS['dmc_registered_abilities']['datamachine/merge-github-pull-request'] ?? null;
    $assert('merge ability is registered', null !== $ability);
    $assert('merge ability uses mergePullRequest execute_callback', array( GitHubAbilities::class, 'mergePullRequest' ) === ( $ability['execute_callback'] ?? null ));
    $assert('merge ability requires repo, pull_number, expected_head_sha', array( 'repo', 'pull_number', 'expected_head_sha' ) === ( $ability['input_schema']['required'] ?? array() ));
    $assert('merge ability limits merge_method enum', array( 'merge', 'squash', 'rebase' ) === ( $ability['input_schema']['properties']['merge_method']['enum'] ?? array() ));
    $assert('merge ability exposes delete_branch', isset($ability['input_schema']['properties']['delete_branch']));
    $cleanup_ability = $GLOBALS['dmc_registered_abilities']['datamachine/cleanup-github-pull-request'] ?? null;
    $assert('cleanup ability is registered', null !== $cleanup_ability);
    $assert('cleanup ability uses cleanupPullRequest execute_callback', array( GitHubAbilities::class, 'cleanupPullRequest' ) === ( $cleanup_ability['execute_callback'] ?? null ));
    $assert('cleanup ability exposes local_only', isset($cleanup_ability['input_schema']['properties']['local_only']));

    $permission = $ability['permission_callback'] ?? null;
    PermissionHelper::$can_manage_result = false;
    $assert('merge ability denies non-can_manage users', is_callable($permission) && false === $permission());
    PermissionHelper::$can_manage_result = true;

    $result = GitHubAbilities::mergePullRequest(array());
    $assert('mergePullRequest rejects missing params', $result instanceof WP_Error && 'missing_params' === $result->get_error_code());

    $result = GitHubAbilities::mergePullRequest(
        array(
        'repo'              => 'owner/repo',
        'pull_number'       => 42,
        'expected_head_sha' => 'abc123',
        'merge_method'      => 'ff-only',
        ) 
    );
    $assert('mergePullRequest rejects invalid merge method', $result instanceof WP_Error && 'invalid_merge_method' === $result->get_error_code());

    GitHubCredentialResolver::$selectors = array();
    $calls = array();
    $result = GitHubAbilities::mergePullRequest(
        array(
        'repo'              => 'owner/repo',
        'pull_number'       => 42,
        'expected_head_sha' => 'abc123',
        ),
        static function ( string $url, array $query, string $pat ) use ( &$calls, $open_pull ): array {
            $calls[] = array( 'method' => 'GET', 'url' => $url, 'query' => $query, 'pat' => $pat );
            return $open_pull();
        },
        static function ( string $method, string $url, array $body, string $pat ) use ( &$calls, $merge_response ): array {
            $calls[] = array( 'method' => $method, 'url' => $url, 'body' => $body, 'pat' => $pat );
            return $merge_response;
        }
    );
    $assert('mergePullRequest success returns merged=true', is_array($result) && true === ( $result['merged'] ?? false ));
    $assert('mergePullRequest returns PR URL', is_array($result) && 'https://github.com/owner/repo/pull/42' === ( $result['pull_request_url'] ?? '' ));
    $assert('mergePullRequest selects credential by repo', array( 'repo' => 'owner/repo' ) === ( GitHubCredentialResolver::$selectors[0] ?? null ));
    $assert('mergePullRequest fetches PR before merge', 'GET' === ( $calls[0]['method'] ?? '' ) && str_ends_with($calls[0]['url'] ?? '', '/repos/owner/repo/pulls/42'));
    $assert('mergePullRequest calls GitHub merge endpoint', 'PUT' === ( $calls[1]['method'] ?? '' ) && str_ends_with($calls[1]['url'] ?? '', '/repos/owner/repo/pulls/42/merge'));
    $assert('mergePullRequest defaults to squash', 'squash' === ( $calls[1]['body']['merge_method'] ?? '' ));

    $calls = array();
    $result = GitHubAbilities::mergePullRequest(
        array(
        'repo'              => 'owner/repo',
        'pull_number'       => 42,
        'expected_head_sha' => 'abc123',
        'merge_method'      => 'rebase',
        ),
        static fn(): array => $open_pull(),
        static function ( string $method, string $url, array $body, string $pat ) use ( &$calls, $merge_response ): array {
            $calls[] = compact('method', 'url', 'body', 'pat');
            return $merge_response;
        }
    );
    $assert('mergePullRequest forwards explicit rebase method', is_array($result) && 'rebase' === ( $calls[0]['body']['merge_method'] ?? '' ));

    $result = GitHubAbilities::mergePullRequest(
        array(
        'repo'              => 'owner/repo',
        'pull_number'       => 42,
        'expected_head_sha' => 'abc123',
        ),
        static fn(): array => array( 'success' => true, 'data' => array( 'state' => 'closed', 'head' => array( 'sha' => 'abc123' ) ) ),
        static fn(): array => $merge_response
    );
    $assert('mergePullRequest rejects closed PR', $result instanceof WP_Error && 'pull_request_not_open' === $result->get_error_code());

    $merge_called = false;
    $result = GitHubAbilities::mergePullRequest(
        array(
        'repo'              => 'owner/repo',
        'pull_number'       => 42,
        'expected_head_sha' => 'abc123',
        ),
        static fn(): array => $open_pull('different-sha'),
        static function () use ( &$merge_called ): array {
            $merge_called = true;
            return array( 'success' => true, 'data' => array() );
        }
    );
    $assert('mergePullRequest rejects head SHA mismatch', $result instanceof WP_Error && 'pull_request_head_sha_mismatch' === $result->get_error_code());
    $assert('mergePullRequest does not merge after SHA mismatch', false === $merge_called);

    $calls = array();
    Workspace::$cleanup_calls = array();
    $result = GitHubAbilities::cleanupPullRequest(
        array(
        'repo'        => 'owner/repo',
        'pull_number' => 42,
        'dry_run'     => true,
        ),
        static function ( string $url, array $query, string $pat ) use ( &$calls, $merged_pull ): array {
            $calls[] = array( 'method' => 'GET', 'url' => $url, 'query' => $query, 'pat' => $pat );
            return $merged_pull();
        },
        static function ( string $method, string $url, ?array $body, string $pat ) use ( &$calls ): array {
            $calls[] = compact('method', 'url', 'body', 'pat');
            return array( 'success' => true, 'data' => null );
        }
    );
    $assert('cleanupPullRequest dry-run succeeds', is_array($result) && true === ( $result['dry_run'] ?? false ));
    $assert('cleanupPullRequest dry-run does not delete branch', 1 === count($calls));

    $calls = array();
    $result = GitHubAbilities::cleanupPullRequest(
        array(
        'repo'        => 'owner/repo',
        'pull_number' => 42,
        ),
        static function ( string $url, array $query, string $pat ) use ( &$calls, $merged_pull ): array {
            $calls[] = array( 'method' => 'GET', 'url' => $url, 'query' => $query, 'pat' => $pat );
            return $merged_pull();
        },
        static function ( string $method, string $url, ?array $body, string $pat ) use ( &$calls ): array {
            $calls[] = compact('method', 'url', 'body', 'pat');
            return array( 'success' => true, 'data' => null );
        }
    );
    $assert('cleanupPullRequest deletes merged same-repo branch', is_array($result) && true === ( $result['branch_deleted'] ?? false ));
    $assert('cleanupPullRequest calls GitHub refs delete endpoint', 'DELETE' === ( $calls[1]['method'] ?? '' ) && str_contains($calls[1]['url'] ?? '', '/repos/owner/repo/git/refs/heads/'));
    $assert('cleanupPullRequest removes matching DMC worktree before remote branch delete', array( 'repo' => 'owner/repo', 'branch' => 'feature/test', 'pr_url' => 'https://github.com/owner/repo/pull/42' ) === ( Workspace::$cleanup_calls[0] ?? array() ));
    $assert('cleanupPullRequest returns local worktree cleanup evidence', true === ( $result['local_worktree_cleanup']['found'] ?? false ));

    $calls = array();
    Workspace::$cleanup_calls = array();
    $result = GitHubAbilities::cleanupPullRequest(
        array(
        'repo'        => 'owner/repo',
        'pull_number' => 42,
        'local_only'  => true,
        ),
        static function ( string $url, array $query, string $pat ) use ( &$calls, $merged_pull ): array {
            $calls[] = array( 'method' => 'GET', 'url' => $url, 'query' => $query, 'pat' => $pat );
            return $merged_pull();
        },
        static function ( string $method, string $url, ?array $body, string $pat ) use ( &$calls ): array {
            $calls[] = compact('method', 'url', 'body', 'pat');
            return array( 'success' => true, 'data' => null );
        }
    );
    $assert('cleanupPullRequest local_only succeeds', is_array($result) && true === ( $result['local_only'] ?? false ));
    $assert('cleanupPullRequest local_only skips remote branch delete', 1 === count($calls));
    $assert('cleanupPullRequest local_only returns local cleanup evidence', true === ( $result['local_worktree_cleanup']['found'] ?? false ));

    $calls = array();
    Workspace::$cleanup_calls = array();
    $result = GitHubAbilities::cleanupPullRequest(
        array(
        'repo'        => 'owner/repo',
        'pull_number' => 42,
        ),
        static function ( string $url, array $query, string $pat ) use ( &$calls, $merged_pull ): array {
            $calls[] = array( 'method' => 'GET', 'url' => $url, 'query' => $query, 'pat' => $pat );
            return $merged_pull();
        },
        static function ( string $method, string $url, ?array $body, string $pat ) use ( &$calls ): WP_Error {
            $calls[] = compact('method', 'url', 'body', 'pat');
            return new WP_Error('github_api_error', 'GitHub API error (403): Resource not accessible by integration', array( 'status' => 403 ));
        }
    );
    $assert('cleanupPullRequest preserves local cleanup on remote delete failure', is_array($result) && true === ( $result['partial_success'] ?? false ));
    $assert('cleanupPullRequest reports remote delete error without losing local evidence', 403 === ( $result['branch_delete_error']['status'] ?? 0 ) && true === ( $result['local_worktree_cleanup']['found'] ?? false ));

    $result = GitHubAbilities::cleanupPullRequest(
        array(
        'repo'        => 'owner/repo',
        'pull_number' => 42,
        ),
        static fn(): array => $open_pull(),
        static fn(): array => array( 'success' => true, 'data' => null )
    );
    $assert('cleanupPullRequest rejects unmerged PR', $result instanceof WP_Error && 'pull_request_not_merged' === $result->get_error_code());

    $result = GitHubAbilities::cleanupPullRequest(
        array(
        'repo'        => 'owner/repo',
        'pull_number' => 42,
        ),
        static fn(): array => $merged_pull('fork/repo'),
        static fn(): array => array( 'success' => true, 'data' => null )
    );
    $assert('cleanupPullRequest rejects fork head repo', $result instanceof WP_Error && 'pull_request_head_repo_mismatch' === $result->get_error_code());

    $tools = new GitHubTools();
    $assert('merge_github_pull_request tool is registered', isset($tools->registered['merge_github_pull_request']));
    $assert('cleanup_github_pull_request tool is registered', isset($tools->registered['cleanup_github_pull_request']));
    $assert('merge tool links to merge ability', 'datamachine/merge-github-pull-request' === ( $tools->registered['merge_github_pull_request']['options']['ability'] ?? '' ));
    $assert('cleanup tool links to cleanup ability', 'datamachine/cleanup-github-pull-request' === ( $tools->registered['cleanup_github_pull_request']['options']['ability'] ?? '' ));
    $definition = $tools->getMergePullRequestDefinition();
    $params     = $definition['parameters'] ?? array();
    $assert('merge tool requires expected_head_sha', in_array('expected_head_sha', $params['required'] ?? array(), true));
    $tool_result = $tools->handle_tool_call(
        array(
        'repo'              => 'owner/repo',
        'pull_number'       => 42,
        'expected_head_sha' => 'abc123',
        ),
        $definition
    );
    $assert('merge tool returns direct ability success', true === ( $tool_result['success'] ?? false ));
    $assert('merge tool response names tool', 'merge_github_pull_request' === ( $tool_result['tool_name'] ?? '' ));
    $tool_call = $GLOBALS['dmc_tool_ability_calls'][0] ?? array();
    $assert('merge tool calls merge ability', 'datamachine/merge-github-pull-request' === ( $tool_call['name'] ?? '' ));
    $cleanup_definition = $tools->getCleanupPullRequestDefinition();
    $assert('cleanup tool exposes local_only', isset($cleanup_definition['parameters']['properties']['local_only']));
    $cleanup_result     = $tools->handle_tool_call(
        array(
        'repo'        => 'owner/repo',
        'pull_number' => 42,
        'dry_run'     => true,
        ),
        $cleanup_definition
    );
    $assert('cleanup tool returns direct ability success', true === ( $cleanup_result['success'] ?? false ));
    $assert('cleanup tool response names tool', 'cleanup_github_pull_request' === ( $cleanup_result['tool_name'] ?? '' ));

    $scaffold_source = file_get_contents(__DIR__ . '/../inc/GitHub/PrReviewFlowScaffold.php');
    $assert('PR review scaffold does not enable merge tool', ! str_contains($scaffold_source, 'merge_github_pull_request'));
    $wc_merge_exposures = array();
    $repo_iterator      = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__)));
    foreach ( $repo_iterator as $file ) {
        if (! $file->isFile() || ! in_array($file->getExtension(), array( 'php', 'json', 'yml', 'yaml', 'md' ), true) ) {
            continue;
        }
        if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR) ) {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if (is_string($contents) && str_contains($contents, 'wc-site-generator') && str_contains($contents, 'merge_github_pull_request') ) {
            $wc_merge_exposures[] = $file->getPathname();
        }
    }
    $assert('wc-site-generator references do not expose merge tool', empty($wc_merge_exposures));

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
