<?php
/**
 * Breadcrumb trail: used for BreadcrumbList schema, the [seofx_breadcrumbs]
 * shortcode and the "Breadcrumbs" block.
 *
 * Front-end markup is plain HTML with no CSS or JS, so themes style it.
 *
 * @package SeoFixer
 */

namespace Seofx\Schema;

use Seofx\Indexing\Indexing;
use Seofx\Meta\Context;
use Seofx\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Breadcrumbs.
 */
final class Breadcrumbs {

	/**
	 * Hooks (always on: the shortcode must not print raw text while SEO tags are paused).
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( 'seofx_breadcrumbs', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Registers the dynamic block (editor script has no build step).
	 *
	 * @return void
	 */
	public function register_block() {
		wp_register_script(
			'seofx-breadcrumbs-block',
			SEOFX_URL . 'assets/blocks/breadcrumbs.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			SEOFX_VERSION,
			array( 'in_footer' => true )
		);
		wp_set_script_translations( 'seofx-breadcrumbs-block', 'seo-fixer' );

		register_block_type(
			'seo-fixer/breadcrumbs',
			array(
				'api_version'     => 3,
				'title'           => __( 'Breadcrumbs', 'seo-fixer' ),
				'description'     => __( 'Shows where the visitor is, for example Home › Bread › Sourdough.', 'seo-fixer' ),
				'category'        => 'theme',
				'icon'            => 'arrow-right-alt2',
				'editor_script'   => 'seofx-breadcrumbs-block',
				'render_callback' => array( __CLASS__, 'render_block' ),
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide', 'full' ),
				),
			)
		);
	}

	/**
	 * Label of the first crumb.
	 *
	 * @return string
	 */
	private static function home_label() {
		$label = trim( (string) Options::get( 'breadcrumbs_home_label' ) );
		return '' !== $label ? $label : __( 'Home', 'seo-fixer' );
	}

	/**
	 * Trail for a context: list of [name, url], first = home, last = current page.
	 *
	 * @param Context $context Context.
	 * @return array<int, array{name: string, url: string}>
	 */
	public static function trail( Context $context ) {
		$trail = array( self::crumb( self::home_label(), home_url( '/' ) ) );

		switch ( $context->type ) {
			case 'singular':
				$post = $context->object;
				if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $post->ID ) {
					break;
				}
				$trail = array_merge( $trail, self::post_parents( $post ) );
				$trail[] = self::crumb( get_the_title( $post ), (string) get_permalink( $post ) );
				break;

			case 'term':
				$term = $context->object;
				foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $ancestor_id ) {
					$ancestor = get_term( $ancestor_id, $term->taxonomy );
					if ( $ancestor instanceof \WP_Term ) {
						$trail[] = self::crumb( $ancestor->name, self::term_url( $ancestor ) );
					}
				}
				$trail[] = self::crumb( $term->name, self::term_url( $term ) );
				break;

			case 'author':
				$trail[] = self::crumb( $context->object->display_name, get_author_posts_url( $context->object->ID ) );
				break;

			case 'archive':
				$type = get_post_type_object( $context->post_type );
				if ( $type ) {
					$trail[] = self::crumb( $type->labels->name, (string) get_post_type_archive_link( $context->post_type ) );
				}
				break;

			case 'date':
				$year    = (int) get_query_var( 'year' );
				$month   = (int) get_query_var( 'monthnum' );
				$trail[] = self::crumb( (string) $year, get_year_link( $year ) );
				if ( $month ) {
					$trail[] = self::crumb( (string) get_the_date( 'F' ), get_month_link( $year, $month ) );
				}
				if ( is_day() ) {
					$trail[] = self::crumb( (string) get_the_date( 'j' ), get_day_link( $year, $month, (int) get_query_var( 'day' ) ) );
				}
				break;

			case 'search':
				/* translators: %s: what the visitor searched for. */
				$trail[] = self::crumb( sprintf( __( 'Search results for “%s”', 'seo-fixer' ), get_search_query( false ) ), '' );
				break;

			case '404':
				$trail[] = self::crumb( __( 'Page not found', 'seo-fixer' ), '' );
				break;
		}

		/**
		 * Filters the breadcrumb trail.
		 *
		 * @param array   $trail   List of [name, url].
		 * @param Context $context Page context.
		 */
		return (array) apply_filters( 'seofx_breadcrumbs', $trail, $context );
	}

	/**
	 * Crumbs between home and a post: blog page + category path, or page ancestors.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	private static function post_parents( \WP_Post $post ) {
		$crumbs = array();

		if ( 'post' === $post->post_type ) {
			$blog = (int) get_option( 'page_for_posts' );
			if ( $blog && 'page' === get_option( 'show_on_front' ) && is_post_publicly_viewable( $blog ) ) {
				$crumbs[] = self::crumb( get_the_title( $blog ), (string) get_permalink( $blog ) );
			}
			$categories = get_the_category( $post->ID );
			if ( $categories ) {
				$category = $categories[0];
				foreach ( array_reverse( get_ancestors( $category->term_id, 'category', 'taxonomy' ) ) as $ancestor_id ) {
					$ancestor = get_term( $ancestor_id, 'category' );
					if ( $ancestor instanceof \WP_Term ) {
						$crumbs[] = self::crumb( $ancestor->name, self::term_url( $ancestor ) );
					}
				}
				$crumbs[] = self::crumb( $category->name, self::term_url( $category ) );
			}
			return $crumbs;
		}

		if ( is_post_type_hierarchical( $post->post_type ) ) {
			foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor_id ) {
				// A private or draft parent must not show its title or address to visitors.
				if ( ! is_post_publicly_viewable( $ancestor_id ) ) {
					continue;
				}
				$crumbs[] = self::crumb( get_the_title( $ancestor_id ), (string) get_permalink( $ancestor_id ) );
			}
			return $crumbs;
		}

		$archive = get_post_type_archive_link( $post->post_type );
		$type    = get_post_type_object( $post->post_type );
		if ( $archive && $type ) {
			$crumbs[] = self::crumb( $type->labels->name, $archive );
		}
		return $crumbs;
	}

	/**
	 * Term link ('' on error).
	 *
	 * @param \WP_Term $term Term.
	 * @return string
	 */
	private static function term_url( \WP_Term $term ) {
		$link = get_term_link( $term );
		return is_wp_error( $link ) ? '' : $link;
	}

	/**
	 * One crumb.
	 *
	 * @param string $name Label.
	 * @param string $url  Link ('' for none).
	 * @return array{name: string, url: string}
	 */
	private static function crumb( $name, $url ) {
		return array(
			'name' => wp_strip_all_tags( html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ) ),
			'url'  => (string) $url,
		);
	}

	/**
	 * HTML for a trail (empty on the homepage).
	 *
	 * @param array $trail Trail.
	 * @return string
	 */
	public static function html( array $trail ) {
		if ( count( $trail ) < 2 ) {
			return '';
		}

		$separator = trim( (string) Options::get( 'breadcrumbs_separator' ) );
		$separator = '' !== $separator ? $separator : '›';
		$last      = count( $trail ) - 1;
		$parts     = array();

		foreach ( $trail as $index => $crumb ) {
			if ( $index === $last || '' === $crumb['url'] ) {
				$parts[] = '<span' . ( $index === $last ? ' aria-current="page"' : '' ) . '>' . esc_html( $crumb['name'] ) . '</span>';
			} else {
				$parts[] = '<a href="' . esc_url( $crumb['url'] ) . '">' . esc_html( $crumb['name'] ) . '</a>';
			}
		}

		return '<nav class="seofx-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'seo-fixer' ) . '">'
			. implode( ' <span class="seofx-breadcrumbs__sep" aria-hidden="true">' . esc_html( $separator ) . '</span> ', $parts )
			. '</nav>';
	}

	/**
	 * [seofx_breadcrumbs] shortcode.
	 *
	 * @return string
	 */
	public static function shortcode() {
		return self::html( self::trail( Context::current() ) );
	}

	/**
	 * Block render callback (shows an example inside the editor).
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_block( $attributes = array() ) {
		// In the editor (server-side render over REST) there is no current page: show an example.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$html = self::html(
				array(
					self::crumb( self::home_label(), home_url( '/' ) ),
					self::crumb( __( 'Category', 'seo-fixer' ), home_url( '/' ) ),
					self::crumb( __( 'Current page', 'seo-fixer' ), '' ),
				)
			);
		} else {
			$html = self::shortcode();
		}

		if ( '' === $html ) {
			return '';
		}
		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}

	/**
	 * Whether a context should carry breadcrumb schema.
	 *
	 * @param Context $context Context.
	 * @return bool
	 */
	public static function has_schema( Context $context ) {
		return ! in_array( $context->type, array( 'home', 'other', '404', 'search' ), true ) && ! Indexing::is_noindex( $context );
	}
}
