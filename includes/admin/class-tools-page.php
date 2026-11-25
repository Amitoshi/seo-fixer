<?php
/**
 * Tools screen: import from another SEO plugin, setup wizard, system information.
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Conflicts;
use Seofx\Installer;
use Seofx\Plugin;
use Seofx\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Tools screen.
 */
final class Tools_Page {

	/**
	 * Conflict detector.
	 *
	 * @var Conflicts
	 */
	private $conflicts;

	/**
	 * Constructor.
	 *
	 * @param Conflicts $conflicts Conflict detector.
	 */
	public function __construct( Conflicts $conflicts ) {
		$this->conflicts = $conflicts;
	}

	/**
	 * System information rows.
	 *
	 * @return array<string, string>
	 */
	private function system_info() {
		global $wp_version;

		return array(
			__( 'SEO Fixer version', 'seo-fixer' )   => SEOFX_VERSION,
			__( 'Database version', 'seo-fixer' )    => (string) get_option( 'seofx_db_version', Installer::DB_VERSION ),
			__( 'WordPress version', 'seo-fixer' )   => (string) $wp_version,
			__( 'PHP version', 'seo-fixer' )         => PHP_VERSION,
			__( 'Site address', 'seo-fixer' )        => home_url( '/' ),
			__( 'Multisite', 'seo-fixer' )           => is_multisite() ? __( 'Yes', 'seo-fixer' ) : __( 'No', 'seo-fixer' ),
			__( 'Search engine visibility', 'seo-fixer' ) => '0' === (string) get_option( 'blog_public' ) ? __( 'Discouraged', 'seo-fixer' ) : __( 'Allowed', 'seo-fixer' ),
			__( 'Permalink structure', 'seo-fixer' ) => '' !== (string) get_option( 'permalink_structure' ) ? (string) get_option( 'permalink_structure' ) : __( 'Plain', 'seo-fixer' ),
			__( 'SEO Fixer output', 'seo-fixer' )    => Plugin::output_enabled() ? __( 'On', 'seo-fixer' ) : __( 'Paused', 'seo-fixer' ),
			__( 'Other SEO plugins', 'seo-fixer' )   => $this->conflicts->has_conflicts() ? $this->conflicts->names() : __( 'None', 'seo-fixer' ),
			__( 'Interface view', 'seo-fixer' )      => Preferences::is_advanced() ? __( 'Advanced', 'seo-fixer' ) : __( 'Simple', 'seo-fixer' ),
		);
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render() {
		Security::require_manage();

		$info  = $this->system_info();
		$plain = '';
		foreach ( $info as $label => $value ) {
			$plain .= $label . ': ' . $value . "\n";
		}
		?>
		<div class="wrap seofx-wrap">
			<?php UI::page_header( __( 'Tools', 'seo-fixer' ), __( 'Handy extras and information for troubleshooting.', 'seo-fixer' ), Admin::SLUG . '-tools' ); ?>
			<?php Import_Panel::render(); ?>

			<section class="seofx-card" aria-labelledby="seofx-wizard-tool-title">
				<header class="seofx-card__header seofx-card__header--row">
					<div>
						<h2 class="seofx-card__title" id="seofx-wizard-tool-title"><?php esc_html_e( 'Setup wizard', 'seo-fixer' ); ?></h2>
						<p class="seofx-card__desc"><?php esc_html_e( 'Answer the setup questions again, for example after your site changes what it offers. Your current answers are filled in.', 'seo-fixer' ); ?></p>
					</div>
					<a class="seofx-btn seofx-btn--secondary seofx-btn--sm" href="<?php echo esc_url( Wizard_Page::url() ); ?>">
						<?php echo Wizard_Page::is_completed() ? esc_html__( 'Run setup again', 'seo-fixer' ) : esc_html__( 'Start setup', 'seo-fixer' ); ?>
					</a>
				</header>
			</section>

			<section class="seofx-card" aria-labelledby="seofx-sysinfo-title">
				<header class="seofx-card__header seofx-card__header--row">
					<div>
						<h2 class="seofx-card__title" id="seofx-sysinfo-title"><?php esc_html_e( 'System information', 'seo-fixer' ); ?></h2>
						<p class="seofx-card__desc"><?php esc_html_e( 'Share this when asking for support. It contains no passwords or personal data.', 'seo-fixer' ); ?></p>
					</div>
					<button type="button" class="seofx-btn seofx-btn--secondary seofx-btn--sm" data-seofx-copy="#seofx-sysinfo-text">
						<?php UI::icon( 'copy', 16 ); ?>
						<span data-seofx-copy-label><?php esc_html_e( 'Copy', 'seo-fixer' ); ?></span>
					</button>
				</header>
				<table class="seofx-table seofx-table--kv">
					<tbody>
						<?php foreach ( $info as $label => $value ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $label ); ?></th>
								<td><?php echo esc_html( $value ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<textarea id="seofx-sysinfo-text" class="seofx-sr" readonly tabindex="-1" aria-hidden="true"><?php echo esc_textarea( $plain ); ?></textarea>
			</section>
		</div>
		<?php
	}
}
