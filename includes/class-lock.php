<?php
/**
 * Short-lived lock so only one request works on a background job at a time.
 *
 * A transient check-then-set can let two requests in at once. This uses a row in
 * the options table instead: INSERT IGNORE succeeds for exactly one request, and
 * an expired lock is taken over with a conditional UPDATE.
 *
 * @package SeoFixer
 */

namespace Seofx;

defined( 'ABSPATH' ) || exit;

/**
 * Atomic lock.
 */
final class Lock {

	/**
	 * Takes the lock.
	 *
	 * @param string $name Option name, e.g. "seofx_import_lock".
	 * @param int    $ttl  Seconds after which a stuck lock may be taken over.
	 * @return bool False when another request holds it.
	 */
	public static function acquire( $name, $ttl ) {
		global $wpdb;
		$now     = time();
		$expires = (string) ( $now + (int) $ttl );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A lock must hit the database directly; a cached read would defeat it.
		$taken = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $wpdb->options, $name, $expires ) );
		if ( 1 !== $taken ) {
			$current = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
			if ( '' !== $current && (int) $current > $now ) {
				return false;
			}
			// Expired (the request holding it died): only one request can swap the old value.
			$taken = (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $expires, $name, $current ) );
		}
		// phpcs:enable

		wp_cache_delete( $name, 'options' );
		return 1 === $taken;
	}

	/**
	 * Releases the lock.
	 *
	 * @param string $name Option name.
	 * @return void
	 */
	public static function release( $name ) {
		delete_option( $name );
	}
}
