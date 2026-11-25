<?php
/**
 * Decides the final title and description of a page.
 *
 * Order: what the user wrote for this item → the template for its type → defaults.
 *
 * @package SeoFixer
 */

namespace Seofx\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Title/description resolver.
 */
final class Resolver {

	/**
	 * Final SEO title.
	 *
	 * @param Context $context Context.
	 * @return string Plain text (not escaped).
	 */
	public static function title( Context $context ) {
		if ( $context->is_current() && isset( $context->memo['title'] ) ) {
			return $context->memo['title'];
		}
		$source = self::source( $context, 'title' );

		// Later pages of a list always say which page they are, so titles stay unique.
		if ( $context->paged > 1 && false === strpos( $source, '%page%' ) ) {
			$source .= ' %sep% %page%';
		}

		$title = Variables::replace( $source, $context );
		if ( '' === $title ) {
			$title = Variables::value( 'sitename', $context );
		}

		/**
		 * Filters the SEO title.
		 *
		 * @param string  $title   Title (plain text).
		 * @param Context $context Page context.
		 */
		$title = (string) apply_filters( 'seofx_title', $title, $context );
		if ( $context->is_current() ) {
			$context->memo['title'] = $title;
		}
		return $title;
	}

	/**
	 * Final meta description ('' = none).
	 *
	 * @param Context $context Context.
	 * @return string Plain text (not escaped).
	 */
	public static function description( Context $context ) {
		if ( $context->is_current() && isset( $context->memo['description'] ) ) {
			return $context->memo['description'];
		}
		$description = Variables::replace( self::source( $context, 'description' ), $context );

		/**
		 * Filters the meta description.
		 *
		 * @param string  $description Description (plain text).
		 * @param Context $context     Page context.
		 */
		$description = (string) apply_filters( 'seofx_description', $description, $context );
		if ( $context->is_current() ) {
			$context->memo['description'] = $description;
		}
		return $description;
	}

	/**
	 * The user's own text for this item, or the template for its type.
	 *
	 * @param Context $context Context.
	 * @param string  $field   title|description.
	 * @return string Template (may contain variables).
	 */
	public static function source( Context $context, $field ) {
		$custom = self::custom( $context, $field );
		return '' !== $custom ? $custom : Templates::get( Templates::key_for( $context, $field ) );
	}

	/**
	 * Text the user wrote for one post or term.
	 *
	 * @param Context $context Context.
	 * @param string  $field   title|description.
	 * @return string
	 */
	public static function custom( Context $context, $field ) {
		$key = 'title' === $field ? Post_Meta::TITLE : Post_Meta::DESCRIPTION;

		if ( $context->object instanceof \WP_Post ) {
			return trim( (string) get_post_meta( $context->object->ID, $key, true ) );
		}
		if ( $context->object instanceof \WP_Term ) {
			return trim( (string) get_term_meta( $context->object->term_id, $key, true ) );
		}
		return '';
	}
}
