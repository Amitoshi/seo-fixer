<?php
/**
 * Admin bootstrap: menu, assets, body classes.
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Conflicts;
use Seofx\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every admin screen.
 */
final class Admin {

	/**
	 * Top-level menu slug.
	 */
	const SLUG = 'seo-fixer';

	/**
	 * Conflict detector.
	 *
	 * @var Conflicts
	 */
	private $conflicts;

	/**
	 * Settings screen.
	 *
	 * @var Settings_Page
	 */
	private $settings_page;

	/**
	 * Setup wizard.
	 *
	 * @var Wizard_Page
	 */
	private $wizard;

	/**
	 * Redirects screen.
	 *
	 * @var Redirects_Page
	 */
	private $redirects_page;

	/**
	 * Constructor.
	 *
	 * @param Conflicts $conflicts Conflict detector.
	 */
	public function __construct( Conflicts $conflicts ) {
		$registry             = new Settings_Registry();
		$this->conflicts      = $conflicts;
		$this->settings_page  = new Settings_Page( $registry );
		$this->wizard         = new Wizard_Page( $registry );
		$this->redirects_page = new Redirects_Page();
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_filter( 'plugin_action_links_' . SEOFX_BASENAME, array( $this, 'action_links' ) );
		add_action( 'admin_post_seofx_save_settings', array( $this->settings_page, 'handle_save' ) );
		add_action( 'admin_post_seofx_set_mode', array( Preferences::class, 'handle_set_mode' ) );
		add_action( 'admin_post_seofx_wizard', array( $this->wizard, 'handle_save' ) );
		add_action( 'admin_head', array( $this, 'hide_wizard_menu_item' ) );

		$this->redirects_page->register();
		Services_Panels::register();
		Import_Panel::register();
		( new Health_Report() )->register();
		( new Fix_Page() )->register();
		( new \Seofx\Editor\Sidebar() )->register();
		( new Setup_Notice() )->register();
		( new Meta_Box() )->register();
		( new Term_Fields() )->register();
	}

	/**
	 * Keeps the wizard reachable by URL but out of the menu.
	 *
	 * Runs on admin_head: WordPress has already checked access to the page by
	 * then, and has not drawn the menu yet.
	 *
	 * @return void
	 */
	public function hide_wizard_menu_item() {
		remove_submenu_page( self::SLUG, Wizard_Page::SLUG );
	}

	/**
	 * Adds the SEO Fixer menu.
	 *
	 * @return void
	 */
	public function menu() {
		$cap = Security::capability();

		add_menu_page(
			__( 'SEO Fixer', 'seo-fixer' ),
			__( 'SEO Fixer', 'seo-fixer' ),
			$cap,
			self::SLUG,
			array( new Dashboard_Page(), 'render' ),
			self::menu_icon(),
			81
		);

		// Renames the first submenu item; the top-level page above already renders it.
		add_submenu_page( self::SLUG, __( 'SEO Health', 'seo-fixer' ), __( 'SEO Health', 'seo-fixer' ), $cap, self::SLUG );
		add_submenu_page( self::SLUG, __( 'Redirects', 'seo-fixer' ), __( 'Redirects', 'seo-fixer' ), $cap, Redirects_Page::SLUG, array( $this->redirects_page, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'seo-fixer' ), __( 'Settings', 'seo-fixer' ), $cap, self::SLUG . '-settings', array( $this->settings_page, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Tools', 'seo-fixer' ), __( 'Tools', 'seo-fixer' ), $cap, self::SLUG . '-tools', array( new Tools_Page( $this->conflicts ), 'render' ) );
		add_submenu_page( self::SLUG, __( 'Setup', 'seo-fixer' ), __( 'Setup', 'seo-fixer' ), $cap, Wizard_Page::SLUG, array( $this->wizard, 'render' ) );

		if ( Styleguide_Page::enabled() ) {
			add_submenu_page( self::SLUG, __( 'Design system', 'seo-fixer' ), __( 'Design system', 'seo-fixer' ), $cap, self::SLUG . '-styleguide', array( new Styleguide_Page(), 'render' ) );
		}
	}

	/**
	 * Whether the current screen belongs to SEO Fixer.
	 *
	 * @return bool
	 */
	public static function is_our_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && false !== strpos( (string) $screen->id, self::SLUG );
	}

	/**
	 * Loads admin CSS/JS on SEO Fixer screens only.
	 *
	 * @return void
	 */
	public function assets() {
		wp_register_script( 'seofx-notices', SEOFX_URL . 'assets/admin/js/notices.js', array(), self::asset_version( 'assets/admin/js/notices.js' ), array( 'in_footer' => true ) );

		if ( ! self::is_our_screen() ) {
			return;
		}

		self::enqueue_assets();

		// The Media Library powers the logo picker (wizard and Settings → Your brand).
		$screen = get_current_screen();
		if ( false !== strpos( $screen->id, Wizard_Page::SLUG ) || false !== strpos( $screen->id, self::SLUG . '-settings' ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Enqueues the design system CSS and admin JS (our screens, editor, term screens).
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		wp_enqueue_style( 'seofx-admin', SEOFX_URL . 'assets/admin/css/admin.css', array(), self::asset_version( 'assets/admin/css/admin.css' ) );
		wp_enqueue_script(
			'seofx-admin',
			SEOFX_URL . 'assets/admin/js/admin.js',
			array( 'wp-i18n', 'wp-api-fetch' ),
			self::asset_version( 'assets/admin/js/admin.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_set_script_translations( 'seofx-admin', 'seo-fixer' );
	}

	/**
	 * Adds theme/mode classes so the design system can switch tokens.
	 *
	 * @param string $classes Space-separated classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		if ( ! self::is_our_screen() ) {
			return $classes;
		}
		return $classes . ' seofx-admin seofx-theme-' . Preferences::theme() . ' seofx-mode-' . Preferences::mode() . ' ';
	}

	/**
	 * Adds a "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ), esc_html__( 'Settings', 'seo-fixer' ) )
		);
		return $links;
	}

	/**
	 * Cache-busting version: file time while developing, plugin version otherwise.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string
	 */
	public static function asset_version( $relative ) {
		$file = SEOFX_DIR . $relative;
		return is_readable( $file ) ? SEOFX_VERSION . '.' . filemtime( $file ) : SEOFX_VERSION;
	}

	/**
	 * Menu icon as an SVG data URI (WordPress recolours it to match the admin scheme).
	 *
	 * @return string
	 */
	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M8.5 2a6.5 6.5 0 0 1 5.17 10.44l3.95 3.94a1 1 0 0 1-1.42 1.42l-3.94-3.95A6.5 6.5 0 1 1 8.5 2Zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9Zm2.2 2.3a1 1 0 0 1 .1 1.4l-2.5 2.8a1 1 0 0 1-1.46.04l-1.25-1.25a1 1 0 1 1 1.42-1.42l.5.5 1.8-1.97a1 1 0 0 1 1.4-.1Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes a static SVG icon for add_menu_page(), as WordPress requires.
	}
}
