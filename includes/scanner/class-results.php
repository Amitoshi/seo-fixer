<?php
/**
 * Scan storage: one row per page (facts, issues, score) plus its internal links.
 *
 * @package SeoFixer
 */

namespace Seofx\Scanner;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables with no WordPress API; results are summarised into an option for display.

/**
 * Scan results storage.
 */
final class Results {

	/**
	 * Scan table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'seofx_scan';
	}

	/**
	 * Links table.
	 *
	 * @return string
	 */
	public static function links_table() {
		global $wpdb;
		return $wpdb->prefix . 'seofx_links';
	}

	/**
	 * Removes every result (before a full scan).
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table() ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::links_table() ) );
	}

	/**
	 * Removes one page's results.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function forget( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'post_id' => $post_id ), array( '%d' ) );
		$wpdb->delete( self::links_table(), array( 'source_id' => $post_id ), array( '%d' ) );
	}

	/**
	 * Stores the facts gathered for a page (issues are added in the scoring pass).
	 *
	 * @param \WP_Post $post  Post.
	 * @param array    $facts Facts from Analyzer.
	 * @param array    $links Internal links: list of {url, target_id, broken}.
	 * @return void
	 */
	public static function save_facts( \WP_Post $post, array $facts, array $links ) {
		global $wpdb;

		self::forget( $post->ID );

		// Pages hidden from search can't compete with others, so they don't count as duplicates.
		$compare = empty( $facts['noindex'] );

		$wpdb->insert(
			self::table(),
			array(
				'post_id'    => $post->ID,
				'post_type'  => $post->post_type,
				'score'      => 100,
				'issue_ids'  => '',
				'issues'     => '[]',
				'facts'      => (string) wp_json_encode( $facts ),
				'title_hash' => $compare && '' !== $facts['title'] ? md5( mb_strtolower( $facts['title'] ) ) : '',
				'desc_hash'  => $compare && '' !== $facts['desc'] ? md5( mb_strtolower( $facts['desc'] ) ) : '',
				'scanned_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		foreach ( $links as $link ) {
			$wpdb->insert(
				self::links_table(),
				array(
					'source_id' => $post->ID,
					'target_id' => (int) $link['target_id'],
					'url'       => mb_substr( $link['url'], 0, 255 ),
					'broken'    => $link['broken'] ? 1 : 0,
				),
				array( '%d', '%d', '%s', '%d' )
			);
		}
	}

	/**
	 * Saves the issues and score of a page.
	 *
	 * @param int   $post_id Post id.
	 * @param array $issues  List of {id, count?, items?}.
	 * @param int   $score   0–100.
	 * @return void
	 */
	public static function save_score( $post_id, array $issues, $score ) {
		global $wpdb;
		$ids = wp_list_pluck( $issues, 'id' );
		$wpdb->update(
			self::table(),
			array(
				'score'     => max( 0, min( 100, (int) $score ) ),
				'issue_ids' => $ids ? ',' . implode( ',', $ids ) . ',' : '',
				'issues'    => (string) wp_json_encode( $issues ),
			),
			array( 'post_id' => $post_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Rows after a post id (for batch passes).
	 *
	 * @param int $after Last processed id.
	 * @param int $limit Batch size.
	 * @return array[] Rows with decoded facts.
	 */
	public static function batch( $after, $limit ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, post_type, facts, title_hash, desc_hash FROM %i WHERE post_id > %d ORDER BY post_id ASC LIMIT %d', self::table(), $after, $limit ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['facts'] = json_decode( $row['facts'], true );
		}
		return $rows;
	}

	/**
	 * One page's row.
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	public static function get( $post_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', self::table(), $post_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['facts']  = json_decode( $row['facts'], true );
		$row['issues'] = json_decode( $row['issues'], true );
		return $row;
	}

	/**
	 * Title and description hashes used by more than one page.
	 *
	 * @return array{title: array<string, true>, desc: array<string, true>}
	 */
	public static function duplicate_hashes() {
		global $wpdb;
		$title = $wpdb->get_col( $wpdb->prepare( "SELECT title_hash FROM %i WHERE title_hash <> '' GROUP BY title_hash HAVING COUNT(*) > 1", self::table() ) );
		$desc  = $wpdb->get_col( $wpdb->prepare( "SELECT desc_hash FROM %i WHERE desc_hash <> '' GROUP BY desc_hash HAVING COUNT(*) > 1", self::table() ) );
		return array(
			'title' => array_fill_keys( (array) $title, true ),
			'desc'  => array_fill_keys( (array) $desc, true ),
		);
	}

	/**
	 * Number of other pages linking to each page.
	 *
	 * @return array<int, int> Target post id => linking pages.
	 */
	public static function inbound_counts() {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT target_id, COUNT(DISTINCT source_id) AS pages FROM %i WHERE target_id > 0 AND target_id <> source_id GROUP BY target_id', self::links_table() ), ARRAY_A );
		$map  = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row['target_id'] ] = (int) $row['pages'];
		}
		return $map;
	}

	/**
	 * Pages linking to one page.
	 *
	 * @param int $post_id Target post id.
	 * @return int
	 */
	public static function inbound_count( $post_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT source_id) FROM %i WHERE target_id = %d AND source_id <> %d', self::links_table(), $post_id, $post_id ) );
	}

