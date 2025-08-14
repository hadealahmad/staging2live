<?php
namespace Staging2Live\Services;

if (!defined('ABSPATH')) {
	exit;
}

class RestoreService {
	/**
	 * Temporary directory prefix used during restore operations.
	 */
	private const TEMP_DIR_PREFIX = 'staging2live-temp-restore-';

	/**
	 * Name of the backups directory to preserve during file restore.
	 */
	private const BACKUPS_DIRNAME = 'staging2live-backups';

	/**
	 * File name used to persist the current site URLs before DB import.
	 */
	private const SITEURL_META_FILENAME = '.staging2live-siteurl.json';

	/**
	 * Maximum depth to search for `wp-content` inside extracted archives.
	 */
	private const WP_CONTENT_SEARCH_MAX_DEPTH = 3;
	/**
	 * Restore a site from a Staging2Live backup zip.
	 *
	 * - Extracts the archive to a temp directory
	 * - Restores files under `wp-content` while preserving this plugin and backup dir
	 * - Imports the database and reconciles `$table_prefix`
	 * - Optionally enforces the current live URL
	 *
	 * @param string $zipPath Absolute path to the backup zip
	 * @param array $options Supported: ['force_current_url' => bool]
	 * @return array{success:bool, message?:string}
	 */
	public function restore_from_backup(string $zipPath, array $options = []): array {
		$zipPath = wp_normalize_path($zipPath);
		if (!file_exists($zipPath)) {
			\Staging2Live\Services\LogService::write('Restore failed: zip not found ' . $zipPath);
			return ['success' => false, 'message' => 'Backup zip not found'];
		}

		// Preserve current plugin settings (especially backup_dir) before DB import
		$prevSettings = get_option('staging2live_settings', []);
		$prevBackupDir = isset($prevSettings['backup_dir']) && $prevSettings['backup_dir']
			? wp_normalize_path($prevSettings['backup_dir'])
			: wp_normalize_path(WP_CONTENT_DIR . '/staging2live-backups');

        // Safety: make a pre-restore backup (configurable)
        $settings = get_option('staging2live_settings', []);
        $doPre = !isset($settings['pre_restore_backup']) || (int)$settings['pre_restore_backup'] === 1;
        if ($doPre) {
            $backupService = new BackupService();
            $pre = $backupService->create_backup(['replace_urls' => false]);
            if (empty($pre['success'])) {
                \Staging2Live\Services\LogService::write('Restore aborted: failed to create pre-restore backup');
                return ['success' => false, 'message' => 'Failed to create pre-restore backup'];
            }
        }

        // Persist current site URL prior to DB import (for canonicalization if requested)
        $currentSiteUrl = rtrim(site_url(), '/');
        $currentHomeUrl = rtrim(home_url(), '/');
        $this->persist_current_site_urls($prevBackupDir, $currentSiteUrl, $currentHomeUrl);

		// Extract zip to temp
		$tmpDir = WP_CONTENT_DIR . '/' . self::TEMP_DIR_PREFIX . time();
		wp_mkdir_p($tmpDir);
		$zipSvc = new ZipService();
		if (!$zipSvc->extract_backup_zip($zipPath, $tmpDir)) {
			\Staging2Live\Services\LogService::write('Restore failed: could not extract zip');
			return ['success' => false, 'message' => 'Failed to extract zip'];
		}
		// Ensure temp dir exists after extraction
		if (!is_dir($tmpDir)) {
			\Staging2Live\Services\LogService::write('Restore failed: temp dir missing post-extraction ' . $tmpDir);
			return ['success' => false, 'message' => 'Temporary directory missing'];
		}

		// Restore files: only wp-content
		$sourceContent = wp_normalize_path($tmpDir . '/wp-content');
		$targetContent = wp_normalize_path(WP_CONTENT_DIR);
		if (!is_dir($sourceContent)) {
			$alt = $this->find_wp_content_dir($tmpDir);
			if ($alt !== '') {
				$sourceContent = $alt;
			}
		}
		if (is_dir($sourceContent)) {
			// Clear target wp-content except backups dir and this plugin directory
			$pluginDir = wp_normalize_path(untrailingslashit(STL_PLUGIN_DIR));
			$excludeDirs = [];
			$excludeDirs[] = wp_normalize_path(untrailingslashit($prevBackupDir));
			$excludeDirs[] = $pluginDir;
			$excludeDirs[] = wp_normalize_path(untrailingslashit($tmpDir)); // do NOT delete extracted temp during cleanup
			$this->clear_target_wp_content($targetContent, $excludeDirs);
			$pluginSlug = basename(untrailingslashit(STL_PLUGIN_DIR));
			$sourcePluginDir = wp_normalize_path(untrailingslashit($sourceContent . '/plugins/' . $pluginSlug));
			$this->copy_directory($sourceContent, $targetContent, [$sourcePluginDir]);
		} else {
			\Staging2Live\Services\LogService::write('Restore notice: no wp-content directory found in extracted backup; tmp=' . $tmpDir);
		}


		// Determine desired prefix from metadata or SQL
		$sqlPath = wp_normalize_path($tmpDir . '/database.sql');
		if (!file_exists($sqlPath)) {
			$altSql = $this->find_sql_file($tmpDir);
			if ($altSql !== '') {
				$sqlPath = $altSql;
			}
			\Staging2Live\Services\LogService::write('SQL path discovery: chosen=' . $sqlPath);
		}
		$desiredPrefix = $this->detect_imported_prefix_from_metadata($zipPath);
		$oldPrefix = $GLOBALS['table_prefix'];
		if ($desiredPrefix === '' || $desiredPrefix === $GLOBALS['table_prefix']) {
			$prefixFromSql = $this->detect_imported_prefix_from_sql($sqlPath);
			if ($prefixFromSql !== '') {
				$desiredPrefix = $prefixFromSql;
			}
		}

		// Import database
		$importer = new DatabaseImportService();
		if (!$importer->import_from_sql($sqlPath)) {
			// Attempt a smaller retry with mysql client if available
			\Staging2Live\Services\LogService::write('Restore retry: attempting mysql client import');
			if (method_exists($importer, 'try_mysql_cli') && $importer->try_mysql_cli($sqlPath)) {
				\Staging2Live\Services\LogService::write('Restore retry succeeded via mysql client');
			} else {
			\Staging2Live\Services\LogService::write('Restore failed: DB import error');
			return ['success' => false, 'message' => 'Failed to import database'];
			}
		}

		// Adjust table prefix in wp-config.php if needed
		$this->reconcile_table_prefix($desiredPrefix);
		// Apply new prefix to current request runtime to keep subsequent operations consistent
		$this->apply_runtime_prefix($desiredPrefix);
		// Drop old-prefix tables to avoid duplicate datasets and ensure clean state
		if ($oldPrefix !== $desiredPrefix) {
			$this->drop_tables_with_prefix($oldPrefix, $desiredPrefix);
		}

		// Restore preserved backup_dir to prevent breaking plugin after DB import
		$this->restore_preserved_backup_dir($prevBackupDir);

        // Enforce canonical live URL on DB when possible
        if (!empty($options['force_current_url'])) {
            $this->ensure_live_url_canonical_explicit($desiredPrefix, $currentSiteUrl);
        }

		// Flush rewrite rules
		if (function_exists('flush_rewrite_rules')) {
			flush_rewrite_rules();
		}

		// Cleanup temp
		$this->rrmdir($tmpDir);

		return ['success' => true];
	}

