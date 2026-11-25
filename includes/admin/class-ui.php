<?php
/**
 * Design-system render helpers shared by every admin screen.
 *
 * All helpers echo escaped markup.
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Meta\Variables;

defined( 'ABSPATH' ) || exit;

/**
 * UI components.
 */
final class UI {

	/**
	 * Inline icon paths (24×24, stroke icons).
	 *
	 * @return array<string, string>
	 */
	private static function icon_paths() {
		return array(
			'check'    => '<path d="M5 12.5l4.2 4.2L19 7"/>',
			'alert'    => '<path d="M12 9v4m0 3.5v.01M10.3 3.9 2.6 17.2A2 2 0 0 0 4.3 20h15.4a2 2 0 0 0 1.7-2.8L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
			'cross'    => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/>',
			'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8.5v.01"/>',
			'gauge'    => '<path d="M4 15a8 8 0 1 1 16 0"/><path d="m12 15 3.5-5"/><circle cx="12" cy="15" r="1.2"/>',
			'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
			'tools'    => '<path d="M14.7 6.3a4 4 0 0 0 5 5L21 13l-8 8-3.5-3.5 8-8-1.3-1.3a4 4 0 0 1-1.5-1.9ZM3 21l6-6"/>',
			'sparkle'  => '<path d="M12 3v4m0 10v4M3 12h4m10 0h4M6 6l2.5 2.5m7 7L18 18M6 18l2.5-2.5m7-7L18 6"/>',
			'shield'   => '<path d="M12 3 5 6v6c0 4.4 3 7.6 7 9 4-1.4 7-4.6 7-9V6l-7-3Z"/><path d="m9 12 2 2 4-4"/>',
			'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
			'arrow'    => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
			'copy'     => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h8"/>',
			'external' => '<path d="M14 4h6v6m0-6-9 9M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/>',
			'download' => '<path d="M12 4v11m-5-5 5 5 5-5M5 20h14"/>',
			'print'    => '<path d="M7 9V4h10v5M7 17H5a1 1 0 0 1-1-1v-6a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1h-2"/><path d="M7 14h10v6H7z"/>',
		);
	}

	/**
	 * One CSV line (with line break). Cells are quoted, and cells that start
	 * like a spreadsheet formula get a leading apostrophe so they can't run.
	 *
	 * @param array $cells Values.
	 * @return string
	 */
	public static function csv_line( array $cells ) {
		$out = array();
		foreach ( $cells as $value ) {
			$value = (string) $value;
			if ( preg_match( '/^[=+\-@\t\r]/', $value ) ) {
				$value = "'" . $value;
			}
			$out[] = '"' . str_replace( '"', '""', $value ) . '"';
		}
		return implode( ',', $out ) . "\r\n";
	}

	/**
	 * Allowed SVG markup for wp_kses().
	 *
	 * @return array
	 */
	private static function svg_kses() {
		$common = array(
			'd'               => true,
			'cx'              => true,
			'cy'              => true,
			'r'               => true,
			'x'               => true,
			'y'               => true,
			'rx'              => true,
			'width'           => true,
			'height'          => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'class'           => true,
			'style'           => true,
			'transform'       => true,
		);

		return array(
			'svg'    => array_merge(
				$common,
				array(
					'xmlns'       => true,
					'viewbox'     => true,
					'aria-hidden' => true,
					'focusable'   => true,
					'role'        => true,
				)
			),
			'path'   => $common,
			'circle' => array_merge(
				$common,
				array(
					'stroke-dasharray'  => true,
					'stroke-dashoffset' => true,
					'pathlength'        => true,
				)
			),
			'rect'   => $common,
		);
	}

