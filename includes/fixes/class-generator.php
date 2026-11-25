<?php
/**
 * Writes suggested descriptions, titles and image alt text from what is
 * already on the site: the content, the title, the image's details and the
 * nearest heading. No AI and no outside service.
 *
 * @package SeoFixer
 */

namespace Seofx\Fixes;

use Seofx\Meta\Context;
use Seofx\Meta\Resolver;
use Seofx\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Suggestion generator.
 */
final class Generator {

	/**
	 * Longest alt text suggested (screen readers read it all).
	 */
	const ALT_MAX = 125;

	/**
	 * Longest SEO title before Google cuts it off.
	 */
	const TITLE_MAX = 65;

	/**
	 * Plain post title.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function post_title( \WP_Post $post ) {
		return Text::squish( wp_strip_all_tags( $post->post_title ) );
	}

	/**
	 * Adds a full stop unless the text already ends a sentence.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function sentence( $text ) {
		$text = rtrim( $text );
		return '' === $text || preg_match( '/[.!?…:]$/u', $text ) ? $text : $text . '.';
	}

	/**
	 * Suggested meta description (70–155 characters where the page allows it).
	 *
	 * The first meaningful sentences of the excerpt or content; when that is
	 * too short, the title is put in front and the site's tagline added.
	 *
	 * @param \WP_Post $post Post.
	 * @return string '' when there is nothing to work with.
	 */
	public static function description( \WP_Post $post ) {
		if ( '' !== $post->post_password ) {
			return '';
		}
		$source = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : $post->post_content;
		$text   = Text::summarize( Text::plain( $source ) );
		$title  = self::post_title( $post );

		if ( mb_strlen( $text ) >= Text::DESCRIPTION_MIN ) {
			return $text;
		}

		if ( '' === $text ) {
			$text = self::sentence( $title );
		} elseif ( '' !== $title && false === mb_stripos( $text, $title ) ) {
			$text = rtrim( $title, ' .:' ) . ': ' . self::sentence( $text );
		}

		if ( mb_strlen( $text ) < Text::DESCRIPTION_MIN ) {
			$site    = Text::squish( wp_strip_all_tags( get_bloginfo( 'name' ) ) );
			$tagline = Text::squish( wp_strip_all_tags( get_bloginfo( 'description' ) ) );
			$about   = '' !== $tagline ? $site . ' – ' . $tagline : $site;
			if ( '' !== $about && false === mb_stripos( $text, $about ) ) {
				$text = self::sentence( $text ) . ' ' . self::sentence( $about );
			}
		}

		return Text::summarize( trim( $text ) );
	}

	/**
	 * Suggested SEO title for a page whose title is too long: the page title
	 * on its own, without the site name. Stored as "%title%" so it follows
	 * future title changes.
	 *
	 * @param \WP_Post $post Post.
	 * @return string Template ('' when the page title itself is too long).
	 */
	public static function title( \WP_Post $post ) {
		$title = self::post_title( $post );
		if ( '' === $title || mb_strlen( $title ) > self::TITLE_MAX ) {
			return '';
		}
		$current = Resolver::title( Context::for_post( $post ) );
		return $current !== $title ? '%title%' : '';
	}

