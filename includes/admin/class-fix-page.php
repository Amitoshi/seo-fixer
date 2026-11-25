<?php
/**
 * Fix screens inside SEO Health: preview and apply fixes for one issue,
 * "Fix all", the automatic-fix switch, and the change history with Undo.
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Fixes\Autofix;
use Seofx\Fixes\Fixer;
use Seofx\Fixes\History;
use Seofx\Options;
use Seofx\Scanner\Checks;
use Seofx\Scanner\Results;
use Seofx\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Fix and Changes views.
 */
final class Fix_Page {

	/**
	 * Pages per preview screen.
	 */
	const PER_PAGE = 20;

	/**
	 * "Fix all" requests handled in one go without JavaScript.
	 */
	const NOJS_ROUNDS = 5;

	/**
	 * Nonce action for "Fix all" requests.
	 */
	const NONCE = 'seofx_fix';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_seofx_fix_apply', array( $this, 'handle_apply' ) );
		add_action( 'admin_post_seofx_fix_all', array( $this, 'handle_fix_all' ) );
		add_action( 'admin_post_seofx_fix_undo', array( $this, 'handle_undo' ) );
		add_action( 'admin_post_seofx_autofix', array( $this, 'handle_autofix' ) );
		add_action( 'wp_ajax_seofx_fix_all', array( $this, 'ajax_fix_all' ) );
	}

	/**
	 * Reads a GET parameter (read-only view state).
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function query_arg( $key ) {
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view state.
	}

	/**
	 * What each fix does, in plain English.
	 *
	 * @param string $fix description|title|alt.
	 * @return string
	 */
	private static function explanation( $fix ) {
		$texts = array(
			'description' => __( 'SEO Fixer writes a description from each page’s first meaningful sentences. When a page says too little, it adds the page title and your tagline. Descriptions you wrote yourself are never changed.', 'seo-fixer' ),
			'title'       => __( 'When a title is too long because the site name is added to it, the page gets its own title without the site name (it follows the page title if you rename the page). Titles you wrote yourself are never changed. Titles that are too long on their own need a shorter wording from you.', 'seo-fixer' ),
			'alt'         => __( 'Each image gets alt text from its Media Library details, its file name, the heading above it or the page title, and the Media Library keeps a copy for next time. Images that already have alt text are never changed.', 'seo-fixer' ),
		);
		return $texts[ $fix ];
	}

	/**
	 * Fix view for one issue.
	 *
	 * @param array $summary Scan summary.
	 * @return void
	 */
	public static function render_fix( array $summary ) {
		$issue = sanitize_key( self::query_arg( 'issue' ) );
		$fix   = Fixer::fix_for( $issue );
		if ( '' === $fix ) {
			UI::callout( __( 'This problem can’t be fixed automatically. Open the page list to see which pages need a look.', 'seo-fixer' ), 'info', '', Health_Report::url( 'pages', array( 'issue' => $issue ) ), __( 'Show pages', 'seo-fixer' ) );
			return;
		}

		$check    = Checks::page_checks()[ $issue ];
		$severity = Dashboard_Page::severities()[ $check['severity'] ];
		$count    = isset( $summary['issues'][ $issue ] ) ? (int) $summary['issues'][ $issue ] : 0;

		self::done_notice( $issue );
		?>
		<section class="seofx-card seofx-issue-card seofx-issue-card--<?php echo esc_attr( $severity['tone'] ); ?>" aria-labelledby="seofx-fix-title">
			<header class="seofx-card__header seofx-card__header--row">
				<h2 class="seofx-card__title" id="seofx-fix-title">
					<?php
					/* translators: %s: name of the problem, e.g. "No description". */
					echo esc_html( sprintf( __( 'Fix: %s', 'seo-fixer' ), $check['label'] ) );
					?>
					<?php UI::badge( $severity['label'], $severity['tone'] ); ?>
				</h2>
				<a class="seofx-btn seofx-btn--ghost seofx-btn--sm" href="<?php echo esc_url( Health_Report::url( 'pages', array( 'issue' => $issue ) ) ); ?>"><?php esc_html_e( 'Show the pages', 'seo-fixer' ); ?></a>
			</header>
			<?php if ( $count ) : ?>
				<p class="seofx-issue-card__lead"><?php echo esc_html( sprintf( translate_nooped_plural( $check['message'], $count, 'seo-fixer' ), number_format_i18n( $count ) ) ); ?></p>
			<?php endif; ?>
			<p class="seofx-card__desc"><?php echo esc_html( self::explanation( $fix ) ); ?></p>
			<p class="seofx-card__desc"><?php esc_html_e( 'Check the suggestions below and change any you like before applying them. Everything can be undone under Changes.', 'seo-fixer' ); ?></p>

			<?php if ( $count ) : ?>
				<div class="seofx-fixall">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-seofx-fixall data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>" data-issue="<?php echo esc_attr( $issue ); ?>" data-total="<?php echo esc_attr( (string) $count ); ?>" data-done-url="<?php echo esc_url( Health_Report::url( 'fix', array( 'issue' => $issue ) ) ); ?>">
						<input type="hidden" name="action" value="seofx_fix_all" />
						<input type="hidden" name="issue" value="<?php echo esc_attr( $issue ); ?>" />
						<?php wp_nonce_field( 'seofx_fix_all' ); ?>
						<button type="submit" class="seofx-btn seofx-btn--primary" data-seofx-confirm="<?php esc_attr_e( 'Apply the suggestions to every page with this problem? You can undo it afterwards.', 'seo-fixer' ); ?>">
							<?php UI::icon( 'sparkle', 18 ); ?>
							<?php
							/* translators: %s: number of pages. */
							echo esc_html( sprintf( _n( 'Fix %s page', 'Fix all %s pages', $count, 'seo-fixer' ), number_format_i18n( $count ) ) );
							?>
						</button>
					</form>
					<div class="seofx-progress" data-seofx-fixall-progress hidden>
						<div class="seofx-progress__track" role="progressbar" aria-label="<?php esc_attr_e( 'Fix progress', 'seo-fixer' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
							<span class="seofx-progress__fill" style="width: 0%"></span>
						</div>
						<p class="seofx-progress__label" aria-live="polite"></p>
					</div>
				</div>
			<?php endif; ?>

			<?php self::autofix_form( $fix, $issue ); ?>
		</section>
		<?php
		if ( $count ) {
			self::preview( $issue, $fix, $count );
		}
	}

	/**
	 * "Fixed N … Undo" after applying.
	 *
	 * @param string $issue Issue id.
	 * @return void
	 */
	private static function done_notice( $issue ) {
		$batch   = sanitize_key( self::query_arg( 'done' ) );
		$changed = absint( self::query_arg( 'changed' ) );
		if ( '' === $batch ) {
			return;
		}
		$more = '1' === self::query_arg( 'more' );
		?>
		<div class="seofx-callout seofx-callout--<?php echo $changed ? 'good' : 'info'; ?> seofx-done" role="status">
			<?php UI::icon( $changed ? 'check' : 'info', 20 ); ?>
			<p>
				<?php
				if ( $changed ) {
					/* translators: %s: number of changes. */
					echo esc_html( sprintf( _n( 'Done: %s change made. The score has been updated.', 'Done: %s changes made. The score has been updated.', $changed, 'seo-fixer' ), number_format_i18n( $changed ) ) );
				} else {
					esc_html_e( 'Nothing was changed: the selected pages already had their own text, or no suggestion was available.', 'seo-fixer' );
				}
				if ( $more ) {
					echo ' ';
					esc_html_e( 'More pages are waiting — click “Fix all” again to continue.', 'seo-fixer' );
				}
				?>
			</p>
			<?php if ( $changed ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="seofx_fix_undo" />
					<input type="hidden" name="batch" value="<?php echo esc_attr( $batch ); ?>" />
					<input type="hidden" name="back" value="<?php echo esc_attr( $issue ); ?>" />
					<?php wp_nonce_field( 'seofx_fix_undo' ); ?>
					<button type="submit" class="seofx-btn seofx-btn--secondary seofx-btn--sm"><?php esc_html_e( 'Undo', 'seo-fixer' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Automatic-fix switch.
	 *
	 * @param string $fix   description|title|alt.
	 * @param string $issue Issue id (to come back to).
	 * @return void
	 */
	private static function autofix_form( $fix, $issue ) {
		$on     = Autofix::enabled( $fix );
		$labels = array(
			'description' => __( 'Write descriptions automatically for new and updated pages that have none (or a very short one)', 'seo-fixer' ),
			'title'       => __( 'Leave the site name off titles that would be too long, for new and updated pages', 'seo-fixer' ),
			'alt'         => __( 'Add alt text automatically to images in new and updated pages', 'seo-fixer' ),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="seofx-autofix">
			<input type="hidden" name="action" value="seofx_autofix" />
			<input type="hidden" name="fix" value="<?php echo esc_attr( $fix ); ?>" />
			<input type="hidden" name="back" value="<?php echo esc_attr( $issue ); ?>" />
			<input type="hidden" name="enabled" value="<?php echo $on ? '0' : '1'; ?>" />
			<?php wp_nonce_field( 'seofx_autofix' ); ?>
			<span class="seofx-autofix__text">
				<strong><?php echo $on ? esc_html__( 'Automatic fixing is on', 'seo-fixer' ) : esc_html__( 'Automatic fixing is off', 'seo-fixer' ); ?></strong>
				<span><?php echo esc_html( $labels[ $fix ] ); ?></span>
			</span>
			<button type="submit" class="seofx-btn <?php echo $on ? 'seofx-btn--ghost' : 'seofx-btn--secondary'; ?> seofx-btn--sm" aria-pressed="<?php echo $on ? 'true' : 'false'; ?>"><?php echo $on ? esc_html__( 'Turn off', 'seo-fixer' ) : esc_html__( 'Turn on', 'seo-fixer' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Suggestions table: one row per page (or per image), editable, applied as selected.
	 *
	 * @param string $issue Issue id.
	 * @param string $fix   description|title|alt.
	 * @param int    $count Pages with the issue.
	 * @return void
	 */
	private static function preview( $issue, $fix, $count ) {
		$pages = max( 1, (int) ceil( $count / self::PER_PAGE ) );
		$paged = min( max( 1, absint( self::query_arg( 'paged' ) ) ), $pages );
		$rows  = Results::report( array( 'issue' => $issue ), 'score', 'asc', self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$n     = 0;
		$max   = 'alt' === $fix ? 125 : ( 'title' === $fix ? 200 : 300 );
		?>
		<section class="seofx-card" aria-labelledby="seofx-preview-title">
			<header class="seofx-card__header">
				<h2 class="seofx-card__title" id="seofx-preview-title"><?php esc_html_e( 'Suggestions', 'seo-fixer' ); ?></h2>
				<p class="seofx-card__desc"><?php esc_html_e( 'Untick anything you don’t want, edit the text if you like, then apply.', 'seo-fixer' ); ?></p>
			</header>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="seofx_fix_apply" />
				<input type="hidden" name="issue" value="<?php echo esc_attr( $issue ); ?>" />
				<?php wp_nonce_field( 'seofx_fix_apply' ); ?>
				<div class="seofx-table-wrap">
					<table class="seofx-table seofx-table--list seofx-fix-table">
						<thead>
							<tr>
								<td class="seofx-table__check"><input type="checkbox" data-seofx-check-all checked aria-label="<?php esc_attr_e( 'Select all', 'seo-fixer' ); ?>" /></td>
								<th scope="col"><?php esc_html_e( 'Page', 'seo-fixer' ); ?></th>
								<th scope="col"><?php echo 'alt' === $fix ? esc_html__( 'Image', 'seo-fixer' ) : esc_html__( 'Now', 'seo-fixer' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Suggestion', 'seo-fixer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<?php
								$post = get_post( (int) $row['post_id'] );
								if ( ! $post ) {
									continue;
								}
								$items = Fixer::suggestions( $fix, $post );
								$title = '' !== trim( $post->post_title ) ? $post->post_title : __( '(no title)', 'seo-fixer' );
								$edit  = get_edit_post_link( $post->ID, 'raw' );
								if ( array() === $items ) :
									?>
									<tr class="is-disabled">
										<td class="seofx-table__check"></td>
										<td><?php echo esc_html( $title ); ?></td>
										<td colspan="2" class="seofx-muted"><?php echo 'alt' === $fix ? esc_html__( 'Nothing to fix here any more.', 'seo-fixer' ) : esc_html__( 'You wrote this one yourself, so SEO Fixer leaves it alone.', 'seo-fixer' ); ?></td>
									</tr>
									<?php
									continue;
								endif;
								foreach ( $items as $item ) :
									$id = 'seofx-fix-' . $n;
									?>
									<tr>
										<td class="seofx-table__check">
											<input type="checkbox" name="fix[<?php echo esc_attr( (string) $n ); ?>][apply]" value="1" <?php checked( '' !== $item['value'] ); ?> aria-label="<?php esc_attr_e( 'Apply this suggestion', 'seo-fixer' ); ?>" />
											<input type="hidden" name="fix[<?php echo esc_attr( (string) $n ); ?>][post_id]" value="<?php echo esc_attr( (string) $post->ID ); ?>" />
											<input type="hidden" name="fix[<?php echo esc_attr( (string) $n ); ?>][field]" value="<?php echo esc_attr( $item['field'] ); ?>" />
											<?php if ( 'alt' === $fix ) : ?>
												<input type="hidden" name="fix[<?php echo esc_attr( (string) $n ); ?>][index]" value="<?php echo esc_attr( (string) $item['index'] ); ?>" />
												<input type="hidden" name="fix[<?php echo esc_attr( (string) $n ); ?>][src]" value="<?php echo esc_attr( $item['src'] ); ?>" />
												<input type="hidden" name="fix[<?php echo esc_attr( (string) $n ); ?>][attachment_id]" value="<?php echo esc_attr( (string) $item['attachment_id'] ); ?>" />
											<?php endif; ?>
										</td>
										<td class="seofx-fix-table__page">
											<?php if ( $edit ) : ?>
												<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $title ); ?></a>
											<?php else : ?>
												<?php echo esc_html( $title ); ?>
											<?php endif; ?>
										</td>
										<td class="seofx-fix-table__now">
											<?php if ( 'alt' === $fix ) : ?>
												<img src="<?php echo esc_url( $item['src'] ); ?>" alt="" width="64" height="48" loading="lazy" class="seofx-fix-thumb" />
												<code><?php echo esc_html( wp_basename( (string) wp_parse_url( $item['src'], PHP_URL_PATH ) ) ); ?></code>
											<?php elseif ( '' === $item['current'] ) : ?>
												<span class="seofx-muted"><?php esc_html_e( 'None', 'seo-fixer' ); ?></span>
											<?php else : ?>
												<?php echo esc_html( $item['current'] ); ?>
											<?php endif; ?>
										</td>
										<td class="seofx-fix-table__new">
											<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Suggested text', 'seo-fixer' ); ?></label>
											<?php if ( 'description' === $fix ) : ?>
												<textarea id="<?php echo esc_attr( $id ); ?>" name="fix[<?php echo esc_attr( (string) $n ); ?>][value]" class="seofx-input seofx-input--textarea" rows="3" maxlength="<?php echo esc_attr( (string) $max ); ?>" data-seofx-charcount><?php echo esc_textarea( $item['value'] ); ?></textarea>
											<?php else : ?>
												<input type="text" id="<?php echo esc_attr( $id ); ?>" name="fix[<?php echo esc_attr( (string) $n ); ?>][value]" class="seofx-input" maxlength="<?php echo esc_attr( (string) $max ); ?>" value="<?php echo esc_attr( $item['value'] ); ?>" placeholder="<?php echo 'title' === $fix ? esc_attr__( 'Write a shorter title', 'seo-fixer' ) : esc_attr__( 'Describe the image', 'seo-fixer' ); ?>" />
											<?php endif; ?>
											<?php if ( 'title' === $fix && '' !== $item['value'] ) : ?>
												<span class="seofx-fix-table__hint">
													<?php
													/* translators: %s: the title as it will look in Google. */
													echo esc_html( sprintf( __( 'Shows as: %s', 'seo-fixer' ), Fixer::preview( $item['value'], $post ) ) );
													?>
												</span>
											<?php elseif ( 'title' === $fix ) : ?>
												<span class="seofx-fix-table__hint"><?php esc_html_e( 'The page title alone is too long, so this one needs your words.', 'seo-fixer' ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
									<?php
									++$n;
								endforeach;
							endforeach;
							?>
						</tbody>
					</table>
				</div>
				<div class="seofx-table-footer">
					<button type="submit" class="seofx-btn seofx-btn--primary"><?php esc_html_e( 'Apply selected', 'seo-fixer' ); ?></button>
					<?php if ( $pages > 1 ) : ?>
						<nav class="seofx-pagination" aria-label="<?php esc_attr_e( 'Pages of suggestions', 'seo-fixer' ); ?>">
							<?php if ( $paged > 1 ) : ?>
								<a class="seofx-btn seofx-btn--ghost seofx-btn--sm" href="<?php echo esc_url( Health_Report::url( 'fix', array( 'issue' => $issue, 'paged' => (string) ( $paged - 1 ) ) ) ); ?>"><?php esc_html_e( 'Previous', 'seo-fixer' ); ?></a>
							<?php endif; ?>
							<span>
								<?php
								/* translators: 1: current page, 2: number of pages. */
								echo esc_html( sprintf( __( 'Page %1$s of %2$s', 'seo-fixer' ), number_format_i18n( $paged ), number_format_i18n( $pages ) ) );
								?>
							</span>
							<?php if ( $paged < $pages ) : ?>
								<a class="seofx-btn seofx-btn--ghost seofx-btn--sm" href="<?php echo esc_url( Health_Report::url( 'fix', array( 'issue' => $issue, 'paged' => (string) ( $paged + 1 ) ) ) ); ?>"><?php esc_html_e( 'Next', 'seo-fixer' ); ?></a>
							<?php endif; ?>
						</nav>
					<?php endif; ?>
				</div>
			</form>
		</section>
		<?php
	}

	/**
	 * Change history with Undo.
	 *
	 * @return void
	 */
	public static function render_changes() {
		$paged  = max( 1, absint( self::query_arg( 'paged' ) ) );
		$result = History::batches( $paged, self::PER_PAGE );
		$pages  = max( 1, (int) ceil( $result['total'] / self::PER_PAGE ) );
		?>
		<section class="seofx-card" aria-labelledby="seofx-changes-title">
			<header class="seofx-card__header">
				<h2 class="seofx-card__title" id="seofx-changes-title"><?php esc_html_e( 'Changes made by SEO Fixer', 'seo-fixer' ); ?></h2>
				<p class="seofx-card__desc">
					<?php
					/* translators: %d: number of days. */
					echo esc_html( sprintf( __( 'Every fix and import is listed here for %d days, so you can undo it. Undo never touches text that was edited again afterwards.', 'seo-fixer' ), History::KEEP_DAYS ) );
					?>
				</p>
			</header>
			<?php if ( array() === $result['rows'] ) : ?>
				<p class="seofx-empty"><?php esc_html_e( 'No changes yet. Fixes you apply will appear here.', 'seo-fixer' ); ?></p>
			<?php else : ?>
				<div class="seofx-table-wrap">
					<table class="seofx-table seofx-table--list">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'When', 'seo-fixer' ); ?></th>
								<th scope="col"><?php esc_html_e( 'What changed', 'seo-fixer' ); ?></th>
								<th scope="col"><?php esc_html_e( 'By', 'seo-fixer' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'seo-fixer' ); ?></th>
								<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'seo-fixer' ); ?></span></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $result['rows'] as $row ) : ?>
								<?php
								$undone  = (int) $row['undone'];
								$changes = (int) $row['changes'];
								$time    = strtotime( $row['created'] . ' UTC' );
								$user    = 'auto' === $row['source'] ? null : get_userdata( (int) $row['user_id'] );
								?>
								<tr>
									<td title="<?php echo esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ); ?>">
										<?php
										/* translators: %s: time span, e.g. "3 hours". */
										echo esc_html( sprintf( __( '%s ago', 'seo-fixer' ), human_time_diff( $time ) ) );
										?>
									</td>
									<td><?php echo esc_html( self::describe_batch( $row ) ); ?></td>
									<td>
										<?php
										if ( 'auto' === $row['source'] ) {
											esc_html_e( 'Automatic', 'seo-fixer' );
										} else {
											echo esc_html( $user ? $user->display_name : __( 'Unknown', 'seo-fixer' ) );
										}
										?>
									</td>
									<td>
										<?php
										if ( 0 === $undone ) {
											UI::badge( __( 'Applied', 'seo-fixer' ), 'good' );
										} elseif ( $undone >= $changes ) {
											UI::badge( __( 'Undone', 'seo-fixer' ), 'neutral' );
										} else {
											UI::badge( __( 'Partly undone', 'seo-fixer' ), 'warn' );
										}
										?>
									</td>
									<td class="seofx-row-actions">
										<?php if ( $undone < $changes ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="seofx_fix_undo" />
												<input type="hidden" name="batch" value="<?php echo esc_attr( $row['batch'] ); ?>" />
												<?php wp_nonce_field( 'seofx_fix_undo' ); ?>
												<button type="submit" class="seofx-btn seofx-btn--secondary seofx-btn--sm" data-seofx-confirm="<?php esc_attr_e( 'Undo these changes?', 'seo-fixer' ); ?>"><?php esc_html_e( 'Undo', 'seo-fixer' ); ?></button>
											</form>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php if ( $pages > 1 ) : ?>
					<div class="seofx-table-footer">
						<span></span>
						<nav class="seofx-pagination" aria-label="<?php esc_attr_e( 'Pages of changes', 'seo-fixer' ); ?>">
							<?php if ( $paged > 1 ) : ?>
								<a class="seofx-btn seofx-btn--ghost seofx-btn--sm" href="<?php echo esc_url( Health_Report::url( 'changes', array( 'paged' => (string) ( $paged - 1 ) ) ) ); ?>"><?php esc_html_e( 'Previous', 'seo-fixer' ); ?></a>
							<?php endif; ?>
							<?php if ( $paged < $pages ) : ?>
								<a class="seofx-btn seofx-btn--ghost seofx-btn--sm" href="<?php echo esc_url( Health_Report::url( 'changes', array( 'paged' => (string) ( $paged + 1 ) ) ) ); ?>"><?php esc_html_e( 'Next', 'seo-fixer' ); ?></a>
							<?php endif; ?>
						</nav>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * "4 descriptions written, 12 images given alt text (on 9 pages)".
	 *
	 * @param array $row Batch row.
	 * @return string
	 */
	private static function describe_batch( array $row ) {
		if ( ! empty( $row['import_detail'] ) ) {
			return self::describe_import( $row );
		}
		$parts = array();
		if ( (int) $row['descriptions'] ) {
			/* translators: %s: number of descriptions. */
			$parts[] = sprintf( _n( '%s description written', '%s descriptions written', (int) $row['descriptions'], 'seo-fixer' ), number_format_i18n( (int) $row['descriptions'] ) );
		}
		if ( (int) $row['titles'] ) {
			/* translators: %s: number of titles. */
			$parts[] = sprintf( _n( '%s title shortened', '%s titles shortened', (int) $row['titles'], 'seo-fixer' ), number_format_i18n( (int) $row['titles'] ) );
		}
		if ( (int) $row['alts'] ) {
			/* translators: %s: number of images. */
			$parts[] = sprintf( _n( '%s image given alt text', '%s images given alt text', (int) $row['alts'], 'seo-fixer' ), number_format_i18n( (int) $row['alts'] ) );
		}
		$posts = (int) $row['posts'];
		return sprintf(
			/* translators: 1: list of changes, 2: number of pages. */
			_n( '%1$s (on %2$s page)', '%1$s (on %2$s pages)', $posts, 'seo-fixer' ),
			implode( ', ', $parts ),
			number_format_i18n( $posts )
		);
	}

	/**
	 * "Imported from Yoast SEO: 312 values on 120 pages, 4 categories or tags, 2 site settings, 15 redirects".
	 *
	 * @param array $row Batch row.
	 * @return string
	 */
	private static function describe_import( array $row ) {
		$detail = json_decode( (string) $row['import_detail'], true );
		$source = is_array( $detail ) && isset( $detail['s'] ) ? \Seofx\Import\Importer::source( (string) $detail['s'] ) : null;
		$parts  = array();
		if ( (int) $row['imported'] ) {
			$parts[] = sprintf(
				/* translators: 1: number of values, 2: number of pages. */
				_n( '%1$s value on %2$s page', '%1$s values on %2$s pages', (int) $row['posts'], 'seo-fixer' ),
				number_format_i18n( (int) $row['imported'] ),
				number_format_i18n( (int) $row['posts'] )
			);
		}
		if ( (int) $row['terms'] ) {
			/* translators: %s: number of categories and tags. */
			$parts[] = sprintf( _n( '%s category or tag', '%s categories and tags', (int) $row['terms'], 'seo-fixer' ), number_format_i18n( (int) $row['terms'] ) );
		}
		if ( (int) $row['settings'] ) {
			/* translators: %s: number of settings. */
			$parts[] = sprintf( _n( '%s site setting', '%s site settings', (int) $row['settings'], 'seo-fixer' ), number_format_i18n( (int) $row['settings'] ) );
		}
		if ( (int) $row['redirects'] ) {
			/* translators: %s: number of redirects. */
			$parts[] = sprintf( _n( '%s redirect', '%s redirects', (int) $row['redirects'], 'seo-fixer' ), number_format_i18n( (int) $row['redirects'] ) );
		}
		return sprintf(
			/* translators: 1: plugin name, e.g. "Yoast SEO", 2: list of what was imported. */
			__( 'Imported from %1$s: %2$s', 'seo-fixer' ),
			$source ? $source->name() : __( 'another SEO plugin', 'seo-fixer' ),
			wp_sprintf( '%l', $parts )
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Requests.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Applies the ticked suggestions.
	 *
	 * @return void
	 */
	public function handle_apply() {
		Security::require_manage();
		check_admin_referer( 'seofx_fix_apply' );

		$issue = isset( $_POST['issue'] ) ? sanitize_key( wp_unslash( $_POST['issue'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each part is validated below; texts are sanitized by Fixer::apply().
		$rows  = isset( $_POST['fix'] ) && is_array( $_POST['fix'] ) ? wp_unslash( $_POST['fix'] ) : array();
		$items = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['apply'] ) ) {
				continue;
			}
			$field = isset( $row['field'] ) ? sanitize_key( $row['field'] ) : '';
			if ( ! in_array( $field, array( 'description', 'title', 'alt' ), true ) ) {
				continue;
			}
			$items[] = array(
				'post_id'       => isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0,
				'field'         => $field,
				'value'         => isset( $row['value'] ) && is_scalar( $row['value'] ) ? (string) $row['value'] : '',
				'index'         => isset( $row['index'] ) ? absint( $row['index'] ) : 0,
				// Compared with the image's address in the content, never printed or stored as-is.
				'src'           => isset( $row['src'] ) && is_scalar( $row['src'] ) ? (string) $row['src'] : '',
				'attachment_id' => isset( $row['attachment_id'] ) ? absint( $row['attachment_id'] ) : 0,
			);
		}

		$batch  = History::new_batch();
		$result = Fixer::apply( $items, $batch );
		wp_safe_redirect(
			Health_Report::url(
				'fix',
				array(
					'issue'   => $issue,
					'done'    => $batch,
					'changed' => (string) $result['changed'],
				)
			)
		);
		exit;
	}

	/**
	 * "Fix all" without JavaScript: a few rounds per click.
	 *
	 * @return void
	 */
	public function handle_fix_all() {
		Security::require_manage();
		check_admin_referer( 'seofx_fix_all' );

		$issue   = isset( $_POST['issue'] ) ? sanitize_key( wp_unslash( $_POST['issue'] ) ) : '';
		$batch   = History::new_batch();
		$after   = 0;
		$changed = 0;
		$more    = false;
		for ( $round = 0; $round < self::NOJS_ROUNDS; $round++ ) {
			$step     = Fixer::fix_next( $issue, $after, $batch );
			$after    = $step['after'];
			$changed += $step['changed'];
			$more     = $step['more'];
			if ( ! $more ) {
				break;
			}
		}
		wp_safe_redirect(
			Health_Report::url(
				'fix',
				array(
					'issue'   => $issue,
					'done'    => $batch,
					'changed' => (string) $changed,
					'more'    => $more ? '1' : '',
				)
			)
		);
		exit;
	}

	/**
	 * "Fix all" with JavaScript: one chunk per request.
	 *
	 * @return void
	 */
	public function ajax_fix_all() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page and try again.', 'seo-fixer' ) ), 403 );
		}
		if ( ! Security::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'seo-fixer' ) ), 403 );
		}
		$issue = isset( $_POST['issue'] ) ? sanitize_key( wp_unslash( $_POST['issue'] ) ) : '';
		$after = isset( $_POST['after'] ) ? absint( $_POST['after'] ) : 0;
		$batch = isset( $_POST['batch'] ) ? sanitize_key( wp_unslash( $_POST['batch'] ) ) : '';
		if ( '' === Fixer::fix_for( $issue ) ) {
			wp_send_json_error( array( 'message' => __( 'This problem can’t be fixed automatically.', 'seo-fixer' ) ), 400 );
		}
		if ( ! preg_match( '/^\d{14}-[a-z0-9]{8}$/', $batch ) ) {
			$batch = History::new_batch();
		}
		$step          = Fixer::fix_next( $issue, $after, $batch );
		$step['batch'] = $batch;
		wp_send_json_success( $step );
	}

	/**
	 * Undoes a batch.
	 *
	 * @return void
	 */
	public function handle_undo() {
		Security::require_manage();
		check_admin_referer( 'seofx_fix_undo' );

		$batch  = isset( $_POST['batch'] ) ? sanitize_key( wp_unslash( $_POST['batch'] ) ) : '';
		$back   = isset( $_POST['back'] ) ? sanitize_key( wp_unslash( $_POST['back'] ) ) : '';
		$result = History::undo( $batch );
		if ( $result['posts'] ) {
			\Seofx\Scanner\Scanner::rescan( $result['posts'] );
		}

		if ( $result['more'] ) {
			/* translators: %s: number of changes undone so far. */
			UI::flash( sprintf( __( 'Undone so far: %s changes. This is a big one — click Undo again to finish.', 'seo-fixer' ), number_format_i18n( $result['undone'] ) ), 'warn' );
		} elseif ( $result['skipped'] ) {
			UI::flash(
				/* translators: %s: number of changes undone. */
				sprintf( _n( 'Undone: %s change.', 'Undone: %s changes.', $result['undone'], 'seo-fixer' ), number_format_i18n( $result['undone'] ) )
				. ' '
				/* translators: %s: number of changes left because they were edited again. */
				. sprintf( _n( '%s was left as it is because it was edited again afterwards.', '%s were left as they are because they were edited again afterwards.', $result['skipped'], 'seo-fixer' ), number_format_i18n( $result['skipped'] ) ),
				'warn'
			);
		} else {
			/* translators: %s: number of changes undone. */
			UI::flash( sprintf( _n( 'Undone: %s change.', 'Undone: %s changes.', $result['undone'], 'seo-fixer' ), number_format_i18n( $result['undone'] ) ) );
		}
		wp_safe_redirect( '' !== $back ? Health_Report::url( 'fix', array( 'issue' => $back ) ) : Health_Report::url( 'changes' ) );
		exit;
	}

	/**
	 * Switches an automatic fix on or off.
	 *
	 * @return void
	 */
	public function handle_autofix() {
		Security::require_manage();
		check_admin_referer( 'seofx_autofix' );

		$fix  = isset( $_POST['fix'] ) ? sanitize_key( wp_unslash( $_POST['fix'] ) ) : '';
		$back = isset( $_POST['back'] ) ? sanitize_key( wp_unslash( $_POST['back'] ) ) : '';
		$on   = ! empty( $_POST['enabled'] );
		if ( isset( Autofix::OPTIONS[ $fix ] ) ) {
			Options::update( array( Autofix::OPTIONS[ $fix ] => $on ) );
			UI::flash( $on ? __( 'Automatic fixing is on. New and updated pages will be fixed as they are saved.', 'seo-fixer' ) : __( 'Automatic fixing is off.', 'seo-fixer' ) );
		}
		wp_safe_redirect( Health_Report::url( 'fix', array( 'issue' => $back ) ) );
		exit;
	}
}
