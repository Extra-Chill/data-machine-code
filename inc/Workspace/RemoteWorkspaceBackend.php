<?php
/**
 * GitHub-backed workspace backend for constrained PHP runtimes.
 *
 * @package DataMachineCode\Workspace
 */

namespace DataMachineCode\Workspace;

use DataMachineCode\Abilities\GitHubAbilities;

defined('ABSPATH') || exit;

class RemoteWorkspaceBackend
{

    private const OPTION        = 'datamachine_code_remote_workspace_state';
    private const MAX_READ_SIZE = 1048576;

    /**
     * Whether the remote backend should handle workspace operations.
     */
    public static function should_handle(): bool
    {
        return ! \DataMachineCode\Support\GitRunner::is_available();
    }

    /**
     * Clone/register a GitHub repository as a remote workspace primary.
     *
     * @param  string      $url  GitHub repository URL.
     * @param  string|null $name Optional workspace repo name.
     * @return array<string,mixed>|\WP_Error
     */
    public function clone_repo( string $url, ?string $name = null ): array|\WP_Error
    {
        $repo = $this->repo_from_url($url);
        if (is_wp_error($repo) ) {
            return $repo;
        }

        $name = $this->sanitize_name(null !== $name && '' !== $name ? $name : basename((string) $repo));
        if ('' === $name ) {
            return new \WP_Error('invalid_clone_name', 'Could not derive a workspace name for the remote repository.', array( 'status' => 400 ));
        }

        $state                        = $this->state();
        $state['repos'][ $name ]      = array(
        'repo' => $repo,
        'url'  => $url,
        );
        $state['repo_names'][ $repo ] = $name;
        $this->save_state($state);

        return array(
        'success'            => true,
        'backend'            => 'github_api',
        'name'               => $name,
        'path'               => 'github://' . $repo,
        'message'            => sprintf('Registered %s as remote workspace "%s".', $repo, $name),
        'conversation_state' => 'incomplete',
        'next_required_tool' => 'workspace_worktree_add',
        'next_required_args' => array( 'repo' => $name ),
        );
    }

