<?php
/**
 * Pure-PHP smoke test for managed GitHub PR review comment upsert.
 *
 * Run: php tests/smoke-github-pr-review-comment-upsert.php
 */

declare( strict_types=1 );

namespace DataMachine\Core {
    class PluginSettings {
        public static function get( string $key, string $default = '' ): string {
            return 'github_pat' === $key ? 'test-token' : $default;
        }
    }
}

namespace {
    if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', __DIR__ . '/' );
    }

    class WP_Error {
        private string $code;
        private string $message;
        private array $data;

        public function __construct( string $code, string $message, array $data = array() ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }

        public function get_error_data(): array {
            return $this->data;
        }
    }

    function is_wp_error( $value ): bool {
        return $value instanceof WP_Error;
    }

    function sanitize_text_field( $value ): string {
        return trim( (string) $value );
    }

    require __DIR__ . '/../inc/Abilities/GitHubAbilities.php';

    use DataMachineCode\Abilities\GitHubAbilities;

    $failures = array();
    $assert   = function ( string $label, bool $cond ) use ( &$failures ): void {
        if ( $cond ) {
            echo "  ok {$label}\n";
            return;
        }

        $failures[] = $label;
        echo "  fail {$label}\n";
    };

    $comment = function ( int $id, string $author, string $body, string $updated_at = '' ): array {
        return array(
            'id'         => $id,
            'body'       => $body,
            'updated_at' => $updated_at,
            'html_url'   => "https://github.test/comment/{$id}",
            'user'       => array( 'login' => $author ),
        );
    };

    $run = function ( array $comments, array $input = array() ) use ( &$requests ): array|WP_Error {
        $requests = array();

        $api_get = function ( string $url, array $query, string $pat ) use ( $comments, &$requests ): array {
            $requests[] = array( 'GET', $url, $query, null, $pat );
            if ( str_ends_with( $url, '/user' ) ) {
                return array(
                    'success' => true,
                    'data'    => array( 'login' => 'datamachine-bot' ),
                );
            }

            return array(
                'success' => true,
                'data'    => 1 === (int) ( $query['page'] ?? 1 ) ? $comments : array(),
            );
        };

        $api_request = function ( string $method, string $url, array $body, string $pat ) use ( &$requests ): array {
            $requests[] = array( $method, $url, array(), $body, $pat );
            $id         = 'PATCH' === $method ? (int) basename( $url ) : 9001;

            return array(
                'success' => true,
                'data'    => array(
                    'id'       => $id,
                    'html_url' => "https://github.test/comment/{$id}",
                    'body'     => $body['body'] ?? '',
                ),
            );
        };

        return GitHubAbilities::upsertPullReviewComment(
            array_merge(
                array(
                    'repo'        => 'Extra-Chill/data-machine-code',
                    'pull_number' => 97,
                    'body'        => 'Managed review body',
                ),
                $input
            ),
            $api_get,
            $api_request
        );
    };

    echo "GitHub PR review comment upsert - smoke\n";

    $result = $run( array() );
    $assert( 'no existing marker comment creates', 'created' === ( $result['action'] ?? '' ) );
    $assert( 'create returns comment id', 9001 === ( $result['comment_id'] ?? 0 ) );
    $assert( 'create returns html_url', 'https://github.test/comment/9001' === ( $result['html_url'] ?? '' ) );
    $assert( 'create uses POST to PR issue comments endpoint', 'POST' === ( $requests[2][0] ?? '' ) && str_contains( $requests[2][1] ?? '', '/issues/97/comments' ) );
    $assert( 'create appends default hidden marker', str_contains( $requests[2][3]['body'] ?? '', '<!-- datamachine-pr-review -->' ) );

    $result = $run( array(
        $comment( 100, 'datamachine-bot', "Previous review\n\n<!-- datamachine-pr-review -->" ),
    ) );
    $assert( 'same-author marker comment updates', 'updated' === ( $result['action'] ?? '' ) );
    $assert( 'update returns existing comment id', 100 === ( $result['comment_id'] ?? 0 ) );
    $assert( 'update uses PATCH to issue comment endpoint', 'PATCH' === ( $requests[2][0] ?? '' ) && str_ends_with( $requests[2][1] ?? '', '/issues/comments/100' ) );

    $result = $run( array(
        $comment( 105, 'datamachine-bot', "Older review\n\n<!-- datamachine-pr-review -->", '2026-01-01T00:00:00Z' ),
        $comment( 106, 'datamachine-bot', "Newer review\n\n<!-- datamachine-pr-review -->", '2026-01-02T00:00:00Z' ),
    ) );
    $assert( 'latest same-author marker comment wins', 'updated' === ( $result['action'] ?? '' ) && 106 === ( $result['comment_id'] ?? 0 ) );

    $result = $run( array(
        $comment( 101, 'someone-else', "Previous review\n\n<!-- datamachine-pr-review -->" ),
    ) );
    $assert( 'other-author marker comment is not overwritten', 'created' === ( $result['action'] ?? '' ) );
    $assert( 'other-author marker create does not patch foreign comment', 'POST' === ( $requests[2][0] ?? '' ) );

    $result = $run( array(
        $comment( 102, 'datamachine-bot', 'Plain comment without marker' ),
    ) );
    $assert( 'comments without marker are ignored', 'created' === ( $result['action'] ?? '' ) );

    $result = $run(
        array(
            $comment( 103, 'datamachine-bot', "Old head review\n\n<!-- datamachine-pr-review -->\n<!-- datamachine-pr-review-head-sha: oldsha -->" ),
        ),
        array(
            'mode'     => 'per_head_sha',
            'head_sha' => 'newsha',
        )
    );
    $assert( 'per_head_sha ignores matching base marker with different head', 'created' === ( $result['action'] ?? '' ) );
    $assert( 'per_head_sha appends head-specific marker', str_contains( $requests[2][3]['body'] ?? '', '<!-- datamachine-pr-review-head-sha: newsha -->' ) );

    $result = $run(
        array(
            $comment( 104, 'datamachine-bot', "Same head review\n\n<!-- datamachine-pr-review -->\n<!-- datamachine-pr-review-head-sha: newsha -->" ),
        ),
        array(
            'mode'     => 'per_head_sha',
            'head_sha' => 'newsha',
        )
    );
    $assert( 'per_head_sha updates same-head managed comment', 'updated' === ( $result['action'] ?? '' ) );
    $assert( 'per_head_sha returns matched comment id', 104 === ( $result['comment_id'] ?? 0 ) );

    $result = $run( array(), array( 'marker' => 'custom-review-marker' ) );
    $assert( 'plain marker input is normalized to hidden HTML comment', str_contains( $requests[2][3]['body'] ?? '', '<!-- custom-review-marker -->' ) );

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
