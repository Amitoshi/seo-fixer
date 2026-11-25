<?php
/**
 * SEO title and description on category, tag and other term edit screens.
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Indexing\Indexing;
use Seofx\Meta\Context;
use Seofx\Meta\Post_Meta;
use Seofx\Meta\Resolver;
use Seofx\Meta\Templates;
use Seofx\Meta\Variables;
use Seofx\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Term fields.
 */
final class Term_Fields {

	/**
	 * Nonce action and field name.
	 */
	const NONCE = 'seofx_term_fields';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'hook_taxonomies' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Adds the form and save hooks for every supported taxonomy.
	 *
	 * @return void
	 */
	public function hook_taxonomies() {
		foreach ( array_keys( Templates::taxonomies() ) as $taxonomy ) {
			add_action( $taxonomy . '_edit_form', array( $this, 'render' ) );
			add_action( 'edited_' . $taxonomy, array( $this, 'save' ) );
		}
	}

	/**
	 * Loads the design system on the term edit screen.
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public function assets( $hook ) {
		$screen = get_current_screen();
		if ( 'term.php' === $hook && $screen && array_key_exists( (string) $screen->taxonomy, Templates::taxonomies() ) ) {
			Admin::enqueue_assets();
		}
	}

	/**
	 * Renders the fields under the term form.
	 *
	 * @param \WP_Term $term Term being edited.
	 * @return void
	 */
	public function render( $term ) {
		$context   = Context::for_term( $term );
		$title_tpl = Templates::get( Templates::key_for( $context, 'title' ) );
		$desc_tpl  = Templates::get( Templates::key_for( $context, 'description' ) );
		$link      = get_term_link( $term );

		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<div class="seofx-scope seofx-wrap seofx-metabox seofx-termbox">
			<h2 class="seofx-card__title"><?php esc_html_e( 'SEO Fixer', 'seo-fixer' ); ?></h2>
			<?php
			UI::serp_preview(
				array(
					'title_input'    => 'seofx-term-title',
					'desc_input'     => 'seofx-term-description',
					'title_template' => $title_tpl,
					'desc_template'  => $desc_tpl,
					'title'          => Resolver::title( $context ),
					'description'    => Resolver::description( $context ),
					'vars'           => Variables::values( $context ),
					'url'            => is_wp_error( $link ) ? home_url( '/' ) : $link,
					'hidden'         => ! Indexing::term_indexable( $term ),
				)
			);
			?>
			<div class="seofx-field seofx-field--stacked">
				<div class="seofx-field__main">
					<label class="seofx-field__label" for="seofx-term-title"><?php esc_html_e( 'SEO title', 'seo-fixer' ); ?></label>
					<p class="seofx-field__help" id="seofx-term-title-help"><?php esc_html_e( 'Leave empty to use the automatic title shown in grey.', 'seo-fixer' ); ?></p>
				</div>
				<div class="seofx-field__control">
					<input type="text" class="seofx-input" id="seofx-term-title" name="seofx_meta[title]" value="<?php echo esc_attr( (string) get_term_meta( $term->term_id, Post_Meta::TITLE, true ) ); ?>" placeholder="<?php echo esc_attr( Variables::replace( $title_tpl, $context ) ); ?>" maxlength="200" aria-describedby="seofx-term-title-help" />
				</div>
			</div>
			<div class="seofx-field seofx-field--stacked">
				<div class="seofx-field__main">
					<label class="seofx-field__label" for="seofx-term-description"><?php esc_html_e( 'Meta description', 'seo-fixer' ); ?></label>
					<p class="seofx-field__help" id="seofx-term-description-help"><?php esc_html_e( 'Leave empty to use the description above.', 'seo-fixer' ); ?></p>
				</div>
				<div class="seofx-field__control">
					<textarea class="seofx-input seofx-input--textarea" id="seofx-term-description" name="seofx_meta[description]" rows="3" maxlength="400" placeholder="<?php echo esc_attr( Variables::replace( $desc_tpl, $context ) ); ?>" aria-describedby="seofx-term-description-help"><?php echo esc_textarea( (string) get_term_meta( $term->term_id, Post_Meta::DESCRIPTION, true ) ); ?></textarea>
				</div>
			</div>
			<?php
			$taxonomy = get_taxonomy( $term->taxonomy );
			Visibility_Fields::render(
				array(
					'shown'        => Indexing::term_indexable( $term ),
					'type_default' => Indexing::taxonomy_indexed( $term->taxonomy ),
					'type_label'   => $taxonomy ? $taxonomy->labels->name : $term->taxonomy,
					'nofollow'     => '1' === (string) get_term_meta( $term->term_id, Post_Meta::NOFOLLOW, true ),
					'canonical'    => (string) get_term_meta( $term->term_id, Post_Meta::CANONICAL, true ),
					'url'          => is_wp_error( $link ) ? '' : $link,
				)
			);
			?>
		</div>
		<?php
	}

	/**
	 * Saves the fields.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public function save( $term_id ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized with Sanitize::template() below.
		$values = isset( $_POST['seofx_meta'] ) && is_array( $_POST['seofx_meta'] ) ? wp_unslash( $_POST['seofx_meta'] ) : array();

		foreach ( array(
			'title'       => Post_Meta::TITLE,
			'description' => Post_Meta::DESCRIPTION,
		) as $field => $key ) {
			$value = isset( $values[ $field ] ) ? Sanitize::template( $values[ $field ] ) : '';
			if ( '' === $value ) {
				delete_term_meta( $term_id, $key );
			} else {
				update_term_meta( $term_id, $key, wp_slash( $value ) );
			}
		}

		$term       = get_term( $term_id );
		$visibility = Visibility_Fields::read( $values, $term instanceof \WP_Term ? Indexing::taxonomy_indexed( $term->taxonomy ) : true );
		foreach ( array(
			'noindex'   => Post_Meta::NOINDEX,
			'nofollow'  => Post_Meta::NOFOLLOW,
			'canonical' => Post_Meta::CANONICAL,
		) as $field => $key ) {
			if ( null === $visibility[ $field ] ) {
				continue;
			}
			if ( '' === $visibility[ $field ] ) {
				delete_term_meta( $term_id, $key );
			} else {
				update_term_meta( $term_id, $key, $visibility[ $field ] );
			}
		}
	}
}
