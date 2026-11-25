<?php
/**
 * Site-wide checks.
 *
 * Most read settings. Three need the page as visitors get it (title tags, main
 * headings, insecure files), so the homepage and one recent post are fetched
 * from this same site — a "loopback" request like WordPress's Site Health
 * makes. Nothing is sent to any other server.
 *
 * @package SeoFixer
 */

namespace Seofx\Scanner;

use Seofx\Indexing\Indexing;
use Seofx\Indexing\Robots;
use Seofx\Indexing\Sitemaps;
use Seofx\Meta\Context;
use Seofx\Meta\Resolver;
use Seofx\Options;
use Seofx\Plugin;
use Seofx\Presets;
use Seofx\Redirects\Log_404;

defined( 'ABSPATH' ) || exit;

/**
 * Site checks.
 */
final class Site_Checks {

	/**
	 * Option set when the owner confirms the sitemap was submitted.
	 */
	const SUBMITTED_OPTION = 'seofx_sitemap_submitted';

	/**
	 * Runs every site check.
	 *
	 * Each result: pass (true, false, or null when it couldn't be checked),
	 * message (plain English), action_url, action_label.
	 *
	 * @return array<string, array>
	 */
	public static function run() {
		$pages   = self::fetch_samples();
		$results = array(
			'search_visibility'    => self::search_visibility(),
			'homepage_noindex'     => self::homepage_noindex(),
			'robots_blocks'        => self::robots(),
			'output_paused'        => self::output(),
			'https'                => self::https(),
			'mixed_content'        => self::mixed_content( $pages ),
			'permalinks'           => self::permalinks(),
			'homepage_description' => self::homepage_description(),
			'title_tags'           => self::title_tags( $pages ),
			'local_incomplete'     => self::local_business(),
			'theme_h1'             => self::theme_h1( $pages ),
			'sitemap'              => self::sitemap(),
			'sitemap_submitted'    => self::sitemap_submitted(),
			'site_icon'            => self::site_icon(),
			'logo'                 => self::logo(),
			'not_found'            => self::not_found(),
		);

		return array_filter( $results );
	}

	/**
	 * Re-runs one quick check (used after the owner marks a reminder done).
	 *
	 * @param string $id Check id.
	 * @return array
	 */
	public static function run_one( $id ) {
		return 'sitemap_submitted' === $id ? self::sitemap_submitted() : array();
	}

	/**
	 * Result helper.
	 *
	 * @param bool|null $pass         Outcome (null = couldn't check, or doesn't apply).
	 * @param string    $message      Plain-English explanation.
	 * @param string    $action_url   Where to fix it.
	 * @param string    $action_label Button label.
	 * @return array
	 */
	private static function result( $pass, $message, $action_url = '', $action_label = '' ) {
		return array(
			'pass'         => $pass,
			'message'      => $message,
			'action_url'   => $action_url,
			'action_label' => $action_label,
		);
	}

	/**
	 * Settings screen URL.
	 *
	 * @param string $tab Tab id.
	 * @return string
	 */
	private static function settings( $tab ) {
		return admin_url( 'admin.php?page=seo-fixer-settings&tab=' . $tab );
	}

