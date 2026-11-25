<?php
/**
 * Open Graph and X (Twitter) card tags, used when a page is shared on
 * Facebook, LinkedIn, WhatsApp, X and most chat apps.
 *
 * @package SeoFixer
 */

namespace Seofx\Frontend;

use Seofx\Meta\Context;
use Seofx\Meta\Images;
use Seofx\Meta\Post_Meta;
use Seofx\Meta\Resolver;
use Seofx\Options;
use Seofx\Schema\Graph;

defined( 'ABSPATH' ) || exit;

/**
 * Social sharing tags.
 */
final class Social {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_head', array( $this, 'print_tags' ), 5 );
		// Jetpack would print a second set of Open Graph tags.
		add_filter( 'jetpack_enable_open_graph', '__return_false' );
	}

	/**
	 * Tags for a context, as property => content pairs (in order).
	 *
	 * @param Context $context Context.
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function tags( Context $context ) {
		if ( in_array( $context->type, array( 'other', '404' ), true ) ) {
			return array();
		}

		$post        = $context->object instanceof \WP_Post ? $context->object : null;
		$title       = $post ? trim( (string) get_post_meta( $post->ID, Post_Meta::SOCIAL_TITLE, true ) ) : '';
		$description = $post ? trim( (string) get_post_meta( $post->ID, Post_Meta::SOCIAL_DESCRIPTION, true ) ) : '';
		$title       = '' !== $title ? \Seofx\Meta\Variables::replace( $title, $context ) : Resolver::title( $context );
		$description = '' !== $description ? \Seofx\Meta\Variables::replace( $description, $context ) : Resolver::description( $context );
		$is_article  = $post && 'post' === $post->post_type && ! ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $post->ID );
		$image       = Images::for_context( $context );

		$tags = array(
			array( 'og:locale', get_locale() ),
			array( 'og:type', $is_article ? 'article' : ( 'author' === $context->type ? 'profile' : 'website' ) ),
			array( 'og:title', $title ),
		);
		if ( '' !== $description ) {
			$tags[] = array( 'og:description', $description );
		}
		$tags[] = array( 'og:url', Graph::page_url( $context ) );
		$tags[] = array( 'og:site_name', wp_strip_all_tags( get_bloginfo( 'name' ) ) );

		if ( $is_article ) {
			$tags[]     = array( 'article:published_time', (string) get_post_time( 'c', true, $post ) );
			$tags[]     = array( 'article:modified_time', (string) get_post_modified_time( 'c', true, $post ) );
			$categories = get_the_category( $post->ID );
			if ( $categories ) {
				$tags[] = array( 'article:section', $categories[0]->name );
			}
		}

		if ( $image ) {
			$tags[] = array( 'og:image', $image['url'] );
			if ( $image['width'] && $image['height'] ) {
				$tags[] = array( 'og:image:width', (string) $image['width'] );
				$tags[] = array( 'og:image:height', (string) $image['height'] );
			}
			if ( '' !== $image['alt'] ) {
				$tags[] = array( 'og:image:alt', $image['alt'] );
			}
		}

		$tags[] = array( 'twitter:card', $image ? 'summary_large_image' : 'summary' );
		$handle = self::x_handle();
		if ( '' !== $handle ) {
			$tags[] = array( 'twitter:site', $handle );
		}

		/**
		 * Filters the social sharing tags.
		 *
		 * @param array   $tags    List of [property, content] pairs.
		 * @param Context $context Page context.
		 */
		return (array) apply_filters( 'seofx_social_tags', $tags, $context );
	}

	/**
	 * "@handle" from the X profile link in Your brand.
	 *
	 * @return string
	 */
	public static function x_handle() {
		$path = (string) wp_parse_url( (string) Options::get( 'social_x' ), PHP_URL_PATH );
		$name = trim( $path, '/' );
		return preg_match( '/^[A-Za-z0-9_]{1,15}$/', $name ) ? '@' . $name : '';
	}

	/**
	 * Prints the tags.
	 *
	 * @return void
	 */
	public function print_tags() {
		foreach ( self::tags( Context::current() ) as $tag ) {
			// Open Graph uses "property"; X cards use "name".
			$attribute = 0 === strpos( $tag[0], 'twitter:' ) ? 'name' : 'property';
			$content   = in_array( $tag[0], array( 'og:image', 'og:url' ), true ) ? esc_url( $tag[1] ) : esc_attr( $tag[1] );
			printf( '<meta %1$s="%2$s" content="%3$s" />' . "\n", esc_attr( $attribute ), esc_attr( $tag[0] ), $content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $content is escaped one line above (esc_url or esc_attr).
		}
	}
}
