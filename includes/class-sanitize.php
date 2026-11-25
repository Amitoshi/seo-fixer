<?php
/**
 * Sanitizers used by settings fields.
 *
 * Every method takes ($raw, $field) so it can be used as a field's 'sanitize' callback.
 *
 * @package SeoFixer
 */

namespace Seofx;

defined( 'ABSPATH' ) || exit;

/**
 * Input cleaning helpers.
 */
final class Sanitize {

	/**
	 * Attachment id of an image, or 0.
	 *
	 * @param mixed $raw Raw value.
	 * @return int
	 */
	public static function image_id( $raw ) {
		$id = absint( is_scalar( $raw ) ? $raw : 0 );
		return ( $id && wp_attachment_is_image( $id ) ) ? $id : 0;
	}

	/**
	 * Phone number: keeps digits, spaces, +, -, ( and ).
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function phone( $raw ) {
		$phone = is_scalar( $raw ) ? (string) $raw : '';
		$phone = preg_replace( '/[^0-9+\-() ]/', '', $phone );
		return trim( (string) preg_replace( '/\s+/', ' ', (string) $phone ) );
	}

	/**
	 * A web address: adds https:// when missing; only http(s) allowed.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function web_url( $raw ) {
		$url = trim( is_scalar( $raw ) ? (string) $raw : '' );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			// Any other scheme (javascript:, mailto:, ftp: …) is rejected, not "fixed".
			if ( preg_match( '#^[a-z][a-z0-9+.\-]*:(?!\d)#i', $url ) ) {
				return '';
			}
			$url = 'https://' . ltrim( $url, '/' );
		}

		$url  = esc_url_raw( $url, array( 'http', 'https' ) );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		// A real web address has a dotted host name.
		return false !== strpos( $host, '.' ) ? $url : '';
	}

	/**
	 * Social profile link. Also accepts "@handle" for X, Instagram and TikTok.
	 *
	 * @param mixed $raw   Raw value.
	 * @param array $field Field definition (its id names the network).
	 * @return string
	 */
	public static function profile_url( $raw, array $field = array() ) {
		$value = trim( is_scalar( $raw ) ? (string) $raw : '' );

		if ( 1 === preg_match( '/^@([A-Za-z0-9_.]{1,64})$/', $value, $match ) ) {
			$handles = array(
				'social_x'         => 'https://x.com/%s',
				'social_instagram' => 'https://www.instagram.com/%s/',
				'social_tiktok'    => 'https://www.tiktok.com/@%s',
			);
			$id      = isset( $field['id'] ) ? $field['id'] : '';
			if ( isset( $handles[ $id ] ) ) {
				$value = sprintf( $handles[ $id ], $match[1] );
			}
		}

		return self::web_url( $value );
	}

	/**
	 * Several links, one per line.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function url_list( $raw ) {
		$lines = preg_split( '/\r\n|\r|\n/', is_scalar( $raw ) ? (string) $raw : '' );
		$urls  = array_filter( array_map( array( __CLASS__, 'web_url' ), (array) $lines ) );
		return implode( "\n", array_unique( $urls ) );
	}

	/**
	 * Title/description text or template: one line, no HTML, %variables% kept.
	 *
	 * sanitize_text_field() is not used on purpose: it deletes anything that
	 * looks like a URL-encoded byte, which would turn "%date%" into "te%".
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function template( $raw ) {
		$text = wp_check_invalid_utf8( is_scalar( $raw ) ? (string) $raw : '' );
		$text = Text::squish( wp_strip_all_tags( $text, true ) );
		return mb_substr( $text, 0, 500 );
	}

	/**
	 * Redirect source or target: a path, URL or pattern on one line, no HTML.
	 *
	 * Not sanitize_text_field(): it deletes "%20"-style sequences, which would turn
	 * "/caf%C3%A9" into "/caf" and "?q=red%20shoes" into "?q=redshoes".
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function address( $raw ) {
		$text = wp_check_invalid_utf8( is_scalar( $raw ) ? (string) $raw : '' );
		$text = wp_strip_all_tags( $text, true );
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $text ) );
	}

	/**
	 * Tri-state flag stored as '1', '0' or '' (not set).
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function flag( $raw ) {
		$value = is_scalar( $raw ) ? (string) $raw : '';
		return in_array( $value, array( '0', '1' ), true ) ? $value : '';
	}

	/**
	 * robots.txt rules: plain text lines, no HTML, no control characters.
	 *
	 * Not sanitize_textarea_field(): it would delete "%2F"-style sequences that
	 * are valid in robots.txt paths.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function robots( $raw ) {
		$text  = wp_check_invalid_utf8( is_scalar( $raw ) ? (string) $raw : '' );
		$text  = wp_strip_all_tags( $text );
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		$lines = array_map(
			static function ( $line ) {
				return trim( (string) preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/', '', $line ) );
			},
			array_slice( (array) $lines, 0, 500 )
		);
		return trim( implode( "\n", $lines ) );
	}

	/**
	 * Opening hours: day => {open, close} for open days only ("HH:MM", 24-hour).
	 *
	 * @param mixed $raw Submitted array: day => {open, close, closed}.
	 * @return array<string, array{open: string, close: string}>
	 */
	public static function hours( $raw ) {
		$clean = array();
		$time  = '/^([01]\d|2[0-3]):[0-5]\d$/';

		foreach ( array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ) as $day ) {
			$row = is_array( $raw ) && isset( $raw[ $day ] ) && is_array( $raw[ $day ] ) ? $raw[ $day ] : array();
			if ( empty( $row['open_day'] ) ) {
				continue;
			}
			$open  = isset( $row['open'] ) && is_scalar( $row['open'] ) ? (string) $row['open'] : '';
			$close = isset( $row['close'] ) && is_scalar( $row['close'] ) ? (string) $row['close'] : '';
			if ( preg_match( $time, $open ) && preg_match( $time, $close ) ) {
				$clean[ $day ] = array(
					'open'  => $open,
					'close' => $close,
				);
			}
		}

