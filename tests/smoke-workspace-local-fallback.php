<?php
/**
 * Pure-PHP smoke for local workspace fallback when remote state exists.
 *
 * Run: php tests/smoke-workspace-local-fallback.php
 */

declare( strict_types=1 );

namespace DataMachineCode\Abilities {
	class AbilityRegistry
	{
	}

	class GitHubAbilities
	{
	}
}

namespace DataMachineCode\Workspace {
	class RemoteWorkspaceBackend
	{
		public static array $show_input = array();
		public static array $read_input = array();
		public static array $list_input = array();
		public static array $grep_input = array();
		public static array $worktree_input = array();
		public static bool $show_returns_remote_success = false;
		public static bool $read_returns_remote_success = false;
		public static bool $list_returns_remote_success = false;
		public static bool $grep_returns_remote_success = false;
		public static bool $worktree_returns_remote_success = false;

		public static function should_handle(): bool
		{
			return true;
		}

		public function show( string $handle ): array|\WP_Error
		{
			self::$show_input = compact('handle');
			if ( self::$show_returns_remote_success ) {
				return array(
					'success'     => true,
					'name'        => $handle,
					'repo'        => $handle,
					'is_worktree' => str_contains($handle, '@'),
					'path'        => 'github://Automattic/' . str_replace('@', '#', $handle),
				);
			}

			return new \WP_Error(
				'remote_workspace_repo_not_found',
				'Remote workspace repository "wpcom-codebox" is not registered. Call workspace_clone first.'
			);
		}

		public function worktree_add( string $repo_name, string $branch, ?string $from = null ): array|\WP_Error
		{
			self::$worktree_input = compact('repo_name', 'branch', 'from');
			if ( self::$worktree_returns_remote_success ) {
				return array(
					'success' => true,
					'handle'  => $repo_name . '@' . str_replace('/', '-', $branch),
					'path'    => 'github://Automattic/' . $repo_name . '#' . $branch,
				);
			}

			return new \WP_Error(
				'remote_workspace_repo_not_found',
				'Remote workspace repository "wpcom-codebox" is not registered. Call workspace_clone first.'
			);
		}

		public function read_file( string $handle, string $path, int $max_size, ?int $offset = null, ?int $limit = null ): array|\WP_Error
		{
			self::$read_input = compact('handle', 'path', 'max_size', 'offset', 'limit');
			if ( self::$read_returns_remote_success ) {
				return array(
					'success' => true,
					'backend' => 'github_api',
					'content' => 'remote file',
					'path'    => $path,
					'size'    => 11,
				);
			}

			return new \WP_Error(
				'remote_workspace_repo_not_found',
				'Remote workspace repository "wpcom-codebox" is not registered. Call workspace_clone first.'
			);
		}

		public function list_directory( string $handle, ?string $path = null ): array|\WP_Error
		{
			self::$list_input = compact('handle', 'path');
			if ( self::$list_returns_remote_success ) {
				return array(
					'success' => true,
					'backend' => 'github_api',
					'repo'    => $handle,
					'path'    => $path ?? '/',
					'entries' => array(),
				);
			}

			return new \WP_Error(
				'remote_workspace_repo_not_found',
				'Remote workspace repository "wpcom-codebox" is not registered. Call workspace_clone first.'
			);
		}

		public function grep( string $handle, string $pattern, ?string $path = null, ?string $include_pattern = null, int $max_results = 100, int $context_lines = 0 ): array|\WP_Error
		{
			self::$grep_input = compact('handle', 'pattern', 'path', 'include_pattern', 'max_results', 'context_lines');
			if ( self::$grep_returns_remote_success ) {
				return array(
					'success' => true,
					'backend' => 'github_api',
					'repo'    => $handle,
					'path'    => $path ?? '/',
					'pattern' => $pattern,
					'matches' => array(),
					'count'   => 0,
				);
			}

			return new \WP_Error(
				'remote_workspace_repo_not_found',
				'Remote workspace repository "wpcom-codebox" is not registered. Call workspace_clone first.'
			);
		}
	}

