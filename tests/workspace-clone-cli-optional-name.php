<?php

declare(strict_types=1);

namespace DataMachine\Cli {
	class BaseCommand {}
}

namespace {
	final class Workspace_Clone_Cli_Optional_Name_Ability {
	/** @var array<string,mixed>|null */
	public ?array $input = null;

	/** @param array<string,mixed> $input */
	public function execute( array $input ): array {
		$this->input = $input;

		return array(
			'message' => 'Repository cloned.',
			'name'    => 'homeboy-desktop',
			'path'    => '/tmp/homeboy-desktop',
		);
	}
}

	final class WP_CLI {
		/** @var array<int,string> */
		public static array $logs = array();

	public static function error( string $message ): never {
		throw new \RuntimeException($message);
	}

	public static function success( string $message ): void {}

	public static function log( string $message ): void { self::$logs[] = $message; }
	}

	$ability = new Workspace_Clone_Cli_Optional_Name_Ability();

	function wp_get_ability( string $name ): ?Workspace_Clone_Cli_Optional_Name_Ability {
		global $ability;
		if ( 'datamachine-code/workspace-clone' !== $name ) {
			return null;
		}

		return $ability;
	}

	function is_wp_error( mixed $value ): bool {
		return false;
	}

	define('ABSPATH', __DIR__ . '/fixtures/');
	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	$command = new \DataMachineCode\Cli\Commands\WorkspaceCommand();
	$command->clone_repo(array( 'https://github.com/Extra-Chill/homeboy-desktop.git' ), array());

	if ( null === $ability->input ) {
		throw new \RuntimeException('Workspace clone CLI did not execute the ability.');
	}
	if ( array_key_exists('name', $ability->input) ) {
		throw new \RuntimeException('Workspace clone CLI must omit name when --name is not provided.');
	}
	if ( 'https://github.com/Extra-Chill/homeboy-desktop.git' !== ( $ability->input['url'] ?? null ) ) {
		throw new \RuntimeException('Workspace clone CLI did not forward the repository URL.');
	}
	if ( array( 'Handle: homeboy-desktop', 'Path: /tmp/homeboy-desktop' ) !== WP_CLI::$logs ) {
		throw new \RuntimeException('Workspace clone CLI must report the resolved handle and checkout path.');
	}

	echo "workspace-clone-cli-optional-name: ok\n";
}