		return $clean;
	}

	/**
	 * A price like "19.99" ('' when not a number).
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function price( $raw ) {
		$value = str_replace( ',', '.', trim( is_scalar( $raw ) ? (string) $raw : '' ) );
		return is_numeric( $value ) && (float) $value >= 0 ? (string) round( (float) $value, 2 ) : '';
	}

	/**
	 * Three-letter currency code, upper case ('' when invalid).
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function currency( $raw ) {
		$value = strtoupper( trim( is_scalar( $raw ) ? (string) $raw : '' ) );
		return preg_match( '/^[A-Z]{3}$/', $value ) ? $value : '';
	}

	/**
	 * Per-post structured data settings, stored as JSON.
	 *
	 * Accepts the submitted form array or a JSON string (REST). Only the
	 * chosen type's fields are kept.
	 *
	 * @param mixed $raw Raw value.
	 * @return string JSON, or '' for "none".
	 */
	public static function schema( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) || empty( $raw['type'] ) || ! is_string( $raw['type'] ) ) {
			return '';
		}

		$type = $raw['type'];
		$data = isset( $raw[ $type ] ) && is_array( $raw[ $type ] ) ? $raw[ $type ] : array();
		$text = static function ( $key, $max = 200 ) use ( $data ) {
			return isset( $data[ $key ] ) ? mb_substr( sanitize_text_field( is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : '' ), 0, $max ) : '';
		};
		$rating = static function () use ( $data ) {
			$value = isset( $data['rating'] ) ? self::price( $data['rating'] ) : '';
			$count = isset( $data['rating_count'] ) ? absint( $data['rating_count'] ) : 0;
			return ( '' !== $value && (float) $value >= 1 && (float) $value <= 5 && $count > 0 )
				? array(
					'rating'       => $value,
					'rating_count' => $count,
				)
				: array();
		};

		switch ( $type ) {
			case 'software':
				$categories = array_keys( \Seofx\Schema\Graph::software_categories() );
				$clean      = array(
					'category' => in_array( $text( 'category' ), $categories, true ) ? $text( 'category' ) : 'BusinessApplication',
					'os'       => $text( 'os', 100 ),
					'price'    => isset( $data['price'] ) ? self::price( $data['price'] ) : '',
					'currency' => isset( $data['currency'] ) ? self::currency( $data['currency'] ) : '',
				) + $rating();
				break;

			case 'product':
				$clean = array(
					'brand'        => $text( 'brand', 100 ),
					'sku'          => $text( 'sku', 64 ),
					'price'        => isset( $data['price'] ) ? self::price( $data['price'] ) : '',
					'currency'     => isset( $data['currency'] ) ? self::currency( $data['currency'] ) : '',
					'availability' => in_array( $text( 'availability' ), array( 'InStock', 'OutOfStock', 'PreOrder' ), true ) ? $text( 'availability' ) : 'InStock',
				) + $rating();
				break;

			case 'faq':
				$clean = array();
				foreach ( array_slice( array_values( $data ), 0, 30 ) as $pair ) {
					$question = is_array( $pair ) && isset( $pair['q'] ) && is_scalar( $pair['q'] ) ? mb_substr( sanitize_text_field( (string) $pair['q'] ), 0, 300 ) : '';
					$answer   = is_array( $pair ) && isset( $pair['a'] ) && is_scalar( $pair['a'] ) ? mb_substr( sanitize_textarea_field( (string) $pair['a'] ), 0, 2000 ) : '';
					if ( '' !== $question && '' !== $answer ) {
						$clean[] = array(
							'q' => $question,
							'a' => $answer,
						);
					}
				}
				if ( array() === $clean ) {
					return '';
				}
				break;

			default:
				return '';
		}

		return (string) wp_json_encode(
			array(
				'type' => $type,
				$type  => $clean,
			)
		);
	}

	/**
	 * Site verification code. Accepts the code itself or the whole
	 * <meta … content="…"> tag the search engine shows.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function verification( $raw ) {
		$value = trim( is_scalar( $raw ) ? (string) $raw : '' );
		if ( preg_match( '/content\s*=\s*["\']([^"\']*)["\']/i', $value, $match ) ) {
			$value = trim( $match[1] );
		}
		return preg_match( '/^[A-Za-z0-9_\-.=+\/]{4,100}$/', $value ) ? $value : '';
	}

	/**
	 * Google Analytics 4 measurement ID ("G-…") or Tag Manager container ID
	 * ("GTM-…"). Also finds the ID inside a pasted tracking snippet.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function analytics_id( $raw ) {
		$value = strtoupper( is_scalar( $raw ) ? (string) $raw : '' );
		return preg_match( '/(?<![A-Z0-9-])(G-[A-Z0-9]{4,20}|GTM-[A-Z0-9]{4,12})(?![A-Z0-9-])/', $value, $match ) ? $match[1] : '';
	}

	/**
	 * Two-letter country code from the known list, or ''.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function country( $raw ) {
		$code = strtoupper( is_scalar( $raw ) ? (string) $raw : '' );
		return in_array( $code, Countries::codes(), true ) ? $code : '';
	}
}
