<?php
/**
 * Ability registration helpers.
 *
 * @package DataMachineCode\Abilities
 */

namespace DataMachineCode\Abilities;

defined('ABSPATH') || exit;

class AbilityRegistry {

	public static function canonical_slug( string $slug ): string {
		if ( str_starts_with($slug, 'datamachine/') ) {
			return 'datamachine-code/' . substr($slug, strlen('datamachine/'));
		}

		return $slug;
	}

	/**
	 * Register a DMC-owned ability and, for shipped datamachine/* slugs, a deprecated alias.
	 *
	 * @param string              $slug Legacy or canonical ability slug.
	 * @param array<string,mixed> $args Ability registration args.
	 */
	public static function register( string $slug, array $args ): void {
		$canonical = self::canonical_slug($slug);
		wp_register_ability($canonical, $args);

		if ( $canonical === $slug || ! str_starts_with($slug, 'datamachine/') ) {
			return;
		}

		$alias_args         = $args;
		$alias_args['meta'] = array_merge(
			$alias_args['meta'] ?? array(),
			array(
				'deprecated'  => true,
				'replacement' => $canonical,
			)
		);

		wp_register_ability($slug, $alias_args);
	}
}
