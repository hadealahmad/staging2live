<?php
namespace Staging2Live\Services;

if (!defined('ABSPATH')) {
	exit;
}

class FilenameService {
	public static function sanitize_human_filename_component(string $input): string {
		$input = rawurldecode($input);
		$input = trim($input);
		// Remove control chars
		$input = preg_replace('/[\x00-\x1F\x7F]/u', '', $input);
		// Replace disallowed filesystem characters with hyphen
		$input = str_replace(["/", "\\", ":", "*", "?", "\"", "<", ">", "|"], '-', $input);
		// Collapse whitespace to single hyphen
		$input = preg_replace('/\s+/u', '-', $input);
		// Trim repeated dashes
		$input = preg_replace('/-+/', '-', $input);
		$input = trim($input, "-._ ");
		// Limit length for safety (bytes)
		if (strlen($input) > 200) {
			$input = mb_strcut($input, 0, 200, 'UTF-8');
			$input = rtrim($input, "-._ ");
		}
		return $input;
	}
}


