<?php
namespace Staging2Live\Services;

if (!defined('ABSPATH')) {
	exit;
}

class ZipService {
	public function create_backup_zip(string $zipPath, string $wpContentDir, string $sqlPath, array $excludeDirs = []): bool {
		// Try system zip for performance on large sites
		if ($this->try_system_zip($zipPath, $wpContentDir, $sqlPath, $excludeDirs)) {
			return file_exists($zipPath) && filesize($zipPath) > 0;
		}

		$zip = new \ZipArchive();
		if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			return false;
		}

		$wpContentDir = wp_normalize_path(untrailingslashit($wpContentDir));
		$excludeDirs = array_map(function ($d) {
			return wp_normalize_path(untrailingslashit($d));
		}, $excludeDirs);

		$addDir = function($dir) use ($zip, $wpContentDir, $excludeDirs, &$addDir) {
			$items = scandir($dir);
			if ($items === false) {
				return;
			}
			foreach ($items as $item) {
				if ($item === '.' || $item === '..') continue;
				$path = wp_normalize_path($dir . '/' . $item);
				// Exclude dirs
				foreach ($excludeDirs as $ex) {
					if (strpos($path, $ex) === 0) {
						continue 2;
					}
				}
				if (is_dir($path)) {
					$addDir($path);
				} else {
					// Skip wp-config.php and core files; only include wp-content files
					if (basename($path) === 'wp-config.php') continue;
					if (strpos($path, $wpContentDir . '/') !== 0) continue;
					$localName = ltrim(str_replace($wpContentDir, 'wp-content', $path), '/');
					$zip->addFile($path, $localName);
				}
			}
		};

		$addDir($wpContentDir);

		// Add SQL dump at root
		$zip->addFile($sqlPath, 'database.sql');

		$zip->close();
		return file_exists($zipPath) && filesize($zipPath) > 0;
	}

	public function extract_backup_zip(string $zipPath, string $destinationDir): bool {
		$zip = new \ZipArchive();
		if ($zip->open($zipPath) !== true) {
			return false;
		}
		$destinationDir = wp_normalize_path(untrailingslashit($destinationDir));
		if (!is_dir($destinationDir)) {
			wp_mkdir_p($destinationDir);
		}
		$allowedRoot = $destinationDir;
		$ok = true;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if ($name === false) continue;
			// Normalize and validate entry path
			$normalized = str_replace('\\', '/', $name);
			if (strpos($normalized, '..') !== false) continue; // prevent traversal
			if (strlen($normalized) > 0 && $normalized[0] === '/') continue; // prevent absolute
			$allow = (basename($normalized) === 'database.sql') || (preg_match('#(^|/)wp-content/#', $normalized) === 1);
			if (!$allow) continue;
			$target = wp_normalize_path($allowedRoot . '/' . $normalized);
			$targetDir = dirname($target);
			if (!is_dir($targetDir)) {
				wp_mkdir_p($targetDir);
			}
			// Directory entries end with '/'
			if (substr($normalized, -1) === '/') {
				if (!is_dir($target)) {
					wp_mkdir_p($target);
				}
				continue;
			}
			$stream = $zip->getStream($name);
			if (!$stream) {
				$ok = false;
				break;
			}
			$out = fopen($target, 'w');
			if (!$out) {
				fclose($stream);
				$ok = false;
				break;
			}
			while (!feof($stream)) {
				$buf = fread($stream, 8192);
				if ($buf === false) break;
				fwrite($out, $buf);
			}
			fclose($stream);
			fclose($out);
		}
		$zip->close();
		return $ok;
	}

	private function try_system_zip(string $zipPath, string $wpContentDir, string $sqlPath, array $excludeDirs): bool {
		$zipBin = trim(shell_exec('command -v zip 2>/dev/null'));
		if ($zipBin === '') {
			return false;
		}
		$wpContentDir = wp_normalize_path(untrailingslashit($wpContentDir));
		$excludeArgs = [];
		foreach ($excludeDirs as $ex) {
			$ex = wp_normalize_path(untrailingslashit($ex));
			$excludeArgs[] = '-x';
			$excludeArgs[] = escapeshellarg(str_replace($wpContentDir . '/', 'wp-content/', $ex) . '/*');
		}
		$cwd = getcwd();
		chdir(ABSPATH);
		$cmd = escapeshellcmd($zipBin) . ' -rq ' . escapeshellarg($zipPath) . ' ' . escapeshellarg('wp-content') . ' ' . implode(' ', $excludeArgs) . ' 2>/dev/null';
		shell_exec($cmd);
		// Add database.sql
		$zip = new \ZipArchive();
		$opened = $zip->open($zipPath, \ZipArchive::CREATE);
		if ($opened === true) {
			$zip->addFile($sqlPath, 'database.sql');
			$zip->close();
		}
		chdir($cwd ?: ABSPATH);
		clearstatcache();
		return file_exists($zipPath) && filesize($zipPath) > 0;
	}
}


