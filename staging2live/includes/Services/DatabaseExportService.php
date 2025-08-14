<?php
namespace Staging2Live\Services;

if (!defined('ABSPATH')) {
	exit;
}

class DatabaseExportService {
	public function export_to_sql(string $destinationPath): string {
		$destinationPath = wp_normalize_path($destinationPath);
		$destinationDir = dirname($destinationPath);
		if (!is_dir($destinationDir)) {
			wp_mkdir_p($destinationDir);
		}

		// Prefer WP-CLI when running under WP-CLI context
		$cli = new \Staging2Live\Services\WpCliService();
		if (\Staging2Live\Services\WpCliService::is_cli_context() || \Staging2Live\Services\WpCliService::can_invoke_from_http()) {
			$cmd = 'db export ' . escapeshellarg($destinationPath);
			$cli::run($cmd);
			if (file_exists($destinationPath) && filesize($destinationPath) > 0) {
				return $destinationPath;
			}
		}

		// Fallback to mysqldump if available
		if ($this->try_mysqldump($destinationPath)) {
			return $destinationPath;
		}

		// Minimal PHP fallback (very limited; not for large sites)
		global $wpdb;
		$tables = $wpdb->get_col('SHOW TABLES');
		$handle = fopen($destinationPath, 'w');
		if (!$handle) {
			return '';
		}
		foreach ($tables as $table) {
			$this->write_table_dump($handle, $table);
		}
		fclose($handle);
		return (file_exists($destinationPath) && filesize($destinationPath) > 0) ? $destinationPath : '';
	}

	private function try_mysqldump(string $destinationPath): bool {
		if (!function_exists('escapeshellarg')) {
			return false;
		}
		$mysqldump = trim(shell_exec('command -v mysqldump 2>/dev/null'));
		if ($mysqldump === '') {
			return false;
		}
		$dbName = defined('DB_NAME') ? DB_NAME : '';
		$dbUser = defined('DB_USER') ? DB_USER : '';
		$dbPass = defined('DB_PASSWORD') ? DB_PASSWORD : '';
		$dbHost = defined('DB_HOST') ? DB_HOST : 'localhost';
		$host = $dbHost;
		$port = '';
		$socket = '';
		if (strpos($dbHost, ':') !== false) {
			list($h, $p) = explode(':', $dbHost, 2);
			$host = $h;
			if (strpos($p, '/') === 0) {
				$socket = $p;
			} else {
				$port = $p;
			}
		}
		$args = [];
		$args[] = '--default-character-set=utf8mb4';
		$args[] = '--single-transaction';
		$args[] = '--quick';
		$args[] = '--skip-lock-tables';
		if ($host !== '') {
			$args[] = '--host=' . escapeshellarg($host);
		}
		if ($port !== '') {
			$args[] = '--port=' . escapeshellarg($port);
		}
		if ($socket !== '') {
			$args[] = '--socket=' . escapeshellarg($socket);
		}
		if ($dbUser !== '') {
			$args[] = '--user=' . escapeshellarg($dbUser);
		}
		if ($dbPass !== '') {
			$args[] = '--password=' . escapeshellarg($dbPass);
		}
		$args[] = escapeshellarg($dbName);

		$cmd = escapeshellcmd($mysqldump) . ' ' . implode(' ', $args) . ' > ' . escapeshellarg($destinationPath) . ' 2>/dev/null';
		shell_exec($cmd);
		clearstatcache();
		return (file_exists($destinationPath) && filesize($destinationPath) > 0);
	}

	private function write_table_dump($handle, string $tableName): void {
		global $wpdb;
		// Header
		fwrite($handle, "--\n-- Table structure for table `{$tableName}`\n--\n\n");
		$row = $wpdb->get_row("SHOW CREATE TABLE `{$tableName}`", ARRAY_N);
		if ($row && isset($row[1])) {
			fwrite($handle, "DROP TABLE IF EXISTS `{$tableName}`;\n");
			fwrite($handle, $row[1] . ";\n\n");
		}
		// Data
		fwrite($handle, "--\n-- Dumping data for table `{$tableName}`\n--\n\n");
		$offset = 0;
		$limit = 1000;
		while (true) {
			$results = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$tableName}` LIMIT %d OFFSET %d", $limit, $offset), ARRAY_A);
			if (empty($results)) {
				break;
			}
			$columns = array_map(function ($c) { return '`' . str_replace('`', '``', $c) . '`'; }, array_keys($results[0]));
			$values = [];
			foreach ($results as $rowData) {
				$escaped = array_map(function ($v) {
					if ($v === null) return 'NULL';
					return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $v) . "'";
				}, array_values($rowData));
				$values[] = '(' . implode(', ', $escaped) . ')';
			}
			fwrite($handle, 'INSERT INTO `' . $tableName . '` (' . implode(', ', $columns) . ") VALUES\n" . implode(",\n", $values) . ";\n");
			$offset += $limit;
		}
		fwrite($handle, "\n\n");
	}
}


