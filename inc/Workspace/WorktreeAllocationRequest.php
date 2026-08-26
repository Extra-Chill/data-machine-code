<?php
/**
 * Typed input for worktree planning and allocation.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

final readonly class WorktreeAllocationRequest {

	/**
	 * @param array<string,mixed> $task
	 * @param array<string,mixed> $intent
	 * @param callable|null       $progress_callback
	 * @param array<string,mixed> $expected_freshness_identity
	 */
	public function __construct(
		public string $repo,
		public string $branch,
		public ?string $from = null,
		public bool $inject_context = true,
		public bool $bootstrap = true,
		public bool $allow_stale = false,
		public bool $rebase_base = false,
		public bool $force = false,
		public array $task = array(),
		public bool $allow_unverified_freshness = false,
		public bool $require_task_tracker = true,
		public array $intent = array(),
		public string $reuse_policy = 'reuse_compatible',
		public bool $remediate_capacity = false,
		public bool $remediate_capacity_dry_run = false,
		public mixed $progress_callback = null,
		public array $expected_freshness_identity = array(),
		public bool $allow_percentage_byte_floor_exception = false
	) {}

	/** Build the canonical request accepted by ability and operation adapters. */
	public static function from_input( array $input, bool $require_task_tracker_default = true ): self {
		$task = array_filter(
			array(
				'task_url' => $input['task_url'] ?? null,
				'task_ref' => $input['task_ref'] ?? null,
			),
			static fn( mixed $value ): bool => is_string($value) && '' !== trim($value)
		);
		$task = WorktreeContextInjector::resolve_task_metadata($task) ?? array();

		$intent = array();
		foreach ( array( 'purpose', 'owner_run_ref', 'cleanup_policy' ) as $key ) {
			if ( array_key_exists($key, $input) ) {
				$intent[ $key ] = $input[ $key ];
			}
		}

		return new self(
			repo: (string) ( $input['repo'] ?? '' ),
			branch: (string) ( $input['branch'] ?? '' ),
			from: isset($input['from']) ? (string) $input['from'] : null,
			inject_context: array_key_exists('inject_context', $input) ? (bool) $input['inject_context'] : true,
			bootstrap: array_key_exists('bootstrap', $input) ? (bool) $input['bootstrap'] : true,
			allow_stale: ! empty($input['allow_stale']),
			rebase_base: ! empty($input['rebase_base']),
			force: ! empty($input['force']),
			task: $task,
			allow_unverified_freshness: ! empty($input['allow_unverified_freshness']),
			require_task_tracker: array_key_exists('require_task_tracker', $input) ? (bool) $input['require_task_tracker'] : $require_task_tracker_default,
			intent: $intent,
			reuse_policy: isset($input['reuse_policy']) ? (string) $input['reuse_policy'] : 'reuse_compatible',
			remediate_capacity: ! empty($input['remediate_capacity']),
			remediate_capacity_dry_run: ! empty($input['remediate_capacity_dry_run']),
			progress_callback: isset($input['progress_callback']) && is_callable($input['progress_callback']) ? $input['progress_callback'] : null,
			expected_freshness_identity: is_array($input['expected_freshness_identity'] ?? null) ? $input['expected_freshness_identity'] : array(),
			allow_percentage_byte_floor_exception: ! empty($input['allow_percentage_byte_floor_exception'])
		);
	}
}
