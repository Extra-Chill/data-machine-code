<?php
/**
 * Pure-PHP smoke test for the GitHub PR comment tool wrapper.
 *
 * Run: php tests/smoke-github-pr-comment-tool.php
 *
 * Verifies the narrow PR-comment surface without bootstrapping WordPress or
 * sending a real GitHub API request.
 */

declare( strict_types=1 );

namespace DataMachine\Engine\AI\Tools {
    class BaseTool {
        public array $registered = array();

        protected function registerTool( string $name, array $definition_callback, array $contexts, array $options = array() ): void {
            $this->registered[ $name ] = array(
                'definition_callback' => $definition_callback,
                'contexts'            => $contexts,
                'options'             => $options,
            );
        }

        protected function buildErrorResponse( string $message, string $tool_name ): array {
            return array(
                'success'   => false,
                'error'     => $message,
                'tool_name' => $tool_name,
            );
        }
    }
}

namespace {
    if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', __DIR__ . '/' );
    }

    require __DIR__ . '/../inc/Abilities/GitHubAbilities.php';
    require __DIR__ . '/../inc/Tools/GitHubTools.php';

    use DataMachineCode\Abilities\GitHubAbilities;
    use DataMachineCode\Tools\GitHubTools;

    $failures = array();
    $assert   = function ( string $label, bool $cond ) use ( &$failures ): void {
        if ( $cond ) {
            echo "  ok {$label}\n";
            return;
        }
        $failures[] = $label;
        echo "  fail {$label}\n";
    };

    echo "GitHub PR comment tool wrapper - smoke\n";

	$mapper = new ReflectionMethod( GitHubAbilities::class, 'buildPullRequestCommentInput' );

	$mapped = $mapper->invoke( null, array(
        'repo'        => 'Extra-Chill/data-machine-code',
        'pull_number' => 86,
        'body'        => 'Review body',
    ) );

    $assert( 'pull_number maps to issue_number', 86 === $mapped['issue_number'] );
    $assert( 'repo passes through unchanged', 'Extra-Chill/data-machine-code' === $mapped['repo'] );
    $assert( 'body passes through without marker', 'Review body' === $mapped['body'] );
    $assert( 'pull_number is the only numeric input in mapped payload', ! array_key_exists( 'pull_number', $mapped ) );

    $marked = $mapper->invoke( null, array(
        'repo'        => 'Extra-Chill/data-machine-code',
        'pull_number' => 87,
        'body'        => 'Review body',
        'marker'      => 'dmc-pr-review-marker',
    ) );

    $assert( 'marker appends as HTML comment', str_contains( $marked['body'], '<!-- dmc-pr-review-marker -->' ) );
    $assert( 'marker preserves visible comment body', str_starts_with( $marked['body'], 'Review body' ) );

    $tools = new GitHubTools();

    $assert( 'comment_github_pull_request tool is registered', isset( $tools->registered['comment_github_pull_request'] ) );
    $assert( 'tool is available in pipeline context', in_array( 'pipeline', $tools->registered['comment_github_pull_request']['contexts'] ?? array(), true ) );

    $definition = $tools->getCommentPullRequestDefinition();
    $params     = $definition['parameters'] ?? array();

    $assert( 'tool delegates to narrow PR comment handler', 'handleCommentPullRequest' === ( $definition['method'] ?? '' ) );
    $assert( 'tool requires repo', true === ( $params['repo']['required'] ?? false ) );
    $assert( 'tool requires pull_number', true === ( $params['pull_number']['required'] ?? false ) );
    $assert( 'tool requires body', true === ( $params['body']['required'] ?? false ) );
    $assert( 'tool accepts optional marker', false === ( $params['marker']['required'] ?? true ) );
    $assert( 'tool does not expose broad issue action parameter', ! array_key_exists( 'action', $params ) );
    $assert( 'tool does not expose issue close state parameter', ! array_key_exists( 'state', $params ) );
    $assert( 'tool does not expose issue update title parameter', ! array_key_exists( 'title', $params ) );
    $assert( 'tool does not expose issue labels update parameter', ! array_key_exists( 'labels', $params ) );

    $ability_source = file_get_contents( __DIR__ . '/../inc/Abilities/GitHubAbilities.php' );
    $tool_source    = file_get_contents( __DIR__ . '/../inc/Tools/GitHubTools.php' );

    $assert( 'PR comment ability is registered', str_contains( $ability_source, 'datamachine/comment-github-pull-request' ) );
    $assert( 'ability executes commentOnPullRequest', str_contains( $ability_source, "'execute_callback'    => array( self::class, 'commentOnPullRequest' )" ) );
    $assert( 'tool is included in configuration checks', str_contains( $tool_source, "'comment_github_pull_request'" ) );

    if ( ! empty( $failures ) ) {
        echo "\nFAIL: " . count( $failures ) . " assertion(s)\n";
        foreach ( $failures as $failure ) {
            echo "  - {$failure}\n";
        }
        exit( 1 );
    }

    echo "\nOK\n";
    exit( 0 );
}
