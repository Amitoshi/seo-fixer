<?php
/**
 * Redirects table: create, read, update, delete and match.
 *
 * All queries use $wpdb->prepare() with %i for the table name (WordPress 6.2+).
 *
 * @package SeoFixer
 */

namespace Seofx\Redirects;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table with no WordPress API; lookups are cached below where it matters.

/**
 * Redirect storage.
 */
final class Store {

	/**
	 * Allowed HTTP status codes.
	 */
	const STATUSES = array( 301, 302, 307, 410 );

	/**
	 * Cache group.
	 */
	const CACHE = 'seofx_redirects';

	/**
	 * Option counting enabled redirects (lets every page skip the lookup when there are none).
	 */
	const COUNT_OPTION = 'seofx_redirects_count';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'seofx_redirects';
	}

	/**
	 * Whether any enabled redirect exists.
	 *
	 * @return bool
	 */
	public static function has_any() {
		return (int) get_option( self::COUNT_OPTION, 0 ) > 0;
	}

	/**
	 * Recounts enabled redirects and clears caches. Call after every change.
	 *
	 * @return void
	 */
	public static function changed() {
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE enabled = 1', self::table() ) );
		update_option( self::COUNT_OPTION, $count, true );
		wp_cache_delete( 'regex', self::CACHE );
	}

	/**
	 * Redirect matching a request, or null.
	 *
	 * @param string $path Normalized request path (may include a query).
	 * @return array|null Row plus 'target_url' (with regex groups filled in).
	 */
	public static function match( $path ) {
		global $wpdb;

		$table = self::table();
		$tries = array( $path );
		$bare  = Paths::without_query( $path );
		if ( $bare !== $path ) {
			$tries[] = $bare;
		}

		foreach ( $tries as $try ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE source = %s AND match_type = %s AND enabled = 1 LIMIT 1', $table, $try, 'exact' ), ARRAY_A );
			if ( $row ) {
				$row['target_url'] = $row['target'];
				return $row;
			}
		}

		foreach ( self::regex_rows() as $row ) {
			$pattern = self::pattern( $row['source'] );
			if ( @preg_match( $pattern, $path, $groups ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Patterns are validated on save; this guards against edge cases at runtime.
				// Captured text comes from the visitor's address, so it is URL-encoded (slashes kept):
				// "@", "?", "#" or "&" in it can't change where the target points.
				$url = (string) preg_replace_callback(
					'/\$(\d+)/',
					static function ( $match ) use ( $groups ) {
						return isset( $groups[ (int) $match[1] ] ) ? str_replace( '%2F', '/', rawurlencode( $groups[ (int) $match[1] ] ) ) : '';
					},
					$row['target']
				);
				// The filled-in target must stay on the host the administrator typed.
				$template_host = (string) wp_parse_url( (string) preg_replace( '/\$\d+/', '', $row['target'] ), PHP_URL_HOST );
				if ( strtolower( $template_host ) !== strtolower( (string) wp_parse_url( Paths::absolute( $url ), PHP_URL_HOST ) ) && '' !== $template_host ) {
					continue;
				}
				// A target that matches its own pattern again would send the visitor round in circles.
				$next = Paths::normalize( Paths::absolute( $url ) );
				if ( '' !== $next && @preg_match( $pattern, $next ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Same pattern as above.
					continue;
				}
				$row['target_url'] = $url;
				return $row;
			}
		}

		return null;
	}

	/**
	 * Enabled regex redirects (cached).
	 *
	 * @return array[]
	 */
	private static function regex_rows() {
		$rows = wp_cache_get( 'regex', self::CACHE );
		if ( ! is_array( $rows ) ) {
			global $wpdb;
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE match_type = %s AND enabled = 1 ORDER BY id ASC', self::table(), 'regex' ), ARRAY_A );
			wp_cache_set( 'regex', $rows, self::CACHE );
		}
		return $rows;
	}

	/**
	 * Full PCRE pattern for a stored regex source (case-insensitive).
	 *
	 * @param string $source Stored pattern, e.g. ^/old-blog/(.*)$.
	 * @return string
	 */
	public static function pattern( $source ) {
		return '#' . str_replace( '#', '\#', $source ) . '#i';
	}

	/**
	 * Whether a regex source compiles.
	 *
	 * @param string $source Pattern.
	 * @return bool
	 */
	public static function valid_regex( $source ) {
		return false !== @preg_match( self::pattern( $source ), '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Testing whether a pattern compiles.
	}

	/**
	 * Records a use of a redirect.
	 *
	 * @param int $id Redirect id.
	 * @return void
	 */
	public static function hit( $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET hits = hits + 1, last_hit = %s WHERE id = %d', self::table(), current_time( 'mysql', true ), $id ) );
	}

	/**
	 * One redirect.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Id of the redirect with this exact source, or 0.
	 *
	 * @param string $source     Normalized source.
	 * @param string $match_type exact|regex.
	 * @return int
	 */
	public static function find_source( $source, $match_type = 'exact' ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE source = %s AND match_type = %s LIMIT 1', self::table(), $source, $match_type ) );
	}

	/**
	 * Adds a redirect, or updates the one with the same source.
	 *
	 * @param array $data source, target, status, match_type, origin (already validated).
	 * @param int   $id   Redirect to update (0 = find by source or add).
	 * @return int Id.
	 */
	public static function save( array $data, $id = 0 ) {
		global $wpdb;

		$row = array(
			'source'     => $data['source'],
			'match_type' => isset( $data['match_type'] ) ? $data['match_type'] : 'exact',
			'target'     => isset( $data['target'] ) ? $data['target'] : '',
			'status'     => isset( $data['status'] ) ? (int) $data['status'] : 301,
		);

		$id = $id ? (int) $id : self::find_source( $row['source'], $row['match_type'] );
		if ( $id ) {
			$wpdb->update( self::table(), $row, array( 'id' => $id ), array( '%s', '%s', '%s', '%d' ), array( '%d' ) );
		} else {
			$row['origin']  = isset( $data['origin'] ) ? $data['origin'] : 'manual';
			$row['created'] = current_time( 'mysql', true );
			$row['enabled'] = 1;
			$wpdb->insert( self::table(), $row, array( '%s', '%s', '%s', '%d', '%s', '%s', '%d' ) );
			$id = (int) $wpdb->insert_id;
		}

		self::changed();
		return $id;
	}

	/**
	 * Deletes redirects.
	 *
	 * @param int[] $ids Ids.
	 * @return void
	 */
	public static function delete( array $ids ) {
		global $wpdb;
		foreach ( array_filter( array_map( 'absint', $ids ) ) as $id ) {
			$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		}
		self::changed();
	}

	/**
	 * Turns redirects on or off.
	 *
	 * @param int[] $ids     Ids.
	 * @param bool  $enabled State.
	 * @return void
	 */
	public static function set_enabled( array $ids, $enabled ) {
		global $wpdb;
		foreach ( array_filter( array_map( 'absint', $ids ) ) as $id ) {
			$wpdb->update( self::table(), array( 'enabled' => $enabled ? 1 : 0 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
		}
		self::changed();
	}

	/**
	 * Points redirects that lead to an old address at a new one (avoids chains).
	 *
	 * @param string $old_url Old full URL.
	 * @param string $new_url New full URL.
	 * @return void
	 */
	public static function retarget( $old_url, $new_url ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET target = %s WHERE target = %s OR target = %s', self::table(), $new_url, $old_url, untrailingslashit( $old_url ) ) );
		self::changed();
	}

	/**
	 * Page of redirects for the admin list.
	 *
	 * @param string $search   Search text ('' for all).
	 * @param int    $page     Page number.
	 * @param int    $per_page Rows per page.
	 * @return array{rows: array[], total: int}
	 */
	public static function query( $search, $page, $per_page ) {
		global $wpdb;

		$table  = self::table();
		$offset = max( 0, ( $page - 1 ) * $per_page );

		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE source LIKE %s OR target LIKE %s', $table, $like, $like ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE source LIKE %s OR target LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d', $table, $like, $like, $per_page, $offset ), ARRAY_A );
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', $table, $per_page, $offset ), ARRAY_A );
		}

		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Every redirect (for CSV export).
	 *
	 * @return array[]
	 */
	public static function all() {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', self::table() ), ARRAY_A );
	}

	/**
	 * Checks a redirect typed by a person.
	 *
	 * @param string $source     Raw source.
	 * @param string $target     Raw target.
	 * @param int    $status     Status code.
	 * @param string $match_type exact|regex.
	 * @return array{data: array, error: string}
	 */
	public static function validate( $source, $target, $status, $match_type = 'exact' ) {
		$status     = in_array( (int) $status, self::STATUSES, true ) ? (int) $status : 301;
		$match_type = 'regex' === $match_type ? 'regex' : 'exact';
		$source     = trim( (string) $source );
		$target     = trim( (string) $target );
		$error      = '';

		if ( 'regex' === $match_type ) {
			$source = mb_substr( $source, 0, 255 ); // Checked as it will be stored.
			if ( '' === $source || ! self::valid_regex( $source ) ) {
				$error = __( 'The “from” pattern isn’t a valid regular expression.', 'seo-fixer' );
			}
		} else {
			$source = Paths::normalize( $source );
			if ( '' === $source ) {
				$error = __( 'The “from” address must be on this website, for example /old-page/.', 'seo-fixer' );
			} elseif ( '/' === $source ) {
				$error = __( 'Your homepage can’t be redirected here. Change the homepage in Settings → Reading instead.', 'seo-fixer' );
			}
		}

		if ( '' === $error && 410 !== $status ) {
			if ( '' === $target ) {
				$error = __( 'Enter where visitors should go.', 'seo-fixer' );
			} elseif ( preg_match( '#^https?://#i', $target ) ) {
				$target = esc_url_raw( $target, array( 'http', 'https' ) );
			} else {
				$target = '/' . ltrim( (string) preg_replace( '/[\s<>"\']/', '', $target ), '/' );
			}
			if ( '' === $error && 'exact' === $match_type && Paths::normalize( Paths::absolute( $target ) ) === $source ) {
				$error = __( 'A page can’t redirect to itself.', 'seo-fixer' );
			}
		}

		if ( 410 === $status ) {
			$target = '';
		}

		return array(
			'data'  => array(
				'source'     => mb_substr( $source, 0, 255 ),
				'target'     => $target,
				'status'     => $status,
				'match_type' => $match_type,
			),
			'error' => $error,
		);
	}
}
