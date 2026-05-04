<?php
/**
 * Workspace cleanup plan fetch handler.
 *
 * @package DataMachineCode\Handlers\Workspace
 */

namespace DataMachineCode\Handlers\Workspace;

use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Steps\Fetch\Handlers\FetchHandler;
use DataMachine\Core\Steps\HandlerRegistrationTrait;
use DataMachineCode\Workspace\Workspace;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CleanupPlan extends FetchHandler {

	use HandlerRegistrationTrait;

	public function __construct() {
		parent::__construct( 'workspace_cleanup_plan' );

		self::registerHandler(
			'workspace_cleanup_plan',
			'fetch',
			self::class,
			'Workspace Cleanup Plan',
			'Freeze a DMC workspace cleanup plan or emit bounded cleanup chunks without applying them inline.',
			false,
			null,
			CleanupPlanSettings::class,
			null
		);
	}

	/**
	 * Fetch a frozen cleanup plan or bounded chunks.
	 *
	 * @param array            $config  Handler config.
	 * @param ExecutionContext $context Execution context.
	 * @return array<string,mixed>
	 */
	protected function executeFetch( array $config, ExecutionContext $context ): array {
		$workspace = new Workspace();
		$opts      = self::normalizeOptions( $config );
		$result    = ! empty( $opts['emit_chunks'] )
			? $workspace->workspace_cleanup_plan_chunks( $opts )
			: $workspace->workspace_cleanup_plan( $opts );

		if ( is_wp_error( $result ) ) {
			$context->log( 'error', 'Workspace cleanup plan failed: ' . $result->get_error_message() );
			return array();
		}

		if ( ! empty( $opts['emit_chunks'] ) ) {
			$items = array();
			foreach ( (array) ( $result['chunks'] ?? array() ) as $chunk ) {
				if ( ! is_array( $chunk ) ) {
					continue;
				}
				$items[] = self::buildPacketItem(
					sprintf( 'Workspace cleanup chunk: %s', (string) ( $chunk['chunk_id'] ?? '' ) ),
					$chunk,
					array(
						'source_type'     => 'workspace_cleanup_chunk',
						'item_identifier' => (string) ( $chunk['chunk_id'] ?? '' ),
						'plan_id'         => (string) ( $chunk['plan_id'] ?? '' ),
						'chunk_type'      => (string) ( $chunk['type'] ?? '' ),
						'safety_class'    => (string) ( $chunk['safety_class'] ?? '' ),
					)
				);
			}

			$context->log( 'info', sprintf( 'Workspace cleanup plan emitted %d chunk(s).', count( $items ) ) );
			return array( 'items' => $items );
		}

		$context->log( 'info', sprintf( 'Workspace cleanup plan frozen: %s.', (string) ( $result['plan_id'] ?? '' ) ) );
		return array(
			'items' => array(
				self::buildPacketItem(
					sprintf( 'Workspace cleanup plan: %s', (string) ( $result['plan_id'] ?? '' ) ),
					$result,
					array(
						'source_type'     => 'workspace_cleanup_plan',
						'item_identifier' => (string) ( $result['plan_id'] ?? '' ),
						'plan_id'         => (string) ( $result['plan_id'] ?? '' ),
					)
				),
			),
		);
	}

	/**
	 * Normalize handler options.
	 *
	 * @param array<string,mixed> $config Handler config.
	 * @return array<string,mixed>
	 */
	public static function normalizeOptions( array $config ): array {
		$opts = array(
			'emit_chunks'            => ! empty( $config['emit_chunks'] ),
			'include_resolvers'      => ! empty( $config['include_resolvers'] ),
			'force_artifact_cleanup' => ! empty( $config['force_artifact_cleanup'] ),
		);
		foreach ( array( 'include_artifacts', 'include_metadata', 'include_worktrees' ) as $key ) {
			if ( array_key_exists( $key, $config ) ) {
				$opts[ $key ] = (bool) $config[ $key ];
			}
		}
		if ( isset( $config['chunk_size'] ) ) {
			$opts['chunk_size'] = (int) $config['chunk_size'];
		}
		if ( isset( $config['worktree_older_than'] ) && '' !== trim( (string) $config['worktree_older_than'] ) ) {
			$opts['worktree_older_than'] = trim( (string) $config['worktree_older_than'] );
		}
		if ( isset( $config['worktree_sort'] ) && '' !== trim( (string) $config['worktree_sort'] ) ) {
			$opts['worktree_sort'] = trim( (string) $config['worktree_sort'] );
		}

		return $opts;
	}

	/**
	 * Build a DataPacket-compatible item.
	 *
	 * @param string              $title    Item title.
	 * @param array<string,mixed> $payload  Item payload.
	 * @param array<string,mixed> $metadata Item metadata.
	 * @return array<string,mixed>
	 */
	private static function buildPacketItem( string $title, array $payload, array $metadata ): array {
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return array(
			'title'    => $title,
			'content'  => $encoded ?: '',
			'metadata' => $metadata,
		);
	}
}