	/**
	 * Fetches the homepage and the newest post as a visitor would see them.
	 *
	 * @return array<string, string> URL => HTML (failed fetches are left out).
	 */
	private static function fetch_samples() {
		$urls   = array( home_url( '/' ) );
		$latest = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'has_password'   => false,
				'fields'         => 'ids',
			)
		);
		if ( $latest ) {
			$urls[] = (string) get_permalink( $latest[0] );
		}

		$pages = array();
		foreach ( $urls as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 10,
					'redirection' => 3,
					// Same as WordPress's own loopback requests: local certificates are often self-signed.
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter for loopback requests.
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				break; // The site can't reach itself; don't wait for a second timeout.
			}
			if ( 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$pages[ $url ] = (string) wp_remote_retrieve_body( $response );
			}
		}
		return $pages;
	}

	/**
	 * Settings → Reading → "Discourage search engines".
	 *
	 * @return array
	 */
	private static function search_visibility() {
		$public = '0' !== (string) get_option( 'blog_public' );
		return self::result(
			$public,
			$public ? __( 'Search engines are allowed to show your site.', 'seo-fixer' ) : __( 'Your whole site is set to “Discourage search engines”, so Google may not show it at all.', 'seo-fixer' ),
			$public ? '' : admin_url( 'options-reading.php' ),
			$public ? '' : __( 'Open Reading settings', 'seo-fixer' )
		);
	}

	/**
	 * Homepage hidden from search.
	 *
	 * @return array
	 */
	private static function homepage_noindex() {
		$front = 'page' === get_option( 'show_on_front' ) ? get_post( (int) get_option( 'page_on_front' ) ) : null;
		if ( ! $front ) {
			return self::result( true, __( 'Your homepage can appear in search results.', 'seo-fixer' ) );
		}
		$shown = Indexing::post_indexable( $front );
		return self::result(
			$shown,
			$shown ? __( 'Your homepage can appear in search results.', 'seo-fixer' ) : __( 'Your homepage is set to “hide from search results”.', 'seo-fixer' ),
			$shown ? '' : (string) get_edit_post_link( $front->ID, 'raw' ),
			$shown ? '' : __( 'Edit homepage', 'seo-fixer' )
		);
	}

	/**
	 * robots.txt blocking everything, or a physical file in the way.
	 *
	 * @return array
	 */
	private static function robots() {
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			return array(); // Already reported by search_visibility.
		}
		$blocks = Robots::blocks_everything( Robots::preview() );
		return self::result(
			! $blocks,
			$blocks ? __( 'Your robots.txt blocks Google from your whole site.', 'seo-fixer' ) : __( 'robots.txt lets search engines visit your pages.', 'seo-fixer' ),
			$blocks ? self::settings( 'sitemap' ) : '',
			$blocks ? __( 'Review robots.txt', 'seo-fixer' ) : ''
		);
	}

	/**
	 * SEO Fixer paused.
	 *
	 * @return array
	 */
	private static function output() {
		$on = Plugin::output_enabled();
		return self::result(
			$on,
			$on ? __( 'SEO Fixer is adding its titles and tags to your pages.', 'seo-fixer' ) : __( 'SEO Fixer is paused, so your pages don’t get its titles, descriptions and schema. The results below show what you’d get once it’s on.', 'seo-fixer' ),
			$on ? '' : self::settings( 'general' ),
			$on ? '' : __( 'Review setting', 'seo-fixer' )
		);
	}

	/**
	 * HTTPS address.
	 *
	 * @return array
	 */
	private static function https() {
		$secure = 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
		return self::result(
			$secure,
			$secure ? __( 'Your site uses a secure connection.', 'seo-fixer' ) : __( 'Your site address starts with http://. Browsers mark it “Not secure” and Google prefers secure pages. Ask your host for a free SSL certificate.', 'seo-fixer' ),
			$secure ? '' : admin_url( 'site-health.php' ),
			$secure ? '' : __( 'Check Site Health', 'seo-fixer' )
		);
	}

	/**
	 * Images, scripts or styles loaded over http:// on an https site.
	 *
	 * @param array<string, string> $pages Fetched pages.
	 * @return array
	 */
	private static function mixed_content( array $pages ) {
		if ( 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
			return array(); // Only applies to secure sites.
		}
		if ( array() === $pages ) {
			return self::result( null, __( 'We couldn’t load your homepage to check for insecure files.', 'seo-fixer' ) );
		}
		$found = array();
		foreach ( $pages as $html ) {
			if ( preg_match_all( '/<(?:img|script|iframe|source|video|audio|embed)\b[^>]*\ssrc=["\'](http:\/\/[^"\']+)|<link\b[^>]*rel=["\']stylesheet["\'][^>]*href=["\'](http:\/\/[^"\']+)/i', $html, $matches ) ) {
				$found = array_merge( $found, array_filter( array_merge( $matches[1], $matches[2] ) ) );
			}
		}
		$found = array_values( array_unique( $found ) );
		return self::result(
			array() === $found,
			array() === $found
				? __( 'All files on your pages load securely.', 'seo-fixer' )
				/* translators: 1: number of files, 2: first file address. */
				: sprintf( _n( '%1$d file loads over an insecure connection (for example %2$s), so browsers may block it.', '%1$d files load over an insecure connection (for example %2$s), so browsers may block them.', count( $found ), 'seo-fixer' ), count( $found ), $found[0] )
		);
	}

	/**
	 * Pretty permalinks.
	 *
	 * @return array
	 */
	private static function permalinks() {
		$pretty = '' !== (string) get_option( 'permalink_structure' );
		return self::result(
			$pretty,
			$pretty ? __( 'Your page addresses contain words.', 'seo-fixer' ) : __( 'Your page addresses look like “?p=123”. Choose “Post name” so they describe the page.', 'seo-fixer' ),
			$pretty ? '' : admin_url( 'options-permalink.php' ),
			$pretty ? '' : __( 'Open Permalink settings', 'seo-fixer' )
		);
	}

	/**
	 * Homepage meta description.
	 *
	 * @return array
	 */
	private static function homepage_description() {
		$front   = 'page' === get_option( 'show_on_front' ) ? get_post( (int) get_option( 'page_on_front' ) ) : null;
		$context = $front ? Context::for_post( $front ) : Context::for_home();
		$ok      = '' !== Resolver::description( $context );
		return self::result(
			$ok,
			$ok ? __( 'Your homepage has a description.', 'seo-fixer' ) : __( 'Your homepage has no description, so Google picks some text itself. This is often the first thing people read about you.', 'seo-fixer' ),
			$ok ? '' : ( $front ? (string) get_edit_post_link( $front->ID, 'raw' ) : self::settings( 'titles' ) ),
			$ok ? '' : __( 'Write a description', 'seo-fixer' )
		);
	}

	/**
	 * Exactly one <title> per page (themes sometimes add their own).
	 *
	 * @param array<string, string> $pages Fetched pages.
	 * @return array
	 */
	private static function title_tags( array $pages ) {
		if ( array() === $pages ) {
			return self::result( null, __( 'We couldn’t load your homepage to check its title.', 'seo-fixer' ) );
		}
		foreach ( $pages as $url => $html ) {
			$count = preg_match_all( '/<title[\s>]/i', (string) preg_replace( '#<svg\b.*?</svg>#is', '', $html ) );
			if ( 1 !== $count ) {
				return self::result(
					false,
					0 === $count
						? __( 'A page has no title tag. Your theme may not support titles properly.', 'seo-fixer' )
						/* translators: %s: page address. */
						: sprintf( __( 'A page has more than one title tag (%s). Your theme or another plugin adds its own.', 'seo-fixer' ), $url )
				);
			}
		}
		return self::result( true, __( 'Each page has exactly one title.', 'seo-fixer' ) );
	}

	/**
	 * One H1 per page, as rendered by the theme.
	 *
	 * @param array<string, string> $pages Fetched pages.
	 * @return array
	 */
	private static function theme_h1( array $pages ) {
		if ( array() === $pages ) {
			return self::result( null, __( 'We couldn’t load your pages to check their headings.', 'seo-fixer' ) );
		}
		foreach ( $pages as $url => $html ) {
			$count = preg_match_all( '/<h1[\s>]/i', $html );
			if ( 0 === $count ) {
				/* translators: %s: page address. */
				return self::result( false, sprintf( __( 'Your theme shows no main heading (H1) on %s. Search engines use it to understand what a page is about.', 'seo-fixer' ), $url ) );
			}
			if ( $count > 1 ) {
				/* translators: 1: number of headings, 2: page address. */
				return self::result( false, sprintf( __( '%2$s has %1$d main headings (H1). One clear main heading per page is best.', 'seo-fixer' ), $count, $url ) );
			}
		}
		return self::result( true, __( 'Your pages have one main heading each.', 'seo-fixer' ) );
	}

	/**
	 * Local business with incomplete address.
	 *
	 * @return array
	 */
	private static function local_business() {
		if ( ! Options::get( 'local_business' ) || ! Presets::is_local_type( (string) Options::get( 'site_type' ) ) ) {
			return array();
		}
		$missing = array();
		foreach ( array(
			'local_street'  => __( 'street', 'seo-fixer' ),
			'local_city'    => __( 'city', 'seo-fixer' ),
			'local_country' => __( 'country', 'seo-fixer' ),
			'local_phone'   => __( 'phone', 'seo-fixer' ),
		) as $key => $label ) {
			if ( '' === trim( (string) Options::get( $key ) ) ) {
				$missing[] = $label;
			}
		}
		return self::result(
			array() === $missing,
			array() === $missing
				? __( 'Your business address and phone are complete.', 'seo-fixer' )
				/* translators: %s: list of missing details, e.g. "street and phone". */
				: sprintf( __( 'Your local business details are missing the %s, which Google needs for local results.', 'seo-fixer' ), wp_sprintf( '%l', $missing ) ),
			array() === $missing ? '' : self::settings( 'local' ),
			array() === $missing ? '' : __( 'Add details', 'seo-fixer' )
		);
	}

	/**
	 * Sitemap on.
	 *
	 * @return array
	 */
	private static function sitemap() {
		$on = Sitemaps::enabled();
		return self::result(
			$on,
			$on ? __( 'Your XML sitemap is ready.', 'seo-fixer' ) : __( 'SEO Fixer’s sitemap is off, so search engines may find new pages more slowly.', 'seo-fixer' ),
			$on ? '' : self::settings( 'sitemap' ),
			$on ? '' : __( 'Review setting', 'seo-fixer' )
		);
	}

	/**
	 * Reminder to submit the sitemap (the owner confirms it once).
	 *
	 * @return array
	 */
	private static function sitemap_submitted() {
		if ( ! Sitemaps::enabled() ) {
			return array();
		}
		$done = (bool) get_option( self::SUBMITTED_OPTION );
		return self::result(
			$done,
			$done ? __( 'You’ve sent your sitemap to Google.', 'seo-fixer' ) : __( 'Send your sitemap to Google Search Console once, so Google finds new pages faster. Mark this done when you have.', 'seo-fixer' ),
			$done ? '' : self::settings( 'sitemap' ),
			$done ? '' : __( 'Show me how', 'seo-fixer' )
		);
	}

	/**
	 * Site icon.
	 *
	 * @return array
	 */
	private static function site_icon() {
		$has = has_site_icon();
		return self::result(
			$has,
			$has ? __( 'Your site icon appears next to your pages in Google.', 'seo-fixer' ) : __( 'No site icon yet, so Google shows a plain globe next to your pages.', 'seo-fixer' ),
			$has ? '' : admin_url( version_compare( get_bloginfo( 'version' ), '6.5', '>=' ) ? 'options-general.php' : 'customize.php' ),
			$has ? '' : __( 'Add a site icon', 'seo-fixer' )
		);
	}

	/**
	 * Logo for Organization schema.
	 *
	 * @return array
	 */
	private static function logo() {
		if ( 'person' === Options::get( 'entity_type' ) ) {
			return array();
		}
		$has = (int) Options::get( 'entity_logo' ) > 0;
		return self::result(
			$has,
			$has ? __( 'Your logo is set for search results.', 'seo-fixer' ) : __( 'No logo set, so Google can’t show it with your business.', 'seo-fixer' ),
			$has ? '' : self::settings( 'brand' ),
			$has ? '' : __( 'Add your logo', 'seo-fixer' )
		);
	}

	/**
	 * Missing pages that people keep trying.
	 *
	 * @return array
	 */
	private static function not_found() {
		if ( ! Options::get( 'log_404' ) ) {
			return array();
		}
		$count = Log_404::count();
		return self::result(
			0 === $count,
			0 === $count
				? __( 'No missing pages have been requested recently.', 'seo-fixer' )
				/* translators: %d: number of addresses. */
				: sprintf( _n( '%d address that doesn’t exist was requested recently. Redirect it if it should lead somewhere.', '%d addresses that don’t exist were requested recently. Redirect the ones that should lead somewhere.', $count, 'seo-fixer' ), $count ),
			0 === $count ? '' : admin_url( 'admin.php?page=seo-fixer-redirects&view=404' ),
			0 === $count ? '' : __( 'Review missing pages', 'seo-fixer' )
		);
	}
}
