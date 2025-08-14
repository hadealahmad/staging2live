<?php
namespace Staging2Live\Services;

if (!defined('ABSPATH')) {
	exit;
}

class BackupService {
	public function create_backup(array $options = []): array {
		$settings = get_option('staging2live_settings', []);
		$stagingUrl = isset($options['staging_url']) ? $options['staging_url'] : ($settings['staging_url'] ?? site_url());
		$liveUrl = isset($options['live_url']) ? $options['live_url'] : ($settings['live_url'] ?? home_url());
		$backupDir = isset($options['backup_dir']) ? $options['backup_dir'] : ($settings['backup_dir'] ?? WP_CONTENT_DIR . '/staging2live-backups');
		$performReplace = isset($options['replace_urls']) ? (bool) $options['replace_urls'] : false;

		$backupDir = wp_normalize_path(untrailingslashit($backupDir));
		if (!is_dir($backupDir)) {
			wp_mkdir_p($backupDir);
		}

		$siteName = get_bloginfo('name');
		$siteSlug = \Staging2Live\Services\FilenameService::sanitize_human_filename_component($siteName);
		if ($siteSlug === '') {
			$siteSlug = preg_replace('~https?://~', '', $liveUrl);
		}
		$timestamp = gmdate('Ymd-His');
		$baseName = $siteSlug . '-' . $timestamp;
		$tmpDir = $backupDir . '/.tmp-' . $baseName;
		wp_mkdir_p($tmpDir);

		$sqlPath = $tmpDir . '/database.sql';
		$exporter = new DatabaseExportService();
		$sql = $exporter->export_to_sql($sqlPath);
		if ($sql === '') {
			return [ 'success' => false, 'message' => 'Failed to export database' ];
		}

		$finalSqlPath = $sql;
		if ($performReplace) {
			$replacer = new SearchReplaceService();
			$replacedSql = $replacer->replace_in_sql_dump($sql, $stagingUrl, $liveUrl);
			if ($replacedSql !== '') {
				$finalSqlPath = $replacedSql;
			}
		}

		$zipService = new ZipService();
		$zipPath = $backupDir . '/' . $baseName . '.zip';
		$exclude = [$backupDir];
		$ok = $zipService->create_backup_zip($zipPath, WP_CONTENT_DIR, $finalSqlPath, $exclude);
		if (!$ok) {
			return [ 'success' => false, 'message' => 'Failed to create backup zip' ];
		}

		$metadata = [
			'site_name' => get_bloginfo('name'),
			'site_url' => site_url(),
			'target_live_url' => $liveUrl,
			'admin_username' => wp_get_current_user()->user_login,
			'backup_date_utc' => gmdate('c'),
			'wordpress_version' => get_bloginfo('version'),
			'php_version' => PHP_VERSION,
			'db_prefix' => $GLOBALS['table_prefix'],
			'wp_config_path' => ABSPATH . 'wp-config.php',
			'backup_zip' => basename($zipPath),
			'wp_content_size_bytes' => 0,
			'db_dump_size_bytes' => file_exists($finalSqlPath) ? filesize($finalSqlPath) : 0,
		];
		$metaService = new MetadataService();
		$metaService->write_metadata($backupDir . '/' . $baseName . '.json', $metadata);

		// Retention policy enforcement
		$retention = isset($settings['retention']) ? intval($settings['retention']) : 10;
		if ($retention > 0) {
			$this->enforce_retention($backupDir, $retention);
		}

		// Cleanup tmp
		if (is_dir($tmpDir)) {
			@unlink($sqlPath);
			if ($finalSqlPath !== $sqlPath) @unlink($finalSqlPath);
			@rmdir($tmpDir);
		}

		return [ 'success' => true, 'zip' => $zipPath, 'metadata' => $metadata ];
	}

	private function enforce_retention(string $backupDir, int $keep): void {
		$files = glob(trailingslashit($backupDir) . '*.json');
		if (!is_array($files)) return;
		usort($files, function($a, $b){ return filemtime($b) <=> filemtime($a); });
		$excess = array_slice($files, $keep);
		foreach ($excess as $json) {
			$base = substr(basename($json), 0, -5);
			@unlink($json);
			@unlink(trailingslashit($backupDir) . $base . '.zip');
		}
	}
}


