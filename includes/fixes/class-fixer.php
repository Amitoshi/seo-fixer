<?php
/**
 * One-click fixes: suggestions per page, applying them (one at a time,
 * selected, or all in batches) and keeping every change in the history.
 *
 * Golden rule: anything a person wrote is never overwritten. Descriptions
 * and titles are only filled where the page has none of its own; alt text
 * is only added to images that have none.
 *
 * @package SeoFixer
 */

namespace Seofx\Fixes;

use Seofx\Meta\Context;
use Seofx\Meta\Post_Meta;
use Seofx\Meta\Resolver;
use Seofx\Meta\Variables;
use Seofx\Sanitize;
use Seofx\Scanner\Results;
use Seofx\Scanner\Scanner;
use Seofx\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Fix engine.
 */
final class Fixer {

	/**
	 * Health issue → the fix that solves it.
	 */
	const FIXES = array(
		'desc_missing'   => 'description',
		'desc_too_short' => 'description',
		'title_too_long' => 'title',
		'img_no_alt'     => 'alt',
	);

	/**
	 * Pages handled per "Fix all" request.
	 */
	const CHUNK = 20;

	/**
	 * True while SEO Fixer itself is saving content (so automatic fixes don't run again).
	 *
	 * @var bool
	 */
	public static $writing = false;

	/**
	 * The fix for a health issue.
	 *
	 * @param string $issue Issue id.
	 * @return string description|title|alt, or '' when it can't be fixed automatically.
	 */
	public static function fix_for( $issue ) {
		return isset( self::FIXES[ $issue ] ) ? self::FIXES[ $issue ] : '';
	}

	/**
	 * Post meta key for a text field.
	 *
	 * @param string $field description|title.
	 * @return string
	 */
	public static function meta_key( $field ) {
		return 'title' === $field ? Post_Meta::TITLE : Post_Meta::DESCRIPTION;
	}

	/**
	 * Whether a page already has its own title or description.
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   description|title.
	 * @return bool
	 */
	public static function has_own( $post_id, $field ) {
		return '' !== trim( (string) get_post_meta( $post_id, self::meta_key( $field ), true ) );
	}

	/**
	 * Suggestions for one page.
	 *
	 * @param string   $fix  description|title|alt.
	 * @param \WP_Post $post Post.
	 * @return array[] Each: post_id, field, value, current, and for images index, src, attachment_id, from.
	 *                 A text suggestion with value '' means "write it yourself".
	 */
	public static function suggestions( $fix, \WP_Post $post ) {
		if ( 'alt' === $fix ) {
			$items = array();
			foreach ( Generator::images_without_alt( $post ) as $image ) {
				$items[] = array_merge(
					array(
						'post_id' => $post->ID,
						'field'   => 'alt',
						'current' => '',
						'value'   => $image['alt'],
					),
					$image
				);
			}
			return $items;
		}

		if ( self::has_own( $post->ID, $fix ) ) {
			return array(); // Written by a person: never replaced.
		}
		$context = Context::for_post( $post );
		return array(
			array(
				'post_id' => $post->ID,
				'field'   => $fix,
				'current' => 'title' === $fix ? Resolver::title( $context ) : Resolver::description( $context ),
				'value'   => 'title' === $fix ? Generator::title( $post ) : Generator::description( $post ),
			),
		);
	}

	/**
	 * What a stored title template looks like on the page ("%title%" → "About us").
	 *
	 * @param string   $value Value.
	 * @param \WP_Post $post  Post.
	 * @return string
	 */
	public static function preview( $value, \WP_Post $post ) {
		return false === strpos( $value, '%' ) ? $value : Variables::replace( $value, Context::for_post( $post ) );
	}

