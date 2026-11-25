<?php
/**
 * Best-practice defaults per site type, applied by the setup wizard.
 *
 * @package SeoFixer
 */

namespace Seofx;

defined( 'ABSPATH' ) || exit;

/**
 * Site-type presets.
 */
final class Presets {

	/**
	 * Site types that usually have a physical location or local service area.
	 */
	const LOCAL_TYPES = array( 'business', 'shop' );

	/**
	 * Recommended settings for a site type.
	 *
	 * @param string $type business|blog|shop|portfolio.
	 * @return array Option key => value.
	 */
	public static function for_site_type( $type ) {
		$preset = array(
			'noindex_search'          => true,
			'noindex_date_archives'   => true,
			'noindex_author_archives' => true,
			'attachment_redirect'     => true,
			'entity_type'             => 'organization',
			'article_type'            => 'Article',
		);

		if ( 'blog' === $type ) {
			// Author pages are useful on blogs: readers browse by writer.
			$preset['noindex_author_archives'] = false;
			$preset['entity_type']             = 'person';
			$preset['article_type']            = 'BlogPosting';
		} elseif ( 'portfolio' === $type ) {
			$preset['entity_type'] = 'person';
		}

		/**
		 * Filters the recommended settings for a site type.
		 *
		 * @param array  $preset Option key => value.
		 * @param string $type   Site type.
		 */
		return (array) apply_filters( 'seofx_site_type_preset', $preset, $type );
	}

	/**
	 * Saves the recommended settings for a site type.
	 *
	 * @param string $type Site type.
	 * @return void
	 */
	public static function apply( $type ) {
		Options::update( self::for_site_type( $type ) );
	}

	/**
	 * Plain-English description of the current recommended settings.
	 *
	 * @return string[]
	 */
	public static function describe() {
		$lines = array();

		if ( Options::get( 'noindex_search' ) ) {
			$lines[] = __( 'Your internal search result pages are kept out of Google, as Google recommends.', 'seo-fixer' );
		}
		if ( Options::get( 'noindex_date_archives' ) ) {
			$lines[] = __( 'Date archives (lists of posts by month) are kept out of Google, so they don’t compete with your real pages.', 'seo-fixer' );
		}
		$lines[] = Options::get( 'noindex_author_archives' )
			? __( 'Author pages are kept out of Google, because they repeat content found elsewhere.', 'seo-fixer' )
			: __( 'Author pages stay visible in Google, so readers can find articles by each writer.', 'seo-fixer' );
		if ( Options::get( 'attachment_redirect' ) ) {
			$lines[] = __( 'Image attachment pages send visitors to the post the image belongs to.', 'seo-fixer' );
		}
		$lines[] = 'BlogPosting' === Options::get( 'article_type' )
			? __( 'Your posts are described to search engines as blog posts.', 'seo-fixer' )
			: __( 'Your posts are described to search engines as articles.', 'seo-fixer' );

		return $lines;
	}

	/**
	 * Whether the local business step applies to a site type.
	 *
	 * @param string $type Site type.
	 * @return bool
	 */
	public static function is_local_type( $type ) {
		return in_array( $type, self::LOCAL_TYPES, true );
	}
}
