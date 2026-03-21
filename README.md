# Data Machine Code

Developer tools extension for [Data Machine](https://github.com/Extra-Chill/data-machine). GitHub integration, workspace management, git operations, and code tools for WordPress AI agents.

## What It Does

Data Machine Code extracts developer-oriented tooling from Data Machine core into a standalone extension. Not every WordPress site needs GitHub integration or workspace management — but if you're using Data Machine as a coding agent platform, this plugin gives your agents the tools to work with code repositories directly from WordPress.

## Features

### GitHub Integration
- List, view, update, close, and comment on issues
- List pull requests and repositories
- Create issues (async via Action Scheduler)
- GitHub fetch handler for pipeline workflows

### Workspace Management
- Clone and manage git repositories in a secure workspace directory
- Read, write, and edit files within workspace repos
- List directory contents with file metadata

### Git Operations
- Status, log, diff (read-only)
- Pull, add, commit, push (policy-controlled)
- Per-repo write/push policies with path allowlists and branch restrictions

### AI Agent Tools
- 10 chat tools for GitHub and workspace operations
- Async GitHub issue creation via system tasks
- All tools register through Data Machine's tool system

### WP-CLI
```bash
# GitHub
wp datamachine-code github issues --repo=owner/repo
wp datamachine-code github view 123 --repo=owner/repo
wp datamachine-code github close 123 --repo=owner/repo
wp datamachine-code github comment 123 "Fixed." --repo=owner/repo
wp datamachine-code github pulls --repo=owner/repo
wp datamachine-code github repos owner
wp datamachine-code github status

# Workspace
wp datamachine-code workspace path
wp datamachine-code workspace list
wp datamachine-code workspace clone https://github.com/org/repo.git
wp datamachine-code workspace show repo-name
wp datamachine-code workspace read repo-name src/main.php
wp datamachine-code workspace ls repo-name src/
wp datamachine-code workspace write repo-name path/to/file.txt @content.txt
wp datamachine-code workspace edit repo-name path/to/file.txt --old="foo" --new="bar"
wp datamachine-code workspace remove repo-name
wp datamachine-code workspace git status repo-name
wp datamachine-code workspace git pull repo-name
wp datamachine-code workspace git add repo-name --paths=src/file.php
wp datamachine-code workspace git commit repo-name --message="fix: something"
wp datamachine-code workspace git push repo-name
wp datamachine-code workspace git log repo-name
wp datamachine-code workspace git diff repo-name
```

## Requirements

- WordPress 6.9+
- PHP 8.2+
- [Data Machine](https://github.com/Extra-Chill/data-machine) plugin (core)

## Installation

1. Install and activate Data Machine core
2. Clone this repo to `wp-content/plugins/data-machine-code`
3. Run `composer install`
4. Activate the plugin

## Configuration

Set your GitHub Personal Access Token and default repository in Data Machine settings:
- `github_pat` — GitHub Personal Access Token with repo access
- `github_default_repo` — Default repository in `owner/repo` format

Workspace git policies are configured via the `datamachine_workspace_git_policies` option for per-repo write/push controls.

## Architecture

```
data-machine (core)
├── AI engine, pipelines, jobs, agents
├── Memory system, tools framework
└── Base classes for extensions
    │
    ├── data-machine-socials (social platforms)
    ├── data-machine-code (developer tools) ← this plugin
    └── data-machine-business (transforms)
```

Data Machine Code extends core base classes (`BaseTool`, `SystemTask`, `BaseCommand`, `FetchHandler`) and registers its capabilities through standard Data Machine hooks (`datamachine_tools`, `datamachine_tasks`, Abilities API).

## Roadmap

- **Phase 1**: Core extraction complete, own settings management
- **Phase 2**: New dev tools — grep, search, code analysis, enhanced git
- **Phase 3**: Full PR workflow — autonomous code changes
- **Phase 4**: Multi-repo orchestration, webhooks, admin UI

## License

GPL v2 or later
