<?php
/**
 * Registers SEO fields on posts and terms.
 *
 * @package SeoFixer
 */

namespace Seofx\Meta;

use Seofx\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Post and term meta registration.
 */
final class Post_Meta {

	/**
	 * Custom SEO title (post and term meta).
	 */
	const TITLE = '_seofx_title';

	/**
	 * Custom meta description (post and term meta).
	 */
	const DESCRIPTION = '_seofx_description';

	/**
	 * Search visibility: '1' = hidden (noindex), '0' = shown even if its type is hidden, absent = follow the type.
	 */
	const NOINDEX = '_seofx_noindex';

	/**
	 * '1' = ask search engines not to follow links on this page.
	 */
	const NOFOLLOW = '_seofx_nofollow';

	/**
	 * Custom canonical URL.
	 */
	const CANONICAL = '_seofx_canonical';

	/**
	 * Image for social sharing (attachment id).
	 */
	const SOCIAL_IMAGE = '_seofx_social_image';

	/**
	 * Title for social sharing.
	 */
	const SOCIAL_TITLE = '_seofx_social_title';

	/**
	 * Description for social sharing.
	 */
	const SOCIAL_DESCRIPTION = '_seofx_social_description';

	/**
	 * Extra structured data (JSON: software app, product or FAQ).
	 */
	const SCHEMA = '_seofx_schema';

	/**
	 * Every key with its sanitizer.
	 *
	 * @return array<string, callable>
	 */
	public static function keys() {
		return array(
			self::TITLE              => array( Sanitize::class, 'template' ),
			self::DESCRIPTION        => array( Sanitize::class, 'template' ),
			self::NOINDEX            => array( Sanitize::class, 'flag' ),
			self::NOFOLLOW           => array( Sanitize::class, 'flag' ),
			self::CANONICAL          => array( Sanitize::class, 'web_url' ),
			self::SOCIAL_IMAGE       => array( __CLASS__, 'sanitize_image' ),
			self::SOCIAL_TITLE       => array( Sanitize::class, 'template' ),
			self::SOCIAL_DESCRIPTION => array( Sanitize::class, 'template' ),
			self::SCHEMA             => array( Sanitize::class, 'schema' ),
		);
	}

	/**
	 * Image id as a string ('' when not an image).
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function sanitize_image( $raw ) {
		$id = Sanitize::image_id( $raw );
		return $id ? (string) $id : '';
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_meta' ), 20 );
	}

	/**
	 * Registers the meta keys (REST-enabled for the block editor sidebar in M9).
	 *
	 * @return void
	 */
	public function register_meta() {
		foreach ( array_keys( Templates::post_types() ) as $post_type ) {
			// The REST API only accepts "meta" for types that support custom fields, so without
			// this the editor sidebar's title and description would be dropped on save.
			$object = get_post_type_object( $post_type );
			if ( $object && $object->show_in_rest && ! post_type_supports( $post_type, 'custom-fields' ) ) {
				add_post_type_support( $post_type, 'custom-fields' );
			}
			foreach ( self::keys() as $key => $sanitize ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'type'              => 'string',
						'single'            => true,
						'default'           => '',
						'show_in_rest'      => true,
						'sanitize_callback' => $sanitize,
						'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
							return current_user_can( 'edit_post', $post_id );
						},
					)
				);
			}
		}

		foreach ( array_keys( Templates::taxonomies() ) as $taxonomy ) {
			foreach ( self::keys() as $key => $sanitize ) {
				register_term_meta(
					$taxonomy,
					$key,
					array(
						'type'              => 'string',
						'single'            => true,
						'sanitize_callback' => $sanitize,
						'auth_callback'     => static function ( $allowed, $meta_key, $term_id ) {
							return current_user_can( 'edit_term', $term_id );
						},
					)
				);
			}
		}
	}
}
