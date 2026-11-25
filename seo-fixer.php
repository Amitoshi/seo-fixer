<?php
/**
 * Plugin Name:       SEO Fixer – Automatic SEO Audit & One-Click Fix
 * Description:       Simple, guided SEO for everyone: a clear health score, plain-English issues and one-click fixes. Light, fast and private.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Amit Jyoti Shaw
 * Author URI:        https://github.com/Amitoshi
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       seo-fixer
 * Domain Path:       /languages
 *
 * @package SeoFixer
 */

defined( 'ABSPATH' ) || exit;

define( 'SEOFX_VERSION', '1.0.0' );
define( 'SEOFX_FILE', __FILE__ );
define( 'SEOFX_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEOFX_URL', plugin_dir_url( __FILE__ ) );
define( 'SEOFX_BASENAME', plugin_basename( __FILE__ ) );

require_once SEOFX_DIR . 'includes/requirements.php';
if ( ! seofx_requirements_met() ) {
	return;
}

require_once SEOFX_DIR . 'includes/class-autoloader.php';
\Seofx\Autoloader::register();

register_activation_hook( __FILE__, array( 'Seofx\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Seofx\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Seofx\Plugin', 'instance' ) );
