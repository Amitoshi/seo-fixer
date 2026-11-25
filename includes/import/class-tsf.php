<?php
/**
 * Import source: The SEO Framework.
 *
 * @package SeoFixer
 */

namespace Seofx\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Reads The SEO Framework's post meta, term settings and site settings.
 */
final class Tsf extends Source {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'tsf';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name() {
		return 'The SEO Framework';
	}

	/**
	 * Post meta keys.
	 *
	 * @return string[]
	 */
	protected function post_keys() {
		return array(
			'_genesis_title',
			'_genesis_description',
			'_genesis_noindex',
			'_genesis_nofollow',
			'_genesis_canonical_uri',
			'_open_graph_title',
			'_open_graph_description',
			'_social_image_id',
			'_social_image_url',
		);
	}

	/**
	 * Term settings are one array per term.
	 *
	 * @return string[]
	 */
	protected function term_keys() {
		return array( 'autodescription-term-settings' );
	}

	/**
	 * The SEO Framework has no variables in its fields.
	 *
	 * @return array<string, string>
	 */
	protected function variables() {
		return array();
	}

	/**
	 * Nothing to match.
	 *
	 * @return string
	 */
	protected function variable_pattern() {
		return '/(?!)/';
	}

	/**
	 * '1' = noindex, '-1' = always index, other = follow the default.
	 *
	 * @param string $value Stored value.
	 * @return bool|null
	 */
	private static function noindex( $value ) {
		if ( '1' === $value ) {
			return true;
		}
		return '-1' === $value ? false : null;
	}

	/**
	 * The SEO Framework adds the site name to custom titles unless told not to.
	 *
	 * @param string $title       Custom title.
	 * @param bool   $no_sitename Whether "Remove the site title" is ticked.
	 * @return string
	 */
	private static function title( $title, $no_sitename ) {
		return '' === $title || $no_sitename ? $title : $title . ' %sep% %sitename%';
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
			$records[ $id ] = self::clean(
				array(
					'title'              => self::title( self::meta( $id, '_genesis_title' ), '1' === self::meta( $id, '_tsf_title_no_blogname' ) ),
					'description'        => self::meta( $id, '_genesis_description' ),
					'noindex'            => self::noindex( self::meta( $id, '_genesis_noindex' ) ),
					'nofollow'           => '1' === self::meta( $id, '_genesis_nofollow' ) ? true : null,
					'canonical'          => self::meta( $id, '_genesis_canonical_uri' ),
					'social_title'       => self::meta( $id, '_open_graph_title' ),
					'social_description' => self::meta( $id, '_open_graph_description' ),
					'social_image'       => self::image( self::meta( $id, '_social_image_id' ), self::meta( $id, '_social_image_url' ) ),
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
			$data = get_term_meta( $id, 'autodescription-term-settings', true );
			$data = is_array( $data ) ? $data : array();

			$records[ $id ] = self::clean(
				array(
					'title'              => self::title( self::dig( $data, array( 'doctitle' ) ), '1' === self::dig( $data, array( 'title_no_blog_name' ) ) ),
					'description'        => self::dig( $data, array( 'description' ) ),
					'noindex'            => self::noindex( self::dig( $data, array( 'noindex' ) ) ),
					'nofollow'           => '1' === self::dig( $data, array( 'nofollow' ) ) ? true : null,
					'canonical'          => self::dig( $data, array( 'canonical' ) ),
					'social_title'       => self::dig( $data, array( 'og_title' ) ),
					'social_description' => self::dig( $data, array( 'og_description' ) ),
					'social_image'       => self::image( self::dig( $data, array( 'social_image_id' ) ), self::dig( $data, array( 'social_image_url' ) ) ),
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
		$options    = self::option( 'autodescription-site-settings' );
		$separators = array(
			'hyphen' => '-',
			'dash'   => '-',
			'ndash'  => '-',
			'mdash'  => '—',
			'pipe'   => '|',
			'middot' => '·',
			'bull'   => '•',
			'raquo'  => '»',
		);
		$separator  = self::dig( $options, array( 'title_separator' ) );

		return array(
			'home_title'       => self::dig( $options, array( 'homepage_title' ) ),
			'home_description' => self::dig( $options, array( 'homepage_description' ) ),
			'title_separator'  => isset( $separators[ $separator ] ) ? $separators[ $separator ] : '',
			'verify_google'    => self::dig( $options, array( 'google_verification' ) ),
			'verify_bing'      => self::dig( $options, array( 'bing_verification' ) ),
			'verify_yandex'    => self::dig( $options, array( 'yandex_verification' ) ),
			'verify_pinterest' => self::dig( $options, array( 'pint_verification' ) ),
		);
	}
}
