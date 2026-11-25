<?php
/**
 * Virtual robots.txt (WordPress serves it; no file is ever written).
 *
 * @package SeoFixer
 */

namespace Seofx\Indexing;

use Seofx\Options;

defined( 'ABSPATH' ) || exit;

/**
 * robots.txt rules, preview and safety checks.
 */
final class Robots {

	/**
	 * Hooks (front end, while output is enabled).
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'robots_txt', array( __CLASS__, 'filter' ), 20, 2 );
	}

	/**
	 * What WordPress itself puts in robots.txt.
	 *
	 * @return string
	 */
	public static function wordpress_default() {
		$path = (string) wp_parse_url( site_url(), PHP_URL_PATH );
		return "User-agent: *\nDisallow: {$path}/wp-admin/\nAllow: {$path}/wp-admin/admin-ajax.php\n";
	}

	/**
	 * Builds robots.txt: custom rules (or WordPress's) plus the sitemap line.
	 *
	 * @param string $output WordPress's robots.txt.
	 * @param bool   $public Whether search engines are allowed (Settings → Reading).
	 * @return string
	 */
	public static function filter( $output, $public ) {
		// "Discourage search engines" is on: never loosen it.
		if ( ! $public ) {
			return $output;
		}

		$custom = trim( (string) Options::get( 'robots_custom' ) );
		if ( '' !== $custom ) {
			$output = $custom . "\n";
		}

		if ( Sitemaps::enabled() ) {
			$output = rtrim( (string) $output ) . "\n\nSitemap: " . Sitemaps::url() . "\n";
		}

		return $output;
	}

	/**
	 * robots.txt exactly as visitors will see it.
	 *
	 * @return string
	 */
	public static function preview() {
		$public = '0' !== (string) get_option( 'blog_public' );
		$output = $public ? self::wordpress_default() : "User-agent: *\nDisallow: /\n";

		// Run every plugin's robots_txt filter, ours included, like WordPress does.
		$filtered = has_filter( 'robots_txt', array( __CLASS__, 'filter' ) ) ? $output : self::filter( $output, $public );
		return (string) apply_filters( 'robots_txt', $filtered, $public ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook, applied to show exactly what WordPress will serve.
	}

	/**
	 * Whether a real robots.txt file exists (WordPress then can't change it).
	 *
	 * @return bool
	 */
	public static function physical_file_exists() {
		return file_exists( ABSPATH . 'robots.txt' );
	}

	/**
	 * Plain-English problems in a set of rules.
	 *
	 * @param string $rules robots.txt text.
	 * @return array[] Each: tone (bad|warn|info), message.
	 */
	public static function warnings( $rules ) {
		$warnings = array();
		$agents   = array();
		$in_rules = false;
		$known    = array( 'user-agent', 'disallow', 'allow', 'sitemap', 'crawl-delay', 'host', 'noindex', 'clean-param' );

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $rules ) as $number => $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( false === strpos( $line, ':' ) ) {
				$warnings[] = array(
					'tone'    => 'warn',
					/* translators: %d: line number. */
					'message' => sprintf( __( 'Line %d isn’t a valid rule. Rules look like “Disallow: /private/”.', 'seo-fixer' ), $number + 1 ),
				);
				continue;
			}

			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field                 = strtolower( $field );

			if ( ! in_array( $field, $known, true ) ) {
				$warnings[] = array(
					'tone'    => 'warn',
					/* translators: 1: line number, 2: the unknown rule name. */
					'message' => sprintf( __( 'Line %1$d: search engines don’t understand “%2$s”.', 'seo-fixer' ), $number + 1, $field ),
				);
				continue;
			}

			if ( 'user-agent' === $field ) {
				// A new group starts after rules; consecutive User-agent lines share one group.
				if ( $in_rules ) {
					$agents   = array();
					$in_rules = false;
				}
				$agents[] = strtolower( $value );
				continue;
			}
			$in_rules = true;

			$applies_to_google = in_array( '*', $agents, true ) || in_array( 'googlebot', $agents, true );

			if ( 'disallow' === $field && '/' === $value && $applies_to_google ) {
				$warnings[] = array(
					'tone'    => 'bad',
					'message' => __( '“Disallow: /” blocks your whole site from Google.', 'seo-fixer' ),
				);
			} elseif ( 'disallow' === $field && $applies_to_google && preg_match( '#^/(wp-content|wp-includes)/?$|\.(css|js)\$?$#i', $value ) ) {
				$warnings[] = array(
					'tone'    => 'warn',
					/* translators: %s: a robots.txt path such as /wp-content/. */
					'message' => sprintf( __( '“Disallow: %s” hides files Google needs to see your pages properly (images, styles or scripts).', 'seo-fixer' ), $value ),
				);
			} elseif ( 'crawl-delay' === $field ) {
				$warnings[] = array(
					'tone'    => 'info',
					'message' => __( 'Google ignores “Crawl-delay”. Bing and some other search engines use it.', 'seo-fixer' ),
				);
			} elseif ( 'noindex' === $field ) {
				$warnings[] = array(
					'tone'    => 'warn',
					'message' => __( 'Google doesn’t support “Noindex” in robots.txt. Hide pages with SEO Fixer’s “Show in search results” switch instead.', 'seo-fixer' ),
				);
			}
		}

		return $warnings;
	}

	/**
	 * Whether rules block the whole site for Google.
	 *
	 * @param string $rules robots.txt text.
	 * @return bool
	 */
	public static function blocks_everything( $rules ) {
		foreach ( self::warnings( $rules ) as $warning ) {
			if ( 'bad' === $warning['tone'] ) {
				return true;
			}
		}
		return false;
	}
}
