<?php
/**
 * Optional Agents API workspace executor adapter.
 *
 * @package DataMachineCode\AgentsApi
 */

namespace DataMachineCode\AgentsApi;

defined('ABSPATH') || exit;

/**
 * Projects blessed DMC workspace abilities into Agents API runtime tools.
 */
class WorkspaceExecutorAdapter {

	public const TARGET_ID   = 'data-machine-code/blessed-workspace';
	public const SOURCE_SLUG = 'client';

	private const TOOL_MAP = array(
		'client/filesystem_read'  => array(
			'capability'   => 'filesystem.read',
			'ability'      => 'datamachine-code/workspace-read',
			'description'  => 'Read a text file from a Data Machine Code workspace handle.',
			'side_effects' => array(),
			'parameters'   => array(
				'type'       => 'object',
				'required'   => array( 'path' ),
				'properties' => array(
					'repo'     => array(
						'type'        => 'string',
						'description' => 'Workspace handle: repo primary or repo@branch-slug worktree.',
					),
					'path'     => array(
						'type'        => 'string',
						'description' => 'Relative file path within the workspace handle.',
					),
					'max_size' => array( 'type' => 'integer' ),
					'offset'   => array( 'type' => 'integer' ),
					'limit'    => array( 'type' => 'integer' ),
				),
			),
		),
		'client/filesystem_write' => array(
			'capability'   => 'filesystem.write',
			'ability'      => 'datamachine-code/workspace-write',
			'description'  => 'Create or overwrite a file through Data Machine Code workspace services.',
			'side_effects' => array( 'filesystem.write' ),
			'parameters'   => array(
				'type'       => 'object',
				'required'   => array( 'path', 'content' ),
				'properties' => array(
					'repo'    => array(
						'type'        => 'string',
						'description' => 'Workspace handle: repo primary or repo@branch-slug worktree.',
					),
					'path'    => array(
						'type'        => 'string',
						'description' => 'Relative file path within the workspace handle.',
					),
					'content' => array(
						'type'        => 'string',
						'description' => 'File content to write.',
					),
				),
			),
		),
		'client/git_diff'         => array(
			'capability'   => 'git.diff',
			'ability'      => 'datamachine-code/workspace-diff-summary',
			'description'  => 'Summarize git diff state for a Data Machine Code workspace handle.',
			'side_effects' => array(),
			'parameters'   => array(
				'type'       => 'object',
				'required'   => array( 'name' ),
				'properties' => array(
					'name'   => array(
						'type'        => 'string',
						'description' => 'Workspace handle: repo primary or repo@branch-slug worktree.',
					),
					'from'   => array( 'type' => 'string' ),
					'to'     => array( 'type' => 'string' ),
					'staged' => array( 'type' => 'boolean' ),
					'path'   => array( 'type' => 'string' ),
				),
			),
		),
		'client/git_commit'       => array(
			'capability'   => 'git.commit',
			'ability'      => 'datamachine-code/workspace-git-commit',
			'description'  => 'Commit staged changes in a Data Machine Code workspace handle.',
			'side_effects' => array( 'git.commit' ),
			'parameters'   => array(
				'type'       => 'object',
				'required'   => array( 'name', 'message' ),
				'properties' => array(
					'name'                   => array(
						'type'        => 'string',
						'description' => 'Workspace handle: repo primary or repo@branch-slug worktree.',
					),
					'message'                => array(
						'type'        => 'string',
						'description' => 'Commit message.',
					),
					'allow_primary_mutation' => array(
						'type'        => 'boolean',
						'description' => 'Permit mutation on primary checkout. Worktrees are always allowed.',
					),
				),
			),
		),
		'client/github_pr'        => array(
			'capability'   => 'github.pr',
			'ability'      => 'datamachine-code/create-github-pull-request',
			'description'  => 'Open a GitHub pull request through Data Machine Code GitHub services.',
			'side_effects' => array( 'github.pull_request.create' ),
			'parameters'   => array(
				'type'       => 'object',
				'required'   => array( 'repo', 'title', 'head' ),
				'properties' => array(
					'repo'                  => array( 'type' => 'string' ),
					'title'                 => array( 'type' => 'string' ),
					'head'                  => array( 'type' => 'string' ),
					'base'                  => array( 'type' => 'string' ),
					'body'                  => array( 'type' => 'string' ),
					'draft'                 => array( 'type' => 'boolean' ),
					'labels'                => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'maintainer_can_modify' => array( 'type' => 'boolean' ),
				),
			),
		),
	);

