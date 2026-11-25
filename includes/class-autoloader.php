<?php
/**
 * Class autoloader.
 *
 * Maps Seofx\Admin\Settings_Page to includes/admin/class-settings-page.php.
 *
 * @package SeoFixer
 */

namespace Seofx;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal autoloader for the Seofx namespace (no Composer needed).
 */
final class Autoloader {

	/**
	 * Registers the autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Loads a class file if it belongs to this plugin.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$name  = array_pop( $parts );
		$path  = SEOFX_DIR . 'includes/';

		foreach ( $parts as $part ) {
			$path .= self::to_file_part( $part ) . '/';
		}

		$file = $path . 'class-' . self::to_file_part( $name ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Converts Some_Name to some-name.
	 *
	 * @param string $part Namespace or class segment.
	 * @return string
	 */
	private static function to_file_part( $part ) {
		return strtolower( str_replace( '_', '-', $part ) );
	}
}
