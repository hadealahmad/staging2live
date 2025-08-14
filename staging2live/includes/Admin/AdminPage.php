<?php
namespace Staging2Live\Admin;

if (!defined('ABSPATH')) {
	exit;
}

class AdminPage {
	const OPTION_NAME = 'staging2live_settings';

	public static function register_menu() {
		add_menu_page(
			__('Staging2Live', 'staging2live'),
			__('Staging2Live', 'staging2live'),
			'manage_options',
			'staging2live',
			[__CLASS__, 'render_settings_page'],
			'dashicons-backup',
			65
		);
	}

	public static function register_settings() {
		$defaults = [
			'staging_url' => '',
			'live_url' => '',
			'backup_dir' => defined('STL_DEFAULT_BACKUP_DIR') ? STL_DEFAULT_BACKUP_DIR : WP_CONTENT_DIR . '/staging2live-backups',
			'retention' => 10,
			'pre_restore_backup' => 1,
		];

		register_setting(
			'staging2live_settings_group',
			self::OPTION_NAME,
			[
				'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
				'default' => $defaults,
			]
		);

		add_settings_section(
			'stl_main',
			__('General Settings', 'staging2live'),
			function () {
				echo '<p>' . esc_html__('Configure URLs and backup directory for Staging2Live.', 'staging2live') . '</p>';
			},
			'staging2live'
		);

		add_settings_field(
			'staging_url',
			__('Staging URL', 'staging2live'),
			[__CLASS__, 'field_staging_url'],
			'staging2live',
			'stl_main'
		);

		add_settings_field(
			'live_url',
			__('Live URL', 'staging2live'),
			[__CLASS__, 'field_live_url'],
			'staging2live',
			'stl_main'
		);

		add_settings_field(
			'backup_dir',
			__('Backup Directory', 'staging2live'),
			[__CLASS__, 'field_backup_dir'],
			'staging2live',
			'stl_main'
		);

		add_settings_field(
			'retention',
			__('Retention (number of backups to keep)', 'staging2live'),
			[__CLASS__, 'field_retention'],
			'staging2live',
			'stl_main'
		);

		add_settings_field(
			'pre_restore_backup',
			__('Create safety backup before restore', 'staging2live'),
			[__CLASS__, 'field_pre_restore_backup'],
			'staging2live',
			'stl_main'
		);
	}

	public static function sanitize_settings($input) {
		$sanitized = [];
		$sanitized['staging_url'] = isset($input['staging_url']) ? esc_url_raw(rtrim(trim($input['staging_url']), '/')) : '';
		$sanitized['live_url'] = isset($input['live_url']) ? esc_url_raw(rtrim(trim($input['live_url']), '/')) : '';
		$backup_dir = isset($input['backup_dir']) ? trim(wp_unslash($input['backup_dir'])) : '';
		if ($backup_dir === '') {
			$backup_dir = defined('STL_DEFAULT_BACKUP_DIR') ? STL_DEFAULT_BACKUP_DIR : WP_CONTENT_DIR . '/staging2live-backups';
		}
		$sanitized['backup_dir'] = wp_normalize_path(untrailingslashit($backup_dir));
		$retention = isset($input['retention']) ? intval($input['retention']) : 10;
		$sanitized['retention'] = max(1, $retention);
		$sanitized['pre_restore_backup'] = isset($input['pre_restore_backup']) ? 1 : 0;
		return $sanitized;
	}

	public static function field_staging_url() {
		$options = get_option(self::OPTION_NAME, []);
		$value = isset($options['staging_url']) ? $options['staging_url'] : get_site_url();
		echo '<input type="url" name="' . esc_attr(self::OPTION_NAME) . '[staging_url]" value="' . esc_attr($value) . '" class="regular-text" placeholder="https://staging.example.com" />';
	}

	public static function field_live_url() {
		$options = get_option(self::OPTION_NAME, []);
		$value = isset($options['live_url']) ? $options['live_url'] : home_url();
		echo '<input type="url" name="' . esc_attr(self::OPTION_NAME) . '[live_url]" value="' . esc_attr($value) . '" class="regular-text" placeholder="https://example.com" />';
	}

