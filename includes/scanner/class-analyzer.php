<?php
/**
 * Looks at one page and records facts about it: final title and description,
 * headings, images, internal links, length and visibility.
 *
 * Content is read as stored (blocks/HTML), without running shortcodes or other
 * plugins' filters, so scanning has no side effects and stays fast.
 *
 * @package SeoFixer
 */

namespace Seofx\Scanner;

use Seofx\Fixes\Html;
use Seofx\Indexing\Indexing;
use Seofx\Meta\Context;
use Seofx\Meta\Images;
use Seofx\Meta\Post_Meta;
use Seofx\Meta\Resolver;
use Seofx\Redirects\Paths;
use Seofx\Redirects\Store as Redirects;
use Seofx\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Page analyzer.
 */
final class Analyzer {

	/**
	 * Images above this size (bytes) are "very large".
	 */
	const LARGE_IMAGE = 512000;

	/**
	 * Known term and archive addresses (built once per request).
	 *
	 * @var array<string, true>|null
	 */
	private static $known_paths = null;

	/**
	 * Resolved link cache for this request: path => [target_id, broken].
	 *
	 * @var array<string, array>
	 */
	private static $resolved = array();

	/**
	 * Facts and internal links of a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{facts: array, links: array[]}
	 */
	public static function analyze( \WP_Post $post ) {
		$context  = Context::for_post( $post );
		$content  = (string) $post->post_content;
		$password = '' !== $post->post_password;

		$title       = Resolver::title( $context );
		$description = Resolver::description( $context );
		$custom_desc = '' !== trim( (string) get_post_meta( $post->ID, Post_Meta::DESCRIPTION, true ) );

		$images = $password ? array(
			'total'      => 0,
			'no_alt'     => array(),
			'large'      => array(),
		) : self::images( $content );
		$links  = $password ? array() : self::links( $content, (string) get_permalink( $post ), $post->ID );
		$broken = array();
		foreach ( $links as $link ) {
			if ( $link['broken'] ) {
				$broken[] = $link['url'];
			}
		}

		$facts = array(
			'title'       => $title,
			'title_len'   => mb_strlen( $title ),
			'desc'        => $description,
			'desc_len'    => mb_strlen( $description ),
			'desc_custom' => $custom_desc,
			'h1'          => $password ? 0 : preg_match_all( '/<h1[\s>]/i', $content ),
			'images'      => $images['total'],
			'no_alt'      => array_slice( $images['no_alt'], 0, 10 ),
			'no_alt_n'    => count( $images['no_alt'] ),
			'large'       => array_slice( $images['large'], 0, 10 ),
			'large_n'     => count( $images['large'] ),
			'has_image'   => null !== Images::for_post( $post ),
			'noindex'     => ! Indexing::post_indexable( $post ),
			'words'       => $password ? 0 : self::word_count( $content ),
			'slug_len'    => mb_strlen( rawurldecode( $post->post_name ) ),
			'links_out'   => count( $links ),
			'broken'      => array_slice( $broken, 0, 10 ),
			'broken_n'    => count( $broken ),
			'is_front'    => 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $post->ID,
			'is_blog'     => (int) get_option( 'page_for_posts' ) === $post->ID,
			'password'    => $password,
		);

		return array(
			'facts' => $facts,
			'links' => $links,
		);
	}

	/**
	 * Words in the visible text.
	 *
	 * @param string $content Content.
	 * @return int
	 */
	private static function word_count( $content ) {
		$text = Text::plain( $content );
		return '' === $text ? 0 : (int) preg_match_all( '/\S+/u', $text );
	}

