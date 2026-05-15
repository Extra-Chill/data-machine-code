<?php
/**
 * Workspace Tools — AI agent tools for workspace read operations.
 *
 * Exposes non-mutating workspace capabilities as global tools so pipelines,
 * system agents, and chat agents can inspect repositories safely.
 *
 * @package DataMachineCode\Tools
 * @since   0.1.0
 */

namespace DataMachineCode\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;
use DataMachineCode\Workspace\WorkspaceAliasResolver;

defined( 'ABSPATH' ) || exit;

class WorkspaceTools extends BaseTool {

	/**
	 * Check if workspace tools are configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return (bool) wp_get_ability( 'datamachine/workspace-path' );
	}

	/**
	 * Check if a workspace tool should be considered configured.
	 *
	 * @param bool   $configured Current configuration status.
	 * @param string $tool_id    Tool identifier.
	 * @return bool
	 */
	public function check_configuration( $configured, $tool_id ) {
		$workspace_tools = array(
			'workspace_path',
			'workspace_capabilities',
			'workspace_list',
			'workspace_show',
			'workspace_ls',
			'workspace_read',
			'workspace_grep',
			'workspace_write',
			'workspace_edit',
			'workspace_apply_patch',
			'workspace_delete',
			'workspace_git_status',
			'workspace_git_log',
			'workspace_git_diff',
			'workspace_git_pull',
			'workspace_worktree_add',
			'workspace_git_add',
			'workspace_git_commit',
			'workspace_git_push',
		);

		if ( ! in_array( $tool_id, $workspace_tools, true ) ) {
			return $configured;
		}

		return self::is_configured();
	}

	/**
	 * Constructor — register workspace tools as global tools.
	 */
	public function __construct() {
		$contexts        = array( 'chat', 'pipeline' );
		$policy_contexts = array( 'chat', 'pipeline' );
		$policy_meta     = array( 'requires_opt_in' => true );
		$this->registerTool( 'workspace_path', array( $this, 'getPathDefinition' ), $contexts, array( 'ability' => 'datamachine/workspace-path' ) );
		$this->registerTool( 'workspace_capabilities', array( $this, 'getCapabilitiesDefinition' ), $contexts, array( 'ability' => 'datamachine/workspace-capabilities' ) );
		$this->registerTool( 'workspace_list', array( $this, 'getListDefinition' ), $contexts, array( 'ability' => 'datamachine/workspace-list' ) );
		$this->registerTool( 'workspace_show', array( $this, 'getShowDefinition' ), $contexts, array( 'ability' => 'datamachine/workspace-show' ) );
		$this->registerTool( 'workspace_ls', array( $this, 'getLsDefinition' ), $contexts, array( 'ability' => 'datamachine/workspace-ls' ) );
		$this->registerTool( 'workspace_read', array( $this, 'getReadDefinition' ), $contexts, array( 'ability' => 'datamachine/workspace-read' ) );
		$this->registerTool( 'workspace_grep', array( $this, 'getGrepDefinition' ), $contexts, array( 'ability' => 'datamachine/workspace-grep' ) );
		$this->registerTool( 'workspace_write', array( $this, 'getWriteDefinition' ), $policy_contexts, $policy_meta + array( 'ability' => 'datamachine/workspace-write' ) );
		$this->registerTool( 'workspace_edit', array( $this, 'getEditDefinition' ), $policy_contexts, $policy_meta + array( 'ability' => 'datamachine/workspace-edit' ) );
		$this->registerTool( 'workspace_apply_patch', array( $this, 'getApplyPatchDefinition' ), $policy_contexts, $policy_meta + array( 'ability' => 'datamachine/workspace-apply-patch' ) );
		$this->registerTool( 'workspace_delete', array( $this, 'getDeleteDefinition' ), $policy_contexts, $policy_meta + array( 'ability' => 'datamachine/workspace-delete' ) );
		$this->registerTool( 'workspace_git_status', array( $this, 'getGitStatusDefinition' ), $policy_contexts, $policy_meta + array( 'ability' => 'datamachine/workspace-git-status' ) );
		$this->registerTool( 'workspace_git_log', array( $this, 'getGitLogDefinition' ), $policy_contexts, $policy_meta + array( 'ability' => 'datamachine/workspace-git-log' ) );
		$this->registerTool( 'workspace_git_diff', array( $this, 'getGitDiffDefinition' ), $policy_contexts, $policy_meta + array( 'ability' => 'datamachine/workspace-git-diff' ) );
		$this->registerTool( 'workspace_git_pull', array( $this, 'getGitPullDefinition' ), $policy_contexts, $policy_meta + array( 'ability' => 'datamachine/workspace-git-pull' ) );
		$this->registerTool( 'workspace_worktree_add', array( $this, 'getWorktreeAddDefinition' ), $policy_contexts, $policy_meta + array( 'ability' => 'datamachine/workspace-worktree-add' ) );
		$this->registerTool( 'workspace_git_add', array( $this, 'getGitAddDefinition' ), $policy_contexts, $policy_meta + array( 'ability' => 'datamachine/workspace-git-add' ) );
		$this->registerTool( 'workspace_git_commit', array( $this, 'getGitCommitDefinition' ), $policy_contexts, $policy_meta + array( 'ability' => 'datamachine/workspace-git-commit' ) );
		$this->registerTool( 'workspace_git_push', array( $this, 'getGitPushDefinition' ), $policy_contexts, $policy_meta + array( 'ability' => 'datamachine/workspace-git-push' ) );
	}

