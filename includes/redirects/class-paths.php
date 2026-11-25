<?php
/**
 * Turns URLs into the form redirects are stored and matched in.
 *
 * "https://example.com/Old-Page/?b=2&a=1" → "/old-page?a=1&b=2"
 * (relative to the site, lower case, no trailing slash, sorted query).
 *
 * @package SeoFixer
 */

namespace Seofx\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * Path helpers.
 */
final class Paths {

	/**
	 * Normalizes a URL or path typed by a person or taken from a request.
	 *
	 * @param string $url URL or path.
	 * @return string '' when it isn't on this site.
	 */
	public static function normalize( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( false === $parts ) {
			return '';
		}

		// A full address must point to this site.
		if ( isset( $parts['host'] ) ) {
			$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$host      = strtolower( preg_replace( '/^www\./i', '', $parts['host'] ) );
			if ( strtolower( preg_replace( '/^www\./i', '', $home_host ) ) !== $host ) {
				return '';
			}
		}

		$path = isset( $parts['path'] ) ? rawurldecode( $parts['path'] ) : '/';

		// Make it relative to a WordPress installed in a sub-folder.
		$home_path = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( '' !== $home_path && 0 === stripos( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		$path = '/' . ltrim( (string) preg_replace( '#/+#', '/', $path ), '/' );
		$path = '/' === $path ? '/' : untrailingslashit( $path );
		$path = mb_strtolower( $path );

		$query = '';
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $args );
			ksort( $args );
			$query = http_build_query( $args );
		}

		return mb_substr( $path . ( '' !== $query ? '?' . $query : '' ), 0, 255 );
	}

	/**
	 * Normalized path of the current request.
	 *
	 * @return string
	 */
	public static function current() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return self::normalize( $uri );
	}

	/**
	 * Path without its query string.
	 *
	 * @param string $path Normalized path.
	 * @return string
	 */
	public static function without_query( $path ) {
		$position = strpos( $path, '?' );
		return false === $position ? $path : substr( $path, 0, $position );
	}

	/**
	 * Address to send visitors to: full URLs stay as they are, paths get the site address.
	 *
	 * @param string $target URL or path.
	 * @return string
	 */
	public static function absolute( $target ) {
		$target = trim( (string) $target );
		if ( preg_match( '#^https?://#i', $target ) ) {
			return $target;
		}
		return home_url( '/' . ltrim( $target, '/' ) );
	}
}
