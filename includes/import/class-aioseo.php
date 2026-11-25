<?php
/**
 * Import source: All in One SEO (version 4 and later).
 *
 * @package SeoFixer
 */

namespace Seofx\Import;

use Seofx\Meta\Templates;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading All in One SEO's own tables once, during an import the user started.

/**
 * Reads All in One SEO's tables (aioseo_posts; aioseo_terms and
 * aioseo_redirects exist in its Pro version) and its settings.
 */
final class Aioseo extends Source {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'aioseo';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'All in One SEO';
	}

	/**
	 * No post meta: everything is in its own table.
	 *
	 * @return string[]
	 */
	protected function post_keys() {
		return array();
	}

	/**
	 * Tags such as #post_title or #custom_field-price.
	 *
	 * @return string
	 */
	protected function variable_pattern() {
		return '/#([a-z_]+)(?:-[a-z0-9_-]+)?/i';
	}

	/**
	 * Tags. Unknown "#words" (hashtags) are kept as they are.
	 *
	 * @return array<string, string>
	 */
	protected function variables() {
		return array(
			'post_title'           => 'title',
			'site_title'           => 'sitename',
			'tagline'              => 'tagline',
			'separator_sa'         => 'sep',
			'post_excerpt'         => 'excerpt',
			'post_excerpt_only'    => 'excerpt',
			'post_content'         => 'excerpt',
			'categories'           => 'category',
			'taxonomy_title'       => 'term',
			'category_title'       => 'term',
			'taxonomy_description' => 'term_description',
			'category_description' => 'term_description',
			'author_name'          => 'author',
			'author_first_name'    => 'author',
			'author_bio'           => 'author_bio',
			'post_date'            => 'date',
			'archive_title'        => 'archive_title',
			'archive_date'         => 'date',
			'search_term'          => 'search',
			'page_number'          => 'page',
			'current_year'         => 'year',
			// Known to All in One SEO, not to SEO Fixer.
			'author_last_name'     => '',
			'current_date'         => '',
			'current_day'          => '',
			'current_month'        => '',
			'custom_field'         => '',
			'permalink'            => '',
			'post_day'             => '',
			'post_month'           => '',
			'post_year'            => '',
			'parent_title'         => '',
			'tax_name'             => '',
			'post_link'            => '',
			'post_link_alt'        => '',
			'site_link'            => '',
			'site_link_alt'        => '',
		);
	}

	/**
	 * Table name if it exists.
	 *
	 * @param string $name posts|terms|redirects.
	 * @return string
	 */
	private static function table( $name ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_' . $name;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ? $table : '';
	}

	/**
	 * Post ids with data, in the content types SEO Fixer handles.
	 *
	 * @param int $after Last id handled.
	 * @param int $limit Maximum number.
	 * @return int[]
	 */
	public function post_ids( $after, $limit ) {
		global $wpdb;
		$table = self::table( 'posts' );
		$types = array_keys( Templates::post_types() );
		if ( '' === $table || array() === $types ) {
			return array();
		}
		$args = array_merge( array( $table, $wpdb->posts ), $types, array( (int) $after, (int) $limit ) );
		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT a.post_id FROM %i a INNER JOIN %i p ON p.ID = a.post_id WHERE p.post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ") AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit') AND (COALESCE(a.title, '') <> '' OR COALESCE(a.description, '') <> '' OR COALESCE(a.canonical_url, '') <> '' OR COALESCE(a.og_title, '') <> '' OR COALESCE(a.og_description, '') <> '' OR a.robots_default = 0 OR a.og_image_type = 'custom_image') AND a.post_id > %d ORDER BY a.post_id ASC LIMIT %d",
					$args
				)
			)
		);
	}

	/**
	 * Number of posts with a row.
	 *
	 * @return int
	 */
	public function post_count() {
		global $wpdb;
		$table = self::table( 'posts' );
		$types = array_keys( Templates::post_types() );
		if ( '' === $table || array() === $types ) {
			return 0;
		}
		$args = array_merge( array( $table, $wpdb->posts ), $types );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i a INNER JOIN %i p ON p.ID = a.post_id WHERE p.post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ") AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit') AND (COALESCE(a.title, '') <> '' OR COALESCE(a.description, '') <> '' OR COALESCE(a.canonical_url, '') <> '' OR COALESCE(a.og_title, '') <> '' OR COALESCE(a.og_description, '') <> '' OR a.robots_default = 0 OR a.og_image_type = 'custom_image')",
				$args
			)
		);
	}

	/**
	 * Rows for some ids.
	 *
	 * @param string $table  Table.
	 * @param string $column post_id|term_id.
	 * @param int[]  $ids    Ids.
	 * @return array<int, array>
	 */
	private static function rows( $table, $column, array $ids ) {
		global $wpdb;
		if ( '' === $table || array() === $ids ) {
			return array();
		}
		$args = array_merge( array( $table, $column ), array_map( 'intval', $ids ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE %i IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $args ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row[ $column ] ] = $row;
		}
		return $out;
	}

	/**
	 * Record from a posts or terms row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function record( array $row ) {
		$value   = static function ( $key ) use ( $row ) {
			return isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? trim( (string) $row[ $key ] ) : '';
		};
		$default = '' === $value( 'robots_default' ) || '1' === $value( 'robots_default' );
		$focus   = json_decode( $value( 'keyphrases' ), true );
		$image   = 'custom_image' === $value( 'og_image_type' ) ? $value( 'og_image_custom_url' ) : '';

		return self::clean(
			array(
				'title'              => $value( 'title' ),
				'description'        => $value( 'description' ),
				'noindex'            => $default ? null : ( '1' === $value( 'robots_noindex' ) ),
				'nofollow'           => ! $default && '1' === $value( 'robots_nofollow' ) ? true : null,
				'canonical'          => $value( 'canonical_url' ),
				'social_title'       => $value( 'og_title' ),
				'social_description' => $value( 'og_description' ),
				'social_image'       => '' !== $image ? $image : null,
				'focus'              => is_array( $focus ) && isset( $focus['focus']['keyphrase'] ) && is_scalar( $focus['focus']['keyphrase'] ) ? trim( (string) $focus['focus']['keyphrase'] ) : '',
			)
		);
	}

	/**
	 * Records for posts.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int, array>
	 */
	public function posts( array $ids ) {
		$records = array();
		foreach ( self::rows( self::table( 'posts' ), 'post_id', $ids ) as $id => $row ) {
			$records[ $id ] = self::record( $row );
		}
		return $records;
	}

	/**
	 * Term ids with data (Pro only).
	 *
	 * @param int $after Last id handled.
	 * @param int $limit Maximum number.
	 * @return int[]
	 */
	public function term_ids( $after, $limit ) {
		global $wpdb;
		$table = self::table( 'terms' );
		if ( '' === $table ) {
			return array();
		}
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT a.term_id FROM %i a WHERE (COALESCE(a.title, '') <> '' OR COALESCE(a.description, '') <> '' OR COALESCE(a.canonical_url, '') <> '' OR COALESCE(a.og_title, '') <> '' OR COALESCE(a.og_description, '') <> '' OR a.robots_default = 0 OR a.og_image_type = 'custom_image') AND a.term_id > %d ORDER BY a.term_id ASC LIMIT %d", $table, (int) $after, (int) $limit ) ) );
	}

	/**
	 * Number of terms with a row.
	 *
	 * @return int
	 */
	public function term_count() {
		global $wpdb;
		$table = self::table( 'terms' );
		return '' === $table ? 0 : (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i a WHERE (COALESCE(a.title, '') <> '' OR COALESCE(a.description, '') <> '' OR COALESCE(a.canonical_url, '') <> '' OR COALESCE(a.og_title, '') <> '' OR COALESCE(a.og_description, '') <> '' OR a.robots_default = 0 OR a.og_image_type = 'custom_image')", $table ) );
	}

	/**
	 * Records for terms.
	 *
	 * @param int[] $ids Term ids.
	 * @return array<int, array>
	 */
	public function terms( array $ids ) {
		$records = array();
		foreach ( self::rows( self::table( 'terms' ), 'term_id', $ids ) as $id => $row ) {
			$records[ $id ] = self::record( $row );
		}
		return $records;
	}

	/**
	 * Site settings (one JSON option).
	 *
	 * @return array<string, string>
	 */
	public function settings() {
		$options   = self::option( 'aioseo_options' );
		$separator = html_entity_decode( self::dig( $options, array( 'searchAppearance', 'global', 'separator' ) ), ENT_QUOTES, 'UTF-8' );
		$separator = in_array( $separator, array( '–', '-' ), true ) ? '-' : $separator;

		return array(
			'home_title'       => self::dig( $options, array( 'searchAppearance', 'global', 'siteTitle' ) ),
			'home_description' => self::dig( $options, array( 'searchAppearance', 'global', 'metaDescription' ) ),
			'title_separator'  => in_array( $separator, array( '-', '—', '|', '·', '•', '/', '»' ), true ) ? $separator : '',
			'verify_google'    => self::dig( $options, array( 'webmasterTools', 'google' ) ),
			'verify_bing'      => self::dig( $options, array( 'webmasterTools', 'bing' ) ),
			'verify_yandex'    => self::dig( $options, array( 'webmasterTools', 'yandex' ) ),
			'verify_pinterest' => self::dig( $options, array( 'webmasterTools', 'pinterest' ) ),
		);
	}

	/**
	 * Number of redirects (Pro only).
	 *
	 * @return int
	 */
	public function redirect_count() {
		global $wpdb;
		$table = self::table( 'redirects' );
		return '' === $table ? 0 : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE enabled = 1', $table ) );
	}

	/**
	 * Redirects (Pro only).
	 *
	 * @param int $after Last id handled.
	 * @param int $limit Maximum number.
	 * @return array{items: array[], next: int}
	 */
	public function redirects( $after, $limit ) {
		global $wpdb;
		$table = self::table( 'redirects' );
		if ( '' === $table ) {
			return array(
				'items' => array(),
				'next'  => 0,
			);
		}
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE enabled = 1 AND id > %d ORDER BY id ASC LIMIT %d', $table, (int) $after, (int) $limit ), ARRAY_A );
		$items = array();
		$last  = 0;
		foreach ( $rows as $row ) {
			$last    = (int) $row['id'];
			$regex   = ! empty( $row['regex'] );
			$source  = isset( $row['source_url'] ) ? (string) $row['source_url'] : '';
			$items[] = array(
				'source'     => $regex ? self::regex_with_slash( $source ) : $source,
				'target'     => isset( $row['target_url'] ) ? (string) $row['target_url'] : '',
				'status'     => self::status( isset( $row['type'] ) ? $row['type'] : 301 ),
				'match_type' => $regex ? 'regex' : 'exact',
			);
		}
		return array(
			'items' => $items,
			'next'  => count( $rows ) < $limit ? 0 : $last,
		);
	}
}
