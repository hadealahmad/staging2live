# Staging2Live WordPress Plugin

Backup a staging site and restore it to a live site with URL search/replace, safe zipping, metadata, and an admin UI/CLI to manage backups and restores.

## Features

- Database backup with URL search/replace (serializer-safe via WP-CLI when available)
- `wp-content` backup to ZIP with exclusions and safe extraction
- Sidecar JSON metadata (site URL, date, admin user, DB prefix, versions)
- Admin page to list, download, delete, and restore backups
- Optional pre-restore safety backup (enabled by default)
- Avoids overwriting core files and `wp-config.php` during restore
- Table prefix reconciliation: updates `$table_prefix` in `wp-config.php` to match the restored DB
- WP-CLI commands to backup/restore
- Retention policy for automatic pruning

## Installation

1. Copy the `staging2live/` directory into your site's `wp-content/plugins/` directory
2. Activate the plugin in WordPress Admin → Plugins
3. Go to Admin → Staging2Live → Settings and configure:
   - Staging URL
   - Live URL
   - Backup directory (default: `wp-content/staging2live-backups`)
   - Retention (how many backups to keep)

## Admin Usage

- Go to Admin → Staging2Live → Backups
- Click "Create Backup" to create a new backup (db + `wp-content`)
- Use the table to:
  - Restore a backup (optionally force URLs to the current site)
  - Download ZIP or JSON
  - Delete a backup

Notes:
- On restore, the plugin can create a pre-restore backup (configurable in Settings)
- Only `wp-content` is restored; core files and `wp-config.php` are not overwritten
- The database is imported from the backup's `database.sql`
- `$table_prefix` in `wp-config.php` is updated to the prefix stored in the backup's metadata
-. If "Force URLs to current site" is checked, the restored database is rewritten so `siteurl` and `home` match the current site (and a broader search/replace runs when WP‑CLI is available)

## WP-CLI Usage

- Create a backup:

```bash
wp staging2live backup [--to=<dir>] [--live-url=<url>] [--staging-url=<url>]
```

- Restore a backup (from zip path or basename found in the backup directory):

```bash
wp staging2live restore --from=<zip|basename> [--no-pre-backup]
```

## Safety & Limitations

- Backups are stored under the configured backup directory; access is blocked via `.htaccess`/`web.config` and `index.html`
- ZIP creation/extraction is streamed but very large sites may require additional server resources
- URL replacement prefers WP-CLI `search-replace`; fallback is a simple streamed replace on the SQL dump (not serializer-safe)
- Ensure the backup directory is writable
- DB import prefers WP-CLI `db import`; fallbacks include mysql client and a streaming PHP importer

## Retention

- Set the retention limit in Settings
- After creating a backup, older backups are pruned according to the retention value

## Metadata JSON

Sidecar JSON file is written alongside the ZIP with the same basename. Example fields:

- `site_name`, `site_url`, `target_live_url`, `admin_username`
- `backup_date_utc`, `wordpress_version`, `php_version`, `db_prefix`
- `backup_zip`, size fields

## Development

- Admin page and actions implemented using WordPress Settings API and admin-post endpoints
- CLI commands registered under `wp staging2live`
- Services handle DB export/import, search-replace, zipping, metadata, and paths

## Testing

- Admin: Create a backup, verify it appears in the list, then attempt a restore
- CLI: `wp staging2live backup`, then `wp staging2live restore --from=<basename>`
- Verify that core files and `wp-config.php` are not overwritten; only `wp-content` changes
- Confirm `$table_prefix` is updated in `wp-config.php` to the prefix stored in the backup's metadata

## License

GPL-2.0-or-later


