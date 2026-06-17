<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function workspace_hygiene_contract_assert_contains( string $needle, string $haystack, string $message ): void {
	if ( ! str_contains($haystack, $needle) ) {
		throw new RuntimeException($message . ' Missing: ' . $needle);
	}
}

function workspace_hygiene_contract_assert_not_contains( string $needle, string $haystack, string $message ): void {
	if ( str_contains($haystack, $needle) ) {
		throw new RuntimeException($message . ' Unexpected: ' . $needle);
	}
}

$hygiene = file_get_contents($root . '/inc/Workspace/WorkspaceHygieneReport.php');
$cli     = file_get_contents($root . '/inc/Cli/Commands/WorkspaceCommand.php');
$task    = file_get_contents($root . '/inc/Tasks/WorkspaceHygieneReportTask.php');
$plugin  = file_get_contents($root . '/data-machine-code.php');
$ability = file_get_contents($root . '/inc/Abilities/WorkspaceAbilities.php');

if ( false === $hygiene || false === $cli || false === $task || false === $plugin || false === $ability ) {
	throw new RuntimeException('Unable to read workspace hygiene source files.');
}

workspace_hygiene_contract_assert_contains("array_key_exists('include_sizes', \$opts) ? (bool) \$opts['include_sizes'] : false", $hygiene, 'Workspace hygiene must skip du sizing by default.');
workspace_hygiene_contract_assert_contains('Size scan skipped by default for large-workspace safety', $hygiene, 'Default report must explain why size data is partial.');
workspace_hygiene_contract_assert_contains('suggested_size_command', $hygiene, 'Default report must expose a continuation command for bounded sizing.');
workspace_hygiene_contract_assert_contains('--include-sizes --size-limit=100 --format=json', $hygiene, 'Continuation command must keep sizing bounded.');

workspace_hygiene_contract_assert_contains("'include_sizes'           => ! empty(\$assoc_args['include-sizes'])", $cli, 'CLI hygiene must require explicit size opt-in.');
workspace_hygiene_contract_assert_contains("'include_sizes'           => false", $cli, 'Cleanup-run inventory mode must not force size scans.');
workspace_hygiene_contract_assert_not_contains("'include_sizes'           => true", $cli, 'CLI wrappers must not opt into size scans by default.');

workspace_hygiene_contract_assert_contains("array_key_exists('include_sizes', \$params) ? (bool) \$params['include_sizes'] : false", $task, 'Scheduled hygiene task must skip sizing unless requested.');
workspace_hygiene_contract_assert_contains("'include_sizes'   => false", $plugin, 'Registered weekly hygiene schedule must remain cheap by default.');
workspace_hygiene_contract_assert_contains('Default false for huge-workspace safety', $ability, 'Ability schema must document cheap size default.');

echo "workspace-hygiene-timeout-safe-contract: ok\n";
