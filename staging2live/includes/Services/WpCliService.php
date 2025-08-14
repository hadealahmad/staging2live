<?php
namespace Staging2Live\Services;

if (!defined('ABSPATH')) {
	exit;
}

class WpCliService {
	public static function is_cli_context(): bool {
		return defined('WP_CLI') && WP_CLI;
	}

	public static function find_php_binary(): string {
		$php = trim(shell_exec('command -v php 2>/dev/null'));
		return $php ?: '';
	}

	public static function embedded_wp_cli_phar(): string {
		$phar = wp_normalize_path(STL_PLUGIN_DIR . 'bin/wp-cli.phar');
		return file_exists($phar) ? $phar : '';
	}

	public static function system_wp_cli(): string {
		$wp = trim(shell_exec('command -v wp 2>/dev/null'));
		return $wp ?: '';
	}

	public static function can_invoke_from_http(): bool {
		return (self::find_php_binary() !== '' && (self::embedded_wp_cli_phar() !== '' || self::system_wp_cli() !== ''));
	}

	/**
	 * Run a WP-CLI command either via native WP_CLI in CLI context, system 'wp', or embedded phar with php.
	 */
	public static function run(string $subcommand, array $options = []): array {
		$pathArg = '--path=' . escapeshellarg(ABSPATH);
		if (self::is_cli_context()) {
			$cmd = $subcommand;
			\WP_CLI::runcommand($cmd, [ 'return' => 'all', 'exit_error' => false ]);
			return ['success' => true, 'output' => ''];
		}

		$systemWp = self::system_wp_cli();
		if ($systemWp !== '') {
			$cmd = escapeshellcmd($systemWp) . ' ' . $subcommand . ' ' . $pathArg . ' --allow-root 2>&1';
			$out = shell_exec($cmd);
			return ['success' => true, 'output' => (string) $out];
		}

		$php = self::find_php_binary();
		$phar = self::embedded_wp_cli_phar();
		if ($php !== '' && $phar !== '') {
			$cmd = escapeshellcmd($php) . ' ' . escapeshellarg($phar) . ' ' . $subcommand . ' ' . $pathArg . ' --allow-root 2>&1';
			$out = shell_exec($cmd);
			return ['success' => true, 'output' => (string) $out];
		}

		return ['success' => false, 'output' => ''];
	}
}


