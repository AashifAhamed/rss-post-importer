<?php
/**
 * Plugin Name: Rss Post Importer
 * Plugin URI: https://wordpress.org/plugins/rss-post-importer/
 * Description: This plugin lets you set up an import posts from one or several rss-feeds and save them as posts on your site, simple and flexible.
 * Author: feedsapi
 * Version: 2.1.4
 * Author URI: https://www.feedsapi.org/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: rss_pi
 * Domain Path: /lang/
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

// Define plugin constants
if (!defined('RSS_PI_VERSION')) {
	define('RSS_PI_VERSION', '2.1.4');
}

if (!defined('RSS_PI_PATH')) {
	define('RSS_PI_PATH', plugin_dir_path(__FILE__));
}

if (!defined('RSS_PI_URL')) {
	define('RSS_PI_URL', plugin_dir_url(__FILE__));
}

if (!defined('RSS_PI_BASENAME')) {
	define('RSS_PI_BASENAME', plugin_basename(__FILE__));
}

if (!defined('RSS_PI_MIN_WP_VERSION')) {
	define('RSS_PI_MIN_WP_VERSION', '5.0');
}

if (!defined('RSS_PI_MIN_PHP_VERSION')) {
	define('RSS_PI_MIN_PHP_VERSION', '7.4');
}

/**
 * Check WordPress and PHP version compatibility
 */
function rss_pi_check_compatibility() {
	global $wp_version;
	
	// Check WordPress version
	if (version_compare($wp_version, RSS_PI_MIN_WP_VERSION, '<')) {
		add_action('admin_notices', 'rss_pi_wordpress_version_notice');
		return false;
	}
	
	// Check PHP version
	if (version_compare(PHP_VERSION, RSS_PI_MIN_PHP_VERSION, '<')) {
		add_action('admin_notices', 'rss_pi_php_version_notice');
		return false;
	}
	
	return true;
}

/**
 * Display WordPress version notice
 */
function rss_pi_wordpress_version_notice() {
	?>
	<div class="error">
		<p><?php printf(
			__('RSS Post Importer requires WordPress %s or higher. Please update WordPress.', 'rss_pi'),
			RSS_PI_MIN_WP_VERSION
		); ?></p>
	</div>
	<?php
}

/**
 * Display PHP version notice
 */
function rss_pi_php_version_notice() {
	?>
	<div class="error">
		<p><?php printf(
			__('RSS Post Importer requires PHP %s or higher. You are running PHP %s.', 'rss_pi'),
			RSS_PI_MIN_PHP_VERSION,
			PHP_VERSION
		); ?></p>
	</div>
	<?php
}

/**
 * Safely include a file if it exists
 */
function rss_pi_include_file($file_path) {
	if (file_exists($file_path)) {
		include_once $file_path;
		return true;
	} else {
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('RSS Post Importer: File not found: ' . $file_path);
		}
		return false;
	}
}

/**
 * Load plugin files
 */
function rss_pi_load_files() {
	$files_loaded = true;
	
	// Helper classes
	$files_loaded &= rss_pi_include_file(RSS_PI_PATH . 'app/classes/helpers/class-rss-pi-log.php');
	$files_loaded &= rss_pi_include_file(RSS_PI_PATH . 'app/classes/helpers/class-rss-pi-featured-image.php');
	$files_loaded &= rss_pi_include_file(RSS_PI_PATH . 'app/classes/helpers/class-rss-pi-parser.php');
	$files_loaded &= rss_pi_include_file(RSS_PI_PATH . 'app/classes/helpers/rss-pi-functions.php');
	$files_loaded &= rss_pi_include_file(RSS_PI_PATH . 'app/classes/helpers/class-OPMLParser.php');
	
	// Admin classes
	$files_loaded &= rss_pi_include_file(RSS_PI_PATH . 'app/classes/admin/class-rss-pi-admin-processor.php');
	$files_loaded &= rss_pi_include_file(RSS_PI_PATH . 'app/classes/admin/class-rss-pi-admin.php');
	$files_loaded &= rss_pi_include_file(RSS_PI_PATH . 'app/classes/admin/class-rss-pi-export-to-csv.php');
	$files_loaded &= rss_pi_include_file(RSS_PI_PATH . 'app/classes/admin/class-rss-pi-stats.php');
	$files_loaded &= rss_pi_include_file(RSS_PI_PATH . 'app/classes/admin/class-rss-pi-opml.php');
	
	// Front classes
	$files_loaded &= rss_pi_include_file(RSS_PI_PATH . 'app/classes/front/class-rss-pi-front.php');
	
	// Import classes
	$files_loaded &= rss_pi_include_file(RSS_PI_PATH . 'app/classes/import/class-rss-pi-engine.php');
	$files_loaded &= rss_pi_include_file(RSS_PI_PATH . 'app/classes/import/class-rss-pi-cron.php');
	
	// Main class
	$files_loaded &= rss_pi_include_file(RSS_PI_PATH . 'app/class-rss-post-importer.php');
	
	return $files_loaded;
}

