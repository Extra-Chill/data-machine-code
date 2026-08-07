<?php
/**
 * Legacy GitHub credential settings migration.
 *
 * @package DataMachineCode\Support
 */

namespace DataMachineCode\Support;

use DataMachineCode\Support\PluginSettings;

defined('ABSPATH') || exit;

final class GitHubCredentialSettingsMigration {

	public const MIGRATED_OPTION = 'datamachine_code_github_legacy_credentials_migrated_v1';

	private const LEGACY_KEYS = array(
		'github_pat',
		'github_auth_mode',
		'github_app_id',
		'github_app_installation_id',
		'github_app_private_key',
		'github_default_repo',
	);

	/**
	 * Report non-secret migration state for operators.
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		$legacy_present = self::legacy_keys_present();
		$profiles       = PluginSettings::get('github_credential_profiles', array());

		return array(
			'legacy_keys_present' => ! empty($legacy_present),
			'legacy_keys'         => $legacy_present,
			'profiles_present'    => is_array($profiles) && ! empty($profiles),
			'migrated'            => (bool) get_option(self::MIGRATED_OPTION, false),
			'removal_target'      => 'Remove legacy writes and implicit synthesis after live installs report migrated=true.',
		);
	}

	/**
	 * Migrate the legacy single-credential shape into credential profiles.
	 *
	 * @return array<string,mixed>
	 */
	public static function migrate( bool $apply = false, bool $force = false ): array {
		$legacy_present = self::legacy_keys_present();
		$existing       = PluginSettings::get('github_credential_profiles', array());
		$has_profiles   = is_array($existing) && ! empty($existing);

		if ( empty($legacy_present) ) {
			return array(
				'success'             => true,
				'applied'             => false,
				'legacy_keys_present' => false,
				'profiles_present'    => $has_profiles,
				'message'             => 'No legacy GitHub credential settings found.',
			);
		}

		if ( $has_profiles && ! $force ) {
			return array(
				'success'             => true,
				'applied'             => false,
				'legacy_keys_present' => true,
				'legacy_keys'         => $legacy_present,
				'profiles_present'    => true,
				'message'             => 'Credential profiles already exist; pass force to overwrite from legacy settings.',
			);
		}

		$profile = self::legacy_profile();
		$preview = self::redact_profile($profile);

		if ( ! $apply ) {
			return array(
				'success'             => true,
				'applied'             => false,
				'legacy_keys_present' => true,
				'legacy_keys'         => $legacy_present,
				'profiles_present'    => $has_profiles,
				'profile'             => $preview,
				'message'             => 'Dry run only; pass apply to write github_credential_profiles.',
			);
		}

		self::write_setting('github_credential_profiles', array( $profile ));
		self::write_setting('github_default_profile_id', GitHubCredentialResolver::DEFAULT_PROFILE_ID);
		update_option(self::MIGRATED_OPTION, true, false);

		return array(
			'success'             => true,
			'applied'             => true,
			'legacy_keys_present' => true,
			'legacy_keys'         => $legacy_present,
			'profiles_present'    => true,
			'profile'             => $preview,
			'message'             => 'Migrated legacy GitHub credential settings to github_credential_profiles.',
		);
	}

	/**
	 * @return array<int,string>
	 */
	private static function legacy_keys_present(): array {
		$present = array();
		foreach ( self::LEGACY_KEYS as $key ) {
			$value = PluginSettings::get($key, '');
			if ( is_string($value) && '' !== trim($value) ) {
				$present[] = $key;
			}
		}

		return $present;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function legacy_profile(): array {
		$mode = strtolower(trim( (string) PluginSettings::get('github_auth_mode', '') ));
		if ( ! in_array($mode, array( 'pat', 'app' ), true) ) {
			$mode = 'pat';
		}

		return GitHubProfileSanitizer::sanitize(
			array(
				array(
					'id'                  => GitHubCredentialResolver::DEFAULT_PROFILE_ID,
					'label'               => 'Default',
					'mode'                => $mode,
					'pat'                 => (string) PluginSettings::get('github_pat', ''),
					'app_id'              => (string) PluginSettings::get('github_app_id', ''),
					'app_installation_id' => (string) PluginSettings::get('github_app_installation_id', ''),
					'app_private_key'     => (string) PluginSettings::get('github_app_private_key', ''),
					'default_repo'        => (string) PluginSettings::get('github_default_repo', ''),
				),
			)
		)[0];
	}

	/**
	 * @param array<string,mixed> $profile
	 * @return array<string,mixed>
	 */
	private static function redact_profile( array $profile ): array {
		$profile['pat_configured']             = '' !== trim( (string) ( $profile['pat'] ?? '' ) );
		$profile['app_private_key_configured'] = '' !== trim( (string) ( $profile['app_private_key'] ?? '' ) );
		unset($profile['pat'], $profile['app_private_key']);
		return $profile;
	}

	private static function write_setting( string $key, mixed $value ): void {
		$settings = get_option('datamachine_settings', array());
		if ( ! is_array($settings) ) {
			$settings = array();
		}
		$settings[ $key ] = $value;
		update_option('datamachine_settings', $settings, false);
	}
}
