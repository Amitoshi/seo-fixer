<?php
/**
 * SEO Health dashboard: score, scan button and what the scan found.
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Fixes\Fixer;
use Seofx\Scanner\Checks;
use Seofx\Scanner\Scanner;
use Seofx\Security;
use Seofx\Site_Status;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard screen.
 */
final class Dashboard_Page {

	/**
	 * Severity → tone and badge label.
	 *
	 * @return array<string, array{tone: string, label: string}>
	 */
	public static function severities() {
		return array(
			'critical'  => array(
				'tone'  => 'bad',
				'label' => __( 'Critical', 'seo-fixer' ),
			),
			'important' => array(
				'tone'  => 'warn',
				'label' => __( 'Important', 'seo-fixer' ),
			),
			'minor'     => array(
				'tone'  => 'info',
				'label' => __( 'Minor', 'seo-fixer' ),
			),
			'hint'      => array(
				'tone'  => 'neutral',
				'label' => __( 'Tip', 'seo-fixer' ),
			),
		);
	}

	/**
	 * Everything the scan found, most serious first.
	 *
	 * @param array $summary Scan summary.
	 * @return array{problems: array[], passed: array[], unknown: array[]}
	 */
	public static function findings( array $summary ) {
		$order    = array_flip( array_keys( self::severities() ) );
		$problems = array();
		$passed   = array();
		$unknown  = array();

		foreach ( Checks::site_checks() as $id => $check ) {
			if ( ! isset( $summary['site'][ $id ] ) ) {
				continue;
			}
			$result = $summary['site'][ $id ];
			$item   = array(
				'id'           => $id,
				'severity'     => $check['severity'],
				'label'        => $check['label'],
				'message'      => $result['message'],
				'action_url'   => $result['action_url'],
				'action_label' => $result['action_label'],
				'pages'        => 0,
			);
			if ( false === $result['pass'] ) {
				$problems[] = $item;
			} elseif ( true === $result['pass'] ) {
				$passed[] = $item;
			} else {
				$unknown[] = $item; // Couldn't be checked (the site didn't answer its own request).
			}
		}

		foreach ( Checks::page_checks() as $id => $check ) {
			$count = isset( $summary['issues'][ $id ] ) ? (int) $summary['issues'][ $id ] : 0;
			if ( ! $count ) {
				continue;
			}
			$problems[] = array(
				'id'           => $id,
				'severity'     => $check['severity'],
				'label'        => $check['label'],
				'message'      => sprintf( translate_nooped_plural( $check['message'], $count, 'seo-fixer' ), number_format_i18n( $count ) ),
				'action_url'   => '',
				'action_label' => '',
				'pages'        => $count,
			);
		}

		// Stable sort: severity first, then site problems before page problems, then most pages.
		$position = array_flip( wp_list_pluck( $problems, 'id' ) );
		usort(
			$problems,
			static function ( $a, $b ) use ( $order, $position ) {
				if ( $order[ $a['severity'] ] !== $order[ $b['severity'] ] ) {
					return $order[ $a['severity'] ] - $order[ $b['severity'] ];
				}
				if ( $a['pages'] !== $b['pages'] && $a['pages'] && $b['pages'] ) {
					return $b['pages'] - $a['pages'];
				}
				return $position[ $a['id'] ] - $position[ $b['id'] ];
			}
		);

		return array(
			'problems' => $problems,
			'passed'   => $passed,
			'unknown'  => $unknown,
		);
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render() {
		Security::require_manage();

		$summary = Scanner::summary();
		$view    = self::view();
		if ( ! $summary && 'changes' !== $view ) {
			$view = ''; // Only the change history works before the first scan (e.g. right after an import).
		}
		?>
		<div class="wrap seofx-wrap">
			<?php
			UI::page_header( __( 'SEO Health', 'seo-fixer' ), __( 'See how search-ready your site is, and fix problems in one click.', 'seo-fixer' ), Admin::SLUG );

			if ( $summary || 'changes' === $view ) {
				self::tabs( $view, $summary );
			}

			if ( 'pages' === $view ) {
				Health_Report::render_pages( $summary );
			} elseif ( 'fix' === $view ) {
				Fix_Page::render_fix( $summary );
			} elseif ( 'changes' === $view ) {
				Fix_Page::render_changes();
			} elseif ( 'print' === $view ) {
				Health_Report::render_print( $summary );
			} else {
				self::overview( $summary );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Overview: score, scan button, breakdown and what the scan found.
	 *
	 * @param array|null $summary Scan summary (null before the first scan).
	 * @return void
	 */
	private static function overview( $summary ) {
		$progress = Scanner::progress();
		$set_up   = Wizard_Page::is_completed();
		$score    = $summary ? (int) $summary['score'] : null;
		$tone     = UI::score_tone( $score );
		$captions = array(
			'good'    => __( 'Healthy', 'seo-fixer' ),
			'warn'    => __( 'Needs work', 'seo-fixer' ),
			'bad'     => __( 'Needs attention', 'seo-fixer' ),
			'neutral' => __( 'Not scanned yet', 'seo-fixer' ),
		);
		?>
			<div class="seofx-grid seofx-grid--hero">
				<section class="seofx-card seofx-hero" aria-labelledby="seofx-hero-title">
					<?php UI::score_ring( $score, $captions[ $tone ] ); ?>
					<div class="seofx-hero__body">
						<h2 class="seofx-hero__title" id="seofx-hero-title"><?php esc_html_e( 'Your SEO health score', 'seo-fixer' ); ?></h2>
						<p class="seofx-hero__text">
							<?php
							if ( ! $summary ) {
								esc_html_e( 'SEO Fixer will check every page for missing titles and descriptions, images without alt text, broken links and more. It only reads your site — nothing is changed.', 'seo-fixer' );
							} elseif ( $summary['critical'] ) {
								esc_html_e( 'Something important is stopping Google from showing your site. Fix the critical items below first — your score can’t go above 45 until you do.', 'seo-fixer' );
							} elseif ( $score >= 80 ) {
								esc_html_e( 'Your site is in good shape. Work through the remaining items to get even closer to 100.', 'seo-fixer' );
							} else {
								esc_html_e( 'Your site is on its way. Fixing the items below will raise your score and help more people find you.', 'seo-fixer' );
							}
							?>
						</p>
						<div class="seofx-hero__actions">
							<?php if ( ! $set_up ) : ?>
								<a class="seofx-btn seofx-btn--primary" href="<?php echo esc_url( Wizard_Page::url() ); ?>">
									<?php esc_html_e( 'Start the 2-minute setup', 'seo-fixer' ); ?>
									<?php UI::icon( 'arrow', 18 ); ?>
								</a>
							<?php endif; ?>
							<?php // Without JavaScript the form starts the scan and WordPress's scheduler finishes it. ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-seofx-scan data-nonce="<?php echo esc_attr( wp_create_nonce( Scanner::NONCE ) ); ?>" data-running="<?php echo $progress['running'] ? '1' : '0'; ?>">
								<input type="hidden" name="action" value="seofx_scan_begin" />
								<?php wp_nonce_field( 'seofx_scan_begin' ); ?>
								<button type="submit" class="seofx-btn <?php echo $set_up ? 'seofx-btn--primary' : 'seofx-btn--secondary'; ?>"<?php echo $progress['running'] ? ' disabled' : ''; ?>>
									<?php UI::icon( 'sparkle', 18 ); ?>
									<span><?php echo $summary ? esc_html__( 'Scan again', 'seo-fixer' ) : esc_html__( 'Scan my site', 'seo-fixer' ); ?></span>
								</button>
							</form>
						</div>
						<div class="seofx-progress" data-seofx-scan-progress<?php echo $progress['running'] ? '' : ' hidden'; ?>>
							<div class="seofx-progress__track" role="progressbar" aria-label="<?php esc_attr_e( 'Scan progress', 'seo-fixer' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $progress['percent'] ); ?>">
								<span class="seofx-progress__fill" style="width: <?php echo esc_attr( (string) $progress['percent'] ); ?>%"></span>
							</div>
							<p class="seofx-progress__label" aria-live="polite"><?php echo esc_html( $progress['label'] ); ?></p>
						</div>
						<?php if ( $summary ) : ?>
							<p class="seofx-hero__meta">
								<?php
								printf(
									/* translators: 1: time since the last scan, e.g. "5 minutes", 2: number of pages. */
									esc_html( _n( 'Last full scan %1$s ago · %2$s page checked · updates by itself when you edit pages', 'Last full scan %1$s ago · %2$s pages checked · updates by itself when you edit pages', (int) $summary['pages'], 'seo-fixer' ) ),
									esc_html( human_time_diff( (int) $summary['scanned_at'] ) ),
									esc_html( number_format_i18n( (int) $summary['pages'] ) )
								);
								?>
							</p>
						<?php endif; ?>
					</div>
				</section>

				<?php
				if ( $summary ) {
					self::breakdown( $summary );
				} else {
					self::quick_checks();
				}
				?>
			</div>

			<?php
			if ( $summary ) {
				self::found( $summary );
			}
			self::getting_started( $set_up, (bool) $summary );
	}

	/**
	 * Current view (read-only navigation).
	 *
	 * @return string ''|pages|fix|changes|print
	 */
	private static function view() {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.
		return in_array( $view, array( 'pages', 'fix', 'changes', 'print' ), true ) ? $view : '';
	}

	/**
	 * Overview / Pages / Printable report tabs.
	 *
	 * @param string     $view    Current view.
	 * @param array|null $summary Scan summary (null before the first scan: only Overview and Changes).
	 * @return void
	 */
	private static function tabs( $view, $summary ) {
		$tabs = array(
			''      => __( 'Overview', 'seo-fixer' ),
			/* translators: %s: number of pages. */
			'pages'   => sprintf( __( 'Pages (%s)', 'seo-fixer' ), number_format_i18n( $summary ? (int) $summary['pages'] : 0 ) ),
			'changes' => __( 'Changes', 'seo-fixer' ),
			'print'   => __( 'Printable report', 'seo-fixer' ),
		);
		if ( ! $summary ) {
			unset( $tabs['pages'], $tabs['print'] );
		}
		// The fix view belongs under "Pages".
		$current = 'fix' === $view ? 'pages' : $view;
		?>
		<nav class="seofx-tabs seofx-no-print" aria-label="<?php esc_attr_e( 'SEO Health sections', 'seo-fixer' ); ?>">
			<?php foreach ( $tabs as $id => $label ) : ?>
				<a class="seofx-tabs__tab<?php echo $id === $current ? ' is-active' : ''; ?>" href="<?php echo esc_url( Health_Report::url( $id ) ); ?>"<?php echo $id === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Score breakdown card.
	 *
	 * @param array $summary Scan summary.
	 * @return void
	 */
	private static function breakdown( array $summary ) {
		$content = null === $summary['content_score'] ? null : (int) $summary['content_score'];
		?>
		<section class="seofx-card" aria-labelledby="seofx-breakdown-title">
			<header class="seofx-card__header">
				<h2 class="seofx-card__title" id="seofx-breakdown-title"><?php esc_html_e( 'How your score adds up', 'seo-fixer' ); ?></h2>
				<p class="seofx-card__desc"><?php esc_html_e( 'Site settings count for 40%, your pages for 60%.', 'seo-fixer' ); ?></p>
			</header>
			<div class="seofx-breakdown">
				<div class="seofx-breakdown__item">
					<?php UI::score_ring( (int) $summary['site_score'], '', 'sm' ); ?>
					<span class="seofx-breakdown__body">
						<strong><?php esc_html_e( 'Site settings', 'seo-fixer' ); ?></strong>
						<span>
							<?php
							$failed = (int) $summary['site_failed'];
							echo esc_html(
								0 === $failed
									? __( 'Everything set up correctly.', 'seo-fixer' )
									/* translators: %d: number of settings to fix. */
									: sprintf( _n( '%d thing to fix.', '%d things to fix.', $failed, 'seo-fixer' ), $failed )
							);
							?>
						</span>
					</span>
				</div>
				<div class="seofx-breakdown__item">
					<?php UI::score_ring( $content, '', 'sm' ); ?>
					<span class="seofx-breakdown__body">
						<strong><?php esc_html_e( 'Your pages', 'seo-fixer' ); ?></strong>
						<span>
							<?php
							echo esc_html(
								null === $content
									? __( 'No published pages yet.', 'seo-fixer' )
									/* translators: %s: number of pages. */
									: sprintf( _n( 'Average of %s page.', 'Average of %s pages.', (int) $summary['pages'], 'seo-fixer' ), number_format_i18n( (int) $summary['pages'] ) )
							);
							?>
						</span>
					</span>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Quick checks card (before the first scan).
	 *
	 * @return void
	 */
	private static function quick_checks() {
		$checks   = Site_Status::checks();
		$problems = count(
			array_filter(
				$checks,
				static function ( $check ) {
					return 'good' !== $check['tone'];
				}
			)
		);
		?>
		<section class="seofx-card" aria-labelledby="seofx-quick-title">
			<header class="seofx-card__header seofx-card__header--row">
				<h2 class="seofx-card__title" id="seofx-quick-title"><?php esc_html_e( 'Quick checks', 'seo-fixer' ); ?></h2>
				<?php
				if ( 0 === $problems ) {
					UI::badge( __( 'All good', 'seo-fixer' ), 'good' );
				} else {
					/* translators: %d: number of items that need attention. */
					UI::badge( sprintf( _n( '%d needs attention', '%d need attention', $problems, 'seo-fixer' ), $problems ), 'warn' );
				}
				?>
			</header>
			<?php UI::status_list( $checks ); ?>
		</section>
		<?php
	}

	/**
	 * "What we found" card.
	 *
	 * @param array $summary Scan summary.
	 * @return void
	 */
	private static function found( array $summary ) {
		$findings   = self::findings( $summary );
		$severities = self::severities();
		$guidance   = Checks::guidance();
		$groups     = array(
			'critical'  => array(
				'title' => __( 'Fix these first', 'seo-fixer' ),
				'desc'  => __( 'These stop Google from showing your site.', 'seo-fixer' ),
			),
			'important' => array(
				'title' => __( 'Important', 'seo-fixer' ),
				'desc'  => __( 'These hold back how your pages appear in Google.', 'seo-fixer' ),
			),
			'minor'     => array(
				'title' => __( 'Worth fixing', 'seo-fixer' ),
				'desc'  => __( 'Smaller improvements that add up.', 'seo-fixer' ),
			),
			'hint'      => array(
				'title' => __( 'Tips', 'seo-fixer' ),
				'desc'  => __( 'Optional ideas. They don’t lower your score.', 'seo-fixer' ),
			),
		);
		$grouped    = array_fill_keys( array_keys( $groups ), array() );
		foreach ( $findings['problems'] as $item ) {
			$grouped[ $item['severity'] ][] = $item;
		}
		?>
		<h2 class="seofx-section-title"><?php esc_html_e( 'What we found', 'seo-fixer' ); ?></h2>

		<?php if ( array() === $findings['problems'] ) : ?>
			<?php UI::callout( __( 'No problems found. Great work!', 'seo-fixer' ), 'good' ); ?>
		<?php endif; ?>

		<?php if ( $findings['unknown'] ) : ?>
			<?php
			UI::callout(
				sprintf(
					/* translators: %s: list of check names in quotes, e.g. “One title per page” and “One main heading per page”. */
					__( 'These checks couldn’t run: %s. Your site didn’t answer when SEO Fixer tried to load your homepage, so they aren’t counted. Scan again later.', 'seo-fixer' ),
					wp_sprintf(
						'%l',
						array_map(
							static function ( $label ) {
								/* translators: %s: name of a check. */
								return sprintf( __( '“%s”', 'seo-fixer' ), $label );
							},
							wp_list_pluck( $findings['unknown'], 'label' )
						)
					)
				),
				'info'
			);
			?>
		<?php endif; ?>

		<?php foreach ( $groups as $severity_id => $group ) : ?>
			<?php
			if ( array() === $grouped[ $severity_id ] ) {
				continue;
			}
			$severity = $severities[ $severity_id ];
			?>
			<section class="seofx-card seofx-group seofx-group--<?php echo esc_attr( $severity['tone'] ); ?>" aria-labelledby="seofx-group-<?php echo esc_attr( $severity_id ); ?>">
				<header class="seofx-card__header seofx-card__header--row">
					<div>
						<h3 class="seofx-card__title" id="seofx-group-<?php echo esc_attr( $severity_id ); ?>"><?php echo esc_html( $group['title'] ); ?></h3>
						<p class="seofx-card__desc"><?php echo esc_html( $group['desc'] ); ?></p>
					</div>
					<?php UI::badge( number_format_i18n( count( $grouped[ $severity_id ] ) ), $severity['tone'] ); ?>
				</header>
				<ul class="seofx-checks seofx-findings">
					<?php foreach ( $grouped[ $severity_id ] as $item ) : ?>
						<li class="seofx-checks__item seofx-checks__item--<?php echo esc_attr( $severity['tone'] ); ?>" data-seofx-finding="<?php echo esc_attr( $item['id'] ); ?>">
							<span class="seofx-checks__icon"><?php UI::icon( UI::tone_icon( 'neutral' === $severity['tone'] ? 'info' : $severity['tone'] ), 18 ); ?></span>
							<span class="seofx-checks__body">
								<strong><?php echo esc_html( $item['label'] ); ?></strong>
								<span><?php echo esc_html( $item['message'] ); ?></span>
								<?php
								if ( isset( $guidance[ $item['id'] ] ) ) {
									UI::why( $guidance[ $item['id'] ]['why'] . ' ' . $guidance[ $item['id'] ]['how'] );
								}
								?>
							</span>
							<span class="seofx-findings__actions">
								<?php if ( $item['pages'] && '' !== Fixer::fix_for( $item['id'] ) ) : ?>
									<a class="seofx-btn seofx-btn--primary seofx-btn--sm" href="<?php echo esc_url( Health_Report::url( 'fix', array( 'issue' => $item['id'] ) ) ); ?>"><?php UI::icon( 'sparkle', 16 ); ?><?php esc_html_e( 'Fix', 'seo-fixer' ); ?></a>
								<?php endif; ?>
								<?php if ( $item['pages'] ) : ?>
									<a class="seofx-btn seofx-btn--secondary seofx-btn--sm" href="<?php echo esc_url( Health_Report::url( 'pages', array( 'issue' => $item['id'] ) ) ); ?>">
										<?php
										/* translators: %s: number of pages. */
										echo esc_html( sprintf( _n( 'See %s page', 'See %s pages', $item['pages'], 'seo-fixer' ), number_format_i18n( $item['pages'] ) ) );
										?>
									</a>
								<?php endif; ?>
								<?php if ( 'sitemap_submitted' === $item['id'] ) : ?>
									<button type="button" class="seofx-btn seofx-btn--ghost seofx-btn--sm" data-seofx-scan-dismiss="sitemap_submitted"><?php esc_html_e( 'I’ve done this', 'seo-fixer' ); ?></button>
								<?php endif; ?>
								<?php if ( '' !== $item['action_url'] ) : ?>
									<a class="seofx-btn seofx-btn--secondary seofx-btn--sm" href="<?php echo esc_url( $item['action_url'] ); ?>"><?php echo esc_html( $item['action_label'] ); ?></a>
								<?php endif; ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endforeach; ?>

		<?php if ( $findings['passed'] ) : ?>
			<section class="seofx-card" aria-label="<?php esc_attr_e( 'Passed checks', 'seo-fixer' ); ?>">
				<details class="seofx-passed">
					<summary class="seofx-why__toggle">
						<?php UI::icon( 'check', 14 ); ?>
						<span>
							<?php
							/* translators: %d: number of passed checks. */
							echo esc_html( sprintf( _n( '%d site check passed', '%d site checks passed', count( $findings['passed'] ), 'seo-fixer' ), count( $findings['passed'] ) ) );
							?>
						</span>
					</summary>
					<ul class="seofx-checks">
						<?php foreach ( $findings['passed'] as $item ) : ?>
							<li class="seofx-checks__item seofx-checks__item--good">
								<span class="seofx-checks__icon"><?php UI::icon( 'check', 18 ); ?></span>
								<span class="seofx-checks__body">
									<strong><?php echo esc_html( $item['label'] ); ?></strong>
									<span><?php echo esc_html( $item['message'] ); ?></span>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			</section>
		<?php endif; ?>
		<?php
	}

	/**
	 * Getting started steps.
	 *
	 * @param bool $set_up  Wizard completed.
	 * @param bool $scanned A scan has finished.
	 * @return void
	 */
	private static function getting_started( $set_up, $scanned ) {
		?>
		<section class="seofx-card" aria-labelledby="seofx-start-title">
			<header class="seofx-card__header">
				<h2 class="seofx-card__title" id="seofx-start-title"><?php esc_html_e( 'Getting started', 'seo-fixer' ); ?></h2>
				<p class="seofx-card__desc"><?php esc_html_e( 'Three short steps to a search-ready site.', 'seo-fixer' ); ?></p>
			</header>
			<ol class="seofx-steps">
				<li class="seofx-steps__item<?php echo $set_up ? ' is-done' : ''; ?>">
					<span class="seofx-steps__num" aria-hidden="true">1</span>
					<span class="seofx-steps__body">
						<strong>
							<?php esc_html_e( 'Run the 2-minute setup', 'seo-fixer' ); ?>
							<?php if ( $set_up ) : ?>
								<span class="screen-reader-text"><?php esc_html_e( '(completed)', 'seo-fixer' ); ?></span>
							<?php endif; ?>
						</strong>
						<span><?php esc_html_e( 'Tell SEO Fixer about your site, brand, social profiles and location.', 'seo-fixer' ); ?></span>
					</span>
					<a class="seofx-btn <?php echo $set_up ? 'seofx-btn--secondary' : 'seofx-btn--primary'; ?> seofx-btn--sm" href="<?php echo esc_url( Wizard_Page::url() ); ?>"><?php echo $set_up ? esc_html__( 'Run again', 'seo-fixer' ) : esc_html__( 'Start setup', 'seo-fixer' ); ?></a>
				</li>
				<li class="seofx-steps__item<?php echo $scanned ? ' is-done' : ''; ?>">
					<span class="seofx-steps__num" aria-hidden="true">2</span>
					<span class="seofx-steps__body">
						<strong>
							<?php esc_html_e( 'Scan your site', 'seo-fixer' ); ?>
							<?php if ( $scanned ) : ?>
								<span class="screen-reader-text"><?php esc_html_e( '(completed)', 'seo-fixer' ); ?></span>
							<?php endif; ?>
						</strong>
						<span><?php esc_html_e( 'Get your SEO health score and a plain-English list of issues.', 'seo-fixer' ); ?></span>
					</span>
					<?php
					if ( $scanned ) {
						UI::badge( __( 'Done', 'seo-fixer' ), 'good' );
					}
					?>
				</li>
				<li class="seofx-steps__item">
					<span class="seofx-steps__num" aria-hidden="true">3</span>
					<span class="seofx-steps__body">
						<strong><?php esc_html_e( 'Fix issues in one click', 'seo-fixer' ); ?></strong>
						<span><?php esc_html_e( 'Fill in missing descriptions, shorten long titles and add image text — then undo anything you don’t like.', 'seo-fixer' ); ?></span>
					</span>
					<?php if ( $scanned ) : ?>
						<a class="seofx-btn seofx-btn--secondary seofx-btn--sm" href="<?php echo esc_url( Health_Report::url( 'changes' ) ); ?>"><?php esc_html_e( 'See changes', 'seo-fixer' ); ?></a>
					<?php else : ?>
						<?php UI::badge( __( 'After the scan', 'seo-fixer' ), 'neutral' ); ?>
					<?php endif; ?>
				</li>
			</ol>
		</section>
		<?php
	}
}
