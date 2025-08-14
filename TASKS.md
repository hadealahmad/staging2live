# Staging2Live WordPress Plugin Implementation

Simple WordPress plugin to back up a staging site and restore it to a live site with URL search/replace, safe zipping, metadata, and an admin UI to manage backups and restores.

## Completed Tasks

- [x] Create initial task list and implementation plan
- [x] Scaffold plugin structure and bootstrap files
- [x] Implement configuration settings (staging URL, live URL, backup directory, retention)
- [x] Implement backup directory protections and exclusions
- [x] Implement admin page: list backups with details and actions (restore, delete)
- [x] Implement basic backup orchestration (DB export, optional URL replace, zip + metadata)
- [x] Implement basic restore workflow (pre-backup, extract zip, restore wp-content, import DB, prefix reconcile stub)
- [x] Harden restore: metadata prefix detection, safe wp-config update backup, admin notices, logging stub
- [x] Add admin "Create Backup" action with nonce/cap checks and service invocation
- [x] Enhance backups list: sizes, human-readable dates, download links, sorting
- [x] Implement retention policy enforcement on backup creation
- [x] Harden restore extraction: validate paths, restrict to `wp-content/` and `database.sql`
- [x] Skip symlinks and backup directory during restore file copy
- [x] CLI restore parity with pre-restore option and basename support
- [x] Documentation: README with usage, safety, configuration, retention, and prefix behavior
- [x] Unicode-safe filenames and metadata (Arabic characters preserved in ZIP name and JSON)
- [x] Post-restore canonicalization: enforce live URL and flush rewrites
- [x] Admin testing tools: download/clear log, plugin action link, i18n bootstrap
- [x] Plugin packaging assets: WordPress readme.txt and placeholder POT
- [x] Restore safety and consistency: preserve plugin folder, exclude plugin during copy, robust wp-content/sql discovery
- [x] Large-site support: system zip path, embedded/system WP-CLI invocation, resilient importer with mysql fallback
- [x] Admin restore form: “Force URLs to current site” checkbox and enforcement
- [x] Persist and restore backup directory setting across DB imports
- [x] Option to enable/disable pre-restore safety backup (default ON)

## In Progress Tasks


## Future Tasks


## Implementation Plan

### Architecture Overview

- Admin UI (menu under Tools or a top-level page) to manage backups and restores
- Services layer for backup, restore, database handling, zip, and metadata
- Settings API for configuration (staging URL, live URL, backup directory)
- WP-CLI commands mirroring admin operations for automation
- Security layer: capabilities (`manage_options`), nonces, path validation, directory hardening

### Backup Process

1. Validate configuration and writable backup directory
2. Determine site name (from `get_bloginfo('name')` or sanitized site URL) and timestamp
3. Export database:
   - Prefer WP-CLI if available: `wp db export` to a temporary SQL file
   - Fallback: PHP exporter that iterates tables via `$wpdb` and writes SQL
4. Perform serializer-safe search/replace from staging URL → live URL on the SQL dump:
   - Prefer WP-CLI `wp search-replace --all-tables-with-prefix --export` when available
   - Fallback: parse and rewrite serialized strings safely (walk and reserialize) before writing final SQL
5. Package `wp-content` directory into ZIP (exclude backup directory itself)
6. Create ZIP named `<site-slug>-<YYYYMMDD-HHMMSS>.zip`
7. Write metadata JSON `<site-slug>-<YYYYMMDD-HHMMSS>.json` alongside ZIP
8. Ensure backup directory is protected from web access (`.htaccess` or `web.config` + `index.html`)

### Restore Process

1. Select backup from admin list (read metadata JSON)
2. Pre-restore safety backup: run a full backup of current site (db + `wp-content`)
3. Extract ZIP into a temporary directory
4. Restore files: sync extracted `wp-content` → live `wp-content` (skip core files and `wp-config.php`)
5. Import database from SQL file
6. Reconcile table prefix:
   - Detect prefix in imported SQL (or metadata)
   - If different from current, update `wp-config.php` `$table_prefix` to the imported value
