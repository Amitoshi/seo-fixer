<?php
/**
 * Renders one settings field per type, with label, help line and "Why it matters".
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Field renderer.
 */
final class Fields {

	/**
	 * Renders a field.
	 *
	 * @param array $field Normalized field definition.
	 * @param mixed $value Current value.
	 * @return void
	 */
	public static function render( array $field, $value ) {
		// Boxes outside the settings form (editor) pass their own input name and id.
		$id      = isset( $field['html_id'] ) ? $field['html_id'] : 'seofx-' . $field['id'];
		$name    = isset( $field['name'] ) ? $field['name'] : 'seofx[' . $field['id'] . ']';
		$help_id = $id . '-help';
		$grouped = in_array( $field['type'], array( 'radio_cards', 'segmented', 'image', 'hours' ), true );
		$stacked = in_array( $field['type'], array( 'radio_cards', 'textarea', 'hours' ), true );

		$classes = array( 'seofx-field', 'seofx-field--' . str_replace( '_', '-', $field['type'] ) );
		if ( $stacked ) {
			$classes[] = 'seofx-field--stacked';
		}
		if ( 'advanced' === $field['mode'] ) {
			$classes[] = 'seofx-field--advanced';
		}

		// Conditional fields: shown only while another setting has a given value.
		$show_if = '';
		$hidden  = false;
		foreach ( $field['show_if'] as $dependency => $expected ) {
			$show_if = $dependency;
			$hidden  = (bool) \Seofx\Options::get( $dependency ) !== (bool) $expected;
		}
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"<?php echo '' !== $show_if ? ' data-seofx-show-if="' . esc_attr( $show_if ) . '"' : ''; ?><?php echo $hidden ? ' hidden' : ''; ?>>
			<div class="seofx-field__main">
				<?php if ( $grouped ) : ?>
					<span class="seofx-field__label" id="<?php echo esc_attr( $id . '-label' ); ?>"><?php echo esc_html( $field['label'] ); ?></span>
				<?php else : ?>
					<label class="seofx-field__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
				<?php endif; ?>
				<?php if ( 'advanced' === $field['mode'] ) : ?>
					<?php UI::badge( __( 'Advanced', 'seo-fixer' ), 'neutral' ); ?>
				<?php endif; ?>
				<?php if ( '' !== $field['help'] ) : ?>
					<p class="seofx-field__help" id="<?php echo esc_attr( $help_id ); ?>"><?php echo esc_html( $field['help'] ); ?></p>
				<?php endif; ?>
				<?php UI::why( $field['why'] ); ?>
			</div>
			<div class="seofx-field__control">
				<?php
				switch ( $field['type'] ) {
					case 'toggle':
						self::toggle( $id, $name, $help_id, (bool) $value );
						break;
					case 'radio_cards':
						self::radio_cards( $field, $id, $name, $help_id, (string) $value );
						break;
					case 'segmented':
						self::segmented( $field, $id, $name, $help_id, (string) $value );
						break;
					case 'select':
						self::select( $field, $id, $name, $help_id, (string) $value );
						break;
					case 'textarea':
						self::textarea( $field, $id, $name, $help_id, (string) $value );
						break;
					case 'image':
						self::image( $id, $name, $help_id, (int) $value, $field['attrs'] );
						break;
					case 'hours':
						self::hours( $id, $name, $help_id, is_array( $value ) ? $value : array() );
						break;
					default:
						self::input( $field, $id, $name, $help_id, $value );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * On/off switch.
	 *
	 * @param string $id      Input id.
	 * @param string $name    Input name.
	 * @param string $help_id Help text id.
	 * @param bool   $checked State.
	 * @return void
	 */
	private static function toggle( $id, $name, $help_id, $checked ) {
		?>
		<span class="seofx-toggle">
			<input type="checkbox" role="switch" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" aria-describedby="<?php echo esc_attr( $help_id ); ?>" <?php checked( $checked ); ?> />
			<span class="seofx-toggle__track" aria-hidden="true"><span class="seofx-toggle__thumb"></span></span>
		</span>
		<?php
	}

	/**
	 * Large selectable cards (radio).
	 *
	 * @param array  $field   Field.
	 * @param string $id      Base id.
	 * @param string $name    Input name.
	 * @param string $help_id Help text id.
	 * @param string $value   Current value.
	 * @return void
	 */
	private static function radio_cards( array $field, $id, $name, $help_id, $value ) {
		?>
		<div class="seofx-radio-cards" role="radiogroup" aria-labelledby="<?php echo esc_attr( $id . '-label' ); ?>" aria-describedby="<?php echo esc_attr( $help_id ); ?>">
			<?php
			foreach ( $field['options'] as $option => $label ) :
				$title = is_array( $label ) ? $label[0] : $label;
				$desc  = is_array( $label ) && isset( $label[1] ) ? $label[1] : '';
				?>
				<label class="seofx-radio-card">
					<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $option ); ?>" <?php checked( $value, (string) $option ); ?> />
					<span class="seofx-radio-card__text">
						<span class="seofx-radio-card__title"><?php echo esc_html( $title ); ?></span>
						<?php if ( '' !== $desc ) : ?>
							<span class="seofx-radio-card__desc"><?php echo esc_html( $desc ); ?></span>
						<?php endif; ?>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Segmented control (radio).
	 *
	 * @param array  $field   Field.
	 * @param string $id      Base id.
	 * @param string $name    Input name.
	 * @param string $help_id Help text id.
	 * @param string $value   Current value.
	 * @return void
	 */
	private static function segmented( array $field, $id, $name, $help_id, $value ) {
		?>
		<div class="seofx-segmented" role="radiogroup" aria-labelledby="<?php echo esc_attr( $id . '-label' ); ?>" aria-describedby="<?php echo esc_attr( $help_id ); ?>">
			<?php foreach ( $field['options'] as $option => $label ) : ?>
				<label class="seofx-segmented__opt">
					<input type="radio" class="seofx-sr" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $option ); ?>" <?php checked( $value, (string) $option ); ?> />
					<span><?php echo esc_html( is_array( $label ) ? $label[0] : $label ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Dropdown.
	 *
	 * @param array  $field   Field.
	 * @param string $id      Input id.
	 * @param string $name    Input name.
	 * @param string $help_id Help text id.
	 * @param string $value   Current value.
	 * @return void
	 */
	private static function select( array $field, $id, $name, $help_id, $value ) {
		?>
		<select class="seofx-select" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" aria-describedby="<?php echo esc_attr( $help_id ); ?>">
			<?php foreach ( $field['options'] as $option => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $value, (string) $option ); ?>><?php echo esc_html( is_array( $label ) ? $label[0] : $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Multi-line text.
	 *
	 * @param array  $field   Field.
	 * @param string $id      Input id.
	 * @param string $name    Input name.
	 * @param string $help_id Help text id.
	 * @param string $value   Current value.
	 * @return void
	 */
	private static function textarea( array $field, $id, $name, $help_id, $value ) {
		?>
		<textarea class="seofx-input seofx-input--textarea" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="<?php echo esc_attr( isset( $field['attrs']['rows'] ) ? (string) (int) $field['attrs']['rows'] : '4' ); ?>" aria-describedby="<?php echo esc_attr( $help_id ); ?>"<?php self::attrs( $field['attrs'], array( 'maxlength', 'placeholder' ) ); ?>><?php echo esc_textarea( $value ); ?></textarea>
		<?php
	}

	/**
	 * Single-line input (text, url, number).
	 *
	 * @param array  $field   Field.
	 * @param string $id      Input id.
	 * @param string $name    Input name.
	 * @param string $help_id Help text id.
	 * @param mixed  $value   Current value.
	 * @return void
	 */
	private static function input( array $field, $id, $name, $help_id, $value ) {
		$type = in_array( $field['type'], array( 'url', 'number', 'tel', 'email' ), true ) ? $field['type'] : 'text';
		// Social links may be typed as "@handle", which a url input would reject.
		if ( 'url' === $type ) {
			$type = 'text';
		}
		?>
		<input class="seofx-input" type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" aria-describedby="<?php echo esc_attr( $help_id ); ?>"<?php self::attrs( $field['attrs'], array( 'maxlength', 'placeholder', 'min', 'max', 'step' ) ); ?> />
		<?php
	}

	/**
	 * Image picker (Media Library). Stores the attachment id.
	 *
	 * @param string $id       Input id.
	 * @param string $name     Input name.
	 * @param string $help_id  Help text id.
	 * @param int    $image_id Attachment id.
	 * @param array  $attrs    Optional: min_size (px, default 112) and small_message.
	 * @return void
	 */
	private static function image( $id, $name, $help_id, $image_id, array $attrs = array() ) {
		$min     = isset( $attrs['min_size'] ) ? (int) $attrs['min_size'] : 112;
		$message = isset( $attrs['small_message'] ) ? (string) $attrs['small_message'] : __( 'This image is smaller than 112 × 112 pixels, so Google may not use it. Try a larger version.', 'seo-fixer' );
		$image   = $image_id ? wp_get_attachment_image_src( $image_id, 'medium' ) : false;
		$meta    = $image_id ? wp_get_attachment_metadata( $image_id ) : false;
		$small   = is_array( $meta ) && isset( $meta['width'], $meta['height'] ) && min( (int) $meta['width'], (int) $meta['height'] ) < $min;
		?>
		<div class="seofx-media" data-seofx-media data-min-size="<?php echo esc_attr( (string) $min ); ?>" role="group" aria-labelledby="<?php echo esc_attr( $id . '-label' ); ?>" aria-describedby="<?php echo esc_attr( $help_id ); ?>">
			<input type="hidden" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $image ? (string) $image_id : '0' ); ?>" data-seofx-media-input />
			<div class="seofx-media__preview" data-seofx-media-preview>
				<?php if ( $image ) : ?>
					<img src="<?php echo esc_url( $image[0] ); ?>" alt="" />
				<?php else : ?>
					<span class="seofx-media__empty"><?php UI::icon( 'sparkle', 22 ); ?></span>
				<?php endif; ?>
			</div>
			<div class="seofx-media__actions">
				<button type="button" class="seofx-btn seofx-btn--secondary seofx-btn--sm" data-seofx-media-choose><?php echo $image ? esc_html__( 'Change image', 'seo-fixer' ) : esc_html__( 'Choose image', 'seo-fixer' ); ?></button>
				<button type="button" class="seofx-btn seofx-btn--ghost seofx-btn--sm" data-seofx-media-remove<?php echo $image ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'seo-fixer' ); ?></button>
			</div>
			<template data-seofx-media-empty><span class="seofx-media__empty"><?php UI::icon( 'sparkle', 22 ); ?></span></template>
			<p class="seofx-media__warning" data-seofx-media-warning role="status"<?php echo $small ? '' : ' hidden'; ?>><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Opening hours: one row per weekday (open switch + two times).
	 *
	 * @param string $id      Base id.
	 * @param string $name    Input name.
	 * @param string $help_id Help text id.
	 * @param array  $hours   day => {open, close}.
	 * @return void
	 */
	private static function hours( $id, $name, $help_id, array $hours ) {
		global $wp_locale;

		$days = array(
			'mon' => 1,
			'tue' => 2,
			'wed' => 3,
			'thu' => 4,
			'fri' => 5,
			'sat' => 6,
			'sun' => 0,
		);
		?>
		<div class="seofx-hours" role="group" aria-labelledby="<?php echo esc_attr( $id . '-label' ); ?>" aria-describedby="<?php echo esc_attr( $help_id ); ?>">
			<?php
			foreach ( $days as $day => $number ) :
				$open     = isset( $hours[ $day ] );
				$day_name = $wp_locale ? $wp_locale->get_weekday( $number ) : ucfirst( $day );
				$row_id   = $id . '-' . $day;
				?>
				<div class="seofx-hours__row">
					<label class="seofx-hours__day" for="<?php echo esc_attr( $row_id ); ?>">
						<input type="checkbox" id="<?php echo esc_attr( $row_id ); ?>" name="<?php echo esc_attr( $name . '[' . $day . '][open_day]' ); ?>" value="1" <?php checked( $open ); ?> />
						<?php echo esc_html( $day_name ); ?>
					</label>
					<input type="time" class="seofx-input seofx-hours__time" name="<?php echo esc_attr( $name . '[' . $day . '][open]' ); ?>" value="<?php echo esc_attr( $open ? $hours[ $day ]['open'] : '09:00' ); ?>" aria-label="<?php /* translators: %s: weekday name. */ echo esc_attr( sprintf( __( '%s opens at', 'seo-fixer' ), $day_name ) ); ?>" />
					<span class="seofx-hours__to" aria-hidden="true">–</span>
					<input type="time" class="seofx-input seofx-hours__time" name="<?php echo esc_attr( $name . '[' . $day . '][close]' ); ?>" value="<?php echo esc_attr( $open ? $hours[ $day ]['close'] : '17:00' ); ?>" aria-label="<?php /* translators: %s: weekday name. */ echo esc_attr( sprintf( __( '%s closes at', 'seo-fixer' ), $day_name ) ); ?>" />
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Prints whitelisted extra attributes.
	 *
	 * @param array    $attrs   Attributes.
	 * @param string[] $allowed Allowed names.
	 * @return void
	 */
	private static function attrs( array $attrs, array $allowed ) {
		foreach ( $attrs as $key => $val ) {
			if ( in_array( $key, $allowed, true ) ) {
				printf( ' %s="%s"', esc_attr( $key ), esc_attr( (string) $val ) );
			}
		}
	}
}
