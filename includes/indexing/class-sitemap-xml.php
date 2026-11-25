<?php
/**
 * Builds sitemap XML: the index, one page of URLs, and the browser stylesheet.
 *
 * Only pages that may appear in search results are listed: no hidden
 * (noindex) items, no password-protected posts, nothing whose canonical
 * points elsewhere, and never user/author lists.
 *
 * @package SeoFixer
 */

namespace Seofx\Indexing;

use Seofx\Meta\Post_Meta;
use Seofx\Meta\Templates;
use Seofx\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Sitemap documents.
 */
final class Sitemap_Xml {

	/**
	 * URLs per sitemap page (Google allows 50,000; smaller pages build faster).
	 *
	 * @return int
	 */
	public static function per_page() {
		/**
		 * Filters how many URLs one sitemap page lists.
		 *
		 * @param int $per_page Default 1000.
		 */
		return max( 1, min( 50000, (int) apply_filters( 'seofx_sitemap_per_page', 1000 ) ) );
	}

	/**
	 * Content types and taxonomies that get a sitemap, pages first.
	 *
	 * @return array<string, string> Name => 'post_type'|'taxonomy'.
	 */
	public static function sources() {
		$sources = array();

		$types = Templates::post_types();
		if ( isset( $types['page'] ) ) {
			$types = array( 'page' => $types['page'] ) + $types;
		}
		// Hidden types stay in the list: items shown one by one must still be found.
		// Their queries only return those items, so a fully hidden type gets no sitemap.
		foreach ( array_keys( $types ) as $type ) {
			$sources[ $type ] = 'post_type';
		}
		foreach ( array_keys( Templates::taxonomies() ) as $taxonomy ) {
			if ( ! isset( $sources[ $taxonomy ] ) ) {
				$sources[ $taxonomy ] = 'taxonomy';
			}
		}

		/**
		 * Filters which sitemaps exist.
		 *
		 * @param array<string, string> $sources Name => 'post_type'|'taxonomy'.
		 */
		return (array) apply_filters( 'seofx_sitemap_sources', $sources );
	}

	/**
	 * Query arguments for the posts of one type that belong in the sitemap.
	 *
	 * @param string $type Post type.
	 * @return array
	 */
	private static function post_query_args( $type ) {
		$args = array(
			'post_type'              => $type,
			'post_status'            => 'publish',
			'has_password'           => false,
			'ignore_sticky_posts'    => true,
			// Terms are only needed when post addresses contain the category (one query per post otherwise).
			'update_post_term_cache' => 'post' === $type && false !== strpos( (string) get_option( 'permalink_structure' ), '%category%' ),
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Hidden posts must be left out; runs only when a sitemap is rebuilt (cached 12 h).
				'relation' => 'OR',
				array(
					'key'     => Post_Meta::NOINDEX,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => Post_Meta::NOINDEX,
					'value'   => '0',
				),
			),
		);

