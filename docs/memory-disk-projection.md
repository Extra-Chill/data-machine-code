# Memory Disk Projection

Data Machine Code owns **local runtime/file projection** for coding agents. Data Machine owns **logical agent memory**, memory storage backends, and prompt injection.

This boundary matters because external coding agents need files on disk, while WordPress-hosted agents may use disk, `wp_guideline`, or another store behind Data Machine's memory interfaces.

## Boundary

```text
Data Machine memory/guideline store
        |
        | explicit update/delete events
        v
Data Machine Code projection
        |
        v
local files for coding-agent runtimes
```

DMC should not decide what `MEMORY.md`, `SOUL.md`, `USER.md`, or guideline records mean. It should only project already-resolved records to safe local file paths when a writable local runtime exists.

## Guardrails

- `wp_guideline` is optional. It is not guaranteed in WordPress core today.
- Guideline-backed projection must feature-detect `post_type_exists( 'wp_guideline' )` and `taxonomy_exists( 'wp_guideline_type' )`, or rely on an explicit producer contract/polyfill.
- Disk projection is one-way until a separate sync-back design exists.
- Data Machine core should not learn about OpenCode, Claude Code, or Kimaki.
- DMC should not subscribe to inferred low-level writes. It should consume explicit Data Machine memory/guideline events.

## Current Safe Slice

`DataMachineCode\MemoryDiskProjection` provides:

- environment gating via `is_available()`;
- optional Guidelines substrate detection via `has_guideline_substrate()`;
- a named list of expected future Data Machine event hooks;
- path normalization/resolution helpers that reject absolute paths, traversal, empty paths, and NUL bytes.

It does not register a live sync loop because Data Machine does not currently emit memory/guideline update events.

## Upstream Dependency

DMC can implement live projection after Data Machine provides explicit events such as:

```php
do_action( 'datamachine_agent_memory_updated', $scope, $content, $metadata );
do_action( 'datamachine_agent_memory_deleted', $scope );
do_action( 'datamachine_guideline_updated', $post_id, $type );
```

The event payload should identify the logical memory four-tuple `(layer, user_id, agent_id, filename)` or a guideline record type. DMC can then map records to local runtime files without owning storage semantics.

Tracked upstream: https://github.com/Extra-Chill/data-machine/issues/1522
