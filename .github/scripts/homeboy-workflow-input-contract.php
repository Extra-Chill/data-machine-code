<?php

declare(strict_types=1);

function homeboy_workflow_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException($message);
	}
}

$contract_path = $argv[1] ?? '';
homeboy_workflow_assert('' !== $contract_path && is_file($contract_path), 'Usage: php .github/scripts/homeboy-workflow-input-contract.php <downloaded-homeboy-action-ci.yml>');

$workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/homeboy.yml');
homeboy_workflow_assert(false !== $workflow, 'Homeboy workflow must be readable.');

preg_match('/^    uses: Extra-Chill\/homeboy-action\/\.github\/workflows\/ci\.yml@v2\n    with:\n(?<inputs>(?:^      .*\n|^ {8,}.*\n)*)^    secrets:/m', $workflow, $match);
homeboy_workflow_assert(isset($match[1]), 'Homeboy job must call the v2 reusable workflow with explicit inputs.');

preg_match_all('/^      ([a-z][a-z0-9-]+):/m', $match['inputs'], $input_matches);
$caller_inputs = $input_matches[1] ?? array();

$contract = file_get_contents($contract_path);
homeboy_workflow_assert(false !== $contract, 'Downloaded reusable workflow contract must be readable.');

preg_match('/^  workflow_call:\n    inputs:\n(?<inputs>(?:^      .*\n|^ {8,}.*\n)*)^    secrets:/m', $contract, $match);
homeboy_workflow_assert(isset($match['inputs']), 'Downloaded contract must expose workflow_call inputs.');

preg_match_all('/^      ([a-z][a-z0-9-]+):/m', $match['inputs'], $input_matches);
$reusable_inputs = $input_matches[1] ?? array();
homeboy_workflow_assert(array() !== $reusable_inputs, 'Downloaded contract must declare reusable workflow inputs.');

$unsupported_inputs = array_values(array_diff($caller_inputs, $reusable_inputs));
homeboy_workflow_assert(array() === $unsupported_inputs, 'Homeboy caller passes unsupported v2 reusable-workflow inputs: ' . implode(', ', $unsupported_inputs));

echo "homeboy-workflow-input-contract: ok\n";
