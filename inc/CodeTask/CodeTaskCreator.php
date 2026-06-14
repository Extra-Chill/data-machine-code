<?php
/**
 * Create isolated code-task worktrees from evidence packets.
 *
 * @package DataMachineCode\CodeTask
 */

namespace DataMachineCode\CodeTask;

use DataMachineCode\Workspace\Workspace;
use DataMachineCode\Workspace\WorkspaceWriter;

defined('ABSPATH') || exit;

class CodeTaskCreator {



	private CodeTaskWorkspaceInterface $workspace;
	private CodeTaskEvidenceWriterInterface $writer;

	public function __construct( ?CodeTaskWorkspaceInterface $workspace = null, ?CodeTaskEvidenceWriterInterface $writer = null ) {
		$workspace       = $workspace ?? new WorkspaceCodeTaskWorkspace(new Workspace());
		$this->workspace = $workspace;
		$this->writer    = $writer ?? new WorkspaceCodeTaskEvidenceWriter(new WorkspaceWriter($workspace->workspace()));
	}

	/**
	 * Create a code-task worktree and write deterministic evidence files.
	 *
	 * @param  EvidencePacket      $packet Packet to scaffold.
	 * @param  array<string,mixed> $args   Optional creation args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function create( EvidencePacket $packet, array $args = array() ): array|\WP_Error {
		$repo = self::resolve_repo($packet->repo());
		if ( $repo instanceof \WP_Error ) {
			return $repo;
		}

		$branch = isset($args['branch']) && '' !== trim( (string) $args['branch'])
		? self::sanitize_branch( (string) $args['branch'])
		: self::build_branch_name($packet);

		if ( '' === $branch ) {
			return new \WP_Error('invalid_branch', 'Could not derive a safe branch name for the code task.', array( 'status' => 400 ));
		}

		$primary_path = $this->workspace->get_primary_path($repo['name']);
		$cloned       = false;
		if ( ! is_dir($primary_path) ) {
			$clone = $this->workspace->clone_repo($repo['url'], $repo['name']);
			if ( $clone instanceof \WP_Error ) {
				return $clone;
			}

			$cloned = true;
		}

		$base_ref = isset($args['base_ref']) && '' !== trim( (string) $args['base_ref'])
		? (string) $args['base_ref']
		: 'origin/main';

		$worktree = $this->workspace->worktree_add(
			$repo['name'],
			$branch,
			$base_ref,
			true,
			true,
			! empty($args['allow_stale']),
			false,
			! empty($args['force'])
		);

		if ( $worktree instanceof \WP_Error ) {
			return $worktree;
		}

		$handle       = (string) ( $worktree['handle'] ?? $repo['name'] . '@' . str_replace('/', '-', $branch) );
		$prompt_path  = '.datamachine-code/code-task.md';
		$packet_path  = '.datamachine-code/evidence-packet.json';
		$packet_json  = wp_json_encode($packet->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$packet_json  = false === $packet_json ? '{}' : $packet_json;
		$prompt_write = $this->writer->write_file($handle, $prompt_path, self::render_prompt($packet, $repo, $branch));
		if ( $prompt_write instanceof \WP_Error ) {
			return $prompt_write;
		}

		$packet_write = $this->writer->write_file(
			$handle,
			$packet_path,
			$packet_json . "\n"
		);
		if ( $packet_write instanceof \WP_Error ) {
			return $packet_write;
		}

		return array(
			'success'      => true,
			'repo'         => $repo['slug'],
			'repo_name'    => $repo['name'],
			'repo_url'     => $repo['url'],
			'cloned'       => $cloned,
			'branch'       => $branch,
			'handle'       => $handle,
			'path'         => $worktree['path'] ?? $this->workspace->get_repo_path($handle),
			'prompt_path'  => $prompt_path,
			'packet_path'  => $packet_path,
			'source'       => $packet->source(),
			'source_url'   => $packet->source_url(),
			'pr_title'     => $packet->title(),
			'pr_body_seed' => self::render_pr_body_seed($packet),
			'worktree'     => $worktree,
		);
	}

	/**
	 * Resolve a packet repo value into a safe GitHub clone URL.
	 *
	 * @param  string $repo Repo slug or GitHub URL.
	 * @return array{slug:string,name:string,url:string}|\WP_Error
	 */
	public static function resolve_repo( string $repo ): array|\WP_Error {
		$repo = trim($repo);
		if ( preg_match('#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)$#', $repo, $matches) ) {
			$slug = $matches[1] . '/' . $matches[2];
			return array(
				'slug' => $slug,
				'name' => self::repo_name_from_slug($slug),
				'url'  => 'https://github.com/' . $slug . '.git',
			);
		}

		if ( preg_match('#^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', $repo, $matches) ) {
			$slug = $matches[1] . '/' . $matches[2];
			return array(
				'slug' => $slug,
				'name' => self::repo_name_from_slug($slug),
				'url'  => 'https://github.com/' . $slug . '.git',
			);
		}

		return new \WP_Error('invalid_repo', 'Repository must be a GitHub owner/repo slug or https://github.com/owner/repo URL.', array( 'status' => 400 ));
	}

