<?php
/**
 * Capability helpers.
 *
 * Nonces are always verified inline in each handler (so the check sits right
 * next to the input it protects); this class only answers "who may do this".
 *
 * @package SeoFixer
 */

namespace Seofx;

defined( 'ABSPATH' ) || exit;

/**
 * Central place for the capability that guards SEO Fixer screens.
 */
final class Security {

	/**
	 * Capability required to manage SEO Fixer.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability required to manage SEO Fixer.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		return (string) apply_filters( 'seofx_capability', 'manage_options' );
	}

	/**
	 * Whether the current user may manage SEO Fixer.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( self::capability() );
	}

	/**
	 * Stops the request when the current user lacks the capability.
	 *
	 * @return void
	 */
	public static function require_manage() {
		if ( ! self::can_manage() ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to change SEO Fixer settings. Please ask a site administrator.', 'seo-fixer' ),
				esc_html__( 'Not allowed', 'seo-fixer' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
	}
}
