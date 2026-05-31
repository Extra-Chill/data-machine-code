<?php
/**
 * Pure-PHP smoke test for the GitHub create abilities.
 *
 * Run: php tests/smoke-github-create-abilities.php
 *
 * Verifies that `datamachine/create-github-issue` and
 * `datamachine/create-github-pull-request` are registered with the right
 * shape, validate required fields, surface GitHub errors via the `error`
 * field, and fall through to `PermissionHelper::can_manage()`.
 */

declare( strict_types=1 );

namespace DataMachine\Abilities {
    class PermissionHelper
    {
        public static int $can_manage_calls = 0;
        public static bool $can_manage_result = true;
        public static ?string $acting_agent_slug = null;
        public static ?int $acting_agent_id = null;
        public static array $runtime_context = array();

        public static function can_manage(): bool
        {
            ++self::$can_manage_calls;
            return self::$can_manage_result;
        }

        public static function get_acting_agent_slug(): ?string
        {
            return self::$acting_agent_slug;
        }

        public static function get_acting_agent_id(): ?int
        {
            return self::$acting_agent_id;
        }

        public static function get_runtime_context(): array
        {
            return self::$runtime_context;
        }
    }
}

namespace DataMachine\Core {
    class PluginSettings
    {
        public static function get( string $key, string $default_value = '' ): string
        {
            return $default_value;
        }
    }
}

namespace DataMachineCode\Support {
    class GitHubCredentialResolver
    {
        public static string $mode = 'pat';
        public static $resolution = null;
        public static array $selectors = array();

