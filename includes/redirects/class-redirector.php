<?php
/**
 * Front end: sends visitors from old addresses to new ones, answers "gone" (410)
 * for removed pages, and logs addresses that were not found.
 *
 * @package SeoFixer
 */

namespace Seofx\Redirects;

use Seofx\Installer;
use Seofx\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Redirect handler.
 */
final class Redirector {

	/**
	 * Whether the current request matched a 410 rule.
	 *
	 * @var bool
	 */
	private $gone = false;

	/**
	 * Hooks. Redirects are matched before WordPress runs its main query, so a
	 * redirected request costs one indexed lookup and nothing else.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'parse_request', array( $this, 'maybe_redirect' ), 0 );
		add_action( 'wp', array( $this, 'maybe_gone' ) );
		add_action( 'template_redirect', array( $this, 'maybe_log_404' ), 99 );
		add_action( Installer::DAILY_HOOK, array( Log_404::class, 'purge' ) );
	}

	/**
	 * Whether a request is one we should never redirect or log.
	 *
	 * @param \WP|null $wp Request.
	 * @return bool
	 */
	private static function skip( $wp = null ) {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return true;
		}
		if ( $wp instanceof \WP && ( ! empty( $wp->query_vars['rest_route'] ) || 0 === strpos( (string) $wp->request, rest_get_url_prefix() ) ) ) {
			return true;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		return ! in_array( $method, array( 'GET', 'HEAD' ), true );
	}

	/**
	 * Redirects the request when a rule matches.
	 *
	 * @param \WP $wp Request.
	 * @return void
	 */
	public function maybe_redirect( $wp ) {
		if ( ! Store::has_any() || self::skip( $wp ) ) {
			return;
		}

		$path = Paths::current();
		$rule = '' !== $path ? Store::match( $path ) : null;
		if ( ! $rule ) {
			return;
		}

		Store::hit( (int) $rule['id'] );

		if ( 410 === (int) $rule['status'] ) {
			$this->gone = true; // Answered after the main query, so the theme's 404 page is shown.
			return;
		}

		$target = Paths::absolute( $rule['target_url'] );
		if ( Paths::normalize( $target ) === $path ) {
			return; // Never redirect a page to itself.
		}

		// wp_redirect, not wp_safe_redirect: the site owner may redirect to another domain on purpose.
		wp_redirect( $target, (int) $rule['status'], 'SEO Fixer' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Target chosen by an administrator, may be another domain.
		exit;
	}

	/**
	 * Shows the "not found" page with status 410 Gone.
	 *
	 * @return void
	 */
	public function maybe_gone() {
		if ( ! $this->gone ) {
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 410 );
		nocache_headers();
	}

	/**
	 * Logs addresses that were not found.
	 *
	 * @return void
	 */
	public function maybe_log_404() {
		if ( ! is_404() || $this->gone || ! Options::get( 'log_404' ) || self::skip() ) {
			return;
		}

		// Without the query string, so "?utm_source=…" variants of one missing page are counted together.
		$path = Paths::without_query( Paths::current() );
		if ( '' === $path || ( Options::get( 'log_404_ignore_probes' ) && Log_404::is_probe( $path ) ) ) {
			return;
		}

		// Only the linking page's address: its query string may hold personal data (search terms, e-mail links).
		$referrer = (string) wp_get_raw_referer();
		$referrer = preg_replace( '/[?#].*$/s', '', $referrer );
		Log_404::record( $path, '' !== $referrer ? esc_url_raw( $referrer ) : '' );
	}
}
