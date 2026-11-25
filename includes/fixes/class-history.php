<?php
/**
 * Change history for one-click and automatic fixes, so every change can be
 * undone. One row per changed value; rows applied together share a batch id.
 *
 * @package SeoFixer
 */

namespace Seofx\Fixes;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table with no WordPress API; read only on SEO Fixer screens.

/**
 * Fix history.
 */
final class History {

	/**
	 * Entries older than this many days are removed by the daily clean-up.
	 */
	const KEEP_DAYS = 90;

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'seofx_changes';
	}

	/**
	 * New batch id.
	 *
	 * @return string
	 */
	public static function new_batch() {
		return gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 8, false ) );
	}

	/**
	 * Records one change.
	 *
	 * @param string $batch     Batch id.
	 * @param int    $post_id   Post (or attachment) id.
	 * @param string $field     description|title|alt|library_alt, or import_post|import_term|import_site|import_redirect.
	 * @param string $old_value Value before.
	 * @param string $new_value Value written.
	 * @param array  $detail    Extra data needed to undo (e.g. image address).
	 * @param string $source    manual|auto.
	 * @return bool Whether it was saved (no record, no change: everything must be undoable).
	 */
	public static function record( $batch, $post_id, $field, $old_value, $new_value, array $detail = array(), $source = 'manual' ) {
		global $wpdb;
		return false !== $wpdb->insert(
			self::table(),
			array(
				'batch'     => $batch,
				'post_id'   => (int) $post_id,
				'field'     => $field,
				'old_value' => (string) $old_value,
				'new_value' => (string) $new_value,
				'detail'    => (string) wp_json_encode( $detail ),
				'source'    => $source,
				'user_id'   => get_current_user_id(),
				'created'   => current_time( 'mysql', true ),
				'undone'    => 0,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' )
		);
	}

	/**
	 * Batches, newest first, with counts per field.
	 *
	 * @param int $page     Page number.
	 * @param int $per_page Batches per page.
	 * @return array{rows: array[], total: int}
	 */
	public static function batches( $page, $per_page ) {
		global $wpdb;
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT batch) FROM %i', self::table() ) );
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT batch, MIN(created) AS created, MAX(source) AS source, MAX(user_id) AS user_id, COUNT(*) AS changes, SUM(undone) AS undone,
					COUNT(DISTINCT CASE WHEN field IN (%s, %s, %s, %s) THEN post_id END) AS posts,
					SUM(CASE WHEN field = %s THEN 1 ELSE 0 END) AS descriptions,
					SUM(CASE WHEN field = %s THEN 1 ELSE 0 END) AS titles,
					SUM(CASE WHEN field = %s THEN 1 ELSE 0 END) AS alts,
					SUM(CASE WHEN field = %s THEN 1 ELSE 0 END) AS imported,
					COUNT(DISTINCT CASE WHEN field = %s THEN post_id END) AS terms,
					SUM(CASE WHEN field = %s THEN 1 ELSE 0 END) AS settings,
					SUM(CASE WHEN field = %s THEN 1 ELSE 0 END) AS redirects,
					MAX(CASE WHEN field IN (%s, %s, %s, %s) THEN detail END) AS import_detail
				FROM %i GROUP BY batch ORDER BY MIN(id) DESC LIMIT %d OFFSET %d',
				'description',
				'title',
				'alt',
				'import_post',
				'description',
				'title',
				'alt',
				'import_post',
				'import_term',
				'import_site',
				'import_redirect',
				'import_post',
				'import_term',
				'import_site',
				'import_redirect',
				self::table(),
				(int) $per_page,
				( max( 1, (int) $page ) - 1 ) * (int) $per_page
			),
			ARRAY_A
		);
		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Changes in a batch that are still applied.
	 *
	 * @param string $batch Batch id.
	 * @return array[]
	 */
	private static function active( $batch ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE batch = %s AND undone = 0 ORDER BY id DESC', self::table(), $batch ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['detail'] = (array) json_decode( (string) $row['detail'], true );
		}
		return $rows;
	}

	/**
	 * Marks changes as undone.
	 *
	 * @param int[] $ids Change ids.
	 * @return void
	 */
	private static function mark_undone( array $ids ) {
		global $wpdb;
		foreach ( $ids as $id ) {
			$wpdb->update( self::table(), array( 'undone' => 1 ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
		}
	}

	/**
	 * Undoes a batch. A value that was edited again since is left alone (the
	 * newer edit wins), so undo never destroys someone's later work.
	 *
	 * Large batches are undone in parts: work stops after $seconds and
	 * 'more' says that another click is needed.
	 *
	 * @param string $batch   Batch id.
	 * @param int    $seconds Time budget.
	 * @return array{undone: int, skipped: int, posts: int[], more: bool}
	 */
	public static function undo( $batch, $seconds = 12 ) {
		$result  = array(
			'undone'  => 0,
			'skipped' => 0,
			'posts'   => array(),
			'more'    => false,
		);
		$alts    = array();
		$done    = array();
		$imports = false;
		$until   = microtime( true ) + $seconds;

		// One transaction: the restored values and their "undone" marks are saved together, and the
		// database commits once instead of per value (much faster for big imports).
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );

		foreach ( self::active( $batch ) as $row ) {
			if ( microtime( true ) >= $until ) {
				$result['more'] = true;
				break;
			}
			$post_id = (int) $row['post_id'];
			switch ( $row['field'] ) {
				case 'description':
				case 'title':
					$key = Fixer::meta_key( $row['field'] );
					if ( (string) get_post_meta( $post_id, $key, true ) === $row['new_value'] ) {
						if ( '' === $row['old_value'] ) {
							delete_post_meta( $post_id, $key );
						} else {
							update_post_meta( $post_id, $key, wp_slash( $row['old_value'] ) );
						}
						$done[]            = (int) $row['id'];
						$result['posts'][] = $post_id;
					} else {
						++$result['skipped'];
					}
					break;

				case 'library_alt':
					if ( (string) get_post_meta( $post_id, '_wp_attachment_image_alt', true ) === $row['new_value'] ) {
						update_post_meta( $post_id, '_wp_attachment_image_alt', wp_slash( $row['old_value'] ) );
					}
					$done[] = (int) $row['id'];
					break;

				case 'alt':
					$alts[ $post_id ][] = $row;
					break;

				case 'import_post':
				case 'import_term':
				case 'import_site':
				case 'import_redirect':
					if ( self::undo_import( $row ) ) {
						$done[] = (int) $row['id'];
						if ( 'import_post' === $row['field'] ) {
							$result['posts'][] = $post_id;
						}
						$imports = true;
					} else {
						++$result['skipped'];
					}
					break;
			}
		}
		if ( $imports ) {
			\Seofx\Indexing\Sitemaps::bump_version(); // Hidden pages may have changed.
		}

		// Image alt text: one content update per page.
		foreach ( $alts as $post_id => $rows ) {
			if ( microtime( true ) >= $until + $seconds / 2 ) {
				$result['more'] = true; // Content saves are slower: allow half the budget again, then stop (well under PHP's usual 30 s limit).
				break;
			}
			$post = get_post( $post_id );
			if ( ! $post ) {
				$result['skipped'] += count( $rows );
				continue;
			}
			$content = (string) $post->post_content;
			foreach ( $rows as $row ) {
				$restored = Html::restore_alt( $content, $row['detail'], $row['new_value'] );
				if ( $restored['count'] > 0 ) {
					$content = $restored['content'];
					$done[]  = (int) $row['id'];
				} else {
					++$result['skipped'];
				}
			}
			if ( $content !== $post->post_content ) {
				Fixer::update_content( $post, $content );
				$result['posts'][] = $post_id;
			}
		}

		self::mark_undone( $done );
		$wpdb->query( 'COMMIT' );
		$result['undone'] = count( $done );
		$result['posts']  = array_values( array_unique( $result['posts'] ) );
		return $result;
	}

	/**
	 * Undoes one imported value, unless it was changed again since.
	 *
	 * @param array $row Change row.
	 * @return bool Whether it was undone (or is already gone).
	 */
	private static function undo_import( array $row ) {
		$id  = (int) $row['post_id'];
		$key = isset( $row['detail']['k'] ) ? (string) $row['detail']['k'] : '';

		switch ( $row['field'] ) {
			case 'import_post':
			case 'import_term':
				$type = 'import_post' === $row['field'] ? 'post' : 'term';
				if ( 0 !== strpos( $key, '_seofx_' ) ) {
					return false; // Only ever SEO Fixer's own fields.
				}
				if ( (string) get_metadata( $type, $id, $key, true ) !== $row['new_value'] ) {
					return false;
				}
				delete_metadata( $type, $id, $key );
				return true;

			case 'import_site':
				if ( ! in_array( $key, \Seofx\Import\Importer::GROUPS['site'], true ) || (string) \Seofx\Options::get( $key ) !== $row['new_value'] ) {
					return false;
				}
				\Seofx\Options::update( array( $key => $row['old_value'] ) );
				return true;

			case 'import_redirect':
				$redirect = \Seofx\Redirects\Store::get( $id );
				if ( ! $redirect ) {
					return true; // Deleted since.
				}
				if ( (string) $redirect['target'] !== $row['new_value'] ) {
					return false;
				}
				\Seofx\Redirects\Store::delete( array( $id ) );
				return true;
		}
		return false;
	}

	/**
	 * Removes old entries (daily).
	 *
	 * @return void
	 */
	public static function purge() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created < %s', self::table(), gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) );
	}
}
