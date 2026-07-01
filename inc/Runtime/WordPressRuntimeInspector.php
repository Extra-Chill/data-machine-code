<?php
/**
 * Read-only WordPress runtime inspection primitives.
 *
 * @package DataMachineCode\Runtime
 */

namespace DataMachineCode\Runtime;

defined('ABSPATH') || exit;

final class WordPressRuntimeInspector {



	private const DEFAULT_MAX_READ_SIZE = 1048576;
	private const DEFAULT_LINE_LIMIT    = 500;
	private const MAX_LINE_LIMIT        = 2000;
	private const DEFAULT_MAX_ENTRIES   = 200;
	private const MAX_ENTRIES           = 1000;

	/**
	 * @return array<string,mixed>
	 */
	public function inventory(): array {
		$this->maybeLoadPluginFunctions();

		return array(
			'success'    => true,
			'wordpress'  => array(
				'version'     => $this->getWordPressVersion(),
				'php_version' => PHP_VERSION,
				'multisite'   => function_exists('is_multisite') ? is_multisite() : false,
			),
			'theme'      => $this->getThemeInventory(),
			'themes'     => $this->getInstalledThemes(),
			'plugins'    => $this->getInstalledPlugins(),
			'mu_plugins' => $this->getMustUsePlugins(),
			'drop_ins'   => $this->getDropIns(),
			'constants'  => $this->getSafeConstants(),
			'roots'      => $this->getRootMetadata(),
			'policy'     => array(
				'read_only'         => true,
				'allowed_roots'     => array_keys($this->getAllowedRoots()),
				'denied_by_default' => array(
					'wp-config.php',
					'.env and credential files',
					'uploads',
					'logs/cache directories',
					'database files',
				),
			),
		);
	}

	/**
	 * @param  array<string,mixed> $input Input parameters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function ls( array $input ): array|\WP_Error {
		$path        = (string) ( $input['path'] ?? 'wp-content/plugins' );
		$max_entries = $this->clampInt($input['max_entries'] ?? self::DEFAULT_MAX_ENTRIES, 1, self::MAX_ENTRIES);
		$resolved    = $this->resolveAllowedPath($path);

		if ( is_wp_error($resolved) ) {
			return $resolved;
		}

		if ( ! is_dir($resolved['real_path']) ) {
			return new \WP_Error('datamachine_runtime_not_directory', 'Path is not a directory.');
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable directories are returned as WP_Error below.
		$handle = @opendir($resolved['real_path']);
		if ( false === $handle ) {
			return new \WP_Error('datamachine_runtime_unreadable', 'Directory is not readable.');
		}

		$entries   = array();
		$truncated = false;
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard readdir loop pattern.
		while ( false !== ( $name = readdir($handle) ) ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			$child_real = realpath($resolved['real_path'] . DIRECTORY_SEPARATOR . $name);
			if ( false === $child_real ) {
				continue;
			}

			$entries[] = array(
				'name'     => $name,
				'path'     => $this->relativeFromAbsolute($child_real),
				'type'     => is_dir($child_real) ? 'directory' : ( is_file($child_real) ? 'file' : 'other' ),
				'size'     => is_file($child_real) ? (int) filesize($child_real) : null,
				'readable' => is_readable($child_real),
			);

			if ( count($entries) >= $max_entries ) {
				$truncated = true;
				break;
			}
		}
		closedir($handle);

		usort(
			$entries,
			static fn( array $a, array $b ): int => ( $a['type'] === $b['type'] ? strcmp($a['name'], $b['name']) : strcmp($a['type'], $b['type']) )
		);

		return array(
			'success'     => true,
			'path'        => $resolved['relative_path'],
			'root'        => $resolved['root'],
			'entries'     => $entries,
			'count'       => count($entries),
			'truncated'   => $truncated,
			'max_entries' => $max_entries,
		);
	}

	/**
	 * @param  array<string,mixed> $input Input parameters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function read( array $input ): array|\WP_Error {
		$path     = (string) ( $input['path'] ?? '' );
		$max_size = $this->clampInt($input['max_size'] ?? self::DEFAULT_MAX_READ_SIZE, 1, self::DEFAULT_MAX_READ_SIZE);
		$offset   = $this->clampInt($input['offset'] ?? 1, 1, PHP_INT_MAX);
		$limit    = $this->clampInt($input['limit'] ?? self::DEFAULT_LINE_LIMIT, 1, self::MAX_LINE_LIMIT);
		$resolved = $this->resolveAllowedPath($path);

		if ( is_wp_error($resolved) ) {
			return $resolved;
		}

		if ( ! is_file($resolved['real_path']) ) {
			return new \WP_Error('datamachine_runtime_not_file', 'Path is not a file.');
		}

		$size = (int) filesize($resolved['real_path']);
		if ( $size > $max_size ) {
			return new \WP_Error(
				'datamachine_runtime_file_too_large', 'File exceeds the requested max_size.', array(
					'path'     => $resolved['relative_path'],
					'size'     => $size,
					'max_size' => $max_size,
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Path is validated by resolveAllowedPath().
		$sample = @file_get_contents($resolved['real_path'], false, null, 0, min($size, 8192));
		if ( false === $sample ) {
			return new \WP_Error('datamachine_runtime_unreadable', 'File is not readable.');
		}

		if ( $this->isBinary($sample) ) {
			return new \WP_Error('datamachine_runtime_binary_file', 'Binary file reading is denied.', array( 'path' => $resolved['relative_path'] ));
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file,WordPress.PHP.NoSilencedErrors.Discouraged -- Path is validated by resolveAllowedPath().
		$lines = @file($resolved['real_path'], FILE_IGNORE_NEW_LINES);
		if ( false === $lines ) {
			return new \WP_Error('datamachine_runtime_unreadable', 'File is not readable.');
		}

		$total_lines = count($lines);
		$slice       = array_slice($lines, max(0, $offset - 1), $limit);

		return array(
			'success'     => true,
			'path'        => $resolved['relative_path'],
			'root'        => $resolved['root'],
			'size'        => $size,
			'offset'      => $offset,
			'limit'       => $limit,
			'lines_read'  => count($slice),
			'total_lines' => $total_lines,
			'truncated'   => $offset + count($slice) - 1 < $total_lines,
			'content'     => implode("\n", $slice),
		);
	}

	/**
	 * @return array{relative_path:string,real_path:string,root:string}|\WP_Error
	 */
	private function resolveAllowedPath( string $path ): array|\WP_Error {
		$relative = $this->normalizeRelativePath($path);

		if ( '' === $relative ) {
			return new \WP_Error('datamachine_runtime_path_required', 'A runtime path is required.');
		}

		if ( $this->hasTraversal($relative) ) {
			return new \WP_Error('datamachine_runtime_path_traversal', 'Path traversal detected. Access denied.');
		}

		if ( $this->isDeniedPath($relative) ) {
			return new \WP_Error('datamachine_runtime_path_denied', 'Path is denied by runtime inspection policy.');
		}

		$real = realpath($this->rootPath() . DIRECTORY_SEPARATOR . $relative);
		if ( false === $real ) {
			return new \WP_Error('datamachine_runtime_path_missing', 'Path does not exist.');
		}

		foreach ( $this->getAllowedRoots() as $root => $root_path ) {
			$root_real = realpath($root_path);
			if ( false === $root_real ) {
				continue;
			}

			if ( $real === $root_real || str_starts_with($real, $root_real . DIRECTORY_SEPARATOR) ) {
				if ( ! is_readable($real) ) {
					return new \WP_Error('datamachine_runtime_unreadable', 'Path is not readable.');
				}

				return array(
					'relative_path' => $this->relativeFromAbsolute($real),
					'real_path'     => $real,
					'root'          => $root,
				);
			}
		}

		return new \WP_Error('datamachine_runtime_path_not_allowed', 'Path is outside the allowlisted WordPress runtime roots.');
	}

