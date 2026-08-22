<?php
/**
 * Presentation model for active/no-signal metadata apply results.
 *
 * @package DataMachineCode\Cli
 */

namespace DataMachineCode\Cli;

defined('ABSPATH') || exit;

final class ActiveNoSignalApplyPresentation {

	/**
	 * Build the table-oriented presentation shared by all apply classifications.
	 *
	 * @param array<string,mixed> $result Apply result.
	 * @return array<string,mixed>
	 */
	public static function build( string $variant, array $result ): array {
		$config = self::variant_config($variant);
		$summary = (array) ( $result['summary'] ?? array() );
		$planned = (array) ( $result['planned'] ?? array() );
		$written = (array) ( $result['written'] ?? array() );
		$skipped = (array) ( $result['skipped'] ?? array() );
		$dry_run = ! empty($result['dry_run']);

		$summary_rows = array();
		foreach ( array( 'inspected', 'planned', 'written', 'skipped' ) as $metric ) {
			$fallback       = 'planned' === $metric ? count($planned) : ( 'written' === $metric ? count($written) : ( 'skipped' === $metric ? count($skipped) : 0 ) );
			$summary_rows[] = array( 'metric' => $metric, 'count' => (int) ( $summary[ $metric ] ?? $fallback ) );
		}
		foreach ( (array) ( $summary['skipped_by_reason'] ?? array() ) as $reason => $count ) {
			$summary_rows[] = array( 'metric' => 'skipped:' . $reason, 'count' => (int) $count );
		}

		$items = array_map(
			static function ( array $row ) use ( $config ): array {
				$item = array(
					'handle' => $row['handle'] ?? '',
					'branch' => $row['branch'] ?? '',
				);
				$item[ $config['detail_key'] ] = self::detail_value($config['detail_key'], $row);
				$item['state']                 = $row['metadata']['lifecycle_state'] ?? '';
				return $item;
			},
			$dry_run ? $planned : $written
		);

		$skipped_items = array_map(
			static fn( array $row ): array => array(
				'handle'      => $row['handle'] ?? '',
				'action'      => $row['action'] ?? '',
				'reason_code' => $row['reason_code'] ?? '',
				'reason'      => $row['reason'] ?? '',
			),
			array_slice($skipped, 0, 10)
		);

		$count = count($dry_run ? $planned : $written);
		return array(
			'summary_title' => $config['summary_title'],
			'summary_rows'  => $summary_rows,
			'items_title'   => $dry_run ? 'Would promote:' : 'Promoted:',
			'items'         => $items,
			'item_fields'   => array( 'handle', 'branch', $config['detail_key'], 'state' ),
			'skipped_items' => $skipped_items,
			'next_command'  => (string) ( $result['pagination']['next_command'] ?? '' ),
			'success'       => $dry_run
				? sprintf('%d %s worktree(s) would be promoted to cleanup_eligible metadata.', $count, $config['noun'])
				: sprintf('Promoted %d %s worktree(s) to cleanup_eligible metadata.', $count, $config['noun']),
		);
	}

	/** @return array{summary_title:string,noun:string,detail_key:string} */
	private static function variant_config( string $variant ): array {
		return match ( $variant ) {
			'finalized'        => array( 'summary_title' => 'Finalized active/no-signal apply summary:', 'noun' => 'finalized', 'detail_key' => 'pr' ),
			'equivalent_clean' => array( 'summary_title' => 'Equivalent-clean active/no-signal apply summary:', 'noun' => 'equivalent-clean', 'detail_key' => 'signal' ),
			'merged'           => array( 'summary_title' => 'Merged-to-default active/no-signal apply summary:', 'noun' => 'merged-to-default', 'detail_key' => 'default_ref' ),
			'remote_clean'     => array( 'summary_title' => 'Remote-clean active/no-signal apply summary:', 'noun' => 'remote-clean', 'detail_key' => 'remote_ref' ),
			default            => throw new \InvalidArgumentException(sprintf('Unknown active/no-signal apply variant: %s.', $variant)),
		};
	}

	/** @param array<string,mixed> $row */
	private static function detail_value( string $detail_key, array $row ): string {
		if ( 'pr' === $detail_key ) {
			return is_array($row['pr'] ?? null)
				? (string) ( $row['pr']['html_url'] ?? $row['pr']['number'] ?? '' )
				: (string) ( $row['metadata']['pr_url'] ?? '' );
		}

		return (string) ( $row['metadata']['cleanup_eligibility_evidence'][ $detail_key ] ?? '' );
	}
}