	/**
	 * Returns an icon's SVG markup (decorative, hidden from screen readers).
	 *
	 * @param string $name Icon name.
	 * @param int    $size Pixel size.
	 * @return string
	 */
	public static function icon_html( $name, $size = 20 ) {
		$paths = self::icon_paths();
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}
		return sprintf(
			'<svg class="seofx-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="%1$d" height="%1$d" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%2$s</svg>',
			(int) $size,
			$paths[ $name ]
		);
	}

	/**
	 * Echoes an icon.
	 *
	 * @param string $name Icon name.
	 * @param int    $size Pixel size.
	 * @return void
	 */
	public static function icon( $name, $size = 20 ) {
		echo wp_kses( self::icon_html( $name, $size ), self::svg_kses() );
	}

	/**
	 * Icon matching a tone.
	 *
	 * @param string $tone good|warn|bad|info.
	 * @return string
	 */
	public static function tone_icon( $tone ) {
		$map = array(
			'good' => 'check',
			'warn' => 'alert',
			'bad'  => 'cross',
			'info' => 'info',
		);
		return isset( $map[ $tone ] ) ? $map[ $tone ] : 'info';
	}

	/**
	 * Page header with brand, page navigation and the Simple/Advanced switch.
	 *
	 * @param string $title    Page title (h1).
	 * @param string $subtitle One-line description.
	 * @param string $current  Current page slug.
	 * @return void
	 */
	public static function page_header( $title, $subtitle, $current ) {
		$nav = array(
			Admin::SLUG               => __( 'SEO Health', 'seo-fixer' ),
			Redirects_Page::SLUG      => __( 'Redirects', 'seo-fixer' ),
			Admin::SLUG . '-settings' => __( 'Settings', 'seo-fixer' ),
			Admin::SLUG . '-tools'    => __( 'Tools', 'seo-fixer' ),
		);
		$mode = Preferences::mode();
		?>
		<header class="seofx-topbar">
			<div class="seofx-topbar__brand">
				<span class="seofx-logo" aria-hidden="true"><?php self::icon( 'search', 18 ); ?></span>
				<span class="seofx-topbar__name"><?php esc_html_e( 'SEO Fixer', 'seo-fixer' ); ?></span>
			</div>
			<nav class="seofx-topbar__nav" aria-label="<?php esc_attr_e( 'SEO Fixer sections', 'seo-fixer' ); ?>">
				<?php foreach ( $nav as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>" class="seofx-topbar__link<?php echo $slug === $current ? ' is-active' : ''; ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<form class="seofx-topbar__mode" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="seofx_set_mode" />
				<?php wp_nonce_field( 'seofx_set_mode' ); ?>
				<span class="seofx-topbar__mode-label" id="seofx-mode-label"><?php esc_html_e( 'View', 'seo-fixer' ); ?></span>
				<span class="seofx-segmented seofx-segmented--sm" role="group" aria-labelledby="seofx-mode-label">
					<button type="submit" name="seofx_mode" value="simple" class="seofx-segmented__btn<?php echo 'simple' === $mode ? ' is-active' : ''; ?>" aria-pressed="<?php echo 'simple' === $mode ? 'true' : 'false'; ?>"><?php esc_html_e( 'Simple', 'seo-fixer' ); ?></button>
					<button type="submit" name="seofx_mode" value="advanced" class="seofx-segmented__btn<?php echo 'advanced' === $mode ? ' is-active' : ''; ?>" aria-pressed="<?php echo 'advanced' === $mode ? 'true' : 'false'; ?>"><?php esc_html_e( 'Advanced', 'seo-fixer' ); ?></button>
				</span>
			</form>
		</header>
		<div class="seofx-pagehead">
			<h1 class="seofx-pagehead__title"><?php echo esc_html( $title ); ?></h1>
			<?php if ( '' !== $subtitle ) : ?>
				<p class="seofx-pagehead__subtitle"><?php echo esc_html( $subtitle ); ?></p>
			<?php endif; ?>
		</div>
		<hr class="wp-header-end" />
		<?php
		self::render_flash();
	}

	/**
	 * Queues a one-time message for the current user's next page view.
	 *
	 * @param string $message Message.
	 * @param string $tone    good|warn|bad|info.
	 * @return void
	 */
	public static function flash( $message, $tone = 'good' ) {
		set_transient(
			'seofx_flash_' . get_current_user_id(),
			array(
				'message' => (string) $message,
				'tone'    => (string) $tone,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Prints and clears the queued message.
	 *
	 * @return void
	 */
	public static function render_flash() {
		$key   = 'seofx_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) || empty( $flash['message'] ) ) {
			return;
		}
		delete_transient( $key );
		self::callout( $flash['message'], $flash['tone'], 'status' );
	}

	/**
	 * Inline callout box.
	 *
	 * @param string $message Text.
	 * @param string $tone    good|warn|bad|info.
	 * @param string $role    ARIA role (status for live messages, '' for static).
	 * @param string $url     Optional link target.
	 * @param string $link    Optional link text.
	 * @return void
	 */
	public static function callout( $message, $tone = 'info', $role = '', $url = '', $link = '' ) {
		?>
		<div class="seofx-callout seofx-callout--<?php echo esc_attr( $tone ); ?>"<?php echo '' !== $role ? ' role="' . esc_attr( $role ) . '"' : ''; ?>>
			<?php self::icon( self::tone_icon( $tone ), 20 ); ?>
			<p>
				<?php echo esc_html( $message ); ?>
				<?php if ( '' !== $url && '' !== $link ) : ?>
					<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $link ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Collapsible list of %variables%.
	 *
	 * @return void
	 */
	public static function variables_help() {
		?>
		<details class="seofx-vars">
			<summary class="seofx-why__toggle"><?php self::icon( 'info', 14 ); ?><span><?php esc_html_e( 'Variables you can use', 'seo-fixer' ); ?></span></summary>
			<dl class="seofx-vars__list">
				<?php foreach ( Variables::definitions() as $name => $description ) : ?>
					<dt><code><?php echo esc_html( '%' . $name . '%' ); ?></code></dt>
					<dd><?php echo esc_html( $description ); ?></dd>
				<?php endforeach; ?>
			</dl>
		</details>
		<?php
	}

	/**
	 * URL the way Google shows it: "example.com › blog › post".
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function display_url( $url ) {
		$parts = wp_parse_url( $url );
		$host  = isset( $parts['host'] ) ? $parts['host'] : '';
		if ( isset( $parts['port'] ) ) {
			$host .= ':' . $parts['port'];
		}
		$path = isset( $parts['path'] ) ? array_filter( explode( '/', $parts['path'] ), 'strlen' ) : array();
		return implode( ' › ', array_merge( array( $host ), array_map( 'rawurldecode', $path ) ) );
	}

	/**
	 * Google-style result preview with desktop/mobile switch and length meters.
	 *
	 * The server renders the current result; admin.js keeps it live while typing
	 * using the same template rules as the front end.
	 *
	 * @param array $args {
	 *     @type string $title_input    Id of the title input.
	 *     @type string $desc_input     Id of the description input.
	 *     @type string $title_template Used while the title input is empty.
	 *     @type string $desc_template  Used while the description input is empty.
	 *     @type string $title          Current resolved title.
	 *     @type string $description    Current resolved description.
	 *     @type array  $vars           Variable values.
	 *     @type string $url            Page URL.
	 *     @type string $live           'post' to follow the editor's post title.
	 * }
	 * @return void
	 */
	public static function serp_preview( array $args ) {
		$args   = wp_parse_args(
			$args,
			array(
				'title_input'    => '',
				'desc_input'     => '',
				'title_template' => '',
				'desc_template'  => '',
				'title'          => '',
				'description'    => '',
				'vars'           => array(),
				'url'            => home_url( '/' ),
				'live'           => '',
				'hidden'         => false,
			)
		);
		$config = array(
			'titleInput'    => $args['title_input'],
			'descInput'     => $args['desc_input'],
			'titleTemplate' => $args['title_template'],
			'descTemplate'  => $args['desc_template'],
			'vars'          => (object) $args['vars'],
			'sep'           => isset( $args['vars']['sep'] ) ? $args['vars']['sep'] : '',
			'live'          => $args['live'],
		);
		$site   = isset( $args['vars']['sitename'] ) ? $args['vars']['sitename'] : get_bloginfo( 'name' );
		$icon   = get_site_icon_url( 32 );
		?>
		<div class="seofx-serp<?php echo $args['hidden'] ? ' seofx-serp--hidden' : ''; ?>" data-seofx-serp="<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>">
			<div class="seofx-serp__head">
				<span class="seofx-serp__label" id="<?php echo esc_attr( $args['title_input'] . '-serp-label' ); ?>"><?php esc_html_e( 'Google preview', 'seo-fixer' ); ?></span>
				<span class="seofx-serp__hidden-badge"><?php self::badge( __( 'Hidden from search results', 'seo-fixer' ), 'bad' ); ?></span>
				<span class="seofx-segmented seofx-segmented--sm" role="group" aria-labelledby="<?php echo esc_attr( $args['title_input'] . '-serp-label' ); ?>">
					<button type="button" class="seofx-segmented__btn is-active" data-seofx-serp-device="desktop" aria-pressed="true"><?php esc_html_e( 'Desktop', 'seo-fixer' ); ?></button>
					<button type="button" class="seofx-segmented__btn" data-seofx-serp-device="mobile" aria-pressed="false"><?php esc_html_e( 'Mobile', 'seo-fixer' ); ?></button>
				</span>
			</div>
			<div class="seofx-serp__result">
				<div class="seofx-serp__site">
					<span class="seofx-serp__favicon" aria-hidden="true">
						<?php if ( $icon ) : ?>
							<img src="<?php echo esc_url( $icon ); ?>" alt="" width="18" height="18" />
						<?php else : ?>
							<?php echo esc_html( mb_strtoupper( mb_substr( $site, 0, 1 ) ) ); ?>
						<?php endif; ?>
					</span>
					<span class="seofx-serp__sitetext">
						<span class="seofx-serp__sitename"><?php echo esc_html( $site ); ?></span>
						<span class="seofx-serp__url"><?php echo esc_html( self::display_url( $args['url'] ) ); ?></span>
					</span>
				</div>
				<div class="seofx-serp__title" data-seofx-serp-title><?php echo esc_html( $args['title'] ); ?></div>
				<div class="seofx-serp__desc" data-seofx-serp-desc><?php echo esc_html( $args['description'] ); ?></div>
			</div>
			<div class="seofx-serp__meters">
				<?php
				foreach ( array(
					'title' => __( 'Title', 'seo-fixer' ),
					'desc'  => __( 'Description', 'seo-fixer' ),
				) as $kind => $label ) :
					?>
					<div class="seofx-charbar" data-seofx-serp-bar="<?php echo esc_attr( $kind ); ?>">
						<span class="seofx-charbar__name"><?php echo esc_html( $label ); ?></span>
						<span class="seofx-charbar__track"><span class="seofx-charbar__fill"></span></span>
						<span class="seofx-charbar__text" aria-live="polite"></span>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="seofx-serp__note"><?php esc_html_e( 'Google sometimes rewrites titles and descriptions. This shows what SEO Fixer sends.', 'seo-fixer' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Small coloured label.
	 *
	 * @param string $label Text.
	 * @param string $tone  good|warn|bad|info|neutral.
	 * @return void
	 */
	public static function badge( $label, $tone = 'neutral' ) {
		printf( '<span class="seofx-badge seofx-badge--%1$s">%2$s</span>', esc_attr( $tone ), esc_html( $label ) );
	}

	/**
	 * "Why it matters" disclosure (native <details>, works without JavaScript).
	 *
	 * @param string $text Explanation.
	 * @return void
	 */
	public static function why( $text ) {
		if ( '' === $text ) {
			return;
		}
		?>
		<details class="seofx-why">
			<summary class="seofx-why__toggle"><?php self::icon( 'info', 14 ); ?><span><?php esc_html_e( 'Why it matters', 'seo-fixer' ); ?></span></summary>
			<div class="seofx-why__panel"><?php echo esc_html( $text ); ?></div>
		</details>
		<?php
	}

	/**
	 * Tone for a 0–100 score.
	 *
	 * @param int|null $score Score.
	 * @return string good|warn|bad|neutral
	 */
	public static function score_tone( $score ) {
		if ( null === $score ) {
			return 'neutral';
		}
		if ( $score >= 80 ) {
			return 'good';
		}
		return $score >= 50 ? 'warn' : 'bad';
	}

	/**
	 * Circular score meter.
	 *
	 * @param int|null $score   0–100, or null when not measured yet.
	 * @param string   $caption Text under the number.
	 * @param string   $size    lg|sm.
	 * @return void
	 */
	public static function score_ring( $score, $caption = '', $size = 'lg' ) {
		$score  = null === $score ? null : max( 0, min( 100, (int) $score ) );
		$tone   = self::score_tone( $score );
		$offset = null === $score ? 100 : 100 - $score;
		$label  = null === $score
			? __( 'SEO health score: not measured yet', 'seo-fixer' )
			/* translators: %d: score from 0 to 100. */
			: sprintf( __( 'SEO health score: %d out of 100', 'seo-fixer' ), $score );
		?>
		<div class="seofx-score seofx-score--<?php echo esc_attr( $size ); ?> seofx-score--<?php echo esc_attr( $tone ); ?>" role="img" aria-label="<?php echo esc_attr( $label ); ?>">
			<svg viewBox="0 0 120 120" aria-hidden="true" focusable="false">
				<circle class="seofx-score__track" cx="60" cy="60" r="52" pathLength="100" />
				<circle class="seofx-score__value" cx="60" cy="60" r="52" pathLength="100" stroke-dasharray="100" stroke-dashoffset="<?php echo esc_attr( (string) $offset ); ?>" />
			</svg>
			<span class="seofx-score__number"><?php echo null === $score ? '–' : esc_html( (string) $score ); ?></span>
			<?php if ( '' !== $caption ) : ?>
				<span class="seofx-score__caption"><?php echo esc_html( $caption ); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * List of check results with tone icons and optional action links.
	 *
	 * @param array[] $checks Items from Site_Status::checks().
	 * @return void
	 */
	public static function status_list( array $checks ) {
		?>
		<ul class="seofx-checks">
			<?php foreach ( $checks as $check ) : ?>
				<li class="seofx-checks__item seofx-checks__item--<?php echo esc_attr( $check['tone'] ); ?>">
					<span class="seofx-checks__icon"><?php self::icon( self::tone_icon( $check['tone'] ), 18 ); ?></span>
					<span class="seofx-checks__body">
						<strong><?php echo esc_html( $check['label'] ); ?></strong>
						<span><?php echo esc_html( $check['message'] ); ?></span>
					</span>
					<?php if ( ! empty( $check['action_url'] ) ) : ?>
						<a class="seofx-btn seofx-btn--secondary seofx-btn--sm" href="<?php echo esc_url( $check['action_url'] ); ?>"><?php echo esc_html( $check['action_label'] ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Character-length meter (updated live by admin.js).
	 *
	 * @param string $target_id Input id to measure.
	 * @param int    $min       Below this: too short (amber).
	 * @param int    $max       Above this: too long (red).
	 * @return void
	 */
	public static function charbar( $target_id, $min, $max ) {
		?>
		<div class="seofx-charbar" data-seofx-charbar="<?php echo esc_attr( $target_id ); ?>" data-min="<?php echo esc_attr( (string) (int) $min ); ?>" data-max="<?php echo esc_attr( (string) (int) $max ); ?>">
			<span class="seofx-charbar__track"><span class="seofx-charbar__fill"></span></span>
			<span class="seofx-charbar__text" aria-live="polite"></span>
		</div>
		<?php
	}
}
