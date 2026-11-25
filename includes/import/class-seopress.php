<?php
/**
 * Import source: SEOPress.
 *
 * @package SeoFixer
 */

namespace Seofx\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Reads SEOPress's post and term meta and its settings.
 */
final class Seopress extends Source {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'seopress';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'SEOPress';
	}

	/**
	 * Post meta keys (the same keys are used for terms).
	 *
	 * @return string[]
	 */
	protected function post_keys() {
		return array(
			'_seopress_titles_title',
			'_seopress_titles_desc',
			'_seopress_robots_index',
			'_seopress_robots_follow',
			'_seopress_robots_canonical',
			'_seopress_social_fb_title',
			'_seopress_social_fb_desc',
			'_seopress_social_fb_img_attachment_id',
			'_seopress_social_fb_img',
			'_seopress_analysis_target_kw',
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
	 * Variables (SEOPress drops any %%variable%% it doesn't know, and so do we).
	 *
	 * @return array<string, string>
	 */
	protected function variables() {
		return array(
			'post_title'           => 'title',
			'sitetitle'            => 'sitename',
			'sitename'             => 'sitename',
			'tagline'              => 'tagline',
			'sitedesc'             => 'tagline',
			'sep'                  => 'sep',
			'post_excerpt'         => 'excerpt',
			'excerpt'              => 'excerpt',
			'post_category'        => 'category',
			'post_category_name'   => 'category',
			'_category_title'      => 'term',
			'term_title'           => 'term',
			'tag_title'            => 'term',
			'_category_description' => 'term_description',
			'term_description'     => 'term_description',
			'tag_description'      => 'term_description',
			'post_author'          => 'author',
			'author_bio'           => 'author_bio',
			'post_date'            => 'date',
			'archive_title'        => 'archive_title',
			'cpt_plural'           => 'archive_title',
			'search_keywords'      => 'search',
			'current_pagination'   => 'page',
			'page'                 => 'page',
			'currentyear'          => 'year',
		);
	}

	/**
	 * Record from a meta reader.
	 *
	 * @param callable $get Reads one key.
	 * @return array
	 */
	private static function record( $get ) {
		return self::clean(
			array(
				'title'              => $get( '_seopress_titles_title' ),
				'description'        => $get( '_seopress_titles_desc' ),
				'noindex'            => 'yes' === $get( '_seopress_robots_index' ) ? true : null,
				'nofollow'           => 'yes' === $get( '_seopress_robots_follow' ) ? true : null,
				'canonical'          => $get( '_seopress_robots_canonical' ),
				'social_title'       => $get( '_seopress_social_fb_title' ),
				'social_description' => $get( '_seopress_social_fb_desc' ),
				'social_image'       => self::image( $get( '_seopress_social_fb_img_attachment_id' ), $get( '_seopress_social_fb_img' ) ),
				'focus'              => $get( '_seopress_analysis_target_kw' ),
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
		update_meta_cache( 'post', $ids );
		$records = array();
		foreach ( $ids as $id ) {
			$records[ $id ] = self::record(
				static function ( $key ) use ( $id ) {
					return self::meta( $id, $key );
				}
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
			$records[ $id ] = self::record(
				static function ( $key ) use ( $id ) {
					return self::term_meta( $id, $key );
				}
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
		$titles   = self::option( 'seopress_titles_option_name' );
		$advanced = self::option( 'seopress_advanced_option_name' );

		$separator = html_entity_decode( self::dig( $titles, array( 'seopress_titles_sep' ) ), ENT_QUOTES, 'UTF-8' );
		$separator = in_array( $separator, array( '–', '-' ), true ) ? '-' : $separator;

		return array(
			'home_title'       => self::dig( $titles, array( 'seopress_titles_home_site_title' ) ),
			'home_description' => self::dig( $titles, array( 'seopress_titles_home_site_desc' ) ),
			'title_separator'  => in_array( $separator, array( '-', '—', '|', '·', '•', '/', '»' ), true ) ? $separator : '',
			'verify_google'    => self::dig( $advanced, array( 'seopress_advanced_advanced_google' ) ),
			'verify_bing'      => self::dig( $advanced, array( 'seopress_advanced_advanced_bing' ) ),
			'verify_yandex'    => self::dig( $advanced, array( 'seopress_advanced_advanced_yandex' ) ),
			'verify_pinterest' => self::dig( $advanced, array( 'seopress_advanced_advanced_pinterest' ) ),
		);
	}
}
