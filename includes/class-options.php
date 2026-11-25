<?php
/**
 * Settings storage.
 *
 * @package SeoFixer
 */

namespace Seofx;

defined( 'ABSPATH' ) || exit;

/**
 * All site-wide settings live in one autoloaded option, merged with defaults
 * at read time so new settings never need a migration.
 */
final class Options {

	/**
	 * Option name.
	 */
	const KEY = 'seofx_settings';

	/**
	 * Per-request cache of the merged settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default values for every setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		$defaults = array(
			// General.
			'site_type'               => 'business',
			'output_mode'             => 'auto',
			'title_separator'         => '-',
			'keep_data'               => true,
			'wizard_completed'        => 0,

			// Titles & descriptions (template keys like title_tpl_post default to Templates::default_for()).
			'home_title'              => '',
			'home_description'        => '',

			// Brand (used for Organization/Person schema in M5).
			'entity_type'             => 'organization',
			'entity_name'             => '',
			'entity_logo'             => 0,

			// Social profiles (schema sameAs, M5).
			'social_facebook'         => '',
			'social_instagram'        => '',
			'social_x'                => '',
			'social_linkedin'         => '',
			'social_youtube'          => '',
			'social_tiktok'           => '',
			'social_pinterest'        => '',
			'social_other'            => '',

			// Local business (LocalBusiness schema, M5).
			'local_business'          => false,
			'local_type'              => 'LocalBusiness',
			'local_street'            => '',
			'local_city'              => '',
			'local_region'            => '',
			'local_postcode'          => '',
			'local_country'           => Countries::guess_from_locale(),
			'local_phone'             => '',
			'local_hours'             => array(),

			// Schema & social.
			'og_enabled'              => true,
			'social_image'            => 0,
			'schema_enabled'          => true,
			'breadcrumbs_home_label'  => '',
			'breadcrumbs_separator'   => '›',

			// Best-practice defaults set by Presets (consumed from M4/M5).
			'noindex_search'          => true,
			'noindex_date_archives'   => true,
			'noindex_author_archives' => true,
			'attachment_redirect'     => true,
			'article_type'            => 'Article',

			// Redirects & 404 log.
			'redirect_slug_changes'   => true,
			'trash_notice'            => true,
			'log_404'                 => true,
			'log_404_days'            => 30,
			'log_404_max'             => 1000,
			'log_404_ignore_probes'   => true,

			// Sitemaps & robots.txt (index_pt_{type} / index_tax_{tax} default to "shown").
			'sitemap_enabled'         => true,
			'sitemap_images'          => true,
			'robots_custom'           => '',

			// Automatic fixes when pages are published or updated (off until the owner opts in).
			'autofix_description'     => false,
			'autofix_title'           => false,
			'autofix_alt'             => false,

			// Search engines & analytics.
			'verify_google'           => '',
			'verify_bing'             => '',
			'verify_yandex'           => '',
			'verify_pinterest'        => '',
			'analytics_id'            => '',
			'analytics_skip_editors'  => true,
			'analytics_consent'       => false,
			'indexnow_enabled'        => false,
		);

		/**
		 * Filters the default settings.
		 *
		 * @param array $defaults Default settings.
		 */
		return apply_filters( 'seofx_default_settings', $defaults );
	}

	/**
	 * Returns all settings.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::KEY, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	/**
	 * Returns one setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Merges already-sanitized values into the stored settings.
	 *
	 * @param array $values Key => value pairs.
	 * @return void
	 */
	public static function update( array $values ) {
		$saved = get_option( self::KEY, array() );
		$saved = is_array( $saved ) ? $saved : array();

		update_option( self::KEY, array_merge( $saved, $values ), true );
		self::$cache = null;
	}

	/**
	 * Creates the option with defaults if it does not exist yet.
	 *
	 * @return void
	 */
	public static function install() {
		if ( false === get_option( self::KEY ) ) {
			add_option( self::KEY, self::defaults(), '', true );
		}
		self::$cache = null;
	}
}
