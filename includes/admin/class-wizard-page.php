<?php
/**
 * Two-minute setup wizard.
 *
 * Every step is a plain form that posts to admin-post.php, so the wizard works
 * without JavaScript (except the Media Library picker, which WordPress itself
 * builds with JavaScript). Steps reuse the fields from Settings_Registry, so the
 * wizard and the Settings screen always stay in sync.
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Options;
use Seofx\Presets;
use Seofx\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Setup wizard screen.
 */
final class Wizard_Page {

	/**
	 * Page slug.
	 */
	const SLUG = 'seo-fixer-setup';

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
	 * URL of the wizard, optionally at a step.
	 *
	 * @param string $step Step id.
	 * @return string
	 */
	public static function url( $step = '' ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG );
		return '' !== $step ? add_query_arg( 'step', $step, $url ) : $url;
	}

	/**
	 * Whether the wizard has been finished at least once.
	 *
	 * @return bool
	 */
	public static function is_completed() {
		return (int) Options::get( 'wizard_completed' ) > 0;
	}

	/**
	 * Steps for the current site type ("local" only for businesses and shops).
	 *
	 * @return array<string, array>
	 */
	private function steps() {
		$steps = array(
			'site'   => array(
				'label'  => __( 'Site type', 'seo-fixer' ),
				'title'  => __( 'What kind of website is this?', 'seo-fixer' ),
				'intro'  => __( 'SEO Fixer uses your answers to choose the right settings. It takes about two minutes, and you can change everything later.', 'seo-fixer' ),
				'fields' => array( 'site_type' ),
			),
			'brand'  => array(
				'label'  => __( 'Brand', 'seo-fixer' ),
				'title'  => __( 'Who is behind the website?', 'seo-fixer' ),
				'intro'  => __( 'Search engines use your name and logo to recognise your brand and show it in results.', 'seo-fixer' ),
				'fields' => array( 'entity_type', 'entity_name', 'entity_logo' ),
			),
			'social' => array(
				'label'  => __( 'Social', 'seo-fixer' ),
				'title'  => __( 'Where else can people find you?', 'seo-fixer' ),
				'intro'  => __( 'Add the social profiles you use. Leave the others empty.', 'seo-fixer' ),
				'fields' => array( 'social_facebook', 'social_instagram', 'social_x', 'social_linkedin', 'social_youtube', 'social_tiktok', 'social_pinterest' ),
			),
			'local'  => array(
				'label'  => __( 'Location', 'seo-fixer' ),
				'title'  => __( 'Do customers visit you?', 'seo-fixer' ),
				'intro'  => __( 'If you have a shop or office, or you serve a local area, your address helps you appear in local search and on Maps.', 'seo-fixer' ),
				'fields' => array( 'local_business', 'local_type', 'local_street', 'local_city', 'local_region', 'local_postcode', 'local_country', 'local_phone' ),
			),
			'done'   => array(
				'label'  => __( 'Done', 'seo-fixer' ),
				'fields' => array(),
			),
		);

		if ( ! Presets::is_local_type( (string) Options::get( 'site_type' ) ) ) {
			unset( $steps['local'] );
		}

		return $steps;
	}

	/**
	 * Requested step (validated).
	 *
	 * @return string
	 */
	private function current_step() {
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		return array_key_exists( $step, $this->steps() ) ? $step : 'site';
	}

	/**
	 * Step after (or before) a given one.
	 *
	 * @param string $step      Step id.
	 * @param int    $direction 1 = next, -1 = previous.
	 * @return string Step id, or '' when there is none.
	 */
	private function sibling( $step, $direction ) {
		$ids   = array_keys( $this->steps() );
		$index = array_search( $step, $ids, true );
		if ( false === $index ) {
			return 'done';
		}
		$target = $index + $direction;
		return isset( $ids[ $target ] ) ? $ids[ $target ] : '';
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render() {
		Security::require_manage();

		$steps   = $this->steps();
		$current = $this->current_step();
		?>
		<div class="wrap seofx-wrap seofx-wizard">
			<header class="seofx-wizard__top">
				<span class="seofx-topbar__brand">
					<span class="seofx-logo" aria-hidden="true"><?php UI::icon( 'search', 18 ); ?></span>
					<span class="seofx-topbar__name"><?php esc_html_e( 'SEO Fixer setup', 'seo-fixer' ); ?></span>
				</span>
				<a class="seofx-btn seofx-btn--ghost seofx-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::SLUG ) ); ?>"><?php esc_html_e( 'Exit setup', 'seo-fixer' ); ?></a>
			</header>
			<hr class="wp-header-end" />

			<?php
			$this->stepper( $steps, $current );

			if ( 'done' === $current ) {
				$this->render_done();
			} else {
				$this->render_step( $current, $steps );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Progress indicator.
	 *
	 * @param array  $steps   Steps.
	 * @param string $current Current step id.
	 * @return void
	 */
	private function stepper( array $steps, $current ) {
		$ids     = array_keys( $steps );
		$current = (int) array_search( $current, $ids, true );
		?>
		<ol class="seofx-stepper" aria-label="<?php esc_attr_e( 'Setup progress', 'seo-fixer' ); ?>">
			<?php
			foreach ( $ids as $index => $id ) :
				$state = $index < $current ? 'is-done' : ( $index === $current ? 'is-current' : '' );
				?>
				<li class="seofx-stepper__item <?php echo esc_attr( $state ); ?>"<?php echo $index === $current ? ' aria-current="step"' : ''; ?>>
					<span class="seofx-stepper__dot" aria-hidden="true">
						<?php
						if ( $index < $current ) {
							UI::icon( 'check', 14 );
						} else {
							echo esc_html( (string) ( $index + 1 ) );
						}
						?>
					</span>
					<span class="seofx-stepper__label">
						<?php echo esc_html( $steps[ $id ]['label'] ); ?>
						<?php if ( $index < $current ) : ?>
							<span class="screen-reader-text"><?php esc_html_e( '(completed)', 'seo-fixer' ); ?></span>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * One question step.
	 *
	 * @param string $step  Step id.
	 * @param array  $steps All steps.
	 * @return void
	 */
	private function render_step( $step, array $steps ) {
		$definition = $steps[ $step ];
		$total      = count( $steps ) - 1;
		$number     = (int) array_search( $step, array_keys( $steps ), true ) + 1;
		$previous   = $this->sibling( $step, -1 );
		?>
		<section class="seofx-card seofx-wizard__card" aria-labelledby="seofx-wizard-title">
			<p class="seofx-wizard__count">
				<?php
				/* translators: 1: current step number, 2: number of steps. */
				echo esc_html( sprintf( __( 'Step %1$d of %2$d', 'seo-fixer' ), $number, $total ) );
				?>
			</p>
			<h1 class="seofx-wizard__title" id="seofx-wizard-title"><?php echo esc_html( $definition['title'] ); ?></h1>
			<p class="seofx-wizard__intro"><?php echo esc_html( $definition['intro'] ); ?></p>
			<?php UI::render_flash(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-seofx-form>
				<input type="hidden" name="action" value="seofx_wizard" />
				<input type="hidden" name="seofx_step" value="<?php echo esc_attr( $step ); ?>" />
				<?php wp_nonce_field( 'seofx_wizard' ); ?>

				<div class="seofx-card__body">
					<?php
					foreach ( $definition['fields'] as $field_id ) {
						$field = $this->registry->field( $field_id );
						if ( null === $field ) {
							continue;
						}
						$value = Settings_Registry::value( $field );
						if ( 'entity_name' === $field_id && '' === $value ) {
							$value = get_bloginfo( 'name' );
						}
						Fields::render( $field, $value );
					}

					if ( 'site' === $step ) {
						Fields::render(
							Settings_Registry::normalize(
								array(
									'id'    => 'apply_presets',
									'type'  => 'toggle',
									'label' => __( 'Use the recommended settings for this kind of site', 'seo-fixer' ),
									'help'  => __( 'For example, internal search result pages are kept out of Google. You can change each setting later.', 'seo-fixer' ),
									'why'   => __( 'Pages like search results and date archives repeat content from your real pages. Keeping them out of Google helps your important pages stand out.', 'seo-fixer' ),
								)
							),
							! self::is_completed()
						);
					}
					?>
				</div>

				<div class="seofx-wizard__nav">
					<?php if ( '' !== $previous ) : ?>
						<a class="seofx-btn seofx-btn--ghost" href="<?php echo esc_url( self::url( $previous ) ); ?>"><?php esc_html_e( 'Back', 'seo-fixer' ); ?></a>
					<?php else : ?>
						<span></span>
					<?php endif; ?>
					<span class="seofx-wizard__nav-end">
						<button type="submit" name="seofx_nav" value="skip" class="seofx-btn seofx-btn--ghost"><?php esc_html_e( 'Skip this step', 'seo-fixer' ); ?></button>
						<button type="submit" name="seofx_nav" value="next" class="seofx-btn seofx-btn--primary">
							<?php esc_html_e( 'Continue', 'seo-fixer' ); ?>
							<?php UI::icon( 'arrow', 18 ); ?>
						</button>
					</span>
				</div>
			</form>
		</section>
		<?php
	}

	/**
	 * Final summary.
	 *
	 * @return void
	 */
	private function render_done() {
		$site_field = $this->registry->field( 'site_type' );
		$site_type  = (string) Options::get( 'site_type' );
		$type_label = isset( $site_field['options'][ $site_type ] ) ? $site_field['options'][ $site_type ][0] : $site_type;
		$name       = (string) Options::get( 'entity_name' );
		$name       = '' !== $name ? $name : get_bloginfo( 'name' );
		$has_logo   = (int) Options::get( 'entity_logo' ) > 0;
		$profiles   = $this->count_profiles();

		$summary = array(
			array(
				'id'           => 'site_type',
				'tone'         => 'good',
				'label'        => __( 'Site type', 'seo-fixer' ),
				'message'      => $type_label,
				'action_url'   => '',
				'action_label' => '',
			),
			array(
				'id'           => 'brand',
				'tone'         => $has_logo ? 'good' : 'warn',
				'label'        => __( 'Brand', 'seo-fixer' ),
				'message'      => $has_logo
					/* translators: %s: business or person name. */
					? sprintf( __( '%s, with a logo.', 'seo-fixer' ), $name )
					/* translators: %s: business or person name. */
					: sprintf( __( '%s. No logo yet — adding one helps Google show your brand.', 'seo-fixer' ), $name ),
				'action_url'   => $has_logo ? '' : self::url( 'brand' ),
				'action_label' => $has_logo ? '' : __( 'Add a logo', 'seo-fixer' ),
			),
			array(
				'id'           => 'social',
				'tone'         => $profiles > 0 ? 'good' : 'warn',
				'label'        => __( 'Social profiles', 'seo-fixer' ),
				'message'      => $profiles > 0
					/* translators: %d: number of social profiles. */
					? sprintf( _n( '%d profile added.', '%d profiles added.', $profiles, 'seo-fixer' ), $profiles )
					: __( 'None added yet.', 'seo-fixer' ),
				'action_url'   => $profiles > 0 ? '' : self::url( 'social' ),
				'action_label' => $profiles > 0 ? '' : __( 'Add profiles', 'seo-fixer' ),
			),
		);

		if ( Presets::is_local_type( $site_type ) ) {
			$local     = (bool) Options::get( 'local_business' );
			$city      = (string) Options::get( 'local_city' );
			$summary[] = array(
				'id'           => 'local',
				'tone'         => ( ! $local || '' !== $city ) ? 'good' : 'warn',
				'label'        => __( 'Local business', 'seo-fixer' ),
				'message'      => $local
					/* translators: %s: city name. */
					? ( '' !== $city ? sprintf( __( 'Address saved (%s).', 'seo-fixer' ), $city ) : __( 'Turned on, but the address is incomplete.', 'seo-fixer' ) )
					: __( 'Not a local business.', 'seo-fixer' ),
				'action_url'   => ( $local && '' === $city ) ? self::url( 'local' ) : '',
				'action_label' => ( $local && '' === $city ) ? __( 'Finish address', 'seo-fixer' ) : '',
			);
		}
		?>
		<section class="seofx-card seofx-wizard__card seofx-wizard__done" aria-labelledby="seofx-wizard-title">
			<span class="seofx-wizard__badge" aria-hidden="true"><?php UI::icon( 'check', 34 ); ?></span>
			<h1 class="seofx-wizard__title" id="seofx-wizard-title"><?php esc_html_e( 'You’re all set!', 'seo-fixer' ); ?></h1>
			<p class="seofx-wizard__intro">
				<?php
				/* translators: %s: site type, e.g. "Online shop". */
				echo esc_html( sprintf( __( 'SEO Fixer is set up for your site (%s). Here is what we saved:', 'seo-fixer' ), $type_label ) );
				?>
			</p>

			<?php UI::status_list( $summary ); ?>

			<h2 class="seofx-wizard__subtitle"><?php esc_html_e( 'Recommended settings', 'seo-fixer' ); ?></h2>
			<ul class="seofx-ticks">
				<?php foreach ( Presets::describe() as $line ) : ?>
					<li><?php UI::icon( 'check', 16 ); ?><span><?php echo esc_html( $line ); ?></span></li>
				<?php endforeach; ?>
			</ul>

			<div class="seofx-wizard__nav">
				<a class="seofx-btn seofx-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::SLUG . '-settings' ) ); ?>"><?php esc_html_e( 'Review all settings', 'seo-fixer' ); ?></a>
				<a class="seofx-btn seofx-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::SLUG ) ); ?>">
					<?php esc_html_e( 'Go to SEO Health', 'seo-fixer' ); ?>
					<?php UI::icon( 'arrow', 18 ); ?>
				</a>
			</div>
		</section>
		<?php
	}

	/**
	 * Number of social profile links saved.
	 *
	 * @return int
	 */
	private function count_profiles() {
		$count = 0;
		foreach ( array( 'facebook', 'instagram', 'x', 'linkedin', 'youtube', 'tiktok', 'pinterest' ) as $network ) {
			if ( '' !== (string) Options::get( 'social_' . $network ) ) {
				++$count;
			}
		}
		$other = trim( (string) Options::get( 'social_other' ) );
		return $count + ( '' !== $other ? count( explode( "\n", $other ) ) : 0 );
	}

	/**
	 * Saves a step and moves on.
	 *
	 * @return void
	 */
	public function handle_save() {
		Security::require_manage();
		check_admin_referer( 'seofx_wizard' );

		$step  = isset( $_POST['seofx_step'] ) ? sanitize_key( wp_unslash( $_POST['seofx_step'] ) ) : '';
		$nav   = isset( $_POST['seofx_nav'] ) ? sanitize_key( wp_unslash( $_POST['seofx_nav'] ) ) : 'next';
		$steps = $this->steps();

		if ( ! isset( $steps[ $step ] ) || 'done' === $step ) {
			wp_safe_redirect( self::url() );
			exit;
		}

		if ( 'skip' !== $nav ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized by its field type in Settings_Registry::sanitize().
			$input = isset( $_POST['seofx'] ) && is_array( $_POST['seofx'] ) ? wp_unslash( $_POST['seofx'] ) : array();
			$clean = $this->registry->sanitize( $steps[ $step ]['fields'], $input );

			if ( array() !== $clean['site'] ) {
				Options::update( $clean['site'] );
			}

			if ( 'site' === $step && ! empty( $input['apply_presets'] ) ) {
				Presets::apply( (string) Options::get( 'site_type' ) );
			}

			// Stay on this step so the entry can be corrected.
			if ( array() !== $clean['invalid'] ) {
				UI::flash( Settings_Registry::invalid_message( $clean['invalid'] ), 'warn' );
				wp_safe_redirect( self::url( $step ) );
				exit;
			}
		}

		// Recalculated after saving: the site type decides whether "local" is a step.
		$next = $this->sibling( $step, 1 );
		if ( 'done' === $next ) {
			Options::update( array( 'wizard_completed' => time() ) );
		}

		wp_safe_redirect( self::url( '' !== $next ? $next : 'done' ) );
		exit;
	}
}
