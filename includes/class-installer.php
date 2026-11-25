<?php
/**
 * Activation, deactivation and upgrades.
 *
 * @package SeoFixer
 */

namespace Seofx;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the stored data in step with the code version.
 */
final class Installer {

	/**
	 * Database schema version. Bump when a table is added or changed.
	 *
	 * 2: redirects and 404 log tables (M6).
	 * 3: health scan results and internal links tables (M7a).
	 * 4: change history for one-click fixes and undo (M8).
	 */
	const DB_VERSION = 4;

	/**
	 * Daily housekeeping job (404 log clean-up).
	 */
	const DAILY_HOOK = 'seofx_daily';

	/**
	 * Activation hook.
	 *
	 * Network activation only installs the current site; every other site is
	 * set up lazily by maybe_upgrade() the first time it loads, which keeps
	 * activation fast on large networks.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install();
	}

	/**
	 * Deactivation hook: stops scheduled jobs. Data is kept.
	 *
	 * @return void
	 */
	public static function deactivate() {
		foreach ( self::cron_hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		// WordPress rebuilds its rewrite rules (without our sitemap URLs) on the next request.
		delete_option( 'rewrite_rules' );
	}

	/**
	 * Runs install steps when the stored version differs from the code.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( SEOFX_VERSION !== get_option( 'seofx_version' ) ) {
			self::install();
		} elseif ( self::DB_VERSION !== (int) get_option( 'seofx_db_version' ) && ! get_transient( 'seofx_tables_retry' ) ) {
			// Tables are still missing (e.g. no CREATE permission): retry at most hourly, not on every request.
			self::install();
		}
	}

	/**
	 * Idempotent install for the current site.
	 *
	 * @return void
	 */
	public static function install() {
		Options::install();

		if ( false === get_option( 'seofx_installed_at' ) ) {
			add_option( 'seofx_installed_at', time(), '', false );
		}

		Indexing\Sitemaps::schedule_flush();

		// Stored even when 0, so pages don't query the database for a missing option.
		if ( false === get_option( Redirects\Store::COUNT_OPTION ) ) {
			add_option( Redirects\Store::COUNT_OPTION, 0, '', true );
		}

		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}

		update_option( 'seofx_version', SEOFX_VERSION, true );

		// The schema version is only recorded once every table exists, so a failed
		// creation is retried (at most hourly) instead of being forgotten.
		if ( self::DB_VERSION !== (int) get_option( 'seofx_db_version' ) && get_transient( 'seofx_tables_retry' ) ) {
			return;
		}
		self::create_tables();
		if ( self::tables_exist() ) {
			update_option( 'seofx_db_version', self::DB_VERSION, true ); // Autoloaded: maybe_upgrade() reads it on every request.
			delete_transient( 'seofx_tables_retry' );
		} else {
			set_transient( 'seofx_tables_retry', 1, HOUR_IN_SECONDS );
		}
	}

	/**
	 * The plugin's tables.
	 *
	 * @return string[]
	 */
	public static function tables() {
		global $wpdb;
		return array(
			$wpdb->prefix . 'seofx_redirects',
			$wpdb->prefix . 'seofx_404',
			$wpdb->prefix . 'seofx_scan',
			$wpdb->prefix . 'seofx_links',
			$wpdb->prefix . 'seofx_changes',
		);
	}

	/**
	 * Whether every table exists.
	 *
	 * @return bool
	 */
	private static function tables_exist() {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			// Case-insensitive: with lower_case_table_names, MySQL reports "wpx7_…" for a "wpX7_" prefix.
			if ( 0 !== strcasecmp( $table, (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking the plugin's own tables during install.
				return false;
			}
		}
		return true;
	}

	/**
	 * Creates or updates the plugin's tables (dbDelta is safe to run repeatedly).
	 *
	 * @return void
	 */
	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}seofx_redirects (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(255) NOT NULL,
			match_type varchar(10) NOT NULL DEFAULT 'exact',
			target text NOT NULL,
			status smallint(3) unsigned NOT NULL DEFAULT 301,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			last_hit datetime DEFAULT NULL,
			origin varchar(20) NOT NULL DEFAULT 'manual',
			enabled tinyint(1) NOT NULL DEFAULT 1,
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY source (source(191)),
			KEY match_type (match_type)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}seofx_404 (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_hash char(32) NOT NULL,
			url varchar(255) NOT NULL,
			hits bigint(20) unsigned NOT NULL DEFAULT 1,
			referrer varchar(255) NOT NULL DEFAULT '',
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY last_seen (last_seen)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}seofx_scan (
			post_id bigint(20) unsigned NOT NULL,
			post_type varchar(20) NOT NULL,
			score tinyint(3) unsigned NOT NULL DEFAULT 100,
			issue_ids varchar(500) NOT NULL DEFAULT '',
			issues longtext NOT NULL,
			facts longtext NOT NULL,
			title_hash char(32) NOT NULL DEFAULT '',
			desc_hash char(32) NOT NULL DEFAULT '',
			scanned_at datetime NOT NULL,
			PRIMARY KEY  (post_id),
			KEY title_hash (title_hash),
			KEY desc_hash (desc_hash),
			KEY score (score)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}seofx_links (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_id bigint(20) unsigned NOT NULL,
			target_id bigint(20) unsigned NOT NULL DEFAULT 0,
			url varchar(255) NOT NULL,
			broken tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY source_id (source_id),
			KEY target_id (target_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}seofx_changes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			batch varchar(32) NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			field varchar(20) NOT NULL,
			old_value longtext NOT NULL,
			new_value longtext NOT NULL,
			detail text NOT NULL,
			source varchar(10) NOT NULL DEFAULT 'manual',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created datetime NOT NULL,
			undone tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY batch (batch),
			KEY post_id (post_id),
			KEY created (created)
			) {$charset};"
		);
	}

	/**
	 * Cron hooks owned by the plugin.
	 *
	 * @return string[]
	 */
	public static function cron_hooks() {
		return array( self::DAILY_HOOK, 'seofx_scan_cron', Indexing\IndexNow::CRON_HOOK );
	}
}
