<?php
/**
 * Front-end output.
 *
 * Only loaded when output is enabled. Adds no CSS or JavaScript to pages.
 *
 * @package SeoFixer
 */

namespace Seofx\Frontend;

use Seofx\Meta\Context;
use Seofx\Meta\Resolver;
use Seofx\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the SEO title and meta description.
 */
final class Frontend {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'document_title_separator', array( $this, 'title_separator' ) );
		add_filter( 'pre_get_document_title', array( $this, 'document_title' ), 20 );
		add_action( 'wp_head', array( $this, 'head' ), 1 );

		// Older themes print <title> with wp_title() instead of supporting title-tag.
		add_action( 'after_setup_theme', array( $this, 'legacy_title_support' ), 99 );
	}

	/**
	 * Uses the chosen separator wherever WordPress builds a title itself.
	 *
	 * @param string $separator Core separator.
	 * @return string
	 */
	public function title_separator( $separator ) {
		$chosen = (string) Options::get( 'title_separator' );
		return '' !== $chosen ? $chosen : $separator;
	}

	/**
	 * Replaces the document title (themes with title-tag support).
	 *
	 * @param string $title Title from earlier filters ('' by default).
	 * @return string Escaped title.
	 */
	public function document_title( $title ) {
		$context = Context::current();
		if ( 'other' === $context->type ) {
			return $title;
		}
		// pre_get_document_title is printed as-is by WordPress, so escape here.
		return esc_html( Resolver::title( $context ) );
	}

	/**
	 * Hooks wp_title for themes without title-tag support.
	 *
	 * @return void
	 */
	public function legacy_title_support() {
		if ( ! current_theme_supports( 'title-tag' ) ) {
			add_filter( 'wp_title', array( $this, 'legacy_title' ), 20 );
		}
	}

	/**
	 * Replaces wp_title() output.
	 *
	 * @param string $title Title built by WordPress.
	 * @return string Escaped title.
	 */
	public function legacy_title( $title ) {
		return $this->document_title( $title );
	}

	/**
	 * Prints the meta description.
	 *
	 * @return void
	 */
	public function head() {
		$context = Context::current();
		if ( 'other' === $context->type ) {
			return;
		}

		$description = Resolver::description( $context );
		if ( '' !== $description ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
		}
	}
}
