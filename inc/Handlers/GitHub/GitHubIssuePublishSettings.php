<?php
/**
 * GitHub Issue Publish Handler Settings.
 *
 * @package DataMachineCode\Handlers\GitHub
 */

namespace DataMachineCode\Handlers\GitHub;

use DataMachine\Core\Steps\Settings\SettingsHandler;

if (! defined('ABSPATH') ) {
    exit;
}

class GitHubIssuePublishSettings extends SettingsHandler
{

    /**
     * Get settings fields for GitHub issue publishing.
     *
     * @return array
     */
    public static function get_fields(): array
    {
        return array(
        'repo'      => array(
        'type'        => 'text',
        'label'       => __('Repository', 'data-machine-code'),
        'description' => __('GitHub repository in owner/repo format. Falls back to the default repo in settings.', 'data-machine-code'),
        'required'    => false,
        ),
        'labels'    => array(
        'type'        => 'text',
        'label'       => __('Labels', 'data-machine-code'),
        'description' => __('Comma-separated labels to apply by default. The AI step can override these.', 'data-machine-code'),
        'required'    => false,
        ),
        'assignees' => array(
        'type'        => 'text',
        'label'       => __('Assignees', 'data-machine-code'),
        'description' => __('Comma-separated GitHub usernames to assign by default.', 'data-machine-code'),
        'required'    => false,
        ),
        'milestone' => array(
        'type'        => 'number',
        'label'       => __('Milestone', 'data-machine-code'),
        'description' => __('Optional GitHub milestone number.', 'data-machine-code'),
        'required'    => false,
        ),
        );
    }
}
