<?php
/**
 * SEO Health report: the per-page table (filters, sorting, paging), the
 * print-friendly report and the CSV download.
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Fixes\Fixer;
use Seofx\Scanner\Checks;
use Seofx\Scanner\Results;
use Seofx\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Health report screens.
 */
final class Health_Report {

	/**
	 * Rows per page in the table.
	 */
	const PER_PAGE = 50;

	/**
	 * Pages listed in the printed report.
	 */
	const PRINT_LIMIT = 200;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_seofx_report_csv', array( $this, 'handle_csv' ) );
	}

	/**
	 * URL of a dashboard view.
	 *
	 * @param string $view '' (overview)|pages|print.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function url( $view = 'pages', array $args = array() ) {
		$base = array( 'page' => Admin::SLUG );
		if ( '' !== $view ) {
			$base['view'] = $view;
		}
		return add_query_arg( array_merge( $base, array_filter( $args, 'strlen' ) ), admin_url( 'admin.php' ) );
	}

	/**
	 * Reads a GET parameter (read-only view state).
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function query_arg( $key ) {
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters for a report.
	}

	/**
	 * Current filters, sorting and page, validated.
	 *
	 * @return array{issue: string, type: string, band: string, search: string, orderby: string, order: string, paged: int}
	 */
	public static function request() {
		$issue   = sanitize_key( self::query_arg( 'issue' ) );
		$type    = sanitize_key( self::query_arg( 'type' ) );
		$band    = sanitize_key( self::query_arg( 'band' ) );
		$orderby = sanitize_key( self::query_arg( 'orderby' ) );
		$order   = 'desc' === self::query_arg( 'order' ) ? 'desc' : 'asc';

		return array(
			'issue'   => isset( Checks::page_checks()[ $issue ] ) ? $issue : '',
			'type'    => in_array( $type, Results::types(), true ) ? $type : '',
			'band'    => isset( self::bands()[ $band ] ) ? $band : '',
			'search'  => mb_substr( self::query_arg( 's' ), 0, 100 ),
			'orderby' => in_array( $orderby, array( 'score', 'post_title', 'post_type' ), true ) ? $orderby : 'score',
			'order'   => $order,
			'paged'   => max( 1, absint( self::query_arg( 'paged' ) ) ),
		);
	}

	/**
	 * Score bands for the filter.
	 *
	 * @return array<string, string>
	 */
	private static function bands() {
		return array(
			'problems' => __( 'Pages with problems', 'seo-fixer' ),
			'bad'      => __( 'Needs attention (under 50)', 'seo-fixer' ),
			'warn'     => __( 'Needs work (50–79)', 'seo-fixer' ),
			'good'     => __( 'Healthy (80 and over)', 'seo-fixer' ),
		);
	}

	/**
	 * Filters as query args (for links that keep them).
	 *
	 * @param array $request From request().
	 * @return array<string, string>
	 */
	private static function filter_args( array $request ) {
		return array(
			'issue'   => $request['issue'],
			'type'    => $request['type'],
			'band'    => $request['band'],
			's'       => $request['search'],
			'orderby' => 'score' === $request['orderby'] ? '' : $request['orderby'],
			'order'   => 'asc' === $request['order'] ? '' : $request['order'],
		);
	}

	/**
	 * Label of a content type.
	 *
	 * @param string $type Post type.
	 * @return string
	 */
	private static function type_label( $type ) {
		$object = get_post_type_object( $type );
		return $object ? $object->labels->singular_name : $type;
	}

	/**
	 * Issues of a row, most serious first, with their check definitions.
	 *
	 * @param array[] $issues Stored issues.
	 * @return array[] Each: id, severity, label, page, count, items.
	 */
	private static function sorted_issues( array $issues ) {
		$checks = Checks::page_checks();
		$order  = array_flip( array_keys( Dashboard_Page::severities() ) );
		$list   = array();
		foreach ( $issues as $issue ) {
			if ( ! isset( $issue['id'], $checks[ $issue['id'] ] ) ) {
				continue;
			}
			$check  = $checks[ $issue['id'] ];
			$list[] = array(
				'id'       => $issue['id'],
				'severity' => $check['severity'],
				'label'    => $check['label'],
				'page'     => $check['page'],
				'count'    => isset( $issue['count'] ) ? (int) $issue['count'] : 0,
				'items'    => isset( $issue['items'] ) ? (array) $issue['items'] : array(),
			);
		}
		usort(
			$list,
			static function ( $a, $b ) use ( $order ) {
				return $order[ $a['severity'] ] - $order[ $b['severity'] ];
			}
		);
		return $list;
	}

	/**
	 * One stored detail item as text (an image address, a link, or "image (900 KB)").
	 *
	 * @param mixed $item Item.
	 * @return string
	 */
	private static function item_text( $item ) {
		if ( is_array( $item ) && isset( $item['src'] ) ) {
			/* translators: 1: image address, 2: size in KB. */
			return sprintf( __( '%1$s (%2$s KB)', 'seo-fixer' ), $item['src'], number_format_i18n( (int) $item['kb'] ) );
		}
		return is_scalar( $item ) ? (string) $item : '';
	}

	/**
	 * Warms the post cache for a page of rows (one query instead of one per row).
	 *
	 * @param array[] $rows Rows.
	 * @return void
	 */
	private static function prime( array $rows ) {
		$ids = array_map( 'intval', wp_list_pluck( $rows, 'post_id' ) );
		if ( $ids ) {
			get_posts(
				array(
					'post__in'       => $ids,
					'post_type'      => 'any',
					'post_status'    => 'any',
					'posts_per_page' => count( $ids ),
					'orderby'        => 'none',
				)
			);
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Pages view.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Renders the per-page table.
	 *
	 * @param array $summary Scan summary.
	 * @return void
	 */
	public static function render_pages( array $summary ) {
		$request = self::request();
		$total   = Results::report_count( $request );
		$pages   = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged   = min( $request['paged'], $pages );
		$rows    = Results::report( $request, $request['orderby'], $request['order'], self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$csv     = wp_nonce_url( add_query_arg( array_filter( array_merge( array( 'action' => 'seofx_report_csv' ), self::filter_args( $request ) ), 'strlen' ), admin_url( 'admin-post.php' ) ), 'seofx_report_csv' );
		self::prime( $rows );

		if ( '' !== $request['issue'] ) {
			self::issue_card( $request['issue'], $summary );
		}
		?>
		<section class="seofx-card" aria-labelledby="seofx-pages-title">
			<header class="seofx-card__header seofx-card__header--row">
				<div>
					<h2 class="seofx-card__title" id="seofx-pages-title"><?php esc_html_e( 'Page by page', 'seo-fixer' ); ?></h2>
					<p class="seofx-card__desc">
						<?php
						/* translators: %s: number of pages. */
						echo esc_html( sprintf( _n( '%s page matches.', '%s pages match.', $total, 'seo-fixer' ), number_format_i18n( $total ) ) );
						?>
					</p>
				</div>
				<div class="seofx-report-actions">
					<a class="seofx-btn seofx-btn--secondary seofx-btn--sm" href="<?php echo esc_url( $csv ); ?>"><?php UI::icon( 'download', 16 ); ?><?php esc_html_e( 'Download CSV', 'seo-fixer' ); ?></a>
					<a class="seofx-btn seofx-btn--secondary seofx-btn--sm" href="<?php echo esc_url( self::url( 'print' ) ); ?>"><?php UI::icon( 'print', 16 ); ?><?php esc_html_e( 'Printable report', 'seo-fixer' ); ?></a>
				</div>
			</header>

			<?php self::filter_form( $request ); ?>

			<?php if ( array() === $rows ) : ?>
				<p class="seofx-empty"><?php esc_html_e( 'No pages match these filters.', 'seo-fixer' ); ?></p>
			<?php else : ?>
				<div class="seofx-table-wrap">
					<table class="seofx-table seofx-table--list seofx-report-table">
						<thead>
							<tr>
								<th scope="col"><?php self::sort_link( __( 'Page', 'seo-fixer' ), 'post_title', $request ); ?></th>
								<th scope="col" class="seofx-num"><?php self::sort_link( __( 'Score', 'seo-fixer' ), 'score', $request ); ?></th>
								<th scope="col"><?php esc_html_e( 'Problems', 'seo-fixer' ); ?></th>
								<th scope="col"><?php self::sort_link( __( 'Type', 'seo-fixer' ), 'post_type', $request ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<?php self::row( $row ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="seofx-table-footer">
					<span></span>
					<?php self::pagination( $paged, $pages, $request ); ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Explanation card for the issue being filtered.
	 *
	 * @param string $issue   Issue id.
	 * @param array  $summary Scan summary.
	 * @return void
	 */
	private static function issue_card( $issue, array $summary ) {
		$check    = Checks::page_checks()[ $issue ];
		$guide    = Checks::guidance()[ $issue ];
		$severity = Dashboard_Page::severities()[ $check['severity'] ];
		$count    = isset( $summary['issues'][ $issue ] ) ? (int) $summary['issues'][ $issue ] : 0;
		?>
		<section class="seofx-card seofx-issue-card seofx-issue-card--<?php echo esc_attr( $severity['tone'] ); ?>" aria-labelledby="seofx-issue-title">
			<header class="seofx-card__header seofx-card__header--row">
				<h2 class="seofx-card__title" id="seofx-issue-title"><?php echo esc_html( $check['label'] ); ?> <?php UI::badge( $severity['label'], $severity['tone'] ); ?></h2>
				<span class="seofx-report-actions">
					<?php if ( $count && '' !== Fixer::fix_for( $issue ) ) : ?>
						<a class="seofx-btn seofx-btn--primary seofx-btn--sm" href="<?php echo esc_url( self::url( 'fix', array( 'issue' => $issue ) ) ); ?>"><?php UI::icon( 'sparkle', 16 ); ?><?php esc_html_e( 'Fix automatically', 'seo-fixer' ); ?></a>
					<?php endif; ?>
					<a class="seofx-btn seofx-btn--ghost seofx-btn--sm" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Show all pages', 'seo-fixer' ); ?></a>
				</span>
			</header>
			<?php if ( $count ) : ?>
				<p class="seofx-issue-card__lead"><?php echo esc_html( sprintf( translate_nooped_plural( $check['message'], $count, 'seo-fixer' ), number_format_i18n( $count ) ) ); ?></p>
			<?php endif; ?>
			<dl class="seofx-guide">
				<dt><?php esc_html_e( 'Why it matters', 'seo-fixer' ); ?></dt>
				<dd><?php echo esc_html( $guide['why'] ); ?></dd>
				<dt><?php esc_html_e( 'How to fix it', 'seo-fixer' ); ?></dt>
				<dd><?php echo esc_html( $guide['how'] ); ?></dd>
			</dl>
		</section>
		<?php
	}

	/**
	 * Filter bar (a plain GET form, works without JavaScript).
	 *
	 * @param array $request From request().
	 * @return void
	 */
	private static function filter_form( array $request ) {
		$checks = Checks::page_checks();
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="seofx-filters" role="search" aria-label="<?php esc_attr_e( 'Filter pages', 'seo-fixer' ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG ); ?>" />
			<input type="hidden" name="view" value="pages" />
			<div class="seofx-filters__field">
				<label for="seofx-filter-issue"><?php esc_html_e( 'Problem', 'seo-fixer' ); ?></label>
				<select id="seofx-filter-issue" name="issue" class="seofx-select">
					<option value=""><?php esc_html_e( 'Any problem', 'seo-fixer' ); ?></option>
					<?php foreach ( $checks as $id => $check ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>"<?php selected( $request['issue'], $id ); ?>><?php echo esc_html( $check['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="seofx-filters__field">
				<label for="seofx-filter-band"><?php esc_html_e( 'Score', 'seo-fixer' ); ?></label>
				<select id="seofx-filter-band" name="band" class="seofx-select">
					<option value=""><?php esc_html_e( 'Any score', 'seo-fixer' ); ?></option>
					<?php foreach ( self::bands() as $id => $label ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>"<?php selected( $request['band'], $id ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="seofx-filters__field">
				<label for="seofx-filter-type"><?php esc_html_e( 'Type', 'seo-fixer' ); ?></label>
				<select id="seofx-filter-type" name="type" class="seofx-select">
					<option value=""><?php esc_html_e( 'All types', 'seo-fixer' ); ?></option>
					<?php foreach ( Results::types() as $type ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>"<?php selected( $request['type'], $type ); ?>><?php echo esc_html( self::type_label( $type ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="seofx-filters__field seofx-filters__field--grow">
				<label for="seofx-filter-search"><?php esc_html_e( 'Title contains', 'seo-fixer' ); ?></label>
				<input type="search" id="seofx-filter-search" name="s" class="seofx-input" value="<?php echo esc_attr( $request['search'] ); ?>" />
			</div>
			<?php if ( 'score' !== $request['orderby'] || 'asc' !== $request['order'] ) : ?>
				<input type="hidden" name="orderby" value="<?php echo esc_attr( $request['orderby'] ); ?>" />
				<input type="hidden" name="order" value="<?php echo esc_attr( $request['order'] ); ?>" />
			<?php endif; ?>
			<div class="seofx-filters__actions">
				<button type="submit" class="seofx-btn seofx-btn--primary seofx-btn--sm"><?php esc_html_e( 'Filter', 'seo-fixer' ); ?></button>
				<?php if ( '' !== $request['issue'] . $request['type'] . $request['band'] . $request['search'] ) : ?>
					<a class="seofx-btn seofx-btn--ghost seofx-btn--sm" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Clear filters', 'seo-fixer' ); ?></a>
				<?php endif; ?>
			</div>
		</form>
		<?php
	}

	/**
	 * Sortable column header.
	 *
	 * @param string $label   Column label.
	 * @param string $column Column key.
	 * @param array  $request From request().
	 * @return void
	 */
	private static function sort_link( $label, $column, array $request ) {
		$current = $request['orderby'] === $column;
		$next    = $current && 'asc' === $request['order'] ? 'desc' : 'asc';
		$args    = array_merge(
			self::filter_args( $request ),
			array(
				'orderby' => 'score' === $column ? '' : $column,
				'order'   => 'asc' === $next ? '' : 'desc',
			)
		);
		$sort    = $current ? ( 'asc' === $request['order'] ? 'ascending' : 'descending' ) : '';
		?>
		<a class="seofx-sort<?php echo $current ? ' is-sorted' : ''; ?>" href="<?php echo esc_url( self::url( 'pages', $args ) ); ?>"<?php echo $sort ? ' aria-sort="' . esc_attr( $sort ) . '"' : ''; ?>>
			<?php echo esc_html( $label ); ?>
			<?php if ( $current ) : ?>
				<span aria-hidden="true"><?php echo 'asc' === $request['order'] ? '↑' : '↓'; ?></span>
			<?php endif; ?>
		</a>
		<?php
	}

	/**
	 * One table row (with expandable details).
	 *
	 * @param array $row Row from Results::report().
	 * @return void
	 */
	private static function row( array $row ) {
		$post_id    = (int) $row['post_id'];
		$issues     = self::sorted_issues( $row['issues'] );
		$severities = Dashboard_Page::severities();
		$facts      = $row['facts'];
		$title      = '' !== trim( (string) $row['post_title'] ) ? $row['post_title'] : __( '(no title)', 'seo-fixer' );
		$edit       = get_edit_post_link( $post_id, 'raw' );
		?>
		<tr>
			<td class="seofx-report-table__page">
				<strong>
					<?php if ( $edit ) : ?>
						<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $title ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $title ); ?>
					<?php endif; ?>
				</strong>
				<a class="seofx-report-table__url" href="<?php echo esc_url( (string) get_permalink( $post_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( UI::display_url( (string) get_permalink( $post_id ) ) ); ?><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'seo-fixer' ); ?></span></a>
				<details class="seofx-report-details">
					<summary><?php esc_html_e( 'Details', 'seo-fixer' ); ?></summary>
					<dl class="seofx-facts">
						<dt><?php esc_html_e( 'Title in Google', 'seo-fixer' ); ?></dt>
						<dd>
							<?php echo esc_html( isset( $facts['title'] ) ? $facts['title'] : '' ); ?>
							<span class="seofx-muted">
								<?php
								/* translators: %s: number of characters. */
								echo esc_html( sprintf( _n( '(%s character)', '(%s characters)', (int) ( $facts['title_len'] ?? 0 ), 'seo-fixer' ), number_format_i18n( (int) ( $facts['title_len'] ?? 0 ) ) ) );
								?>
							</span>
						</dd>
						<dt><?php esc_html_e( 'Description', 'seo-fixer' ); ?></dt>
						<dd>
							<?php if ( ! empty( $facts['desc'] ) ) : ?>
								<?php echo esc_html( $facts['desc'] ); ?>
								<span class="seofx-muted">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: number of characters, 2: "written by you" or "automatic". */
											__( '(%1$s characters, %2$s)', 'seo-fixer' ),
											number_format_i18n( (int) $facts['desc_len'] ),
											empty( $facts['desc_custom'] ) ? __( 'automatic', 'seo-fixer' ) : __( 'written by you', 'seo-fixer' )
										)
									);
									?>
								</span>
							<?php else : ?>
								<span class="seofx-muted"><?php esc_html_e( 'None', 'seo-fixer' ); ?></span>
							<?php endif; ?>
						</dd>
						<dt><?php esc_html_e( 'Words', 'seo-fixer' ); ?></dt>
						<dd><?php echo esc_html( number_format_i18n( (int) ( $facts['words'] ?? 0 ) ) ); ?></dd>
						<dt><?php esc_html_e( 'Internal links', 'seo-fixer' ); ?></dt>
						<dd><?php echo esc_html( number_format_i18n( (int) ( $facts['links_out'] ?? 0 ) ) ); ?></dd>
					</dl>
					<?php foreach ( $issues as $issue ) : ?>
						<div class="seofx-report-issue">
							<strong><?php echo esc_html( $issue['label'] ); ?></strong>
							<span><?php echo esc_html( $issue['page'] ); ?></span>
							<?php if ( $issue['items'] ) : ?>
								<ul>
									<?php foreach ( $issue['items'] as $item ) : ?>
										<li><code><?php echo esc_html( self::item_text( $item ) ); ?></code></li>
									<?php endforeach; ?>
									<?php if ( $issue['count'] > count( $issue['items'] ) ) : ?>
										<li class="seofx-muted">
											<?php
											/* translators: %s: number of further items. */
											echo esc_html( sprintf( __( 'and %s more', 'seo-fixer' ), number_format_i18n( $issue['count'] - count( $issue['items'] ) ) ) );
											?>
										</li>
									<?php endif; ?>
								</ul>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</details>
			</td>
			<td class="seofx-num"><?php self::score_pill( (int) $row['score'] ); ?></td>
			<td>
				<?php if ( array() === $issues ) : ?>
					<span class="seofx-muted"><?php esc_html_e( 'None', 'seo-fixer' ); ?></span>
				<?php else : ?>
					<ul class="seofx-chips">
						<?php foreach ( $issues as $issue ) : ?>
							<li class="seofx-chip seofx-chip--<?php echo esc_attr( $severities[ $issue['severity'] ]['tone'] ); ?>">
								<span class="screen-reader-text"><?php echo esc_html( $severities[ $issue['severity'] ]['label'] . ':' ); ?></span>
								<?php echo esc_html( $issue['label'] ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( self::type_label( $row['post_type'] ) ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Coloured score pill.
	 *
	 * @param int $score Score.
	 * @return void
	 */
	public static function score_pill( $score ) {
		$tone = UI::score_tone( $score );
		/* translators: %d: score from 0 to 100. */
		$label = sprintf( __( 'Score %d out of 100', 'seo-fixer' ), $score );
		printf( '<span class="seofx-pill seofx-pill--%1$s" title="%2$s"><span aria-hidden="true">%3$d</span><span class="screen-reader-text">%2$s</span></span>', esc_attr( $tone ), esc_attr( $label ), (int) $score );
	}

	/**
	 * Previous/next paging with page numbers.
	 *
	 * @param int   $paged   Current page.
	 * @param int   $pages   Number of pages.
	 * @param array $request From request().
	 * @return void
	 */
	private static function pagination( $paged, $pages, array $request ) {
		if ( $pages < 2 ) {
			return;
		}
		$args = self::filter_args( $request );
		?>
		<nav class="seofx-pagination" aria-label="<?php esc_attr_e( 'Pages of results', 'seo-fixer' ); ?>">
			<?php if ( $paged > 1 ) : ?>
				<a class="seofx-btn seofx-btn--ghost seofx-btn--sm" href="<?php echo esc_url( self::url( 'pages', $args + array( 'paged' => (string) ( $paged - 1 ) ) ) ); ?>"><?php esc_html_e( 'Previous', 'seo-fixer' ); ?></a>
			<?php endif; ?>
			<span>
				<?php
				/* translators: 1: current page, 2: number of pages. */
				echo esc_html( sprintf( __( 'Page %1$s of %2$s', 'seo-fixer' ), number_format_i18n( $paged ), number_format_i18n( $pages ) ) );
				?>
			</span>
			<?php if ( $paged < $pages ) : ?>
				<a class="seofx-btn seofx-btn--ghost seofx-btn--sm" href="<?php echo esc_url( self::url( 'pages', $args + array( 'paged' => (string) ( $paged + 1 ) ) ) ); ?>"><?php esc_html_e( 'Next', 'seo-fixer' ); ?></a>
			<?php endif; ?>
		</nav>
		<?php
	}

	/*
	 * ---------------------------------------------------------------------
	 * Printable report.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Renders the print-friendly report (the browser's "Save as PDF" makes a PDF of it).
	 *
	 * @param array $summary Scan summary.
	 * @return void
	 */
	public static function render_print( array $summary ) {
		$findings   = Dashboard_Page::findings( $summary );
		$severities = Dashboard_Page::severities();
		$guidance   = Checks::guidance();
		$filters    = array( 'band' => 'problems' );
		$total      = Results::report_count( $filters );
		$rows       = Results::report( $filters, 'score', 'asc', self::PRINT_LIMIT, 0 );
		self::prime( $rows );
		$date     = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $summary['scanned_at'] );
		?>
		<div class="seofx-print-bar seofx-no-print">
			<a class="seofx-btn seofx-btn--ghost seofx-btn--sm" href="<?php echo esc_url( self::url( '' ) ); ?>"><?php esc_html_e( '← Back to SEO Health', 'seo-fixer' ); ?></a>
			<button type="button" class="seofx-btn seofx-btn--primary seofx-btn--sm" data-seofx-print><?php UI::icon( 'print', 16 ); ?><?php esc_html_e( 'Print or save as PDF', 'seo-fixer' ); ?></button>
		</div>

		<article class="seofx-card seofx-print">
			<header class="seofx-print__header">
				<div>
					<h2 class="seofx-print__title"><?php esc_html_e( 'SEO health report', 'seo-fixer' ); ?></h2>
					<p class="seofx-print__meta">
						<?php echo esc_html( get_bloginfo( 'name' ) ); ?> · <?php echo esc_html( home_url( '/' ) ); ?><br />
						<?php
						/* translators: %s: date and time of the scan. */
						echo esc_html( sprintf( __( 'Scanned %s', 'seo-fixer' ), $date ) );
						?>
					</p>
				</div>
				<?php UI::score_ring( (int) $summary['score'], '', 'sm' ); ?>
			</header>

			<table class="seofx-print__scores">
				<tr>
					<th scope="row"><?php esc_html_e( 'Overall score', 'seo-fixer' ); ?></th>
					<td><?php echo esc_html( (string) (int) $summary['score'] ); ?> / 100</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Site settings (40%)', 'seo-fixer' ); ?></th>
					<td><?php echo esc_html( (string) (int) $summary['site_score'] ); ?> / 100</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Pages (60%)', 'seo-fixer' ); ?></th>
					<td>
						<?php
						if ( null === $summary['content_score'] ) {
							esc_html_e( 'No pages yet', 'seo-fixer' );
						} else {
							/* translators: 1: score, 2: number of pages. */
							echo esc_html( sprintf( __( '%1$s / 100 (average of %2$s pages)', 'seo-fixer' ), (int) $summary['content_score'], number_format_i18n( (int) $summary['pages'] ) ) );
						}
						?>
					</td>
				</tr>
			</table>

			<h3 class="seofx-print__heading"><?php esc_html_e( 'What to fix', 'seo-fixer' ); ?></h3>
			<?php if ( array() === $findings['problems'] ) : ?>
				<p><?php esc_html_e( 'No problems found.', 'seo-fixer' ); ?></p>
			<?php else : ?>
				<ol class="seofx-print__findings">
					<?php foreach ( $findings['problems'] as $item ) : ?>
						<li>
							<strong><?php echo esc_html( $item['label'] ); ?></strong>
							<span class="seofx-print__severity">(<?php echo esc_html( $severities[ $item['severity'] ]['label'] ); ?>)</span>
							<p><?php echo esc_html( $item['message'] ); ?></p>
							<?php if ( isset( $guidance[ $item['id'] ] ) ) : ?>
								<p class="seofx-print__guide">
									<em><?php esc_html_e( 'Why it matters:', 'seo-fixer' ); ?></em> <?php echo esc_html( $guidance[ $item['id'] ]['why'] ); ?><br />
									<em><?php esc_html_e( 'How to fix it:', 'seo-fixer' ); ?></em> <?php echo esc_html( $guidance[ $item['id'] ]['how'] ); ?>
								</p>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>

			<?php if ( $findings['passed'] ) : ?>
				<h3 class="seofx-print__heading"><?php esc_html_e( 'Already in good shape', 'seo-fixer' ); ?></h3>
				<ul class="seofx-print__passed">
					<?php foreach ( $findings['passed'] as $item ) : ?>
						<li><?php echo esc_html( $item['label'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( $rows ) : ?>
				<h3 class="seofx-print__heading"><?php esc_html_e( 'Pages with problems', 'seo-fixer' ); ?></h3>
				<?php if ( $total > count( $rows ) ) : ?>
					<p class="seofx-muted">
						<?php
						/* translators: 1: pages listed, 2: all pages with problems. */
						echo esc_html( sprintf( __( 'The %1$s pages with the lowest scores, out of %2$s. Download the CSV from the Pages view for the full list.', 'seo-fixer' ), number_format_i18n( count( $rows ) ), number_format_i18n( $total ) ) );
						?>
					</p>
				<?php endif; ?>
				<table class="seofx-print__pages">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Page', 'seo-fixer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Score', 'seo-fixer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Problems', 'seo-fixer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td>
									<?php echo esc_html( '' !== trim( (string) $row['post_title'] ) ? $row['post_title'] : __( '(no title)', 'seo-fixer' ) ); ?>
									<br /><small><?php echo esc_html( (string) get_permalink( (int) $row['post_id'] ) ); ?></small>
								</td>
								<td><?php echo esc_html( (string) (int) $row['score'] ); ?></td>
								<td><?php echo esc_html( implode( ', ', wp_list_pluck( self::sorted_issues( $row['issues'] ), 'label' ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p class="seofx-print__footer">
				<?php
				/* translators: %s: plugin name. */
				echo esc_html( sprintf( __( 'Made with %s.', 'seo-fixer' ), 'SEO Fixer' ) );
				?>
			</p>
		</article>
		<?php
	}

	/*
	 * ---------------------------------------------------------------------
	 * CSV download.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Downloads the (filtered) page list as CSV, 500 rows at a time.
	 *
	 * @return void
	 */
	public function handle_csv() {
		Security::require_manage();
		check_admin_referer( 'seofx_report_csv' );

		$request = self::request();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=seo-fixer-health-' . gmdate( 'Y-m-d' ) . '.csv' );

		$header = array(
			__( 'Address', 'seo-fixer' ),
			__( 'Title', 'seo-fixer' ),
			__( 'Type', 'seo-fixer' ),
			__( 'Score', 'seo-fixer' ),
			__( 'Problems', 'seo-fixer' ),
			__( 'Details', 'seo-fixer' ),
			__( 'Title in Google', 'seo-fixer' ),
			__( 'Description', 'seo-fixer' ),
			__( 'Words', 'seo-fixer' ),
		);
		// UTF-8 byte order mark so spreadsheet apps read accents correctly.
		echo "\xEF\xBB\xBF" . UI::csv_line( $header ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download; every cell is escaped by UI::csv_line().

		$offset = 0;
		do {
			$rows = Results::report( $request, $request['orderby'], $request['order'], 500, $offset );
			self::prime( $rows );
			foreach ( $rows as $row ) {
				$issues  = self::sorted_issues( $row['issues'] );
				$details = array();
				foreach ( $issues as $issue ) {
					foreach ( $issue['items'] as $item ) {
						$details[] = $issue['label'] . ': ' . self::item_text( $item );
					}
				}
				$facts = $row['facts'];
				$line  = UI::csv_line(
					array(
						(string) get_permalink( (int) $row['post_id'] ),
						$row['post_title'],
						self::type_label( $row['post_type'] ),
						(int) $row['score'],
						implode( '; ', wp_list_pluck( $issues, 'label' ) ),
						implode( '; ', $details ),
						isset( $facts['title'] ) ? $facts['title'] : '',
						isset( $facts['desc'] ) ? $facts['desc'] : '',
						isset( $facts['words'] ) ? (int) $facts['words'] : 0,
					)
				);
				echo $line; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download; every cell is escaped by UI::csv_line().
			}
			$offset += 500;
		} while ( count( $rows ) === 500 );
		exit;
	}
}
