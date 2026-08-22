<?php

declare(strict_types=1);

namespace DataMachine\Cli {
	class BaseCommand {}
}

namespace {
	function worktree_wp_cli_synopsis_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException($message);
		}
	}

	$wp_cli_root = getenv('WP_CLI_ROOT');
	if ( ! is_string($wp_cli_root) || '' === $wp_cli_root ) {
		throw new \RuntimeException('Set WP_CLI_ROOT to a WP-CLI installation before running this integration test.');
	}
	require_once rtrim($wp_cli_root, '/') . '/php/WP_CLI/SynopsisParser.php';
	require_once rtrim($wp_cli_root, '/') . '/php/WP_CLI/SynopsisValidator.php';

	define('ABSPATH', __DIR__ . '/');
	require_once dirname(__DIR__) . '/inc/Cli/Commands/WorkspaceCommand.php';

	$definitions = \DataMachineCode\Cli\Commands\WorkspaceCommand::worktree_command_definitions();
	$parse = static function ( string $operation, array $args, array $assoc_args ) use ( $definitions ): string {
		$synopsis = \WP_CLI\SynopsisParser::render($definitions[ $operation ]['synopsis']);
		$validator = new \WP_CLI\SynopsisValidator($synopsis);
		list( $errors ) = $validator->validate_assoc($assoc_args);

		worktree_wp_cli_synopsis_assert(array() === $validator->get_unknown(), sprintf('%s generated an invalid WP-CLI synopsis: %s', $operation, $synopsis));
		worktree_wp_cli_synopsis_assert($validator->enough_positionals($args), sprintf('%s rejected documented positional arguments: %s', $operation, $synopsis));
		worktree_wp_cli_synopsis_assert(array() === $errors['fatal'], sprintf('%s rejected documented named arguments: %s', $operation, implode('; ', $errors['fatal'])));
		worktree_wp_cli_synopsis_assert(array() === $validator->unknown_assoc($assoc_args), sprintf('%s rejected registered named arguments.', $operation));

		return $synopsis;
	};

	$add_synopsis = $parse('add', array( 'data-machine-code', 'fix/1070' ), array( 'from' => 'origin/main', 'task-url' => 'https://github.com/Extra-Chill/data-machine-code/issues/1070', 'skip-bootstrap' => true ));
	worktree_wp_cli_synopsis_assert(str_contains($add_synopsis, '[--from=<from>]'), 'add did not render --from as optional.');
	worktree_wp_cli_synopsis_assert(str_contains($add_synopsis, '[--skip-bootstrap]'), 'add did not render --skip-bootstrap as optional.');

	$parse('add', array( 'data-machine-code', 'fix/1070' ), array( 'base' => 'origin/main' ));
	$parse('add', array( 'data-machine-code', 'fix/1070' ), array( 'base-ref' => 'origin/main' ));
	$parse('add', array( 'data-machine-code', 'fix/1070' ), array( 'base-branch' => 'main' ));
	$parse('remove', array( 'data-machine-code@fix-1070' ), array( 'force' => true ));
	$parse('remove', array( 'data-machine-code', 'fix/1070' ), array());
	$parse('finalize', array( 'data-machine-code@fix-1070' ), array( 'pr' => 'https://github.com/Extra-Chill/data-machine-code/pull/1070' ));
	$parse('locks', array(), array( 'prune-stale' => true, 'dry-run' => true, 'format' => 'json' ));

	$remove_synopsis = \WP_CLI\SynopsisParser::render($definitions['remove']['synopsis']);
	worktree_wp_cli_synopsis_assert(str_starts_with($remove_synopsis, '<repo-or-handle> [<branch>]'), 'remove did not render branch as an optional positional argument.');
	$add_validator = new \WP_CLI\SynopsisValidator($add_synopsis);
	$remove_validator = new \WP_CLI\SynopsisValidator($remove_synopsis);
	worktree_wp_cli_synopsis_assert(! $add_validator->enough_positionals(array( 'data-machine-code' )), 'add accepted a missing required branch positional.');
	worktree_wp_cli_synopsis_assert(! $remove_validator->enough_positionals(array()), 'remove accepted a missing required repo-or-handle positional.');

	echo "worktree-command-wp-cli-synopsis: ok\n";
}
