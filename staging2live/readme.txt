=== Staging2Live ===
Contributors: staging2live
Tags: backup, restore, migration, staging, live, wp-cli
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backup a staging site and restore it to a live site with URL replacement, safe zipping, metadata, and admin/CLI tools.

== Description ==

- Database backup with URL search/replace (serializer-safe via WP-CLI when available)
- `wp-content` backup to ZIP with exclusions and safe extraction
- Sidecar JSON metadata (site URL, date, admin user, DB prefix, versions)
- Admin page to list, download, delete, and restore backups
- Optional pre-restore safety backup (enabled by default)
- Avoids overwriting core files and `wp-config.php` during restore
- Table prefix reconciliation: updates `$table_prefix` in `wp-config.php`
- WP-CLI commands to backup/restore
- Retention policy for automatic pruning

== Installation ==

1. Upload `staging2live` to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure under Admin → Staging2Live (backup directory, retention, pre-restore safety backup toggle).

== Frequently Asked Questions ==

= Does this overwrite core WordPress files? =

No. Only `wp-content` and the database are restored. `wp-config.php` is updated only to adjust the table prefix.

= Is URL replacement serializer-safe? =

When WP-CLI is available, use `wp search-replace`. Otherwise, a streamed SQL replacement is used. If "Force URLs to current site" is checked at restore time, `siteurl` and `home` are set to the current site and a broader replacement runs when WP‑CLI is available.

== Screenshots ==

1. Backups list
2. Settings
3. Diagnostics

== Changelog ==

= 0.1.0 =
* Initial release.
