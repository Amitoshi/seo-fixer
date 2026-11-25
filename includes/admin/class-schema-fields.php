<?php
/**
 * "Social sharing" and "Structured data" sections of the SEO Fixer box.
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Meta\Post_Meta;
use Seofx\Sanitize;
use Seofx\Schema\Graph;

defined( 'ABSPATH' ) || exit;

/**
 * Social image/title/description and per-post schema type.
 */
final class Schema_Fields {

	/**
	 * Renders both sections for a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public static function render( \WP_Post $post ) {
		self::render_social( $post );
		if ( Preferences::is_advanced() ) {
			self::render_schema( $post );
		}
	}

	/**
	 * Social sharing section.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	private static function render_social( \WP_Post $post ) {
		?>
		<div class="seofx-subsection">
			<h3 class="seofx-subsection__title"><?php esc_html_e( 'Social sharing', 'seo-fixer' ); ?></h3>
			<input type="hidden" name="seofx_meta[social_present]" value="1" />
			<?php
			Fields::render(
				Settings_Registry::normalize(
					array(
						'id'      => 'social_image',
						'type'    => 'image',
						'name'    => 'seofx_meta[social_image]',
						'html_id' => 'seofx-social-image',
						'label'   => __( 'Image for social sharing', 'seo-fixer' ),
						'help'    => __( 'Shown when this page is shared on Facebook, LinkedIn, WhatsApp or X. Leave empty to use the featured image.', 'seo-fixer' ),
						'attrs'   => array(
							'min_size'      => 200,
							'small_message' => __( 'This image is smaller than 200 × 200 pixels, so Facebook and others may not show it. Use a larger image, ideally 1200 × 630.', 'seo-fixer' ),
						),
					)
				),
				(int) get_post_meta( $post->ID, Post_Meta::SOCIAL_IMAGE, true )
			);

			if ( Preferences::is_advanced() ) :
				?>
				<input type="hidden" name="seofx_meta[social_advanced]" value="1" />
				<?php
				Fields::render(
					Settings_Registry::normalize(
						array(
							'id'      => 'social_title',
							'type'    => 'text',
							'mode'    => 'advanced',
							'name'    => 'seofx_meta[social_title]',
							'html_id' => 'seofx-social-title',
							'label'   => __( 'Title for social sharing', 'seo-fixer' ),
							'help'    => __( 'Leave empty to use the SEO title.', 'seo-fixer' ),
							'attrs'   => array( 'maxlength' => 200 ),
						)
					),
					(string) get_post_meta( $post->ID, Post_Meta::SOCIAL_TITLE, true )
				);
				Fields::render(
					Settings_Registry::normalize(
						array(
							'id'      => 'social_description',
							'type'    => 'textarea',
							'mode'    => 'advanced',
							'name'    => 'seofx_meta[social_description]',
							'html_id' => 'seofx-social-description',
							'label'   => __( 'Description for social sharing', 'seo-fixer' ),
							'help'    => __( 'Leave empty to use the meta description.', 'seo-fixer' ),
							'attrs'   => array(
								'rows'      => 2,
								'maxlength' => 400,
							),
						)
					),
					(string) get_post_meta( $post->ID, Post_Meta::SOCIAL_DESCRIPTION, true )
				);
			endif;
			?>
		</div>
		<?php
	}

	/**
	 * Structured data section (Advanced view).
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	private static function render_schema( \WP_Post $post ) {
		$saved = json_decode( (string) get_post_meta( $post->ID, Post_Meta::SCHEMA, true ), true );
		$type  = is_array( $saved ) && ! empty( $saved['type'] ) ? $saved['type'] : '';
		$data  = '' !== $type && isset( $saved[ $type ] ) ? $saved[ $type ] : array();
		$woo   = 'product' === $post->post_type && class_exists( 'WooCommerce' );

		$types = array(
			''         => __( 'Nothing extra', 'seo-fixer' ),
			'software' => __( 'Software app', 'seo-fixer' ),
			'product'  => __( 'Product', 'seo-fixer' ),
			'faq'      => __( 'Frequently asked questions (FAQ)', 'seo-fixer' ),
		);
		if ( $woo ) {
			unset( $types['product'] );
		}

		$get = static function ( $key, $fallback = '' ) use ( $data ) {
			return isset( $data[ $key ] ) ? $data[ $key ] : $fallback;
		};
		?>
		<div class="seofx-subsection" data-seofx-schema>
			<h3 class="seofx-subsection__title"><?php esc_html_e( 'Structured data', 'seo-fixer' ); ?> <?php UI::badge( __( 'Advanced', 'seo-fixer' ), 'neutral' ); ?></h3>
			<input type="hidden" name="seofx_meta[schema_present]" value="1" />
			<?php
			if ( 'post' === $post->post_type ) {
				UI::callout( __( 'Posts are described as articles automatically. Add something extra only if this page is about an app, a product or answers common questions.', 'seo-fixer' ), 'info' );
			}
			if ( $woo ) {
				UI::callout( __( 'WooCommerce already describes this product to search engines, so SEO Fixer leaves that to WooCommerce.', 'seo-fixer' ), 'info' );
			}
			?>
			<div class="seofx-field seofx-field--stacked">
				<div class="seofx-field__main">
					<label class="seofx-field__label" for="seofx-schema-type"><?php esc_html_e( 'This page is also about', 'seo-fixer' ); ?></label>
					<p class="seofx-field__help" id="seofx-schema-type-help"><?php esc_html_e( 'Only choose a type if the page really shows this information to visitors.', 'seo-fixer' ); ?></p>
					<?php UI::why( __( 'Google only uses structured data that matches what people see on the page. Markup for things that aren’t on the page can lead to a manual penalty.', 'seo-fixer' ) ); ?>
				</div>
				<div class="seofx-field__control">
					<select class="seofx-select" id="seofx-schema-type" name="seofx_meta[schema][type]" aria-describedby="seofx-schema-type-help" data-seofx-schema-type>
						<?php foreach ( $types as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="seofx-schema-group" data-seofx-schema-group="software"<?php echo 'software' === $type ? '' : ' hidden'; ?>>
				<p class="seofx-schema-group__label"><?php Schema_Panels::label( 'needs-ratings' ); ?> <?php esc_html_e( 'Google shows app details when the page also has reviews or a rating.', 'seo-fixer' ); ?></p>
				<?php
				self::field( 'software', 'category', __( 'App category', 'seo-fixer' ), 'select', $get( 'category', 'BusinessApplication' ), Graph::software_categories() );
				self::field( 'software', 'os', __( 'Works on', 'seo-fixer' ), 'text', $get( 'os' ), array(), __( 'For example: Windows, macOS, Android, iOS, Web', 'seo-fixer' ) );
				self::price_fields( 'software', $get );
				self::rating_fields( 'software', $get );
				?>
			</div>

			<?php if ( ! $woo ) : ?>
				<div class="seofx-schema-group" data-seofx-schema-group="product"<?php echo 'product' === $type ? '' : ' hidden'; ?>>
					<p class="seofx-schema-group__label"><?php Schema_Panels::label( 'rich' ); ?> <?php esc_html_e( 'Google can show the price, stock and rating with your result.', 'seo-fixer' ); ?></p>
					<?php
					self::field( 'product', 'brand', __( 'Brand', 'seo-fixer' ), 'text', $get( 'brand' ) );
					self::field( 'product', 'sku', __( 'SKU or product code', 'seo-fixer' ), 'text', $get( 'sku' ) );
					self::price_fields( 'product', $get );
					self::field(
						'product',
						'availability',
						__( 'Availability', 'seo-fixer' ),
						'select',
						$get( 'availability', 'InStock' ),
						array(
							'InStock'    => __( 'In stock', 'seo-fixer' ),
							'OutOfStock' => __( 'Out of stock', 'seo-fixer' ),
							'PreOrder'   => __( 'Pre-order', 'seo-fixer' ),
						)
					);
					self::rating_fields( 'product', $get );
					?>
				</div>
			<?php endif; ?>

			<div class="seofx-schema-group" data-seofx-schema-group="faq"<?php echo 'faq' === $type ? '' : ' hidden'; ?>>
				<?php UI::callout( __( 'Since May 2026 Google no longer shows FAQ rich results. The markup still helps search engines and AI answers understand your page. Only add questions that are visible on the page.', 'seo-fixer' ), 'warn' ); ?>
				<?php self::faq_repeater( 'faq' === $type ? (array) $data : array() ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * One labelled input inside a schema group.
	 *
	 * @param string $group       Group (software|product).
	 * @param string $key         Field key.
	 * @param string $label       Label.
	 * @param string $type        text|number|select.
	 * @param string $value       Current value.
	 * @param array  $options     Select options.
	 * @param string $placeholder Placeholder.
	 * @return void
	 */
	private static function field( $group, $key, $label, $type, $value, array $options = array(), $placeholder = '' ) {
		$id   = 'seofx-schema-' . $group . '-' . $key;
		$name = 'seofx_meta[schema][' . $group . '][' . $key . ']';
		?>
		<div class="seofx-mini-field">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<?php if ( 'select' === $type ) : ?>
				<select class="seofx-select" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>">
					<?php foreach ( $options as $option => $option_label ) : ?>
						<option value="<?php echo esc_attr( $option ); ?>" <?php selected( (string) $value, (string) $option ); ?>><?php echo esc_html( $option_label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<input class="seofx-input" type="text"<?php echo 'number' === $type ? ' inputmode="decimal"' : ''; ?> id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Price + currency.
	 *
	 * @param string   $group Group.
	 * @param callable $get   Value getter.
	 * @return void
	 */
	private static function price_fields( $group, $get ) {
		echo '<div class="seofx-mini-row">';
		self::field( $group, 'price', __( 'Price', 'seo-fixer' ), 'number', $get( 'price' ), array(), __( 'For example 19.99, or 0 if free', 'seo-fixer' ) );
		self::field( $group, 'currency', __( 'Currency', 'seo-fixer' ), 'text', $get( 'currency', Graph::default_currency() ), array(), 'USD' );
		echo '</div>';
	}

	/**
	 * Average rating + number of ratings.
	 *
	 * @param string   $group Group.
	 * @param callable $get   Value getter.
	 * @return void
	 */
	private static function rating_fields( $group, $get ) {
		echo '<div class="seofx-mini-row">';
		self::field( $group, 'rating', __( 'Average rating (1–5)', 'seo-fixer' ), 'number', $get( 'rating' ), array(), '4.7' );
		self::field( $group, 'rating_count', __( 'Number of ratings', 'seo-fixer' ), 'number', $get( 'rating_count' ), array(), '120' );
		echo '</div>';
		echo '<p class="seofx-field__help">' . esc_html__( 'Only fill in ratings that visitors can see on this page. Leave empty if there are none.', 'seo-fixer' ) . '</p>';
	}

	/**
	 * Question/answer list with add and remove buttons.
	 *
	 * @param array $pairs Saved pairs (q, a).
	 * @return void
	 */
	private static function faq_repeater( array $pairs ) {
		if ( array() === $pairs ) {
			$pairs = array(
				array(
					'q' => '',
					'a' => '',
				),
			);
		}
		?>
		<div class="seofx-faq" data-seofx-faq data-next-index="<?php echo esc_attr( (string) count( $pairs ) ); ?>">
			<ol class="seofx-faq__list" data-seofx-faq-list>
				<?php foreach ( array_values( $pairs ) as $index => $pair ) : ?>
					<?php self::faq_row( (string) $index, (string) $pair['q'], (string) $pair['a'] ); ?>
				<?php endforeach; ?>
			</ol>
			<template data-seofx-faq-template><?php self::faq_row( '__INDEX__', '', '' ); ?></template>
			<button type="button" class="seofx-btn seofx-btn--secondary seofx-btn--sm" data-seofx-faq-add><?php esc_html_e( 'Add a question', 'seo-fixer' ); ?></button>
		</div>
		<?php
	}

	/**
	 * One FAQ row.
	 *
	 * @param string $index    Row index (or __INDEX__ in the template).
	 * @param string $question Question.
	 * @param string $answer   Answer.
	 * @return void
	 */
	private static function faq_row( $index, $question, $answer ) {
		$base = 'seofx_meta[schema][faq][' . $index . ']';
		$id   = 'seofx-faq-' . $index;
		?>
		<li class="seofx-faq__item">
			<div class="seofx-mini-field">
				<label for="<?php echo esc_attr( $id . '-q' ); ?>"><?php esc_html_e( 'Question', 'seo-fixer' ); ?></label>
				<input class="seofx-input" type="text" id="<?php echo esc_attr( $id . '-q' ); ?>" name="<?php echo esc_attr( $base . '[q]' ); ?>" value="<?php echo esc_attr( $question ); ?>" maxlength="300" />
			</div>
			<div class="seofx-mini-field">
				<label for="<?php echo esc_attr( $id . '-a' ); ?>"><?php esc_html_e( 'Answer', 'seo-fixer' ); ?></label>
				<textarea class="seofx-input seofx-input--textarea" id="<?php echo esc_attr( $id . '-a' ); ?>" name="<?php echo esc_attr( $base . '[a]' ); ?>" rows="2" maxlength="2000"><?php echo esc_textarea( $answer ); ?></textarea>
			</div>
			<button type="button" class="seofx-btn seofx-btn--ghost seofx-btn--sm" data-seofx-faq-remove><?php esc_html_e( 'Remove question', 'seo-fixer' ); ?></button>
		</li>
		<?php
	}

	/**
	 * Values to store, from the submitted box. Keys absent = leave unchanged.
	 *
	 * @param array $values Submitted seofx_meta values (unslashed).
	 * @return array<string, string> Meta key => value ('' deletes).
	 */
	public static function read( array $values ) {
		$result = array();

		if ( ! empty( $values['social_present'] ) ) {
			$result[ Post_Meta::SOCIAL_IMAGE ] = Post_Meta::sanitize_image( isset( $values['social_image'] ) ? $values['social_image'] : '' );
		}
		if ( ! empty( $values['social_advanced'] ) ) {
			$result[ Post_Meta::SOCIAL_TITLE ]       = Sanitize::template( isset( $values['social_title'] ) ? $values['social_title'] : '' );
			$result[ Post_Meta::SOCIAL_DESCRIPTION ] = Sanitize::template( isset( $values['social_description'] ) ? $values['social_description'] : '' );
		}
		if ( ! empty( $values['schema_present'] ) ) {
			$result[ Post_Meta::SCHEMA ] = Sanitize::schema( isset( $values['schema'] ) ? $values['schema'] : array() );
		}

		return $result;
	}
}