	private static bool $registered = false;

	/**
	 * Register optional Agents API hooks when the substrate is loaded.
	 */
	public static function register(): bool {
		if ( self::$registered ) {
			return true;
		}

		if ( ! self::substrate_exists() ) {
			return false;
		}

		add_filter('agents_api_tool_sources', array( self::class, 'register_tool_source' ), 20, 3);
		add_filter('agents_api_executor_targets', array( self::class, 'register_executor_target' ), 20, 2);
		add_filter('agents_api_execution_targets', array( self::class, 'register_executor_target' ), 20, 2);
		add_filter('agents_api_tool_executors', array( self::class, 'register_tool_executor' ), 20, 2);

		self::$registered = true;
		return true;
	}

	/**
	 * Whether Agents API has the generic tool execution substrate loaded.
	 */
	public static function substrate_exists(): bool {
		return interface_exists('AgentsAPI\\AI\\Tools\\WP_Agent_Tool_Executor')
			&& class_exists('AgentsAPI\\AI\\Tools\\WP_Agent_Tool_Source_Registry');
	}

	/**
	 * Register this adapter as an Agents API tool source.
	 *
	 * @param array<string,callable> $sources Existing sources.
	 * @param array<string,mixed>    $context Runtime context.
	 * @param mixed                  $registry Source registry.
	 * @return array<string,callable>
	 */
	public static function register_tool_source( array $sources, array $context = array(), $registry = null ): array {
		unset( $context, $registry );

		$existing                     = $sources[ self::SOURCE_SLUG ] ?? null;
		$sources[ self::SOURCE_SLUG ] = static function ( array $source_context = array(), $source_registry = null ) use ( $existing ): array {
			$tools = is_callable($existing) ? call_user_func($existing, $source_context, $source_registry) : array();
			if ( ! is_array($tools) ) {
				$tools = array();
			}

			foreach ( self::tool_declarations($source_context, $source_registry) as $tool_name => $tool_declaration ) {
				if ( ! isset($tools[ $tool_name ]) ) {
					$tools[ $tool_name ] = $tool_declaration;
				}
			}

			return $tools;
		};
		return $sources;
	}

	/**
	 * Register this adapter as a future Agents API executor target, when present.
	 *
	 * @param array<string,mixed> $targets Existing targets.
	 * @param array<string,mixed> $context Runtime context.
	 * @return array<string,mixed>
	 */
	public static function register_executor_target( array $targets, array $context = array() ): array {
		unset( $context );

		$targets[ self::TARGET_ID ] = self::target_metadata();
		return $targets;
	}