    /**
     * Create/register a remote worktree branch.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function worktree_add( string $repo_name, string $branch, ?string $from = null ): array|\WP_Error
    {
        $repo = $this->resolve_repo($repo_name);
        if (is_wp_error($repo) ) {
            return $repo;
        }

        $branch = trim($branch);
        if ('' === $branch ) {
            return new \WP_Error('missing_branch', 'Branch is required.', array( 'status' => 400 ));
        }

        $slug                          = $this->branch_slug($branch);
        $handle                        = $repo_name . '@' . $slug;
        $state                         = $this->state();
        $state['worktrees'][ $handle ] = array(
        'repo_name'       => $repo_name,
        'repo'            => $repo,
        'branch'          => $branch,
        'base_ref'        => null !== $from && '' !== $from ? $from : '',
        'pending_files'   => array(),
        'changed_files'   => array(),
        'last_commit_sha' => '',
        );
        $this->save_state($state);

        return array(
        'success'            => true,
        'backend'            => 'github_api',
        'handle'             => $handle,
        'path'               => 'github://' . $repo . '#' . $branch,
        'branch'             => $branch,
        'slug'               => $slug,
        'created_branch'     => true,
        'message'            => sprintf('Registered remote workspace %s for %s.', $handle, $repo),
        'conversation_state' => 'incomplete',
        'next_required_tool' => 'workspace_read or workspace_edit or workspace_write',
        'next_required_args' => array( 'repo' => $handle ),
        );
    }

    /**
     * Read a file from GitHub or pending remote workspace state.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function read_file( string $handle, string $path, int $max_size, ?int $offset = null, ?int $limit = null ): array|\WP_Error
    {
        $context = $this->resolve_handle($handle);
        if (is_wp_error($context) ) {
            return $context;
        }

        $path = $this->normalize_path($path);
        if (is_wp_error($path) ) {
            return $path;
        }

        $content = $context['pending_files'][ $path ] ?? null;
        if (null === $content ) {
            $file = $this->get_file_contents_with_fallback($context, $path);
            if (is_wp_error($file) ) {
                return $file;
            }
            if (empty($file['files'][0]) ) {
                $error = $file['errors'][0]['message'] ?? sprintf('File not found: %s.', $path);
                return new \WP_Error('remote_workspace_file_unavailable', $error, array( 'status' => 404 ));
            }
            $content = (string) ( $file['files'][0]['content'] ?? '' );
        }

        $size = strlen($content);
        if ($size > $max_size ) {
            return new \WP_Error('file_too_large', sprintf('File too large: %s.', $path), array( 'status' => 400 ));
        }

        $result_content = $content;
        if (null !== $offset || null !== $limit ) {
            $start_line     = max(1, (int) ( $offset ?? 1 ));
            $lines          = explode("\n", $content);
            $lines          = array_slice($lines, $start_line - 1, null === $limit ? null : max(0, $limit));
            $result_content = implode("\n", $lines);
        }

        return array(
        'success' => true,
        'backend' => 'github_api',
        'content' => $result_content,
        'path'    => $path,
        'size'    => $size,
        );
    }

    /**
     * List repository files under a path prefix.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function list_directory( string $handle, ?string $path = null ): array|\WP_Error
    {
        $context = $this->resolve_handle($handle);
        if (is_wp_error($context) ) {
            return $context;
        }

        $prefix = null === $path ? '' : trim(ltrim($path, '/'), '/');
        $tree   = GitHubAbilities::getRepoTree(
            array(
            'repo' => $context['repo'],
            'ref'  => $context['read_ref'],
            )
        );
        if (is_wp_error($tree) && '' !== $context['read_ref'] ) {
            $tree = GitHubAbilities::getRepoTree(array( 'repo' => $context['repo'] ));
        }
        if (is_wp_error($tree) ) {
            return $tree;
        }

        $entries = array();
        foreach ( (array) ( $tree['files'] ?? array() ) as $file ) {
            $file_path = (string) ( $file['path'] ?? '' );
            if ('' !== $prefix && ! str_starts_with($file_path, $prefix . '/') ) {
                continue;
            }

            $relative = '' === $prefix ? $file_path : substr($file_path, strlen($prefix) + 1);
            if ('' === $relative || str_contains($relative, '/') ) {
                continue;
            }

            $entries[] = array(
            'name' => $relative,
            'type' => (string) ( $file['type'] ?? 'file' ),
            'size' => (int) ( $file['size'] ?? 0 ),
            );
        }

        return array(
        'success' => true,
        'backend' => 'github_api',
        'repo'    => $handle,
        'path'    => '' === $prefix ? '/' : $prefix,
        'entries' => $entries,
        );
    }

    /**
     * Search text files through the GitHub-backed workspace backend.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function grep( string $handle, string $pattern, ?string $path = null, ?string $include_pattern = null, int $max_results = 100, int $context_lines = 0 ): array|\WP_Error
    {
        $context = $this->resolve_handle($handle);
        if (is_wp_error($context) ) {
            return $context;
        }

        $prefix = null === $path ? '' : trim(ltrim($path, '/'), '/');
        if (str_contains($prefix, '..') ) {
            return new \WP_Error('path_traversal', 'Path traversal detected. Access denied.', array( 'status' => 403 ));
        }

        $regex = $this->compile_search_pattern($pattern);
        if (is_wp_error($regex) ) {
            return $regex;
        }

        $tree_input = array(
        'repo' => $context['repo'],
        'ref'  => $context['read_ref'],
        );
        if ('' !== $prefix ) {
            $tree_input['path'] = $prefix;
        }

        $tree = GitHubAbilities::getRepoTree($tree_input);
        if (is_wp_error($tree) && '' !== $context['read_ref'] ) {
            unset($tree_input['ref']);
            $tree = GitHubAbilities::getRepoTree($tree_input);
        }
        if (is_wp_error($tree) ) {
            return $tree;
        }

        $max_results   = max(1, min(500, $max_results));
        $context_lines = max(0, min(10, $context_lines));
        $matches       = array();
        $seen          = array();
        $files         = (array) ( $tree['files'] ?? array() );

        foreach ( array_keys((array) $context['pending_files']) as $pending_path ) {
            if ('' === $prefix || $pending_path === $prefix || str_starts_with($pending_path, $prefix . '/') ) {
                array_unshift(
                    $files, array(
                    'path' => $pending_path,
                    'type' => 'file',
                    'size' => strlen((string) $context['pending_files'][ $pending_path ]),
                    ) 
                );
            }
        }

        foreach ( $files as $file ) {
            $file_path = (string) ( $file['path'] ?? '' );
            if ('' === $file_path || isset($seen[ $file_path ]) || ! $this->path_matches_include($file_path, $include_pattern) ) {
                continue;
            }
            $seen[ $file_path ] = true;

            if ((int) ( $file['size'] ?? 0 ) > self::MAX_READ_SIZE ) {
                continue;
            }

            $read = $this->read_file($handle, $file_path, self::MAX_READ_SIZE);
            if (is_wp_error($read) ) {
                continue;
            }

            $content = (string) ( $read['content'] ?? '' );
            if (false !== strpos(substr($content, 0, 8192), "\0") ) {
                continue;
            }

            $file_matches = $this->grep_content($content, $handle, $file_path, $regex, $context_lines, $max_results - count($matches));
            $matches      = array_merge($matches, $file_matches);
            if (count($matches) >= $max_results ) {
                break;
            }
        }

        return array(
        'success'   => true,
        'backend'   => 'github_api',
        'repo'      => $handle,
        'path'      => '' === $prefix ? '/' : $prefix,
        'pattern'   => $pattern,
        'matches'   => $matches,
        'count'     => count($matches),
        'truncated' => count($matches) >= $max_results,
        );
    }

    /**
     * Stage file content in the remote workspace.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function write_file( string $handle, string $path, string $content ): array|\WP_Error
    {
        $context = $this->resolve_handle($handle);
        if (is_wp_error($context) ) {
            return $context;
        }
        $path = $this->normalize_path($path);
        if (is_wp_error($path) ) {
            return $path;
        }

        $state = $this->state();
        $state['worktrees'][ $context['handle'] ]['pending_files'][ $path ] = $content;
        $state['worktrees'][ $context['handle'] ]['changed_files'][ $path ] = $path;
        $this->save_state($state);

        return array(
        'success'            => true,
        'backend'            => 'github_api',
        'path'               => $path,
        'size'               => strlen($content),
        'created'            => true,
        'conversation_state' => 'incomplete',
        'next_required_tool' => 'workspace_git_status',
        'next_required_args' => array( 'name' => $context['handle'] ),
        );
    }

    /**
     * Stage a find-and-replace edit in the remote workspace.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function edit_file( string $handle, string $path, string $old_string, string $new_string, bool $replace_all = false ): array|\WP_Error
    {
        $context = $this->resolve_handle($handle);
        if (is_wp_error($context) ) {
            return $context;
        }

        $current = $this->read_file($handle, $path, PHP_INT_MAX);
        if (is_wp_error($current) ) {
            return $current;
        }

        $content = (string) ( $current['content'] ?? '' );
        $count   = substr_count($content, $old_string);
        if (0 === $count ) {
            return new \WP_Error(
                'string_not_found', 'old_string not found in file content.', array(
                'status'      => 400,
                'path'        => (string) ( $current['path'] ?? $path ),
                'suggestions' => $this->build_edit_suggestions($content, $old_string),
                ) 
            );
        }
        if ($count > 1 && ! $replace_all ) {
            return new \WP_Error('multiple_matches', sprintf('Found %d matches for old_string.', $count), array( 'status' => 400 ));
        }

        if ($replace_all ) {
            $new_content = str_replace($old_string, $new_string, $content);
        } else {
            $offset = strpos($content, $old_string);
            // $count > 0 above guarantees strpos cannot return false here.
            $new_content = false === $offset
            ? $content
            : substr_replace($content, $new_string, $offset, strlen($old_string));
        }

        $write = $this->write_file($handle, $path, $new_content);
        if (is_wp_error($write) ) {
            return $write;
        }

        return array(
        'success'            => true,
        'backend'            => 'github_api',
        'path'               => $write['path'],
        'replacements'       => $replace_all ? $count : 1,
        'conversation_state' => 'incomplete',
        'next_required_tool' => 'workspace_git_status',
        'next_required_args' => array( 'name' => $context['handle'] ),
        );
    }

    /**
     * Show remote workspace details.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function show( string $handle ): array|\WP_Error
    {
        $context = $this->resolve_handle($handle);
        if (is_wp_error($context) ) {
            return $context;
        }

        $files = array_values(array_unique(array_values((array) $context['changed_files'])));

        return array(
        'success'     => true,
        'backend'     => 'github_api',
        'name'        => $handle,
        'repo'        => $context['repo_name'],
        'is_worktree' => isset($context['branch']) && '' !== (string) $context['branch'],
        'path'        => 'github://' . $context['repo'] . ( '' !== (string) $context['branch']
        ? '#' . $context['branch']
        : '' ),
        'branch'      => '' !== (string) $context['branch'] ? (string) $context['branch'] : null,
        'remote'      => 'https://github.com/' . $context['repo'] . '.git',
        'commit'      => '' !== $context['last_commit_sha'] ? $context['last_commit_sha'] : null,
        'dirty'       => count($files),
        'files'       => $files,
        );
    }

    /**
     * Return a diff of pending remote workspace changes.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function git_diff( string $handle, ?string $from = null, ?string $to = null, bool $staged = false, ?string $path = null ): array|\WP_Error
    {
        unset($staged);

        $context = $this->resolve_handle($handle);
        if (is_wp_error($context) ) {
            return $context;
        }

        if (( null !== $from && '' !== trim($from) ) || ( null !== $to && '' !== trim($to) ) ) {
            return new \WP_Error('remote_workspace_diff_refs_unsupported', 'Remote workspace diff currently supports pending workspace changes only; omit from/to refs.', array( 'status' => 400 ));
        }

        $path_filter = null;
        if (null !== $path && '' !== trim($path) ) {
            $normalized = $this->normalize_path($path);
            if (is_wp_error($normalized) ) {
                return $normalized;
            }
            $path_filter = $normalized;
        }

        $diff = '';
        foreach ( (array) $context['pending_files'] as $changed_path => $new_content ) {
            $changed_path = (string) $changed_path;
            if (null !== $path_filter && $changed_path !== $path_filter ) {
                continue;
            }

            $old_content = '';
            $current     = $this->get_file_contents_with_fallback($context, $changed_path);
            if (! is_wp_error($current) && ! empty($current['files'][0]) ) {
                $old_content = (string) ( $current['files'][0]['content'] ?? '' );
            }

            $diff .= $this->build_unified_file_diff($changed_path, $old_content, (string) $new_content);
        }

        return array(
        'success' => true,
        'backend' => 'github_api',
        'name'    => $handle,
        'repo'    => $context['repo_name'],
        'diff'    => $diff,
        );
    }

    /**
     * Return pending remote workspace changes.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function git_status( string $handle ): array|\WP_Error
    {
        $context = $this->resolve_handle($handle);
        if (is_wp_error($context) ) {
            return $context;
        }

        $files = array_values(array_unique(array_values((array) $context['changed_files'])));
        return array(
        'success'            => true,
        'backend'            => 'github_api',
        'name'               => $handle,
        'repo'               => $context['repo_name'],
        'is_worktree'        => true,
        'path'               => 'github://' . $context['repo'] . '#' . $context['branch'],
        'branch'             => $context['branch'],
        'remote'             => 'https://github.com/' . $context['repo'] . '.git',
        'commit'             => '' !== $context['last_commit_sha'] ? $context['last_commit_sha'] : null,
        'dirty'              => count($files),
        'files'              => $files,
        'conversation_state' => 'incomplete',
        'next_required_tool' => count($files) > 0 ? 'workspace_git_commit' : 'workspace_edit or workspace_write',
        'next_required_args' => array( 'name' => $handle ),
        );
    }

    /**
     * Compatibility no-op: files are tracked by pending remote workspace state.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function git_add( string $handle, array $paths ): array|\WP_Error
    {
        $context = $this->resolve_handle($handle);
        if (is_wp_error($context) ) {
            return $context;
        }

        return array(
        'success' => true,
        'backend' => 'github_api',
        'name'    => $handle,
        'paths'   => array_values(array_map('strval', $paths)),
        'message' => 'Remote workspace changes are staged automatically.',
        );
    }

    /**
     * Commit pending remote workspace changes through GitHub Contents API.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function git_commit( string $handle, string $message ): array|\WP_Error
    {
        $context = $this->resolve_handle($handle);
        if (is_wp_error($context) ) {
            return $context;
        }
        if ('' === trim($message) ) {
            return new \WP_Error('missing_commit_message', 'Commit message is required.', array( 'status' => 400 ));
        }

        $pending = (array) $context['pending_files'];
        if (empty($pending) ) {
            return new \WP_Error('nothing_to_commit', 'No remote workspace changes to commit.', array( 'status' => 400 ));
        }

        $last_sha = '';
        foreach ( $pending as $path => $content ) {
            $current_sha = $this->file_sha_for_commit($context, (string) $path);
            if (is_wp_error($current_sha) ) {
                return $current_sha;
            }

            $input = array(
            'repo'           => $context['repo'],
            'file_path'      => $path,
            'content'        => (string) $content,
            'commit_message' => $message,
            'branch'         => $context['branch'],
            );
            if ('' !== $current_sha ) {
                $input['sha'] = $current_sha;
            }

            $result = GitHubAbilities::createOrUpdateFile(
                $input
            );
            if (is_wp_error($result) ) {
                return $result;
            }
            $last_sha = (string) ( $result['commit']['sha'] ?? $last_sha );
        }

        $state = $this->state();
        $state['worktrees'][ $context['handle'] ]['pending_files']   = array();
        $state['worktrees'][ $context['handle'] ]['last_commit_sha'] = $last_sha;
        $this->save_state($state);

        return array(
        'success'            => true,
        'backend'            => 'github_api',
        'name'               => $handle,
        'commit'             => $last_sha,
        'message'            => sprintf('Committed remote workspace changes to %s.', $context['branch']),
        'conversation_state' => 'incomplete',
        'next_required_tool' => 'workspace_git_push',
        'next_required_args' => array(
        'name'   => $handle,
        'branch' => $context['branch'],
        ),
        );
    }

    /**
     * Resolve the current file SHA for a Contents API update.
     *
     * @param array<string,mixed> $context Remote workspace context.
     */
    private function file_sha_for_commit( array $context, string $path ): string|\WP_Error
    {
        $file = $this->get_file_contents_with_fallback($context, $path);
        if (is_wp_error($file) ) {
            $status = (int) ( $file->get_error_data()['status'] ?? 0 );
            if (404 === $status ) {
                return '';
            }

            return $file;
        }
        if (empty($file['files'][0]) ) {
            $status = (int) ( $file['errors'][0]['status'] ?? 0 );
            if (404 === $status ) {
                return '';
            }

            return new \WP_Error('remote_workspace_file_unavailable', $file['errors'][0]['message'] ?? sprintf('File not found: %s.', $path), array( 'status' => 404 ));
        }

        return (string) ( $file['files'][0]['sha'] ?? '' );
    }