	class Workspace
	{
		public static array $show_input = array();
		public static array $worktree_input = array();

		public function show_repo( string $handle ): array
		{
			self::$show_input = compact('handle');
			$parsed = explode('@', $handle, 2);
			$repo   = $parsed[0];
			$slug   = $parsed[1] ?? '';
			$path   = '/Users/chubes/Developer/' . $handle;
			return array(
				'success'     => true,
				'name'        => $handle,
				'repo'        => $repo,
				'is_worktree' => '' !== $slug,
				'path'        => $path,
				'branch'      => '' !== $slug ? str_replace('-', '/', $slug) : 'main',
				'remote'      => 'git@github.a8c.com:Automattic/wpcom-codebox.git',
				'commit'      => 'abc123 listed primary',
				'dirty'       => 0,
			);
		}

		public function worktree_add(
			string $repo,
			string $branch,
			?string $from = null,
			bool $inject_context = true,
			bool $bootstrap = true,
			bool $allow_stale = false,
			bool $rebase_base = false,
			bool $force = false,
			array $task = array()
		): array {
			self::$worktree_input = compact('repo', 'branch', 'from', 'inject_context', 'bootstrap', 'allow_stale', 'rebase_base', 'force', 'task');
			return array(
				'success' => true,
				'handle'  => $repo . '@' . str_replace('/', '-', $branch),
				'path'    => '/Users/chubes/Developer/' . $repo . '@' . str_replace('/', '-', $branch),
			);
		}
	}

	class WorkspaceReader
	{
		public static array $read_input = array();
		public static array $list_input = array();
		public static array $grep_input = array();

		public function __construct( private Workspace $workspace )
		{
		}

		public function read_file( string $name, string $path, int $max_size, ?int $offset = null, ?int $limit = null ): array
		{
			self::$read_input = compact('name', 'path', 'max_size', 'offset', 'limit');
			return array(
				'success' => true,
				'content' => 'local file',
				'path'    => $path,
				'size'    => 10,
			);
		}

		public function list_directory( string $name, ?string $path = null ): array
		{
			self::$list_input = compact('name', 'path');
			return array(
				'success' => true,
				'repo'    => $name,
				'path'    => $path ?? '/',
				'entries' => array(
					array(
						'name' => 'README.md',
						'type' => 'file',
						'size' => 10,
					),
				),
			);
		}

		public function grep( string $name, string $pattern, ?string $path = null, ?string $include_pattern = null, int $max_results = 100, int $context_lines = 0 ): array
		{
			self::$grep_input = compact('name', 'pattern', 'path', 'include_pattern', 'max_results', 'context_lines');
			return array(
				'success' => true,
				'repo'    => $name,
				'path'    => $path ?? '/',
				'pattern' => $pattern,
				'matches' => array(
					array(
						'file' => 'README.md',
						'line' => 1,
						'text' => 'local file',
					),
				),
				'count'   => 1,
			);
		}
	}
}

namespace DataMachineCode\Support {
	class RuntimeCapabilities
	{
	}

	class GitRunner
	{
	}
}

namespace {
	if ( ! defined('ABSPATH') ) {
		define('ABSPATH', __DIR__);
	}

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

	function is_wp_error( $value ): bool
	{
		return $value instanceof WP_Error;
	}

	require __DIR__ . '/../inc/Abilities/WorkspaceAbilities.php';

	$failures = array();
	$assert   = function ( string $label, bool $condition ) use ( &$failures ): void {
		if ( $condition ) {
			echo "  ok {$label}\n";
			return;
		}

		$failures[] = $label;
		echo "  fail {$label}\n";
	};

	echo "Workspace local fallback - smoke\n";

	$show = \DataMachineCode\Abilities\WorkspaceAbilities::showRepo(
		array(
			'name' => 'wpcom-codebox',
		)
	);

