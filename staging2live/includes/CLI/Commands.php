<?php
namespace Staging2Live\CLI;

if (!defined('ABSPATH')) {
	exit;
}

class Commands {
	public static function register() {
		if (!class_exists('WP_CLI')) {
			return;
		}
		\WP_CLI::add_command('staging2live', __CLASS__);
	}

	/**
	 * Create a backup (db + wp-content).
	 *
	 * ## OPTIONS
	 * [--to=<dir>]
	 * : Destination directory for backups
	 *
	 * [--live-url=<url>]
	 * : Live URL to replace staging URLs with
	 *
	 * [--staging-url=<url>]
	 * : Staging URL to search for
	 */
	public function backup($args, $assoc_args) {
		$settings = get_option('staging2live_settings', []);
		$staging = isset($assoc_args['staging-url']) ? $assoc_args['staging-url'] : ($settings['staging_url'] ?? site_url());
		$live = isset($assoc_args['live-url']) ? $assoc_args['live-url'] : ($settings['live_url'] ?? home_url());
		$backupDir = isset($assoc_args['to']) ? $assoc_args['to'] : ($settings['backup_dir'] ?? WP_CONTENT_DIR . '/staging2live-backups');
		$backupDir = wp_normalize_path(untrailingslashit($backupDir));
		if (!is_dir($backupDir)) {
			wp_mkdir_p($backupDir);
		}

		$siteName = get_bloginfo('name');
		$siteSlug = \Staging2Live\Services\FilenameService::sanitize_human_filename_component($siteName);
		if ($siteSlug === '') {
			$siteSlug = preg_replace('~https?://~', '', $live);
		}
		$timestamp = gmdate('Ymd-His');
		$baseName = $siteSlug . '-' . $timestamp;
		$tmpDir = $backupDir . '/.tmp-' . $baseName;
		wp_mkdir_p($tmpDir);

		$sqlPath = $tmpDir . '/database.sql';
		$exporter = new \Staging2Live\Services\DatabaseExportService();
		$sql = $exporter->export_to_sql($sqlPath);
		if ($sql === '') {
			\WP_CLI::error('Failed to export database.');
			return;
		}

		$replacer = new \Staging2Live\Services\SearchReplaceService();
		$replacedSql = $replacer->replace_in_sql_dump($sql, $staging, $live);
		if ($replacedSql === '') {
			$replacedSql = $sql; // fallback to original
		}

		$zipService = new \Staging2Live\Services\ZipService();
		$zipPath = $backupDir . '/' . $baseName . '.zip';
		$exclude = [$backupDir];
		$ok = $zipService->create_backup_zip($zipPath, WP_CONTENT_DIR, $replacedSql, $exclude);
		if (!$ok) {
			\WP_CLI::error('Failed to create zip.');
			return;
		}

		$metadata = [
			'site_name' => get_bloginfo('name'),
			'site_url' => site_url(),
			'target_live_url' => $live,
			'admin_username' => wp_get_current_user()->user_login,
			'backup_date_utc' => gmdate('c'),
			'wordpress_version' => get_bloginfo('version'),
			'php_version' => PHP_VERSION,
			'db_prefix' => $GLOBALS['table_prefix'],
			'backup_zip' => basename($zipPath),
			'wp_content_size_bytes' => 0,
			'db_dump_size_bytes' => file_exists($replacedSql) ? filesize($replacedSql) : 0,
		];
		$metaService = new \Staging2Live\Services\MetadataService();
		$metaService->write_metadata($backupDir . '/' . $baseName . '.json', $metadata);

		// Cleanup tmp
		if (is_dir($tmpDir)) {
			// best-effort cleanup
			@unlink($sql);
			@unlink($replacedSql);
			@rmdir($tmpDir);
		}

		\WP_CLI::success('Backup created: ' . $zipPath);
	}

	/**
	 * Restore from a backup.
	 *
	 * ## OPTIONS
	 * --from=<zip|basename>
	 * : Path to backup zip or basename found in backup directory (without extension)
	 *
	 * [--no-pre-backup]
	 * : Skip creating a pre-restore backup (not recommended)
	 */
	public function restore($args, $assoc_args) {
		if (empty($assoc_args['from'])) {
			\WP_CLI::error('Please provide --from=<zip|basename>');
			return;
		}
		$from = $assoc_args['from'];
		$settings = get_option('staging2live_settings', []);
		$backupDir = isset($settings['backup_dir']) ? $settings['backup_dir'] : (WP_CONTENT_DIR . '/staging2live-backups');
		$backupDir = wp_normalize_path(untrailingslashit($backupDir));

		$zipPath = $from;
		if (substr($zipPath, -4) !== '.zip') {
			$zipPath = $backupDir . '/' . $from . '.zip';
		}
		$zipPath = wp_normalize_path($zipPath);
		if (!file_exists($zipPath)) {
			\WP_CLI::error('Backup zip not found: ' . $zipPath);
			return;
		}

		// Optionally create pre-backup (default true)
		$doPre = !isset($assoc_args['no-pre-backup']);
		if ($doPre) {
			$backupService = new \Staging2Live\Services\BackupService();
			$pre = $backupService->create_backup(['replace_urls' => false]);
			if (empty($pre['success'])) {
				\WP_CLI::error('Failed to create pre-restore backup');
				return;
			}
			\WP_CLI::log('Pre-restore backup created: ' . $pre['zip']);
		}

		$service = new \Staging2Live\Services\RestoreService();
		$result = $service->restore_from_backup($zipPath);
		if (empty($result['success'])) {
			\WP_CLI::error('Restore failed' . (!empty($result['message']) ? (': ' . $result['message']) : ''));
			return;
		}
		\WP_CLI::success('Restore completed successfully');
	}
}


