<?php
namespace Staging2Live\Services;

if (!defined('ABSPATH')) {
	exit;
}

class SearchReplaceService {
	public function replace_in_sql_dump(string $sqlPath, string $search, string $replace): string {
		$sqlPath = wp_normalize_path($sqlPath);
		if (!file_exists($sqlPath)) {
			return '';
		}

		// Try WP-CLI from HTTP context if available
		if (\Staging2Live\Services\WpCliService::can_invoke_from_http()) {
			$exportPath = $sqlPath . '.replaced.sql';
			\Staging2Live\Services\WpCliService::run(
				'search-replace ' . escapeshellarg($search) . ' ' . escapeshellarg($replace) . ' --all-tables-with-prefix --export=' . escapeshellarg($exportPath) . ' --skip-columns=guid'
			);
			if (file_exists($exportPath) && filesize($exportPath) > 0) {
				return $exportPath;
			}
		}

		// Simple stream replace (schema-preserving). Serializer-unsafe; final replacement also runs after restore.
		$tmpPath = $sqlPath . '.replaced.sql';
		$in = fopen($sqlPath, 'r');
		$out = fopen($tmpPath, 'w');
		if (!$in || !$out) {
			if ($in) fclose($in);
			if ($out) fclose($out);
			return '';
		}
		$searchEsc = $search;
		$replaceEsc = $replace;
		while (!feof($in)) {
			$line = fgets($in);
			if ($line === false) break;
			$line = str_replace($searchEsc, $replaceEsc, $line);
			fwrite($out, $line);
		}
		fclose($in);
		fclose($out);
		return $tmpPath;
	}
}


