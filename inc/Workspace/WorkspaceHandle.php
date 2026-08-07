<?php
/**
 * Workspace handle value helper.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

defined('ABSPATH') || exit;

/**
 * Parsed workspace handle identity.
 */
final class WorkspaceHandle {

	private function __construct(
		private readonly string $repo,
		private readonly ?string $branch_slug,
		private readonly bool $is_worktree,
		private readonly string $dir_name
	) {}

	/**
	 * Parse a workspace handle into its canonical components.
	 */
	public static function parse( string $handle ): self {
		$handle = trim($handle);

		if ( str_contains($handle, '@') ) {
			$parts = explode('@', $handle, 2);
			$repo  = self::sanitize_name($parts[0] ?? '');
			$slug  = self::sanitize_slug($parts[1] ?? '');

			if ( '' !== $repo && '' !== $slug ) {
				return new self($repo, $slug, true, $repo . '@' . $slug);
			}
		}

		$repo = self::sanitize_name($handle);

		return new self($repo, null, false, $repo);
	}

	public function repo(): string {
		return $this->repo;
	}

	public function branch_slug(): ?string {
		return $this->branch_slug;
	}

	public function is_worktree(): bool {
		return $this->is_worktree;
	}

	public function dir_name(): string {
		return $this->dir_name;
	}

	/**
	 * @return array{repo: string, branch_slug: string|null, is_worktree: bool, dir_name: string}
	 */
	public function to_array(): array {
		return array(
			'repo'        => $this->repo,
			'branch_slug' => $this->branch_slug,
			'is_worktree' => $this->is_worktree,
			'dir_name'    => $this->dir_name,
		);
	}

	private static function sanitize_slug( string $slug ): string {
		$slug = preg_replace('/[^a-zA-Z0-9._-]/', '', $slug);
		$slug = preg_replace('/-{2,}/', '-', (string) $slug);
		return trim( (string) $slug, '-.');
	}

	private static function sanitize_name( string $name ): string {
		return (string) preg_replace('/[^a-zA-Z0-9._-]/', '', $name);
	}
}