	/**
	 * Whether another page has the same title or description hash.
	 *
	 * @param string $column  title_hash|desc_hash.
	 * @param string $hash    Hash.
	 * @param int    $post_id Page to exclude.
	 * @return bool
	 */
	public static function hash_shared( $column, $hash, $post_id ) {
		global $wpdb;
		if ( '' === $hash || ! in_array( $column, array( 'title_hash', 'desc_hash' ), true ) ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE %i = %s AND post_id <> %d LIMIT 1', self::table(), $column, $hash, $post_id ) );
	}

	/**
	 * Published content ids after a cursor, in id order.
	 *
	 * @param string[] $types Post types.
	 * @param int      $after Last processed id.
	 * @param int      $limit Batch size.
	 * @return int[]
	 */
	public static function published_ids( array $types, $after, $limit ) {
		global $wpdb;
		if ( array() === $types ) {
			return array();
		}
		$args = array_merge( array( $wpdb->posts ), array_values( $types ), array( (int) $after, (int) $limit ) );
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_status = 'publish' AND post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND ID > %d ORDER BY ID ASC LIMIT %d', $args ) ) );
	}

	/**
	 * Number of published content items.
	 *
	 * @param string[] $types Post types.
	 * @return int
	 */
	public static function published_count( array $types ) {
		global $wpdb;
		if ( array() === $types ) {
			return 0;
		}
		$args = array_merge( array( $wpdb->posts ), array_values( $types ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_status = 'publish' AND post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')', $args ) );
	}

	/**
	 * Pages whose "duplicate" flag can change when one page joins or leaves this group.
	 *
	 * Only a group of two matters: when one leaves, the other is no longer a duplicate;
	 * when one joins a single page, both become duplicates. In a bigger group everyone
	 * stays a duplicate, so nothing else needs rescoring (thousands of pages can share
	 * the same automatic description).
	 *
	 * @param string $column title_hash|desc_hash.
	 * @param string $hash   Hash (including the changed page).
	 * @return int[]
	 */
	public static function dupe_partners( $column, $hash ) {
		global $wpdb;
		if ( '' === $hash || ! in_array( $column, array( 'title_hash', 'desc_hash' ), true ) ) {
			return array();
		}
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE %i = %s LIMIT 3', self::table(), $column, $hash ) ) );
		return count( $ids ) <= 2 ? $ids : array();
	}

	/**
	 * Pages a page links to.
	 *
	 * @param int $post_id Source post id.
	 * @return int[]
	 */
	public static function targets_of( $post_id ) {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT target_id FROM %i WHERE source_id = %d AND target_id > 0', self::links_table(), $post_id ) ) );
	}

	/**
	 * Score range of a band.
	 *
	 * @param string $band bad|warn|good|problems|'' (all).
	 * @return int[] Min and max score.
	 */
	private static function band_range( $band ) {
		$bands = array(
			'bad'      => array( 0, 49 ),
			'warn'     => array( 50, 79 ),
			'good'     => array( 80, 100 ),
			'problems' => array( 0, 99 ),
		);
		return isset( $bands[ $band ] ) ? $bands[ $band ] : array( 0, 100 );
	}

	/**
	 * Placeholder values shared by the report queries.
	 *
	 * Every filter is always present in the SQL and switched off with an empty
	 * value, so the queries stay fully prepared.
	 *
	 * @param array $filters issue, type, band, search.
	 * @return array{issue: string, issue_like: string, type: string, min: int, max: int, search: string, search_like: string}
	 */
	private static function filter_args( array $filters ) {
		global $wpdb;
		$issue  = isset( $filters['issue'] ) ? (string) $filters['issue'] : '';
		$search = isset( $filters['search'] ) ? (string) $filters['search'] : '';
		$range  = self::band_range( isset( $filters['band'] ) ? (string) $filters['band'] : '' );
		return array(
			'issue'       => $issue,
			'issue_like'  => '%,' . $wpdb->esc_like( $issue ) . ',%',
			'type'        => isset( $filters['type'] ) ? (string) $filters['type'] : '',
			'min'         => $range[0],
			'max'         => $range[1],
			'search'      => $search,
			'search_like' => '%' . $wpdb->esc_like( $search ) . '%',
		);
	}

	/**
	 * Pages for the report, filtered and sorted.
	 *
	 * @param array  $filters issue, type, band (bad|warn|good|problems), search.
	 * @param string $orderby score|post_title|post_type|scanned_at.
	 * @param string $order   asc|desc.
	 * @param int    $limit   Rows.
	 * @param int    $offset  Offset.
	 * @return array[] Rows: post_id, post_type, score, issues (decoded), facts (decoded), post_title.
	 */
	public static function report( array $filters, $orderby, $order, $limit, $offset ) {
		global $wpdb;
		$orderby = in_array( $orderby, array( 'score', 'post_title', 'post_type', 'scanned_at' ), true ) ? $orderby : 'score';
		$f       = self::filter_args( $filters );

		if ( 'desc' === $order ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.post_id, s.post_type, s.score, s.issues, s.facts, s.scanned_at, p.post_title FROM %i s INNER JOIN %i p ON p.ID = s.post_id WHERE ( %s = '' OR s.issue_ids LIKE %s ) AND ( %s = '' OR s.post_type = %s ) AND s.score BETWEEN %d AND %d AND ( %s = '' OR p.post_title LIKE %s ) ORDER BY %i DESC, s.post_id DESC LIMIT %d OFFSET %d", self::table(), $wpdb->posts, $f['issue'], $f['issue_like'], $f['type'], $f['type'], $f['min'], $f['max'], $f['search'], $f['search_like'], $orderby, (int) $limit, (int) $offset ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.post_id, s.post_type, s.score, s.issues, s.facts, s.scanned_at, p.post_title FROM %i s INNER JOIN %i p ON p.ID = s.post_id WHERE ( %s = '' OR s.issue_ids LIKE %s ) AND ( %s = '' OR s.post_type = %s ) AND s.score BETWEEN %d AND %d AND ( %s = '' OR p.post_title LIKE %s ) ORDER BY %i ASC, s.post_id ASC LIMIT %d OFFSET %d", self::table(), $wpdb->posts, $f['issue'], $f['issue_like'], $f['type'], $f['type'], $f['min'], $f['max'], $f['search'], $f['search_like'], $orderby, (int) $limit, (int) $offset ), ARRAY_A );
		}

