<?php
/**
 * Ability registration helpers.
 *
 * @package DataMachineCode\Abilities
 */

namespace DataMachineCode\Abilities;

defined('ABSPATH') || exit;

class AbilityRegistry {

	/**
	 * Run ability registration only inside Core's valid lifecycle window.
	 */
	public static function when_ready( callable $register ): void {
		$doing = function_exists('doing_action') && doing_action('wp_abilities_api_init');
		$did   = function_exists('did_action') ? did_action('wp_abilities_api_init') : 0;
		if ( $doing && function_exists('wp_register_ability') ) {
			$register();
			return;
		}
		if ( ! $did && function_exists('add_action') ) {
			add_action(
				'wp_abilities_api_init',
				static function () use ( $register ): void {
					if ( function_exists('wp_register_ability') ) {
						$register();
					}
				}
			);
		}
	}

	/**
	 * Register a DMC-owned ability.
	 *
	 * @param string              $slug Canonical ability slug.
	 * @param array<string,mixed> $args Ability registration args.
	 */
	public static function register( string $slug, array $args ): void {
		if ( function_exists('wp_has_ability') && wp_has_ability($slug) ) {
			return;
		}
		wp_register_ability($slug, $args);
	}

	/**
	 * Return a machine-readable explanation for an unavailable DMC ability.
	 *
	 * @return array<string,mixed>
	 */
	public static function unavailable_diagnostic( string $expected_ability ): array {
		$doing = function_exists('doing_action') && doing_action('wp_abilities_api_init');
		$did   = function_exists('did_action') ? (int) did_action('wp_abilities_api_init') : 0;
		$phase = $doing ? 'registering' : ( $did > 0 ? 'closed' : 'pending' );

		$siblings = array();
		if ( function_exists('wp_get_abilities') ) {
			$siblings = array_values(array_keys(wp_get_abilities(array( 'namespace' => 'datamachine-code' ))));
			sort($siblings, SORT_STRING);
		}

		return array(
			'code'                    => 'datamachine_code_ability_unavailable',
			'expected_ability'        => $expected_ability,
			'active_plugin_version'   => defined('DATAMACHINE_CODE_VERSION') ? DATAMACHINE_CODE_VERSION : null,
			'registration_phase'      => $phase,
			'registration_generation' => $did,
			'registered_siblings'     => $siblings,
		);
	}
}