	public static function field_backup_dir() {
		$options = get_option(self::OPTION_NAME, []);
		$default_dir = defined('STL_DEFAULT_BACKUP_DIR') ? STL_DEFAULT_BACKUP_DIR : WP_CONTENT_DIR . '/staging2live-backups';
		$value = isset($options['backup_dir']) ? $options['backup_dir'] : $default_dir;
		echo '<input type="text" name="' . esc_attr(self::OPTION_NAME) . '[backup_dir]" value="' . esc_attr($value) . '" class="regular-text code" placeholder="' . esc_attr($default_dir) . '" />';
	}

	public static function field_retention() {
		$options = get_option(self::OPTION_NAME, []);
		$value = isset($options['retention']) ? intval($options['retention']) : 10;
		echo '<input type="number" min="1" step="1" name="' . esc_attr(self::OPTION_NAME) . '[retention]" value="' . esc_attr($value) . '" class="small-text" />';
	}

	public static function field_pre_restore_backup() {
		$options = get_option(self::OPTION_NAME, []);
		$checked = isset($options['pre_restore_backup']) ? (int)$options['pre_restore_backup'] : 1;
		echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_NAME) . '[pre_restore_backup]" value="1" ' . checked(1, $checked, false) . ' /> ' . esc_html__('Enable automatic safety backup before restore', 'staging2live') . '</label>';
	}

	public static function render_settings_page() {
		if (!current_user_can('manage_options')) {
			return;
		}
		$tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'backups';
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__('Staging2Live', 'staging2live') . '</h1>';
		echo '<h2 class="nav-tab-wrapper">';
		self::nav_tab('backups', __('Backups', 'staging2live'), $tab === 'backups');
		self::nav_tab('settings', __('Settings', 'staging2live'), $tab === 'settings');
		echo '</h2>';
		if ($tab === 'settings') {
			self::render_settings_tab();
		} else {
			self::render_backups_tab();
		}
		echo '</div>';
	}

	private static function nav_tab(string $slug, string $label, bool $active): void {
		$url = add_query_arg(['page' => 'staging2live', 'tab' => $slug], admin_url('admin.php'));
		$cls = 'nav-tab' . ($active ? ' nav-tab-active' : '');
		echo '<a href="' . esc_url($url) . '" class="' . esc_attr($cls) . '">' . esc_html($label) . '</a>';
	}

	private static function render_settings_tab(): void {
		echo '<form method="post" action="options.php">';
		settings_fields('staging2live_settings_group');
		do_settings_sections('staging2live');
		submit_button();
		echo '</form>';

		// Upload backups UI
		$options = get_option(self::OPTION_NAME, []);
		$backupDir = isset($options['backup_dir']) ? $options['backup_dir'] : (WP_CONTENT_DIR . '/staging2live-backups');
		$backupDir = wp_normalize_path(untrailingslashit($backupDir));
		echo '<hr />';
		echo '<h2>' . esc_html__('Upload Backup Files', 'staging2live') . '</h2>';
		echo '<p>' . esc_html__('Upload a matching pair of ZIP and JSON files with the same basename.', 'staging2live') . '</p>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
		echo '<input type="hidden" name="action" value="stl_upload_backup" />';
		echo wp_nonce_field('stl_upload_backup', '_wpnonce', true, false);
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__('ZIP file', 'staging2live') . '</th><td><input type="file" name="stl_zip" accept=".zip" required /></td></tr>';
		echo '<tr><th>' . esc_html__('JSON file', 'staging2live') . '</th><td><input type="file" name="stl_json" accept="application/json,.json" required /></td></tr>';
		echo '</tbody></table>';
		$max_upload = wp_max_upload_size();
		$max_upload_h = $max_upload ? size_format($max_upload) : __('Unknown', 'staging2live');
		$ini_upload = ini_get('upload_max_filesize');
		$ini_post = ini_get('post_max_size');
		$ini_files = ini_get('max_file_uploads');
		echo '<p><em>' . esc_html__('Server upload limits:', 'staging2live') . ' ' . esc_html(sprintf(__('Max upload size: %s (upload_max_filesize=%s, post_max_size=%s, max_file_uploads=%s)', 'staging2live'), $max_upload_h, $ini_upload, $ini_post, $ini_files)) . '</em></p>';
		submit_button(__('Upload Backup', 'staging2live'));
		echo '</form>';

		// Orphaned files list
		$orphans = self::find_orphaned_files($backupDir);
		if (!empty($orphans)) {
			echo '<h3>' . esc_html__('Orphaned Files', 'staging2live') . '</h3>';
			echo '<p>' . esc_html__('These files have no matching ZIP/JSON pair. You can delete them.', 'staging2live') . '</p>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('File', 'staging2live') . '</th><th>' . esc_html__('Action', 'staging2live') . '</th></tr></thead><tbody>';
			foreach ($orphans as $file) {
				$del = wp_nonce_url(admin_url('admin-post.php?action=stl_delete_orphan&file=' . rawurlencode(basename($file))), 'stl_delete_orphan_' . basename($file));
				echo '<tr><td>' . esc_html(basename($file)) . '</td><td><a class="button button-link-delete" onclick="return confirm(\'' . esc_js(__('Delete this file?', 'staging2live')) . '\');" href="' . esc_url($del) . '">' . esc_html__('Delete', 'staging2live') . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	private static function render_backups_tab(): void {
		$options = get_option(self::OPTION_NAME, []);
		$backupDir = isset($options['backup_dir']) ? $options['backup_dir'] : (WP_CONTENT_DIR . '/staging2live-backups');
		$backupDir = wp_normalize_path(untrailingslashit($backupDir));
		$items = self::scan_backups($backupDir);
		if (isset($_GET['deleted'])) {
			echo '<div class="notice notice-success stl-notice"><p>' . esc_html__('Backup deleted.', 'staging2live') . '</p></div>';
		}
		if (isset($_GET['restored'])) {
			$ok = intval($_GET['restored']) === 1;
			echo '<div class="notice ' . ($ok ? 'notice-success' : 'notice-error') . ' stl-notice"><p>' . esc_html($ok ? __('Restore completed.', 'staging2live') : __('Restore failed. Check logs.', 'staging2live')) . '</p></div>';
		}
		if (isset($_GET['created'])) {
			$ok = intval($_GET['created']) === 1;
			echo '<div class="notice ' . ($ok ? 'notice-success' : 'notice-error') . ' stl-notice"><p>' . esc_html($ok ? __('Backup created.', 'staging2live') : __('Backup failed. Check logs.', 'staging2live')) . '</p></div>';
		}
		if (isset($_GET['logcleared'])) {
			$ok = intval($_GET['logcleared']) === 1;
			echo '<div class="notice ' . ($ok ? 'notice-success' : 'notice-error') . ' stl-notice"><p>' . esc_html($ok ? __('Log cleared.', 'staging2live') : __('Failed to clear log.', 'staging2live')) . '</p></div>';
		}
		$createUrl = wp_nonce_url(admin_url('admin-post.php?action=stl_create_backup'), 'stl_create_backup');
		$logUrl = wp_nonce_url(admin_url('admin-post.php?action=stl_download_log'), 'stl_download_log');
		$clearLogUrl = wp_nonce_url(admin_url('admin-post.php?action=stl_clear_log'), 'stl_clear_log');
		self::render_diagnostics($backupDir);
		echo '<p>';
		echo '<a href="' . esc_url($createUrl) . '" class="button button-primary">' . esc_html__('Create Backup', 'staging2live') . '</a> ';
		echo '<a href="' . esc_url($logUrl) . '" class="button">' . esc_html__('Download Log', 'staging2live') . '</a> ';
		echo '<a href="' . esc_url($clearLogUrl) . '" class="button">' . esc_html__('Clear Log', 'staging2live') . '</a>';
		echo '</p>';
		echo '<p>' . esc_html__('Available backups', 'staging2live') . '</p>';
		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__('Backup', 'staging2live') . '</th>';
		echo '<th>' . esc_html__('Size', 'staging2live') . '</th>';
		echo '<th>' . esc_html__('Site URL', 'staging2live') . '</th>';
		echo '<th>' . esc_html__('Admin', 'staging2live') . '</th>';
		echo '<th>' . esc_html__('Date', 'staging2live') . '</th>';
		echo '<th>' . esc_html__('Actions', 'staging2live') . '</th>';
		echo '</tr></thead><tbody>';
		if (empty($items)) {
			echo '<tr><td colspan="5">' . esc_html__('No backups found.', 'staging2live') . '</td></tr>';
		} else {
			foreach ($items as $item) {
				$deleteUrl = wp_nonce_url(admin_url('admin-post.php?action=stl_delete_backup&file=' . rawurlencode($item['base'])), 'stl_delete_' . $item['base']);
				$restoreNonceField = wp_nonce_field('stl_restore_' . $item['base'], '_wpnonce', true, false);
				$downloadZip = wp_nonce_url(admin_url('admin-post.php?action=stl_download_backup&file=' . rawurlencode($item['base']) . '&type=zip'), 'stl_download_' . $item['base']);
				$downloadJson = wp_nonce_url(admin_url('admin-post.php?action=stl_download_backup&file=' . rawurlencode($item['base']) . '&type=json'), 'stl_download_' . $item['base']);
				echo '<tr>';
				echo '<td>' . esc_html($item['zip']) . '</td>';
				$size = file_exists($item['path']) ? size_format(filesize($item['path'])) : '';
				echo '<td>' . esc_html($size) . '</td>';
				echo '<td>' . esc_html($item['meta']['site_url'] ?? '') . '</td>';
				echo '<td>' . esc_html($item['meta']['admin_username'] ?? '') . '</td>';
				$date = isset($item['meta']['backup_date_utc']) ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $item['meta']['backup_date_utc']) : '';
				echo '<td>' . esc_html($date) . '</td>';
				echo '<td>';
				// Inline restore form with option to enforce current site URL
				echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px;">';
				echo '<input type="hidden" name="action" value="stl_restore_backup" />';
				echo '<input type="hidden" name="file" value="' . esc_attr($item['base']) . '" />';
				echo $restoreNonceField;
				echo '<label style="margin-right:6px;display:inline-block;"><input type="checkbox" name="force_current_url" value="1" /> ' . esc_html__('Force URLs to current site', 'staging2live') . '</label>';
				echo '<button type="submit" class="button">' . esc_html__('Restore', 'staging2live') . '</button>';
				echo '</form>';
				echo '<a class="button" href="' . esc_url($downloadZip) . '">' . esc_html__('Download Zip', 'staging2live') . '</a> ';
				echo '<a class="button" href="' . esc_url($downloadJson) . '">' . esc_html__('Download JSON', 'staging2live') . '</a> ';
				echo '<a class="button button-link-delete" href="' . esc_url($deleteUrl) . '" onclick="return confirm(\'' . esc_js(__('Delete this backup?', 'staging2live')) . '\');">' . esc_html__('Delete', 'staging2live') . '</a>';
				echo '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';
	}

	private static function render_diagnostics(string $backupDir): void {
		$checks = [];
		$checks[] = [__('Backup directory', 'staging2live'), $backupDir, is_writable($backupDir) ? __('Writable', 'staging2live') : __('Not writable', 'staging2live')];
		$checks[] = [__('WP-CLI available', 'staging2live'), '', (defined('WP_CLI') && WP_CLI) ? __('Yes', 'staging2live') : __('No', 'staging2live')];
		$checks[] = [__('ZipArchive', 'staging2live'), '', class_exists('ZipArchive') ? __('Yes', 'staging2live') : __('No', 'staging2live')];
		$mysqldump = trim(shell_exec('command -v mysqldump 2>/dev/null'));
		$mysql = trim(shell_exec('command -v mysql 2>/dev/null'));
		$checks[] = [__('mysqldump', 'staging2live'), '', $mysqldump ? __('Yes', 'staging2live') : __('No', 'staging2live')];
		$checks[] = [__('mysql (client)', 'staging2live'), '', $mysql ? __('Yes', 'staging2live') : __('No', 'staging2live')];
		echo '<h2>' . esc_html__('Diagnostics', 'staging2live') . '</h2>';
		echo '<table class="widefat striped">';
		echo '<thead><tr><th>' . esc_html__('Check', 'staging2live') . '</th><th>' . esc_html__('Value', 'staging2live') . '</th><th>' . esc_html__('Status', 'staging2live') . '</th></tr></thead><tbody>';
		foreach ($checks as $row) {
			echo '<tr><td>' . esc_html($row[0]) . '</td><td>' . esc_html($row[1]) . '</td><td>' . esc_html($row[2]) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function scan_backups(string $backupDir): array {
		$items = [];
		if (!is_dir($backupDir)) {
			return $items;
		}
		$files = scandir($backupDir);
		if ($files === false) {
			return $items;
		}
		foreach ($files as $file) {
			if ($file === '.' || $file === '..') continue;
			if (substr($file, -4) !== '.zip') continue;
			$base = substr($file, 0, -4);
			$json = $backupDir . '/' . $base . '.json';
			$meta = [];
			if (file_exists($json)) {
				$raw = file_get_contents($json);
				$decoded = json_decode($raw, true);
				if (is_array($decoded)) {
					$meta = $decoded;
				}
			}
			$items[] = [
				'base' => $base,
				'zip' => $file,
				'json' => $base . '.json',
				'path' => $backupDir . '/' . $file,
				'json_path' => $json,
				'meta' => $meta,
			];
		}
		// Sort by date desc if metadata available, else by filename
		usort($items, function($a, $b){
			$ad = $a['meta']['backup_date_utc'] ?? '';
			$bd = $b['meta']['backup_date_utc'] ?? '';
			if ($ad === '' && $bd === '') return strcmp($b['zip'], $a['zip']);
			return strcmp($bd, $ad);
		});
		return $items;
	}

	private static function find_orphaned_files(string $backupDir): array {
		$files = @scandir($backupDir);
		if ($files === false) return [];
		$zips = [];
		$jsons = [];
		foreach ($files as $f) {
			if ($f === '.' || $f === '..') continue;
			$path = $backupDir . '/' . $f;
			if (!is_file($path)) continue;
			// Ignore internal state file
			if ($f === '.staging2live-siteurl.json') continue;
			if (substr($f, -4) === '.zip') $zips[] = substr($f, 0, -4);
			if (substr($f, -5) === '.json') $jsons[] = substr($f, 0, -5);
		}
		$orphans = [];
		foreach ($zips as $b) {
			if (!in_array($b, $jsons, true)) $orphans[] = $backupDir . '/' . $b . '.zip';
		}
		foreach ($jsons as $b) {
			if (!in_array($b, $zips, true)) $orphans[] = $backupDir . '/' . $b . '.json';
		}
		return $orphans;
	}

	public static function handle_upload_backup(): void {
		if (!current_user_can('manage_options')) wp_die(__('Insufficient permissions', 'staging2live'));
		check_admin_referer('stl_upload_backup');
		$options = get_option(self::OPTION_NAME, []);
		$backupDir = isset($options['backup_dir']) ? $options['backup_dir'] : (WP_CONTENT_DIR . '/staging2live-backups');
		$backupDir = wp_normalize_path(untrailingslashit($backupDir));
		wp_mkdir_p($backupDir);

		$zip = isset($_FILES['stl_zip']) ? $_FILES['stl_zip'] : null;
		$json = isset($_FILES['stl_json']) ? $_FILES['stl_json'] : null;
		if (!$zip || !$json || $zip['error'] !== UPLOAD_ERR_OK || $json['error'] !== UPLOAD_ERR_OK) {
			wp_safe_redirect(add_query_arg(['page' => 'staging2live', 'tab' => 'settings', 'upload' => 'error'], admin_url('admin.php')));
			exit;
		}
		$zipName = pathinfo($zip['name'], PATHINFO_FILENAME);
		$jsonName = pathinfo($json['name'], PATHINFO_FILENAME);
		if ($zipName !== $jsonName) {
			wp_safe_redirect(add_query_arg(['page' => 'staging2live', 'tab' => 'settings', 'upload' => 'mismatch'], admin_url('admin.php')));
			exit;
		}
		$base = \Staging2Live\Services\FilenameService::sanitize_human_filename_component($zipName);
		$destZip = $backupDir . '/' . $base . '.zip';
		$destJson = $backupDir . '/' . $base . '.json';
		if (!@move_uploaded_file($zip['tmp_name'], $destZip) || !@move_uploaded_file($json['tmp_name'], $destJson)) {
			@unlink($destZip);
			@unlink($destJson);
			wp_safe_redirect(add_query_arg(['page' => 'staging2live', 'tab' => 'settings', 'upload' => 'ioerror'], admin_url('admin.php')));
			exit;
		}
		wp_safe_redirect(add_query_arg(['page' => 'staging2live', 'tab' => 'backups', 'uploaded' => 1], admin_url('admin.php')));
		exit;
	}

	public static function handle_delete_orphan(): void {
		if (!current_user_can('manage_options')) wp_die(__('Insufficient permissions', 'staging2live'));
		$file = isset($_GET['file']) ? wp_basename(rawurldecode(wp_unslash($_GET['file']))) : '';
		check_admin_referer('stl_delete_orphan_' . $file);
		$options = get_option(self::OPTION_NAME, []);
		$backupDir = isset($options['backup_dir']) ? $options['backup_dir'] : (WP_CONTENT_DIR . '/staging2live-backups');
		$backupDir = wp_normalize_path(untrailingslashit($backupDir));
		$path = $backupDir . '/' . $file;
		if (is_file($path)) @unlink($path);
		wp_safe_redirect(add_query_arg(['page' => 'staging2live', 'tab' => 'settings'], admin_url('admin.php')));
		exit;
	}

	public static function handle_delete_backup(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Insufficient permissions', 'staging2live'));
		}
		$raw = isset($_REQUEST['file']) ? wp_unslash($_REQUEST['file']) : '';
		// Support values coming from GET (urlencoded) or POST (plain)
		$decoded = is_string($raw) ? rawurldecode($raw) : '';
		$base = wp_basename($decoded !== '' ? $decoded : $raw);
		// Strip extension if provided
		$base = preg_replace('/\.(zip|json)$/i', '', $base);
		if ($base === '' || strpos($base, '..') !== false || strpos($base, '/') !== false || strpos($base, '\\') !== false) {
			wp_die(__('Invalid file', 'staging2live'));
		}
		check_admin_referer('stl_delete_' . $base);
		$options = get_option(self::OPTION_NAME, []);
		$backupDir = isset($options['backup_dir']) ? $options['backup_dir'] : (WP_CONTENT_DIR . '/staging2live-backups');
		$backupDir = wp_normalize_path(untrailingslashit($backupDir));
		if ($base) {
			@unlink($backupDir . '/' . $base . '.zip');
			@unlink($backupDir . '/' . $base . '.json');
		}
		wp_safe_redirect(add_query_arg(['page' => 'staging2live', 'tab' => 'backups', 'deleted' => 1], admin_url('admin.php')));
		exit;
	}

	public static function handle_restore_backup(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Insufficient permissions', 'staging2live'));
		}
		$raw = isset($_REQUEST['file']) ? wp_unslash($_REQUEST['file']) : '';
		$decoded = is_string($raw) ? rawurldecode($raw) : '';
		$base = wp_basename($decoded !== '' ? $decoded : $raw);
		$base = preg_replace('/\.(zip|json)$/i', '', $base);
		if ($base === '' || strpos($base, '..') !== false || strpos($base, '/') !== false || strpos($base, '\\') !== false) {
			wp_die(__('Invalid file', 'staging2live'));
		}
		check_admin_referer('stl_restore_' . $base);
		$forceCurrentUrl = !empty($_REQUEST['force_current_url']);
		$options = get_option(self::OPTION_NAME, []);
		$backupDir = isset($options['backup_dir']) ? $options['backup_dir'] : (WP_CONTENT_DIR . '/staging2live-backups');
		$backupDir = wp_normalize_path(untrailingslashit($backupDir));
		$zipPath = $backupDir . '/' . $base . '.zip';
		if (!file_exists($zipPath)) {
			$resolved = self::resolve_backup_base($backupDir, $base);
			if ($resolved !== '') {
				$base = $resolved;
				$zipPath = $backupDir . '/' . $base . '.zip';
			}
		}
		if (!file_exists($zipPath)) {
			\Staging2Live\Services\LogService::write('Restore invalid file: raw=' . print_r($raw, true) . ' decoded=' . $decoded . ' base=' . $base . ' path=' . $zipPath);
			wp_die(__('Invalid file', 'staging2live'));
		}
		$service = new \Staging2Live\Services\RestoreService();
		$result = $service->restore_from_backup($zipPath, [ 'force_current_url' => $forceCurrentUrl ]);
		$ok = !empty($result['success']);
		wp_safe_redirect(add_query_arg(['page' => 'staging2live', 'tab' => 'backups', 'restored' => $ok ? 1 : 0], admin_url('admin.php')));
		exit;
	}

	public static function handle_download_backup(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Insufficient permissions', 'staging2live'));
		}
		$raw = isset($_REQUEST['file']) ? wp_unslash($_REQUEST['file']) : '';
		$decoded = is_string($raw) ? rawurldecode($raw) : '';
		$base = wp_basename($decoded !== '' ? $decoded : $raw);
		$base = preg_replace('/\.(zip|json)$/i', '', $base);
		$type = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : 'zip';
		if ($base === '' || strpos($base, '..') !== false || strpos($base, '/') !== false || strpos($base, '\\') !== false) {
			wp_die(__('Invalid file', 'staging2live'));
		}
		check_admin_referer('stl_download_' . $base);
		$options = get_option(self::OPTION_NAME, []);
		$backupDir = isset($options['backup_dir']) ? $options['backup_dir'] : (WP_CONTENT_DIR . '/staging2live-backups');
		$backupDir = wp_normalize_path(untrailingslashit($backupDir));
		$path = $backupDir . '/' . $base . ($type === 'json' ? '.json' : '.zip');
		if (!file_exists($path)) {
			wp_die(__('File not found', 'staging2live'));
		}
		// Serve file with robust streaming for large files and Unicode names
		ignore_user_abort(true);
		@set_time_limit(0);
		if (function_exists('apache_setenv')) {
			@apache_setenv('no-gzip', '1');
		}
		if (function_exists('zlib_get_encoding') && zlib_get_encoding()) {
			@ini_set('zlib.output_compression', 'Off');
		}
		nocache_headers();
		$basename = basename($path);
		$asciiFallback = ($type === 'json') ? 'backup.json' : 'backup.zip';
		$disposition = "attachment; filename=\"$asciiFallback\"; filename*=UTF-8''" . rawurlencode($basename);
		header('Content-Description: File Transfer');
		header('Content-Type: ' . ($type === 'json' ? 'application/json; charset=UTF-8' : 'application/zip'));
		header('Content-Disposition: ' . $disposition);
		header('Content-Length: ' . sprintf('%u', filesize($path)));
		header('X-Content-Type-Options: nosniff');
		while (ob_get_level()) { ob_end_clean(); }
		$chunkSize = 1048576; // 1MB
		$fh = fopen($path, 'rb');
		if ($fh) {
			while (!feof($fh)) {
				echo fread($fh, $chunkSize);
				flush();
			}
			fclose($fh);
		}
		exit;
	}

	public static function handle_download_log(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Insufficient permissions', 'staging2live'));
		}
		check_admin_referer('stl_download_log');
		$options = get_option(self::OPTION_NAME, []);
		$backupDir = isset($options['backup_dir']) ? $options['backup_dir'] : (WP_CONTENT_DIR . '/staging2live-backups');
		$backupDir = wp_normalize_path(untrailingslashit($backupDir));
		$path = $backupDir . '/staging2live.log';
		if (!file_exists($path)) {
			wp_die(__('Log file not found', 'staging2live'));
		}
		header('Content-Description: File Transfer');
		header('Content-Type: text/plain; charset=UTF-8');
		header('Content-Disposition: attachment; filename=' . basename($path));
		header('Content-Length: ' . filesize($path));
		readfile($path);
		exit;
	}

	public static function handle_clear_log(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Insufficient permissions', 'staging2live'));
		}
		check_admin_referer('stl_clear_log');
		$options = get_option(self::OPTION_NAME, []);
		$backupDir = isset($options['backup_dir']) ? $options['backup_dir'] : (WP_CONTENT_DIR . '/staging2live-backups');
		$backupDir = wp_normalize_path(untrailingslashit($backupDir));
		$path = $backupDir . '/staging2live.log';
		$ok = true;
		if (file_exists($path)) {
			$ok = file_put_contents($path, '') !== false;
		}
		wp_safe_redirect(add_query_arg(['page' => 'staging2live', 'tab' => 'backups', 'logcleared' => $ok ? 1 : 0], admin_url('admin.php')));
		exit;
	}

	public static function handle_create_backup(): void {
		if (!current_user_can('manage_options')) {
			wp_die(__('Insufficient permissions', 'staging2live'));
		}
		check_admin_referer('stl_create_backup');
		$svc = new \Staging2Live\Services\BackupService();
		$result = $svc->create_backup(['replace_urls' => true]);
		$ok = !empty($result['success']);
		wp_safe_redirect(add_query_arg(['page' => 'staging2live', 'tab' => 'backups', 'created' => $ok ? 1 : 0], admin_url('admin.php')));
		exit;
	}
}


