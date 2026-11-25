<?php
/**
 * Extra content for the "Sitemap & robots.txt" settings: sitemap address,
 * submission guides, robots.txt preview and safety checks.
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Indexing\Robots;
use Seofx\Indexing\Sitemaps;
use Seofx\Options;
use Seofx\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Settings panels for sitemaps and robots.txt.
 */
final class Indexing_Panels {

	/**
	 * Sitemap address, status and "submit to Google/Bing" guides.
	 *
	 * @return void
	 */
	public static function sitemap() {
		$url = Sitemaps::url();

		if ( ! Options::get( 'sitemap_enabled' ) ) {
			UI::callout( __( 'Your sitemap is turned off. WordPress’s basic sitemap is used instead.', 'seo-fixer' ), 'warn' );
			return;
		}
		if ( ! Plugin::output_enabled() ) {
			UI::callout( __( 'SEO Fixer is paused, so its sitemap is too. The other SEO plugin’s sitemap is used instead.', 'seo-fixer' ), 'warn' );
			return;
		}
		?>
		<div class="seofx-copybox">
			<span class="seofx-copybox__label"><?php esc_html_e( 'Your sitemap address', 'seo-fixer' ); ?></span>
			<code class="seofx-copybox__value" id="seofx-sitemap-url"><?php echo esc_html( $url ); ?></code>
			<span class="seofx-copybox__actions">
				<button type="button" class="seofx-btn seofx-btn--secondary seofx-btn--sm" data-seofx-copy="#seofx-sitemap-url">
					<?php UI::icon( 'copy', 16 ); ?><span data-seofx-copy-label><?php esc_html_e( 'Copy', 'seo-fixer' ); ?></span>
				</button>
				<a class="seofx-btn seofx-btn--ghost seofx-btn--sm" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Open', 'seo-fixer' ); ?><?php UI::icon( 'external', 14 ); ?><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'seo-fixer' ); ?></span>
				</a>
			</span>
		</div>

		<p class="seofx-guide-intro"><?php esc_html_e( 'Tell Google and Bing about your sitemap once. They check it again by themselves after that.', 'seo-fixer' ); ?></p>

		<details class="seofx-guide">
			<summary><?php esc_html_e( 'How to submit your sitemap to Google', 'seo-fixer' ); ?></summary>
			<ol class="seofx-guide__steps">
				<li><?php esc_html_e( 'Open Google Search Console and sign in with your Google account.', 'seo-fixer' ); ?></li>
				<li>
					<?php
					printf(
						/* translators: 1: "Add property" button label, 2: "URL prefix" option label, 3: the site address. */
						esc_html__( 'If your site isn’t there yet, click %1$s, choose %2$s and enter %3$s. Google then asks you to prove the site is yours.', 'seo-fixer' ),
						'<span class="seofx-ui">' . esc_html__( 'Add property', 'seo-fixer' ) . '</span>',
						'<span class="seofx-ui">' . esc_html__( 'URL prefix', 'seo-fixer' ) . '</span>',
						'<code>' . esc_html( home_url( '/' ) ) . '</code>'
					);
					?>
				</li>
				<li>
					<?php
					printf(
						/* translators: 1: "HTML tag" option label, 2: link to the settings tab. */
						esc_html__( 'To prove it, choose %1$s and paste the tag under %2$s.', 'seo-fixer' ),
						'<span class="seofx-ui">' . esc_html__( 'HTML tag', 'seo-fixer' ) . '</span>',
						'<a href="' . esc_url( Services_Panels::url() ) . '">' . esc_html__( 'Search engines & analytics', 'seo-fixer' ) . '</a>'
					);
					?>
				</li>
				<li>
					<?php
					printf(
						/* translators: %s: "Sitemaps" menu label. */
						esc_html__( 'In the menu on the left, open %s.', 'seo-fixer' ),
						'<span class="seofx-ui">' . esc_html__( 'Sitemaps', 'seo-fixer' ) . '</span>'
					);
					?>
				</li>
				<li>
					<?php
					printf(
						/* translators: 1: "Add a new sitemap" box label, 2: sitemap address, 3: "Submit" button label. */
						esc_html__( 'Under %1$s, paste %2$s and click %3$s.', 'seo-fixer' ),
						'<span class="seofx-ui">' . esc_html__( 'Add a new sitemap', 'seo-fixer' ) . '</span>',
						'<code>' . esc_html( $url ) . '</code>',
						'<span class="seofx-ui">' . esc_html__( 'Submit', 'seo-fixer' ) . '</span>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'The status changes to “Success” within a few days. You don’t need to submit it again.', 'seo-fixer' ); ?></li>
			</ol>
			<a class="seofx-btn seofx-btn--secondary seofx-btn--sm" href="https://search.google.com/search-console" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Open Google Search Console', 'seo-fixer' ); ?><?php UI::icon( 'external', 14 ); ?><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'seo-fixer' ); ?></span>
			</a>
		</details>

		<details class="seofx-guide">
			<summary><?php esc_html_e( 'How to submit your sitemap to Bing', 'seo-fixer' ); ?></summary>
			<ol class="seofx-guide__steps">
				<li><?php esc_html_e( 'Open Bing Webmaster Tools and sign in.', 'seo-fixer' ); ?></li>
				<li>
					<?php
					printf(
						/* translators: %s: "Import from Google Search Console" option label. */
						esc_html__( 'Add your site. The quickest way is %s: it copies your site and sitemap from Google.', 'seo-fixer' ),
						'<span class="seofx-ui">' . esc_html__( 'Import from Google Search Console', 'seo-fixer' ) . '</span>'
					);
					?>
				</li>
				<li>
					<?php
					printf(
						/* translators: 1: "Sitemaps" menu label, 2: "Submit sitemap" button label, 3: sitemap address. */
						esc_html__( 'Otherwise open %1$s, click %2$s and paste %3$s.', 'seo-fixer' ),
						'<span class="seofx-ui">' . esc_html__( 'Sitemaps', 'seo-fixer' ) . '</span>',
						'<span class="seofx-ui">' . esc_html__( 'Submit sitemap', 'seo-fixer' ) . '</span>',
						'<code>' . esc_html( $url ) . '</code>'
					);
					?>
				</li>
			</ol>
			<a class="seofx-btn seofx-btn--secondary seofx-btn--sm" href="https://www.bing.com/webmasters" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Open Bing Webmaster Tools', 'seo-fixer' ); ?><?php UI::icon( 'external', 14 ); ?><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'seo-fixer' ); ?></span>
			</a>
		</details>
		<?php
	}

	/**
	 * robots.txt preview, warnings and notes.
	 *
	 * @return void
	 */
	public static function robots() {
		$url = home_url( '/robots.txt' );

		if ( Robots::physical_file_exists() ) {
			UI::callout( __( 'Your server has a real robots.txt file, so WordPress and SEO Fixer can’t change what search engines see. Ask your host to delete it if you want to manage robots.txt here.', 'seo-fixer' ), 'warn' );
		}
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			UI::callout( __( 'Your site is set to “Discourage search engines”, so robots.txt blocks everything. Your custom rules are used once you turn that off.', 'seo-fixer' ), 'bad', '', admin_url( 'options-reading.php' ), __( 'Open Reading settings', 'seo-fixer' ) );
		}
		if ( '' === (string) get_option( 'permalink_structure' ) && ! Robots::physical_file_exists() ) {
			// WordPress only answers /robots.txt itself when pretty permalinks are on.
			UI::callout( __( 'Your addresses use the “Plain” setting (like ?p=123), so WordPress can’t answer at /robots.txt and search engines won’t see these rules. Choose any other option under Settings → Permalinks to fix this.', 'seo-fixer' ), 'warn', '', admin_url( 'options-permalink.php' ), __( 'Open Permalink settings', 'seo-fixer' ) );
		}

		foreach ( Robots::warnings( (string) Options::get( 'robots_custom' ) ) as $warning ) {
			UI::callout( $warning['message'], $warning['tone'] );
		}
		?>
		<div class="seofx-codebox">
			<div class="seofx-codebox__head">
				<span><?php esc_html_e( 'What search engines see now', 'seo-fixer' ); ?></span>
				<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $url ); ?><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'seo-fixer' ); ?></span></a>
			</div>
			<pre class="seofx-codebox__body"><?php echo esc_html( Robots::preview() ); ?></pre>
		</div>
		<?php
	}

	/**
	 * Refuses robots.txt rules that would block the whole site.
	 *
	 * @param string $rules Sanitized rules.
	 * @return string Error message, or '' when fine.
	 */
	public static function validate_robots( $rules ) {
		if ( Robots::blocks_everything( $rules ) ) {
			return __( 'Your robots.txt rules were not saved: they would hide your whole site from Google. To hide a site on purpose, use “Discourage search engines” under Settings → Reading instead.', 'seo-fixer' );
		}
		return '';
	}
}
