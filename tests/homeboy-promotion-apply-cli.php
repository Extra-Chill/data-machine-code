<?php

declare(strict_types=1);

final class WP_Error {
	public function __construct( private string $code, private string $message, private array $data = array() ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}

function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

define('ABSPATH', __DIR__ . '/fixtures/');
require_once dirname(__DIR__) . '/inc/Cli/HomeboyPromotionApplyAdapter.php';

final class Promotion_Apply_Fake_Workspace {
	public function __construct( public array $target ) {}
	public function parse_handle( string $handle ): array {
		$parts = explode('@', $handle, 2);
		return array( 'repo' => $parts[0], 'is_worktree' => isset($parts[1]), 'dir_name' => $handle );
	}
	public function managed_worktree_get( string $handle ): array { return $this->target; }
}

final class Promotion_Apply_Fake_Writer {
	public array $calls = array();
	public bool $mutated = false;
	public function apply_patch( string $handle, string $patch, bool $allow_primary = false, bool $dry_run = false ): array {
		$this->calls[] = array( $handle, $patch, $allow_primary, $dry_run );
		if ( ! $dry_run ) { $this->mutated = true; }
		return array( 'changed_files' => array( 'README.md' ), 'check_output' => 'checked', 'apply_output' => $dry_run ? '' : 'applied' );
	}
}

function promotion_apply_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException($message); } }
function promotion_apply_request( array $overrides = array() ): array {
	return array_merge(array(
		'schema' => 'homeboy/agent-task-promotion-apply-request/v1', 'to_workspace' => 'repo@feature', 'patch_path' => '/tmp/promotion.patch',
		'changed_files' => array( 'README.md' ), 'patch' => "diff --git a/README.md b/README.md\n", 'dry_run' => false,
	), $overrides);
}
function promotion_apply_adapter( array $target, ?Promotion_Apply_Fake_Writer &$writer = null ): \DataMachineCode\Cli\HomeboyPromotionApplyAdapter {
	$writer = new Promotion_Apply_Fake_Writer();
	return new \DataMachineCode\Cli\HomeboyPromotionApplyAdapter(new Promotion_Apply_Fake_Workspace($target), $writer);
}

$target = array( 'handle' => 'repo@feature', 'is_worktree' => true, 'is_primary' => false, 'external' => false, 'path' => '/workspace/repo@feature', 'head' => 'abc123', 'dirty' => 0, 'unpushed' => 0 );

$adapter = promotion_apply_adapter($target, $writer);
$result = $adapter->execute('repo@feature', promotion_apply_request());
promotion_apply_assert(! is_wp_error($result), 'valid non-empty promotion patch should apply');
promotion_apply_assert($writer->mutated, 'valid promotion patch should mutate after preflight');
promotion_apply_assert(2 === count($writer->calls) && true === $writer->calls[0][3], 'valid apply should preflight before mutation');
promotion_apply_assert('homeboy/agent-task-promotion-apply-response/v1' === $result['schema'], 'valid response schema mismatch');
promotion_apply_assert('/workspace/repo@feature' === $result['workspace_path'], 'response must use canonical managed workspace path');

$adapter = promotion_apply_adapter($target, $writer);
$result = $adapter->execute('repo@feature', promotion_apply_request(array( 'dry_run' => true )));
promotion_apply_assert(! is_wp_error($result) && ! $writer->mutated && 1 === count($writer->calls), 'dry run must validate without mutation');

$adapter = promotion_apply_adapter($target, $writer);
$result = $adapter->execute('repo@feature', promotion_apply_request(array( 'schema' => 'invalid/v1' )));
promotion_apply_assert(is_wp_error($result) && 'invalid_promotion_schema' === $result->get_error_code(), 'wrong schema must be rejected');

$adapter = promotion_apply_adapter($target, $writer);
$result = $adapter->execute('repo@other', promotion_apply_request());
promotion_apply_assert(is_wp_error($result) && 'promotion_handle_mismatch' === $result->get_error_code(), 'wrong explicit handle must be rejected');

$adapter = promotion_apply_adapter($target, $writer);
$result = $adapter->execute('repo@feature', promotion_apply_request(array( 'patch_path' => '' )));
promotion_apply_assert(is_wp_error($result) && 'invalid_patch_path' === $result->get_error_code(), 'empty patch path must be rejected');

$adapter = promotion_apply_adapter($target, $writer);
$result = $adapter->execute('repo', promotion_apply_request(array( 'to_workspace' => 'repo' )));
promotion_apply_assert(is_wp_error($result) && 'primary_worktree' === $result->get_error_code(), 'primary destination must be rejected');

$dirty = $target; $dirty['dirty'] = 1;
$adapter = promotion_apply_adapter($dirty, $writer);
$result = $adapter->execute('repo@feature', promotion_apply_request());
promotion_apply_assert(is_wp_error($result) && 'dirty_worktree' === $result->get_error_code() && ! $writer->mutated, 'dirty destination must be rejected before mutation');

$unpushed = $target; $unpushed['unpushed'] = 1;
$adapter = promotion_apply_adapter($unpushed, $writer);
$result = $adapter->execute('repo@feature', promotion_apply_request());
promotion_apply_assert(is_wp_error($result) && 'untrusted_unpushed_target' === $result->get_error_code() && ! $writer->mutated, 'untrusted unpushed destination must be rejected before mutation');

echo "homeboy-promotion-apply-cli: ok\n";
