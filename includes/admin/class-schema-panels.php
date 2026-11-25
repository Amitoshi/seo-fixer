<?php
/**
 * Extra content for the "Schema & social" settings: what is described,
 * which parts can earn rich results, and how to add breadcrumbs.
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Options;
use Seofx\Presets;

defined( 'ABSPATH' ) || exit;

/**
 * Settings panels for structured data and breadcrumbs.
 */
final class Schema_Panels {

	/**
	 * Labels for what Google does with each kind of markup (as of 2026).
	 *
	 * @param string $kind rich|desktop|needs-ratings|understanding|none.
	 * @return void
	 */
	public static function label( $kind ) {
		$labels = array(
			'rich'          => array( __( 'Rich result', 'seo-fixer' ), 'good' ),
			'desktop'       => array( __( 'Rich result (desktop)', 'seo-fixer' ), 'good' ),
			'needs-ratings' => array( __( 'Rich result with reviews', 'seo-fixer' ), 'info' ),
			'understanding' => array( __( 'Helps Google understand', 'seo-fixer' ), 'neutral' ),
			'none'          => array( __( 'No rich result since May 2026', 'seo-fixer' ), 'warn' ),
		);
		if ( isset( $labels[ $kind ] ) ) {
			UI::badge( $labels[ $kind ][0], $labels[ $kind ][1] );
		}
	}

	/**
	 * What SEO Fixer describes, with honest labels and a test link.
	 *
	 * @return void
	 */
	public static function overview() {
		if ( ! Options::get( 'schema_enabled' ) ) {
			UI::callout( __( 'Structured data is turned off.', 'seo-fixer' ), 'warn' );
			return;
		}

		$person = 'person' === Options::get( 'entity_type' );
		$local  = ! $person && Options::get( 'local_business' ) && Presets::is_local_type( (string) Options::get( 'site_type' ) );

		$rows = array(
			array(
				$person ? __( 'You, as a person', 'seo-fixer' ) : ( $local ? __( 'Your local business: address, phone and opening hours', 'seo-fixer' ) : __( 'Your organization and logo', 'seo-fixer' ) ),
				$person ? 'understanding' : 'rich',
				admin_url( 'admin.php?page=' . Admin::SLUG . '-settings&tab=' . ( $local ? 'local' : 'brand' ) ),
			),
			array( __( 'Your website and its name', 'seo-fixer' ), 'understanding', '' ),
			array( __( 'Every page: title, description, dates and main image', 'seo-fixer' ), 'understanding', '' ),
			array( __( 'Breadcrumbs', 'seo-fixer' ), 'desktop', '' ),
			array( __( 'Posts as articles, with author', 'seo-fixer' ), 'rich', '' ),
			array( __( 'Software apps and products (choose per page in the editor)', 'seo-fixer' ), 'needs-ratings', '' ),
			array( __( 'FAQs (choose per page in the editor)', 'seo-fixer' ), 'none', '' ),
		);
		?>
		<h3 class="seofx-panel-title"><?php esc_html_e( 'What SEO Fixer describes', 'seo-fixer' ); ?></h3>
		<ul class="seofx-schema-list">
			<?php foreach ( $rows as $row ) : ?>
				<li>
					<?php UI::icon( 'check', 16 ); ?>
					<span class="seofx-schema-list__text">
						<?php echo esc_html( $row[0] ); ?>
						<?php if ( '' !== $row[2] ) : ?>
							<a href="<?php echo esc_url( $row[2] ); ?>"><?php esc_html_e( 'Edit details', 'seo-fixer' ); ?></a>
						<?php endif; ?>
					</span>
					<?php self::label( $row[1] ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="seofx-panel-note">
			<?php esc_html_e( 'Rich results are never guaranteed: Google decides when to show them. Only types Google still supports get the “Rich result” label.', 'seo-fixer' ); ?>
		</p>
		<a class="seofx-btn seofx-btn--secondary seofx-btn--sm" href="<?php echo esc_url( 'https://search.google.com/test/rich-results?url=' . rawurlencode( home_url( '/' ) ) ); ?>" target="_blank" rel="noopener noreferrer">
			<?php esc_html_e( 'Test your homepage in Google’s Rich Results Test', 'seo-fixer' ); ?><?php UI::icon( 'external', 14 ); ?><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'seo-fixer' ); ?></span>
		</a>
		<?php
	}

	/**
	 * How to show breadcrumbs on the site.
	 *
	 * @return void
	 */
	public static function breadcrumbs() {
		?>
		<p class="seofx-panel-note"><?php esc_html_e( 'Breadcrumb data is added for search engines automatically. To also show the trail to visitors, use one of these:', 'seo-fixer' ); ?></p>
		<ul class="seofx-howto">
			<li>
				<strong><?php esc_html_e( 'Block themes and the block editor:', 'seo-fixer' ); ?></strong>
				<?php
				printf(
					/* translators: %s: block name. */
					esc_html__( 'add the %s block, for example at the top of your post template in Appearance → Editor.', 'seo-fixer' ),
					'<span class="seofx-ui">' . esc_html__( 'Breadcrumbs', 'seo-fixer' ) . '</span>'
				);
				?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Anywhere else:', 'seo-fixer' ); ?></strong>
				<?php esc_html_e( 'paste this shortcode', 'seo-fixer' ); ?>
				<code id="seofx-breadcrumbs-shortcode">[seofx_breadcrumbs]</code>
				<button type="button" class="seofx-btn seofx-btn--ghost seofx-btn--sm" data-seofx-copy="#seofx-breadcrumbs-shortcode">
					<?php UI::icon( 'copy', 14 ); ?><span data-seofx-copy-label><?php esc_html_e( 'Copy', 'seo-fixer' ); ?></span>
				</button>
			</li>
		</ul>
		<?php
	}
}