	/**
	 * @return array<string,string>
	 */
	private function getAllowedRoots(): array {
		$root = $this->rootPath();

		return array(
			'wp-content/plugins' => $root . '/wp-content/plugins',
			'wp-content/themes'  => $root . '/wp-content/themes',
			'wp-includes'        => $root . '/wp-includes',
			'wp-admin'           => $root . '/wp-admin',
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function getRootMetadata(): array {
		$roots = array();
		foreach ( $this->getAllowedRoots() as $relative => $absolute ) {
			$real    = realpath($absolute);
			$roots[] = array(
				'root'     => $relative,
				'exists'   => false !== $real,
				'readable' => false !== $real && is_readable($real),
				'path'     => false !== $real ? $this->relativeFromAbsolute($real) : $relative,
			);
		}

		return $roots;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function getThemeInventory(): array {
		$theme = function_exists('wp_get_theme') ? wp_get_theme() : null;

		return array(
			'name'       => $theme && method_exists($theme, 'get') ? (string) $theme->get('Name') : '',
			'version'    => $theme && method_exists($theme, 'get') ? (string) $theme->get('Version') : '',
			'template'   => function_exists('get_template') ? (string) get_template() : '',
			'stylesheet' => function_exists('get_stylesheet') ? (string) get_stylesheet() : '',
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function getInstalledThemes(): array {
		if ( ! function_exists('wp_get_themes') ) {
			return array();
		}

		$themes = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$themes[] = array(
				'stylesheet' => (string) $stylesheet,
				'name'       => method_exists($theme, 'get') ? (string) $theme->get('Name') : (string) $stylesheet,
				'version'    => method_exists($theme, 'get') ? (string) $theme->get('Version') : '',
				'active'     => function_exists('get_stylesheet') && get_stylesheet() === (string) $stylesheet,
			);
		}

		return $themes;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function getInstalledPlugins(): array {
		$plugins = function_exists('get_plugins') ? get_plugins() : array();
		$active  = function_exists('get_option') ? (array) get_option('active_plugins', array()) : array();
		$items   = array();

		foreach ( $plugins as $file => $plugin ) {
			$items[] = array(
				'file'           => (string) $file,
				'name'           => (string) ( $plugin['Name'] ?? $file ),
				'version'        => (string) ( $plugin['Version'] ?? '' ),
				'active'         => function_exists('is_plugin_active') ? is_plugin_active($file) : in_array($file, $active, true),
				'network_active' => function_exists('is_plugin_active_for_network') ? is_plugin_active_for_network($file) : false,
			);
		}

		return $items;
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private function getMustUsePlugins(): array {
		if ( ! function_exists('get_mu_plugins') ) {
			return array();
		}

		$items = array();
		foreach ( get_mu_plugins() as $file => $plugin ) {
			$items[] = array(
				'file'    => (string) $file,
				'name'    => (string) ( $plugin['Name'] ?? $file ),
				'version' => (string) ( $plugin['Version'] ?? '' ),
			);
		}

		return $items;
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private function getDropIns(): array {
		$drop_ins = function_exists('get_dropins') ? get_dropins() : array();
		$items    = array();

		foreach ( $drop_ins as $file => $drop_in ) {
			$items[] = array(
				'file'        => (string) $file,
				'name'        => (string) ( $drop_in['Name'] ?? $file ),
				'description' => (string) ( $drop_in['Description'] ?? '' ),
			);
		}

		return $items;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function getSafeConstants(): array {
		$constants = array( 'WP_DEBUG', 'WP_ENVIRONMENT_TYPE', 'SCRIPT_DEBUG', 'WP_CONTENT_DIR', 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR' );
		$safe      = array();

		foreach ( $constants as $constant ) {
			if ( ! defined($constant) ) {
				continue;
			}

			$value = constant($constant);
			if ( is_string($value) && $this->isAbsolutePath($value) ) {
				$value = $this->relativeFromAbsolute($value);
			}

			$safe[ $constant ] = $value;
		}

		return $safe;
	}

	private function maybeLoadPluginFunctions(): void {
		$plugin_file = $this->rootPath() . '/wp-admin/includes/plugin.php';
		if ( ( ! function_exists('get_plugins') || ! function_exists('get_mu_plugins') || ! function_exists('get_dropins') ) && is_readable($plugin_file) ) {
			include_once $plugin_file;
		}
	}

	private function getWordPressVersion(): string {
		if ( function_exists('get_bloginfo') ) {
			$version = get_bloginfo('version');
			if ( is_string($version) && '' !== $version ) {
				return $version;
			}
		}

		global $wp_version;
		return is_string($wp_version ?? null) ? $wp_version : '';
	}

	private function normalizeRelativePath( string $path ): string {
		$path = str_replace('\\', '/', trim($path));
		$path = preg_replace('#/+#', '/', $path) ?? $path;
		return trim($path, '/');
	}

	private function hasTraversal( string $path ): bool {
		foreach ( explode('/', $path) as $part ) {
			if ( '.' === $part || '..' === $part ) {
				return true;
			}
		}

		return false;
	}

	private function isDeniedPath( string $path ): bool {
		$normalized = strtolower($this->normalizeRelativePath($path));
		$basename   = basename($normalized);
		$segments   = explode('/', $normalized);

		if ( 'wp-config.php' === $basename || str_starts_with($basename, '.env') ) {
			return true;
		}

		foreach ( $segments as $segment ) {
			if ( in_array($segment, array( 'uploads', 'cache', 'logs', 'log', 'secrets', 'private' ), true) ) {
				return true;
			}
		}

		if ( preg_match('/(^|[._-])(secret|secrets|credential|credentials|token|tokens|key|keys)([._-]|$)/', $basename) ) {
			return true;
		}

		if ( preg_match('/\.(sqlite|sqlite3|db|sql|log|pem|key|crt|p12|pfx)$/', $basename) ) {
			return true;
		}

		return false;
	}

	private function isBinary( string $content ): bool {
		if ( str_contains($content, "\0") ) {
			return true;
		}

		if ( '' === $content ) {
			return false;
		}

		$non_text = preg_match_all('/[^\x09\x0A\x0D\x20-\x7E]/', $content);
		return false !== $non_text && ( $non_text / strlen($content) ) > 0.30;
	}

	private function clampInt( mixed $value, int $min, int $max ): int {
		$value = is_numeric($value) ? (int) $value : $min;
		return max($min, min($max, $value));
	}

	private function rootPath(): string {
		return rtrim(str_replace('\\', '/', ABSPATH), '/');
	}

	private function relativeFromAbsolute( string $path ): string {
		$path = str_replace('\\', '/', $path);
		$root = $this->rootPath();

		if ( $path === $root ) {
			return '';
		}

		if ( str_starts_with($path, $root . '/') ) {
			return substr($path, strlen($root) + 1);
		}

		return basename($path);
	}

	private function isAbsolutePath( string $path ): bool {
		return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
	}
}
