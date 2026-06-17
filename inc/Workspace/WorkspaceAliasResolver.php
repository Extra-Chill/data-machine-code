<?php
/**
 * Workspace alias resolver for agent-facing tool calls.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

require_once __DIR__ . '/WorkspaceHandle.php';

class WorkspaceAliasResolver {



	private const OPTION         = 'datamachine_code_workspace_aliases';
	private const CONTEXT_OPTION = 'datamachine_code_context_repositories';

	/**
	 * Return normalized alias specs keyed by agent-facing alias.
	 *
	 * Runners can persist aliases in the option, or inject them just-in-time via
	 * the filter when a single conversation should see an opaque project name.
	 *
	 * @return array<string,array{target:string,root:string,access:string,is_context:bool,repo:string,ref:string,paths:array<int,string>}>
	 */
	public static function aliases(): array {
		$aliases = function_exists('get_option') ? get_option(self::OPTION, array()) : array();
		if ( ! is_array($aliases) ) {
			$aliases = array();
		}

		if ( function_exists('apply_filters') ) {
			$aliases = apply_filters('datamachine_code_workspace_aliases', $aliases);
		}

		foreach ( self::context_repositories() as $alias => $context ) {
			$aliases[ $alias ] = array_merge(
				$context,
				array(
					'target'     => (string) ( $context['target'] ?? $alias ),
					'access'     => 'read_only',
					'is_context' => true,
				)
			);
		}

		$normalized = array();
		foreach ( $aliases as $alias => $spec ) {
			$alias = trim( (string) $alias);
			if ( is_array($spec) ) {
				$handle = trim( (string) ( $spec['target'] ?? $spec['handle'] ?? $spec['name'] ?? '' ));
				$root   = self::normalize_root( (string) ( $spec['root'] ?? $spec['agent_root'] ?? $spec['path_prefix'] ?? '' ));
			} else {
				$handle = trim( (string) $spec);
				$root   = '';
			}

			if ( '' === $alias || '' === $handle ) {
				continue;
			}
			$normalized[ $alias ] = array(
				'target'     => $handle,
				'root'       => $root,
				'access'     => is_array($spec) ? (string) ( $spec['access'] ?? 'read_write' ) : 'read_write',
				'is_context' => is_array($spec) && ! empty($spec['is_context']),
				'repo'       => is_array($spec) ? (string) ( $spec['repo'] ?? '' ) : '',
				'ref'        => is_array($spec) ? (string) ( $spec['ref'] ?? '' ) : '',
				'paths'      => is_array($spec) ? self::normalize_paths($spec['paths'] ?? array()) : array(),
			);
		}

		return $normalized;
	}

	public static function resolve( string $handle ): string {
		$aliases = self::aliases();
		return $aliases[ $handle ]['target'] ?? $handle;
	}

	/**
	 * Return the normalized alias spec for an agent-facing handle.
	 *
	 * @return array{target:string,root:string,access:string,is_context:bool,repo:string,ref:string,paths:array<int,string>}|null
	 */
	public static function spec( string $handle ): ?array {
		return self::aliases()[ $handle ] ?? null;
	}

	public static function alias_for( string $handle ): ?string {
		foreach ( self::aliases() as $alias => $spec ) {
			if ( $spec['target'] === $handle ) {
				return $alias;
			}
		}

		return null;
	}

	public static function has_alias( string $handle ): bool {
		return isset(self::aliases()[ $handle ]);
	}

	public static function root_for( string $handle ): string {
		return self::aliases()[ $handle ]['root'] ?? '';
	}

	/**
	 * Return normalized read-only context repository specs keyed by alias.
	 *
	 * Runners can persist the list in `datamachine_code_context_repositories` or
	 * inject it per run via the `datamachine_code_context_repositories` filter.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function context_repositories(): array {
		$contexts = function_exists('get_option') ? get_option(self::CONTEXT_OPTION, array()) : array();
		if ( ! is_array($contexts) ) {
			$contexts = array();
		}

		if ( function_exists('apply_filters') ) {
			$contexts = apply_filters('datamachine_code_context_repositories', $contexts);
		}

		$normalized = array();
		foreach ( $contexts as $key => $spec ) {
			if ( ! is_array($spec) ) {
				continue;
			}

			$alias = trim( (string) ( $spec['alias'] ?? $key ));
			if ( '' === $alias ) {
				continue;
			}

			$repo   = trim( (string) ( $spec['repo'] ?? '' ));
			$target = trim( (string) ( $spec['target'] ?? $spec['handle'] ?? $spec['name'] ?? $alias ));
			$root   = self::normalize_root( (string) ( $spec['root'] ?? $spec['agent_root'] ?? $spec['path_prefix'] ?? '' ));
			$paths  = self::normalize_paths($spec['paths'] ?? array());

			$normalized[ $alias ] = array(
				'alias'  => $alias,
				'repo'   => $repo,
				'ref'    => trim( (string) ( $spec['ref'] ?? '' )),
				'target' => $target,
				'root'   => $root,
				'paths'  => $paths,
			);
		}

		return $normalized;
	}

	/**
	 * Return context policy for either the alias or its resolved target handle.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function context_policy_for( string $handle ): ?array {
		foreach ( self::aliases() as $alias => $spec ) {
			if ( empty($spec['is_context']) ) {
				continue;
			}

			if ( $handle === $alias || $handle === (string) $spec['target'] ) {
				return array_merge($spec, array( 'alias' => $alias ));
			}
		}

		return null;
	}

	public static function is_context_repository( string $handle ): bool {
		return null !== self::context_policy_for($handle);
	}

	public static function mutation_error( string $handle, string $operation = 'mutation' ): \WP_Error {
		$policy = self::context_policy_for($handle);
		$alias  = (string) ( $policy['alias'] ?? $handle );

		return new \WP_Error(
			'context_repository_read_only',
			sprintf(
				'Context repository "%s" is read-only for this run; %s operations must target the writable workspace repository instead.',
				$alias,
				$operation
			),
			array(
				'status'           => 403,
				'workspace_policy' => self::policy_attestation($handle),
			)
		);
	}

	public static function read_error_if_disallowed( string $handle, ?string $path ): ?\WP_Error {
		$policy = self::context_policy_for($handle);
		if ( null === $policy ) {
			return null;
		}

		$normalized_path = self::normalize_path( (string) ( $path ?? '' ) );
		if ( self::path_allowed_by_policy($normalized_path, $policy, true) ) {
			return null;
		}

		return new \WP_Error(
			'context_repository_path_not_allowed',
			sprintf('Path "%s" is outside the read allowlist for context repository "%s".', '' === $normalized_path ? '/' : $normalized_path, (string) $policy['alias']),
			array(
				'status'           => 403,
				'workspace_policy' => self::policy_attestation($handle),
			)
		);
	}

	public static function filter_context_entries( string $handle, string $listed_path, array $entries ): array {
		$policy = self::context_policy_for($handle);
		if ( null === $policy || empty($policy['paths']) ) {
			return $entries;
		}

		$base = self::normalize_path($listed_path);
		return array_values(
			array_filter(
				$entries,
				static function ( array $entry ) use ( $base, $policy ): bool {
					$name = (string) ( $entry['name'] ?? '' );
					$path = '' === $base || '/' === $base ? $name : $base . '/' . $name;
					return self::path_allowed_by_policy($path, $policy, true);
				}
			)
		);
	}

	public static function policy_attestation( string $handle ): array {
		$policy = self::context_policy_for($handle);
		if ( null === $policy ) {
			return array(
				'access'    => 'read_write',
				'writable'  => true,
				'read_only' => false,
			);
		}

		$attestation = array(
			'access'        => 'read_only',
			'writable'      => false,
			'read_only'     => true,
			'alias'         => (string) $policy['alias'],
			'target'        => (string) $policy['target'],
			'repo'          => (string) ( $policy['repo'] ?? '' ),
			'ref'           => (string) ( $policy['ref'] ?? '' ),
			'allowed_paths' => array_values( (array) ( $policy['paths'] ?? array() ) ),
		);

		$policy_json                = function_exists('wp_json_encode') ? wp_json_encode($attestation) : http_build_query($attestation, '', '&', PHP_QUERY_RFC3986);
		$attestation['policy_hash'] = hash('sha256', (string) $policy_json);

		return $attestation;
	}

	public static function path_allowed_by_policy( string $path, array $policy, bool $allow_ancestors = false ): bool {
		$paths = self::normalize_paths($policy['paths'] ?? array());
		if ( empty($paths) ) {
			return true;
		}

		$path = self::normalize_path($path);
		foreach ( $paths as $allowed ) {
			if ( self::path_matches($path, $allowed, $allow_ancestors) ) {
				return true;
			}
		}

		return false;
	}

	public static function scope_path( string $path, string $root ): string|false {
		$root = self::normalize_root($root);
		if ( '' === $root ) {
			return $path;
		}

		$virtual = str_replace('\\', '/', $path);
		if ( str_starts_with($virtual, '/') || preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $virtual) ) {
			return false;
		}

		$segments = array();
		foreach ( explode('/', $virtual) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment || str_contains($segment, "\0") ) {
				return false;
			}

			$segments[] = $segment;
		}

		return empty($segments) ? $root : $root . '/' . implode('/', $segments);
	}

	public static function unscope_path( string $path, string $root ): string {
		$root = self::normalize_root($root);
		if ( '' === $root ) {
			return $path;
		}

		$normalized = str_replace('\\', '/', $path);
		if ( $normalized === $root ) {
			return '';
		}

		$prefix = $root . '/';
		if ( str_starts_with($normalized, $prefix) ) {
			return substr($normalized, strlen($prefix));
		}

		return $path;
	}

	/**
	 * Sanitize model-facing workspace metadata while leaving content/diff fields intact.
	 *
	 * @param  mixed  $value  Tool result value.
	 * @param  string $alias  Agent-facing alias.
	 * @param  string $handle Canonical workspace handle.
	 * @return mixed
	 */
	public static function sanitize_result( mixed $value, string $alias, string $handle, string $root = '' ): mixed {
		if ( is_array($value) ) {
			$sanitized = array();
			foreach ( $value as $key => $item ) {
				if ( in_array($key, array( 'content', 'patch' ), true) ) {
					$sanitized[ $key ] = $item;
					continue;
				}
				$sanitized[ $key ] = self::sanitize_result($item, $alias, $handle, $root);
			}
			return $sanitized;
		}

		if ( ! is_string($value) || '' === $handle ) {
			return $value;
		}

		$sanitized = self::sanitize_scoped_string($value, $root);
		$sanitized = str_replace($handle, $alias, $sanitized);
		$workspace_handle = WorkspaceHandle::parse($handle);
		if ( $workspace_handle->is_worktree() ) {
			$sanitized = str_replace(array( $workspace_handle->repo(), (string) $workspace_handle->branch_slug() ), array( $alias, $alias ), $sanitized);
		}

		return $sanitized;
	}

	private static function normalize_root( string $root ): string {
		$root     = trim(str_replace('\\', '/', $root), '/');
		$segments = array();
		foreach ( explode('/', $root) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				continue;
			}
			$segments[] = $segment;
		}

		return implode('/', $segments);
	}

	private static function normalize_paths( mixed $paths ): array {
		if ( ! is_array($paths) ) {
			return array();
		}

		$normalized = array();
		foreach ( $paths as $path ) {
			$path = self::normalize_path( (string) $path );
			if ( '' !== $path ) {
				$normalized[] = $path;
			}
		}

		return array_values(array_unique($normalized));
	}

	private static function normalize_path( string $path ): string {
		$path     = trim(str_replace('\\', '/', $path), '/');
		$segments = array();
		foreach ( explode('/', $path) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment || str_contains($segment, "\0") ) {
				continue;
			}
			$segments[] = $segment;
		}

		return implode('/', $segments);
	}

	private static function path_matches( string $path, string $allowed, bool $allow_ancestors ): bool {
		$path    = self::normalize_path($path);
		$allowed = self::normalize_path($allowed);
		if ( '' === $allowed ) {
			return true;
		}

		if ( $path === $allowed || fnmatch($allowed, $path) ) {
			return true;
		}

		if ( str_ends_with($allowed, '/**') ) {
			$prefix = substr($allowed, 0, -3);
			if ( $path === $prefix || str_starts_with($path, $prefix . '/') ) {
				return true;
			}
		}

		if ( $allow_ancestors && '' === $path ) {
			return true;
		}

		if ( $allow_ancestors ) {
			return str_starts_with($allowed, $path . '/');
		}

		return false;
	}

	private static function sanitize_scoped_string( string $value, string $root ): string {
		$root = self::normalize_root($root);
		if ( '' === $root ) {
			return $value;
		}

		$sanitized = str_replace(array( 'a/' . $root . '/', 'b/' . $root . '/' ), array( 'a/', 'b/' ), $value);
		$sanitized = str_replace($root . '/', '', $sanitized);

		return self::unscope_path($sanitized, $root);
	}
}
