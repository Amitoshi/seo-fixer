<?php
/**
 * Extra content and checks for Settings → Search engines & analytics:
 * verification guides, analytics warnings and the IndexNow status.
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Frontend\Site_Tags;
use Seofx\Indexing\IndexNow;
use Seofx\Options;
use Seofx\Plugin;
use Seofx\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Settings panels for verification, analytics and IndexNow.
 */
final class Services_Panels {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_post_seofx_indexnow_test', array( __CLASS__, 'handle_test' ) );
	}

	/**
	 * Address of this settings tab.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'admin.php?page=' . Admin::SLUG . '-settings&tab=services' );
	}

	/**
	 * Shows why nothing is added to pages while SEO Fixer is paused.
	 *
	 * @return bool Whether output is paused.
	 */
	private static function paused_notice() {
		if ( Plugin::output_enabled() ) {
			return false;
		}
		UI::callout( __( 'SEO Fixer is paused because another SEO plugin is active, so these codes are not added to your pages yet.', 'seo-fixer' ), 'warn' );
		return true;
	}

	/**
	 * Verification: where to find each code.
	 *
	 * @return void
	 */
	public static function verification() {
		self::paused_notice();

		$guides = array(
			array(
				__( 'Where do I find my Google code?', 'seo-fixer' ),
				__( 'In Google Search Console, add your site as a “URL prefix” property. Under “Other verification methods”, choose “HTML tag”, copy the tag and paste it into the Google box. Save here first, then click “Verify” in Search Console.', 'seo-fixer' ),
				'https://search.google.com/search-console',
				__( 'Open Google Search Console', 'seo-fixer' ),
			),
			array(
				__( 'Where do I find my Bing code?', 'seo-fixer' ),
				__( 'In Bing Webmaster Tools, add your site and choose “HTML Meta Tag”. Copy the tag and paste it into the Bing box. If your site is already in Google Search Console, you can import it into Bing instead and skip this step.', 'seo-fixer' ),
				'https://www.bing.com/webmasters',
				__( 'Open Bing Webmaster Tools', 'seo-fixer' ),
			),
			array(
				__( 'Where do I find my Yandex or Pinterest code?', 'seo-fixer' ),
				__( 'Yandex Webmaster: add your site and choose “Meta tag”. Pinterest: open Settings → Claimed accounts, click “Claim” next to Websites and choose “Add HTML tag”. Copy the tag and paste it into the matching box.', 'seo-fixer' ),
				'',
				'',
			),
		);
		?>
		<p class="seofx-guide-intro"><?php esc_html_e( 'The codes are only added to your homepage, which is where search engines look for them. You can paste the code on its own or the whole tag.', 'seo-fixer' ); ?></p>
		<?php foreach ( $guides as $guide ) : ?>
			<details class="seofx-guide">
				<summary><?php echo esc_html( $guide[0] ); ?></summary>
				<p><?php echo esc_html( $guide[1] ); ?></p>
				<?php if ( '' !== $guide[2] ) : ?>
					<a class="seofx-btn seofx-btn--secondary seofx-btn--sm" href="<?php echo esc_url( $guide[2] ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( $guide[3] ); ?><?php UI::icon( 'external', 14 ); ?><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'seo-fixer' ); ?></span>
					</a>
				<?php endif; ?>
			</details>
			<?php
		endforeach;
	}

	/**
	 * Refuses a verification tag pasted into the wrong search engine's box.
	 *
	 * @param string $value Cleaned code.
	 * @param array  $field Field definition.
	 * @param mixed  $raw   What was entered.
	 * @return string Error message, or '' when fine.
	 */
	public static function validate_verification( $value, array $field, $raw = '' ) {
		$raw = is_scalar( $raw ) ? (string) $raw : '';
		if ( ! preg_match( '/name\s*=\s*["\']([^"\']+)["\']/i', $raw, $match ) ) {
			return '';
		}

		$labels = array(
			'verify_google'    => 'Google',
			'verify_bing'      => 'Bing',
			'verify_yandex'    => 'Yandex',
			'verify_pinterest' => 'Pinterest',
		);
		$owner  = array_search( strtolower( $match[1] ), Site_Tags::verification_tags(), true );
		if ( false === $owner || $owner === $field['id'] ) {
			return '';
		}

		return sprintf(
			/* translators: 1: search engine the tag belongs to, e.g. "Bing", 2: box it was pasted into, e.g. "Google". */
			__( 'The tag you pasted into the %2$s box is a %1$s tag, so it was not saved. Paste it into the %1$s box instead.', 'seo-fixer' ),
			$labels[ $owner ],
			$labels[ $field['id'] ]
		);
	}

	/**
	 * Other active plugins that add Google Analytics themselves.
	 *
	 * @return string[] Plugin names.
	 */
	public static function analytics_plugins() {
		$known = array(
			'google-site-kit/google-site-kit.php'          => 'Site Kit by Google',
			'google-analytics-for-wordpress/googleanalytics.php' => 'MonsterInsights',
			'google-analytics-dashboard-for-wp/gadwp.php'  => 'ExactMetrics',
			'ga-google-analytics/ga-google-analytics.php'  => 'GA Google Analytics',
			'duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php' => 'GTM4WP',
			'wp-analytify/wp-analytify.php'                => 'Analytify',
			'woocommerce-google-analytics-integration/woocommerce-google-analytics-integration.php' => 'Google Analytics for WooCommerce',
		);

		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		return array_values( array_intersect_key( $known, array_flip( $active ) ) );
	}

	/**
	 * Analytics: status, double-counting warning and the consent note.
	 *
	 * @return void
	 */
	public static function analytics() {
		$id     = Site_Tags::analytics_id();
		$others = self::analytics_plugins();

		if ( '' !== $id && array() !== $others ) {
			UI::callout(
				sprintf(
					/* translators: %s: plugin names, e.g. "Site Kit by Google". */
					__( '%s is also active and may add Google Analytics too. Then every visit is counted twice. Use only one of them for analytics.', 'seo-fixer' ),
					wp_sprintf( '%l', $others )
				),
				'warn'
			);
		}

		if ( ! self::paused_notice() && '' !== $id ) {
			UI::callout(
				Site_Tags::is_tag_manager( $id )
					/* translators: %s: Tag Manager container ID. */
					? sprintf( __( 'Google Tag Manager (%s) is added to every page.', 'seo-fixer' ), $id )
					/* translators: %s: Google Analytics measurement ID. */
					: sprintf( __( 'Google Analytics (%s) is added to every page. New visits show up in Google Analytics under Reports → Realtime within a minute.', 'seo-fixer' ), $id ),
				'good'
			);
		}
		?>
		<details class="seofx-guide">
			<summary><?php esc_html_e( 'Where do I find my ID?', 'seo-fixer' ); ?></summary>
			<p><?php esc_html_e( 'In Google Analytics, open Admin → Data streams and click your website. The Measurement ID at the top right starts with “G-”. For Google Tag Manager, use the container ID at the top of your workspace; it starts with “GTM-”.', 'seo-fixer' ); ?></p>
			<a class="seofx-btn seofx-btn--secondary seofx-btn--sm" href="https://analytics.google.com/" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Open Google Analytics', 'seo-fixer' ); ?><?php UI::icon( 'external', 14 ); ?><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'seo-fixer' ); ?></span>
			</a>
		</details>
		<details class="seofx-guide">
			<summary><?php esc_html_e( 'Do I need my visitors’ consent?', 'seo-fixer' ); ?></summary>
			<p><?php esc_html_e( 'Google Analytics uses cookies, and Google’s code is loaded from Google’s servers. If you have visitors in the EU, the UK or other places with similar privacy laws, you usually need their consent first and should mention Google Analytics in your privacy policy. Use a cookie consent plugin that supports Google Consent Mode and turn on “Wait for consent” in Advanced view.', 'seo-fixer' ); ?></p>
		</details>
		<?php
	}

	/**
	 * Explains an analytics ID that couldn't be used.
	 *
	 * @param string $value Cleaned ID.
	 * @param array  $field Field definition.
	 * @param mixed  $raw   What was entered.
	 * @return string Error message, or '' when fine.
	 */
	public static function validate_analytics( $value, array $field, $raw = '' ) {
		$raw = strtoupper( trim( is_scalar( $raw ) ? (string) $raw : '' ) );
		if ( '' !== $value || '' === $raw ) {
			return '';
		}
		if ( false !== strpos( $raw, 'UA-' ) ) {
			return __( 'IDs starting with “UA-” belong to the old Universal Analytics, which Google switched off in 2023. Use the Measurement ID of your Google Analytics 4 property instead; it starts with “G-”.', 'seo-fixer' );
		}
		if ( false !== strpos( $raw, 'AW-' ) ) {
			return __( 'IDs starting with “AW-” are Google Ads tags, which SEO Fixer doesn’t add. Enter a Google Analytics ID (“G-…”) or a Tag Manager ID (“GTM-…”).', 'seo-fixer' );
		}
		return __( 'That doesn’t look like a Google Analytics ID, so it was not saved. It starts with “G-” (Google Analytics) or “GTM-” (Tag Manager), for example G-ABC123XYZ9.', 'seo-fixer' );
	}

	/**
	 * IndexNow: status, key file, test button and recent submissions.
	 *
	 * @return void
	 */
	public static function indexnow() {
		$blocker = IndexNow::blocker();

		if ( 'off' === $blocker ) {
			?>
			<p class="seofx-guide-intro"><?php esc_html_e( 'When you turn this on, SEO Fixer creates a key and a small key file on your site that proves the messages come from you. Only the addresses of your pages are sent.', 'seo-fixer' ); ?></p>
			<?php
			return;
		}

		$messages = array(
			'paused'     => array( __( 'SEO Fixer is paused because another SEO plugin is active, so nothing is sent. Many SEO plugins send IndexNow themselves.', 'seo-fixer' ), 'warn', '', '' ),
			'private'    => array( __( 'Your site is set to “Discourage search engines”, so nothing is sent.', 'seo-fixer' ), 'bad', admin_url( 'options-reading.php' ), __( 'Open Reading settings', 'seo-fixer' ) ),
			'permalinks' => array( __( 'IndexNow needs readable page addresses (permalinks) so search engines can open your key file. Choose any setting other than “Plain”.', 'seo-fixer' ), 'warn', admin_url( 'options-permalink.php' ), __( 'Open Permalink settings', 'seo-fixer' ) ),
			/* translators: %s: the site's host name, e.g. "localhost". */
			'local'      => array( sprintf( __( 'Your site’s address (%s) isn’t reachable from the internet, so nothing is sent. IndexNow starts working once the site is live on its real address.', 'seo-fixer' ), IndexNow::host() ), 'info', '', '' ),
		);
		if ( isset( $messages[ $blocker ] ) ) {
			UI::callout( $messages[ $blocker ][0], $messages[ $blocker ][1], '', $messages[ $blocker ][2], $messages[ $blocker ][3] );
		}

		$key_url = IndexNow::key_url();
		$queued  = count( IndexNow::queued()['urls'] );
		?>
		<div class="seofx-copybox">
			<span class="seofx-copybox__label"><?php esc_html_e( 'Your key file', 'seo-fixer' ); ?></span>
			<code class="seofx-copybox__value"><?php echo esc_html( $key_url ); ?></code>
			<span class="seofx-copybox__actions">
				<a class="seofx-btn seofx-btn--ghost seofx-btn--sm" href="<?php echo esc_url( $key_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Open', 'seo-fixer' ); ?><?php UI::icon( 'external', 14 ); ?><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'seo-fixer' ); ?></span>
				</a>
			</span>
		</div>

		<?php if ( '' === $blocker ) : ?>
			<p class="seofx-indexnow-actions">
				<a class="seofx-btn seofx-btn--secondary seofx-btn--sm" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=seofx_indexnow_test' ), 'seofx_indexnow_test' ) ); ?>"><?php esc_html_e( 'Send a test now', 'seo-fixer' ); ?></a>
				<span class="seofx-muted"><?php esc_html_e( 'Checks your key file, then sends your homepage address.', 'seo-fixer' ); ?></span>
			</p>
			<?php if ( $queued > 0 ) : ?>
				<p class="seofx-muted">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of page addresses. */
							_n( '%d page address is waiting and will be sent within a few minutes.', '%d page addresses are waiting and will be sent within a few minutes.', $queued, 'seo-fixer' ),
							$queued
						)
					);
					?>
				</p>
			<?php endif; ?>
		<?php endif; ?>

		<?php
		$recent = IndexNow::recent();
		if ( array() === $recent ) {
			if ( '' === $blocker ) {
				echo '<p class="seofx-muted">' . esc_html__( 'Nothing sent yet. The next time you publish or update a page, it appears here.', 'seo-fixer' ) . '</p>';
			}
			return;
		}
		?>
		<div class="seofx-table-wrap">
			<table class="seofx-table seofx-indexnow-log">
				<caption class="screen-reader-text"><?php esc_html_e( 'Recent IndexNow submissions', 'seo-fixer' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'When', 'seo-fixer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Pages', 'seo-fixer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Result', 'seo-fixer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $recent as $entry ) :
						$result = IndexNow::describe( $entry );
						$count  = isset( $entry['count'] ) ? (int) $entry['count'] : 0;
						?>
						<tr>
							<td>
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: time span, e.g. "3 hours". */
										__( '%s ago', 'seo-fixer' ),
										human_time_diff( isset( $entry['time'] ) ? (int) $entry['time'] : time() )
									)
								);
								if ( isset( $entry['source'] ) && 'test' === $entry['source'] ) {
									echo ' ';
									UI::badge( __( 'Test', 'seo-fixer' ) );
								}
								?>
							</td>
							<td>
								<?php
								if ( 1 === $count && ! empty( $entry['url'] ) ) {
									echo '<span class="seofx-indexnow-log__url">' . esc_html( UI::display_url( (string) $entry['url'] ) ) . '</span>';
								} else {
									echo esc_html( number_format_i18n( $count ) );
								}
								?>
							</td>
							<td><span class="seofx-indexnow-log__result seofx-indexnow-log__result--<?php echo esc_attr( $result['tone'] ); ?>"><?php UI::icon( UI::tone_icon( $result['tone'] ), 16 ); ?><?php echo esc_html( $result['message'] ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * "Send a test now": checks that the key file opens, then sends the homepage.
	 *
	 * @return void
	 */
	public static function handle_test() {
		Security::require_manage();
		check_admin_referer( 'seofx_indexnow_test' );

		if ( '' !== IndexNow::blocker() ) {
			UI::flash( __( 'IndexNow can’t send anything on this site right now. The reason is shown below.', 'seo-fixer' ), 'warn' );
		} else {
			$key      = IndexNow::key();
			$response = wp_remote_get(
				IndexNow::key_url(),
				array(
					'timeout'     => 10,
					'redirection' => 0,
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter for loopback requests.
				)
			);
			$body     = is_wp_error( $response ) ? '' : trim( (string) wp_remote_retrieve_body( $response ) );

			if ( $body !== $key ) {
				UI::flash( __( 'Your key file didn’t open correctly, so nothing was sent: search engines would refuse it. Some servers or security plugins block .txt addresses; ask your host to let WordPress answer them.', 'seo-fixer' ), 'bad' );
			} else {
				$result = IndexNow::submit( array( home_url( '/' ) ), 'test' );
				$answer = IndexNow::describe( $result );
				UI::flash( $answer['message'], $answer['tone'] );
			}
		}

		wp_safe_redirect( self::url() . '#seofx-section-indexnow' );
		exit;
	}
}
