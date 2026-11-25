<?php
/**
 * "SEO Fixer" box on the post edit screen.
 *
 * Classic editor: live checklist, Google preview, SEO title and description,
 * visibility and structured data. Block editor: the first four are in the
 * SEO Fixer sidebar, so the box keeps visibility and structured data.
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Editor\Live_Check;
use Seofx\Fixes\Fixer;
use Seofx\Indexing\Indexing;
use Seofx\Meta\Context;
use Seofx\Meta\Post_Meta;
use Seofx\Meta\Resolver;
use Seofx\Meta\Templates;
use Seofx\Meta\Variables;
use Seofx\Plugin;
use Seofx\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Post meta box.
 */
final class Meta_Box {

	/**
	 * Nonce action and field name.
	 */
	const NONCE = 'seofx_meta_box';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'save_post', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Whether a post type gets the box.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private static function supports( $post_type ) {
		return array_key_exists( (string) $post_type, Templates::post_types() );
	}

	/**
	 * Adds the box.
	 *
	 * @param string $post_type Post type being edited.
	 * @return void
	 */
	public function add( $post_type ) {
		if ( self::supports( $post_type ) ) {
			add_meta_box( 'seofx-meta', __( 'SEO Fixer', 'seo-fixer' ), array( $this, 'render' ), $post_type, 'normal', 'high' );
		}
	}

	/**
	 * Loads the design system on the editor screen.
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public function assets( $hook ) {
		$screen = get_current_screen();
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && $screen && self::supports( $screen->post_type ) ) {
			Admin::enqueue_assets();
			wp_enqueue_media(); // Social image picker.
		}
	}

	/**
	 * Renders the box.
	 *
	 * @param \WP_Post $post Post being edited.
	 * @return void
	 */
	public function render( $post ) {
		$context     = Context::for_post( $post );
		$title       = (string) get_post_meta( $post->ID, Post_Meta::TITLE, true );
		$description = (string) get_post_meta( $post->ID, Post_Meta::DESCRIPTION, true );
		$title_tpl   = Templates::get( Templates::key_for( $context, 'title' ) );
		$desc_tpl    = Templates::get( Templates::key_for( $context, 'description' ) );
		$is_front    = 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $post->ID;
		$screen      = get_current_screen();
		$block       = $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor();

		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<div class="seofx-scope seofx-wrap seofx-metabox">
			<?php
			if ( ! Plugin::output_enabled() ) {
				UI::callout( __( 'SEO Fixer is paused, so these settings are saved but not used on your site yet.', 'seo-fixer' ), 'warn', '', admin_url( 'admin.php?page=' . Admin::SLUG . '-settings' ), __( 'Review setting', 'seo-fixer' ) );
			}
			if ( $is_front ) {
				UI::callout( __( 'This page is your homepage, so this is how your whole site appears in Google.', 'seo-fixer' ), 'info' );
			}

			if ( $block ) {
				// The block editor has the score, checklist, preview, title and description in the sidebar.
				?>
				<div class="seofx-callout seofx-callout--info seofx-metabox__sidebar-note">
					<?php UI::icon( 'info', 20 ); ?>
					<p><?php esc_html_e( 'Your SEO score, checklist, Google preview, SEO title and description are in the SEO Fixer sidebar.', 'seo-fixer' ); ?></p>
					<button type="button" class="seofx-btn seofx-btn--secondary seofx-btn--sm" data-seofx-open-sidebar><?php esc_html_e( 'Open the sidebar', 'seo-fixer' ); ?></button>
				</div>
				<?php
			} else {
				self::render_classic_fields( $post, $context, $title, $description, $title_tpl, $desc_tpl );
			}

			$type = get_post_type_object( $post->post_type );
			Visibility_Fields::render(
				array(
					'shown'        => Indexing::post_indexable( $post ),
					'type_default' => Indexing::type_indexed( $post->post_type ),
					'type_label'   => $type ? $type->labels->name : $post->post_type,
					'nofollow'     => '1' === (string) get_post_meta( $post->ID, Post_Meta::NOFOLLOW, true ),
					'canonical'    => (string) get_post_meta( $post->ID, Post_Meta::CANONICAL, true ),
					'url'          => (string) get_permalink( $post ),
					'is_front'     => $is_front,
				)
			);

			Schema_Fields::render( $post );
			?>
		</div>
		<?php
	}

