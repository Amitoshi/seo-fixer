<?php
/**
 * Design-system reference page.
 *
 * Only registered when SEOFX_STYLEGUIDE is true in wp-config.php, so normal
 * sites never see it.
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Styleguide screen.
 */
final class Styleguide_Page {

	/**
	 * Whether the page is turned on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return defined( 'SEOFX_STYLEGUIDE' ) && SEOFX_STYLEGUIDE;
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render() {
		Security::require_manage();

		$swatches = array( 'primary', 'good', 'warn', 'bad', 'info', 'surface', 'surface-2', 'border', 'text', 'text-2' );
		?>
		<div class="wrap seofx-wrap">
			<?php UI::page_header( __( 'Design system', 'seo-fixer' ), __( 'Every SEO Fixer component in one place.', 'seo-fixer' ), Admin::SLUG . '-styleguide' ); ?>

			<div class="seofx-grid seofx-grid--2">
				<section class="seofx-card">
					<header class="seofx-card__header"><h2 class="seofx-card__title"><?php esc_html_e( 'Colour tokens', 'seo-fixer' ); ?></h2></header>
					<div class="seofx-swatches">
						<?php foreach ( $swatches as $swatch ) : ?>
							<span class="seofx-swatch"><span class="seofx-swatch__chip" style="background: var(--seofx-<?php echo esc_attr( $swatch ); ?>)"></span><code><?php echo esc_html( '--seofx-' . $swatch ); ?></code></span>
						<?php endforeach; ?>
					</div>
				</section>

				<section class="seofx-card">
					<header class="seofx-card__header"><h2 class="seofx-card__title"><?php esc_html_e( 'Score rings', 'seo-fixer' ); ?></h2></header>
					<div class="seofx-row">
						<?php
						UI::score_ring( 92, __( 'Good', 'seo-fixer' ), 'sm' );
						UI::score_ring( 64, __( 'Improve', 'seo-fixer' ), 'sm' );
						UI::score_ring( 31, __( 'Critical', 'seo-fixer' ), 'sm' );
						UI::score_ring( null, __( 'Not scanned', 'seo-fixer' ), 'sm' );
						?>
					</div>
				</section>

				<section class="seofx-card">
					<header class="seofx-card__header"><h2 class="seofx-card__title"><?php esc_html_e( 'Buttons & badges', 'seo-fixer' ); ?></h2></header>
					<div class="seofx-row">
						<button type="button" class="seofx-btn seofx-btn--primary"><?php UI::icon( 'sparkle', 18 ); ?><?php esc_html_e( 'Fix all', 'seo-fixer' ); ?></button>
						<button type="button" class="seofx-btn seofx-btn--secondary"><?php esc_html_e( 'Preview', 'seo-fixer' ); ?></button>
						<button type="button" class="seofx-btn seofx-btn--ghost"><?php esc_html_e( 'Undo', 'seo-fixer' ); ?></button>
						<button type="button" class="seofx-btn seofx-btn--danger"><?php esc_html_e( 'Delete', 'seo-fixer' ); ?></button>
						<button type="button" class="seofx-btn seofx-btn--primary" disabled><?php esc_html_e( 'Disabled', 'seo-fixer' ); ?></button>
					</div>
					<div class="seofx-row">
						<?php
						UI::badge( __( 'Good', 'seo-fixer' ), 'good' );
						UI::badge( __( 'Improve', 'seo-fixer' ), 'warn' );
						UI::badge( __( 'Critical', 'seo-fixer' ), 'bad' );
						UI::badge( __( 'Rich result', 'seo-fixer' ), 'info' );
						UI::badge( __( 'Advanced', 'seo-fixer' ), 'neutral' );
						?>
					</div>
				</section>

				<section class="seofx-card">
					<header class="seofx-card__header"><h2 class="seofx-card__title"><?php esc_html_e( 'Callouts', 'seo-fixer' ); ?></h2></header>
					<?php
					UI::callout( __( '12 pages were fixed. You can undo this for 30 days.', 'seo-fixer' ), 'good' );
					UI::callout( __( '3 pages have no description — Google may show random text.', 'seo-fixer' ), 'warn' );
					UI::callout( __( 'Search engines are blocked from your whole site.', 'seo-fixer' ), 'bad' );
					UI::callout( __( 'FAQ markup helps understanding but shows no rich result since May 2026.', 'seo-fixer' ), 'info' );
					?>
				</section>

				<section class="seofx-card">
					<header class="seofx-card__header"><h2 class="seofx-card__title"><?php esc_html_e( 'Character bars', 'seo-fixer' ); ?></h2></header>
					<div class="seofx-field seofx-field--stacked">
						<div class="seofx-field__main"><label class="seofx-field__label" for="seofx-demo-title"><?php esc_html_e( 'Title', 'seo-fixer' ); ?></label></div>
						<div class="seofx-field__control">
							<input class="seofx-input" type="text" id="seofx-demo-title" value="<?php esc_attr_e( 'Fresh sourdough bread delivered in Pune – Demo Bakery', 'seo-fixer' ); ?>" />
							<?php UI::charbar( 'seofx-demo-title', 30, 60 ); ?>
						</div>
					</div>
					<div class="seofx-field seofx-field--stacked">
						<div class="seofx-field__main"><label class="seofx-field__label" for="seofx-demo-desc"><?php esc_html_e( 'Description', 'seo-fixer' ); ?></label></div>
						<div class="seofx-field__control">
							<textarea class="seofx-input seofx-input--textarea" id="seofx-demo-desc" rows="3"><?php echo esc_textarea( __( 'Too short.', 'seo-fixer' ) ); ?></textarea>
							<?php UI::charbar( 'seofx-demo-desc', 120, 155 ); ?>
						</div>
					</div>
				</section>

				<section class="seofx-card">
					<header class="seofx-card__header"><h2 class="seofx-card__title"><?php esc_html_e( 'Table', 'seo-fixer' ); ?></h2></header>
					<table class="seofx-table">
						<thead><tr><th scope="col"><?php esc_html_e( 'Page', 'seo-fixer' ); ?></th><th scope="col"><?php esc_html_e( 'Score', 'seo-fixer' ); ?></th><th scope="col"><?php esc_html_e( 'Issues', 'seo-fixer' ); ?></th></tr></thead>
						<tbody>
							<tr><td><?php esc_html_e( 'Home', 'seo-fixer' ); ?></td><td><?php UI::badge( '96', 'good' ); ?></td><td>0</td></tr>
							<tr><td><?php esc_html_e( 'About us', 'seo-fixer' ); ?></td><td><?php UI::badge( '71', 'warn' ); ?></td><td>2</td></tr>
							<tr><td><?php esc_html_e( 'Old offer', 'seo-fixer' ); ?></td><td><?php UI::badge( '38', 'bad' ); ?></td><td>5</td></tr>
						</tbody>
					</table>
				</section>
			</div>
		</div>
		<?php
	}
}
