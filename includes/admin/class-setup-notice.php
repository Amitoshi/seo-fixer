<?php
/**
 * One-time "run the setup" notice.
 *
 * Shown instead of redirecting after activation: it appears only on the
 * Dashboard and Plugins screens, only to admins, and disappears for good once
 * the wizard is finished or the notice is dismissed.
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Setup notice.
 */
final class Setup_Notice {

	/**
	 * User meta key set when the notice is dismissed.
	 */
	const DISMISS_META = 'seofx_setup_notice_dismissed';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_seofx_setup_notice', array( $this, 'handle_hide' ) );
		add_action( 'wp_ajax_seofx_dismiss_setup', array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * Whether to show the notice on this screen for this user.
	 *
	 * @return bool
	 */
	private function should_show() {
		if ( Wizard_Page::is_completed() || ! Security::can_manage() ) {
			return false;
		}
		if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return false;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && in_array( $screen->id, array( 'dashboard', 'plugins' ), true );
	}

	/**
	 * Prints the notice.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! $this->should_show() ) {
			return;
		}

		wp_enqueue_script( 'seofx-notices' );
		?>
		<div class="notice notice-info is-dismissible seofx-dismissible-notice" data-seofx-dismiss-action="seofx_dismiss_setup" data-seofx-nonce="<?php echo esc_attr( wp_create_nonce( 'seofx_dismiss_setup' ) ); ?>">
			<p><strong><?php esc_html_e( 'Welcome to SEO Fixer!', 'seo-fixer' ); ?></strong></p>
			<p><?php esc_html_e( 'Answer a few quick questions and SEO Fixer will set itself up for your website. It takes about two minutes.', 'seo-fixer' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="seofx_setup_notice" />
				<?php wp_nonce_field( 'seofx_setup_notice' ); ?>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( Wizard_Page::url() ); ?>"><?php esc_html_e( 'Start setup', 'seo-fixer' ); ?></a>
					<button type="submit" class="button"><?php esc_html_e( 'Not now', 'seo-fixer' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * "Not now" button (works without JavaScript).
	 *
	 * @return void
	 */
	public function handle_hide() {
		Security::require_manage();
		check_admin_referer( 'seofx_setup_notice' );

		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * The notice's "×" button.
	 *
	 * @return void
	 */
	public function ajax_dismiss() {
		check_ajax_referer( 'seofx_dismiss_setup', 'nonce' );

		if ( ! Security::can_manage() ) {
			wp_send_json_error( null, 403 );
		}

		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
		wp_send_json_success();
	}
}
