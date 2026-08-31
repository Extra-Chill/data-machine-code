# WordPress Runtime Inspection

DMC exposes a read-only runtime perception layer for agents running inside WordPress:

- `wordpress_runtime_inventory` / `datamachine-code/wordpress-runtime-inventory`
- `wordpress_runtime_ls` / `datamachine-code/wordpress-runtime-ls`
- `wordpress_runtime_read` / `datamachine-code/wordpress-runtime-read`

These tools are for discovery, not durable memory. Agents should inspect the live runtime when they need current facts, then intentionally write durable memory only for facts that should persist beyond the session.

## Security Model

Runtime file access is default-deny and read-only.

Allowed source roots:

- `wp-content/plugins`
- `wp-content/themes`
- `wp-includes`
- `wp-admin`

Denied by policy:

- `wp-config.php`
- `.env`, credentials, secrets, tokens, key files, and certificate files
- `wp-content/uploads` by default
- `logs`, `cache`, `private`, and `secrets` directories
- SQLite/MySQL dump/database files and log files
- path traversal such as `../`
- arbitrary absolute filesystem reads

`wordpress_runtime_read` is bounded by `max_size`, `offset`, and `limit`, and rejects binary files before returning content.

## Intended Use

Use inventory to understand the live install: WordPress/PHP version, active theme, installed themes/plugins, plugin active/network-active state, mu-plugins, drop-ins, safe constants, and readable source-root metadata.

Use `ls` and `read` only for allowlisted source inspection. Use the existing Data Machine memory surface when an agent decides a discovered fact should become durable context.
