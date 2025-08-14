<?php
namespace Staging2Live\Services;

if (!defined('ABSPATH')) {
	exit;
}

class PathService {
	public static function normalize(string $path): string {
		return wp_normalize_path(untrailingslashit($path));
	}

	public static function ensure_directory(string $dir): bool {
		$dir = self::normalize($dir);
		if (!is_dir($dir)) {
			return wp_mkdir_p($dir);
		}
		return true;
	}

	public static function is_subpath(string $child, string $parent): bool {
		$child = trailingslashit(self::normalize($child));
		$parent = trailingslashit(self::normalize($parent));
		return strpos($child, $parent) === 0;
	}
}


