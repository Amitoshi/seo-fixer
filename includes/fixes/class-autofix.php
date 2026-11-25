<?php
/**
 * Automatic fixes for pages as they are published or updated (each kind is
 * off until the owner switches it on).
 *
 * Runs at the end of the request, after the editor has saved its own SEO
 * fields, so a description or title someone just typed is never replaced.
 *
 * @package SeoFixer
 */

namespace Seofx\Fixes;

use Seofx\Meta\Context;
use Seofx\Meta\Resolver;
use Seofx\Options;
use Seofx\Scanner\Scanner;
use Seofx\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Automatic fixes.
 */
final class Autofix {

	/**
	 * Setting that switches each fix on.
	 */
	const OPTIONS = array(
		'description' => 'autofix_description',
		'title'       => 'autofix_title',
		'alt'         => 'autofix_alt',
	);

	/**
	 * Pages saved in this request.
	 *
	 * @var array<int, true>
	 */
	private $queue = array();

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! array_filter( array_map( array( Options::class, 'get' ), self::OPTIONS ) ) ) {
			return; // Nothing switched on.
		}
		add_action( 'save_post', array( $this, 'queue' ), 30, 2 );
		// Before the scanner's rescan (priority 10), so the new score includes the fix.
		add_action( 'shutdown', array( $this, 'process' ), 5 );
	}

	/**
	 * Whether a fix is switched on.
	 *
	 * @param string $fix description|title|alt.
	 * @return bool
	 */
	public static function enabled( $fix ) {
		return isset( self::OPTIONS[ $fix ] ) && (bool) Options::get( self::OPTIONS[ $fix ] );
	}

	/**
	 * Remembers a saved page.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public function queue( $post_id, $post ) {
		if ( Fixer::$writing || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}
		$this->queue[ (int) $post_id ] = true;
	}

	/**
	 * Fixes the saved pages.
	 *
	 * @return void
	 */
	public function process() {
		if ( array() === $this->queue || count( $this->queue ) > Scanner::BATCH ) {
			return; // Bulk imports are left to "Fix all", where the owner sees what happens.
		}
		$types = Scanner::post_types();
		foreach ( array_keys( $this->queue ) as $post_id ) {
			$post = get_post( $post_id );
			if ( $post && 'publish' === $post->post_status && in_array( $post->post_type, $types, true ) ) {
				self::fix_post( $post );
			}
		}
		$this->queue = array();
	}

	/**
	 * Applies every switched-on fix that a page needs.
	 *
	 * @param \WP_Post $post Post.
	 * @return int Changes made.
	 */
	public static function fix_post( \WP_Post $post ) {
		$context = Context::for_post( $post );
		$items   = array();

		if ( self::enabled( 'description' ) && mb_strlen( Resolver::description( $context ) ) < Text::DESCRIPTION_MIN ) {
			$items = array_merge( $items, Fixer::suggestions( 'description', $post ) );
		}
		if ( self::enabled( 'title' ) && mb_strlen( Resolver::title( $context ) ) > Generator::TITLE_MAX ) {
			$items = array_merge( $items, Fixer::suggestions( 'title', $post ) );
		}
		if ( self::enabled( 'alt' ) ) {
			$items = array_merge( $items, Fixer::suggestions( 'alt', $post ) );
		}

		$items = array_filter(
			$items,
			static function ( $item ) {
				return '' !== $item['value'];
			}
		);
		if ( array() === $items ) {
			return 0;
		}
		$result = Fixer::apply( array_values( $items ), History::new_batch(), 'auto' );
		return $result['changed'];
	}
}
