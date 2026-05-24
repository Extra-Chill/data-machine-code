<?php
/**
 * Code-task abilities.
 *
 * @package DataMachineCode\Abilities
 */

namespace DataMachineCode\Abilities;

use DataMachine\Abilities\PermissionHelper;
use DataMachineCode\CodeTask\CodeTaskCreator;
use DataMachineCode\CodeTask\EvidencePacket;

defined('ABSPATH') || exit;

class CodeTaskAbilities {



	private static bool $registered = false;

	public function __construct() {
		if ( self::$registered ) {
			return;
		}

		if ( ! function_exists('wp_register_ability') ) {
			add_action(
				'wp_abilities_api_init', function (): void {
					if ( self::$registered || ! function_exists('wp_register_ability') ) {
						return;
					}

					$this->register();
					self::$registered = true;
				}
			);
			return;
		}

		if ( function_exists('doing_action') && doing_action('wp_abilities_api_init') ) {
			$this->register();
		} else {
			add_action('wp_abilities_api_init', array( $this, 'register' ));
		}
		self::$registered = true;
	}

	public function register(): void {
		wp_register_ability(
			'datamachine/create-code-task',
			array(
				'label'               => 'Create Code Task',
				'description'         => 'Create an isolated workspace worktree from a structured evidence packet.',
				'category'            => 'datamachine-code-code-task',
				'input_schema'        => self::input_schema(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'boolean' ),
						'repo'        => array( 'type' => 'string' ),
						'branch'      => array( 'type' => 'string' ),
						'handle'      => array( 'type' => 'string' ),
						'path'        => array( 'type' => 'string' ),
						'prompt_path' => array( 'type' => 'string' ),
						'packet_path' => array( 'type' => 'string' ),
						'source_url'  => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( self::class, 'create' ),
				'permission_callback' => fn() => PermissionHelper::can_manage(),
				'meta'                => array( 'show_in_rest' => false ),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function input_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'packet' ),
			'properties' => array(
				'packet'      => array(
					'type'        => 'object',
					'description' => 'Structured evidence packet describing the source evidence and target repository.',
				),
				'branch'      => array(
					'type'        => 'string',
					'description' => 'Optional explicit branch name. Defaults to deterministic code-task/<source>-<title>-<hash>.',
				),
				'base_ref'    => array(
					'type'        => 'string',
					'description' => 'Optional base ref for the worktree. Defaults to origin/main.',
				),
				'allow_stale' => array( 'type' => 'boolean' ),
				'force'       => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * @param  array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function create( array $input ): array|\WP_Error {
		$packet = EvidencePacket::from_array($input['packet'] ?? null);
		if ( $packet instanceof \WP_Error ) {
			return $packet;
		}

		$creator = new CodeTaskCreator();
		return $creator->create($packet, $input);
	}
}
