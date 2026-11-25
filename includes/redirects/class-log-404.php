<?php
/**
 * "Page not found" log: which missing addresses visitors and search engines try.
 *
 * Privacy: no IP addresses or user agents are stored, only the address,
 * how often it was requested, when, and the last page that linked to it.
 *
 * @package SeoFixer
 */

namespace Seofx\Redirects;

use Seofx\Options;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table with no WordPress API.

/**
 * 404 log storage.
 */
final class Log_404 {

	/**
	 * Transient holding the approximate number of entries (refreshed every 5 minutes).
	 */
	const COUNT_KEY = 'seofx_404_count';

	/**
	 * Most entries the log keeps.
	 *
	 * @return int
	 */
	private static function max() {
		return max( 100, (int) Options::get( 'log_404_max' ) );
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'seofx_404';
	}

	/**
	 * Whether an address looks like an automated probe for weak spots, not a lost visitor.
	 *
	 * @param string $path Normalized path.
	 * @return bool
	 */
	public static function is_probe( $path ) {
		return (bool) preg_match( '#\.(php\d?|aspx?|jsp|cgi|env|ini|sql|bak|old|log|ya?ml|git|svn)(\?|$)|/(wp-admin|wp-includes|wp-login|xmlrpc|\.well-known|\.git|\.env|cgi-bin|phpmyadmin)(/|\?|$)#i', $path );
	}

	/**
	 * Records one "not found" request.
	 *
	 * @param string $path     Normalized path.
	 * @param string $referrer Linking page ('' if none).
	 * @return void
	 */
	public static function record( $path, $referrer ) {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$hash = md5( $path );
		$ref  = mb_substr( (string) $referrer, 0, 255 );

		if ( '' !== $ref ) {
			$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits = hits + 1, last_seen = %s, referrer = %s WHERE url_hash = %s', self::table(), $now, $ref, $hash ) );
		} else {
			$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits = hits + 1, last_seen = %s WHERE url_hash = %s', self::table(), $now, $hash ) );
		}

		if ( ! $updated ) {
			// The size limit holds at all times, not just after the daily clean-up: when the log is
			// full (e.g. a bot trying thousands of made-up addresses), the oldest entry makes room.
			$count = get_transient( self::COUNT_KEY );
			if ( false === $count ) {
				$count = self::count();
				set_transient( self::COUNT_KEY, $count, 5 * MINUTE_IN_SECONDS );
			}
			if ( (int) $count >= self::max() ) {
				$oldest = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY last_seen ASC, id ASC LIMIT 1', self::table() ) );
				$wpdb->delete( self::table(), array( 'id' => $oldest ), array( '%d' ) );
			} else {
				set_transient( self::COUNT_KEY, (int) $count + 1, 5 * MINUTE_IN_SECONDS );
			}

			// suppress_errors: two requests at once may both try to insert; the unique key keeps one.
			$suppress = $wpdb->suppress_errors( true );
			$wpdb->insert(
				self::table(),
				array(
					'url_hash'   => $hash,
					'url'        => $path,
					'hits'       => 1,
					'referrer'   => $ref,
					'first_seen' => $now,
					'last_seen'  => $now,
				),
				array( '%s', '%s', '%d', '%s', '%s', '%s' )
			);
			$wpdb->suppress_errors( $suppress );
		}
	}

	/**
	 * Page of the log, most requested first.
	 *
	 * @param int $page     Page number.
	 * @param int $per_page Rows per page.
	 * @return array{rows: array[], total: int}
	 */
	public static function query( $page, $per_page ) {
		global $wpdb;
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY hits DESC, last_seen DESC LIMIT %d OFFSET %d', self::table(), $per_page, max( 0, ( $page - 1 ) * $per_page ) ), ARRAY_A );
		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Number of logged addresses.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
	}

	/**
	 * Removes entries.
	 *
	 * @param int[] $ids Ids.
	 * @return void
	 */
	public static function delete( array $ids ) {
		global $wpdb;
		foreach ( array_filter( array_map( 'absint', $ids ) ) as $id ) {
			$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		}
		delete_transient( self::COUNT_KEY );
	}

	/**
	 * Removes the entry for an address (e.g. once it has been redirected).
	 *
	 * @param string $path Normalized path.
	 * @return void
	 */
	public static function forget( $path ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'url_hash' => md5( $path ) ), array( '%s' ) );
	}

	/**
	 * Empties the log.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table() ) );
		delete_transient( self::COUNT_KEY );
	}

	/**
	 * Daily clean-up: drops old entries and keeps the log under its size limit.
	 *
	 * @return void
	 */
	public static function purge() {
		global $wpdb;

		$days   = max( 1, (int) Options::get( 'log_404_days' ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %s', self::table(), $cutoff ) );

		// Keep only the most recent entries: find the first one past the limit, then delete it and
		// everything older in one query. The id breaks ties between entries with the same time.
		$first_extra = $wpdb->get_row( $wpdb->prepare( 'SELECT id, last_seen FROM %i ORDER BY last_seen DESC, id DESC LIMIT 1 OFFSET %d', self::table(), self::max() ), ARRAY_A );
		if ( $first_extra ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %s OR ( last_seen = %s AND id <= %d )', self::table(), $first_extra['last_seen'], $first_extra['last_seen'], (int) $first_extra['id'] ) );
		}

		delete_transient( self::COUNT_KEY );
	}
}