        public static function resolve( ?callable $http_request = null, ?int $now = null, ?array $selector = null )
        {
            self::$selectors[] = $selector;
            if (null === self::$resolution ) {
                return array( 'token' => 'test-token', 'mode' => self::$mode );
            }
            return self::$resolution;
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
            return self::$mode;
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

    if (! function_exists('sanitize_key') ) {
        function sanitize_key( $key ): string
        {
            $key = strtolower((string) $key);
            return preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';
        }
    }

    if (! function_exists('sanitize_title') ) {
        function sanitize_title( $title ): string
        {
            $title = strtolower(trim((string) $title));
            $title = preg_replace('/[^a-z0-9]+/', '-', $title) ?? '';
            return trim($title, '-');
        }
    }

    if (! function_exists('wp_json_encode') ) {
        function wp_json_encode( $data ): string
        {
            return json_encode($data); 
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
    $GLOBALS['dmc_http_calls']            = array();
    $GLOBALS['dmc_http_responses']        = array();

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

    function wp_remote_get( string $url, array $args = array() )
    {
        $GLOBALS['dmc_http_calls'][] = array( 'method' => 'GET', 'url' => $url, 'args' => $args );
        return array_shift($GLOBALS['dmc_http_responses']);
    }

    function wp_remote_request( string $url, array $args = array() )
    {
        $GLOBALS['dmc_http_calls'][] = array( 'method' => $args['method'] ?? 'POST', 'url' => $url, 'args' => $args );
        return array_shift($GLOBALS['dmc_http_responses']);
    }

    function wp_remote_retrieve_response_code( $response ): int
    {
        return (int) ( $response['response']['code'] ?? 0 );
    }

    function wp_remote_retrieve_body( $response ): string
    {
        return (string) ( $response['body'] ?? '' );
    }

    include __DIR__ . '/../inc/Abilities/GitHubAbilities.php';

    use DataMachineCode\Abilities\GitHubAbilities;
    use DataMachine\Abilities\PermissionHelper;

    $failures = array();
    $assert   = function ( string $label, bool $cond ) use ( &$failures ): void {
        if ($cond ) {
            echo "  ok {$label}\n";
            return;
        }
        $failures[] = $label;
        echo "  fail {$label}\n";
    };

    $queue_response = static function ( int $status, array $payload ): void {
        $GLOBALS['dmc_http_responses'][] = array(
        'response' => array( 'code' => $status ),
        'body'     => json_encode($payload),
        );
    };

    $reset_http = static function (): void {
        $GLOBALS['dmc_http_calls']     = array();
        $GLOBALS['dmc_http_responses'] = array();
    };

    echo "GitHub create abilities - smoke\n";

    new GitHubAbilities();

    $issue_ability = $GLOBALS['dmc_registered_abilities']['datamachine/create-github-issue'] ?? null;
    $pr_ability    = $GLOBALS['dmc_registered_abilities']['datamachine/create-github-pull-request'] ?? null;
    $file_ability  = $GLOBALS['dmc_registered_abilities']['datamachine/create-or-update-github-file'] ?? null;

    $assert('create-github-issue ability is registered', null !== $issue_ability);
    $assert('create-github-issue uses createIssue execute_callback', array( GitHubAbilities::class, 'createIssue' ) === ( $issue_ability['execute_callback'] ?? null ));
    $assert('create-github-issue requires repo and title', array( 'repo', 'title' ) === ( $issue_ability['input_schema']['required'] ?? array() ));
    $assert('create-github-issue exposes labels', array_key_exists('labels', $issue_ability['input_schema']['properties'] ?? array()));
    $assert('create-github-issue labels schema declares string items', array( 'type' => 'string' ) === ( $issue_ability['input_schema']['properties']['labels']['items'] ?? null ));
    $assert('create-github-issue exposes assignees', array_key_exists('assignees', $issue_ability['input_schema']['properties'] ?? array()));
    $assert('create-github-issue assignees schema declares string items', array( 'type' => 'string' ) === ( $issue_ability['input_schema']['properties']['assignees']['items'] ?? null ));
    $assert('create-github-issue exposes milestone', array_key_exists('milestone', $issue_ability['input_schema']['properties'] ?? array()));
    $assert('create-github-issue is hidden from REST', false === ( $issue_ability['meta']['show_in_rest'] ?? null ));
    $assert('create-github-issue category matches family', 'datamachine-code-github' === ( $issue_ability['category'] ?? '' ));

    $assert('create-github-pull-request ability is registered', null !== $pr_ability);
    $assert('create-github-pull-request uses createPullRequest execute_callback', array( GitHubAbilities::class, 'createPullRequest' ) === ( $pr_ability['execute_callback'] ?? null ));
    $assert('create-github-pull-request requires repo, title, head', array( 'repo', 'title', 'head' ) === ( $pr_ability['input_schema']['required'] ?? array() ));
    $assert('create-github-pull-request exposes base', array_key_exists('base', $pr_ability['input_schema']['properties'] ?? array()));
    $assert('create-github-pull-request exposes draft', array_key_exists('draft', $pr_ability['input_schema']['properties'] ?? array()));
    $assert('create-github-pull-request exposes labels', array_key_exists('labels', $pr_ability['input_schema']['properties'] ?? array()));
    $assert('create-github-pull-request labels schema declares string items', array( 'type' => 'string' ) === ( $pr_ability['input_schema']['properties']['labels']['items'] ?? null ));
    $assert('create-github-pull-request exposes maintainer_can_modify', array_key_exists('maintainer_can_modify', $pr_ability['input_schema']['properties'] ?? array()));
    $pr_output_properties = $pr_ability['output_schema']['properties'] ?? array();
    $assert('create-github-pull-request output exposes top-level url', array_key_exists('url', $pr_output_properties));
    $assert('create-github-pull-request output exposes top-level html_url', array_key_exists('html_url', $pr_output_properties));
    $assert('create-github-pull-request output exposes top-level pull_number', array_key_exists('pull_number', $pr_output_properties));
    $assert('create-github-pull-request output exposes top-level reused', array_key_exists('reused', $pr_output_properties));
    $assert('create-github-pull-request is hidden from REST', false === ( $pr_ability['meta']['show_in_rest'] ?? null ));

    $assert('create-or-update-github-file ability is registered', null !== $file_ability);
    $assert('create-or-update-github-file exposes allowed_file_paths', array_key_exists('allowed_file_paths', $file_ability['input_schema']['properties'] ?? array()));
    $assert('create-or-update-github-file allowed_file_paths declares string items', array( 'type' => 'string' ) === ( $file_ability['input_schema']['properties']['allowed_file_paths']['items'] ?? null ));

    // Permission gating uses PermissionHelper::can_manage().
    $issue_permission = $issue_ability['permission_callback'] ?? null;
    $pr_permission    = $pr_ability['permission_callback'] ?? null;
    $assert('create-github-issue permission_callback is callable', is_callable($issue_permission));
    $assert('create-github-pull-request permission_callback is callable', is_callable($pr_permission));

    PermissionHelper::$can_manage_calls = 0;
    PermissionHelper::$can_manage_result = true;
    $assert('create-github-issue allows can_manage users', true === $issue_permission());
    $assert('create-github-issue called PermissionHelper::can_manage', 1 === PermissionHelper::$can_manage_calls);

    PermissionHelper::$can_manage_result = false;
    $assert('create-github-pull-request denies non-can_manage users', false === $pr_permission());
    PermissionHelper::$can_manage_result = true;

    // ---- createIssue: required-field validation
    $reset_http();
    $result = GitHubAbilities::createIssue(array());
    $assert('createIssue rejects missing repo', $result instanceof WP_Error && 'missing_repo' === $result->get_error_code());

    $result = GitHubAbilities::createIssue(array( 'repo' => 'owner/repo' ));
    $assert('createIssue rejects missing title', $result instanceof WP_Error && 'missing_title' === $result->get_error_code());

    // ---- createIssue: success path includes all optional inputs
    $reset_http();
    $queue_response(
        201, array(
        'number'   => 42,
        'title'    => 'Hello',
        'state'    => 'open',
        'html_url' => 'https://github.com/owner/repo/issues/42',
        'labels'   => array( array( 'name' => 'bug' ) ),
        ) 
    );
    $result = GitHubAbilities::createIssue(
        array(
        'repo'      => 'owner/repo',
        'title'     => 'Hello',
        'body'      => 'Body text',
        'labels'    => array( 'bug' ),
        'assignees' => array( 'octocat' ),
        'milestone' => 7,
        ) 
    );
    $assert('createIssue success path returns success=true', is_array($result) && true === ( $result['success'] ?? false ));
    $assert('createIssue result kind identifies issue', is_array($result) && 'issue' === ( $result['kind'] ?? '' ));
    $assert('createIssue result exposes repo', is_array($result) && 'owner/repo' === ( $result['repo'] ?? '' ));
    $assert('createIssue result exposes top-level number', is_array($result) && 42 === ( $result['number'] ?? 0 ));
    $assert('createIssue result exposes canonical URL', is_array($result) && 'https://github.com/owner/repo/issues/42' === ( $result['url'] ?? '' ));
    $assert('createIssue success path returns normalized issue', is_array($result) && 42 === ( $result['issue']['number'] ?? 0 ));
    $assert('createIssue success exposes issue_number', is_array($result) && 42 === ( $result['issue_number'] ?? 0 ));
    $call = $GLOBALS['dmc_http_calls'][0] ?? array();
    $body = is_string($call['args']['body'] ?? null) ? json_decode($call['args']['body'], true) : null;
    $assert('createIssue posts to /repos/owner/repo/issues', is_string($call['url'] ?? null) && str_ends_with($call['url'], '/repos/owner/repo/issues'));
    $assert('createIssue forwards labels', is_array($body) && array( 'bug' ) === ( $body['labels'] ?? array() ));
    $assert('createIssue does not add provenance labels without agent context', is_array($body) && array( 'bug' ) === ( $body['labels'] ?? array() ));
    $assert('createIssue forwards assignees', is_array($body) && array( 'octocat' ) === ( $body['assignees'] ?? array() ));
    $assert('createIssue forwards milestone as int', is_array($body) && 7 === ( $body['milestone'] ?? null ));

    // ---- createIssue: agent context merges provenance labels with caller labels
    $reset_http();
    PermissionHelper::$runtime_context = array( 'agent_slug' => 'code-reviewer' );
    $queue_response(
        201, array(
        'number'   => 44,
        'title'    => 'Agent issue',
        'html_url' => 'https://github.com/owner/repo/issues/44',
        ) 
    );
    GitHubAbilities::createIssue(
        array(
        'repo'   => 'owner/repo',
        'title'  => 'Agent issue',
        'labels' => array( 'bug' ),
        ) 
    );
    $call = $GLOBALS['dmc_http_calls'][0] ?? array();
    $body = is_string($call['args']['body'] ?? null) ? json_decode($call['args']['body'], true) : null;
    $assert('createIssue preserves caller labels when adding provenance', is_array($body) && in_array('bug', $body['labels'] ?? array(), true));
    $assert('createIssue adds agent slug provenance label', is_array($body) && in_array('agent:code-reviewer', $body['labels'] ?? array(), true));
    $assert('createIssue adds datamachine-agent label', is_array($body) && in_array('datamachine-agent', $body['labels'] ?? array(), true));
    PermissionHelper::$runtime_context = array();

    // ---- createIssue: ignores null milestone
    $reset_http();
    $queue_response(201, array( 'number' => 43, 'title' => 'No milestone' ));
    GitHubAbilities::createIssue(
        array(
        'repo'      => 'owner/repo',
        'title'     => 'No milestone',
        'milestone' => null,
        ) 
    );
    $call = $GLOBALS['dmc_http_calls'][0] ?? array();
    $body = is_string($call['args']['body'] ?? null) ? json_decode($call['args']['body'], true) : null;
    $assert('createIssue omits milestone when null', is_array($body) && ! array_key_exists('milestone', $body));

    // ---- createIssue: GitHub error surfaces via WP_Error
    $reset_http();
    $queue_response(422, array( 'message' => 'Validation Failed' ));
    $result = GitHubAbilities::createIssue(
        array(
        'repo'  => 'owner/repo',
        'title' => 'Bad payload',
        ) 
    );
    $assert('createIssue surfaces 422 as WP_Error', $result instanceof WP_Error);
    $assert('createIssue 422 carries github_api_error code', $result instanceof WP_Error && 'github_api_error' === $result->get_error_code());
    $assert('createIssue 422 message includes GitHub message', $result instanceof WP_Error && str_contains($result->get_error_message(), 'Validation Failed'));

    // ---- createPullRequest: required-field validation
    $reset_http();
    $result = GitHubAbilities::createPullRequest(array());
    $assert('createPullRequest rejects missing repo', $result instanceof WP_Error && 'missing_repo' === $result->get_error_code());

    $result = GitHubAbilities::createPullRequest(array( 'repo' => 'owner/repo' ));
    $assert('createPullRequest rejects missing title', $result instanceof WP_Error && 'missing_title' === $result->get_error_code());

    $result = GitHubAbilities::createPullRequest(array( 'repo' => 'owner/repo', 'title' => 'PR' ));
    $assert('createPullRequest rejects missing head', $result instanceof WP_Error && 'missing_head' === $result->get_error_code());

    // ---- createPullRequest: success path with explicit base, draft default false
    $reset_http();
    \DataMachineCode\Support\GitHubCredentialResolver::$selectors = array();
    $queue_response(200, array());
    $queue_response(
        201, array(
        'number'   => 88,
        'title'    => 'Open PR',
        'state'    => 'open',
        'html_url' => 'https://github.com/owner/repo/pull/88',
        'head'     => array( 'ref' => 'feature/x', 'sha' => 'aaa' ),
        'base'     => array( 'ref' => 'main', 'sha' => 'bbb' ),
        'draft'    => false,
        ) 
    );
    $result = GitHubAbilities::createPullRequest(
        array(
        'repo'  => 'owner/repo',
        'title' => 'Open PR',
        'head'  => 'feature/x',
        'base'  => 'main',
        'body'  => 'Body',
        ) 
    );
    $assert('createPullRequest success returns success=true', is_array($result) && true === ( $result['success'] ?? false ));
    $assert('createPullRequest selects pull_request_create credential by repo', array( 'repo' => 'owner/repo', 'capability' => 'pull_request_create' ) === ( \DataMachineCode\Support\GitHubCredentialResolver::$selectors[0] ?? null ));
    $assert('createPullRequest result kind identifies pull request', is_array($result) && 'pull_request' === ( $result['kind'] ?? '' ));
    $assert('createPullRequest result exposes repo', is_array($result) && 'owner/repo' === ( $result['repo'] ?? '' ));
    $assert('createPullRequest result exposes top-level number', is_array($result) && 88 === ( $result['number'] ?? 0 ));
    $assert('createPullRequest result exposes canonical PR URL', is_array($result) && 'https://github.com/owner/repo/pull/88' === ( $result['url'] ?? '' ));
    $assert('createPullRequest exposes pull_request key', is_array($result) && isset($result['pull_request']['number']) && 88 === $result['pull_request']['number']);
    $assert('createPullRequest normalized head ref', is_array($result) && 'feature/x' === ( $result['pull_request']['head'] ?? '' ));

    $call = $GLOBALS['dmc_http_calls'][1] ?? array();
    $body = is_string($call['args']['body'] ?? null) ? json_decode($call['args']['body'], true) : null;
    $preflight_call = $GLOBALS['dmc_http_calls'][0] ?? array();
    $assert('createPullRequest preflights open PRs for same head/base', is_string($preflight_call['url'] ?? null) && str_contains($preflight_call['url'], '/repos/owner/repo/pulls') && str_contains($preflight_call['url'], 'head=owner%3Afeature%2Fx') && str_contains($preflight_call['url'], 'base=main'));
    $assert('createPullRequest posts to /repos/owner/repo/pulls', is_string($call['url'] ?? null) && str_ends_with($call['url'], '/repos/owner/repo/pulls'));
    $assert('createPullRequest forwards head and base', is_array($body) && 'feature/x' === ( $body['head'] ?? '' ) && 'main' === ( $body['base'] ?? '' ));
    $assert('createPullRequest defaults maintainer_can_modify=true', is_array($body) && true === ( $body['maintainer_can_modify'] ?? null ));
    $assert('createPullRequest does not set draft when omitted', is_array($body) && ! array_key_exists('draft', $body));
    $assert('createPullRequest does not call labels endpoint without labels or agent context', 2 === count($GLOBALS['dmc_http_calls']));

    // ---- createPullRequest: existing open PR is reused instead of POSTing a duplicate
    $reset_http();
    $queue_response(
        200, array(
        array(
        'number'   => 188,
        'title'    => 'Existing PR',
        'state'    => 'open',
        'html_url' => 'https://github.com/owner/repo/pull/188',
        'head'     => array( 'ref' => 'feature/x', 'sha' => 'ccc' ),
        'base'     => array( 'ref' => 'main', 'sha' => 'ddd' ),
        ),
        ) 
    );
    $result = GitHubAbilities::createPullRequest(
        array(
        'repo'  => 'owner/repo',
        'title' => 'Duplicate PR',
        'head'  => 'feature/x',
        'base'  => 'main',
        ) 
    );
    $assert('createPullRequest reuses existing open PR', is_array($result) && true === ( $result['reused'] ?? false ) && 188 === ( $result['number'] ?? 0 ));
    $assert('createPullRequest skips POST when existing PR is reused', 1 === count($GLOBALS['dmc_http_calls']) && 'GET' === ( $GLOBALS['dmc_http_calls'][0]['method'] ?? '' ));

    // ---- createPullRequest: agent context applies caller and provenance labels after creation
    $reset_http();
    PermissionHelper::$acting_agent_slug = 'code-reviewer';
    $queue_response(200, array());
    $queue_response(
        201, array(
        'number'   => 91,
        'html_url' => 'https://github.com/owner/repo/pull/91',
        'head'     => array( 'ref' => 'feat/labels' ),
        'base'     => array( 'ref' => 'main' ),
        ) 
    );
    $queue_response(
        200, array(
        array( 'name' => 'needs-review' ),
        array( 'name' => 'agent:code-reviewer' ),
        array( 'name' => 'datamachine-agent' ),
        ) 
    );
    $result = GitHubAbilities::createPullRequest(
        array(
        'repo'   => 'owner/repo',
        'title'  => 'Label PR',
        'head'   => 'feat/labels',
        'base'   => 'main',
        'labels' => array( 'needs-review' ),
        ) 
    );
    $label_call = $GLOBALS['dmc_http_calls'][2] ?? array();
    $label_body = is_string($label_call['args']['body'] ?? null) ? json_decode($label_call['args']['body'], true) : null;
    $assert('createPullRequest labels PR through issues labels endpoint', is_string($label_call['url'] ?? null) && str_ends_with($label_call['url'], '/repos/owner/repo/issues/91/labels'));
    $assert('createPullRequest preserves caller label during post-create labeling', is_array($label_body) && in_array('needs-review', $label_body['labels'] ?? array(), true));
    $assert('createPullRequest adds agent slug label during post-create labeling', is_array($label_body) && in_array('agent:code-reviewer', $label_body['labels'] ?? array(), true));
    $assert('createPullRequest adds datamachine-agent during post-create labeling', is_array($label_body) && in_array('datamachine-agent', $label_body['labels'] ?? array(), true));
    $assert('createPullRequest reports successful labeling metadata', is_array($result) && true === ( $result['labeling']['success'] ?? false ) && in_array('agent:code-reviewer', $result['labeling']['applied_labels'] ?? array(), true));
    PermissionHelper::$acting_agent_slug = null;

    // ---- createPullRequest: run artifacts are committed and rendered on direct ability calls
    $reset_http();
    $queue_response(200, array());
    $queue_response(200, array( 'ref' => 'refs/heads/world-day/memory' ));
    $queue_response(404, array( 'message' => 'Not Found' ));
    $queue_response(
        201, array(
        'commit' => array( 'sha' => 'memory-commit' ),
        'content' => array(
        'path'     => 'bundles/world-creator/memory/agent/daily/2026/05/09.md',
        'html_url' => 'https://github.com/owner/repo/blob/world-day/memory/bundles/world-creator/memory/agent/daily/2026/05/09.md',
        ),
        ) 
    );
    $queue_response(
        201, array(
        'number'   => 93,
        'html_url' => 'https://github.com/owner/repo/pull/93',
        'head'     => array( 'ref' => 'world-day/memory' ),
        'base'     => array( 'ref' => 'main' ),
        ) 
    );
    $result = GitHubAbilities::createPullRequest(
        array(
        'repo'                       => 'owner/repo',
        'title'                      => 'Memory PR',
        'head'                       => 'world-day/memory',
        'base'                       => 'main',
        'body'                       => 'Original body.',
        'run_artifacts'              => array(
        'required_tool_names'    => array( 'agent_daily_memory', 'create_github_pull_request' ),
        'satisfied_tool_names'   => array( 'agent_daily_memory' ),
        'daily_memory_artifacts' => array(
                array(
                    'type'                 => 'agent_daily_memory',
                    'agent_slug'           => 'world-creator',
                    'date'                 => '2026-05-09',
                    'bundle_relative_path' => 'memory/agent/daily/2026/05/09.md',
                    'content'              => "# Daily Memory: 2026-05-09\n\nThe journal survived.\n",
                ),
        ),
        ),
        'run_artifact_egress_policy' => array(
        'completion_assertions' => array( 'egress' => array( 'pr-body' ) ),
        'daily_memory'          => array( 'egress' => array( 'bundle-file', 'pr-body' ) ),
        ),
        ) 
    );
    $artifact_put_call = $GLOBALS['dmc_http_calls'][3] ?? array();
    $artifact_put_body = is_string($artifact_put_call['args']['body'] ?? null) ? json_decode($artifact_put_call['args']['body'], true) : null;
    $pr_call           = $GLOBALS['dmc_http_calls'][4] ?? array();
    $pr_body           = is_string($pr_call['args']['body'] ?? null) ? json_decode($pr_call['args']['body'], true) : null;
    $assert('createPullRequest direct ability commits bundle-file artifact before PR creation', 'PUT' === ( $artifact_put_call['method'] ?? '' ) && str_contains((string) ( $artifact_put_call['url'] ?? '' ), '/contents/bundles/world-creator/memory/agent/daily/2026/05/09.md'));
    $assert('createPullRequest direct ability writes artifact to head branch', is_array($artifact_put_body) && 'world-day/memory' === ( $artifact_put_body['branch'] ?? '' ));
    $assert('createPullRequest direct ability appends artifact section to PR body', is_array($pr_body) && str_contains((string) ( $pr_body['body'] ?? '' ), '## Agent Run Artifacts') && str_contains((string) ( $pr_body['body'] ?? '' ), 'The journal survived.'));
    $assert('createPullRequest direct ability marks current PR tool satisfied', is_array($pr_body) && str_contains((string) ( $pr_body['body'] ?? '' ), '`create_github_pull_request`: satisfied'));
    $assert('createPullRequest direct ability reports committed artifact file', is_array($result) && 'bundles/world-creator/memory/agent/daily/2026/05/09.md' === ( $result['run_artifact_files'][0]['file_path'] ?? '' ));

    // ---- createPullRequest: labeling failure does not mask PR creation success
    $reset_http();
    PermissionHelper::$acting_agent_slug = 'code-reviewer';
    $queue_response(200, array());
    $queue_response(
        201, array(
        'number'   => 92,
        'html_url' => 'https://github.com/owner/repo/pull/92',
        'head'     => array( 'ref' => 'feat/missing-label' ),
        'base'     => array( 'ref' => 'main' ),
        ) 
    );
    $queue_response(422, array( 'message' => 'Validation Failed' ));
    $result = GitHubAbilities::createPullRequest(
        array(
        'repo'  => 'owner/repo',
        'title' => 'Label failure PR',
        'head'  => 'feat/missing-label',
        'base'  => 'main',
        ) 
    );
    $assert('createPullRequest keeps success=true when post-create labeling fails', is_array($result) && true === ( $result['success'] ?? false ));
    $assert('createPullRequest returns explicit label failure metadata', is_array($result) && false === ( $result['labeling']['success'] ?? null ) && 'github_api_error' === ( $result['labeling']['error_code'] ?? '' ));

    // ---- removeLabel: surgical removal path URL-encodes the label segment and sends no request body.
    $reset_http();
    $queue_response(200, array( array( 'name' => 'kept' ) ));
    $result = GitHubAbilities::removeLabel(
        array(
        'repo'         => 'owner/repo',
        'issue_number' => 42,
        'label'        => 'status:idea ready',
        ) 
    );
    $call = $GLOBALS['dmc_http_calls'][0] ?? array();
    $assert('removeLabel success path returns success=true', is_array($result) && true === ( $result['success'] ?? false ));
    $assert('removeLabel reports removed label', is_array($result) && 'status:idea ready' === ( $result['removed_label'] ?? '' ));
    $assert('removeLabel uses DELETE method', 'DELETE' === ( $call['method'] ?? '' ));
    $assert('removeLabel URL-encodes label path segment', is_string($call['url'] ?? null) && str_ends_with($call['url'], '/repos/owner/repo/issues/42/labels/status%3Aidea%20ready'));
    $assert('removeLabel does not send request body', ! array_key_exists('body', $call['args'] ?? array()));

    // ---- removeLabel: missing label on GitHub is idempotent success.
    $reset_http();
    $queue_response(404, array( 'message' => 'Label does not exist' ));
    $result = GitHubAbilities::removeLabel(
        array(
        'repo'         => 'owner/repo',
        'issue_number' => 42,
        'label'        => 'status:idea-ready',
        ) 
    );
    $assert('removeLabel treats missing label as success', is_array($result) && true === ( $result['success'] ?? false ));
    $assert('removeLabel missing-label success preserves label name', is_array($result) && 'status:idea-ready' === ( $result['removed_label'] ?? '' ));

    // ---- removeLabel: required-field validation.
    $reset_http();
    $result = GitHubAbilities::removeLabel(array( 'repo' => 'owner/repo' ));
    $assert('removeLabel rejects missing issue or pull number', $result instanceof WP_Error && 'missing_number' === $result->get_error_code());

    $result = GitHubAbilities::removeLabel(array( 'repo' => 'owner/repo', 'issue_number' => 42 ));
    $assert('removeLabel rejects missing label', $result instanceof WP_Error && 'missing_label' === $result->get_error_code());

    $reset_http();
    $queue_response(200, array());
    $result = GitHubAbilities::removeLabel(
        array(
        'repo'        => 'owner/repo',
        'pull_number' => 77,
        'label'       => 'status:idea-ready',
        ) 
    );
    $call = $GLOBALS['dmc_http_calls'][0] ?? array();
    $assert('removeLabel accepts pull_number alias', is_array($result) && true === ( $result['success'] ?? false ) && is_string($call['url'] ?? null) && str_contains($call['url'], '/issues/77/labels/'));

    // ---- createPullRequest: explicit draft and maintainer_can_modify=false
    $reset_http();
    $queue_response(200, array());
    $queue_response(
        201, array(
        'number' => 89,
        'head'   => array( 'ref' => 'feat/y' ),
        'base'   => array( 'ref' => 'main' ),
        ) 
    );
    GitHubAbilities::createPullRequest(
        array(
        'repo'                  => 'owner/repo',
        'title'                 => 'Draft PR',
        'head'                  => 'feat/y',
        'base'                  => 'main',
        'draft'                 => true,
        'maintainer_can_modify' => false,
        ) 
    );
    $call = $GLOBALS['dmc_http_calls'][1] ?? array();
    $body = is_string($call['args']['body'] ?? null) ? json_decode($call['args']['body'], true) : null;
    $assert('createPullRequest forwards draft=true', is_array($body) && true === ( $body['draft'] ?? null ));
    $assert('createPullRequest forwards maintainer_can_modify=false', is_array($body) && false === ( $body['maintainer_can_modify'] ?? null ));

    // ---- createPullRequest: missing base falls back to default branch via GET /repos
    $reset_http();
    $queue_response(200, array( 'default_branch' => 'trunk' ));
    $queue_response(200, array());
    $queue_response(
        201, array(
        'number' => 90,
        'head'   => array( 'ref' => 'feat/z' ),
        'base'   => array( 'ref' => 'trunk' ),
        ) 
    );
    $result = GitHubAbilities::createPullRequest(
        array(
        'repo'  => 'owner/repo',
        'title' => 'Default branch PR',
        'head'  => 'feat/z',
        ) 
    );
    $assert('createPullRequest fallback resolves default branch', is_array($result) && true === ( $result['success'] ?? false ));
    $assert('createPullRequest fallback issued GET /repos/owner/repo first', is_string($GLOBALS['dmc_http_calls'][0]['url'] ?? null) && str_contains($GLOBALS['dmc_http_calls'][0]['url'], '/repos/owner/repo') && 'GET' === ( $GLOBALS['dmc_http_calls'][0]['method'] ?? '' ));
    $pr_call = $GLOBALS['dmc_http_calls'][2] ?? array();
    $pr_body = is_string($pr_call['args']['body'] ?? null) ? json_decode($pr_call['args']['body'], true) : null;
    $assert('createPullRequest sends fallback default base', is_array($pr_body) && 'trunk' === ( $pr_body['base'] ?? '' ));

    // ---- createPullRequest: GitHub validation error surfaces as WP_Error
    $reset_http();
    $queue_response(200, array());
    $queue_response(422, array( 'message' => 'A pull request already exists for owner:feat/x.' ));
    $result = GitHubAbilities::createPullRequest(
        array(
        'repo'  => 'owner/repo',
        'title' => 'Dup PR',
        'head'  => 'feat/x',
        'base'  => 'main',
        ) 
    );
    $assert('createPullRequest surfaces 422 as WP_Error', $result instanceof WP_Error);
    $assert('createPullRequest 422 carries github_api_error code', $result instanceof WP_Error && 'github_api_error' === $result->get_error_code());
    $assert('createPullRequest 422 message includes GitHub message', $result instanceof WP_Error && str_contains($result->get_error_message(), 'already exists'));

    // ---- createOrUpdateFile: optional write scope guardrails reject out-of-scope paths before GitHub calls.
    $reset_http();
    $result = GitHubAbilities::createOrUpdateFile(
        array(
        'repo'               => 'owner/repo',
        'file_path'          => 'src/generated.php',
        'content'            => '<?php echo "nope";',
        'commit_message'     => 'chore: attempt out of scope write',
        'allowed_file_paths' => array( 'README.md', 'docs/**' ),
        ) 
    );
    $assert('createOrUpdateFile rejects out-of-scope file path', $result instanceof WP_Error && 'forbidden_file_path' === $result->get_error_code());
    $assert('createOrUpdateFile rejects out-of-scope path before HTTP calls', 0 === count($GLOBALS['dmc_http_calls']));

    $reset_http();
    $result = GitHubAbilities::createOrUpdateFile(
        array(
        'repo'           => 'owner/repo',
        'file_path'      => '../README.md',
        'content'        => 'Bad path',
        'commit_message' => 'docs: bad path',
        ) 
    );
    $assert('createOrUpdateFile rejects traversal file path', $result instanceof WP_Error && 'invalid_file_path' === $result->get_error_code());
    $assert('createOrUpdateFile rejects traversal before HTTP calls', 0 === count($GLOBALS['dmc_http_calls']));

    // ---- createOrUpdateFile: creates a missing target branch from the default branch.
    $reset_http();
    $queue_response(404, array( 'message' => 'Not Found' ));
    $queue_response(200, array( 'default_branch' => 'main' ));
    $queue_response(200, array( 'object' => array( 'sha' => 'base-sha' ) ));
    $queue_response(201, array( 'ref' => 'refs/heads/store/new-doc' ));
    $queue_response(404, array( 'message' => 'Not Found' ));
    $queue_response(
        201, array(
        'commit' => array(
        'sha'      => 'commit-sha',
        'html_url' => 'https://github.com/owner/repo/commit/commit-sha',
        'message'  => 'docs: add generated file',
        ),
        'content' => array(
        'path'     => 'docs/generated.md',
        'html_url' => 'https://github.com/owner/repo/blob/store/new-doc/docs/generated.md',
        ),
        ) 
    );
    $result = GitHubAbilities::createOrUpdateFile(
        array(
        'repo'               => 'owner/repo',
        'file_path'          => 'docs/generated.md',
        'content'            => '# Generated',
        'commit_message'     => 'docs: add generated file',
        'branch'             => 'store/new-doc',
        'allowed_file_paths' => array( 'README.md', 'docs/**' ),
        ) 
    );
    $assert('createOrUpdateFile missing branch returns success=true', is_array($result) && true === ( $result['success'] ?? false ));
    $assert('createOrUpdateFile checks target branch ref first', is_string($GLOBALS['dmc_http_calls'][0]['url'] ?? null) && str_ends_with($GLOBALS['dmc_http_calls'][0]['url'], '/repos/owner/repo/git/ref/heads/store%2Fnew-doc'));
    $assert('createOrUpdateFile resolves default branch for missing target branch', is_string($GLOBALS['dmc_http_calls'][1]['url'] ?? null) && str_ends_with($GLOBALS['dmc_http_calls'][1]['url'], '/repos/owner/repo'));
    $assert('createOrUpdateFile reads default branch ref', is_string($GLOBALS['dmc_http_calls'][2]['url'] ?? null) && str_ends_with($GLOBALS['dmc_http_calls'][2]['url'], '/repos/owner/repo/git/ref/heads/main'));
    $create_ref_call = $GLOBALS['dmc_http_calls'][3] ?? array();
    $create_ref_body = is_string($create_ref_call['args']['body'] ?? null) ? json_decode($create_ref_call['args']['body'], true) : null;
    $assert('createOrUpdateFile creates branch via git refs API', 'POST' === ( $create_ref_call['method'] ?? '' ) && is_string($create_ref_call['url'] ?? null) && str_ends_with($create_ref_call['url'], '/repos/owner/repo/git/refs'));
    $assert('createOrUpdateFile creates branch from default branch sha', is_array($create_ref_body) && 'refs/heads/store/new-doc' === ( $create_ref_body['ref'] ?? '' ) && 'base-sha' === ( $create_ref_body['sha'] ?? '' ));
    $put_call = $GLOBALS['dmc_http_calls'][5] ?? array();
    $put_body = is_string($put_call['args']['body'] ?? null) ? json_decode($put_call['args']['body'], true) : null;
    $assert('createOrUpdateFile PUTs generated file to requested branch', 'PUT' === ( $put_call['method'] ?? '' ) && is_array($put_body) && 'store/new-doc' === ( $put_body['branch'] ?? '' ));

    // ---- createOrUpdateFile: existing target branch does not trigger branch creation.
    $reset_http();
    $queue_response(200, array( 'ref' => 'refs/heads/store/existing' ));
    $queue_response(404, array( 'message' => 'Not Found' ));
    $queue_response(
        201, array(
        'commit' => array( 'sha' => 'commit-existing' ),
        'content' => array( 'path' => 'docs/existing.md' ),
        ) 
    );
    $result = GitHubAbilities::createOrUpdateFile(
        array(
        'repo'           => 'owner/repo',
        'file_path'      => 'docs/existing.md',
        'content'        => 'Existing branch',
        'commit_message' => 'docs: update generated file',
        'branch'         => 'store/existing',
        ) 
    );
    $assert('createOrUpdateFile existing branch returns success=true', is_array($result) && true === ( $result['success'] ?? false ));
    $assert('createOrUpdateFile existing branch only checks ref, contents, and PUTs', 3 === count($GLOBALS['dmc_http_calls']));
    $assert('createOrUpdateFile existing branch does not create git ref', ! str_ends_with((string) ( $GLOBALS['dmc_http_calls'][1]['url'] ?? '' ), '/git/refs') && ! str_ends_with((string) ( $GLOBALS['dmc_http_calls'][2]['url'] ?? '' ), '/git/refs'));

    // ---- commentOnIssue: fresh issues do not need authenticated actor lookup.
    $reset_http();
    $queue_response(200, array( 'comments' => 0 ));
    $queue_response(
        201, array(
        'id'         => 321,
        'html_url'   => 'https://github.com/owner/repo/issues/123#issuecomment-321',
        'created_at' => '2026-05-10T00:00:00Z',
        )
    );
    $result = GitHubAbilities::commentOnIssue(array( 'repo' => 'owner/repo', 'issue_number' => 123, 'body' => 'Fresh design direction' ));
    $assert('commentOnIssue succeeds for issue with no comments', is_array($result) && true === ( $result['success'] ?? false ));
    $assert('commentOnIssue checks issue comment count before posting', 2 === count($GLOBALS['dmc_http_calls']) && str_contains((string) ( $GLOBALS['dmc_http_calls'][0]['url'] ?? '' ), '/repos/owner/repo/issues/123'));
    $assert('commentOnIssue skips GET /user for issue with no comments', ! str_contains((string) ( $GLOBALS['dmc_http_calls'][0]['url'] ?? '' ), '/user') && ! str_contains((string) ( $GLOBALS['dmc_http_calls'][1]['url'] ?? '' ), '/user'));

    // ---- getIssue: metadata includes comment counts and the latest issue comment.
    $reset_http();
    $queue_response(
        200, array(
        'number'     => 123,
        'title'      => 'Fresh context',
        'body'       => 'Issue body',
        'user'       => array( 'login' => 'reporter' ),
        'labels'     => array( array( 'name' => 'context' ) ),
        'assignees'  => array(),
        'comments'   => 2,
        'created_at' => '2026-05-10T00:00:00Z',
        'updated_at' => '2026-05-10T02:00:00Z',
        ) 
    );
    $queue_response(
        200, array(
        array(
        'id'         => 987,
        'body'       => 'Latest external input',
        'html_url'   => 'https://github.com/owner/repo/issues/123#issuecomment-987',
        'user'       => array( 'login' => 'reviewer' ),
        'created_at' => '2026-05-10T01:00:00Z',
        'updated_at' => '2026-05-10T01:05:00Z',
        ),
        ) 
    );
    $result = GitHubAbilities::getIssue(array( 'repo' => 'owner/repo', 'issue_number' => 123 ));
    $issue  = is_array($result) ? ( $result['issue'] ?? array() ) : array();
    $assert('getIssue returns generated_at', is_array($result) && ! empty($result['generated_at']));
    $assert('getIssue preserves comments count', 2 === ( $issue['comments'] ?? null ) && 2 === ( $issue['comment_count'] ?? null ));
    $assert('getIssue fetches latest issue comment page', is_string($GLOBALS['dmc_http_calls'][1]['url'] ?? null) && str_contains($GLOBALS['dmc_http_calls'][1]['url'], '/repos/owner/repo/issues/123/comments') && str_contains($GLOBALS['dmc_http_calls'][1]['url'], 'per_page=1') && str_contains($GLOBALS['dmc_http_calls'][1]['url'], 'page=2'));
    $assert('getIssue includes latest comment metadata', 'reviewer' === ( $issue['latest_comment_author'] ?? '' ) && 'Latest external input' === ( $issue['latest_comment']['body'] ?? '' ));

    // ---- getPull: metadata includes PR comments, review comments, and change counts.
    $reset_http();
    $queue_response(
        200, array(
        'number'          => 55,
        'title'           => 'Review context',
        'body'            => 'PR body',
        'user'            => array( 'login' => 'author' ),
        'head'            => array( 'ref' => 'feature/context', 'sha' => 'head-sha' ),
        'base'            => array( 'ref' => 'main', 'sha' => 'base-sha' ),
        'comments'        => 3,
        'review_comments' => 4,
        'commits'         => 5,
        'additions'       => 6,
        'deletions'       => 7,
        'changed_files'   => 8,
        ) 
    );
    $result = GitHubAbilities::getPull(array( 'repo' => 'owner/repo', 'pull_number' => 55 ));
    $pull   = is_array($result) ? ( $result['pull'] ?? array() ) : array();
    $assert('getPull returns generated_at', is_array($result) && ! empty($result['generated_at']));
    $assert('getPull includes issue comment counts', 3 === ( $pull['comments'] ?? null ) && 3 === ( $pull['comment_count'] ?? null ) && 3 === ( $pull['issue_comment_count'] ?? null ));
    $assert('getPull includes review comment counts', 4 === ( $pull['review_comments'] ?? null ) && 4 === ( $pull['review_comment_count'] ?? null ));
    $assert('getPull includes change counts', 5 === ( $pull['commits'] ?? null ) && 6 === ( $pull['additions'] ?? null ) && 7 === ( $pull['deletions'] ?? null ) && 8 === ( $pull['changed_files'] ?? null ));

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
