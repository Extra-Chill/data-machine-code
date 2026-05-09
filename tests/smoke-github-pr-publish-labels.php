<?php
/**
 * Behavioral smoke tests for GitHub Pull Request publish handler label support.
 *
 * Exercises GitHubPullRequestPublish::executePublish() with stubbed
 * GitHubAbilities to verify label resolution, application, and partial-failure
 * reporting introduced for issue #269.
 *
 * Run: php tests/smoke-github-pr-publish-labels.php
 *
 * @package DataMachineCode\Tests
 */

declare( strict_types=1 );

namespace DataMachine\Core\Steps {
	if ( ! trait_exists( __NAMESPACE__ . '\\HandlerRegistrationTrait' ) ) {
		trait HandlerRegistrationTrait {
			public static function registerHandler( ...$args ): void {
				// no-op for tests
			}
		}
	}
}

namespace DataMachine\Core\Steps\Publish\Handlers {
	if ( ! class_exists( __NAMESPACE__ . '\\PublishHandler' ) ) {
		abstract class PublishHandler {
			protected string $handler_type;
			public array $log_entries = array();

			public function __construct( string $handler_type ) {
				$this->handler_type = $handler_type;
			}

			abstract protected function executePublish( array $parameters, array $handler_config ): array;

			protected function successResponse( array $data ): array {
				return array(
					'success'   => true,
					'data'      => $data,
					'tool_name' => "{$this->handler_type}_publish",
				);
			}

			protected function errorResponse( string $error_message, ?array $context = null, string $severity = 'warning' ): array {
				$this->log_entries[] = array( 'level' => 'error', 'message' => $error_message, 'context' => $context ?? array() );
				return array(
					'success'   => false,
					'error'     => $error_message,
					'severity'  => $severity,
					'tool_name' => "{$this->handler_type}_publish",
				);
			}

			protected function log( string $level, string $message, array $context = array() ): void {
				$this->log_entries[] = array( 'level' => $level, 'message' => $message, 'context' => $context );
			}
		}
	}
}

namespace DataMachineCode\Abilities {
	class GitHubAbilities {
		public static array $createPullRequestCalls = array();
		public static array $addLabelsCalls         = array();
		public static array $createOrUpdateFileCalls = array();
		/** @var callable|null */
		public static $createPullRequestImpl = null;
		/** @var callable|null */
		public static $addLabelsImpl = null;
		/** @var callable|null */
		public static $createOrUpdateFileImpl = null;

		public static function reset(): void {
			self::$createPullRequestCalls   = array();
			self::$addLabelsCalls           = array();
			self::$createOrUpdateFileCalls  = array();
			self::$createPullRequestImpl    = null;
			self::$addLabelsImpl            = null;
			self::$createOrUpdateFileImpl   = null;
		}

		public static function getDefaultRepo(): string {
			return 'Extra-Chill/data-machine-code';
		}

		public static function createPullRequest( array $input ): array|\WP_Error {
			self::$createPullRequestCalls[] = $input;
			$impl = self::$createPullRequestImpl;
			if ( is_callable( $impl ) ) {
				return $impl( $input );
			}
			return array(
				'success'      => true,
				'pull_request' => array( 'number' => 4242, 'html_url' => 'https://example.test/pull/4242' ),
				'pull_number'  => 4242,
				'html_url'     => 'https://example.test/pull/4242',
			);
		}

		public static function addLabels( array $input ): array|\WP_Error {
			self::$addLabelsCalls[] = $input;
			$impl = self::$addLabelsImpl;
			if ( is_callable( $impl ) ) {
				return $impl( $input );
			}
			$labels = $input['labels'] ?? array();
			return array(
				'success'        => true,
				'applied_labels' => $labels,
				'message'        => 'ok',
			);
		}

