<?php

/**
 * One class to rule them all
 * 
 * @author mobilova UG (haftungsbeschränkt) <rsspostimporter@feedsapi.com>
 */
class rssPostImporter {

	/**
	 * Instance of this class
	 * @var rssPostImporter
	 */
	private static $instance = null;

	/**
	 * A var to store the options in
	 * @var array
	 */
	public $options = array();

	/**
	 * A var to store the link to the plugin page
	 * @var string
	 */
	public $page_link = '';

	/**
	 * To initialise the admin and cron classes
	 * 
	 * @var object
	 */
	private $admin, $cron, $front;

	/**
	 * Whether the plugin is initialized
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * Get singleton instance
	 * 
	 * @return rssPostImporter
	 */
	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to prevent direct instantiation
	 */
	private function __construct() {
		// Prevent direct instantiation
	}

	/**
	 * Initialize the plugin
	 */
	public function initialize() {
		if ($this->initialized) {
			return;
		}

		try {
			// Check WordPress functions availability
			if (!function_exists('get_option') || !function_exists('admin_url') || !function_exists('add_action')) {
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('RSS Post Importer: Required WordPress functions not available');
				}
				return;
			}

			// populate the options first
			$this->load_options();

			// do any upgrade if needed
			$this->upgrade();

			// setup this plugin options page link
			if (function_exists('admin_url')) {
				$this->page_link = admin_url('options-general.php?page=rss_pi');
			}

			// hook translations
			if (function_exists('add_action')) {
				add_action('plugins_loaded', array($this, 'localize'));
				add_filter('plugin_action_links_' . RSS_PI_BASENAME, array($this, 'settings_link'));
			}

			$this->initialized = true;
		} catch (Exception $e) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('RSS Post Importer: Initialization error - ' . $e->getMessage());
			}
		}
	}

	/**
	 * Load options from the db
	 */
	public function load_options() {
		if (!function_exists('get_option') || !function_exists('wp_parse_args')) {
			$this->options = $this->get_default_options();
			return;
		}

		try {
			$default_settings = array(
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
			);

			$options = get_option('rss_pi_feeds', array());

			// prepare default options when there is no record in the database
			if (!isset($options['feeds'])) {
				$options['feeds'] = array();
			}
			if (!isset($options['settings'])) {
				$options['settings'] = array();
			}
			if (!isset($options['latest_import'])) {
				$options['latest_import'] = '';
			}
			if (!isset($options['imports'])) {
				$options['imports'] = 0;
			}
			if (!isset($options['upgraded'])) {
				$options['upgraded'] = array();
			}

			$options['settings'] = wp_parse_args($options['settings'], $default_settings);

			if (!array_key_exists('imports', $options)) {
				$options['imports'] = 0;
			}

			$this->options = $options;
		} catch (Exception $e) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('RSS Post Importer: Error loading options - ' . $e->getMessage());
			}
			$this->options = $this->get_default_options();
		}
	}

	/**
	 * Get default options
	 * 
	 * @return array
	 */
	private function get_default_options() {
		return array(
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
	}

	/**
	 * Upgrade plugin settings
	 */
	public function upgrade() {
		if (!function_exists('get_option') || !function_exists('update_option') || !function_exists('update_post_meta') || !function_exists('delete_option')) {
			return;
		}

		try {
			global $wpdb;
			$upgraded = FALSE;
			$bail = FALSE;

		// migrate to rss_pi_deleted_posts only items from rss_pi_imported_posts that are actually deleted, discard the others
		// do this in iterations so not to degrade the UX
		if ( ! isset($this->options['upgraded']['deleted_posts']) ) {
			// get meta data for "deleted" and "imported" posts
			$rss_pi_deleted_posts = get_option( 'rss_pi_deleted_posts', array() );
			$rss_pi_imported_posts = get_option( 'rss_pi_imported_posts', array() );
			$rss_pi_imported_posts_migrated = get_option( 'rss_pi_imported_posts_migrated', array() );
			// limit execution time (in seconds)
			$_limit = ( ( defined('DOING_CRON') && DOING_CRON ) ? 20 : ( ( defined('DOING_AJAX') && DOING_AJAX ) ? 10 : 3 ) );
			$_start = microtime(TRUE);
			// iterate through all imported posts' source URLs
			foreach ( $rss_pi_imported_posts as $k => $source_url ) {
				// strip any params from the URL
				$_source_url = explode('?',$source_url);
				$_source_url = $_source_url[0];
				// hash the URL for storage
				$source_md5 = md5($_source_url);
				// properly format the URL for comparison
				$source_url = esc_url($source_url);
				// skip if we already have "migrated" this item
				if ( in_array( $k, $rss_pi_imported_posts_migrated ) ) {
					continue;
				}
				// skip if we already have "deleted" metadata for this item
				if ( in_array( $source_md5, $rss_pi_deleted_posts ) ) {
					continue;
				}
				$rss_pi_imported_posts_migrated[] = $k;
				// check if there is a post with this source URL
				$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'rss_pi_source_url' and meta_value = %s", $source_url ) );
				// when there is no such post (it was deleted?)
				if ( ! $post_id ) {
					// add this source URL to "deleted" metadata
					$rss_pi_deleted_posts[] = $source_md5;
				} else {
					// otherwise update the post metadata to include hashed URL
					update_post_meta( $post_id, 'rss_pi_source_md5', $source_md5 );
				}
				// remove it from "imported" metadata
				$_curr = microtime(TRUE);
				if ( $_curr - $_start > $_limit ) {
					// bail out when the "max execution time" limit is exhausted
					$bail = TRUE;
					break;
				}
			}
			// shed any duplicates
			$rss_pi_deleted_posts = array_unique($rss_pi_deleted_posts);
			update_option('rss_pi_deleted_posts', $rss_pi_deleted_posts);
			// keep record of migrated items
			update_option('rss_pi_imported_posts_migrated', $rss_pi_imported_posts_migrated);
			// are there still source URLs in the "imported" metadata?
			if ( count($rss_pi_imported_posts_migrated) < count($rss_pi_imported_posts) ) {
			} else {
				// remove the "imported" metadata from database
				delete_option('rss_pi_imported_posts_migrated');
				delete_option('rss_pi_imported_posts');
				// mark this upgrade as completed
				$this->options['upgraded']['deleted_posts'] = TRUE;
				$upgraded = TRUE;
			}
		}
		// check after each upgrade routine
		if ( $bail ) {
			return;
		}

			// if there is something to record as an upgrade
			if ( $upgraded ) {
				update_option('rss_pi_feeds', $this->options);
			}
		} catch (Exception $e) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('RSS Post Importer: Error during upgrade - ' . $e->getMessage());
			}
		}
	}

	/**
	 * Load translations
	 */
	public function localize() {
		if (function_exists('load_plugin_textdomain')) {
			load_plugin_textdomain('rss_pi', false, RSS_PI_PATH . 'app/lang/');
		}
	}

	/**
	 * Initialise
	 */
	public function init() {
		if (!$this->initialized) {
			$this->initialize();
		}

		try {
			// initialise admin and cron
			if (class_exists('rssPICron')) {
				$this->cron = new rssPICron();
				if (method_exists($this->cron, 'init')) {
					$this->cron->init();
				}
			}

			if (class_exists('rssPIAdmin')) {
				$this->admin = new rssPIAdmin();
				if (method_exists($this->admin, 'init')) {
					$this->admin->init();
				}
			}

			if (class_exists('rssPIFront')) {
				$this->front = new rssPIFront();
				if (method_exists($this->front, 'init')) {
					$this->front->init();
				}
			}
		} catch (Exception $e) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('RSS Post Importer: Error in init() - ' . $e->getMessage());
			}
		}
	}

	/**
	 * Check if a given API key is valid
	 * Note: Plugin now works standalone without external API validation
	 * 
	 * @param string $key
	 * @return boolean
	 */
	public function is_valid_key($key) {
		// Plugin works standalone - if a key is provided, assume it's valid
		// This allows the plugin to work without external API calls
		if (empty($key)) {
			return false;
		}

		// Return true if key is not empty (standalone mode)
		// External API validation is optional and disabled by default
		return true;
	}

	/**
	 * Adds a settings link
	 * 
	 * @param array $links Existing links
	 * @return array
	 */
	public function settings_link($links) {
		if (empty($this->page_link)) {
			return $links;
		}
		
		$settings_link = array(
			'<a href="' . esc_url($this->page_link) . '">' . __('Settings', 'rss_pi') . '</a>',
		);
		return array_merge($settings_link, $links);
	}

	/**
	 * Prevent cloning
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization
	 */
	public function __wakeup() {
		throw new Exception('Cannot unserialize singleton');
	}

}
