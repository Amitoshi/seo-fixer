<?php
/**
 * Reads and changes <img> tags in post content, one tag at a time, with
 * WordPress's HTML Tag Processor (so attributes are always escaped properly
 * and the rest of the content is left byte-for-byte as it was).
 *
 * The scanner and the fixer share these rules, so a fixed image is never
 * reported again.
 *
 * @package SeoFixer
 */

namespace Seofx\Fixes;

defined( 'ABSPATH' ) || exit;

/**
 * Image tag helpers.
 */
final class Html {

	/**
	 * Pattern matching one <img> tag.
	 */
	const IMG = '/<img\s[^>]*>/i';

	/**
	 * Facts about one <img> tag.
	 *
	 * @param string $tag The tag's HTML.
	 * @return array{src: string, alt: string|null, has_alt: bool, decorative: bool, attachment_id: int}
	 */
	public static function img_info( $tag ) {
		$processor = new \WP_HTML_Tag_Processor( $tag );
		$info      = array(
			'src'           => '',
			'alt'           => null,
			'has_alt'       => false,
			'decorative'    => false,
			'attachment_id' => 0,
		);
		if ( ! $processor->next_tag( 'img' ) ) {
			return $info;
		}

		$src   = $processor->get_attribute( 'src' );
		$alt   = $processor->get_attribute( 'alt' );
		$role  = $processor->get_attribute( 'role' );
		$aria  = $processor->get_attribute( 'aria-hidden' );
		$class = (string) $processor->get_attribute( 'class' );

		$info['src']        = is_string( $src ) ? $src : '';
		$info['alt']        = is_string( $alt ) ? $alt : ( true === $alt ? '' : null );
		$info['has_alt']    = is_string( $alt ) && '' !== trim( $alt );
		// Decorative images (role=presentation / aria-hidden) may have empty alt text on purpose.
		$info['decorative'] = 'presentation' === $role || 'none' === $role || 'true' === $aria;
		if ( preg_match( '/\bwp-image-(\d+)\b/', $class, $match ) ) {
			$info['attachment_id'] = (int) $match[1];
		}
		return $info;
	}

	/**
	 * Sets the alt text of the nth image, if it is still the same image and still has none.
	 *
	 * @param string $content Post content.
	 * @param int    $index   Position of the image (0 = first <img> in the content).
	 * @param string $src     Expected image address.
	 * @param string $alt     Alt text.
	 * @return array{content: string, had_attr: bool, tag_before: string, tag_after: string}|null Null when the image moved or already has alt text.
	 */
	public static function set_alt( $content, $index, $src, $alt ) {
		$result = null;
		$seen   = -1;
		$new    = preg_replace_callback(
			self::IMG,
			static function ( $match ) use ( $index, $src, $alt, &$seen, &$result ) {
				++$seen;
				if ( $seen !== (int) $index ) {
					return $match[0];
				}
				$info = self::img_info( $match[0] );
				if ( $info['src'] !== $src || $info['has_alt'] || $info['decorative'] ) {
					return $match[0];
				}
				$processor = new \WP_HTML_Tag_Processor( $match[0] );
				$processor->next_tag( 'img' );
				$processor->set_attribute( 'alt', $alt );
				$result = array(
					'had_attr'   => null !== $info['alt'],
					'tag_before' => $match[0],
					'tag_after'  => $processor->get_updated_html(),
				);
				return $result['tag_after'];
			},
			(string) $content
		);
		if ( null === $result || ! is_string( $new ) ) {
			return null;
		}
		$result['content'] = $new;
		return $result;
	}

	/**
	 * Undoes set_alt() for one image: the first image with this address whose
	 * alt text is still exactly what SEO Fixer wrote gets its old state back.
	 * A tag nobody has touched since is put back byte for byte.
	 *
	 * @param string $content Post content.
	 * @param array  $detail  From the history: src, had_attr, tag_before, tag_after.
	 * @param string $alt     Alt text SEO Fixer wrote.
	 * @return array{content: string, count: int}
	 */
	public static function restore_alt( $content, array $detail, $alt ) {
		$src      = isset( $detail['src'] ) ? (string) $detail['src'] : '';
		$had_attr = ! empty( $detail['had_attr'] );
		$before   = isset( $detail['tag_before'] ) ? (string) $detail['tag_before'] : '';
		$after    = isset( $detail['tag_after'] ) ? (string) $detail['tag_after'] : '';
		$count    = 0;
		$new      = preg_replace_callback(
			self::IMG,
			static function ( $match ) use ( $src, $alt, $had_attr, $before, $after, &$count ) {
				if ( $count > 0 ) {
					return $match[0]; // One history entry restores one image.
				}
				if ( '' !== $after && $match[0] === $after ) {
					++$count;
					return $before;
				}
				$info = self::img_info( $match[0] );
				if ( $info['src'] !== $src || $info['alt'] !== $alt ) {
					return $match[0];
				}
				$processor = new \WP_HTML_Tag_Processor( $match[0] );
				$processor->next_tag( 'img' );
				if ( $had_attr ) {
					$processor->set_attribute( 'alt', '' );
				} else {
					$processor->remove_attribute( 'alt' );
				}
				++$count;
				return $processor->get_updated_html();
			},
			(string) $content
		);
		return array(
			'content' => is_string( $new ) ? $new : (string) $content,
			'count'   => $count,
		);
	}
}