	/**
	 * Register the tool-call executor adapter for future registry-based dispatch.
	 *
	 * @param array<string,mixed> $executors Existing executors.
	 * @param array<string,mixed> $context Runtime context.
	 * @return array<string,mixed>
	 */
	public static function register_tool_executor( array $executors, array $context = array() ): array {
		$executors[ self::TARGET_ID ] = new class( new self() ) implements \AgentsAPI\AI\Tools\WP_Agent_Tool_Executor {
			public function __construct( private WorkspaceExecutorAdapter $adapter ) {}

			/**
			 * @param array<string,mixed> $tool_call Tool call.
			 * @param array<string,mixed> $tool_definition Tool declaration.
			 * @param array<string,mixed> $context Runtime context.
			 * @return array<string,mixed>
			 */
			public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
				return $this->adapter->executeWP_Agent_Tool_Call($tool_call, $tool_definition, $context);
			}
		};
		return $executors;
	}

	/**
	 * Agents API runtime tool declarations backed by DMC abilities.
	 *
	 * @param array<string,mixed> $context Runtime context.
	 * @param mixed              $registry Source registry.
	 * @return array<string,array<string,mixed>>
	 */
	public static function tool_declarations( array $context = array(), $registry = null ): array {
		unset( $context, $registry );

		$tools = array();
		foreach ( self::TOOL_MAP as $tool_name => $config ) {
			$tools[ $tool_name ] = array(
				'name'        => $tool_name,
				'source'      => self::SOURCE_SLUG,
				'description' => $config['description'],
				'parameters'  => $config['parameters'],
				'executor'    => 'client',
				'scope'       => 'run',
				'runtime'     => array(
					'executor_target'      => self::TARGET_ID,
					'capability'           => $config['capability'],
					'ability'              => $config['ability'],
					'side_effects'         => $config['side_effects'],
					'side_effect_boundary' => 'data-machine-code',
				),
			);
		}

		return $tools;
	}

	/**
	 * Target metadata for generic Agents API executor discovery.
	 *
	 * @return array<string,mixed>
	 */
	public static function target_metadata(): array {
		return array(
			'id'                    => self::TARGET_ID,
			'label'                 => 'Data Machine Code blessed workspace',
			'description'           => 'Optional DMC-backed workspace executor target. Filesystem, Git, and GitHub side effects remain inside Data Machine Code abilities and services.',
			'resource_class'        => 'workspace',
			'required_capabilities' => array_values(
				array_unique(
					array_map(
						static fn( array $config ): string => (string) $config['capability'],
						self::TOOL_MAP
					)
				)
			),
			'side_effect_boundary'  => 'data-machine-code',
			'side_effects'          => array(
				'filesystem.write',
				'git.commit',
				'github.pull_request.create',
			),
		);
	}

	/**
	 * Execute a prepared Agents API tool call through the mapped DMC ability.
	 *
	 * @param array<string,mixed> $tool_call Tool call.
	 * @param array<string,mixed> $tool_definition Tool declaration.
	 * @param array<string,mixed> $context Runtime context.
	 * @return array<string,mixed>
	 */
	public function executeWP_Agent_Tool_Call( array $tool_call, array $tool_definition, array $context = array() ): array {
		unset( $context );

		$started_at  = microtime(true);
		$input_bytes = self::payload_bytes(is_array($tool_call['parameters'] ?? null) ? $tool_call['parameters'] : array());

		$tool_name = (string) ( $tool_call['tool_name'] ?? $tool_definition['name'] ?? '' );
		$config    = self::TOOL_MAP[ $tool_name ] ?? null;
		if ( null === $config ) {
			return self::error_result($tool_name, 'DMC workspace executor does not provide this tool.', 'unsupported_tool', null, $input_bytes, null, $started_at);
		}

		if ( ! function_exists('wp_get_ability') ) {
			return self::error_result($tool_name, 'WordPress Abilities API is not available.', 'abilities_api_unavailable', $config, $input_bytes, null, $started_at);
		}

		$ability = wp_get_ability( (string) $config['ability'] );
		if ( ! is_object($ability) || ! method_exists($ability, 'execute') ) {
			return self::error_result($tool_name, 'Mapped DMC ability is not registered.', 'ability_unavailable', $config, $input_bytes, null, $started_at);
		}

		$ability_started_at = microtime(true);
		$result             = $ability->execute(is_array($tool_call['parameters'] ?? null) ? $tool_call['parameters'] : array());
		$ability_timing_ms  = self::elapsed_ms($ability_started_at);
		if ( function_exists('is_wp_error') && is_wp_error($result) ) {
			return self::error_result(
				$tool_name,
				method_exists($result, 'get_error_message') ? $result->get_error_message() : 'DMC ability failed.',
				method_exists($result, 'get_error_code') ? $result->get_error_code() : 'ability_error',
				$config,
				$input_bytes,
				null,
				$started_at,
				$ability_timing_ms
			);
		}

		return array(
			'success'           => true,
			'tool_name'         => $tool_name,
			'result'            => $result,
			'runtime'           => self::runtime_metadata($config),
			'execution_metrics' => self::execution_metrics($tool_name, $config, $input_bytes, $result, $started_at, null, $ability_timing_ms),
		);
	}

	/**
	 * @param array<string,mixed> $config Tool map config.
	 * @return array<string,mixed>
	 */
	private static function runtime_metadata( array $config ): array {
		return array(
			'executor_target'      => self::TARGET_ID,
			'capability'           => $config['capability'],
			'ability'              => $config['ability'],
			'side_effects'         => $config['side_effects'],
			'side_effect_boundary' => 'data-machine-code',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function error_result(
		string $tool_name,
		string $message,
		string $error_type,
		?array $config = null,
		int $input_bytes = 0,
		$output = null,
		?float $started_at = null,
		?float $ability_timing_ms = null
	): array {
		$runtime = array(
			'executor_target'      => self::TARGET_ID,
			'side_effect_boundary' => 'data-machine-code',
		);
		if ( null !== $config ) {
			$runtime = self::runtime_metadata($config);
		}

		return array(
			'success'           => false,
			'tool_name'         => $tool_name,
			'error'             => $message,
			'error_type'        => $error_type,
			'runtime'           => $runtime,
			'execution_metrics' => self::execution_metrics($tool_name, $config, $input_bytes, $output, $started_at ?? microtime(true), $error_type, $ability_timing_ms),
		);
	}

	/**
	 * Build numeric/classification-only executor metrics for placement policy.
	 *
	 * @param array<string,mixed>|null $config Tool map config.
	 * @param mixed                    $output Raw ability output.
	 * @return array<string,mixed>
	 */
	private static function execution_metrics( string $tool_name, ?array $config, int $input_bytes, $output, float $started_at, ?string $failure_class, ?float $ability_timing_ms ): array {
		$output_bytes = null === $output ? 0 : self::payload_bytes($output);
		$ability_name = null === $config ? '' : (string) $config['ability'];

		$metrics = array(
			'executor_target'      => self::TARGET_ID,
			'tool_name'            => $tool_name,
			'wall_time_ms'         => self::elapsed_ms($started_at),
			'ability_call_count'   => '' === $ability_name || null === $ability_timing_ms ? 0 : 1,
			'ability_timings_ms'   => array(),
			'payload_bytes'        => array(
				'input'  => $input_bytes,
				'output' => $output_bytes,
			),
			'artifacts'            => self::artifact_metrics($output),
			'side_effect_classes'  => null === $config ? array() : self::side_effect_classes($config),
			'side_effect_boundary' => 'data-machine-code',
			'failure_class'        => $failure_class,
		);

		if ( '' !== $ability_name && null !== $ability_timing_ms ) {
			$metrics['ability_timings_ms'][] = array(
				'ability' => $ability_name,
				'ms'      => $ability_timing_ms,
			);
		}

		return $metrics;
	}

	private static function elapsed_ms( float $started_at ): float {
		return round(max(0, microtime(true) - $started_at) * 1000, 3);
	}

	/**
	 * @param mixed $payload Payload to count without retaining raw contents.
	 */
	private static function payload_bytes( $payload ): int {
		$encoded = wp_json_encode($payload);
		if ( false === $encoded ) {
			return 0;
		}

		return strlen($encoded);
	}

	/**
	 * @param array<string,mixed> $config Tool map config.
	 * @return list<string>
	 */
	private static function side_effect_classes( array $config ): array {
		$classes = array();
		foreach ( $config['side_effects'] as $side_effect ) {
			$parts = explode('.', (string) $side_effect, 2);
			if ( '' !== $parts[0] ) {
				$classes[] = $parts[0];
			}
		}

		return array_values(array_unique($classes));
	}

	/**
	 * @param mixed $payload Raw ability output.
	 * @return array{count:int,bytes:int}
	 */
	private static function artifact_metrics( $payload ): array {
		$metrics = array(
			'count' => 0,
			'bytes' => 0,
		);
		self::accumulate_artifact_metrics($payload, $metrics);

		return $metrics;
	}

	/**
	 * @param mixed                      $payload Raw ability output.
	 * @param array{count:int,bytes:int} $metrics Running metrics.
	 */
	private static function accumulate_artifact_metrics( $payload, array &$metrics ): void {
		if ( ! is_array($payload) ) {
			return;
		}

		if ( isset($payload['artifact_size_bytes']) && is_numeric($payload['artifact_size_bytes']) ) {
			++$metrics['count'];
			$metrics['bytes'] += max(0, (int) $payload['artifact_size_bytes']);
		} elseif ( isset($payload['size_bytes']) && is_numeric($payload['size_bytes']) && self::looks_like_artifact_row($payload) ) {
			++$metrics['count'];
			$metrics['bytes'] += max(0, (int) $payload['size_bytes']);
		}

		foreach ( $payload as $value ) {
			self::accumulate_artifact_metrics($value, $metrics);
		}
	}

	/**
	 * @param array<mixed> $row Candidate artifact row.
	 */
	private static function looks_like_artifact_row( array $row ): bool {
		return isset($row['artifact'], $row['size_bytes'])
			|| isset($row['artifact_path'], $row['size_bytes'])
			|| isset($row['path'], $row['size_bytes']);
	}
}
