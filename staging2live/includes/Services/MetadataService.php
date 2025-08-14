<?php
namespace Staging2Live\Services;

if (!defined('ABSPATH')) {
	exit;
}

class MetadataService {
	public function write_metadata(string $jsonPath, array $data): bool {
		$dir = dirname($jsonPath);
		if (!is_dir($dir)) {
			wp_mkdir_p($dir);
		}
		$json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return file_put_contents($jsonPath, $json) !== false;
	}

	public function read_metadata(string $jsonPath): array {
		if (!file_exists($jsonPath)) {
			return [];
		}
		$raw = file_get_contents($jsonPath);
		$data = json_decode($raw, true);
		return is_array($data) ? $data : [];
	}
}


