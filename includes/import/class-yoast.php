<?php
/**
 * Import source: Yoast SEO (and the redirects of Yoast SEO Premium).
 *
 * @package SeoFixer
 */

namespace Seofx\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Yoast SEO's post meta, its category/tag option and its settings.
 */
final class Yoast extends Source {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'yoast';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'Yoast SEO';
	}

	/**
	 * Post meta keys.
	 *
	 * @return string[]
	 */
	protected function post_keys() {
		return array(
			'_yoast_wpseo_title',
			'_yoast_wpseo_metadesc',
			'_yoast_wpseo_meta-robots-noindex',
			'_yoast_wpseo_meta-robots-nofollow',
			'_yoast_wpseo_canonical',
			'_yoast_wpseo_opengraph-title',
			'_yoast_wpseo_opengraph-description',
			'_yoast_wpseo_opengraph-image-id',
			'_yoast_wpseo_opengraph-image',
			'_yoast_wpseo_focuskw',
		);
	}

	/**
	 * Variables (Yoast drops any %%variable%% it doesn't know, and so do we).
	 *
	 * @return array<string, string>
	 */
	protected function variables() {
		return array(
			'title'                => 'title',
			'sitename'             => 'sitename',
			'sitedesc'             => 'tagline',
			'sep'                  => 'sep',
			'excerpt'              => 'excerpt',
			'excerpt_only'         => 'excerpt',
			'category'             => 'category',
			'primary_category'     => 'category',
			'term_title'           => 'term',
			'category_title'       => 'term',
			'term_description'     => 'term_description',
			'category_description' => 'term_description',
			'tag_description'      => 'term_description',
			'name'                 => 'author',
			'user_description'     => 'author_bio',
			'date'                 => 'date',
			'pt_plural'            => 'archive_title',
			'archive_title'        => 'archive_title',
			'searchphrase'         => 'search',
			'page'                 => 'page',
			'pagenumber'           => 'page',
			'currentyear'          => 'year',
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
			$noindex         = self::meta( $id, '_yoast_wpseo_meta-robots-noindex' );
			$records[ $id ] = self::clean(
				array(
					'title'              => self::meta( $id, '_yoast_wpseo_title' ),
					'description'        => self::meta( $id, '_yoast_wpseo_metadesc' ),
					'noindex'            => '1' === $noindex ? true : ( '2' === $noindex ? false : null ),
					'nofollow'           => '1' === self::meta( $id, '_yoast_wpseo_meta-robots-nofollow' ) ? true : null,
					'canonical'          => self::meta( $id, '_yoast_wpseo_canonical' ),
					'social_title'       => self::meta( $id, '_yoast_wpseo_opengraph-title' ),
					'social_description' => self::meta( $id, '_yoast_wpseo_opengraph-description' ),
					'social_image'       => self::image( self::meta( $id, '_yoast_wpseo_opengraph-image-id' ), self::meta( $id, '_yoast_wpseo_opengraph-image' ) ),
					'focus'              => self::meta( $id, '_yoast_wpseo_focuskw' ),
				)
			);
		}
		return $records;
	}

	/**
	 * Category and tag data, stored by Yoast in one option: [taxonomy][term id] => values.
	 *
	 * @return array<int, array>
	 */
	private function term_data() {
		$data = array();
		foreach ( self::option( 'wpseo_taxonomy_meta' ) as $terms ) {
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term_id => $values ) {
				if ( is_array( $values ) && (int) $term_id > 0 && array() !== array_filter( $values, 'is_scalar' ) ) {
					$data[ (int) $term_id ] = $values;
				}
			}
		}
		ksort( $data );
		return $data;
	}

	/**
	 * Term ids with data.
	 *
	 * @param int $after Last id handled.
	 * @param int $limit Maximum number.
	 * @return int[]
	 */
	public function term_ids( $after, $limit ) {
		$ids = array();
		foreach ( array_keys( $this->term_data() ) as $id ) {
			if ( $id > $after ) {
				$ids[] = $id;
			}
			if ( count( $ids ) >= $limit ) {
				break;
			}
		}
		return $ids;
	}

	/**
	 * Number of terms with data.
	 *
	 * @return int
	 */
	public function term_count() {
		return count( $this->term_data() );
	}

	/**
	 * Records for terms.
	 *
	 * @param int[] $ids Term ids.
	 * @return array<int, array>
	 */
	public function terms( array $ids ) {
		$data    = $this->term_data();
		$records = array();
		foreach ( $ids as $id ) {
			if ( ! isset( $data[ $id ] ) ) {
				continue;
			}
			$values         = $data[ $id ];
			$noindex        = self::dig( $values, array( 'wpseo_noindex' ) );
			$records[ $id ] = self::clean(
				array(
					'title'              => self::dig( $values, array( 'wpseo_title' ) ),
					'description'        => self::dig( $values, array( 'wpseo_desc' ) ),
					'noindex'            => 'noindex' === $noindex ? true : ( 'index' === $noindex ? false : null ),
					'canonical'          => self::dig( $values, array( 'wpseo_canonical' ) ),
					'social_title'       => self::dig( $values, array( 'wpseo_opengraph-title' ) ),
					'social_description' => self::dig( $values, array( 'wpseo_opengraph-description' ) ),
					'social_image'       => self::image( self::dig( $values, array( 'wpseo_opengraph-image-id' ) ), self::dig( $values, array( 'wpseo_opengraph-image' ) ) ),
					'focus'              => self::dig( $values, array( 'wpseo_focuskw' ) ),
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
		$titles = self::option( 'wpseo_titles' );
		$main   = self::option( 'wpseo' );
		$social = self::option( 'wpseo_social' );

		$separators = array(
			'sc-dash'   => '-',
			'sc-ndash'  => '-',
			'sc-mdash'  => '—',
			'sc-pipe'   => '|',
			'sc-middot' => '·',
			'sc-bull'   => '•',
			'sc-raquo'  => '»',
		);
		$separator  = self::dig( $titles, array( 'separator' ) );

		return array(
			'home_title'       => self::dig( $titles, array( 'title-home-wpseo' ) ),
			'home_description' => self::dig( $titles, array( 'metadesc-home-wpseo' ) ),
			'title_separator'  => isset( $separators[ $separator ] ) ? $separators[ $separator ] : '',
			'verify_google'    => self::dig( $main, array( 'googleverify' ) ),
			'verify_bing'      => self::dig( $main, array( 'msverify' ) ),
			'verify_yandex'    => self::dig( $main, array( 'yandexverify' ) ),
			'verify_pinterest' => self::dig( $social, array( 'pinterestverify' ) ),
		);
	}

	/**
	 * Yoast SEO Premium redirects (one option, plain and regex together).
	 *
	 * @return array[]
	 */
	private function redirect_list() {
		$list = self::option( 'wpseo-premium-redirects-base' );
		return array_values(
			array_filter(
				$list,
				static function ( $item ) {
					return is_array( $item ) && isset( $item['origin'] );
				}
			)
		);
	}

	/**
	 * Number of redirects.
	 *
	 * @return int
	 */
	public function redirect_count() {
		return count( $this->redirect_list() );
	}

	/**
	 * Redirects.
	 *
	 * @param int $after Position to continue after.
	 * @param int $limit Maximum number.
	 * @return array{items: array[], next: int}
	 */
	public function redirects( $after, $limit ) {
		$list  = $this->redirect_list();
		$items = array();
		foreach ( array_slice( $list, $after, $limit ) as $item ) {
			$regex   = isset( $item['format'] ) && 'regex' === $item['format'];
			$origin  = (string) $item['origin'];
			$items[] = array(
				'source'     => $regex ? self::regex_with_slash( $origin ) : '/' . ltrim( $origin, '/' ),
				'target'     => isset( $item['url'] ) ? (string) $item['url'] : '',
				'status'     => self::status( isset( $item['type'] ) ? $item['type'] : 301 ),
				'match_type' => $regex ? 'regex' : 'exact',
			);
		}
		$next = $after + $limit;
		return array(
			'items' => $items,
			'next'  => $next < count( $list ) ? $next : 0,
		);
	}
}