7. Run search/replace on the live database if needed to ensure live URL is canonical
8. Flush caches and rewrite rules

### Metadata JSON (sidecar)

Stored next to the ZIP with the same base name. Example:

```json
{
  "site_name": "Staging Site",
  "site_url": "https://staging.example.com",
  "target_live_url": "https://example.com",
  "admin_username": "admin",
  "backup_date_utc": "2025-08-14T12:34:56Z",
  "wordpress_version": "6.x",
  "php_version": "8.2",
  "db_prefix": "wp_",
  "backup_zip": "staging-site-20250814-123456.zip",
  "wp_content_size_bytes": 123456789,
  "db_dump_size_bytes": 4567890
}
```

### Naming & Storage

- Backup directory default: `wp-content/staging2live-backups`
- ZIP name: `<site-slug>-<YYYYMMDD-HHMMSS>.zip`
- JSON name: `<site-slug>-<YYYYMMDD-HHMMSS>.json`
- Exclude the backup directory from future backups to avoid recursive growth

### Safety & Exclusions

- Never overwrite core WordPress files or `wp-config.php` during restore
- Only restore `wp-content` and database
- Validate paths; block directory traversal attempts
- Enforce `manage_options` capability and verify nonces for all actions
- Rate-limit restore operations; show explicit confirmation dialogs

### Search/Replace Strategy (Serializer-Safe)

- Prefer WP-CLI `search-replace` for accuracy and performance
- Fallback algorithm:
  - Load values via `$wpdb` table iteration for text columns
  - Detect serialized strings and arrays, unserialize safely, perform string replace on scalars, reserialize
  - For non-serialized text, perform direct replace
  - Preserve lengths in serialized data by reserializing after replace

### Admin UI

- Admin page lists backups by reading the backup directory and parsing JSON metadata
- Columns: site URL, backup date, admin username, sizes, actions (Restore, Delete, Download)
- Filters and search by date
- Bulk delete with confirmation

### CLI (Optional but Recommended)

- `wp staging2live backup --to=<dir> [--live-url=] [--staging-url=]`
- `wp staging2live restore --from=<zip|name> [--confirm]`

### Configuration

- Settings page with fields:
  - Staging URL
  - Live URL
  - Backup directory (default `wp-content/staging2live-backups`)
  - Retention policy (number of backups to keep)
- Environment detection to prefill site URLs

### Logging & Errors

- Log file in backup directory (rotated)
- Admin notices for success/failure
- Downloadable logs for troubleshooting

### Testing

- Unit tests for serializer-safe replace
- Integration tests for backup/restore round-trip on a small fixture site

## Relevant Files

- staging2live/staging2live.php - Plugin bootstrap, hooks, autoload
- staging2live/includes/Services/BackupService.php - Orchestrates backup flow
- staging2live/includes/Services/RestoreService.php - Orchestrates restore flow
- staging2live/includes/Services/DatabaseExportService.php - DB export abstraction
- staging2live/includes/Services/DatabaseImportService.php - DB import abstraction
- staging2live/includes/Services/SearchReplaceService.php - Serializer-safe replace
- staging2live/includes/Services/ZipService.php - ZIP creation/extraction (streaming)
- staging2live/includes/Services/MetadataService.php - JSON sidecar read/write
- staging2live/includes/Services/PathService.php - Path validation and exclusions
- staging2live/includes/Admin/AdminPage.php - Admin UI page and actions
- staging2live/includes/CLI/Commands.php - WP-CLI commands (optional)
- staging2live/assets/admin.css - Admin styles
- staging2live/assets/admin.js - Admin interactivity
- wp-content/staging2live-backups/.htaccess - Deny access
- wp-content/staging2live-backups/index.html - Directory privacy

## Next Step

- Testing and release:
  - Smoke test: backup/restore with/without force-URL, with/without pre-restore backup
  - Validate downloads, retention, and diagnostics
  - Tag v0.1.0 and ship zip

## Future Tasks

- [ ] Add "Force URLs to current site" option in restore UI
- [ ] On restore, if enabled, enforce current site URL via DB search/replace


