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
		wp_register_ability($slug, $args);
	}
}
