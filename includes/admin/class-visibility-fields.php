<?php
/**
 * "Search visibility" controls shared by the post box and the term screen.
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Show-in-search switch, follow-links switch and canonical URL.
 */
final class Visibility_Fields {

	/**
	 * Renders the controls (inside a form that posts seofx_meta[...]).
	 *
	 * @param array $args {
	 *     @type bool   $shown        Whether the item is currently shown in search results.
	 *     @type bool   $type_default Whether its type is shown by default.
	 *     @type string $type_label   Name of its type, e.g. "Pages".
	 *     @type bool   $nofollow     Whether links are not followed.
	 *     @type string $canonical    Custom canonical URL.
	 *     @type string $url          The item's own URL.
	 *     @type bool   $is_front     Whether this is the homepage.
	 * }
	 * @return void
	 */
	public static function render( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'shown'        => true,
				'type_default' => true,
				'type_label'   => '',
				'nofollow'     => false,
				'canonical'    => '',
				'url'          => '',
				'is_front'     => false,
			)
		);

		$help = $args['type_default']
			? __( 'Turn this off for pages that shouldn’t appear in Google, such as a thank-you or login page.', 'seo-fixer' )
			/* translators: %s: content type name, e.g. "Pages". */
			: sprintf( __( '%s are hidden from search results in Settings. Turn this on to show this one anyway.', 'seo-fixer' ), $args['type_label'] );
		?>
		<div class="seofx-subsection">
			<h3 class="seofx-subsection__title"><?php esc_html_e( 'Search visibility', 'seo-fixer' ); ?></h3>
			<?php
			if ( $args['is_front'] && ! $args['shown'] ) {
				UI::callout( __( 'This is your homepage and it is hidden from search results. Most sites should show their homepage.', 'seo-fixer' ), 'bad' );
			}
			?>
			<input type="hidden" name="seofx_meta[visibility]" value="1" />
			<div class="seofx-field seofx-field--toggle">
				<div class="seofx-field__main">
					<label class="seofx-field__label" for="seofx-visibility-index"><?php esc_html_e( 'Show in search results', 'seo-fixer' ); ?></label>
					<p class="seofx-field__help" id="seofx-visibility-index-help"><?php echo esc_html( $help ); ?></p>
					<?php UI::why( __( 'Hidden pages get a “noindex” tag and are left out of your sitemap. Search engines can still follow their links to your other pages.', 'seo-fixer' ) ); ?>
				</div>
				<div class="seofx-field__control">
					<span class="seofx-toggle">
						<input type="checkbox" role="switch" id="seofx-visibility-index" name="seofx_meta[index]" value="1" data-seofx-noindex-toggle aria-describedby="seofx-visibility-index-help" <?php checked( $args['shown'] ); ?> />
						<span class="seofx-toggle__track" aria-hidden="true"><span class="seofx-toggle__thumb"></span></span>
					</span>
				</div>
			</div>

			<?php if ( Preferences::is_advanced() ) : ?>
				<input type="hidden" name="seofx_meta[visibility_advanced]" value="1" />
				<div class="seofx-field seofx-field--toggle seofx-field--advanced">
					<div class="seofx-field__main">
						<label class="seofx-field__label" for="seofx-visibility-follow"><?php esc_html_e( 'Let search engines follow the links on this page', 'seo-fixer' ); ?></label>
						<?php UI::badge( __( 'Advanced', 'seo-fixer' ), 'neutral' ); ?>
						<p class="seofx-field__help" id="seofx-visibility-follow-help"><?php esc_html_e( 'Leave this on. Turn it off only for pages full of links you don’t vouch for.', 'seo-fixer' ); ?></p>
					</div>
					<div class="seofx-field__control">
						<span class="seofx-toggle">
							<input type="checkbox" role="switch" id="seofx-visibility-follow" name="seofx_meta[follow]" value="1" aria-describedby="seofx-visibility-follow-help" <?php checked( ! $args['nofollow'] ); ?> />
							<span class="seofx-toggle__track" aria-hidden="true"><span class="seofx-toggle__thumb"></span></span>
						</span>
					</div>
				</div>
				<div class="seofx-field seofx-field--stacked seofx-field--advanced">
					<div class="seofx-field__main">
						<label class="seofx-field__label" for="seofx-visibility-canonical"><?php esc_html_e( 'Canonical URL', 'seo-fixer' ); ?></label>
						<?php UI::badge( __( 'Advanced', 'seo-fixer' ), 'neutral' ); ?>
						<p class="seofx-field__help" id="seofx-visibility-canonical-help"><?php esc_html_e( 'Only if this page is a copy of another page: enter the original’s address. Leave empty in almost every case.', 'seo-fixer' ); ?></p>
						<?php UI::why( __( 'When two pages are nearly identical, the canonical URL tells Google which one to show, so they don’t compete with each other.', 'seo-fixer' ) ); ?>
					</div>
					<div class="seofx-field__control">
						<input type="text" inputmode="url" class="seofx-input" id="seofx-visibility-canonical" name="seofx_meta[canonical]" value="<?php echo esc_attr( $args['canonical'] ); ?>" placeholder="<?php echo esc_attr( $args['url'] ); ?>" aria-describedby="seofx-visibility-canonical-help" />
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Reads submitted values into what should be stored.
	 *
	 * Each key is null when its control was not on the form (leave unchanged),
	 * '' to delete the stored value, or the value to store.
	 *
	 * @param array $values       Submitted seofx_meta values (unslashed).
	 * @param bool  $type_default Whether the item's type is shown by default.
	 * @return array{noindex: ?string, nofollow: ?string, canonical: ?string}
	 */
	public static function read( array $values, $type_default ) {
		$result = array(
			'noindex'   => null,
			'nofollow'  => null,
			'canonical' => null,
		);

		if ( ! empty( $values['visibility'] ) ) {
			$shown = ! empty( $values['index'] );
			// Store nothing when the item simply follows its type's setting.
			$result['noindex'] = $shown === (bool) $type_default ? '' : ( $shown ? '0' : '1' );
		}
		if ( ! empty( $values['visibility_advanced'] ) ) {
			$result['nofollow']  = empty( $values['follow'] ) ? '1' : '';
			$result['canonical'] = isset( $values['canonical'] ) ? Sanitize::web_url( $values['canonical'] ) : '';
		}

		return $result;
	}
}
