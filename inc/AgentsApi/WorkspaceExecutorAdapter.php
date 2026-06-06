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

		$tool_name = (string) ( $tool_call['tool_name'] ?? $tool_definition['name'] ?? '' );
		$config    = self::TOOL_MAP[ $tool_name ] ?? null;
		if ( null === $config ) {
			return self::error_result($tool_name, 'DMC workspace executor does not provide this tool.', 'unsupported_tool');
		}

		if ( ! function_exists('wp_get_ability') ) {
			return self::error_result($tool_name, 'WordPress Abilities API is not available.', 'abilities_api_unavailable');
		}

		$ability = wp_get_ability( (string) $config['ability'] );
		if ( ! is_object($ability) || ! method_exists($ability, 'execute') ) {
			return self::error_result($tool_name, 'Mapped DMC ability is not registered.', 'ability_unavailable');
		}

		$result = $ability->execute(is_array($tool_call['parameters'] ?? null) ? $tool_call['parameters'] : array());
		if ( function_exists('is_wp_error') && is_wp_error($result) ) {
			return self::error_result(
				$tool_name,
				method_exists($result, 'get_error_message') ? $result->get_error_message() : 'DMC ability failed.',
				method_exists($result, 'get_error_code') ? $result->get_error_code() : 'ability_error'
			);
		}

		return array(
			'success'   => true,
			'tool_name' => $tool_name,
			'result'    => $result,
			'runtime'   => self::runtime_metadata($config),
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
	private static function error_result( string $tool_name, string $message, string $error_type ): array {
		return array(
			'success'    => false,
			'tool_name'  => $tool_name,
			'error'      => $message,
			'error_type' => $error_type,
			'runtime'    => array(
				'executor_target'      => self::TARGET_ID,
				'side_effect_boundary' => 'data-machine-code',
			),
		);
	}
}
