<?php

declare(strict_types=1);

if ( ! defined('ABSPATH') ) {
	define('ABSPATH', __DIR__ . '/fixtures/');
}

require_once dirname(__DIR__) . '/inc/Cli/ActiveNoSignalApplyPresentation.php';

use DataMachineCode\Cli\ActiveNoSignalApplyPresentation;

function active_apply_presentation_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(sprintf('%s Expected %s, got %s.', $message, var_export($expected, true), var_export($actual, true)));
	}
}

$base_result = array(
	'dry_run'    => true,
	'summary'    => array( 'inspected' => 3, 'skipped_by_reason' => array( 'dirty' => 1 ) ),
	'planned'    => array(
		array(
			'handle'   => 'repo@task',
			'branch'   => 'task',
			'pr'       => array( 'html_url' => 'https://example.test/pull/1' ),
			'metadata' => array(
				'lifecycle_state'             => 'cleanup_eligible',
				'cleanup_eligibility_evidence' => array(
					'signal'      => 'patch_equivalent_to_default',
					'default_ref' => 'origin/main',
					'remote_ref'  => 'origin/task',
				),
			),
		),
	),
	'skipped'    => array( array( 'handle' => 'repo@dirty', 'action' => 'review', 'reason_code' => 'dirty', 'reason' => 'Dirty worktree.' ) ),
	'pagination' => array( 'next_command' => 'studio wp datamachine-code workspace worktree continue' ),
);

$expectations = array(
	'finalized'        => array( 'Finalized active/no-signal apply summary:', 'pr', 'https://example.test/pull/1', '1 finalized worktree(s) would be promoted to cleanup_eligible metadata.' ),
	'equivalent_clean' => array( 'Equivalent-clean active/no-signal apply summary:', 'signal', 'patch_equivalent_to_default', '1 equivalent-clean worktree(s) would be promoted to cleanup_eligible metadata.' ),
	'merged'           => array( 'Merged-to-default active/no-signal apply summary:', 'default_ref', 'origin/main', '1 merged-to-default worktree(s) would be promoted to cleanup_eligible metadata.' ),
	'remote_clean'     => array( 'Remote-clean active/no-signal apply summary:', 'remote_ref', 'origin/task', '1 remote-clean worktree(s) would be promoted to cleanup_eligible metadata.' ),
);

foreach ( $expectations as $variant => $expected ) {
	list( $title, $detail_key, $detail_value, $success ) = $expected;
	$presentation = ActiveNoSignalApplyPresentation::build($variant, $base_result);
	active_apply_presentation_assert_same($title, $presentation['summary_title'], $variant . ' title remains compatible');
	active_apply_presentation_assert_same(array( 'handle', 'branch', $detail_key, 'state' ), $presentation['item_fields'], $variant . ' fields remain compatible');
	active_apply_presentation_assert_same($detail_value, $presentation['items'][0][ $detail_key ], $variant . ' detail remains compatible');
	active_apply_presentation_assert_same($success, $presentation['success'], $variant . ' dry-run success remains compatible');
	active_apply_presentation_assert_same('studio wp datamachine-code workspace worktree continue', $presentation['next_command'], $variant . ' continuation remains compatible');
	active_apply_presentation_assert_same('dirty', $presentation['skipped_items'][0]['reason_code'], $variant . ' skipped rows remain compatible');
}

$applied = ActiveNoSignalApplyPresentation::build(
	'remote_clean',
	array(
		'written' => $base_result['planned'],
	)
);
active_apply_presentation_assert_same('Promoted:', $applied['items_title'], 'apply title remains compatible');
active_apply_presentation_assert_same('Promoted 1 remote-clean worktree(s) to cleanup_eligible metadata.', $applied['success'], 'apply success remains compatible');

echo "active-no-signal-apply-presentation: ok\n";
