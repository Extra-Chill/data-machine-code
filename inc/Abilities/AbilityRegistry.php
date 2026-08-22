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
		if ( ! function_exists('wp_register_ability') ) {
			return;
		}
		if ( doing_action('wp_abilities_api_init') ) {
			$register();
			return;
		}
		if ( ! did_action('wp_abilities_api_init') ) {
			add_action('wp_abilities_api_init', $register);
		}
	}

	/**
	 * Register a DMC-owned ability.
	 *
	 * @param string              $slug Canonical ability slug.
	 * @param array<string,mixed> $args Ability registration args.
	 */
	public static function register( string $slug, array $args ): void {
		wp_register_ability($slug, $args);
	}
}