	/**
	 * Recursively copy a directory, skipping symlinks, the backups dir, and any source paths
	 * that start with one of the provided exclude prefixes.
	 *
	 * @param string $source
	 * @param string $destination
	 * @param string[] $excludeSourcePrefixes Absolute, normalized paths to skip (prefix match)
	 */
	private function copy_directory(string $source, string $destination, array $excludeSourcePrefixes = []): void {
		$source = wp_normalize_path($source);
		$destination = wp_normalize_path($destination);
		$items = scandir($source);
		if ($items === false) return;
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') continue;
			$src = $source . '/' . $item;
			$dst = $destination . '/' . $item;
			// Skip backup directory itself
			if (basename($src) === self::BACKUPS_DIRNAME) continue;
			// No symlinks
			if (is_link($src)) continue;
			// Skip excluded source prefixes (e.g., this plugin inside the backup's plugins dir)
			foreach ($excludeSourcePrefixes as $prefix) {
				$prefix = wp_normalize_path(untrailingslashit($prefix));
				if ($prefix !== '' && strpos(wp_normalize_path($src), $prefix) === 0) {
					continue 2;
				}
			}
			if (is_dir($src)) {
				if (!is_dir($dst)) wp_mkdir_p($dst);
				$this->copy_directory($src, $dst, $excludeSourcePrefixes);
			} else {
				// Copy file
				@copy($src, $dst);
			}
		}
	}

	/**
	 * Remove all contents from `wp-content` except for provided exclusions and the running plugin.
	 *
	 * @param string $targetRoot Absolute path to the `wp-content` directory
	 * @param string[] $excludeDirs Absolute paths that should not be removed (prefix match)
	 */
	private function clear_target_wp_content(string $targetRoot, array $excludeDirs): void {
		$targetRoot = wp_normalize_path(untrailingslashit($targetRoot));
		$items = scandir($targetRoot);
		if ($items === false) return;
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') continue;
			$path = wp_normalize_path($targetRoot . '/' . $item);
			$pluginsDir = wp_normalize_path(WP_PLUGIN_DIR);
			// If this is the plugins directory, clear inside it but preserve our own plugin
			if ($path === $pluginsDir) {
				$this->clear_plugins_directory_preserving_self($pluginsDir);
				continue;
			}
			// Skip excludes
			foreach ($excludeDirs as $ex) {
				$ex = wp_normalize_path(untrailingslashit($ex));
				if ($ex !== '' && strpos($path, $ex) === 0) {
					continue 2;
				}
			}
			// Safety: never remove wp-config.php or core dirs (but they are not under wp-content)
			if (is_dir($path)) {
				$this->rrmdir($path);
			} else {
				@unlink($path);
			}
		}
	}

	/**
	 * Remove all plugins except for this plugin.
	 */
	private function clear_plugins_directory_preserving_self(string $pluginsDir): void {
		$pluginsDir = wp_normalize_path(untrailingslashit($pluginsDir));
		$selfDir = wp_normalize_path(untrailingslashit(STL_PLUGIN_DIR));
		$items = scandir($pluginsDir);
		if ($items === false) return;
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') continue;
			$path = wp_normalize_path($pluginsDir . '/' . $item);
			if (strpos($selfDir, $path) === 0) {
				// This is our own plugin path or a parent of it; skip
				continue;
			}
			if (is_dir($path)) {
				$this->rrmdir($path);
			} else {
				@unlink($path);
			}
		}
	}

	/** Ensure `$table_prefix` in wp-config.php matches the imported database prefix. */
	private function reconcile_table_prefix(string $desiredPrefix = ''): void {
		$currentPrefix = $GLOBALS['table_prefix'];
		$desiredPrefix = $desiredPrefix !== '' ? $desiredPrefix : $currentPrefix;
		if ($desiredPrefix === $currentPrefix) {
			return;
		}
		$config = ABSPATH . 'wp-config.php';
		if (!is_writable($config)) {
			return;
		}
		$contents = file_get_contents($config);
		if ($contents === false) {
			return;
		}
		// Backup wp-config.php
		@copy($config, $config . '.staging2live.bak');
		$replacement = "\$table_prefix = '" . addslashes($desiredPrefix) . "';";
		$updated = preg_replace(
			"/\$table_prefix\s*=\s*([\'\"]) [^'\"]* \1\s*;/x",
			$replacement,
			$contents,
			-1,
			$count
		);
		if ($count === 0) {
			// Broader match without quote backref
			$updated2 = preg_replace('/\$table_prefix\s*=\s*[^;]+;/', $replacement, $contents, -1, $count2);
			if ($count2 > 0) {
				$updated = $updated2;
			}
		}
		if ($updated === $contents) {
			// Insert before wp-settings.php require if possible
			$needle = 'wp-settings.php';
			$pos = strpos($contents, $needle);
			if ($pos !== false) {
				// Find the start of the line containing require wp-settings.php
				$lineStart = strrpos(substr($contents, 0, $pos), "\n");
				if ($lineStart === false) { $lineStart = 0; } else { $lineStart += 1; }
				$updated = substr($contents, 0, $lineStart) . $replacement . "\n" . substr($contents, $lineStart);
			} else {
				$updated = $contents . "\n\n// Staging2Live: enforce table prefix\n" . $replacement . "\n";
			}
		}
		file_put_contents($config, $updated);
	}

	/** Try to determine the imported DB prefix from the backup's metadata. */
	private function detect_imported_prefix_from_metadata(string $zipPath): string {
		$dir = dirname($zipPath);
		$base = basename($zipPath, '.zip');
		$jsonPath = $dir . '/' . $base . '.json';
		$metaSvc = new MetadataService();
		$data = $metaSvc->read_metadata($jsonPath);
		if (isset($data['db_prefix']) && is_string($data['db_prefix']) && $data['db_prefix'] !== '') {
			return $data['db_prefix'];
		}
		return $GLOBALS['table_prefix'];
	}

	/** Parse a SQL dump to detect the DB prefix by looking at well-known tables. */
	private function detect_imported_prefix_from_sql(string $sqlPath): string {
		if (!file_exists($sqlPath)) {
			return '';
		}
		$handle = fopen($sqlPath, 'r');
		if (!$handle) return '';
		$prefix = '';
		while (!feof($handle)) {
			$line = fgets($handle);
			if ($line === false) break;
			if (preg_match('/^\s*CREATE\s+TABLE\s+[`\"]([a-zA-Z0-9_]+)(options|users|posts)[`\"]/i', $line, $m)) {
				$prefix = $m[1];
				break;
			}
		}
		fclose($handle);
		return $prefix;
	}

	/** Apply the imported DB prefix for the remainder of the request lifecycle. */
	private function apply_runtime_prefix(string $desiredPrefix): void {
		if ($desiredPrefix === '' || $desiredPrefix === $GLOBALS['table_prefix']) {
			return;
		}
		$GLOBALS['table_prefix'] = $desiredPrefix;
		if (isset($GLOBALS['wpdb'])) {
			global $wpdb;
			if (method_exists($wpdb, 'set_prefix')) {
				$wpdb->set_prefix($desiredPrefix);
			}
		}
	}

	/** Drop all tables with the given drop prefix, preserving tables that match the keep prefix. */
	private function drop_tables_with_prefix(string $dropPrefix, string $keepPrefix): void {
		if ($dropPrefix === '' || $dropPrefix === $keepPrefix) {
			return;
		}
		global $wpdb;
		$pattern = $dropPrefix . '%';
		$tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $pattern));
		if (empty($tables)) {
			return;
		}
		$wpdb->query('SET FOREIGN_KEY_CHECKS=0');
		foreach ($tables as $table) {
			if (strpos($table, $keepPrefix) === 0) {
				continue;
			}
			$wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
		}
		$wpdb->query('SET FOREIGN_KEY_CHECKS=1');
	}

	private function find_wp_content_dir(string $root): string {
		$root = wp_normalize_path(untrailingslashit($root));
		$queue = [$root];
		$maxDepth = self::WP_CONTENT_SEARCH_MAX_DEPTH;
		$depth = 0;
		while (!empty($queue) && $depth <= $maxDepth) {
			$next = [];
			foreach ($queue as $dir) {
				$items = @scandir($dir);
				if ($items === false) continue;
				foreach ($items as $item) {
					if ($item === '.' || $item === '..') continue;
					$path = wp_normalize_path($dir . '/' . $item);
					if (is_dir($path)) {
						if (basename($path) === 'wp-content') {
							return $path;
						}
						$next[] = $path;
					}
				}
			}
			$queue = $next;
			$depth++;
		}
		return '';
	}

	/** Locate a SQL file within the extracted archive. */
	private function find_sql_file(string $root): string {
		$root = wp_normalize_path(untrailingslashit($root));
		if (!is_dir($root)) return '';
		$iter = @new \RecursiveIteratorIterator(@new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
		if ($iter) {
			foreach ($iter as $file) {
				$path = wp_normalize_path($file->getPathname());
				if (substr($path, -4) === '.sql') {
					return $path;
				}
			}
		}
		return '';
	}

	/** Restore the preserved backup directory setting and ensure it exists and is protected. */
	private function restore_preserved_backup_dir(string $backupDir): void {
		$settings = get_option('staging2live_settings', []);
		$settings = is_array($settings) ? $settings : [];
		$settings['backup_dir'] = wp_normalize_path(untrailingslashit($backupDir));
		update_option('staging2live_settings', $settings);

		// Ensure directory exists and is protected (index.html, .htaccess)
		$dir = wp_normalize_path(trailingslashit($settings['backup_dir']));
		if (!is_dir($dir)) {
			wp_mkdir_p($dir);
		}
		$index_path = $dir . 'index.html';
		if (!file_exists($index_path)) {
			file_put_contents($index_path, "");
		}
		$htaccess_path = $dir . '.htaccess';
		if (!file_exists($htaccess_path)) {
			$rules = "# Staging2Live backups protection\nOptions -Indexes\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n";
			file_put_contents($htaccess_path, $rules);
		}
	}

	/** Ensure that URLs in the DB match the expected current site URL using metadata. */
	private function ensure_live_url_canonical(string $zipPath): void {
		$dir = dirname($zipPath);
		$base = basename($zipPath, '.zip');
		$jsonPath = $dir . '/' . $base . '.json';
		$metaSvc = new MetadataService();
		$data = $metaSvc->read_metadata($jsonPath);
		$target = isset($data['target_live_url']) ? rtrim($data['target_live_url'], '/') : '';
		if ($target === '') {
			$target = rtrim(home_url(), '/');
		}
		$current = rtrim(site_url(), '/');
		if ($current === $target) {
			return;
		}
		$this->run_wp_cli_search_replace($current, $target);
		// Best-effort options update regardless of CLI
		update_option('siteurl', $target);
		update_option('home', $target);
	}

	/**
	 * Enforce the provided URL as canonical by updating options and running search/replace when possible.
	 */
	private function ensure_live_url_canonical_explicit(string $dbPrefix, string $targetUrl): void {
		global $wpdb;
		$target = rtrim($targetUrl, '/');
		// Update options table entries directly
		$optionsTable = $dbPrefix . 'options';
		$wpdb->query($wpdb->prepare("UPDATE `$optionsTable` SET option_value=%s WHERE option_name IN ('siteurl','home')", $target));
		// Also run a broader search/replace when WP-CLI is available (safer for serialized data)
		$this->run_wp_cli_search_replace(rtrim(site_url(), '/'), $target);
	}

	/** Persist the current site URLs prior to DB import for later inspection. */
	private function persist_current_site_urls(string $backupDir, string $siteurl, string $home): void {
		$meta = [
			'siteurl' => $siteurl,
			'home' => $home,
			'saved_at' => gmdate('c'),
		];
		$path = wp_normalize_path(untrailingslashit($backupDir) . '/' . self::SITEURL_META_FILENAME);
		@file_put_contents($path, wp_json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	/** Recursively remove a directory. */
	private function rrmdir(string $dir): void {
		$dir = wp_normalize_path($dir);
		if (!is_dir($dir)) return;
		$items = scandir($dir);
		if ($items === false) return;
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') continue;
			$path = $dir . '/' . $item;
			if (is_dir($path)) {
				$this->rrmdir($path);
			} else {
				@unlink($path);
			}
		}
		@rmdir($dir);
	}

	/** Run a WP-CLI search/replace operation when WP-CLI is available. */
	private function run_wp_cli_search_replace(string $fromUrl, string $toUrl): void {
		if (defined('WP_CLI') && WP_CLI && class_exists('\\WP_CLI')) {
			$cmd = sprintf(
				'search-replace %s %s --all-tables-with-prefix --skip-columns=guid --precise --quiet',
				escapeshellarg($fromUrl),
				escapeshellarg($toUrl)
			);
			\WP_CLI::runcommand($cmd, [ 'return' => 'all', 'exit_error' => false ]);
		}
	}
}


