<?php
/**
 * AI tools for read-only WordPress runtime inspection.
 *
 * @package DataMachineCode\Tools
 */

namespace DataMachineCode\Tools;

use DataMachine\Engine\AI\Tools\BaseTool;

defined( 'ABSPATH' ) || exit;

class WordPressRuntimeTools extends BaseTool {

	public function __construct() {
		$contexts = array( 'chat', 'pipeline' );
		$options  = array( 'access_level' => 'editor' );

		$this->registerTool( 'wordpress_runtime_inventory', array( $this, 'getInventoryDefinition' ), $contexts, $options + array( 'ability' => 'datamachine-code/wordpress-runtime-inventory' ) );
		$this->registerTool( 'wordpress_runtime_ls', array( $this, 'getLsDefinition' ), $contexts, $options + array( 'ability' => 'datamachine-code/wordpress-runtime-ls' ) );
		$this->registerTool( 'wordpress_runtime_read', array( $this, 'getReadDefinition' ), $contexts, $options + array( 'ability' => 'datamachine-code/wordpress-runtime-read' ) );
	}

	public static function is_configured(): bool {
		return (bool) wp_get_ability( 'datamachine-code/wordpress-runtime-inventory' );
	}

	public function check_configuration( $configured, $tool_id ) {
		$runtime_tools = array( 'wordpress_runtime_inventory', 'wordpress_runtime_ls', 'wordpress_runtime_read' );
		if ( ! in_array( $tool_id, $runtime_tools, true ) ) {
			return $configured;
		}

		return self::is_configured();
	}

	/** @param array<string,mixed> $parameters Tool parameters. @param array<string,mixed> $tool_def Tool definition. @return array<string,mixed> */
	public function handle_tool_call( array $parameters, array $tool_def = array() ): array {
		$method = $tool_def['method'] ?? '';
		if ( ! method_exists( $this, $method ) ) {
			return $this->buildErrorResponse( "Unknown WordPress runtime tool method: {$method}", 'wordpress_runtime' );
		}

		return $this->{$method}( $parameters );
	}

	/** @return array<string,mixed> */
	public function handleInventory(): array {
		return $this->executeAbility( 'datamachine-code/wordpress-runtime-inventory', array(), 'wordpress_runtime_inventory' );
	}

	/** @param array<string,mixed> $parameters Tool parameters. @return array<string,mixed> */
	public function handleLs( array $parameters ): array {
		$input = array();
		if ( isset( $parameters['path'] ) ) {
			$input['path'] = (string) $parameters['path'];
		}
		if ( isset( $parameters['max_entries'] ) ) {
			$input['max_entries'] = (int) $parameters['max_entries'];
		}

		return $this->executeAbility( 'datamachine-code/wordpress-runtime-ls', $input, 'wordpress_runtime_ls' );
	}

	/** @param array<string,mixed> $parameters Tool parameters. @return array<string,mixed> */
	public function handleRead( array $parameters ): array {
		$input = array( 'path' => (string) ( $parameters['path'] ?? '' ) );
		foreach ( array( 'max_size', 'offset', 'limit' ) as $key ) {
			if ( isset( $parameters[ $key ] ) ) {
				$input[ $key ] = (int) $parameters[ $key ];
			}
		}

		return $this->executeAbility( 'datamachine-code/wordpress-runtime-read', $input, 'wordpress_runtime_read' );
	}

	/** @return array<string,mixed> */
	public function getToolDefinition(): array {
		return $this->getInventoryDefinition();
	}

	/** @return array<string,mixed> */
	public function getInventoryDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleInventory',
			'description' => 'Inspect the live WordPress runtime inventory: WP/PHP versions, active theme, installed plugins/themes, mu-plugins, drop-ins, and safe source-root policy metadata.',
			'parameters'  => array(),
		);
	}

	/** @return array<string,mixed> */
	public function getLsDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleLs',
			'description' => 'List files/directories under allowlisted WordPress runtime source roots only. Default path is wp-content/plugins.',
			'parameters'  => array(
				'path'        => array(
					'type'        => 'string',
					'required'    => false,
					'description' => 'Runtime directory path relative to ABSPATH. Must be under wp-content/plugins, wp-content/themes, wp-includes, or wp-admin.',
				),
				'max_entries' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Maximum entries to return (default 200, max 1000).',
				),
			),
		);
	}

	/** @return array<string,mixed> */
	public function getReadDefinition(): array {
		return array(
			'class'       => __CLASS__,
			'method'      => 'handleRead',
			'description' => 'Read a bounded text file under allowlisted WordPress runtime source roots only. Denies sensitive paths, traversal, oversized files, and binary files.',
			'parameters'  => array(
				'path'     => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Runtime file path relative to ABSPATH.',
				),
				'max_size' => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Maximum file size in bytes (default/max 1MB).',
				),
				'offset'   => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Line number to start reading from (1-indexed).',
				),
				'limit'    => array(
					'type'        => 'integer',
					'required'    => false,
					'description' => 'Maximum number of lines to return (default 500, max 2000).',
				),
			),
		);
	}

	/** @param array<string,mixed> $input Ability input. @return array<string,mixed> */
	private function executeAbility( string $ability_name, array $input, string $tool_name ): array {
		$ability = wp_get_ability( $ability_name );
		if ( ! $ability ) {
			return $this->buildErrorResponse( 'WordPress runtime ability not available.', $tool_name );
		}

		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			return $this->buildErrorResponse( $result->get_error_message(), $tool_name );
		}

		return array(
			'success'   => true,
			'data'      => $result,
			'tool_name' => $tool_name,
		);
	}
}
