<?php
/**
 * XML sitemaps: URLs, routing, caching.
 *
 *   /sitemap_index.xml       list of sitemaps (also served at /sitemap.xml)
 *   /{type}-sitemap{N}.xml   one page of posts, pages, products, categories…
 *   /sitemap.xsl             stylesheet that makes sitemaps readable in a browser
 *
 * Without pretty permalinks the same files live at ?seofx_sitemap=index etc.
 * WordPress's own wp-sitemap.xml is switched off and redirected here, so
 * search engines only ever see one sitemap.
 *
 * @package SeoFixer
 */

namespace Seofx\Indexing;

use Seofx\Meta\Templates;
use Seofx\Options;
use Seofx\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Sitemap controller.
 */
final class Sitemaps {

	/**
	 * Option holding the cache version; bumping it invalidates every cached sitemap.
	 */
	const VERSION_OPTION = 'seofx_sitemap_version';

	/**
	 * Option holding whether the stored rewrite rules include ours: 'on', 'off',
	 * or '' when they must be rebuilt on the next request. Autoloaded, so checking
	 * it costs no query.
	 */
	const FLUSH_OPTION = 'seofx_flush_rewrites';

	/**
	 * Hooks.
	 *
	 * The pretty URLs are only registered while our sitemap is on. While another
	 * SEO plugin is in charge (output paused), its own "-sitemap.xml" URLs must
	 * not be caught by ours.
	 *
	 * @return void
	 */
	public function register() {
		if ( self::enabled() ) {
			add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		}
		add_action( 'init', array( $this, 'maybe_flush' ), 99 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_request', array( $this, 'serve' ), 1 );

		add_action( 'save_post', array( __CLASS__, 'post_changed' ) );
		add_action( 'deleted_post', array( __CLASS__, 'post_changed' ) );
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'term_changed' ), 10, 3 );
		}
		add_action( 'update_option_' . Options::KEY, array( __CLASS__, 'bump_version' ) );
		// WordPress settings that change addresses or what the homepage is.
		foreach ( array( 'permalink_structure', 'category_base', 'tag_base', 'home', 'show_on_front', 'page_on_front', 'page_for_posts' ) as $option ) {
			add_action( 'update_option_' . $option, array( __CLASS__, 'bump_version' ) );
		}

		if ( self::enabled() ) {
			add_filter( 'wp_sitemaps_enabled', '__return_false' );
		}
	}

	/**
	 * Whether SEO Fixer's sitemap is switched on and output isn't paused.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Options::get( 'sitemap_enabled' ) && Plugin::output_enabled();
	}

	/**
	 * Public URL of a sitemap file.
	 *
	 * @param string $name 'index', 'xsl' or a type name.
	 * @param int    $page Page number (types only).
	 * @return string
	 */
	public static function url( $name = 'index', $page = 1 ) {
		global $wp_rewrite;

		if ( $wp_rewrite && $wp_rewrite->using_permalinks() ) {
			if ( 'index' === $name ) {
				return home_url( '/sitemap_index.xml' );
			}
			if ( 'xsl' === $name ) {
				return home_url( '/sitemap.xsl' );
			}
			return home_url( '/' . $name . '-sitemap' . ( $page > 1 ? (string) $page : '' ) . '.xml' );
		}

		$args = array( 'seofx_sitemap' => $name );
		if ( $page > 1 ) {
			$args['seofx_sitemap_page'] = $page;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Rewrite rules for the pretty sitemap URLs.
	 *
	 * @return void
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule( '^sitemap_index\.xml$', 'index.php?seofx_sitemap=index', 'top' );
		add_rewrite_rule( '^sitemap\.xml$', 'index.php?seofx_sitemap=index', 'top' );
		add_rewrite_rule( '^sitemap\.xsl$', 'index.php?seofx_sitemap=xsl', 'top' );
		add_rewrite_rule( '^([a-z0-9_-]+?)-sitemap([0-9]+)?\.xml$', 'index.php?seofx_sitemap=$matches[1]&seofx_sitemap_page=$matches[2]', 'top' );
	}

	/**
	 * Rebuilds rewrite rules once after activation or upgrade, and whenever the
	 * sitemap is switched on or off (in Settings, or because another SEO plugin
	 * was activated or deactivated).
	 *
	 * @return void
	 */
	public function maybe_flush() {
		$wanted = self::enabled() ? 'on' : 'off';
		if ( get_option( self::FLUSH_OPTION ) !== $wanted ) {
			update_option( self::FLUSH_OPTION, $wanted, true );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Asks for a rewrite flush on the next request.
	 *
	 * @return void
	 */
	public static function schedule_flush() {
		update_option( self::FLUSH_OPTION, '', true );
	}

	/**
	 * Registers the query variables.
	 *
	 * @param string[] $vars Public query vars.
	 * @return string[]
	 */
	public function query_vars( $vars ) {
		$vars[] = 'seofx_sitemap';
		$vars[] = 'seofx_sitemap_page';
		return $vars;
	}

	/**
	 * Invalidates all cached sitemaps.
	 *
	 * @return void
	 */
	public static function bump_version() {
		update_option( self::VERSION_OPTION, (string) microtime( true ), false );
	}

	/**
	 * A post was saved or deleted. Only content that can be in a sitemap counts:
	 * menu items, revisions, orders and other internal types are saved all the time.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function post_changed( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( array_key_exists( (string) get_post_type( $post_id ), Templates::post_types() ) ) {
			self::bump_version();
		}
	}

	/**
	 * A term was created, edited or deleted.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public static function term_changed( $term_id, $tt_id, $taxonomy ) {
		if ( array_key_exists( (string) $taxonomy, Templates::taxonomies() ) ) {
			self::bump_version();
		}
	}

	/**
	 * Serves a sitemap before WordPress runs its main query.
	 *
	 * @param \WP $wp Request.
	 * @return void
	 */
	public function serve( $wp ) {
		$request = isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';

		// WordPress's own sitemap is off; send its visitors to ours.
		if ( self::enabled() && ( 'wp-sitemap.xml' === $request || 0 === strpos( $request, 'wp-sitemap-' ) ) ) {
			wp_safe_redirect( self::url(), 301, 'SEO Fixer' );
			exit;
		}

		// WordPress's own "sitemap.xml" rule matches before ours; claim the path.
		if ( self::enabled() && 'sitemap.xml' === $request ) {
			$wp->query_vars['seofx_sitemap'] = 'index';
		}

		if ( empty( $wp->query_vars['seofx_sitemap'] ) ) {
			return;
		}

		$name = is_scalar( $wp->query_vars['seofx_sitemap'] ) ? sanitize_key( (string) $wp->query_vars['seofx_sitemap'] ) : '';
		$page = isset( $wp->query_vars['seofx_sitemap_page'] ) && is_scalar( $wp->query_vars['seofx_sitemap_page'] ) ? max( 1, (int) $wp->query_vars['seofx_sitemap_page'] ) : 1;

		// Only known files are built, so made-up addresses cost nothing and leave nothing behind.
		$known = 'index' === $name || 'xsl' === $name || array_key_exists( $name, Sitemap_Xml::sources() );
		if ( ! self::enabled() || ! $known || ( 'index' === $name && $page > 1 ) ) {
			// Sitemap off, unknown sitemap: let WordPress show its 404 (not the homepage).
			unset( $wp->query_vars['seofx_sitemap'], $wp->query_vars['seofx_sitemap_page'] );
			$wp->query_vars['error'] = '404';
			return;
		}

		if ( 'xsl' === $name ) {
			$this->send( Sitemap_Xml::stylesheet(), 'text/xsl' );
		}

		$xml = $this->cached( $name, $page );
		if ( '' === $xml ) {
			// Past the last page.
			unset( $wp->query_vars['seofx_sitemap'], $wp->query_vars['seofx_sitemap_page'] );
			$wp->query_vars['error'] = '404';
			return;
		}

		$this->send( $xml, 'application/xml' );
	}

	/**
	 * Built sitemap from cache, or freshly built ('' when it doesn't exist).
	 *
	 * @param string $name 'index' or a known type name.
	 * @param int    $page Page number.
	 * @return string
	 */
	private function cached( $name, $page ) {
		$key = 'seofx_sm_' . md5( $name . '|' . $page . '|' . get_option( self::VERSION_OPTION, '' ) . '|' . get_locale() );
		$xml = get_transient( $key );

		if ( ! is_string( $xml ) ) {
			$xml = 'index' === $name ? Sitemap_Xml::index() : Sitemap_Xml::urlset( $name, $page );
			// Empty pages aren't cached: a page number past the end must not create a cache entry.
			if ( '' !== $xml ) {
				set_transient( $key, $xml, 12 * HOUR_IN_SECONDS );
			}
		}

		return $xml;
	}

	/**
	 * Prints XML with the right headers and stops.
	 *
	 * @param string $xml          Document (already escaped while building).
	 * @param string $content_type MIME type.
	 * @return void
	 */
	private function send( $xml, $content_type ) {
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: ' . $content_type . '; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow' );
		}
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by Sitemap_Xml with esc_xml() on every value.
		exit;
	}
}