	/**
	 * Classic editor: live checklist, Google preview, SEO title and description.
	 *
	 * @param \WP_Post $post        Post.
	 * @param Context  $context     Context.
	 * @param string   $title       Saved SEO title.
	 * @param string   $description Saved description.
	 * @param string   $title_tpl   Title template for this type.
	 * @param string   $desc_tpl    Description template for this type.
	 * @return void
	 */
	private static function render_classic_fields( \WP_Post $post, Context $context, $title, $description, $title_tpl, $desc_tpl ) {
		?>
		<section class="seofx-livecheck" data-seofx-livecheck data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>" aria-labelledby="seofx-livecheck-title">
			<div class="seofx-livecheck__bar">
				<h3 class="seofx-livecheck__title" id="seofx-livecheck-title"><?php esc_html_e( 'SEO checklist', 'seo-fixer' ); ?></h3>
				<button type="button" class="seofx-btn seofx-btn--ghost seofx-btn--sm" data-seofx-livecheck-run><?php esc_html_e( 'Check again', 'seo-fixer' ); ?></button>
			</div>
			<div class="seofx-livecheck__body" aria-live="polite">
				<?php Live_Check::render( Live_Check::run( $post ) ); ?>
			</div>
		</section>
		<?php
		// Input ids avoid "seofx-meta-title": WordPress gives the box heading that id.
		UI::serp_preview(
			array(
				'title_input'    => 'seofx-seo-title',
				'desc_input'     => 'seofx-seo-description',
				'title_template' => $title_tpl,
				'desc_template'  => $desc_tpl,
				'title'          => Resolver::title( $context ),
				'description'    => Resolver::description( $context ),
				'vars'           => Variables::values( $context ),
				'url'            => (string) get_permalink( $post ),
				'live'           => 'post',
				'hidden'         => ! Indexing::post_indexable( $post ),
			)
		);
		?>
		<div class="seofx-field seofx-field--stacked">
			<div class="seofx-field__main">
				<label class="seofx-field__label" for="seofx-seo-title"><?php esc_html_e( 'SEO title', 'seo-fixer' ); ?></label>
				<p class="seofx-field__help" id="seofx-seo-title-help"><?php esc_html_e( 'The clickable headline in Google. Leave empty to use the automatic title shown in grey.', 'seo-fixer' ); ?></p>
				<?php UI::why( __( 'A clear, specific title that includes what people search for gets more clicks. Keep it under about 60 characters so Google doesn’t cut it off.', 'seo-fixer' ) ); ?>
			</div>
			<div class="seofx-field__control">
				<input type="text" class="seofx-input" id="seofx-seo-title" name="seofx_meta[title]" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php echo esc_attr( Variables::replace( $title_tpl, $context ) ); ?>" maxlength="200" aria-describedby="seofx-seo-title-help" />
			</div>
		</div>
		<div class="seofx-field seofx-field--stacked">
			<div class="seofx-field__main">
				<label class="seofx-field__label" for="seofx-seo-description"><?php esc_html_e( 'Meta description', 'seo-fixer' ); ?></label>
				<p class="seofx-field__help" id="seofx-seo-description-help"><?php esc_html_e( 'The short text under the title. Leave empty to use the first sentences of your content.', 'seo-fixer' ); ?></p>
				<?php UI::why( __( 'A good description works like a small advert: it tells people what they’ll find and why to click. Without one, Google picks some text from the page.', 'seo-fixer' ) ); ?>
			</div>
			<div class="seofx-field__control">
				<textarea class="seofx-input seofx-input--textarea" id="seofx-seo-description" name="seofx_meta[description]" rows="3" maxlength="400" placeholder="<?php echo esc_attr( Variables::replace( $desc_tpl, $context ) ); ?>" aria-describedby="seofx-seo-description-help"><?php echo esc_textarea( $description ); ?></textarea>
			</div>
		</div>
		<?php
		if ( Preferences::is_advanced() ) {
			UI::variables_help();
		}
	}

	/**
	 * Saves the box (only when it was on the submitted form).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// Only the post being edited: other saves in the same request (our own automatic fixes,
		// or another plugin updating a different post) must not receive this form's values.
		if ( Fixer::$writing || ( isset( $_POST['post_ID'] ) && absint( $_POST['post_ID'] ) !== (int) $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized with Sanitize::template() below.
		$values = isset( $_POST['seofx_meta'] ) && is_array( $_POST['seofx_meta'] ) ? wp_unslash( $_POST['seofx_meta'] ) : array();

		foreach ( array(
			'title'       => Post_Meta::TITLE,
			'description' => Post_Meta::DESCRIPTION,
		) as $field => $key ) {
			// In the block editor these fields live in the sidebar (saved through the REST API), not in this box.
			if ( ! array_key_exists( $field, $values ) ) {
				continue;
			}
			$value = Sanitize::template( $values[ $field ] );
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, wp_slash( $value ) );
			}
		}

		$visibility = Visibility_Fields::read( $values, Indexing::type_indexed( (string) get_post_type( $post_id ) ) );
		foreach ( array(
			'noindex'   => Post_Meta::NOINDEX,
			'nofollow'  => Post_Meta::NOFOLLOW,
			'canonical' => Post_Meta::CANONICAL,
		) as $field => $key ) {
			if ( null === $visibility[ $field ] ) {
				continue;
			}
			if ( '' === $visibility[ $field ] ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $visibility[ $field ] );
			}
		}

		foreach ( Schema_Fields::read( $values ) as $key => $value ) {
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				// wp_slash(): update_post_meta() unslashes, which would break the JSON's escaped quotes.
				update_post_meta( $post_id, $key, wp_slash( $value ) );
			}
		}
	}
}
