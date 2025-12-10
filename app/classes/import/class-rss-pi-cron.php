<?php

/**
 * Handles cron jobs
 *
 * @author mobilova UG (haftungsbeschränkt) <rsspostimporter@feedsapi.com>
 */
class rssPICron {

	/**
	 * Initialise
	 */
	public function init() {

		// hook up scheduled events
		add_action('wp', array(&$this, 'schedule'));

		add_action('rss_pi_cron', array(&$this, 'do_hourly'));
	}

	/**
	 * Check and confirm scheduling
	 */
	function schedule() {
		if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event') || !function_exists('time')) {
			return;
		}

		try {
			if (!wp_next_scheduled('rss_pi_cron')) {
				wp_schedule_event(time(), 'hourly', 'rss_pi_cron');
			}
		} catch (Exception $e) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('RSS Post Importer: Error scheduling cron - ' . $e->getMessage());
			}
		}
	}

	/**
	 * Import the feeds on schedule
	 */
	function do_hourly() {
		try {
			if (class_exists('rssPIEngine')) {
				$engine = new rssPIEngine();
				if (method_exists($engine, 'import_feed')) {
					$engine->import_feed();
				}
			}
		} catch (Exception $e) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('RSS Post Importer: Error in do_hourly cron - ' . $e->getMessage());
			}
		}
	}

}
