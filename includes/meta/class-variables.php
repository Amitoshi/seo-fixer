<?php
/**
 * %variables% that can be used in titles and descriptions.
 *
 * The same replacement and clean-up rules exist in admin.js for the live preview.
 *
 * @package SeoFixer
 */

namespace Seofx\Meta;

use Seofx\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Variable definitions and replacement.
 */
final class Variables {

	/**
	 * Variable name => plain-English description.
	 *
	 * @return array<string, string>
	 */
	public static function definitions() {
		return array(
			'title'            => __( 'Title of the post or page', 'seo-fixer' ),
			'sitename'         => __( 'Your site title', 'seo-fixer' ),
			'tagline'          => __( 'Your site tagline', 'seo-fixer' ),
			'sep'              => __( 'The title separator', 'seo-fixer' ),
			'excerpt'          => __( 'The excerpt, or else the first sentences of the content', 'seo-fixer' ),
			'category'         => __( 'The first category of the post', 'seo-fixer' ),
			'term'             => __( 'Name of the category, tag or other group', 'seo-fixer' ),
			'term_description' => __( 'Description of the category, tag or other group', 'seo-fixer' ),
			'author'           => __( 'Author name', 'seo-fixer' ),
			'author_bio'       => __( 'Author biography', 'seo-fixer' ),
			'date'             => __( 'Publish date, or the date of a date archive', 'seo-fixer' ),
			'archive_title'    => __( 'Name of a content-type archive, such as “Products”', 'seo-fixer' ),
			'search'           => __( 'What the visitor searched for', 'seo-fixer' ),
			'page'             => __( '“Page 2 of 5” on later pages; empty on the first page', 'seo-fixer' ),
			'year'             => __( 'The current year', 'seo-fixer' ),
		);
	}

	/**
	 * Value of every variable for a context (used for the editor preview).
	 *
	 * @param Context $context Context.
	 * @return array<string, string>
	 */
	public static function values( Context $context ) {
		$values = array();
		foreach ( array_keys( self::definitions() ) as $name ) {
			$values[ $name ] = self::value( $name, $context );
		}
		return $values;
	}

	/**
	 * Replaces variables and tidies separators.
	 *
	 * Unknown %words% are left untouched, so text like "50% off" is safe.
	 *
	 * @param string  $template Template.
	 * @param Context $context  Context.
	 * @return string
	 */
	public static function replace( $template, Context $context ) {
		$known  = self::definitions();
		$result = preg_replace_callback(
			'/%([a-z_]+)%/',
			static function ( $match ) use ( $known, $context ) {
				return isset( $known[ $match[1] ] ) ? self::value( $match[1], $context ) : $match[0];
			},
			(string) $template
		);

		return self::cleanup( (string) $result, Templates::separator() );
	}

	/**
	 * Collapses spaces and repeated or dangling separators.
	 *
	 * "Title – – Site" → "Title – Site"; "– Site" → "Site".
	 *
	 * @param string $text      Text.
	 * @param string $separator Separator.
	 * @return string
	 */
	public static function cleanup( $text, $separator ) {
		$text = Text::squish( $text );
		if ( '' !== $separator ) {
			$sep  = preg_quote( $separator, '/' );
			$text = (string) preg_replace( '/(\s*' . $sep . '\s*){2,}/u', ' ' . $separator . ' ', $text );
			$text = (string) preg_replace( '/^\s*' . $sep . '\s*|\s*' . $sep . '\s*$/u', '', $text );
		}
		return trim( $text );
	}

	/**
	 * Value of one variable.
	 *
	 * @param string  $name    Variable name.
	 * @param Context $context Context.
	 * @return string
	 */
	public static function value( $name, Context $context ) {
		$object = $context->object;
		$post   = $object instanceof \WP_Post ? $object : null;
		$term   = $object instanceof \WP_Term ? $object : null;
		$user   = $object instanceof \WP_User ? $object : null;

		switch ( $name ) {
			case 'sitename':
				return Text::squish( wp_strip_all_tags( get_bloginfo( 'name' ) ) );
			case 'tagline':
				return Text::squish( wp_strip_all_tags( get_bloginfo( 'description' ) ) );
			case 'sep':
				return Templates::separator();
			case 'year':
				return wp_date( 'Y' );
			case 'title':
				return $post ? Text::squish( wp_strip_all_tags( $post->post_title ) ) : '';
			case 'excerpt':
				return $post ? self::excerpt( $post ) : '';
			case 'category':
				if ( ! $post ) {
					return '';
				}
				$categories = get_the_category( $post->ID );
				return $categories ? $categories[0]->name : '';
			case 'term':
				return $term ? $term->name : '';
			case 'term_description':
				return $term ? Text::summarize( Text::plain( $term->description ) ) : '';
			case 'author':
				if ( $user ) {
					return $user->display_name;
				}
				return $post ? (string) get_the_author_meta( 'display_name', (int) $post->post_author ) : '';
			case 'author_bio':
				$user_id = $user ? $user->ID : ( $post ? (int) $post->post_author : 0 );
				return $user_id ? Text::summarize( Text::plain( (string) get_the_author_meta( 'description', $user_id ) ) ) : '';
			case 'date':
				return self::date( $context );
			case 'archive_title':
				$type = get_post_type_object( $context->post_type );
				return $type ? $type->labels->name : '';
			case 'search':
				return Text::squish( get_search_query( false ) );
			case 'page':
				return $context->paged > 1
					/* translators: 1: current page number, 2: total number of pages. */
					? sprintf( __( 'Page %1$d of %2$d', 'seo-fixer' ), $context->paged, max( $context->paged, $context->max_pages ) )
					: '';
		}
		return '';
	}

	/**
	 * Manual excerpt, or the first sentences of the content.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function excerpt( \WP_Post $post ) {
		if ( post_password_required( $post ) ) {
			return '';
		}
		$source = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : $post->post_content;
		return Text::summarize( Text::plain( $source ) );
	}

	/**
	 * Publish date of a post, or the date of a date archive.
	 *
	 * @param Context $context Context.
	 * @return string
	 */
	private static function date( Context $context ) {
		if ( $context->object instanceof \WP_Post ) {
			return (string) get_the_date( '', $context->object );
		}
		if ( 'date' === $context->type ) {
			if ( is_day() ) {
				return (string) get_the_date();
			}
			if ( is_month() ) {
				return (string) get_the_date( 'F Y' );
			}
			if ( is_year() ) {
				return (string) get_the_date( 'Y' );
			}
		}
		return '';
	}
}