		// Posts hidden by their type but shown one by one ('0') are still listed.
		if ( ! Indexing::type_indexed( $type ) ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- See above.
				array(
					'key'   => Post_Meta::NOINDEX,
					'value' => '0',
				),
			);
		}

		return $args;
	}

	/**
	 * Query arguments for the terms of one taxonomy that belong in the sitemap.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array
	 */
	private static function term_query_args( $taxonomy ) {
		$shown_one_by_one = array(
			'key'   => Post_Meta::NOINDEX,
			'value' => '0',
		);

		return array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Hidden terms must be left out; cached 12 h.
			'meta_query' => Indexing::taxonomy_indexed( $taxonomy )
				? array(
					'relation' => 'OR',
					array(
						'key'     => Post_Meta::NOINDEX,
						'compare' => 'NOT EXISTS',
					),
					$shown_one_by_one,
				)
				: array( $shown_one_by_one ),
		);
	}

	/**
	 * The sitemap index.
	 *
	 * @return string XML.
	 */
	public static function index() {
		$per     = self::per_page();
		$entries = '';

		foreach ( self::sources() as $name => $kind ) {
			if ( 'post_type' === $kind ) {
				$query = new \WP_Query(
					self::post_query_args( $name ) + array(
						'posts_per_page' => 1,
						'orderby'        => 'modified',
						'order'          => 'DESC',
					)
				);
				$total   = (int) $query->found_posts;
				$lastmod = $query->posts ? $query->posts[0]->post_modified_gmt : '';
				if ( 'page' === $name && 'posts' === get_option( 'show_on_front' ) ) {
					++$total; // The homepage is listed first in the page sitemap.
				}
			} else {
				$total   = (int) wp_count_terms( self::term_query_args( $name ) );
				$lastmod = '';
			}

			$pages = (int) ceil( $total / $per );
			for ( $page = 1; $page <= $pages; $page++ ) {
				$entries .= "\t<sitemap>\n\t\t<loc>" . esc_xml( Sitemaps::url( $name, $page ) ) . "</loc>\n";
				if ( '' !== $lastmod && 1 === $page ) {
					$entries .= "\t\t<lastmod>" . esc_xml( self::w3c_date( $lastmod ) ) . "</lastmod>\n";
				}
				$entries .= "\t</sitemap>\n";
			}
		}

		return self::header()
			. '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
			. $entries
			. "</sitemapindex>\n";
	}

	/**
	 * One page of URLs ('' when the type or page does not exist).
	 *
	 * @param string $name Type or taxonomy name.
	 * @param int    $page Page number.
	 * @return string XML.
	 */
	public static function urlset( $name, $page ) {
		$sources = self::sources();
		if ( ! isset( $sources[ $name ] ) ) {
			return '';
		}

		$urls = 'post_type' === $sources[ $name ] ? self::post_urls( $name, $page ) : self::term_urls( $name, $page );
		if ( array() === $urls ) {
			return '';
		}

		$entries = '';
		foreach ( $urls as $url ) {
			$entries .= "\t<url>\n\t\t<loc>" . esc_xml( $url['loc'] ) . "</loc>\n";
			if ( ! empty( $url['lastmod'] ) ) {
				$entries .= "\t\t<lastmod>" . esc_xml( self::w3c_date( $url['lastmod'] ) ) . "</lastmod>\n";
			}
			foreach ( isset( $url['images'] ) ? $url['images'] : array() as $image ) {
				$entries .= "\t\t<image:image>\n\t\t\t<image:loc>" . esc_xml( $image ) . "</image:loc>\n\t\t</image:image>\n";
			}
			$entries .= "\t</url>\n";
		}

		return self::header()
			. '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n"
			. $entries
			. "</urlset>\n";
	}

	/**
	 * URLs of one page of posts.
	 *
	 * @param string $type Post type.
	 * @param int    $page Page number.
	 * @return array[]
	 */
	private static function post_urls( $type, $page ) {
		$per    = self::per_page();
		$urls   = array();
		$offset = ( $page - 1 ) * $per;

		// The "latest posts" homepage leads the page sitemap.
		if ( 'page' === $type && 'posts' === get_option( 'show_on_front' ) ) {
			if ( 1 === $page ) {
				$latest = get_lastpostmodified( 'gmt' );
				$urls[] = array(
					'loc'     => home_url( '/' ),
					'lastmod' => $latest ? $latest : '',
				);
				--$per;
			} else {
				--$offset;
			}
		}

		$query = new \WP_Query(
			self::post_query_args( $type ) + array(
				'posts_per_page' => $per,
				'offset'         => max( 0, $offset ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$images = (bool) Options::get( 'sitemap_images' );
		if ( $images ) {
			update_post_thumbnail_cache( $query ); // One query for all featured images.
		}

		foreach ( $query->posts as $post ) {
			$loc = get_permalink( $post );
			if ( ! $loc ) {
				continue;
			}
			// A custom canonical pointing elsewhere means "list the other URL instead".
			$canonical = Indexing::custom_canonical( $post );
			if ( '' !== $canonical && untrailingslashit( $canonical ) !== untrailingslashit( $loc ) ) {
				continue;
			}
			$urls[] = array(
				'loc'     => $loc,
				'lastmod' => $post->post_modified_gmt,
				'images'  => $images ? self::images( $post ) : array(),
			);
		}

		// Past the last page: no sitemap (404).
		if ( array() === $query->posts && $page > 1 ) {
			return array();
		}
		return $urls;
	}

	/**
	 * URLs of one page of terms.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $page     Page number.
	 * @return array[]
	 */
	private static function term_urls( $taxonomy, $page ) {
		$per   = self::per_page();
		$terms = get_terms(
			self::term_query_args( $taxonomy ) + array(
				'number'  => $per,
				'offset'  => ( $page - 1 ) * $per,
				'orderby' => 'term_id',
			)
		);

		$urls = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$canonical = Indexing::custom_canonical( $term );
			if ( '' !== $canonical && untrailingslashit( $canonical ) !== untrailingslashit( $link ) ) {
				continue;
			}
			$urls[] = array( 'loc' => $link );
		}
		return $urls;
	}

	/**
	 * Featured image plus images in the content (absolute URLs, max 10).
	 *
	 * @param \WP_Post $post Post.
	 * @return string[]
	 */
	private static function images( \WP_Post $post ) {
		$images = array();

		$thumbnail = get_post_thumbnail_id( $post );
		if ( $thumbnail ) {
			$url = wp_get_attachment_image_url( $thumbnail, 'full' );
			if ( $url ) {
				$images[] = $url;
			}
		}

		if ( preg_match_all( '/<img\s[^>]*?src=["\']([^"\']+)["\']/i', $post->post_content, $matches ) ) {
			foreach ( $matches[1] as $src ) {
				if ( preg_match( '#^https?://#i', $src ) ) {
					$images[] = html_entity_decode( $src, ENT_QUOTES, 'UTF-8' );
				}
			}
		}

		return array_slice( array_values( array_unique( $images ) ), 0, 10 );
	}

	/**
	 * GMT MySQL date → W3C datetime.
	 *
	 * @param string $gmt Date in GMT.
	 * @return string
	 */
	private static function w3c_date( $gmt ) {
		$time = strtotime( $gmt . ' UTC' );
		return $time ? gmdate( 'c', $time ) : '';
	}

	/**
	 * XML declaration and stylesheet reference.
	 *
	 * @return string
	 */
	private static function header() {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<?xml-stylesheet type="text/xsl" href="' . esc_xml( Sitemaps::url( 'xsl' ) ) . '"?>' . "\n";
	}

	/**
	 * Stylesheet that shows sitemaps as a readable table in browsers.
	 *
	 * @return string XSL.
	 */
	public static function stylesheet() {
		$title = esc_xml( __( 'XML Sitemap', 'seo-fixer' ) );
		$intro = esc_xml( __( 'This sitemap is made by SEO Fixer. Search engines read it to find every page you want them to show. People don’t need to do anything with it.', 'seo-fixer' ) );
		$maps  = esc_xml( __( 'Sitemaps in this index:', 'seo-fixer' ) );
		$urls  = esc_xml( __( 'Addresses in this sitemap:', 'seo-fixer' ) );
		$col1  = esc_xml( __( 'Address', 'seo-fixer' ) );
		$col2  = esc_xml( __( 'Images', 'seo-fixer' ) );
		$col3  = esc_xml( __( 'Last changed', 'seo-fixer' ) );
		$back  = esc_xml( __( 'Back to the sitemap index', 'seo-fixer' ) );
		$index = esc_xml( Sitemaps::url() );

		$css = 'body{font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1b2130;background:#f4f5f8;margin:0;padding:32px 16px}'
			. 'main{max-width:1000px;margin:0 auto;background:#fff;border:1px solid #e2e5eb;border-radius:14px;padding:28px}'
			. 'h1{margin:0 0 8px;font-size:24px}p{color:#535b6e;margin:0 0 20px}'
			. 'table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:9px 10px;border-bottom:1px solid #e2e5eb}'
			. 'th{background:#f8f9fb;color:#535b6e;font-size:12px;text-transform:uppercase;letter-spacing:.04em}'
			. 'a{color:#2f54eb;word-break:break-all}.count{font-weight:600;color:#1b2130}';

		$lines = array(
			'<?xml version="1.0" encoding="UTF-8"?>',
			'<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" exclude-result-prefixes="s image">',
			'<xsl:output method="html" encoding="UTF-8" indent="yes"/>',
			'<xsl:template match="/">',
			'<html><head><meta name="robots" content="noindex, follow"/>',
			'<title>' . $title . '</title>',
			'<style>' . $css . '</style>',
			'</head><body><main>',
			'<h1>' . $title . '</h1>',
			'<p>' . $intro . '</p>',
			'<xsl:choose>',
			'<xsl:when test="s:sitemapindex">',
			'<p>' . $maps . ' <span class="count"><xsl:value-of select="count(s:sitemapindex/s:sitemap)"/></span></p>',
			'<table><thead><tr><th>' . $col1 . '</th><th>' . $col3 . '</th></tr></thead><tbody>',
			'<xsl:for-each select="s:sitemapindex/s:sitemap">',
			'<tr><td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td><td><xsl:value-of select="substring(s:lastmod,1,10)"/></td></tr>',
			'</xsl:for-each>',
			'</tbody></table>',
			'</xsl:when>',
			'<xsl:otherwise>',
			'<p>' . $urls . ' <span class="count"><xsl:value-of select="count(s:urlset/s:url)"/></span> · <a href="' . $index . '">' . $back . '</a></p>',
			'<table><thead><tr><th>' . $col1 . '</th><th>' . $col2 . '</th><th>' . $col3 . '</th></tr></thead><tbody>',
			'<xsl:for-each select="s:urlset/s:url">',
			'<tr><td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td><td><xsl:value-of select="count(image:image)"/></td><td><xsl:value-of select="substring(s:lastmod,1,10)"/></td></tr>',
			'</xsl:for-each>',
			'</tbody></table>',
			'</xsl:otherwise>',
			'</xsl:choose>',
			'</main></body></html>',
			'</xsl:template>',
			'</xsl:stylesheet>',
		);

		return implode( "\n", $lines ) . "\n";
	}
}
