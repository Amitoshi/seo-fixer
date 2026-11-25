<?php
/**
 * Settings screen: renders the registry and saves submissions.
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Meta\Context;
use Seofx\Meta\Resolver;
use Seofx\Meta\Templates;
use Seofx\Meta\Variables;
use Seofx\Options;
use Seofx\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Generic settings page.
 */
final class Settings_Page {

	/**
	 * Registry.
	 *
	 * @var Settings_Registry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param Settings_Registry $registry Registry.
	 */
	public function __construct( Settings_Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Current tab id (validated).
	 *
	 * @return string
	 */
	private function current_tab() {
		$tabs = $this->registry->tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
		return isset( $tabs[ $tab ] ) ? $tab : (string) key( $tabs );
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render() {
		Security::require_manage();

		$tabs     = $this->registry->tabs();
		$current  = $this->current_tab();
		$advanced = Preferences::is_advanced();
		$hidden   = 0;
		$rendered = array();
		?>
		<div class="wrap seofx-wrap">
			<?php UI::page_header( __( 'Settings', 'seo-fixer' ), __( 'Smart defaults are already on. Change only what you need.', 'seo-fixer' ), Admin::SLUG . '-settings' ); ?>

			<?php if ( count( $tabs ) > 1 ) : ?>
				<nav class="seofx-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'seo-fixer' ); ?>">
					<?php foreach ( $tabs as $id => $tab ) : ?>
						<a class="seofx-tabs__tab<?php echo $id === $current ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', $id, admin_url( 'admin.php?page=' . Admin::SLUG . '-settings' ) ) ); ?>"<?php echo $id === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $tab['label'] ); ?></a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>

			<form class="seofx-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-seofx-form>
				<input type="hidden" name="action" value="seofx_save_settings" />
				<input type="hidden" name="seofx_tab" value="<?php echo esc_attr( $current ); ?>" />
				<?php wp_nonce_field( 'seofx_save_settings' ); ?>

				<?php
				foreach ( $tabs[ $current ]['sections'] as $section_id => $section ) :
					$fields = array();
					foreach ( $section['fields'] as $field ) {
						$field = Settings_Registry::normalize( $field );
						if ( 'advanced' === $field['mode'] && ! $advanced ) {
							++$hidden;
							continue;
						}
						$fields[] = $field;
					}
					if ( array() === $fields && empty( $section['callout'] ) && empty( $section['render'] ) ) {
						continue;
					}
					?>
					<section class="seofx-card" aria-labelledby="seofx-section-<?php echo esc_attr( $section_id ); ?>">
						<header class="seofx-card__header">
							<h2 class="seofx-card__title" id="seofx-section-<?php echo esc_attr( $section_id ); ?>"><?php echo esc_html( $section['title'] ); ?></h2>
							<?php if ( ! empty( $section['description'] ) ) : ?>
								<p class="seofx-card__desc"><?php echo esc_html( $section['description'] ); ?></p>
							<?php endif; ?>
							<?php
							if ( ! empty( $section['variables'] ) ) {
								UI::variables_help();
							}
							?>
						</header>
						<?php
						if ( ! empty( $section['callout'] ) ) {
							UI::callout( $section['callout']['text'], 'info', '', isset( $section['callout']['url'] ) ? $section['callout']['url'] : '', isset( $section['callout']['link'] ) ? $section['callout']['link'] : '' );
						}
						if ( ! empty( $section['preview'] ) ) {
							$this->home_preview( $section['preview'] );
						}
						?>
						<div class="seofx-card__body">
							<?php
							foreach ( $fields as $field ) {
								Fields::render( $field, Settings_Registry::value( $field ) );
								$rendered[] = $field['id'];
							}
							?>
						</div>
						<?php if ( ! empty( $section['render'] ) && is_callable( $section['render'] ) ) : ?>
							<div class="seofx-card__extra<?php echo array() !== $fields ? ' seofx-card__extra--after-fields' : ''; ?>"><?php call_user_func( $section['render'] ); ?></div>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>

				<?php foreach ( $rendered as $field_id ) : ?>
					<input type="hidden" name="seofx_fields[]" value="<?php echo esc_attr( $field_id ); ?>" />
				<?php endforeach; ?>

				<?php if ( $hidden > 0 ) : ?>
					<p class="seofx-advanced-hint">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of hidden settings. */
								_n( '%d more setting is available in Advanced view.', '%d more settings are available in Advanced view.', $hidden, 'seo-fixer' ),
								$hidden
							)
						);
						?>
					</p>
				<?php endif; ?>

				<div class="seofx-savebar">
					<span class="seofx-savebar__status" data-seofx-dirty-text aria-live="polite"></span>
					<button type="submit" class="seofx-btn seofx-btn--primary"><?php esc_html_e( 'Save changes', 'seo-fixer' ); ?></button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Google preview for the homepage fields.
	 *
	 * @param array $preview Field ids: title, description.
	 * @return void
	 */
	private function home_preview( array $preview ) {
		$context = Context::for_home();

		UI::serp_preview(
			array(
				'title_input'    => 'seofx-' . $preview['title'],
				'desc_input'     => 'seofx-' . $preview['description'],
				'title_template' => Templates::default_for( 'home_title' ),
				'desc_template'  => Templates::default_for( 'home_description' ),
				'title'          => Resolver::title( $context ),
				'description'    => Resolver::description( $context ),
				'vars'           => Variables::values( $context ),
				'url'            => home_url( '/' ),
			)
		);
	}

	/**
	 * Saves a submitted settings form.
	 *
	 * @return void
	 */
	public function handle_save() {
		Security::require_manage();
		check_admin_referer( 'seofx_save_settings' );

		$field_ids = isset( $_POST['seofx_fields'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['seofx_fields'] ) ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized by its field type in Settings_Registry::sanitize().
		$input = isset( $_POST['seofx'] ) && is_array( $_POST['seofx'] ) ? wp_unslash( $_POST['seofx'] ) : array();
		$tab   = isset( $_POST['seofx_tab'] ) ? sanitize_key( wp_unslash( $_POST['seofx_tab'] ) ) : '';

		$clean = $this->registry->sanitize( $field_ids, $input );

		if ( array() !== $clean['site'] ) {
			Options::update( $clean['site'] );
		}
		foreach ( $clean['user'] as $id => $value ) {
			Preferences::set( $id, $value );
		}

		if ( array() !== $clean['errors'] ) {
			UI::flash( implode( ' ', $clean['errors'] ), 'bad' );
		} elseif ( array() !== $clean['invalid'] ) {
			UI::flash( Settings_Registry::invalid_message( $clean['invalid'] ), 'warn' );
		} else {
			UI::flash( __( 'Settings saved.', 'seo-fixer' ) );
		}

		$url = admin_url( 'admin.php?page=' . Admin::SLUG . '-settings' );
		if ( '' !== $tab ) {
			$url = add_query_arg( 'tab', $tab, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
