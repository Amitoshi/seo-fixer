<?php
/**
 * Import source: Rank Math SEO.
 *
 * @package SeoFixer
 */

namespace Seofx\Import;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading Rank Math's own redirects table once, during an import the user started.

/**
 * Reads Rank Math's post and term meta, settings and redirects table.
 */
final class Rank_Math extends Source {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'rankmath';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'Rank Math SEO';
	}

	/**
	 * Post meta keys (the same keys are used for terms).
	 *
	 * @return string[]
	 */
	protected function post_keys() {
		return array(
			'rank_math_title',
			'rank_math_description',
			'rank_math_robots',
			'rank_math_canonical_url',
			'rank_math_facebook_title',
			'rank_math_facebook_description',
			'rank_math_facebook_image_id',
			'rank_math_facebook_image',
			'rank_math_focus_keyword',
		);
	}

	/**
	 * Term meta keys.
	 *
	 * @return string[]
	 */
	protected function term_keys() {
		return $this->post_keys();
	}

	/**
	 * Variables: %name% or %name(argument)%.
	 *
	 * @return string
	 */
	protected function variable_pattern() {
		return '/%([a-z0-9_]+)(?:\([^)%]*\))?%/i';
	}

	/**
	 * Variables. Rank Math variables SEO Fixer doesn't have are left out.
	 *
	 * @return array<string, string>
	 */
	protected function variables() {
		return array(
			'title'            => 'title',
			'seo_title'        => 'title',
			'sitename'         => 'sitename',
			'sitedesc'         => 'tagline',
			'sep'              => 'sep',
			'excerpt'          => 'excerpt',
			'excerpt_only'     => 'excerpt',
			'seo_description'  => 'excerpt',
			'category'         => 'category',
			'primary_category' => 'category',
			'term'             => 'term',
			'term_description' => 'term_description',
			'name'             => 'author',
			'post_author'      => 'author',
			'user_description' => 'author_bio',
			'date'             => 'date',
			'pt_plural'        => 'archive_title',
			'search_query'     => 'search',
			'page'             => 'page',
			'pagenumber'       => 'page',
			'currentyear'      => 'year',
			// Known to Rank Math, not to SEO Fixer.
			'focuskw'          => '',
			'keywords'         => '',
			'modified'         => '',
			'id'               => '',
			'parent_title'     => '',
			'categories'       => '',
			'tags'             => '',
			'tag'              => '',
			'pt_single'        => '',
			'filename'         => '',
			'url'              => '',
			'org_name'         => '',
			'org_url'          => '',
			'org_logo'         => '',
			'currenttime'      => '',
			'currentdate'      => '',
			'currentday'       => '',
			'currentmonth'     => '',
			'customfield'      => '',
			'customterm'       => '',
			'customterm_desc'  => '',
			'count'            => '',
			'post_thumbnail'   => '',
			'wc_price'         => '',
			'wc_sku'           => '',
			'wc_brand'         => '',
		);
	}

	/**
	 * Rank Math stores robots as a list, e.g. array( 'noindex', 'nofollow' ).
	 *
	 * @param mixed $robots Stored value.
	 * @return array{noindex: bool|null, nofollow: bool|null}
	 */
	private static function robots( $robots ) {
		$robots = is_array( $robots ) ? array_map( 'strval', $robots ) : array();
		return array(
			'noindex'  => in_array( 'noindex', $robots, true ) ? true : ( in_array( 'index', $robots, true ) ? false : null ),
			'nofollow' => in_array( 'nofollow', $robots, true ) ? true : null,
		);
	}

	/**
	 * Records for posts.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int, array>
	 */
	public function posts( array $ids ) {
		update_meta_cache( 'post', $ids );
		$records = array();
		foreach ( $ids as $id ) {
			$robots         = self::robots( get_post_meta( $id, 'rank_math_robots', true ) );
			$records[ $id ] = self::clean(
				array(
					'title'              => self::meta( $id, 'rank_math_title' ),
					'description'        => self::meta( $id, 'rank_math_description' ),
					'noindex'            => $robots['noindex'],
					'nofollow'           => $robots['nofollow'],
					'canonical'          => self::meta( $id, 'rank_math_canonical_url' ),
					'social_title'       => self::meta( $id, 'rank_math_facebook_title' ),
					'social_description' => self::meta( $id, 'rank_math_facebook_description' ),
					'social_image'       => self::image( self::meta( $id, 'rank_math_facebook_image_id' ), self::meta( $id, 'rank_math_facebook_image' ) ),
					'focus'              => self::meta( $id, 'rank_math_focus_keyword' ),
				)
			);
		}
		return $records;
	}

	/**
	 * Records for terms.
	 *
	 * @param int[] $ids Term ids.
	 * @return array<int, array>
	 */
	public function terms( array $ids ) {
		update_meta_cache( 'term', $ids );
		$records = array();
		foreach ( $ids as $id ) {
			$robots         = self::robots( get_term_meta( $id, 'rank_math_robots', true ) );
			$records[ $id ] = self::clean(
				array(
					'title'              => self::term_meta( $id, 'rank_math_title' ),
					'description'        => self::term_meta( $id, 'rank_math_description' ),
					'noindex'            => $robots['noindex'],
					'nofollow'           => $robots['nofollow'],
					'canonical'          => self::term_meta( $id, 'rank_math_canonical_url' ),
					'social_title'       => self::term_meta( $id, 'rank_math_facebook_title' ),
					'social_description' => self::term_meta( $id, 'rank_math_facebook_description' ),
					'social_image'       => self::image( self::term_meta( $id, 'rank_math_facebook_image_id' ), self::term_meta( $id, 'rank_math_facebook_image' ) ),
					'focus'              => self::term_meta( $id, 'rank_math_focus_keyword' ),
				)
			);
		}
		return $records;
	}

	/**
	 * Site settings.
	 *
	 * @return array<string, string>
	 */
	public function settings() {
		$titles  = self::option( 'rank-math-options-titles' );
		$general = self::option( 'rank-math-options-general' );

		$separator = self::dig( $titles, array( 'title_separator' ) );
		$separator = html_entity_decode( $separator, ENT_QUOTES, 'UTF-8' );
		$separator = in_array( $separator, array( '–', '-' ), true ) ? '-' : $separator;

		return array(
			'home_title'       => self::dig( $titles, array( 'homepage_title' ) ),
			'home_description' => self::dig( $titles, array( 'homepage_description' ) ),
			'title_separator'  => in_array( $separator, array( '-', '—', '|', '·', '•', '/', '»' ), true ) ? $separator : '',
			'verify_google'    => self::dig( $general, array( 'google_verify' ) ),
			'verify_bing'      => self::dig( $general, array( 'bing_verify' ) ),
			'verify_yandex'    => self::dig( $general, array( 'yandex_verify' ) ),
			'verify_pinterest' => self::dig( $general, array( 'pinterest_verify' ) ),
		);
	}

	/**
	 * Rank Math's redirects table, if it exists.
	 *
	 * @return string
	 */
	private static function redirect_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ? $table : '';
	}

	/**
	 * Number of redirects (not counting ones in Rank Math's trash).
	 *
	 * @return int
	 */
	public function redirect_count() {
		global $wpdb;
		$table = self::redirect_table();
		return '' === $table ? 0 : (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status <> 'trashed'", $table ) );
	}

	/**
	 * Redirects. One Rank Math redirect can have several "from" addresses;
	 * each becomes its own SEO Fixer redirect.
	 *
	 * @param int $after Last Rank Math id handled.
	 * @param int $limit Maximum number of Rank Math redirects.
	 * @return array{items: array[], next: int}
	 */
	public function redirects( $after, $limit ) {
		global $wpdb;
		$table = self::redirect_table();
		if ( '' === $table ) {
			return array(
				'items' => array(),
				'next'  => 0,
			);
		}
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, sources, url_to, header_code FROM %i WHERE status <> 'trashed' AND id > %d ORDER BY id ASC LIMIT %d", $table, (int) $after, (int) $limit ), ARRAY_A );
		$items = array();
		$last  = 0;
		foreach ( $rows as $row ) {
			$last    = (int) $row['id'];
			$sources = maybe_unserialize( (string) $row['sources'] );
			foreach ( is_array( $sources ) ? $sources : array() as $source ) {
				if ( ! is_array( $source ) || ! isset( $source['pattern'] ) || '' === trim( (string) $source['pattern'] ) ) {
					continue;
				}
				$pattern    = trim( (string) $source['pattern'] );
				$comparison = isset( $source['comparison'] ) ? (string) $source['comparison'] : 'exact';
				$quoted     = preg_quote( '/' . ltrim( $pattern, '/' ), '#' );
				switch ( $comparison ) {
					case 'regex':
						$match = self::regex_with_slash( $pattern );
						break;
					case 'start':
						$match = '^' . $quoted;
						break;
					case 'end':
						$match = preg_quote( $pattern, '#' ) . '$';
						break;
					case 'contains':
						$match = preg_quote( $pattern, '#' );
						break;
					default:
						$match = '';
				}
				$items[] = array(
					'source'     => '' === $match ? '/' . ltrim( $pattern, '/' ) : $match,
					'target'     => (string) $row['url_to'],
					'status'     => self::status( $row['header_code'] ),
					'match_type' => '' === $match ? 'exact' : 'regex',
				);
			}
		}
		return array(
			'items' => $items,
			'next'  => count( $rows ) < $limit ? 0 : $last,
		);
	}
}
