<?php

declare(strict_types=1);

function homeboy_workflow_fail( string $message ): never {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Standalone CLI diagnostic, not HTML output.
	throw new RuntimeException( $message );
}

$contract_path = $argv[1] ?? '';
if ( '' === $contract_path || ! is_file( $contract_path ) ) {
	homeboy_workflow_fail( 'Usage: php .github/scripts/homeboy-workflow-input-contract.php <downloaded-homeboy-action-ci.yml>' );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local CI fixture; WordPress is not loaded.
$workflow = file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/homeboy.yml' );
if ( false === $workflow ) {
	homeboy_workflow_fail( 'Homeboy workflow must be readable.' );
}

if ( 1 !== preg_match( '/^    uses: Extra-Chill\/homeboy-action\/\.github\/workflows\/ci\.yml@v2\n    with:\n(?<inputs>(?:^      .*\n|^ {8,}.*\n)*)^    secrets:/m', $workflow, $match ) ) {
	homeboy_workflow_fail( 'Homeboy job must call the v2 reusable workflow with explicit inputs.' );
}

preg_match_all( '/^      ([a-z][a-z0-9-]+):/m', $match['inputs'], $input_matches );
$caller_inputs = $input_matches[1];

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local CI fixture; WordPress is not loaded.
$contract = file_get_contents( $contract_path );
if ( false === $contract ) {
	homeboy_workflow_fail( 'Downloaded reusable workflow contract must be readable.' );
}

if ( 1 !== preg_match( '/^  workflow_call:\n    inputs:\n(?<inputs>(?:^      .*\n|^ {8,}.*\n)*)^    secrets:/m', $contract, $match ) ) {
	homeboy_workflow_fail( 'Downloaded contract must expose workflow_call inputs.' );
}

preg_match_all( '/^      ([a-z][a-z0-9-]+):/m', $match['inputs'], $input_matches );
$reusable_inputs = $input_matches[1];
if ( array() === $reusable_inputs ) {
	homeboy_workflow_fail( 'Downloaded contract must declare reusable workflow inputs.' );
}

$unsupported_inputs = array_values( array_diff( $caller_inputs, $reusable_inputs ) );
if ( array() !== $unsupported_inputs ) {
	homeboy_workflow_fail( 'Homeboy caller passes unsupported v2 reusable-workflow inputs: ' . implode( ', ', $unsupported_inputs ) );
}

echo "homeboy-workflow-input-contract: ok\n";
