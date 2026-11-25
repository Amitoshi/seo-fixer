<?php
/**
 * Site verification tags and the Google Analytics / Tag Manager code.
 *
 * Nothing is printed until the site owner enters a code or an ID.
 *
 * @package SeoFixer
 */

namespace Seofx\Frontend;

use Seofx\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Prints verification meta tags (homepage only) and the analytics code.
 */
final class Site_Tags {

	/**
	 * Google's tag library (Google Analytics 4).
	 */
	const GTAG_URL = 'https://www.googletagmanager.com/gtag/js';

	/**
	 * Tag Manager's fallback for browsers without JavaScript.
	 */
	const GTM_NOSCRIPT_URL = 'https://www.googletagmanager.com/ns.html';

	/**
	 * Setting key => meta tag name each search engine looks for.
	 *
	 * @return array<string, string>
	 */
	public static function verification_tags() {
		return array(
			'verify_google'    => 'google-site-verification',
			'verify_bing'      => 'msvalidate.01',
			'verify_yandex'    => 'yandex-verification',
			'verify_pinterest' => 'p:domain_verify',
		);
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_head', array( $this, 'verification' ), 1 );

		if ( '' !== self::analytics_id() ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'analytics' ) );
			add_action( 'wp_body_open', array( $this, 'gtm_noscript' ) );
		}
	}

	/**
	 * Saved Google Analytics or Tag Manager ID ('' = none).
	 *
	 * @return string
	 */
	public static function analytics_id() {
		return (string) Options::get( 'analytics_id' );
	}

	/**
	 * Whether the ID is a Tag Manager container.
	 *
	 * @param string $id ID.
	 * @return bool
	 */
	public static function is_tag_manager( $id ) {
		return 0 === strpos( $id, 'GTM-' );
	}

	/**
	 * Prints the verification tags. Search engines only look at the homepage.
	 *
	 * @return void
	 */
	public function verification() {
		if ( ! is_front_page() ) {
			return;
		}
		foreach ( self::verification_tags() as $key => $name ) {
			$code = (string) Options::get( $key );
			if ( '' !== $code ) {
				printf( '<meta name="%s" content="%s" />' . "\n", esc_attr( $name ), esc_attr( $code ) );
			}
		}
	}

	/**
	 * Whether this visit should not be counted (the site's own editors, previews).
	 *
	 * @return bool
	 */
	private static function skip_visit() {
		if ( is_preview() || is_customize_preview() ) {
			return true;
		}
		return Options::get( 'analytics_skip_editors' ) && current_user_can( 'edit_posts' );
	}

	/**
	 * Adds the Google Analytics or Tag Manager code to the page.
	 *
	 * @return void
	 */
	public function analytics() {
		if ( self::skip_visit() ) {
			return;
		}

		$id    = self::analytics_id();
		$setup = "window.dataLayer = window.dataLayer || [];\nfunction gtag(){dataLayer.push(arguments);}\n";
		if ( Options::get( 'analytics_consent' ) ) {
			// Google Consent Mode: nothing is stored until a consent banner calls gtag('consent', 'update', …).
			$setup .= "gtag('consent', 'default', {ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied', wait_for_update: 500});\n";
		}

		if ( self::is_tag_manager( $id ) ) {
			wp_register_script( 'seofx-gtm', false, array(), SEOFX_VERSION, false );
			wp_enqueue_script( 'seofx-gtm' );
			wp_add_inline_script(
				'seofx-gtm',
				$setup . "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s);j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer'," . wp_json_encode( $id ) . ');'
			);
			return;
		}

		wp_enqueue_script(
			'seofx-gtag',
			add_query_arg( 'id', rawurlencode( $id ), self::GTAG_URL ),
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google's own script; a version parameter would only change its address.
			array(
				'strategy'  => 'async',
				'in_footer' => false,
			)
		);
		// "before", so the async loading strategy is kept.
		wp_add_inline_script( 'seofx-gtag', $setup . "gtag('js', new Date());\ngtag('config', " . wp_json_encode( $id ) . ');', 'before' );
	}

	/**
	 * Tag Manager's fallback for browsers without JavaScript (right after <body>).
	 *
	 * Left out with Consent Mode: those visitors can't answer a consent banner.
	 *
	 * @return void
	 */
	public function gtm_noscript() {
		$id = self::analytics_id();
		if ( ! self::is_tag_manager( $id ) || Options::get( 'analytics_consent' ) || self::skip_visit() ) {
			return;
		}
		printf(
			'<noscript><iframe src="%s" height="0" width="0" style="display:none;visibility:hidden" title="%s"></iframe></noscript>' . "\n",
			esc_url( add_query_arg( 'id', rawurlencode( $id ), self::GTM_NOSCRIPT_URL ) ),
			esc_attr__( 'Google Tag Manager', 'seo-fixer' )
		);
	}
}