	/**
	 * Applies changes and records them.
	 *
	 * @param array[] $items  Items like those from suggestions() (value may be edited).
	 * @param string  $batch  Batch id.
	 * @param string  $source manual|auto.
	 * @return array{changed: int, posts: int[]}
	 */
	public static function apply( array $items, $batch, $source = 'manual' ) {
		$by_post = array();
		foreach ( $items as $item ) {
			$by_post[ (int) $item['post_id'] ][] = $item;
		}

		$changed = 0;
		$posts   = array();
		foreach ( $by_post as $post_id => $post_items ) {
			$post = get_post( $post_id );
			if ( ! $post || ( 'auto' !== $source && ! current_user_can( 'edit_post', $post_id ) ) ) {
				continue;
			}

			$content = (string) $post->post_content;
			foreach ( $post_items as $item ) {
				$field = (string) $item['field'];

				if ( 'description' === $field || 'title' === $field ) {
					$value = Sanitize::template( $item['value'] );
					if ( '' === $value || self::has_own( $post_id, $field ) || ! History::record( $batch, $post_id, $field, '', $value, array(), $source ) ) {
						continue;
					}
					update_post_meta( $post_id, self::meta_key( $field ), wp_slash( $value ) );
					++$changed;
					$posts[] = $post_id;
					continue;
				}

				if ( 'alt' === $field ) {
					$value = Text::squish( sanitize_text_field( (string) $item['value'] ) );
					if ( '' === $value ) {
						continue;
					}
					$src = (string) $item['src'];
					$set = Html::set_alt( $content, (int) $item['index'], $src, $value );
					if ( null === $set ) {
						continue; // The image moved or got alt text in the meantime.
					}
					$recorded = History::record(
						$batch,
						$post_id,
						'alt',
						'',
						$value,
						array(
							'src'        => $src,
							'had_attr'   => $set['had_attr'],
							'tag_before' => $set['tag_before'],
							'tag_after'  => $set['tag_after'],
						),
						$source
					);
					if ( ! $recorded ) {
						continue;
					}
					$content = $set['content'];
					++$changed;

					// Also store it in the Media Library, so the image has alt text wherever it is used next.
					$attachment_id = (int) ( $item['attachment_id'] ?? 0 );
					if ( $attachment_id && 'attachment' === get_post_type( $attachment_id ) && current_user_can( 'edit_post', $attachment_id ) && '' === trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) )
						&& History::record( $batch, $attachment_id, 'library_alt', '', $value, array(), $source ) ) {
						update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( $value ) );
					}
				}
			}

			if ( $content !== $post->post_content ) {
				self::update_content( $post, $content );
				$posts[] = $post_id;
			}
		}

		$posts = array_values( array_unique( $posts ) );
		if ( $posts ) {
			Scanner::rescan( $posts );
		}
		return array(
			'changed' => $changed,
			'posts'   => $posts,
		);
	}

	/**
	 * Saves new post content.
	 *
	 * Only alt attributes were changed (through the HTML Tag Processor, which
	 * escapes them), so content filtering is paused for this save: running the
	 * page's existing content through it again could strip embeds that the
	 * author was allowed to add.
	 *
	 * @param \WP_Post $post    Post.
	 * @param string   $content New content.
	 * @return void
	 */
	public static function update_content( \WP_Post $post, $content ) {
		$filtered = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $filtered ) {
			kses_remove_filters();
		}
		self::$writing = true;
		wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => wp_slash( $content ),
			)
		);
		self::$writing = false;
		if ( $filtered ) {
			kses_init_filters();
		}
	}

	/**
	 * "Fix all": the next pages with an issue, fixed with the suggestions as they are.
	 *
	 * @param string $issue  Issue id.
	 * @param int    $after  Last post id handled in a previous request.
	 * @param string $batch  Batch id (shared by every request of one "Fix all").
	 * @return array{after: int, more: bool, changed: int}
	 */
	public static function fix_next( $issue, $after, $batch ) {
		$fix = self::fix_for( $issue );
		$ids = '' === $fix ? array() : Results::ids_with_issue( $issue, (int) $after, self::CHUNK );

		$items = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			foreach ( self::suggestions( $fix, $post ) as $item ) {
				if ( '' !== $item['value'] ) {
					$items[] = $item;
				}
			}
		}

		$result = $items ? self::apply( $items, $batch ) : array( 'changed' => 0 );
		return array(
			'after'   => $ids ? (int) end( $ids ) : (int) $after,
			'more'    => count( $ids ) === self::CHUNK,
			'changed' => (int) $result['changed'],
		);
	}
}
