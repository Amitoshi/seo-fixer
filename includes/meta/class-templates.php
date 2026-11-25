<?php
/**
 * Title and description templates, with built-in defaults.
 *
 * Stored option keys:
 * - home_title / home_description            Homepage (when it lists latest posts).
 * - title_tpl_{post_type} / desc_tpl_{…}     Posts, pages, products…
 * - title_tpl_tax_{taxonomy} / desc_tpl_…    Categories, tags…
 * - title_tpl_{author|date|search|404|archive} and desc_tpl_{author|archive}.
 *
 * An empty stored value always means "use the default".
 *
 * @package SeoFixer
 */

namespace Seofx\Meta;

use Seofx\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Template lookup.
 */
final class Templates {

	/**
	 * Separator as shown to people ("-" is displayed as an en dash, like WordPress does).
	 *
	 * @return string
	 */
	public static function separator() {
		$separator = (string) Options::get( 'title_separator' );
		if ( '' === $separator || '-' === $separator ) {
			$separator = '–';
		}
		return $separator;
	}

	/**
	 * Option key holding the template for a context.
	 *
	 * @param Context $context Context.
	 * @param string  $field   title|description.
	 * @return string
	 */
	public static function key_for( Context $context, $field ) {
		$prefix = 'title' === $field ? 'title_tpl_' : 'desc_tpl_';

		switch ( $context->type ) {
			case 'home':
				return 'title' === $field ? 'home_title' : 'home_description';
			case 'singular':
				return $prefix . $context->object->post_type;
			case 'term':
				return $prefix . 'tax_' . $context->object->taxonomy;
			default:
				return $prefix . $context->type;
		}
	}

	/**
	 * Stored template, or the default when empty.
	 *
	 * @param string $key Option key.
	 * @return string
	 */
	public static function get( $key ) {
		$stored = Options::get( $key );
		return ( is_string( $stored ) && '' !== trim( $stored ) ) ? $stored : self::default_for( $key );
	}

	/**
	 * Built-in default for a template key.
	 *
	 * @param string $key Option key.
	 * @return string
	 */
	public static function default_for( $key ) {
		$fixed = array(
			'home_title'       => '%sitename% %sep% %tagline%',
			'home_description' => '%tagline%',
			'title_tpl_author' => '%author% %sep% %page% %sep% %sitename%',
			'desc_tpl_author'  => '%author_bio%',
			'title_tpl_date'   => '%date% %sep% %page% %sep% %sitename%',
			/* translators: %s: what the visitor searched for. */
			'title_tpl_search' => sprintf( __( 'Search results for “%s”', 'seo-fixer' ), '%search%' ) . ' %sep% %sitename%',
			'title_tpl_404'    => __( 'Page not found', 'seo-fixer' ) . ' %sep% %sitename%',
			'title_tpl_archive' => '%archive_title% %sep% %page% %sep% %sitename%',
		);

		if ( isset( $fixed[ $key ] ) ) {
			return $fixed[ $key ];
		}
		if ( 0 === strpos( $key, 'title_tpl_tax_' ) ) {
			return '%term% %sep% %page% %sep% %sitename%';
		}
		if ( 0 === strpos( $key, 'desc_tpl_tax_' ) ) {
			return '%term_description%';
		}
		if ( 0 === strpos( $key, 'title_tpl_' ) ) {
			return '%title% %sep% %page% %sep% %sitename%';
		}
		if ( 0 === strpos( $key, 'desc_tpl_' ) && ! in_array( $key, array( 'desc_tpl_date', 'desc_tpl_search', 'desc_tpl_404', 'desc_tpl_archive' ), true ) ) {
			return '%excerpt%';
		}
		return '';
	}

	/**
	 * Public post types that get SEO fields (attachments excluded).
	 *
	 * @return \WP_Post_Type[]
	 */
	public static function post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );

		/**
		 * Filters the post types SEO Fixer adds titles and descriptions to.
		 *
		 * @param \WP_Post_Type[] $types Post type objects keyed by name.
		 */
		return (array) apply_filters( 'seofx_post_types', $types );
	}

	/**
	 * Public taxonomies that get SEO fields (post formats excluded).
	 *
	 * @return \WP_Taxonomy[]
	 */
	public static function taxonomies() {
		$taxonomies = get_taxonomies(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);
		unset( $taxonomies['post_format'] );

		/**
		 * Filters the taxonomies SEO Fixer adds titles and descriptions to.
		 *
		 * @param \WP_Taxonomy[] $taxonomies Taxonomy objects keyed by name.
		 */
		return (array) apply_filters( 'seofx_taxonomies', $taxonomies );
	}
}