	public static function build_branch_name( EvidencePacket $packet ): string {
		$source = self::slugify($packet->source());
		$title  = self::slugify($packet->title());
		$hash   = substr(hash('sha256', $packet->source_url() . "\n" . $packet->title()), 0, 8);

		$title = '' === $title ? 'evidence-task' : substr($title, 0, 48);
		return self::sanitize_branch(sprintf('code-task/%s-%s-%s', $source, $title, $hash));
	}

	/**
	 * Render the deterministic agent prompt/evidence file.
	 *
	 * @param array{slug:string,name:string,url:string} $repo Resolved repo.
	 */
	public static function render_prompt( EvidencePacket $packet, array $repo, string $branch ): string {
		$lines = array(
			'# Code Task Evidence',
			'',
			'## Task',
			$packet->title(),
			'',
			'## Source',
			'- Source: ' . $packet->source(),
			'- URL: ' . $packet->source_url(),
			'',
			'## Target',
			'- Repo: ' . $repo['slug'],
			'- Branch: ' . $branch,
			'',
			'## Summary',
			$packet->summary(),
			'',
			'## Classification',
		);

		foreach ( $packet->classification() as $classification ) {
			$lines[] = '- ' . $classification;
		}

		$lines[]         = '';
		$lines[]         = '## Suggested Tests';
		$suggested_tests = $packet->suggested_tests();
		if ( array() === $suggested_tests ) {
			$suggested_tests = array( 'Add or update the smallest regression coverage that proves the fix.' );
		}
		foreach ( $suggested_tests as $test ) {
			$lines[] = '- ' . $test;
		}

		$lines[]     = '';
		$lines[]     = '## Constraints';
		$constraints = $packet->constraints();
		if ( array() === $constraints ) {
			$constraints = array( 'Keep the change narrowly scoped to the evidence above.' );
		}
		foreach ( $constraints as $constraint ) {
			$lines[] = '- ' . $constraint;
		}

		$lines[] = '';
		$lines[] = '## Instructions';
		$lines[] = '- Inspect the referenced source evidence before editing code.';
		$lines[] = '- Make the smallest correct change in the target repo.';
		$lines[] = '- Run focused tests and record the commands/results in the PR body.';
		$lines[] = '- Preserve a link back to the source URL in the PR description.';
		$lines[] = '';

		return implode("\n", $lines);
	}

	public static function render_pr_body_seed( EvidencePacket $packet ): string {
		return implode(
			"\n",
			array(
				'## Source evidence',
				'- Source: ' . $packet->source(),
				'- URL: ' . $packet->source_url(),
				'',
				'## Summary',
				$packet->summary(),
			)
		);
	}

	private static function repo_name_from_slug( string $slug ): string {
		$parts = explode('/', $slug);
		$name  = end($parts);
		return sanitize_key($name);
	}

	private static function slugify( string $value ): string {
		$value = strtolower($value);
		$value = preg_replace('/[^a-z0-9]+/', '-', $value);
		$value = trim( (string) $value, '-');
		return '' === $value ? 'unknown' : $value;
	}

	private static function sanitize_branch( string $branch ): string {
		$branch = trim($branch);
		$branch = preg_replace('#[^A-Za-z0-9._/-]+#', '-', $branch);
		$branch = preg_replace('#/{2,}#', '/', (string) $branch);
		$branch = preg_replace('/-{2,}/', '-', (string) $branch);
		$branch = trim( (string) $branch, '/.-');

		return substr($branch, 0, 96);
	}
}
