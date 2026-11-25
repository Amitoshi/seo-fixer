<?php
/**
 * What kind of page is being described (front end or editor preview).
 *
 * @package SeoFixer
 */

namespace Seofx\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Page context: type + the object it is about.
 */
final class Context {

	/**
	 * One of: home, singular, term, author, date, search, 404, archive, other.
	 *
	 * @var string
	 */
	public $type = 'other';

	/**
	 * WP_Post, WP_Term, WP_User or null.
	 *
	 * @var object|null
	 */
	public $object = null;

	/**
	 * Post type of an archive page.
	 *
	 * @var string
	 */
	public $post_type = '';

	/**
	 * Current page number (1 = first page).
	 *
	 * @var int
	 */
	public $paged = 1;

	/**
	 * Total number of pages.
	 *
	 * @var int
	 */
	public $max_pages = 1;

	/**
	 * Results worked out for this page (title, description, image), so the title tag,
	 * social tags and schema don't each repeat the work. Only used for current().
	 *
	 * @var array<string, mixed>
	 */
	public $memo = array();

	/**
	 * Request cache for current().
	 *
	 * @var Context|null
	 */
	private static $current = null;

	/**
	 * Whether this is the front-end page being shown (whose data can't change during the request).
	 *
	 * @return bool
	 */
	public function is_current() {
		return null !== self::$current && $this === self::$current;
	}

	/**
	 * Context of the current front-end request.
	 *
	 * @return Context
	 */
	public static function current() {
		if ( null !== self::$current ) {
			return self::$current;
		}

		global $wp_query;

		$context            = new self();
		$context->paged     = max( 1, (int) get_query_var( 'paged' ), is_front_page() ? (int) get_query_var( 'page' ) : 0 );
		$context->max_pages = isset( $wp_query->max_num_pages ) ? max( 1, (int) $wp_query->max_num_pages ) : 1;

		if ( is_front_page() && is_home() ) {
			$context->type = 'home';
		} elseif ( is_singular() || is_home() ) {
			// A static front page and the posts page are both described as pages.
			$object = get_queried_object();
			if ( $object instanceof \WP_Post ) {
				$context->type   = 'singular';
				$context->object = $object;
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$object = get_queried_object();
			if ( $object instanceof \WP_Term ) {
				$context->type   = 'term';
				$context->object = $object;
			}
		} elseif ( is_author() ) {
			$object = get_queried_object();
			if ( $object instanceof \WP_User ) {
				$context->type   = 'author';
				$context->object = $object;
			}
		} elseif ( is_post_type_archive() ) {
			$context->type      = 'archive';
			$post_type          = get_query_var( 'post_type' );
			$context->post_type = is_array( $post_type ) ? (string) reset( $post_type ) : (string) $post_type;
		} elseif ( is_date() ) {
			$context->type = 'date';
		} elseif ( is_search() ) {
			$context->type = 'search';
		} elseif ( is_404() ) {
			$context->type = '404';
		}

		self::$current = $context;
		return $context;
	}

	/**
	 * Context describing a post (used by the editor preview).
	 *
	 * @param \WP_Post $post Post.
	 * @return Context
	 */
	public static function for_post( \WP_Post $post ) {
		$context         = new self();
		$context->type   = 'singular';
		$context->object = $post;
		return $context;
	}

	/**
	 * Context describing a term (used by the term edit screen).
	 *
	 * @param \WP_Term $term Term.
	 * @return Context
	 */
	public static function for_term( \WP_Term $term ) {
		$context         = new self();
		$context->type   = 'term';
		$context->object = $term;
		return $context;
	}

	/**
	 * Context describing the "latest posts" homepage.
	 *
	 * @return Context
	 */
	public static function for_home() {
		$context       = new self();
		$context->type = 'home';
		return $context;
	}

	/**
	 * Forgets the cached front-end context (tests and previews).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$current = null;
	}
}
