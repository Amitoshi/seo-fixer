<?php
/**
 * Per-user interface preferences (Simple/Advanced mode, light/dark theme).
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes user-level preferences.
 */
final class Preferences {

	/**
	 * User meta keys by preference id.
	 */
	const META = array(
		'ui_mode'     => 'seofx_ui_mode',
		'admin_theme' => 'seofx_admin_theme',
	);

	/**
	 * Allowed values (first = default).
	 *
	 * @return array<string, string[]>
	 */
	public static function allowed() {
		return array(
			'ui_mode'     => array( 'simple', 'advanced' ),
			'admin_theme' => array( 'light', 'dark', 'auto' ),
		);
	}

	/**
	 * Returns a preference for the current user.
	 *
	 * @param string $id Preference id.
	 * @return string
	 */
	public static function get( $id ) {
		$allowed = self::allowed();
		if ( ! isset( $allowed[ $id ] ) ) {
			return '';
		}
		$value = (string) get_user_meta( get_current_user_id(), self::META[ $id ], true );
		return in_array( $value, $allowed[ $id ], true ) ? $value : $allowed[ $id ][0];
	}

	/**
	 * Stores a preference for the current user (ignores unknown values).
	 *
	 * @param string $id    Preference id.
	 * @param string $value Value.
	 * @return void
	 */
	public static function set( $id, $value ) {
		$allowed = self::allowed();
		if ( isset( $allowed[ $id ] ) && in_array( $value, $allowed[ $id ], true ) ) {
			update_user_meta( get_current_user_id(), self::META[ $id ], $value );
		}
	}

	/**
	 * Current interface mode.
	 *
	 * @return string simple|advanced
	 */
	public static function mode() {
		return self::get( 'ui_mode' );
	}

	/**
	 * Whether Advanced mode is on.
	 *
	 * @return bool
	 */
	public static function is_advanced() {
		return 'advanced' === self::mode();
	}

	/**
	 * Current admin theme.
	 *
	 * @return string light|dark|auto
	 */
	public static function theme() {
		return self::get( 'admin_theme' );
	}

	/**
	 * Handles the Simple/Advanced switch in the page header.
	 *
	 * @return void
	 */
	public static function handle_set_mode() {
		Security::require_manage();
		check_admin_referer( 'seofx_set_mode' );

		$mode = isset( $_POST['seofx_mode'] ) ? sanitize_key( wp_unslash( $_POST['seofx_mode'] ) ) : '';
		self::set( 'ui_mode', $mode );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . Admin::SLUG ) );
		exit;
	}
}