	/**
	 * Images: total, those without alt text, and very large ones.
	 *
	 * @param string $content Content.
	 * @return array{total: int, no_alt: string[], large: array[]}
	 */
	private static function images( $content ) {
		$result = array(
			'total'  => 0,
			'no_alt' => array(),
			'large'  => array(),
		);

		if ( ! preg_match_all( Html::IMG, $content, $tags ) ) {
			return $result;
		}

		foreach ( $tags[0] as $tag ) {
			++$result['total'];
			// Same rules as the fixer, so a fixed image is never reported again.
			$info = Html::img_info( $tag );
			$src  = $info['src'];
			if ( ! $info['has_alt'] && ! $info['decorative'] ) {
				$result['no_alt'][] = $src;
			}

			// Block markup names the attachment: class="wp-image-123".
			$id = $info['attachment_id'];
			if ( ! $id && '' !== $src ) {
				$id = attachment_url_to_postid( $src );
			}
			if ( $id ) {
				$meta = wp_get_attachment_metadata( $id );
				$size = is_array( $meta ) && isset( $meta['filesize'] ) ? (int) $meta['filesize'] : 0;
				if ( $size > self::LARGE_IMAGE ) {
					$result['large'][] = array(
						'src' => $src,
						'kb'  => (int) round( $size / 1024 ),
					);
				}
			}
		}

		return $result;
	}

	/**
	 * Internal links found in the content, each resolved to a page or marked broken.
	 *
	 * @param string $content   Content.
	 * @param string $permalink Address of the page (for relative links).
	 * @param int    $post_id   Page id.
	 * @return array[] Each: url, target_id, broken.
	 */
	private static function links( $content, $permalink, $post_id ) {
		if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $content, $matches ) ) {
			return array();
		}

		$links = array();
		$seen  = array();
		foreach ( $matches[1] as $href ) {
			$href = trim( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $href || '#' === $href[0] || preg_match( '/^(mailto|tel|javascript|data|sms):/i', $href ) ) {
				continue;
			}
			if ( ! preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $href ) && 0 !== strpos( $href, '//' ) ) {
				$href = \WP_Http::make_absolute_url( $href, $permalink );
			}
			$href = (string) strtok( $href, '#' );
			$path = Paths::normalize( $href );
			if ( '' === $path || isset( $seen[ $path ] ) ) {
				continue; // Another website, or already counted.
			}
			$seen[ $path ] = true;

			list( $target_id, $broken ) = self::resolve( $path );
			if ( $target_id === $post_id ) {
				continue; // A page linking to itself doesn't count.
			}
			$links[] = array(
				'url'       => $path,
				'target_id' => $target_id,
				'broken'    => $broken,
			);
		}
		return $links;
	}

	/**
	 * What an internal address leads to.
	 *
	 * @param string $path Normalized path.
	 * @return array{0: int, 1: bool} Target post id (0 if not a post), broken.
	 */
	public static function resolve( $path ) {
		if ( isset( self::$resolved[ $path ] ) ) {
			return self::$resolved[ $path ];
		}

		$bare   = Paths::without_query( $path );
		$result = array( 0, false );

		if ( '/' === $bare ) {
			$front  = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
			$result = array( $front, false );
		} elseif ( preg_match( '#^/(wp-content|wp-includes|wp-admin|wp-json|feed|comments|author|search|page|\d{4})(/|$)|/feed$|\.[a-z0-9]{2,5}$#i', $bare ) ) {
			$result = array( 0, false ); // Files, feeds and archive lists.
		} else {
			$id = url_to_postid( home_url( $path ) );
			if ( $id ) {
				$result = array( $id, 'publish' !== get_post_status( $id ) );
			} elseif ( isset( self::known_paths()[ $bare ] ) || Redirects::match( $path ) ) {
				$result = array( 0, false );
			} else {
				$result = array( 0, true );
			}
		}

		self::$resolved[ $path ] = $result;
		return $result;
	}

	/**
	 * Addresses of category/tag pages and content-type archives.
	 *
	 * @return array<string, true>
	 */
	private static function known_paths() {
		if ( null !== self::$known_paths ) {
			return self::$known_paths;
		}

		self::$known_paths = array();
		foreach ( array_keys( \Seofx\Meta\Templates::taxonomies() ) as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 5000,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					self::$known_paths[ Paths::normalize( $link ) ] = true;
				}
			}
		}
		foreach ( array_keys( \Seofx\Meta\Templates::post_types() ) as $type ) {
			$link = get_post_type_archive_link( $type );
			if ( $link ) {
				self::$known_paths[ Paths::normalize( $link ) ] = true;
			}
		}
		return self::$known_paths;
	}
}