		public static function createOrUpdateFile( array $input ): array|\WP_Error {
			self::$createOrUpdateFileCalls[] = $input;
			$impl = self::$createOrUpdateFileImpl;
			if ( is_callable( $impl ) ) {
				return $impl( $input );
			}
			return array(
				'content' => array( 'path' => $input['file_path'] ?? '', 'html_url' => 'https://example.test/blob/main/' . ( $input['file_path'] ?? '' ) ),
				'commit'  => array( 'sha' => 'deadbeef' ),
			);
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ): string {
			return is_string( $value ) ? trim( $value ) : (string) $value;
		}
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ): string {
			$key = strtolower( (string) $key );
			return preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '';
		}
	}

	if ( ! function_exists( 'sanitize_title' ) ) {
		function sanitize_title( $title ): string {
			$title = strtolower( trim( (string) $title ) );
			$title = preg_replace( '/[^a-z0-9]+/', '-', $title ) ?? '';
			return trim( $title, '-' );
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool {
			return $thing instanceof \WP_Error;
		}
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
			public function get_error_message(): string { return $this->message; }
			public function get_error_code(): string { return $this->code; }
		}
	}

	require_once __DIR__ . '/../inc/Support/RunArtifactBundleFileWriter.php';
	require_once __DIR__ . '/../inc/Handlers/GitHub/GitHubPullRequestPublish.php';

	use DataMachineCode\Abilities\GitHubAbilities;
	use DataMachineCode\Handlers\GitHub\GitHubPullRequestPublish;

	/**
	 * Test subclass exposing executePublish for direct invocation.
	 */
	class TestablePullRequestPublish extends GitHubPullRequestPublish {
		public function callExecutePublish( array $parameters, array $handler_config ): array {
			return $this->executePublish( $parameters, $handler_config );
		}
	}

	$failures = array();
	$assert   = function ( string $label, bool $cond ) use ( &$failures ): void {
		if ( $cond ) {
			echo "  ok {$label}\n";
			return;
		}
		echo "  FAIL {$label}\n";
		$failures[] = $label;
	};

	echo "GitHub PR publish handler - label behavior\n";

	// --- Test 1: handler-config labels are applied ---
	GitHubAbilities::reset();
	$handler = new TestablePullRequestPublish();

	$result = $handler->callExecutePublish(
		array(
			'title' => 'Generated blueprint',
			'head'  => 'agent/blueprint-1',
			'body'  => 'body',
		),
		array(
			'repo'   => 'Extra-Chill/data-machine-code',
			'labels' => 'target:blueprint, automation',
			'base'   => 'main',
		)
	);

	$assert( 'success when handler-config labels apply', true === ( $result['success'] ?? false ) );
	$assert( 'applied_labels reflects handler-config labels', array( 'target:blueprint', 'automation' ) === ( $result['data']['applied_labels'] ?? array() ) );
	$assert( 'addLabels called once for handler-config labels', 1 === count( GitHubAbilities::$addLabelsCalls ) );
	$assert(
		'addLabels received resolved label array',
		array( 'target:blueprint', 'automation' ) === ( GitHubAbilities::$addLabelsCalls[0]['labels'] ?? array() )
	);
	$assert( 'addLabels uses pull_number from createPullRequest', 4242 === (int) ( GitHubAbilities::$addLabelsCalls[0]['pull_number'] ?? 0 ) );
	$assert( 'no label_error on success path', ! array_key_exists( 'label_error', $result['data'] ?? array() ) );

	// --- Test 2: tool-parameter labels override handler-config ---
	GitHubAbilities::reset();
	$handler = new TestablePullRequestPublish();

	$result = $handler->callExecutePublish(
		array(
			'title'  => 'Generated static site',
			'head'   => 'agent/static-1',
			'labels' => array( 'target:static-site' ),
		),
		array(
			'repo'   => 'Extra-Chill/data-machine-code',
			'labels' => 'fallback,unused',
		)
	);

	$assert( 'tool-parameter labels override handler-config labels', array( 'target:static-site' ) === ( GitHubAbilities::$addLabelsCalls[0]['labels'] ?? array() ) );
	$assert( 'response applied_labels uses tool-parameter labels', array( 'target:static-site' ) === ( $result['data']['applied_labels'] ?? array() ) );

	// --- Test 3: no labels = no addLabels call ---
	GitHubAbilities::reset();
	$handler = new TestablePullRequestPublish();

	$result = $handler->callExecutePublish(
		array(
			'title' => 'No labels PR',
			'head'  => 'agent/no-labels',
		),
		array(
			'repo' => 'Extra-Chill/data-machine-code',
		)
	);

	$assert( 'no labels means addLabels is never called', 0 === count( GitHubAbilities::$addLabelsCalls ) );
	$assert( 'response applied_labels is empty when no labels', array() === ( $result['data']['applied_labels'] ?? null ) );
	$assert( 'response labels is empty when no labels', array() === ( $result['data']['labels'] ?? null ) );

	// --- Test 4: label apply failure does NOT roll back PR creation ---
	GitHubAbilities::reset();
	GitHubAbilities::$addLabelsImpl = static function ( array $input ): \WP_Error {
		return new \WP_Error( 'github_api_error', 'GitHub API error (422): Validation failed', array( 'status' => 422 ) );
	};
	$handler = new TestablePullRequestPublish();

	$result = $handler->callExecutePublish(
		array(
			'title'  => 'Label failure PR',
			'head'   => 'agent/label-fail',
			'labels' => array( 'does-not-exist' ),
		),
		array(
			'repo' => 'Extra-Chill/data-machine-code',
		)
	);

	$assert( 'PR success is preserved even when labeling fails', true === ( $result['success'] ?? false ) );
	$assert( 'pull_number returned even when labeling fails', 4242 === (int) ( $result['data']['pull_number'] ?? 0 ) );
	$assert( 'applied_labels empty when labeling fails', array() === ( $result['data']['applied_labels'] ?? null ) );
	$assert( 'label_error includes API message', isset( $result['data']['label_error'] ) && str_contains( (string) $result['data']['label_error'], 'Validation failed' ) );
	$has_label_warning = false;
	foreach ( $handler->log_entries as $entry ) {
		if ( 'warning' === $entry['level'] && str_contains( $entry['message'], 'failed to apply labels' ) ) {
			$has_label_warning = true;
			break;
		}
	}
	$assert( 'label failure logged as warning', $has_label_warning );

	// --- Test 5: array-shaped tool labels with mixed whitespace get sanitized ---
	GitHubAbilities::reset();
	$handler = new TestablePullRequestPublish();

	$result = $handler->callExecutePublish(
		array(
			'title'  => 'Sanitization PR',
			'head'   => 'agent/sanitize',
			'labels' => array( '  spaced  ', '', 'kept' ),
		),
		array( 'repo' => 'Extra-Chill/data-machine-code' )
	);

	$assert( 'empty label entries are dropped after sanitization', array( 'spaced', 'kept' ) === ( GitHubAbilities::$addLabelsCalls[0]['labels'] ?? array() ) );

	// --- Test 6: comma-separated string label parameter is accepted (issue spec: string[] | string) ---
	GitHubAbilities::reset();
	$handler = new TestablePullRequestPublish();

	$result = $handler->callExecutePublish(
		array(
			'title'  => 'String labels PR',
			'head'   => 'agent/string-labels',
			'labels' => 'one, two , three',
		),
		array( 'repo' => 'Extra-Chill/data-machine-code' )
	);

	$assert(
		'comma-separated string label parameter is parsed',
		array( 'one', 'two', 'three' ) === ( GitHubAbilities::$addLabelsCalls[0]['labels'] ?? array() )
	);

	// --- Test 7: bundle-file run artifacts are committed to the PR head branch ---
	GitHubAbilities::reset();
	$handler = new TestablePullRequestPublish();

	$result = $handler->callExecutePublish(
		array(
			'title'                      => 'Memory artifact PR',
			'head'                       => 'agent/memory-artifact',
			'run_artifacts'              => array(
				'daily_memory_artifacts' => array(
					array(
						'type'                 => 'agent_daily_memory',
						'agent_slug'           => 'world-creator',
						'date'                 => '2026-05-09',
						'bundle_relative_path' => 'memory/agent/daily/2026/05/09.md',
						'content'              => "# 2026-05-09\n\n- Preserved memory.\n",
					),
				),
			),
			'run_artifact_egress_policy' => array(
				'daily_memory' => array( 'egress' => array( 'bundle-file' ) ),
			),
		),
		array(
			'repo' => 'Extra-Chill/data-machine-code',
		)
	);

	$assert( 'run artifact publish succeeds', true === ( $result['success'] ?? false ) );
	$assert( 'bundle-file artifact creates one GitHub file write', 1 === count( GitHubAbilities::$createOrUpdateFileCalls ) );
	$assert( 'artifact write targets PR head branch', 'agent/memory-artifact' === ( GitHubAbilities::$createOrUpdateFileCalls[0]['branch'] ?? '' ) );
	$assert( 'artifact write uses bundle-relative path under default bundle root', 'bundles/world-creator/memory/agent/daily/2026/05/09.md' === ( GitHubAbilities::$createOrUpdateFileCalls[0]['file_path'] ?? '' ) );
	$assert( 'artifact write is reported in response files', 'bundles/world-creator/memory/agent/daily/2026/05/09.md' === ( $result['data']['files'][0]['file_path'] ?? '' ) );

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