		$rows = (array) $rows;
		foreach ( $rows as &$row ) {
			$row['issues'] = (array) json_decode( $row['issues'], true );
			$row['facts']  = (array) json_decode( $row['facts'], true );
		}
		return $rows;
	}

	/**
	 * Number of pages matching the report filters.
	 *
	 * @param array $filters See report().
	 * @return int
	 */
	public static function report_count( array $filters ) {
		global $wpdb;
		$f = self::filter_args( $filters );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i s INNER JOIN %i p ON p.ID = s.post_id WHERE ( %s = '' OR s.issue_ids LIKE %s ) AND ( %s = '' OR s.post_type = %s ) AND s.score BETWEEN %d AND %d AND ( %s = '' OR p.post_title LIKE %s )", self::table(), $wpdb->posts, $f['issue'], $f['issue_like'], $f['type'], $f['type'], $f['min'], $f['max'], $f['search'], $f['search_like'] ) );
	}

	/**
	 * Pages with an issue, in id order after a cursor (for batch fixes).
	 *
	 * @param string $issue Issue id.
	 * @param int    $after Last id handled.
	 * @param int    $limit Batch size.
	 * @return int[]
	 */
	public static function ids_with_issue( $issue, $after, $limit ) {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE issue_ids LIKE %s AND post_id > %d ORDER BY post_id ASC LIMIT %d', self::table(), '%,' . $wpdb->esc_like( $issue ) . ',%', (int) $after, (int) $limit ) ) );
	}

	/**
	 * Content types present in the results.
	 *
	 * @return string[]
	 */
	public static function types() {
		global $wpdb;
		return (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT post_type FROM %i ORDER BY post_type ASC', self::table() ) );
	}

	/**
	 * Average score, page count and pages per issue.
	 *
	 * @param string[] $issue_ids Issue ids to count.
	 * @return array{pages: int, average: int|null, issues: array<string, int>}
	 */
	public static function totals( array $issue_ids ) {
		global $wpdb;
		$row    = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS pages, AVG(score) AS average FROM %i', self::table() ), ARRAY_A );
		$counts = array();
		foreach ( $issue_ids as $id ) {
			$counts[ $id ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE issue_ids LIKE %s', self::table(), '%,' . $wpdb->esc_like( $id ) . ',%' ) );
		}
		return array(
			'pages'   => (int) $row['pages'],
			'average' => $row['pages'] ? (int) round( (float) $row['average'] ) : null,
			'issues'  => $counts,
		);
	}
}
