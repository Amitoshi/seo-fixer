<?php
/**
 * Keeps old links working when content moves:
 * - a published post, page or term gets a new address → 301 from the old one;
 * - a published item goes to the trash → a one-time reminder to redirect it.
 *
 * Runs everywhere (the block editor saves through the REST API, not wp-admin).
 *
 * @package SeoFixer
 */

namespace Seofx\Redirects;

use Seofx\Meta\Templates;
use Seofx\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Content change watcher.
 */
final class Watcher {

	/**
	 * User meta holding trashed items waiting for a reminder.
	 */
	const TRASH_META = 'seofx_trashed_urls';

	/**
	 * Term links captured before an edit, keyed by term id.
	 *
	 * @var array<int, string>
	 */
	private $term_links = array();

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'post_updated', array( $this, 'post_updated' ), 10, 3 );
		add_action( 'edit_terms', array( $this, 'before_term_edit' ), 10, 2 );
		add_action( 'edited_term', array( $this, 'after_term_edit' ), 10, 3 );
		add_action( 'wp_trash_post', array( $this, 'trashing' ) );
		add_action( 'untrashed_post', array( $this, 'untrashed' ) );
		add_action( 'admin_notices', array( $this, 'trash_notice' ) );
	}

	/**
	 * Adds a redirect when a published item's address changes.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $after   Post after the update.
	 * @param \WP_Post $before  Post before the update.
	 * @return void
	 */
	public function post_updated( $post_id, $after, $before ) {
		if ( ! Options::get( 'redirect_slug_changes' ) || 'publish' !== $before->post_status || 'publish' !== $after->post_status ) {
			return;
		}
		if ( ! array_key_exists( $after->post_type, Templates::post_types() ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$old_url = (string) get_permalink( $before );
		$new_url = (string) get_permalink( $after );
		self::moved( $old_url, $new_url );
	}

	/**
	 * Remembers a term's address before it is edited.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function before_term_edit( $term_id, $taxonomy ) {
		$link = get_term_link( (int) $term_id, $taxonomy );
		if ( ! is_wp_error( $link ) ) {
			$this->term_links[ (int) $term_id ] = $link;
		}
	}

	/**
	 * Adds a redirect when a term's address changed.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function after_term_edit( $term_id, $tt_id, $taxonomy ) {
		if ( ! Options::get( 'redirect_slug_changes' ) || empty( $this->term_links[ (int) $term_id ] ) || ! array_key_exists( $taxonomy, Templates::taxonomies() ) ) {
			return;
		}
		clean_term_cache( (int) $term_id, $taxonomy );
		$link = get_term_link( (int) $term_id, $taxonomy );
		if ( ! is_wp_error( $link ) ) {
			self::moved( $this->term_links[ (int) $term_id ], $link );
		}
	}

	/**
	 * Records that content moved from one address to another.
	 *
	 * @param string $old_url Old URL.
	 * @param string $new_url New URL.
	 * @return void
	 */
	private static function moved( $old_url, $new_url ) {
		$old_path = Paths::normalize( $old_url );
		$new_path = Paths::normalize( $new_url );
		if ( '' === $old_path || '' === $new_path || $old_path === $new_path || '/' === $old_path ) {
			return;
		}

		// A redirect away from the new address would now loop: remove it.
		$loop = Store::find_source( $new_path );
		if ( $loop ) {
			Store::delete( array( $loop ) );
		}

		Store::retarget( $old_url, $new_url );
		Store::save(
			array(
				'source' => $old_path,
				'target' => $new_url,
				'status' => 301,
				'origin' => 'slug',
			)
		);
		Log_404::forget( $old_path );
	}

	/**
	 * Remembers a published item that is going to the trash.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function trashing( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ! Options::get( 'trash_notice' ) || ! array_key_exists( $post->post_type, Templates::post_types() ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$items             = (array) get_user_meta( $user_id, self::TRASH_META, true );
		$items[ $post_id ] = array(
			'title' => get_the_title( $post ),
			'path'  => Paths::normalize( (string) get_permalink( $post ) ),
		);
		update_user_meta( $user_id, self::TRASH_META, array_slice( $items, -10, 10, true ) );
	}

	/**
	 * Forgets an item restored from the trash.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function untrashed( $post_id ) {
		$user_id = get_current_user_id();
		$items   = (array) get_user_meta( $user_id, self::TRASH_META, true );
		if ( isset( $items[ $post_id ] ) ) {
			unset( $items[ $post_id ] );
			update_user_meta( $user_id, self::TRASH_META, $items );
		}
	}

	/**
	 * One-time reminder on the posts list after trashing published content.
	 *
	 * @return void
	 */
	public function trash_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit' !== $screen->base || ! current_user_can( \Seofx\Security::capability() ) ) {
			return;
		}

		$user_id = get_current_user_id();
		$items   = array_filter( (array) get_user_meta( $user_id, self::TRASH_META, true ) );
		if ( array() === $items ) {
			return;
		}
		delete_user_meta( $user_id, self::TRASH_META );

		foreach ( $items as $post_id => $item ) {
			if ( 'trash' !== get_post_status( (int) $post_id ) || empty( $item['path'] ) ) {
				continue;
			}
			$url = add_query_arg(
				array(
					'page'   => 'seo-fixer-redirects',
					'source' => rawurlencode( $item['path'] ),
				),
				admin_url( 'admin.php' )
			);
			?>
			<div class="notice notice-info is-dismissible">
				<p>
					<?php
					printf(
						/* translators: %s: post title. */
						esc_html__( '“%s” is in the trash, so anyone following a link to it now sees “Page not found”. Send them to a similar page instead?', 'seo-fixer' ),
						esc_html( wp_strip_all_tags( (string) $item['title'] ) )
					);
					?>
					<a class="button button-small" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Create a redirect', 'seo-fixer' ); ?></a>
				</p>
			</div>
			<?php
		}
	}
}
