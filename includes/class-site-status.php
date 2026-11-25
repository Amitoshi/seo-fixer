<?php
/**
 * Quick site-level checks shown on the dashboard and Tools page.
 *
 * These are the seed of the full Health scanner (M7a).
 *
 * @package SeoFixer
 */

namespace Seofx;

defined( 'ABSPATH' ) || exit;

/**
 * Cheap checks that need no scan.
 */
final class Site_Status {

	/**
	 * Runs all quick checks.
	 *
	 * Each item: id, tone (good|warn|bad), label, message, action_url, action_label.
	 *
	 * @return array[]
	 */
	public static function checks() {
		$checks = array(
			self::search_visibility(),
			self::https(),
			self::permalinks(),
			self::site_icon(),
			self::sitemap(),
			self::output(),
		);

		/**
		 * Filters the quick site checks.
		 *
		 * @param array[] $checks Check results.
		 */
		return (array) apply_filters( 'seofx_site_status_checks', $checks );
	}

	/**
	 * "Discourage search engines" setting.
	 *
	 * @return array
	 */
	private static function search_visibility() {
		$public = '0' !== (string) get_option( 'blog_public' );

		return array(
			'id'           => 'search_visibility',
			'tone'         => $public ? 'good' : 'bad',
			'label'        => __( 'Search engines can see your site', 'seo-fixer' ),
			'message'      => $public
				? __( 'Your site is open to Google, Bing and other search engines.', 'seo-fixer' )
				: __( 'Your site is set to “Discourage search engines”, so Google may not show it at all.', 'seo-fixer' ),
			'action_url'   => $public ? '' : admin_url( 'options-reading.php' ),
			'action_label' => $public ? '' : __( 'Open Reading settings', 'seo-fixer' ),
		);
	}

	/**
	 * HTTPS site address.
	 *
	 * @return array
	 */
	private static function https() {
		$secure = 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );

		return array(
			'id'           => 'https',
			'tone'         => $secure ? 'good' : 'bad',
			'label'        => __( 'Secure connection (HTTPS)', 'seo-fixer' ),
			'message'      => $secure
				? __( 'Your site address starts with https://.', 'seo-fixer' )
				: __( 'Your site address starts with http://. Browsers mark it “Not secure” and Google prefers HTTPS pages.', 'seo-fixer' ),
			'action_url'   => $secure ? '' : admin_url( 'site-health.php' ),
			'action_label' => $secure ? '' : __( 'Check Site Health', 'seo-fixer' ),
		);
	}

	/**
	 * Pretty permalinks.
	 *
	 * @return array
	 */
	private static function permalinks() {
		$pretty = '' !== (string) get_option( 'permalink_structure' );

		return array(
			'id'           => 'permalinks',
			'tone'         => $pretty ? 'good' : 'warn',
			'label'        => __( 'Readable page addresses', 'seo-fixer' ),
			'message'      => $pretty
				? __( 'Your page addresses contain words, not numbers.', 'seo-fixer' )
				: __( 'Your page addresses look like “?p=123”. Words in the address help people and search engines.', 'seo-fixer' ),
			'action_url'   => $pretty ? '' : admin_url( 'options-permalink.php' ),
			'action_label' => $pretty ? '' : __( 'Choose “Post name”', 'seo-fixer' ),
		);
	}

	/**
	 * Site icon (favicon shown next to results).
	 *
	 * @return array
	 */
	private static function site_icon() {
		$has_icon = has_site_icon();
		$url      = version_compare( get_bloginfo( 'version' ), '6.5', '>=' )
			? admin_url( 'options-general.php' )
			: admin_url( 'customize.php?autofocus[section]=title_tagline' );

		return array(
			'id'           => 'site_icon',
			'tone'         => $has_icon ? 'good' : 'warn',
			'label'        => __( 'Site icon', 'seo-fixer' ),
			'message'      => $has_icon
				? __( 'Google can show your icon next to your pages in results.', 'seo-fixer' )
				: __( 'No site icon yet. Google shows a generic globe next to your pages instead.', 'seo-fixer' ),
			'action_url'   => $has_icon ? '' : $url,
			'action_label' => $has_icon ? '' : __( 'Add a site icon', 'seo-fixer' ),
		);
	}

	/**
	 * XML sitemap.
	 *
	 * @return array
	 */
	private static function sitemap() {
		$enabled = Indexing\Sitemaps::enabled();

		return array(
			'id'           => 'sitemap',
			'tone'         => $enabled ? 'good' : 'warn',
			'label'        => __( 'XML sitemap', 'seo-fixer' ),
			'message'      => $enabled
				? __( 'Your sitemap is ready. Submit it once in Google Search Console so Google finds new pages faster.', 'seo-fixer' )
				: __( 'SEO Fixer’s sitemap is off or paused.', 'seo-fixer' ),
			'action_url'   => admin_url( 'admin.php?page=seo-fixer-settings&tab=sitemap' ),
			'action_label' => $enabled ? __( 'How to submit', 'seo-fixer' ) : __( 'Review setting', 'seo-fixer' ),
		);
	}

	/**
	 * Whether SEO Fixer output is running.
	 *
	 * @return array
	 */
	private static function output() {
		$enabled = Plugin::output_enabled();
		$names   = Plugin::instance()->conflicts()->names();

		if ( $enabled ) {
			$message = __( 'SEO Fixer is adding its tags to your pages.', 'seo-fixer' );
		} elseif ( Plugin::instance()->conflicts()->has_conflicts() && 'auto' === Options::get( 'output_mode' ) ) {
			$message = sprintf(
				/* translators: %s: names of other SEO plugins. */
				_n( 'Paused while %s is active, to avoid duplicate tags.', 'Paused while %s are active, to avoid duplicate tags.', count( Plugin::instance()->conflicts()->found() ), 'seo-fixer' ),
				$names
			);
		} else {
			$message = __( 'Paused in Settings. Your pages get no SEO tags from SEO Fixer.', 'seo-fixer' );
		}

		return array(
			'id'           => 'output',
			'tone'         => $enabled ? 'good' : 'warn',
			'label'        => __( 'SEO Fixer output', 'seo-fixer' ),
			'message'      => $message,
			'action_url'   => $enabled ? '' : admin_url( 'admin.php?page=seo-fixer-settings' ),
			'action_label' => $enabled ? '' : __( 'Review setting', 'seo-fixer' ),
		);
	}
}