	$assert('remote show is not consulted when local primary exists', array() === \DataMachineCode\Workspace\RemoteWorkspaceBackend::$show_input);
	$assert('local show attempted first', 'wpcom-codebox' === ( \DataMachineCode\Workspace\Workspace::$show_input['handle'] ?? '' ));
	$assert('show returns local listed primary', is_array($show) && '/Users/chubes/Developer/wpcom-codebox' === ( $show['path'] ?? '' ));

	\DataMachineCode\Workspace\RemoteWorkspaceBackend::$show_returns_remote_success = true;
	\DataMachineCode\Workspace\RemoteWorkspaceBackend::$show_input = array();
	\DataMachineCode\Workspace\Workspace::$show_input = array();
	$duplicate_show = \DataMachineCode\Abilities\WorkspaceAbilities::showRepo(
		array(
			'name' => 'wpcom-codebox',
		)
	);

	$assert('duplicate remote show is not consulted when local primary exists', array() === \DataMachineCode\Workspace\RemoteWorkspaceBackend::$show_input);
	$assert('duplicate local primary wins show path', is_array($duplicate_show) && '/Users/chubes/Developer/wpcom-codebox' === ( $duplicate_show['path'] ?? '' ));

	$duplicate_path = \DataMachineCode\Abilities\WorkspaceAbilities::getPath(
		array(
			'name' => 'wpcom-codebox@fix-listed-primary-resolution',
		)
	);

	$assert('workspace path returns local worktree when duplicate remote exists', is_array($duplicate_path) && '/Users/chubes/Developer/wpcom-codebox@fix-listed-primary-resolution' === ( $duplicate_path['path'] ?? '' ));
	$assert('workspace path marks local worktree handle as existing', is_array($duplicate_path) && true === ( $duplicate_path['exists'] ?? null ));

	\DataMachineCode\Workspace\RemoteWorkspaceBackend::$read_returns_remote_success = true;
	\DataMachineCode\Workspace\RemoteWorkspaceBackend::$read_input = array();
	\DataMachineCode\Workspace\WorkspaceReader::$read_input = array();
	$read = \DataMachineCode\Abilities\WorkspaceAbilities::readFile(
		array(
			'repo'     => 'wpcom-codebox',
			'path'     => 'README.md',
			'max_size' => 500,
			'offset'   => 2,
			'limit'    => 3,
		)
	);

	$assert('remote read is not consulted when local primary exists', array() === \DataMachineCode\Workspace\RemoteWorkspaceBackend::$read_input);
	$assert('local read attempted for local primary', 'wpcom-codebox' === ( \DataMachineCode\Workspace\WorkspaceReader::$read_input['name'] ?? '' ));
	$assert('read preserves options locally', 500 === ( \DataMachineCode\Workspace\WorkspaceReader::$read_input['max_size'] ?? null ) && 2 === ( \DataMachineCode\Workspace\WorkspaceReader::$read_input['offset'] ?? null ) && 3 === ( \DataMachineCode\Workspace\WorkspaceReader::$read_input['limit'] ?? null ));
	$assert('read returns local file result', is_array($read) && 'local file' === ( $read['content'] ?? '' ));

	\DataMachineCode\Workspace\RemoteWorkspaceBackend::$list_returns_remote_success = true;
	\DataMachineCode\Workspace\RemoteWorkspaceBackend::$list_input = array();
	\DataMachineCode\Workspace\WorkspaceReader::$list_input = array();
	$list = \DataMachineCode\Abilities\WorkspaceAbilities::listDirectory(
		array(
			'repo' => 'wpcom-codebox',
			'path' => 'inc',
		)
	);

	$assert('remote list is not consulted when local primary exists', array() === \DataMachineCode\Workspace\RemoteWorkspaceBackend::$list_input);
	$assert('local list attempted for local primary', 'wpcom-codebox' === ( \DataMachineCode\Workspace\WorkspaceReader::$list_input['name'] ?? '' ));
	$assert('list preserves path locally', 'inc' === ( \DataMachineCode\Workspace\WorkspaceReader::$list_input['path'] ?? '' ));
	$assert('list returns local entries', is_array($list) && 'README.md' === ( $list['entries'][0]['name'] ?? '' ));

