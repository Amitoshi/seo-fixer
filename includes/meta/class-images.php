<?php
/**
 * Picks the image that represents a page (social cards and schema use the same one,
 * as Google recommends since March 2026).
 *
 * @package SeoFixer
 */

namespace Seofx\Meta;

use Seofx\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Representative images.
 */
final class Images {

	/**
	 * Image for a context: the page's own image, else the site's default.
	 *
	 * @param Context $context Context.
	 * @return array|null { id, url, width, height, alt }
	 */
	public static function for_context( Context $context ) {
		if ( $context->is_current() && array_key_exists( 'image', $context->memo ) ) {
			return $context->memo['image'];
		}
		$image = null;
		if ( $context->object instanceof \WP_Post ) {
			$image = self::for_post( $context->object );
		}
		$image = $image ? $image : self::site_default();
		if ( $context->is_current() ) {
			$context->memo['image'] = $image;
		}
		return $image;
	}

	/**
	 * Social image chosen for the post → featured image → first image in the content.
	 *
	 * @param \WP_Post $post Post.
	 * @return array|null
	 */
	public static function for_post( \WP_Post $post ) {
		$chosen = (int) get_post_meta( $post->ID, Post_Meta::SOCIAL_IMAGE, true );
		if ( $chosen ) {
			$image = self::from_attachment( $chosen );
			if ( $image ) {
				return $image;
			}
		}

		$featured = (int) get_post_thumbnail_id( $post );
		if ( $featured ) {
			$image = self::from_attachment( $featured );
			if ( $image ) {
				return $image;
			}
		}

		if ( ! post_password_required( $post ) && preg_match( '/<img\s[^>]*?src=["\']([^"\']+)["\']/i', $post->post_content, $match ) ) {
			$url = html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' );
			if ( preg_match( '#^https?://#i', $url ) ) {
				$id = attachment_url_to_postid( $url );
				return $id ? self::from_attachment( $id ) : array(
					'id'     => 0,
					'url'    => $url,
					'width'  => 0,
					'height' => 0,
					'alt'    => '',
				);
			}
		}

		return null;
	}

	/**
	 * Default social image (setting) → logo.
	 *
	 * @return array|null
	 */
	public static function site_default() {
		foreach ( array( 'social_image', 'entity_logo' ) as $key ) {
			$id = (int) Options::get( $key );
			if ( $id ) {
				$image = self::from_attachment( $id );
				if ( $image ) {
					return $image;
				}
			}
		}
		return null;
	}

	/**
	 * Image data for an attachment (full size).
	 *
	 * @param int $id Attachment id.
	 * @return array|null
	 */
	public static function from_attachment( $id ) {
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! $src ) {
			return null;
		}
		return array(
			'id'     => (int) $id,
			'url'    => $src[0],
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
			'alt'    => trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
		);
	}
}
