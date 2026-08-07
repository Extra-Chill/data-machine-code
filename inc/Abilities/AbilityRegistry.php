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
	 * Register a DMC-owned ability.
	 *
	 * @param string              $slug Canonical ability slug.
	 * @param array<string,mixed> $args Ability registration args.
	 */
	public static function register( string $slug, array $args ): void {
		wp_register_ability($slug, $args);
	}
}