	/**
	 * Turns a file or attachment name into words: "sourdough-loaf_2-1024x768.jpg" → "Sourdough loaf".
	 *
	 * @param string $name Name.
	 * @return string '' when it doesn't describe anything (camera names, numbers).
	 */
	public static function words_from_name( $name ) {
		$name = trim( rawurldecode( (string) $name ) );
		// A name someone typed ("Demo Bakery logo") keeps its capitals; a file name is tidied.
		$typed = false !== strpos( $name, ' ' );
		$name  = (string) preg_replace( '/\.(jpe?g|png|gif|webp|avif|svg|bmp|tiff?|heic)$/i', '', $name );
		$name  = (string) preg_replace( '/-(\d+x\d+|scaled|rotated|e\d{10,})$/i', '', $name );
		$name  = (string) preg_replace( '/[-_.+~]+/', ' ', $name );
		$name  = (string) preg_replace( '/\b(\d+x\d+|\d+(px|p|w|h|k|kb|mb)|@\dx)\b/i', ' ', $name );
		$name  = (string) preg_replace( '/\b\d+\b/', ' ', $name );
		$name  = Text::squish( $name );

		$junk = '/^(img|image|dsc|dscn|dscf|pxl|pic|photo|screenshot|screen shot|whatsapp image|untitled|unnamed|wp|canva|design|file|copy|edited|final|mvimg|gopr)\b/i';
		if ( mb_strlen( $name ) < 3 || preg_match( $junk, $name ) || ! preg_match( '/\p{L}{3,}/u', $name ) ) {
			return '';
		}
		// A single long run of letters and digits is a code, not a name ("a7f3k9q2").
		if ( false === strpos( $name, ' ' ) && preg_match( '/\d/', $name ) ) {
			return '';
		}
		$rest = mb_substr( $name, 1 );
		return self::cap( mb_strtoupper( mb_substr( $name, 0, 1 ) ) . ( $typed ? $rest : mb_strtolower( $rest ) ), self::ALT_MAX );
	}

	/**
	 * Shortens text to a word boundary.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	private static function cap( $text, $max ) {
		$text = Text::squish( $text );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $max );
		$space = mb_strrpos( $cut, ' ' );
		return rtrim( false !== $space ? mb_substr( $cut, 0, $space ) : $cut, ' ,;:-–—' );
	}

	/**
	 * Suggested alt text for one image.
	 *
	 * In order: the alt text saved in the Media Library, the image's caption,
	 * its title, its file name, the heading above it, the page title.
	 *
	 * @param \WP_Post $post          Page the image is on.
	 * @param string   $src           Image address.
	 * @param int      $attachment_id Media Library id (0 if unknown).
	 * @param string   $heading       Nearest heading above the image ('' if none).
	 * @return array{alt: string, from: string} Suggestion and where it came from.
	 */
	public static function alt( \WP_Post $post, $src, $attachment_id, $heading ) {
		$candidates = array();
		if ( $attachment_id ) {
			$attachment = get_post( $attachment_id );
			$candidates['library'] = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( $attachment ) {
				$candidates['caption'] = Text::plain( $attachment->post_excerpt );
				$candidates['title']   = self::words_from_name( $attachment->post_title );
			}
		}
		$candidates['file']    = self::words_from_name( (string) wp_basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) );
		$candidates['heading'] = Text::squish( $heading );
		$candidates['page']    = self::post_title( $post );

		foreach ( $candidates as $from => $text ) {
			$text = trim( (string) $text );
			if ( '' !== $text ) {
				return array(
					'alt'  => self::cap( $text, self::ALT_MAX ),
					'from' => $from,
				);
			}
		}
		return array(
			'alt'  => '',
			'from' => '',
		);
	}

	/**
	 * Images in the content that need alt text, with a suggestion for each.
	 *
	 * @param \WP_Post $post Post.
	 * @return array[] Each: index (nth <img> in the content), src, attachment_id, alt, from.
	 */
	public static function images_without_alt( \WP_Post $post ) {
		$content = (string) $post->post_content;
		if ( ! preg_match_all( '/<img\s[^>]*>/i', $content, $tags, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		preg_match_all( '#<h[1-6]\b[^>]*>(.*?)</h[1-6]>#is', $content, $headings, PREG_OFFSET_CAPTURE );

		$images = array();
		foreach ( $tags[0] as $index => $match ) {
			$info = Html::img_info( $match[0] );
			if ( $info['has_alt'] || $info['decorative'] ) {
				continue;
			}

			$heading = '';
			foreach ( $headings[1] as $candidate ) {
				if ( $candidate[1] > $match[1] ) {
					break;
				}
				$heading = Text::plain( $candidate[0] );
			}

			$id = $info['attachment_id'];
			if ( ! $id && '' !== $info['src'] ) {
				$id = attachment_url_to_postid( $info['src'] );
			}

			$suggestion = self::alt( $post, $info['src'], $id, $heading );
			$images[]   = array(
				'index'         => $index,
				'src'           => $info['src'],
				'attachment_id' => $id,
				'alt'           => $suggestion['alt'],
				'from'          => $suggestion['from'],
			);
		}
		return $images;
	}
}
