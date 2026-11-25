<?php
/**
 * Plain-text helpers for titles and descriptions.
 *
 * @package SeoFixer
 */

namespace Seofx;

defined( 'ABSPATH' ) || exit;

/**
 * Turns post content into clean, readable text.
 */
final class Text {

	/**
	 * Recommended maximum length of a meta description.
	 */
	const DESCRIPTION_MAX = 155;

	/**
	 * Converts HTML/blocks/shortcodes into a single line of plain text.
	 *
	 * Headings, captions, scripts and styles are dropped: they rarely make a
	 * good description.
	 *
	 * @param string $html Content.
	 * @return string
	 */
	public static function plain( $html ) {
		$html = (string) $html;
		if ( function_exists( 'excerpt_remove_blocks' ) && false !== strpos( $html, '<!-- wp:' ) ) {
			$html = excerpt_remove_blocks( $html );
		}
		$html = strip_shortcodes( $html );
		$html = (string) preg_replace( '#<(h[1-6]|figcaption|script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html );
		// Keep words from neighbouring blocks apart.
		$html = (string) preg_replace( '#</(p|div|li|blockquote|td|th|section|article)>|<br\s*/?>#i', '$0 ', $html );
		$text = html_entity_decode( wp_strip_all_tags( $html, true ), ENT_QUOTES, 'UTF-8' );

		return self::squish( $text );
	}

	/**
	 * Collapses all whitespace to single spaces.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function squish( $text ) {
		// On invalid UTF-8 the /u pattern fails (null); fall back to ASCII whitespace instead of losing the text.
		$squished = preg_replace( '/\s+/u', ' ', (string) $text );
		return trim( null === $squished ? (string) preg_replace( '/\s+/', ' ', (string) $text ) : $squished );
	}

	/**
	 * Shortest description worth showing (shorter ones are flagged by the health scan).
	 */
	const DESCRIPTION_MIN = 70;

	/**
	 * Shortens text to its first meaningful sentences.
	 *
	 * Greetings and filler ("Welcome!", "Click here.") are skipped. Whole
	 * sentences are used when they say enough (70+ characters); otherwise the
	 * text is cut at a comma or dash where possible, else at a word, with "…".
	 *
	 * @param string $text Plain text.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	public static function summarize( $text, $max = self::DESCRIPTION_MAX ) {
		$text = self::squish( $text );
		if ( '' === $text ) {
			return '';
		}

		$sentences = array_values( array_filter( (array) preg_split( '/(?<=[.!?…])\s+/u', $text ), array( __CLASS__, 'is_meaningful' ) ) );
		if ( array() === $sentences ) {
			$sentences = array( $text ); // Only filler: better than nothing.
		}
		$text = implode( ' ', $sentences );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}

		$summary = '';
		foreach ( $sentences as $sentence ) {
			$candidate = '' === $summary ? $sentence : $summary . ' ' . $sentence;
			if ( mb_strlen( $candidate ) > $max ) {
				break;
			}
			$summary = $candidate;
		}

		// Whole sentences are best, as long as they say enough.
		$min = min( self::DESCRIPTION_MIN, (int) ( $max * 0.5 ) );
		if ( mb_strlen( $summary ) >= $min ) {
			return $summary;
		}

		$cut = mb_substr( $text, 0, $max - 1 );
		// Prefer a natural pause (comma, semicolon, colon, dash) near the end, then a space.
		$pause = (int) ( $max * 0.7 );
		if ( preg_match( '/^(.{' . $pause . ',})[,;:]\s|^(.{' . $pause . ',})\s[–—-]\s/u', $cut, $match ) ) {
			$cut = '' !== $match[1] ? $match[1] : $match[2];
		} else {
			$space = mb_strrpos( $cut, ' ' );
			if ( false !== $space && $space > $max * 0.6 ) {
				$cut = mb_substr( $cut, 0, $space );
			}
		}
		return rtrim( $cut, " ,;:-–—" ) . '…';
	}

	/**
	 * Whether a sentence says something (not a greeting, a link prompt or a fragment).
	 *
	 * @param string $sentence Sentence.
	 * @return bool
	 */
	public static function is_meaningful( $sentence ) {
		$sentence = trim( (string) $sentence );
		if ( mb_strlen( $sentence ) < 20 || ! preg_match( '/\p{L}{2,}/u', $sentence ) ) {
			return false; // "Hi!", "Welcome.", "1.", "→".
		}
		if ( preg_match( '#^(https?://\S+|www\.\S+)$#i', $sentence ) ) {
			return false;
		}
		/**
		 * Filters the start of sentences that are never used in a description.
		 *
		 * @param string[] $phrases Lower-case phrases.
		 */
		$filler = (array) apply_filters( 'seofx_filler_phrases', array( 'click here', 'read more', 'continue reading', 'table of contents', 'skip to content', 'share this', 'welcome to our website', 'welcome to my blog' ) );
		$lower  = mb_strtolower( $sentence );
		foreach ( $filler as $phrase ) {
			if ( 0 === strpos( $lower, (string) $phrase ) ) {
				return false;
			}
		}
		return true;
	}
}