	\DataMachineCode\Workspace\RemoteWorkspaceBackend::$grep_returns_remote_success = true;
	\DataMachineCode\Workspace\RemoteWorkspaceBackend::$grep_input = array();
	\DataMachineCode\Workspace\WorkspaceReader::$grep_input = array();
	$grep = \DataMachineCode\Abilities\WorkspaceAbilities::grepFiles(
		array(
			'repo'          => 'wpcom-codebox',
			'pattern'       => 'local',
			'path'          => 'inc',
			'include'       => '*.php',
			'max_results'   => 7,
			'context_lines' => 2,
		)
	);

	$assert('remote grep is not consulted when local primary exists', array() === \DataMachineCode\Workspace\RemoteWorkspaceBackend::$grep_input);
	$assert('local grep attempted for local primary', 'wpcom-codebox' === ( \DataMachineCode\Workspace\WorkspaceReader::$grep_input['name'] ?? '' ));
	$assert('grep preserves options locally', '*.php' === ( \DataMachineCode\Workspace\WorkspaceReader::$grep_input['include_pattern'] ?? '' ) && 7 === ( \DataMachineCode\Workspace\WorkspaceReader::$grep_input['max_results'] ?? null ) && 2 === ( \DataMachineCode\Workspace\WorkspaceReader::$grep_input['context_lines'] ?? null ));
	$assert('grep returns local matches', is_array($grep) && 1 === ( $grep['count'] ?? null ));

	\DataMachineCode\Workspace\RemoteWorkspaceBackend::$worktree_returns_remote_success = true;
	\DataMachineCode\Workspace\RemoteWorkspaceBackend::$worktree_input = array();
	$worktree = \DataMachineCode\Abilities\WorkspaceAbilities::worktreeAdd(
		array(
			'repo'                 => 'wpcom-codebox',
			'branch'               => 'fix/listed-primary-resolution',
			'from'                 => 'origin/main',
			'inject_context'       => false,
			'bootstrap'            => false,
			'allow_stale'          => true,
			'rebase_base'          => true,
			'force'                => true,
			'task_url'             => 'https://github.com/Extra-Chill/data-machine-code/issues/635',
			'task_ref'             => 'Extra-Chill/data-machine-code#635',
		)
	);

	$assert('remote github worktree row is ignored when local primary exists', array() === \DataMachineCode\Workspace\RemoteWorkspaceBackend::$worktree_input);
	$assert('local worktree add fallback attempted', 'wpcom-codebox' === ( \DataMachineCode\Workspace\Workspace::$worktree_input['repo'] ?? '' ));
	$assert('worktree add preserves base ref', 'origin/main' === ( \DataMachineCode\Workspace\Workspace::$worktree_input['from'] ?? '' ));
	$assert('worktree add preserves local options', false === ( \DataMachineCode\Workspace\Workspace::$worktree_input['inject_context'] ?? null ) && true === ( \DataMachineCode\Workspace\Workspace::$worktree_input['allow_stale'] ?? null ));
	$assert('worktree add preserves task metadata', 'Extra-Chill/data-machine-code#635' === ( \DataMachineCode\Workspace\Workspace::$worktree_input['task']['task_ref'] ?? '' ));
	$assert('worktree add returns local worktree result', is_array($worktree) && 'wpcom-codebox@fix-listed-primary-resolution' === ( $worktree['handle'] ?? '' ));
	$assert('worktree add returns editable local path', is_array($worktree) && '/Users/chubes/Developer/wpcom-codebox@fix-listed-primary-resolution' === ( $worktree['path'] ?? '' ));

	if ( $failures ) {
		echo "\nFailures:\n";
		foreach ( $failures as $failure ) {
			echo " - {$failure}\n";
		}
		exit(1);
	}

	echo "\nOK\n";
}