/**
 * Initialize the plugin
 */
function rss_pi_init_plugin() {
	// Check compatibility first
	if (!rss_pi_check_compatibility()) {
		return;
	}
	
	// Load all required files
	if (!rss_pi_load_files()) {
		add_action('admin_notices', function() {
			?>
			<div class="error">
				<p><?php _e('RSS Post Importer: Some required files are missing. Please reinstall the plugin.', 'rss_pi'); ?></p>
			</div>
			<?php
		});
		return;
	}
	
	// Check if main class exists
	if (!class_exists('rssPostImporter')) {
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('RSS Post Importer: Main class not found');
		}
		return;
	}
	
	// Initialize log directory
	if (defined('WP_CONTENT_DIR')) {
		$log_path = trailingslashit(WP_CONTENT_DIR) . 'rsspi-log/';
		if (!is_dir($log_path)) {
			@mkdir($log_path, 0755, true);
			if (!is_dir($log_path) && defined('WP_DEBUG') && WP_DEBUG) {
				error_log('RSS Post Importer: Could not create log directory: ' . $log_path);
			}
		}
	}
	
	// Initialize plugin
	try {
		global $rss_post_importer;
		
		if (!isset($rss_post_importer)) {
			$rss_post_importer = rssPostImporter::get_instance();
			$rss_post_importer->initialize();
		}
		
		if (method_exists($rss_post_importer, 'init')) {
			$rss_post_importer->init();
		}
	} catch (Exception $e) {
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('RSS Post Importer: Initialization error - ' . $e->getMessage());
		}
		add_action('admin_notices', function() use ($e) {
			?>
			<div class="error">
				<p><?php printf(
					__('RSS Post Importer: Error during initialization. %s', 'rss_pi'),
					esc_html($e->getMessage())
				); ?></p>
			</div>
			<?php
		});
	}
}

/**
 * Plugin activation handler
 */
function rss_pi_activate() {
	// Check compatibility
	if (!rss_pi_check_compatibility()) {
		deactivate_plugins(plugin_basename(__FILE__));
		wp_die(
			sprintf(
				__('RSS Post Importer requires WordPress %s and PHP %s. Please update your environment.', 'rss_pi'),
				RSS_PI_MIN_WP_VERSION,
				RSS_PI_MIN_PHP_VERSION
			)
		);
	}
	
	// Load files for activation
	if (rss_pi_load_files() && class_exists('rssPostImporter')) {
		// Create log directory
		if (defined('WP_CONTENT_DIR')) {
			$log_path = trailingslashit(WP_CONTENT_DIR) . 'rsspi-log/';
			if (!is_dir($log_path)) {
				@mkdir($log_path, 0755, true);
			}
		}
		
		// Set default options if needed
		$options = get_option('rss_pi_feeds', array());
		if (empty($options)) {
			$default_options = array(
				'feeds' => array(),
				'settings' => array(
					'enable_logging' => false,
					'feeds_api_key' => false,
					'frequency' => 0,
					'post_template' => "{\$content}\nSource: {\$feed_title}",
					'post_status' => 'publish',
					'author_id' => 1,
					'allow_comments' => 'open',
					'block_indexing' => false,
					'nofollow_outbound' => true,
					'keywords' => array(),
					'import_images_locally' => false,
					'disable_thumbnail' => false,
					'cache_deleted' => true,
				),
				'latest_import' => '',
				'imports' => 0,
				'upgraded' => array(),
			);
			update_option('rss_pi_feeds', $default_options);
		}
	}
}

/**
 * Plugin deactivation handler
 */
function rss_pi_deactivate() {
	// Clear any scheduled cron jobs
	$timestamp = wp_next_scheduled('rss_pi_cron');
	if ($timestamp) {
		wp_unschedule_event($timestamp, 'rss_pi_cron');
	}
}

// Register activation and deactivation hooks
register_activation_hook(__FILE__, 'rss_pi_activate');
register_deactivation_hook(__FILE__, 'rss_pi_deactivate');

// Initialize plugin after WordPress is fully loaded
add_action('plugins_loaded', 'rss_pi_init_plugin', 10);
