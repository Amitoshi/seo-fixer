<?php
/**
 * Main plugin container.
 *
 * @package SeoFixer
 */

namespace Seofx;

defined( 'ABSPATH' ) || exit;

/**
 * Boots every module once, on plugins_loaded.
 */
final class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Conflict detector.
	 *
	 * @var Conflicts
	 */
	private $conflicts;

	/**
	 * Returns (and on first call boots) the plugin.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Wires up modules.
	 *
	 * @return void
	 */
	private function boot() {
		Installer::maybe_upgrade();

		$this->conflicts = new Conflicts();
		$this->conflicts->register();

		( new Meta\Post_Meta() )->register();
		( new Indexing\Sitemaps() )->register();

		// IndexNow (when switched on): key file, change queue on saves, and sending by WP-Cron.
		( new Indexing\IndexNow() )->register();
		( new Schema\Breadcrumbs() )->register();

		// Scan requests, background continuation and rescans when pages change.
		( new Scanner\Scanner() )->register();

		// Live checklist for the editors (REST, so it is registered outside the admin too).
		( new Editor\Live_Check() )->register();

		// Automatic fixes on publish/update (when switched on), and clean-up of old fix history.
		( new Fixes\Autofix() )->register();
		add_action( Installer::DAILY_HOOK, array( Fixes\History::class, 'purge' ) );

		// Redirects keep working while SEO tags are paused: they don't clash with other plugins' tags.
		( new Redirects\Watcher() )->register();
		if ( ! is_admin() ) {
			( new Redirects\Redirector() )->register();
		}

		if ( is_admin() ) {
			( new Admin\Admin( $this->conflicts ) )->register();
		}

		if ( ! is_admin() && self::output_enabled() ) {
			( new Frontend\Frontend() )->register();
			( new Frontend\Site_Tags() )->register();
			( new Indexing\Indexing() )->register();
			( new Indexing\Robots() )->register();

			if ( Options::get( 'og_enabled' ) ) {
				( new Frontend\Social() )->register();
			}
			if ( Options::get( 'schema_enabled' ) ) {
				( new Schema\Graph() )->register();
			}
		}
	}

	/**
	 * Returns the conflict detector.
	 *
	 * @return Conflicts
	 */
	public function conflicts() {
		return $this->conflicts;
	}

	/**
	 * Whether SEO Fixer may print anything on the front end.
	 *
	 * "auto" pauses output while another SEO plugin is active, so pages never
	 * get duplicate titles, meta tags or schema.
	 *
	 * @return bool
	 */
	public static function output_enabled() {
		$mode = Options::get( 'output_mode' );

		if ( 'off' === $mode ) {
			$enabled = false;
		} elseif ( 'on' === $mode ) {
			$enabled = true;
		} else {
			$enabled = ! self::instance()->conflicts()->has_conflicts();
		}

		/**
		 * Filters whether SEO Fixer adds its tags to the front end.
		 *
		 * @param bool $enabled Whether output is enabled.
		 */
		return (bool) apply_filters( 'seofx_output_enabled', $enabled );
	}
}
