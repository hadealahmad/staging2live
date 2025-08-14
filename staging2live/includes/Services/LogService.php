<?php
namespace Staging2Live\Services;

if (!defined('ABSPATH')) {
	exit;
}

class LogService {
	public static function write(string $message): void {
		$options = get_option('staging2live_settings', []);
		$backupDir = isset($options['backup_dir']) ? $options['backup_dir'] : (WP_CONTENT_DIR . '/staging2live-backups');
		$backupDir = wp_normalize_path(untrailingslashit($backupDir));
		if (!is_dir($backupDir)) {
			wp_mkdir_p($backupDir);
		}
		$logPath = $backupDir . '/staging2live.log';
		$line = '[' . gmdate('c') . '] ' . $message . "\n";
		@file_put_contents($logPath, $line, FILE_APPEND);
	}
}