	/**
	 * Dispatch tool calls to specific handlers.
	 *
	 * @param array $parameters Tool parameters.
	 * @param array $tool_def   Tool definition with method key.
	 * @return array
	 */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$method = $tool_def['method'] ?? '';

		if ( ! method_exists( $this, $method ) ) {
			return $this->buildErrorResponse( "Unknown workspace tool method: {$method}", 'workspace_tools' );
		}

		return $this->{$method}( $parameters, $tool_def );
	}

	/**
	 * Handle workspace_path tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array
	 */
	public function handlePath( array $parameters ): array {
		$ability = wp_get_ability( 'datamachine/workspace-path' );

		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace path ability not available.', 'workspace_path' );
		}

		$result = $ability->execute(
			array(
				'ensure' => ! empty( $parameters['ensure'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_path' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'workspace_path',
		);
	}

	/**
	 * Handle workspace_capabilities tool call.
	 *
	 * @return array
	 */
	public function handleCapabilities(): array {
		$ability = wp_get_ability( 'datamachine/workspace-capabilities' );

		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace capabilities ability not available.', 'workspace_capabilities' );
		}

		$result = $ability->execute( array() );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_capabilities' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'workspace_capabilities',
		);
	}

	/**
	 * Handle workspace_list tool call.
	 *
	 * @return array
	 */
	public function handleList( array $parameters = array() ): array {
		$ability = wp_get_ability( 'datamachine/workspace-list' );

		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace list ability not available.', 'workspace_list' );
		}

		$input = array();
		if ( isset( $parameters['repo'] ) ) {
			$input['repo'] = (string) $parameters['repo'];
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_list' );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => 'workspace_list',
		);
	}

	/**
	 * Handle workspace_show tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array
	 */
	public function handleShow( array $parameters ): array {
		$ability = wp_get_ability( 'datamachine/workspace-show' );

		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace show ability not available.', 'workspace_show' );
		}

		$input = array( 'name' => $parameters['name'] ?? '' );
		$input = $this->resolveWorkspaceInputAliases( $input, array( 'name' ) );
		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_show' );
		}

		return array(
			'success'   => true,
			'data'      => $this->sanitizeWorkspaceResult( $result, $input ),
			'tool_name' => 'workspace_show',
		);
	}

	/**
	 * Handle workspace_ls tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array
	 */
	public function handleLs( array $parameters ): array {
		$ability = wp_get_ability( 'datamachine/workspace-ls' );

		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace ls ability not available.', 'workspace_ls' );
		}

		$input = $this->resolveWorkspaceInputAliases(
			array(
				'repo' => $parameters['repo'] ?? '',
				'path' => $parameters['path'] ?? '',
			),
			array( 'repo' )
		);
		if ( isset( $input['_workspace_alias_error'] ) ) {
			return $this->buildErrorResponse( (string) $input['_workspace_alias_error'], 'workspace_ls' );
		}
		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_ls' );
		}

		return array(
			'success'   => true,
			'data'      => $this->sanitizeWorkspaceResult( $result, $input ),
			'tool_name' => 'workspace_ls',
		);
	}

	/**
	 * Handle workspace_read tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array
	 */
	public function handleRead( array $parameters ): array {
		$ability = wp_get_ability( 'datamachine/workspace-read' );

		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace read ability not available.', 'workspace_read' );
		}

		$input = array(
			'repo' => $parameters['repo'] ?? '',
			'path' => $parameters['path'] ?? '',
		);
		$input = $this->resolveWorkspaceInputAliases( $input, array( 'repo' ) );
		if ( isset( $input['_workspace_alias_error'] ) ) {
			return $this->buildErrorResponse( (string) $input['_workspace_alias_error'], 'workspace_read' );
		}

		if ( isset( $parameters['max_size'] ) ) {
			$input['max_size'] = (int) $parameters['max_size'];
		}

		if ( isset( $parameters['offset'] ) ) {
			$input['offset'] = (int) $parameters['offset'];
		}

		if ( isset( $parameters['limit'] ) ) {
			$input['limit'] = (int) $parameters['limit'];
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_read' );
		}

		return array(
			'success'   => true,
			'data'      => $this->sanitizeWorkspaceResult( $result, $input ),
			'tool_name' => 'workspace_read',
		);
	}

	/**
	 * Handle workspace_grep tool call.
	 *
	 * @param array $parameters Tool parameters.
	 * @return array
	 */
	public function handleGrep( array $parameters ): array {
		$ability = wp_get_ability( 'datamachine/workspace-grep' );

		if ( ! $ability ) {
			return $this->buildErrorResponse( 'Workspace grep ability not available.', 'workspace_grep' );
		}

		$input = array(
			'repo'    => $parameters['repo'] ?? '',
			'pattern' => $parameters['pattern'] ?? '',
		);

		foreach ( array( 'path', 'include' ) as $key ) {
			if ( isset( $parameters[ $key ] ) ) {
				$input[ $key ] = $parameters[ $key ];
			}
		}
		$input = $this->resolveWorkspaceInputAliases( $input, array( 'repo' ) );
		if ( isset( $input['_workspace_alias_error'] ) ) {
			return $this->buildErrorResponse( (string) $input['_workspace_alias_error'], 'workspace_grep' );
		}

		foreach ( array( 'max_results', 'context_lines' ) as $key ) {
			if ( isset( $parameters[ $key ] ) ) {
				$input[ $key ] = (int) $parameters[ $key ];
			}
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), 'workspace_grep' );
		}

		return array(
			'success'   => true,
			'data'      => $this->sanitizeWorkspaceResult( $result, $input ),
			'tool_name' => 'workspace_grep',
		);
	}

	/** @param array<string,mixed> $parameters Tool parameters. @return array<string,mixed> */
	public function handleWrite( array $parameters ): array {
		return $this->executeAbility( 'datamachine/workspace-write', 'workspace_write', array(
			'repo'    => $parameters['repo'] ?? '',
			'path'    => $parameters['path'] ?? '',
			'content' => $parameters['content'] ?? '',
		), array( 'repo' ) );
	}

	/** @param array<string,mixed> $parameters Tool parameters. @return array<string,mixed> */
	public function handleEdit( array $parameters ): array {
		$input = array(
			'repo'       => $parameters['repo'] ?? '',
			'path'       => $parameters['path'] ?? '',
			'old_string' => $parameters['old_string'] ?? '',
			'new_string' => $parameters['new_string'] ?? '',
		);

		if ( array_key_exists( 'replace_all', $parameters ) ) {
			$input['replace_all'] = (bool) $parameters['replace_all'];
		}

		return $this->executeAbility( 'datamachine/workspace-edit', 'workspace_edit', $input, array( 'repo' ) );
	}

	/** @param array<string,mixed> $parameters Tool parameters. @return array<string,mixed> */
	public function handleApplyPatch( array $parameters ): array {
		$input = array(
			'repo'  => $parameters['repo'] ?? '',
			'patch' => $parameters['patch'] ?? '',
		);

		if ( array_key_exists( 'allow_primary_mutation', $parameters ) ) {
			$input['allow_primary_mutation'] = (bool) $parameters['allow_primary_mutation'];
		}

		return $this->executeAbility( 'datamachine/workspace-apply-patch', 'workspace_apply_patch', $input, array( 'repo' ) );
	}

	/** @param array<string,mixed> $parameters Tool parameters. @return array<string,mixed> */
	public function handleDelete( array $parameters ): array {
		$input = array(
			'repo' => $parameters['repo'] ?? '',
			'path' => $parameters['path'] ?? '',
		);

		foreach ( array( 'recursive', 'allow_primary_mutation' ) as $key ) {
			if ( array_key_exists( $key, $parameters ) ) {
				$input[ $key ] = (bool) $parameters[ $key ];
			}
		}

		return $this->executeAbility( 'datamachine/workspace-delete', 'workspace_delete', $input, array( 'repo' ) );
	}

	/** @param array<string,mixed> $parameters Tool parameters. @return array<string,mixed> */
	public function handleGitStatus( array $parameters ): array {
		return $this->executeAbility( 'datamachine/workspace-git-status', 'workspace_git_status', array( 'name' => $parameters['name'] ?? $parameters['repo'] ?? '' ), array( 'name' ) );
	}

	/** @param array<string,mixed> $parameters Tool parameters. @return array<string,mixed> */
	public function handleGitLog( array $parameters ): array {
		$input = array( 'name' => $parameters['name'] ?? $parameters['repo'] ?? '' );
		if ( isset( $parameters['limit'] ) ) {
			$input['limit'] = (int) $parameters['limit'];
		}

		return $this->executeAbility( 'datamachine/workspace-git-log', 'workspace_git_log', $input, array( 'name' ) );
	}

	/** @param array<string,mixed> $parameters Tool parameters. @return array<string,mixed> */
	public function handleGitDiff( array $parameters ): array {
		$input = array( 'name' => $parameters['name'] ?? $parameters['repo'] ?? '' );
		foreach ( array( 'from', 'to', 'path' ) as $key ) {
			if ( isset( $parameters[ $key ] ) ) {
				$input[ $key ] = $parameters[ $key ];
			}
		}
		if ( array_key_exists( 'staged', $parameters ) ) {
			$input['staged'] = (bool) $parameters['staged'];
		}

		return $this->executeAbility( 'datamachine/workspace-git-diff', 'workspace_git_diff', $input, array( 'name' ) );
	}

	/** @param array<string,mixed> $parameters Tool parameters. @return array<string,mixed> */
	public function handleGitPull( array $parameters ): array {
		$input = array( 'name' => $parameters['name'] ?? $parameters['repo'] ?? '' );
		foreach ( array( 'allow_dirty', 'allow_primary_mutation' ) as $key ) {
			if ( array_key_exists( $key, $parameters ) ) {
				$input[ $key ] = (bool) $parameters[ $key ];
			}
		}

		return $this->executeAbility( 'datamachine/workspace-git-pull', 'workspace_git_pull', $input, array( 'name' ) );
	}

	/** @param array<string,mixed> $parameters Tool parameters. @return array<string,mixed> */
	public function handleWorktreeAdd( array $parameters ): array {
		$input = array(
			'repo'   => $parameters['repo'] ?? '',
			'branch' => $parameters['branch'] ?? '',
		);

		foreach ( array( 'from', 'task_url', 'task_ref' ) as $key ) {
			if ( isset( $parameters[ $key ] ) ) {
				$input[ $key ] = $parameters[ $key ];
			}
		}

		foreach ( array( 'inject_context', 'bootstrap', 'allow_stale', 'rebase_base', 'force' ) as $key ) {
			if ( array_key_exists( $key, $parameters ) ) {
				$input[ $key ] = (bool) $parameters[ $key ];
			}
		}

		return $this->executeAbility( 'datamachine/workspace-worktree-add', 'workspace_worktree_add', $input, array( 'repo' ) );
	}

	/** @param array<string,mixed> $parameters Tool parameters. @return array<string,mixed> */
	public function handleGitAdd( array $parameters ): array {
		$input = array(
			'name'  => $parameters['name'] ?? $parameters['repo'] ?? '',
			'paths' => isset( $parameters['paths'] ) && is_array( $parameters['paths'] ) ? $parameters['paths'] : array(),
		);
		if ( array_key_exists( 'allow_primary_mutation', $parameters ) ) {
			$input['allow_primary_mutation'] = (bool) $parameters['allow_primary_mutation'];
		}

		return $this->executeAbility( 'datamachine/workspace-git-add', 'workspace_git_add', $input, array( 'name' ) );
	}

	/** @param array<string,mixed> $parameters Tool parameters. @return array<string,mixed> */
	public function handleGitCommit( array $parameters ): array {
		$input = array(
			'name'    => $parameters['name'] ?? $parameters['repo'] ?? '',
			'message' => $parameters['message'] ?? '',
		);
		if ( array_key_exists( 'allow_primary_mutation', $parameters ) ) {
			$input['allow_primary_mutation'] = (bool) $parameters['allow_primary_mutation'];
		}

		return $this->executeAbility( 'datamachine/workspace-git-commit', 'workspace_git_commit', $input, array( 'name' ) );
	}

	/** @param array<string,mixed> $parameters Tool parameters. @return array<string,mixed> */
	public function handleGitPush( array $parameters ): array {
		$input = array( 'name' => $parameters['name'] ?? $parameters['repo'] ?? '' );
		foreach ( array( 'remote', 'branch' ) as $key ) {
			if ( isset( $parameters[ $key ] ) ) {
				$input[ $key ] = $parameters[ $key ];
			}
		}
		if ( array_key_exists( 'allow_primary_mutation', $parameters ) ) {
			$input['allow_primary_mutation'] = (bool) $parameters['allow_primary_mutation'];
		}

		return $this->executeAbility( 'datamachine/workspace-git-push', 'workspace_git_push', $input, array( 'name' ) );
	}

	/** @param array<string,mixed> $input Ability input. @return array<string,mixed> */
	private function executeAbility( string $ability_name, string $tool_name, array $input, array $handle_keys = array() ): array {
		$ability = wp_get_ability( $ability_name );
		if ( ! $ability ) {
			return $this->buildErrorResponse( "{$tool_name} ability not available.", $tool_name );
		}

		$input = $this->resolveWorkspaceInputAliases( $input, $handle_keys );
		if ( isset( $input['_workspace_alias_error'] ) ) {
			return $this->buildErrorResponse( (string) $input['_workspace_alias_error'], $tool_name );
		}

		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), $tool_name );
		}

		return array(
			'success'   => true,
			'data'      => $this->sanitizeWorkspaceResult( $result, $input ),
			'tool_name' => $tool_name,
		);
	}

	/** @param array<string,mixed> $input Tool input. @param string[] $handle_keys Keys that hold workspace handles. @return array<string,mixed> */
	private function resolveWorkspaceInputAliases( array $input, array $handle_keys ): array {
		foreach ( $handle_keys as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) ) {
				continue;
			}

			$alias = $input[ $key ];
			$spec  = WorkspaceAliasResolver::spec( $alias );
			if ( null === $spec ) {
				continue;
			}

			$real                      = $spec['target'];
			$root                      = $spec['root'];
			$input[ $key ]             = $real;
			$input['_workspace_alias'] = $alias;
			$input['_workspace_handle'] = $real;
			$input['_workspace_root']   = $root;

			$scoped = $this->scopeWorkspacePaths( $input, $root );
			if ( is_string( $scoped ) ) {
				$input['_workspace_alias_error'] = $scoped;
			} else {
				$input = $scoped;
			}
		}

		return $input;
	}

	/** @param mixed $result Ability result. @param array<string,mixed> $input Resolved ability input. @return mixed */
	private function sanitizeWorkspaceResult( mixed $result, array $input ): mixed {
		$alias  = isset( $input['_workspace_alias'] ) ? (string) $input['_workspace_alias'] : '';
		$handle = isset( $input['_workspace_handle'] ) ? (string) $input['_workspace_handle'] : '';
		$root   = isset( $input['_workspace_root'] ) ? (string) $input['_workspace_root'] : '';
		if ( '' === $alias || '' === $handle ) {
			return $result;
		}

		return WorkspaceAliasResolver::sanitize_result( $result, $alias, $handle, $root );
	}

	/** @param array<string,mixed> $input Resolved ability input. @return array<string,mixed>|string */
	private function scopeWorkspacePaths( array $input, string $root ): array|string {
		if ( '' === $root ) {
			return $input;
		}

		if ( array_key_exists( 'pattern', $input ) && ! array_key_exists( 'path', $input ) ) {
			$input['path'] = '';
		}

		foreach ( array( 'path' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$scoped = WorkspaceAliasResolver::scope_path( (string) $input[ $key ], $root );
				if ( false === $scoped ) {
					return 'Path is outside the scoped workspace.';
				}
				$input[ $key ] = $scoped;
			}
		}

		if ( array_key_exists( 'paths', $input ) && is_array( $input['paths'] ) ) {
			$paths = array();
			foreach ( $input['paths'] as $path ) {
				$scoped = WorkspaceAliasResolver::scope_path( (string) $path, $root );
				if ( false === $scoped ) {
					return 'Path is outside the scoped workspace.';
				}
				$paths[] = $scoped;
			}
			$input['paths'] = $paths;
		}

		if ( isset( $input['patch'] ) && is_string( $input['patch'] ) ) {
			$patch = $this->scopeWorkspacePatch( $input['patch'], $root );
			if ( false === $patch ) {
				return 'Patch contains a path outside the scoped workspace.';
			}
			$input['patch'] = $patch;
		}

		return $input;
	}

	private function scopeWorkspacePatch( string $patch, string $root ): string|false {
		$lines = explode( "\n", $patch );
		foreach ( $lines as &$line ) {
			if ( preg_match( '/^(diff --git) a\/(.+) b\/(.+)$/', $line, $matches ) ) {
				$from = WorkspaceAliasResolver::scope_path( $matches[2], $root );
				$to   = WorkspaceAliasResolver::scope_path( $matches[3], $root );
				if ( false === $from || false === $to ) {
					return false;
				}
				$line = $matches[1] . ' a/' . $from . ' b/' . $to;
				continue;
			}

			if ( preg_match( '/^(---|\+\+\+) ([ab])\/(.+)$/', $line, $matches ) ) {
				$path = WorkspaceAliasResolver::scope_path( $matches[3], $root );
				if ( false === $path ) {
					return false;
				}
				$line = $matches[1] . ' ' . $matches[2] . '/' . $path;
			}
		}
		unset( $line );

		return implode( "\n", $lines );
	}

	/**
	 * Primary tool definition for convention compatibility.
	 *
	 * @return array
	 */
	public function getToolDefinition(): array {
		return $this->getPathDefinition();
	}

	/**
	 * Tool definition for workspace_path.
	 *
	 * @return array
	 */
	public function getPathDefinition(): array {
		return $this->repeatableDefinition( array(
			'class'       => __CLASS__,
			'method'      => 'handlePath',
			'description' => 'Get the Data Machine workspace path. Optionally ensure it exists.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'ensure' => array(
						'type'        => 'boolean',
						'description' => 'Create the workspace directory if it does not exist (default false).',
					),
				),
				'required'   => array(),
			),
		) );
	}

	/**
	 * Get workspace_capabilities tool definition.
	 *
	 * @return array Tool definition.
	 */
	public function getCapabilitiesDefinition(): array {
		return $this->repeatableDefinition( array(
			'class'       => self::class,
			'method'      => 'handleCapabilities',
			'description' => 'Inspect whether the current Data Machine Code workspace backend can run local git operations in this runtime.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'include_diagnostics' => array(
						'type'        => 'boolean',
						'description' => 'Include workspace backend diagnostics. Defaults to true.',
					),
				),
				'required'   => array(),
			),
		) );
	}

	/**
	 * Tool definition for workspace_list.
	 *
	 * @return array
	 */
	public function getListDefinition(): array {
		return $this->repeatableDefinition( array(
			'class'       => __CLASS__,
			'method'      => 'handleList',
			'description' => 'List repositories currently present in the Data Machine workspace.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'repo' => array(
						'type'        => 'string',
						'description' => 'Optional primary repository name to filter by. Includes the primary checkout and its worktrees.',
					),
				),
				'required'   => array(),
			),
		) );
	}

	/**
	 * Tool definition for workspace_show.
	 *
	 * @return array
	 */
	public function getShowDefinition(): array {
		return $this->repeatableDefinition( array(
			'class'       => __CLASS__,
			'method'      => 'handleShow',
			'description' => 'Show detailed information about a workspace repository (branch, remote, latest commit, dirty count).',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'name' => array(
						'type'        => 'string',
						'description' => 'Workspace repository directory name.',
					),
				),
				'required'   => array( 'name' ),
			),
		) );
	}

	/**
	 * Tool definition for workspace_ls.
	 *
	 * @return array
	 */
	public function getLsDefinition(): array {
		return $this->repeatableDefinition( array(
			'class'       => __CLASS__,
			'method'      => 'handleLs',
			'description' => 'List directory contents within a workspace repository.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'repo' => array(
						'type'        => 'string',
						'description' => 'Workspace repository directory name.',
					),
					'path' => array(
						'type'        => 'string',
						'description' => 'Optional relative directory path inside the repo.',
					),
				),
				'required'   => array( 'repo' ),
			),
		) );
	}

	/**
	 * Tool definition for workspace_read.
	 *
	 * @return array
	 */
	public function getReadDefinition(): array {
		return $this->repeatableDefinition( array(
			'class'       => __CLASS__,
			'method'      => 'handleRead',
			'description' => 'Read a text file from a workspace repository. Supports optional max_size, offset, and limit for large files.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'repo'     => array(
						'type'        => 'string',
						'description' => 'Workspace repository directory name.',
					),
					'path'     => array(
						'type'        => 'string',
						'description' => 'Relative file path inside the repository.',
					),
					'max_size' => array(
						'type'        => 'integer',
						'description' => 'Maximum readable size in bytes (default 1MB).',
					),
					'offset'   => array(
						'type'        => 'integer',
						'description' => 'Line offset to start reading from (1-indexed).',
					),
					'limit'    => array(
						'type'        => 'integer',
						'description' => 'Maximum number of lines to return.',
					),
				),
				'required'   => array( 'repo', 'path' ),
			),
		) );
	}

	/**
	 * Tool definition for workspace_grep.
	 *
	 * @return array
	 */
	public function getGrepDefinition(): array {
		return $this->repeatableDefinition( array(
			'class'       => __CLASS__,
			'method'      => 'handleGrep',
			'description' => 'Search text files in a workspace repository using a regular expression pattern.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'repo'          => array(
						'type'        => 'string',
						'description' => 'Workspace repository directory name or worktree handle.',
					),
					'pattern'       => array(
						'type'        => 'string',
						'description' => 'Regular expression pattern to search for.',
					),
					'path'          => array(
						'type'        => 'string',
						'description' => 'Optional relative file or directory path inside the repository.',
					),
					'include'       => array(
						'type'        => 'string',
						'description' => 'Optional glob pattern to limit matching file paths.',
					),
					'max_results'   => array(
						'type'        => 'integer',
						'description' => 'Maximum number of matches to return (default 100, max 500).',
					),
					'context_lines' => array(
						'type'        => 'integer',
						'description' => 'Number of surrounding lines to include for each match (default 0, max 10).',
					),
				),
				'required'   => array( 'repo', 'pattern' ),
			),
		) );
	}

	/** @return array<string,mixed> */
	public function getWriteDefinition(): array {
		return $this->progressDefinition( array(
			'class'       => __CLASS__,
			'method'      => 'handleWrite',
			'description' => 'Create or overwrite a file in a workspace repository. Policy-gated for pipeline use.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'repo'    => array( 'type' => 'string', 'description' => 'Workspace handle: <repo> or <repo>@<branch-slug>.' ),
					'path'    => array( 'type' => 'string', 'description' => 'Relative file path within the repo.' ),
					'content' => array( 'type' => 'string', 'description' => 'File content to write.' ),
				),
				'required'   => array( 'repo', 'path', 'content' ),
			),
		) );
	}

	/** @return array<string,mixed> */
	public function getEditDefinition(): array {
		return $this->progressDefinition( array(
			'class'       => __CLASS__,
			'method'      => 'handleEdit',
			'description' => 'Find-and-replace exact text in a workspace repository file. Policy-gated for pipeline use.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'repo'        => array( 'type' => 'string', 'description' => 'Workspace handle: <repo> or <repo>@<branch-slug>.' ),
					'path'        => array( 'type' => 'string', 'description' => 'Relative file path within the repo.' ),
					'old_string'  => array( 'type' => 'string', 'description' => 'Exact text to find.' ),
					'new_string'  => array( 'type' => 'string', 'description' => 'Replacement text.' ),
					'replace_all' => array( 'type' => 'boolean', 'description' => 'Replace all occurrences. Default false.' ),
				),
				'required'   => array( 'repo', 'path', 'old_string', 'new_string' ),
			),
		) );
	}

	/** @return array<string,mixed> */
	public function getApplyPatchDefinition(): array {
		return $this->progressDefinition( array(
			'class'       => __CLASS__,
			'method'      => 'handleApplyPatch',
			'description' => 'Apply a unified diff to a workspace repository using git apply checks. Policy-gated for pipeline use.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'repo'                   => array( 'type' => 'string', 'description' => 'Workspace handle: <repo> or <repo>@<branch-slug>.' ),
					'patch'                 => array( 'type' => 'string', 'description' => 'Unified diff content to apply.' ),
					'allow_primary_mutation' => array( 'type' => 'boolean', 'description' => 'Permit mutation on a primary checkout. Default false.' ),
				),
				'required'   => array( 'repo', 'patch' ),
			),
		) );
	}

	/** @return array<string,mixed> */
	public function getDeleteDefinition(): array {
		return $this->progressDefinition( array(
			'class'       => __CLASS__,
			'method'      => 'handleDelete',
			'description' => 'Delete a tracked or untracked path from a workspace repository. Policy-gated for pipeline use.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'repo'                   => array( 'type' => 'string', 'description' => 'Workspace handle: <repo> or <repo>@<branch-slug>.' ),
					'path'                   => array( 'type' => 'string', 'description' => 'Relative path within the repo.' ),
					'recursive'              => array( 'type' => 'boolean', 'description' => 'Required when target is a directory. Default false.' ),
					'allow_primary_mutation' => array( 'type' => 'boolean', 'description' => 'Permit mutation on a primary checkout. Default false.' ),
				),
				'required'   => array( 'repo', 'path' ),
			),
		) );
	}

	/** @return array<string,mixed> */
	public function getGitStatusDefinition(): array {
		return $this->simpleGitDefinition( 'handleGitStatus', 'Get git status information for a workspace handle.', array(), array( 'name' ), array( 'duplicate_policy' => 'repeatable' ) );
	}

	/** @return array<string,mixed> */
	public function getGitLogDefinition(): array {
		return $this->simpleGitDefinition( 'handleGitLog', 'Read git log entries for a workspace handle.', array(
			'limit' => array( 'type' => 'integer', 'description' => 'Maximum log entries to return.' ),
		), array( 'name' ), array( 'duplicate_policy' => 'repeatable' ) );
	}

	/** @return array<string,mixed> */
	public function getGitDiffDefinition(): array {
		return $this->simpleGitDefinition( 'handleGitDiff', 'Read git diff output for a workspace handle.', array(
			'from'   => array( 'type' => 'string', 'description' => 'Optional from git ref.' ),
			'to'     => array( 'type' => 'string', 'description' => 'Optional to git ref.' ),
			'staged' => array( 'type' => 'boolean', 'description' => 'Read staged diff instead of working tree diff.' ),
			'path'   => array( 'type' => 'string', 'description' => 'Optional relative path filter.' ),
		), array( 'name' ), array( 'duplicate_policy' => 'repeatable' ) );
	}

	/** @return array<string,mixed> */
	public function getGitPullDefinition(): array {
		return $this->simpleGitDefinition( 'handleGitPull', 'Run git pull --ff-only for a workspace handle. Policy-gated for pipeline use.', array(
			'allow_dirty'            => array( 'type' => 'boolean', 'description' => 'Allow pull when working tree is dirty. Default false.' ),
			'allow_primary_mutation' => array( 'type' => 'boolean', 'description' => 'Permit mutation on a primary checkout. Default false.' ),
		), array( 'name' ), array( 'completion_signal' => 'progress' ) );
	}

	/** @return array<string,mixed> */
	public function getWorktreeAddDefinition(): array {
		return $this->progressDefinition( array(
			'class'       => __CLASS__,
			'method'      => 'handleWorktreeAdd',
			'description' => 'Create a git worktree for a workspace repository branch. Policy-gated for pipeline use.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'repo'           => array( 'type' => 'string', 'description' => 'Primary workspace repo name, without an @ worktree suffix.' ),
					'branch'         => array( 'type' => 'string', 'description' => 'Branch to check out in the worktree, for example fix/foo-bar.' ),
					'from'           => array( 'type' => 'string', 'description' => 'Base ref when creating the branch. Defaults to origin/HEAD.' ),
					'inject_context' => array( 'type' => 'boolean', 'description' => 'Inject originating agent context into the worktree. Default true.' ),
					'bootstrap'      => array( 'type' => 'boolean', 'description' => 'Run detected bootstrap steps after creation. Default true.' ),
					'allow_stale'    => array( 'type' => 'boolean', 'description' => 'Bypass the staleness gate. Default false.' ),
					'rebase_base'    => array( 'type' => 'boolean', 'description' => 'Rebase the worktree onto the upstream tip after creation. Default false.' ),
					'force'          => array( 'type' => 'boolean', 'description' => 'Bypass disk-budget refusal threshold. Default false.' ),
					'task_url'       => array( 'type' => 'string', 'description' => 'Optional task or issue URL to record on the worktree.' ),
					'task_ref'       => array( 'type' => 'string', 'description' => 'Optional short task or issue reference to record on the worktree.' ),
				),
				'required'   => array( 'repo', 'branch' ),
			),
		) );
	}

	/** @return array<string,mixed> */
	public function getGitAddDefinition(): array {
		return $this->simpleGitDefinition( 'handleGitAdd', 'Stage repository paths with git add. Policy-gated for pipeline use.', array(
			'paths'                  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Relative paths to stage.' ),
			'allow_primary_mutation' => array( 'type' => 'boolean', 'description' => 'Permit mutation on a primary checkout. Default false.' ),
		), array( 'name', 'paths' ), array( 'completion_signal' => 'progress' ) );
	}

	/** @return array<string,mixed> */
	public function getGitCommitDefinition(): array {
		return $this->simpleGitDefinition( 'handleGitCommit', 'Commit staged changes in a workspace handle. Policy-gated for pipeline use.', array(
			'message'                => array( 'type' => 'string', 'description' => 'Commit message.' ),
			'allow_primary_mutation' => array( 'type' => 'boolean', 'description' => 'Permit mutation on a primary checkout. Default false.' ),
		), array( 'name', 'message' ), array( 'completion_signal' => 'progress' ) );
	}

	/** @return array<string,mixed> */
	public function getGitPushDefinition(): array {
		return $this->simpleGitDefinition( 'handleGitPush', 'Push commits for a workspace handle. Policy-gated for pipeline use.', array(
			'remote'                 => array( 'type' => 'string', 'description' => 'Remote name. Default origin.' ),
			'branch'                 => array( 'type' => 'string', 'description' => 'Branch override.' ),
			'allow_primary_mutation' => array( 'type' => 'boolean', 'description' => 'Permit mutation on a primary checkout. Default false.' ),
		), array( 'name' ), array( 'completion_signal' => 'progress' ) );
	}

	/** @param array<string,mixed> $extra_properties Extra parameters. @param string[] $required Required properties. @return array<string,mixed> */
	private function simpleGitDefinition( string $method, string $description, array $extra_properties, array $required = array( 'name' ), array $runtime = array() ): array {
		return $this->withRuntime( array(
			'class'       => __CLASS__,
			'method'      => $method,
			'description' => $description,
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array_merge(
					array(
						'name' => array( 'type' => 'string', 'description' => 'Workspace handle: <repo> or <repo>@<branch-slug>.' ),
						'repo' => array( 'type' => 'string', 'description' => 'Alias for name.' ),
					),
					$extra_properties
				),
				'required'   => $required,
			),
		), $runtime );
	}

	/** @param array<string,mixed> $definition Tool definition. @return array<string,mixed> */
	private function repeatableDefinition( array $definition ): array {
		return $this->withRuntime( $definition, array( 'duplicate_policy' => 'repeatable' ) );
	}

	/** @param array<string,mixed> $definition Tool definition. @return array<string,mixed> */
	private function progressDefinition( array $definition ): array {
		return $this->withRuntime( $definition, array( 'completion_signal' => 'progress' ) );
	}

	/** @param array<string,mixed> $definition Tool definition. @param array<string,mixed> $runtime Runtime metadata. @return array<string,mixed> */
	private function withRuntime( array $definition, array $runtime ): array {
		if ( empty( $runtime ) ) {
			return $definition;
		}

		$definition['runtime'] = array_merge( is_array( $definition['runtime'] ?? null ) ? $definition['runtime'] : array(), $runtime );
		return $definition;
	}
}