    /**
     * Read GitHub contents for a worktree path, falling back to the default branch
     * when the remote worktree branch has not been materialized yet.
     *
     * @param  array<string,mixed> $context Remote workspace context.
     * @return array<string,mixed>|\WP_Error
     */
    private function get_file_contents_with_fallback( array $context, string $path ): array|\WP_Error
    {
        $file_input = array(
        'repo' => $context['repo'],
        'path' => $path,
        );
        if ('' !== $context['read_ref'] ) {
            $file_input['ref'] = $context['read_ref'];
        }

        $file = GitHubAbilities::getFileContents($file_input);
        if ('' === $context['read_ref'] || ! $this->should_retry_default_ref($file) ) {
            return $file;
        }

        return GitHubAbilities::getFileContents(
            array(
            'repo' => $context['repo'],
            'path' => $path,
            )
        );
    }

    /**
     * GitHub file reads can report missing refs either as a WP_Error or as a
     * normalized `{ success: false, files: [], errors: [...] }` payload.
     */
    private function should_retry_default_ref( array|\WP_Error $file ): bool
    {
        if (is_wp_error($file) ) {
            return 404 === (int) ( $file->get_error_data()['status'] ?? 0 );
        }

        if (! empty($file['files'][0]) ) {
            return false;
        }

        foreach ( (array) ( $file['errors'] ?? array() ) as $error ) {
            if (404 === (int) ( $error['status'] ?? 0 ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a unified diff for a single file's pending change.
     *
     * Uses Myers' algorithm to find a minimal edit script, then groups adjacent
     * edits into hunks with surrounding context (default 3 lines). Output matches
     * the format produced by `git diff --no-color`, so consumers that scan the
     * diff for `-foo` / `+bar` lines see actual changed lines rather than a fake
     * whole-file replace.
     *
     * Previously this method emitted every old line as `-` followed by every new
     * line as `+`, regardless of how small the actual change was. That misled
     * agents into thinking surgical edits had rewritten the entire file.
     *
     * @see https://github.com/Extra-Chill/data-machine-code/issues/429
     */
    private function build_unified_file_diff( string $path, string $old_content, string $new_content, int $context_lines = 3 ): string
    {
        $header  = 'diff --git a/' . $path . ' b/' . $path . "\n";
        $header .= '--- a/' . $path . "\n";
        $header .= '+++ b/' . $path . "\n";

        if ($old_content === $new_content ) {
            return $header;
        }

        $old_lines = $this->diff_lines($old_content);
        $new_lines = $this->diff_lines($new_content);

        $ops = $this->myers_diff($old_lines, $new_lines);
        if (empty($ops) ) {
            return $header;
        }

        $hunks = $this->group_diff_hunks($ops, $context_lines);
        if (empty($hunks) ) {
            return $header;
        }

        $body = '';
        foreach ( $hunks as $hunk ) {
            $body .= sprintf(
                "@@ -%d,%d +%d,%d @@\n",
                $hunk['old_start'],
                $hunk['old_count'],
                $hunk['new_start'],
                $hunk['new_count']
            );
            foreach ( $hunk['lines'] as $line ) {
                $body .= $line . "\n";
            }
        }

        return $header . $body;
    }

    /**
     * @return array<int,string> 
     */
    private function diff_lines( string $content ): array
    {
        if ('' === $content ) {
            return array();
        }

        return explode("\n", rtrim($content, "\n"));
    }

    /**
     * Myers' diff algorithm producing an edit script over two arrays of lines.
     *
     * Returns an ordered list of operations:
     *   ['op' => '=', 'line' => string]  (unchanged)
     *   ['op' => '-', 'line' => string]  (removed from old)
     *   ['op' => '+', 'line' => string]  (added in new)
     *
     * Trims common prefix/suffix first so the O(ND) core only runs on the
     * actually-different middle window — typical "surgical edit in large file"
     * cases finish in O(N) instead of O(N^2).
     *
     * @param  array<int,string> $a Old lines.
     * @param  array<int,string> $b New lines.
     * @return array<int,array{op:string,line:string}>
     */
    private function myers_diff( array $a, array $b ): array
    {
        $ops = array();

        $prefix = 0;
        $a_len  = count($a);
        $b_len  = count($b);
        $min    = min($a_len, $b_len);
        while ( $prefix < $min && $a[ $prefix ] === $b[ $prefix ] ) {
            $ops[] = array(
            'op'   => '=',
            'line' => $a[ $prefix ],
            );
            ++$prefix;
        }

        $suffix     = 0;
        $max_suffix = $min - $prefix;
        while ( $suffix < $max_suffix && $a[ $a_len - 1 - $suffix ] === $b[ $b_len - 1 - $suffix ] ) {
            ++$suffix;
        }

        $middle_a = array_slice($a, $prefix, $a_len - $prefix - $suffix);
        $middle_b = array_slice($b, $prefix, $b_len - $prefix - $suffix);

        foreach ( $this->myers_middle_diff($middle_a, $middle_b) as $op ) {
            $ops[] = $op;
        }

        for ( $i = $a_len - $suffix; $i < $a_len; $i++ ) {
            $ops[] = array(
            'op'   => '=',
            'line' => $a[ $i ],
            );
        }

        return $ops;
    }

    /**
     * Core Myers algorithm over a (presumably small) middle window.
     *
     * Implements Eugene Myers' O(ND) algorithm, recording trace V-arrays at each
     * D-step and walking back to reconstruct the edit script. Falls back to a
     * simple "remove-all then add-all" emission if the middle is degenerate
     * (one side empty), which is both faster and produces the same result.
     *
     * @param  array<int,string> $a
     * @param  array<int,string> $b
     * @return array<int,array{op:string,line:string}>
     */
    private function myers_middle_diff( array $a, array $b ): array
    {
        $n = count($a);
        $m = count($b);

        if (0 === $n && 0 === $m ) {
            return array();
        }
        if (0 === $n ) {
            $ops = array();
            foreach ( $b as $line ) {
                $ops[] = array(
                'op'   => '+',
                'line' => $line,
                );
            }
            return $ops;
        }
        if (0 === $m ) {
            $ops = array();
            foreach ( $a as $line ) {
                $ops[] = array(
                'op'   => '-',
                'line' => $line,
                );
            }
            return $ops;
        }

        $max    = $n + $m;
        $offset = $max;
        $trace  = array();
        $v      = array_fill(0, 2 * $max + 1, 0);

        for ( $d = 0; $d <= $max; $d++ ) {
            for ( $k = -$d; $k <= $d; $k += 2 ) {
                if (-$d === $k || ( $d !== $k && $v[ $k - 1 + $offset ] < $v[ $k + 1 + $offset ] ) ) {
                    $x = $v[ $k + 1 + $offset ];
                } else {
                    $x = $v[ $k - 1 + $offset ] + 1;
                }
                $y = $x - $k;
                while ( $x < $n && $y < $m && $a[ $x ] === $b[ $y ] ) {
                    ++$x;
                    ++$y;
                }
                $v[ $k + $offset ] = $x;
                if ($x >= $n && $y >= $m ) {
                    $trace[] = $v;
                    return $this->myers_backtrack($trace, $a, $b, $d, $offset);
                }
            }
            $trace[] = $v;
        }

        return array();
    }

    /**
     * Walk the recorded Myers trace backwards to build the ordered edit script.
     *
     * @param  array<int,array<int,int>> $trace
     * @param  array<int,string>         $a
     * @param  array<int,string>         $b
     * @return array<int,array{op:string,line:string}>
     */
    private function myers_backtrack( array $trace, array $a, array $b, int $d, int $offset ): array
    {
        $ops = array();
        $x   = count($a);
        $y   = count($b);

        for ( ; $d > 0; $d-- ) {
            $v = $trace[ $d - 1 ];
            $k = $x - $y;
            if (-$d === $k || ( $d !== $k && $v[ $k - 1 + $offset ] < $v[ $k + 1 + $offset ] ) ) {
                $prev_k = $k + 1;
            } else {
                $prev_k = $k - 1;
            }
            $prev_x = $v[ $prev_k + $offset ];
            $prev_y = $prev_x - $prev_k;

            while ( $x > $prev_x && $y > $prev_y ) {
                $ops[] = array(
                'op'   => '=',
                'line' => $a[ $x - 1 ],
                );
                --$x;
                --$y;
            }
            if ($x === $prev_x ) {
                $ops[] = array(
                'op'   => '+',
                'line' => $b[ $y - 1 ],
                );
            } else {
                $ops[] = array(
                'op'   => '-',
                'line' => $a[ $x - 1 ],
                );
            }
            $x = $prev_x;
            $y = $prev_y;
        }
        while ( $x > 0 && $y > 0 ) {
            $ops[] = array(
            'op'   => '=',
            'line' => $a[ $x - 1 ],
            );
            --$x;
            --$y;
        }
        while ( $x > 0 ) {
            $ops[] = array(
            'op'   => '-',
            'line' => $a[ $x - 1 ],
            );
            --$x;
        }
        while ( $y > 0 ) {
            $ops[] = array(
            'op'   => '+',
            'line' => $b[ $y - 1 ],
            );
            --$y;
        }

        return array_reverse($ops);
    }

    /**
     * Group consecutive non-context edit operations into unified-diff hunks.
     *
     * Each hunk has up to `$context_lines` of unchanged context on each side.
     * Returns one entry per hunk with `old_start`, `old_count`, `new_start`,
     * `new_count`, and a list of `+line` / `-line` / ` line` strings ready to
     * emit between `@@` markers.
     *
     * @param  array<int,array{op:string,line:string}> $ops
     * @return array<int,array{old_start:int,old_count:int,new_start:int,new_count:int,lines:array<int,string>}>
     */
    private function group_diff_hunks( array $ops, int $context_lines ): array
    {
        $hunks = array();
        $count = count($ops);

        $old_line = 1;
        $new_line = 1;
        $i        = 0;

        while ( $i < $count ) {
            if ('=' === $ops[ $i ]['op'] ) {
                ++$old_line;
                ++$new_line;
                ++$i;
                continue;
            }

            $context_before = min($context_lines, $i);
            $hunk_start_i   = $i - $context_before;
            $hunk_old_start = $old_line - $context_before;
            $hunk_new_start = $new_line - $context_before;

            $lines     = array();
            $old_count = 0;
            $new_count = 0;
            for ( $j = $hunk_start_i; $j < $i; $j++ ) {
                $lines[] = ' ' . $ops[ $j ]['line'];
                ++$old_count;
                ++$new_count;
            }

            $tail_eq = 0;
            while ( $i < $count ) {
                $op = $ops[ $i ]['op'];
                if ('=' === $op ) {
                    ++$tail_eq;
                    if ($tail_eq > 2 * $context_lines ) {
                        --$tail_eq;
                        break;
                    }
                    $lines[] = ' ' . $ops[ $i ]['line'];
                    ++$old_count;
                    ++$new_count;
                    ++$old_line;
                    ++$new_line;
                    ++$i;
                    continue;
                }
                $tail_eq = 0;
                if ('-' === $op ) {
                    $lines[] = '-' . $ops[ $i ]['line'];
                    ++$old_count;
                    ++$old_line;
                } else {
                    $lines[] = '+' . $ops[ $i ]['line'];
                    ++$new_count;
                    ++$new_line;
                }
                ++$i;
            }

            $keep_tail = min($context_lines, $tail_eq);
            $drop_tail = $tail_eq - $keep_tail;
            if ($drop_tail > 0 ) {
                $lines      = array_slice($lines, 0, count($lines) - $drop_tail);
                $old_count -= $drop_tail;
                $new_count -= $drop_tail;
                $old_line  -= $drop_tail;
                $new_line  -= $drop_tail;
            }

            $hunks[] = array(
            'old_start' => 0 === $old_count ? max(0, $hunk_old_start - 1) : $hunk_old_start,
            'old_count' => $old_count,
            'new_start' => 0 === $new_count ? max(0, $hunk_new_start - 1) : $hunk_new_start,
            'new_count' => $new_count,
            'lines'     => $lines,
            );
        }

        return $hunks;
    }

    /**
     * Compatibility no-op: commit already wrote to the remote branch.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function git_push( string $handle, string $remote = 'origin', ?string $branch = null ): array|\WP_Error
    {
        $context = $this->resolve_handle($handle);
        if (is_wp_error($context) ) {
            return $context;
        }

        $push_branch = null !== $branch && '' !== $branch ? $branch : $context['branch'];
        $branch_url  = '' !== $push_branch ? 'https://github.com/' . $context['repo'] . '/tree/' . rawurlencode($push_branch) : null;

        return array(
        'success'            => true,
        'kind'               => 'branch_push',
        'backend'            => 'github_api',
        'name'               => $handle,
        'repo'               => $context['repo'],
        'workspace_repo'     => $context['repo_name'] ?? $handle,
        'github_repo'        => $context['repo'],
        'remote'             => $remote,
        'branch'             => $push_branch,
        'url'                => $branch_url,
        'html_url'           => $branch_url,
        'message'            => 'Remote workspace branch already updated via GitHub API.',
        'conversation_state' => 'incomplete',
        'next_required_tool' => 'create_github_pull_request',
        'next_required_args' => array(
        'repo' => $context['repo'],
        'head' => $push_branch,
        ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function resolve_handle( string $handle ): array|\WP_Error
    {
        $state = $this->state();
        if (isset($state['worktrees'][ $handle ]) ) {
            $worktree             = (array) $state['worktrees'][ $handle ];
            $worktree['handle']   = $handle;
            $worktree['read_ref'] = (string) ( $worktree['branch'] ?? '' );
            return $worktree;
        }

        $repo = $this->resolve_repo($handle);
        if (is_wp_error($repo) ) {
            return $repo;
        }

        return array(
        'handle'          => $handle,
        'repo_name'       => $handle,
        'repo'            => $repo,
        'branch'          => '',
        'read_ref'        => '',
        'pending_files'   => array(),
        'changed_files'   => array(),
        'last_commit_sha' => '',
        );
    }

    private function resolve_repo( string $repo_name ): string|\WP_Error
    {
        $state = $this->state();
        if (isset($state['repos'][ $repo_name ]['repo']) ) {
            return (string) $state['repos'][ $repo_name ]['repo'];
        }

        if (str_contains($repo_name, '/') ) {
            return $repo_name;
        }

        return new \WP_Error('remote_workspace_repo_not_found', sprintf('Remote workspace repository "%s" is not registered. Call workspace_clone first.', $repo_name), array( 'status' => 404 ));
    }

    private function repo_from_url( string $url ): string|\WP_Error
    {
        $url = trim($url);
        if (preg_match('#github\.com[:/]([^/]+)/([^/.]+)(?:\.git)?$#', $url, $matches) ) {
            return $matches[1] . '/' . $matches[2];
        }

        return new \WP_Error('unsupported_remote_workspace_url', 'Remote workspace backend currently supports GitHub repository URLs only.', array( 'status' => 400 ));
    }

    private function normalize_path( string $path ): string|\WP_Error
    {
        $path = trim(ltrim($path, '/'));
        if ('' === $path ) {
            return new \WP_Error('missing_path', 'File path is required.', array( 'status' => 400 ));
        }
        foreach ( explode('/', $path) as $part ) {
            if ('.' === $part || '..' === $part || '' === $part ) {
                return new \WP_Error('path_traversal', 'Path traversal detected. Access denied.', array( 'status' => 403 ));
            }
        }
        return $path;
    }

    private function sanitize_name( string $name ): string
    {
        return trim(strtolower(preg_replace('/[^a-zA-Z0-9._-]+/', '-', $name)), '-');
    }

    private function branch_slug( string $branch ): string
    {
        return trim(strtolower(preg_replace('/[^a-zA-Z0-9._-]+/', '-', $branch)), '-');
    }

    private function compile_search_pattern( string $pattern ): string|\WP_Error
    {
        if ('' === $pattern ) {
            return new \WP_Error('missing_pattern', 'Search pattern is required.', array( 'status' => 400 ));
        }

        $regex = '~' . str_replace('~', '\\~', $pattern) . '~u';
     // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Validate user-supplied regex without surfacing PHP warnings.
        $previous_handler = set_error_handler(fn() => true);
        $is_valid         = false !== preg_match($regex, '');
        restore_error_handler();
        unset($previous_handler);

        if (! $is_valid ) {
            return new \WP_Error('invalid_pattern', 'Search pattern is not a valid regular expression.', array( 'status' => 400 ));
        }

        return $regex;
    }

    private function path_matches_include( string $path, ?string $include_pattern ): bool
    {
        if (null === $include_pattern || '' === $include_pattern ) {
            return true;
        }

        return fnmatch($include_pattern, $path) || fnmatch($include_pattern, basename($path));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function grep_content( string $content, string $repo, string $path, string $regex, int $context_lines, int $limit ): array
    {
        $lines   = explode("\n", $content);
        $matches = array();
        foreach ( $lines as $index => $line ) {
            if (! preg_match($regex, $line) ) {
                continue;
            }

            $start      = max(0, $index - $context_lines);
            $end        = min(count($lines) - 1, $index + $context_lines);
            $read_limit = $end - $start + 1;

            $match = array(
            'match_id'  => substr(hash('sha256', $path . ':' . ( $index + 1 ) . ':' . $line), 0, 16),
            'path'      => $path,
            'line'      => $index + 1,
            'text'      => $line,
            'preview'   => $this->build_preview($lines, $start, $end),
            'read_args' => array(
            'repo'   => $repo,
            'path'   => $path,
            'offset' => $start + 1,
            'limit'  => $read_limit,
            ),
            );

            if ($context_lines > 0 ) {
                $match['context'] = array();
                for ( $context_index = $start; $context_index <= $end; ++$context_index ) {
                    $match['context'][] = array(
                    'line' => $context_index + 1,
                    'text' => $lines[ $context_index ],
                    );
                }
            }

            $matches[] = $match;
            if (count($matches) >= $limit ) {
                break;
            }
        }

        return $matches;
    }

    private function build_preview( array $lines, int $start, int $end ): string
    {
        $preview = array();
        for ( $context_index = $start; $context_index <= $end; ++$context_index ) {
            $preview[] = sprintf('%d: %s', $context_index + 1, $lines[ $context_index ]);
        }

        return implode("\n", $preview);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function build_edit_suggestions( string $content, string $old_string ): array
    {
        $candidates = array_values(array_filter(array_map('trim', explode("\n", $old_string)), static fn( $line ) => strlen($line) >= 4));
        usort($candidates, static fn( $a, $b ) => strlen($b) <=> strlen($a));

        $needle = $candidates[0] ?? trim($old_string);
        if ('' === $needle ) {
            return array();
        }

        $needle      = substr($needle, 0, 120);
        $lines       = explode("\n", $content);
        $suggestions = array();
        foreach ( $lines as $index => $line ) {
            if (false === strpos($line, $needle) ) {
                continue;
            }

            $start         = max(0, $index - 2);
            $end           = min(count($lines) - 1, $index + 2);
            $suggestions[] = array(
            'line'    => $index + 1,
            'text'    => $line,
            'preview' => $this->build_preview($lines, $start, $end),
            );

            if (count($suggestions) >= 3 ) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * @return array<string,mixed>
     */
    private function state(): array
    {
        $state = function_exists('get_option') ? get_option(self::OPTION, array()) : array();
        if (! is_array($state) ) {
            $state = array();
        }
        $state['repos']      = is_array($state['repos'] ?? null) ? $state['repos'] : array();
        $state['repo_names'] = is_array($state['repo_names'] ?? null) ? $state['repo_names'] : array();
        $state['worktrees']  = is_array($state['worktrees'] ?? null) ? $state['worktrees'] : array();
        return $state;
    }

    /**
     * @param array<string,mixed> $state State to persist.
     */
    private function save_state( array $state ): void
    {
        if (function_exists('update_option') ) {
            update_option(self::OPTION, $state, false);
        }
    }
}
