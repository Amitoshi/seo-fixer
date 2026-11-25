<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Data is removed only on sites where "Keep my settings if I delete the
 * plugin" was turned off. Posts, pages and images are never touched (alt
 * text added by a fix is part of the content and stays), and neither is
 * the data another SEO plugin keeps.
 *
 * @package SeoFixer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes SEO Fixer data from the current site if the user opted in.
 *
 * @return bool Whether data was removed.
 */
function seofx_uninstall_site() {
	// Scheduled tasks are always removed (each site has its own schedule).
	wp_clear_scheduled_hook( 'seofx_daily' );
	wp_clear_scheduled_hook( 'seofx_scan_cron' );
	wp_clear_scheduled_hook( 'seofx_indexnow_send' );

	$settings = get_option( 'seofx_settings' );
	$keep     = is_array( $settings ) && array_key_exists( 'keep_data', $settings ) ? (bool) $settings['keep_data'] : true;

	if ( $keep ) {
		return false;
	}

	global $wpdb;

	foreach ( array( 'seofx_settings', 'seofx_version', 'seofx_db_version', 'seofx_installed_at', 'seofx_sitemap_version', 'seofx_flush_rewrites', 'seofx_redirects_count', 'seofx_scan_state', 'seofx_scan_summary', 'seofx_sitemap_submitted', 'seofx_indexnow_key', 'seofx_indexnow_queue', 'seofx_indexnow_log', 'seofx_import', 'seofx_import_lock', 'seofx_scan_lock' ) as $option ) {
		delete_option( $option );
	}
	foreach ( array( 'seofx_tables_retry', 'seofx_404_count' ) as $transient ) {
		delete_transient( $transient );
	}
	// Cached sitemaps and one-time messages (transients named seofx_…). With a
	// persistent object cache they aren't in this table and expire by themselves.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removing the plugin's own cached data on uninstall.
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_seofx_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_seofx_' ) . '%'
		)
	);

	// Redirects, the "not found" log, health scan results and fix history.
	foreach ( array( $wpdb->prefix . 'seofx_redirects', $wpdb->prefix . 'seofx_404', $wpdb->prefix . 'seofx_scan', $wpdb->prefix . 'seofx_links', $wpdb->prefix . 'seofx_changes' ) as $table ) { // Same list as Installer::tables(); the plugin isn't loaded here.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own tables on uninstall.
	}

	// SEO fields written for posts and terms.
	foreach ( array( '_seofx_title', '_seofx_description', '_seofx_noindex', '_seofx_nofollow', '_seofx_canonical', '_seofx_social_image', '_seofx_social_title', '_seofx_social_description', '_seofx_schema' ) as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
		delete_metadata( 'term', 0, $meta_key, '', true );
	}

	return true;
}

$seofx_removed_any = false;

if ( is_multisite() ) {
	$seofx_site_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);
	foreach ( $seofx_site_ids as $seofx_site_id ) {
		switch_to_blog( $seofx_site_id );
		$seofx_removed_any = seofx_uninstall_site() || $seofx_removed_any;
		restore_current_blog();
	}
} else {
	$seofx_removed_any = seofx_uninstall_site();
}

// User preferences are shared across a network, so they go only when data was removed.
if ( $seofx_removed_any ) {
	foreach ( array( 'seofx_ui_mode', 'seofx_admin_theme', 'seofx_dismissed_conflict', 'seofx_setup_notice_dismissed', 'seofx_trashed_urls' ) as $seofx_meta_key ) {
		delete_metadata( 'user', 0, $seofx_meta_key, '', true );
	}
}
