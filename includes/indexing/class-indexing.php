<?php
/**
 * Search visibility rules: noindex/nofollow, canonical URLs, attachment redirects.
 *
 * The decision functions are static so the sitemap (and later the health
 * scanner) use exactly the same rules as the front end.
 *
 * @package SeoFixer
 */

namespace Seofx\Indexing;

use Seofx\Meta\Context;
use Seofx\Meta\Post_Meta;
use Seofx\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Indexing rules and front-end hooks.
 */
final class Indexing {

	/**
	 * Front-end hooks (only while output is enabled).
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_robots', array( $this, 'robots' ), 20 );
		add_filter( 'get_canonical_url', array( $this, 'singular_canonical' ), 20, 2 );
		add_action( 'wp_head', array( $this, 'archive_canonical' ), 2 );
		add_action( 'template_redirect', array( $this, 'redirect_attachment' ), 1 );

		// WordPress hides search results by itself; respect the setting both ways.
		if ( ! Options::get( 'noindex_search' ) ) {
			remove_filter( 'wp_robots', 'wp_robots_noindex_search' );
		}
	}

	/**
	 * Whether a content type is shown in search results (setting index_pt_{type}, default yes).
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function type_indexed( $post_type ) {
		$value = Options::get( 'index_pt_' . $post_type );
		return null === $value ? true : (bool) $value;
	}

	/**
	 * Whether a taxonomy's archive pages are shown in search results (default yes).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	public static function taxonomy_indexed( $taxonomy ) {
		$value = Options::get( 'index_tax_' . $taxonomy );
		return null === $value ? true : (bool) $value;
	}

	/**
	 * Whether a post may appear in search results.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function post_indexable( \WP_Post $post ) {
		$flag = (string) get_post_meta( $post->ID, Post_Meta::NOINDEX, true );
		if ( '' !== $flag ) {
			return '0' === $flag;
		}
		return self::type_indexed( $post->post_type );
	}

	/**
	 * Whether a term archive may appear in search results.
	 *
	 * @param \WP_Term $term Term.
	 * @return bool
	 */
	public static function term_indexable( \WP_Term $term ) {
		$flag = (string) get_term_meta( $term->term_id, Post_Meta::NOINDEX, true );
		if ( '' !== $flag ) {
			return '0' === $flag;
		}
		return self::taxonomy_indexed( $term->taxonomy );
	}

	/**
	 * Whether the page in a context should carry "noindex".
	 *
	 * @param Context $context Context.
	 * @return bool
	 */
	public static function is_noindex( Context $context ) {
		switch ( $context->type ) {
			case 'singular':
				return ! self::post_indexable( $context->object );
			case 'term':
				return ! self::term_indexable( $context->object );
			case 'author':
				return (bool) Options::get( 'noindex_author_archives' );
			case 'date':
				return (bool) Options::get( 'noindex_date_archives' );
			case 'search':
				return (bool) Options::get( 'noindex_search' );
			case 'archive':
				return ! self::type_indexed( $context->post_type );
		}
		return false;
	}

	/**
	 * Whether links on the page in a context should not be followed.
	 *
	 * @param Context $context Context.
	 * @return bool
	 */
	public static function is_nofollow( Context $context ) {
		if ( $context->object instanceof \WP_Post ) {
			return '1' === (string) get_post_meta( $context->object->ID, Post_Meta::NOFOLLOW, true );
		}
		if ( $context->object instanceof \WP_Term ) {
			return '1' === (string) get_term_meta( $context->object->term_id, Post_Meta::NOFOLLOW, true );
		}
		return false;
	}

	/**
	 * Custom canonical URL of a post or term ('' = none).
	 *
	 * @param \WP_Post|\WP_Term $item Post or term.
	 * @return string
	 */
	public static function custom_canonical( $item ) {
		if ( $item instanceof \WP_Post ) {
			return (string) get_post_meta( $item->ID, Post_Meta::CANONICAL, true );
		}
		if ( $item instanceof \WP_Term ) {
			return (string) get_term_meta( $item->term_id, Post_Meta::CANONICAL, true );
		}
		return '';
	}

	/**
	 * Adds noindex/nofollow to WordPress's robots meta tag.
	 *
	 * @param array $robots Directives.
	 * @return array
	 */
	public function robots( $robots ) {
		$context = Context::current();

		if ( self::is_noindex( $context ) ) {
			$robots['noindex'] = true;
			// Links on hidden pages still help search engines find other pages (unless WordPress
			// already says nofollow, e.g. "Discourage search engines" is on).
			if ( empty( $robots['nofollow'] ) ) {
				$robots['follow'] = true;
			}
		}
		if ( self::is_nofollow( $context ) ) {
			$robots['nofollow'] = true;
			unset( $robots['follow'] );
		}

		return $robots;
	}

	/**
	 * Uses the custom canonical URL for posts and pages.
	 *
	 * @param string   $url  Canonical URL from WordPress.
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public function singular_canonical( $url, $post ) {
		$custom = self::custom_canonical( $post );
		return '' !== $custom ? $custom : $url;
	}

	/**
	 * Prints a canonical link on list pages (WordPress only does this for single posts).
	 *
	 * @return void
	 */
	public function archive_canonical() {
		$context = Context::current();
		if ( ! in_array( $context->type, array( 'home', 'term', 'author', 'archive' ), true ) || self::is_noindex( $context ) ) {
			return;
		}

		$url = '';
		if ( 'term' === $context->type ) {
			$url = self::custom_canonical( $context->object );
			if ( '' === $url ) {
				$link = get_term_link( $context->object );
				$url  = is_wp_error( $link ) ? '' : self::paged( $link, $context->paged );
			}
		} elseif ( 'home' === $context->type ) {
			$url = self::paged( home_url( '/' ), $context->paged );
		} elseif ( 'author' === $context->type ) {
			$url = self::paged( get_author_posts_url( $context->object->ID ), $context->paged );
		} elseif ( 'archive' === $context->type ) {
			$link = get_post_type_archive_link( $context->post_type );
			$url  = $link ? self::paged( $link, $context->paged ) : '';
		}

		if ( '' !== $url ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $url ) );
		}
	}

	/**
	 * URL of page N of a list.
	 *
	 * @param string $base  First page URL.
	 * @param int    $paged Page number.
	 * @return string
	 */
	public static function paged( $base, $paged ) {
		if ( $paged < 2 ) {
			return $base;
		}
		if ( '' === (string) get_option( 'permalink_structure' ) || false !== strpos( $base, '?' ) ) {
			return add_query_arg( 'paged', $paged, $base );
		}
		return trailingslashit( $base ) . user_trailingslashit( 'page/' . $paged, 'paged' );
	}

	/**
	 * Sends visitors from an attachment page to the post it belongs to (or the file).
	 *
	 * @return void
	 */
	public function redirect_attachment() {
		if ( ! is_attachment() || ! Options::get( 'attachment_redirect' ) ) {
			return;
		}

		$attachment = get_queried_object();
		if ( ! $attachment instanceof \WP_Post ) {
			return;
		}

		$parent = $attachment->post_parent ? get_post( $attachment->post_parent ) : null;
		$target = ( $parent && 'publish' === $parent->post_status ) ? get_permalink( $parent ) : wp_get_attachment_url( $attachment->ID );

		if ( $target ) {
			// wp_redirect: with offloaded media the file lives on another domain (a CDN), which
			// wp_safe_redirect() would refuse. The address comes from WordPress, not the visitor.
			wp_redirect( $target, 301, 'SEO Fixer' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Permalink or attachment URL from WordPress itself.
			exit;
		}
	}
}
