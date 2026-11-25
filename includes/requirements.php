<?php
/**
 * Server requirements checked before the plugin loads.
 *
 * In its own file (not the main plugin file) so its text is picked up for translation.
 *
 * @package SeoFixer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the server has what SEO Fixer needs. If not, shows why in the admin.
 *
 * The PHP "mbstring" extension is needed for text in every language. Almost every
 * host has it; without it, the plugin explains the problem instead of failing on the
 * first non-English page.
 *
 * @return bool
 */
function seofx_requirements_met() {
	if ( function_exists( 'mb_strtolower' ) ) {
		return true;
	}
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'SEO Fixer needs the PHP “mbstring” extension, which is missing on this server. Your hosting provider can switch it on. Until then, SEO Fixer does nothing.', 'seo-fixer' ) . '</p></div>';
			}
		}
	);
	return false;
}
