<?php
/**
 * Detects other SEO plugins and shows one dismissible notice.
 *
 * @package SeoFixer
 */

namespace Seofx;

defined( 'ABSPATH' ) || exit;

/**
 * Conflict detector + notice.
 */
final class Conflicts {

	/**
	 * User meta key holding the dismissed notice signature.
	 */
	const DISMISS_META = 'seofx_dismissed_conflict';

	/**
	 * Cached detection result.
	 *
	 * @var array|null
	 */
	private $found = null;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_post_seofx_conflict', array( $this, 'handle_action' ) );
		add_action( 'wp_ajax_seofx_dismiss_conflict', array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * SEO plugins that print the same tags we do.
	 *
	 * @return array<string, array{name: string, file: string, constant?: string, class?: string}>
	 */
	public static function known() {
		return array(
			'yoast'    => array(
				'name'     => 'Yoast SEO',
				'file'     => 'wordpress-seo/wp-seo.php',
				'constant' => 'WPSEO_VERSION',
			),
			'rankmath' => array(
				'name'     => 'Rank Math SEO',
				'file'     => 'seo-by-rank-math/rank-math.php',
				'class'    => 'RankMath',
			),
			'aioseo'   => array(
				'name'     => 'All in One SEO',
				'file'     => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
				'constant' => 'AIOSEO_VERSION',
			),
			'seopress' => array(
				'name'     => 'SEOPress',
				'file'     => 'wp-seopress/seopress.php',
				'constant' => 'SEOPRESS_VERSION',
			),
			'tsf'      => array(
				'name'     => 'The SEO Framework',
				'file'     => 'autodescription/autodescription.php',
				'constant' => 'THE_SEO_FRAMEWORK_VERSION',
			),
			'slimseo'  => array(
				'name'     => 'Slim SEO',
				'file'     => 'slim-seo/slim-seo.php',
				'constant' => 'SLIM_SEO_VER',
			),
			'squirrly' => array(
				'name'     => 'Squirrly SEO',
				'file'     => 'squirrly-seo/squirrly.php',
				'constant' => 'SQ_VERSION',
			),
		);
	}

	/**
	 * Active SEO plugins, keyed by id, value = display name.
	 *
	 * @return array<string, string>
	 */
	public function found() {
		if ( null !== $this->found ) {
			return $this->found;
		}

		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$this->found = array();
		foreach ( self::known() as $id => $plugin ) {
			$is_active = in_array( $plugin['file'], $active, true )
				|| ( isset( $plugin['constant'] ) && defined( $plugin['constant'] ) )
				|| ( isset( $plugin['class'] ) && class_exists( $plugin['class'], false ) );

			if ( $is_active ) {
				$this->found[ $id ] = $plugin['name'];
			}
		}

		/**
		 * Filters the detected conflicting SEO plugins.
		 *
		 * @param array<string, string> $found Id => name.
		 */
		$this->found = (array) apply_filters( 'seofx_conflicting_plugins', $this->found );

		return $this->found;
	}

	/**
	 * Whether any conflicting plugin is active.
	 *
	 * @return bool
	 */
	public function has_conflicts() {
		return array() !== $this->found();
	}

	/**
	 * Human list, e.g. "Yoast SEO and Rank Math SEO".
	 *
	 * @return string
	 */
	public function names() {
		return wp_sprintf( '%l', array_values( $this->found() ) );
	}

	/**
	 * Identifies the current situation so a dismissal only hides this exact notice.
	 *
	 * @return string
	 */
	private function signature() {
		$ids = array_keys( $this->found() );
		sort( $ids );
		return md5( Options::get( 'output_mode' ) . '|' . implode( ',', $ids ) );
	}

	/**
	 * Screens where the notice may appear (kept deliberately few).
	 *
	 * @return bool
	 */
	private function is_notice_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		return in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) || false !== strpos( $screen->id, 'seo-fixer' );
	}

	/**
	 * Prints the notice.
	 *
	 * @return void
	 */
	public function render_notice() {
		if ( ! $this->has_conflicts() || ! Security::can_manage() || ! $this->is_notice_screen() ) {
			return;
		}

		$mode = Options::get( 'output_mode' );
		if ( 'off' === $mode ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) === $this->signature() ) {
			return;
		}

		$names = $this->names();

		if ( 'on' === $mode ) {
			$title   = sprintf(
				/* translators: %s: names of other SEO plugins, e.g. "Yoast SEO". */
				__( 'SEO Fixer and %s are both adding SEO tags to your pages.', 'seo-fixer' ),
				$names
			);
			$message = __( 'Search engines may see two titles and two descriptions per page. Keep only one SEO plugin active, or let SEO Fixer pause itself.', 'seo-fixer' );
		} else {
			$title   = sprintf(
				/* translators: %s: names of other SEO plugins, e.g. "Yoast SEO" or "Yoast SEO and Rank Math SEO". */
				_n( 'SEO Fixer is paused because %s is active.', 'SEO Fixer is paused because %s are active.', count( $this->found() ), 'seo-fixer' ),
				$names
			);
			$message = __( 'To avoid duplicate titles and tags, SEO Fixer is not adding anything to your pages yet. When you are ready to switch, deactivate the other plugin — SEO Fixer will start working automatically.', 'seo-fixer' );
		}

		wp_enqueue_script( 'seofx-notices' );
		?>
		<div class="notice notice-warning is-dismissible seofx-conflict-notice seofx-dismissible-notice" data-seofx-dismiss-action="seofx_dismiss_conflict" data-seofx-nonce="<?php echo esc_attr( wp_create_nonce( 'seofx_dismiss_conflict' ) ); ?>">
			<p><strong><?php echo esc_html( $title ); ?></strong></p>
			<p><?php echo esc_html( $message ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="seofx_conflict" />
				<?php wp_nonce_field( 'seofx_conflict' ); ?>
				<p>
					<?php if ( 'on' === $mode ) : ?>
						<button type="submit" name="seofx_do" value="pause" class="button button-primary"><?php esc_html_e( 'Let SEO Fixer pause itself', 'seo-fixer' ); ?></button>
					<?php else : ?>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Open Plugins', 'seo-fixer' ); ?></a>
					<?php endif; ?>
					<?php if ( array() !== array_intersect_key( $this->found(), \Seofx\Import\Importer::sources() ) ) : ?>
						<a class="button" href="<?php echo esc_url( \Seofx\Admin\Import_Panel::url() ); ?>"><?php esc_html_e( 'Bring your settings over first', 'seo-fixer' ); ?></a>
					<?php endif; ?>
					<button type="submit" name="seofx_do" value="hide" class="button"><?php esc_html_e( 'Hide this notice', 'seo-fixer' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Handles the notice buttons (works without JavaScript).
	 *
	 * @return void
	 */
	public function handle_action() {
		Security::require_manage();
		check_admin_referer( 'seofx_conflict' );

		$do = isset( $_POST['seofx_do'] ) ? sanitize_key( wp_unslash( $_POST['seofx_do'] ) ) : '';

		if ( 'pause' === $do ) {
			Options::update( array( 'output_mode' => 'auto' ) );
		} elseif ( 'hide' === $do ) {
			update_user_meta( get_current_user_id(), self::DISMISS_META, $this->signature() );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Handles the notice's "×" button.
	 *
	 * @return void
	 */
	public function ajax_dismiss() {
		check_ajax_referer( 'seofx_dismiss_conflict', 'nonce' );

		if ( ! Security::can_manage() ) {
			wp_send_json_error( null, 403 );
		}

		update_user_meta( get_current_user_id(), self::DISMISS_META, $this->signature() );
		wp_send_json_success();
	}
}
