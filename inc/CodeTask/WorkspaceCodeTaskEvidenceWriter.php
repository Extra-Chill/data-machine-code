<?php
/**
 * WorkspaceWriter-backed code-task evidence writer.
 *
 * @package DataMachineCode\CodeTask
 */

namespace DataMachineCode\CodeTask;

use DataMachineCode\Workspace\WorkspaceWriter;

defined('ABSPATH') || exit;

class WorkspaceCodeTaskEvidenceWriter implements CodeTaskEvidenceWriterInterface
{
    public function __construct( private WorkspaceWriter $writer )
    {
    }

    public function write_file( string $handle, string $path, string $content ): array|\WP_Error
    {
        return $this->writer->write_file($handle, $path, $content);
    }
}
