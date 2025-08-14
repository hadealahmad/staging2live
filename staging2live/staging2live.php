<?php
/*
Plugin Name: Staging2Live
Description: Backup a staging site and restore it to a live site with URL replacement, zipping, and admin management.
Version: 0.1.0
Author: Staging2Live
License: GPLv2 or later
Text Domain: staging2live
*/

if (!defined('ABSPATH')) {
	exit;
}

// Constants
define('STL_PLUGIN_FILE', __FILE__);
define('STL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('STL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('STL_DEFAULT_BACKUP_DIR', trailingslashit(ABSPATH . 'wp-content/staging2live-backups'));

// Simple PSR-4 style autoloader for the plugin namespace
spl_autoload_register(function ($class) {
	$prefix = 'Staging2Live\\';
	$base_dir = STL_PLUGIN_DIR . 'includes/';
	$len = strlen($prefix);
	if (strncmp($prefix, $class, $len) !== 0) {
		return;
	}
	$relative_class = substr($class, $len);
	$file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
	if (file_exists($file)) {
		require_once $file;
	}
});

// i18n
add_action('plugins_loaded', function () {
	load_plugin_textdomain('staging2live', false, dirname(plugin_basename(__FILE__)) . '/languages/');
});

// Activation: ensure backup directory exists and is protected
register_activation_hook(__FILE__, function () {
	$options = get_option('staging2live_settings');
	if (!is_array($options)) {
		$options = [];
	}
	$backup_dir = isset($options['backup_dir']) && $options['backup_dir'] ? $options['backup_dir'] : STL_DEFAULT_BACKUP_DIR;
	$backup_dir = wp_normalize_path(trailingslashit($backup_dir));
	if (!is_dir($backup_dir)) {
		wp_mkdir_p($backup_dir);
	}
	// Add index.html to prevent directory listing
	$index_path = $backup_dir . 'index.html';
	if (!file_exists($index_path)) {
		file_put_contents($index_path, "");
	}
	// Add .htaccess to prevent web access on Apache
	$htaccess_path = $backup_dir . '.htaccess';
	if (!file_exists($htaccess_path)) {
		$rules = "# Staging2Live backups protection\nOptions -Indexes\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n";
		file_put_contents($htaccess_path, $rules);
	}
	// Add web.config for IIS
	$webconfig_path = $backup_dir . 'web.config';
	if (!file_exists($webconfig_path)) {
		$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <security>\n      <authorization>\n        <remove users=\"*\" roles=\"\" verbs=\"\" />\n        <add accessType=\"Deny\" users=\"*\" />\n      </authorization>\n    </security>\n  </system.webServer>\n</configuration>\n";
		file_put_contents($webconfig_path, $xml);
	}
});

// Ensure directory protection at admin_init as well (in case of manual deletion)
add_action('admin_init', function () {
	$options = get_option('staging2live_settings');
	if (!is_array($options)) {
		$options = [];
	}
	$backup_dir = isset($options['backup_dir']) && $options['backup_dir'] ? $options['backup_dir'] : STL_DEFAULT_BACKUP_DIR;
	$backup_dir = wp_normalize_path(trailingslashit($backup_dir));
	if (!is_dir($backup_dir)) {
		wp_mkdir_p($backup_dir);
	}
	$index_path = $backup_dir . 'index.html';
	if (!file_exists($index_path)) {
		file_put_contents($index_path, "");
	}
	$htaccess_path = $backup_dir . '.htaccess';
	if (!file_exists($htaccess_path)) {
		$rules = "# Staging2Live backups protection\nOptions -Indexes\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n";
		file_put_contents($htaccess_path, $rules);
	}
});

// Admin
add_action('admin_menu', function () {
	if (!current_user_can('manage_options')) {
		return;
	}
	\Staging2Live\Admin\AdminPage::register_menu();
});

add_action('admin_init', function () {
	\Staging2Live\Admin\AdminPage::register_settings();
});

add_action('admin_enqueue_scripts', function ($hook_suffix) {
	if (strpos($hook_suffix, 'staging2live') === false) {
		return;
	}
	wp_enqueue_style('stl-admin', STL_PLUGIN_URL . 'assets/admin.css', [], '0.1.0');
	wp_enqueue_script('stl-admin', STL_PLUGIN_URL . 'assets/admin.js', ['jquery'], '0.1.0', true);
});

// Plugins list quick link
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
	$settings_link = '<a href="' . esc_url(admin_url('admin.php?page=staging2live')) . '">' . esc_html__('Backups', 'staging2live') . '</a>';
	array_unshift($links, $settings_link);
	return $links;
});

// Admin actions (delete/restore)
add_action('admin_post_stl_delete_backup', function () {
	\Staging2Live\Admin\AdminPage::handle_delete_backup();
});

add_action('admin_post_stl_restore_backup', function () {
	\Staging2Live\Admin\AdminPage::handle_restore_backup();
});

add_action('admin_post_stl_create_backup', function () {
	\Staging2Live\Admin\AdminPage::handle_create_backup();
});

add_action('admin_post_stl_download_backup', function () {
	\Staging2Live\Admin\AdminPage::handle_download_backup();
});

add_action('admin_post_stl_download_log', function () {
	\Staging2Live\Admin\AdminPage::handle_download_log();
});

add_action('admin_post_stl_clear_log', function () {
	\Staging2Live\Admin\AdminPage::handle_clear_log();
});

// Optional CLI bootstrap
if (defined('WP_CLI') && WP_CLI) {
	\Staging2Live\CLI\Commands::register();
}


