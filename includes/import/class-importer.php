<?php
/**
 * Imports SEO data from another plugin, in small steps.
 *
 * Two modes share one engine: "check" reads everything and counts what would
 * happen (nothing is written), "import" writes. Rules:
 * - a value already set in SEO Fixer is never replaced;
 * - values that match what SEO Fixer would do anyway are skipped;
 * - every value written is recorded in the change history first, so the
 *   whole import can be undone from SEO Health → Changes;
 * - the other plugin's data is only read, never changed.
 *
 * @package SeoFixer
 */

namespace Seofx\Import;

use Seofx\Fixes\History;
use Seofx\Indexing\Indexing;
use Seofx\Indexing\Sitemaps;
use Seofx\Lock;
use Seofx\Meta\Post_Meta;
use Seofx\Meta\Templates;
use Seofx\Meta\Variables;
use Seofx\Options;
use Seofx\Redirects\Store;
use Seofx\Sanitize;
use Seofx\Scanner\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Import engine.
 */
final class Importer {

	/**
	 * Option holding the current or last job.
	 */
	const STATE = 'seofx_import';

	/**
	 * Transient that stops two requests working on the job at once.
	 */
	const LOCK = 'seofx_import_lock';

	/**
	 * Posts or terms read per query.
	 */
	const BATCH = 100;

	/**
	 * Number of examples kept for the check report.
	 */
	const SAMPLES = 8;

	/**
	 * Record field => SEO Fixer meta key.
	 */
	const META = array(
		'title'              => Post_Meta::TITLE,
		'description'        => Post_Meta::DESCRIPTION,
		'noindex'            => Post_Meta::NOINDEX,
		'nofollow'           => Post_Meta::NOFOLLOW,
		'canonical'          => Post_Meta::CANONICAL,
		'social_title'       => Post_Meta::SOCIAL_TITLE,
		'social_description' => Post_Meta::SOCIAL_DESCRIPTION,
		'social_image'       => Post_Meta::SOCIAL_IMAGE,
	);

	/**
	 * Groups the user can choose, with their fields.
	 */
	const GROUPS = array(
		'text'       => array( 'title', 'description' ),
		'visibility' => array( 'noindex', 'nofollow', 'canonical' ),
		'social'     => array( 'social_title', 'social_description', 'social_image' ),
		'site'       => array( 'home_title', 'home_description', 'title_separator', 'verify_google', 'verify_bing', 'verify_yandex', 'verify_pinterest' ),
		'redirects'  => array( 'redirect' ),
	);

	/**
	 * Every source, keyed by id.
	 *
	 * @return array<string, Source>
	 */
	public static function sources() {
		$sources = array();
		foreach ( array( new Yoast(), new Rank_Math(), new Aioseo(), new Seopress(), new Tsf() ) as $source ) {
			$sources[ $source->id() ] = $source;
		}
		return $sources;
	}

	/**
	 * One source.
	 *
	 * @param string $id Id.
	 * @return Source|null
	 */
	public static function source( $id ) {
		$sources = self::sources();
		return isset( $sources[ $id ] ) ? $sources[ $id ] : null;
	}

	/**
	 * Current or last job.
	 *
	 * @return array|null
	 */
	public static function state() {
		$state = get_option( self::STATE );
		return is_array( $state ) && isset( $state['source'], $state['phase'] ) && self::source( $state['source'] ) ? $state : null;
	}

	/**
	 * Whether a job is still running.
	 *
	 * @return bool
	 */
	public static function running() {
		$state = self::state();
		return $state && 'done' !== $state['phase'];
	}

	/**
	 * Forgets the job (its changes stay, and stay undoable).
	 *
	 * @return void
	 */
	public static function reset() {
		delete_option( self::STATE );
		Lock::release( self::LOCK );
	}

	/**
	 * Starts a check or an import, replacing any earlier job.
	 *
	 * @param string   $source_id Source id.
	 * @param string   $mode      check|import.
	 * @param string[] $groups    Groups to import (ignored for a check, which looks at everything).
	 * @return bool
	 */
	public static function start( $source_id, $mode, array $groups = array() ) {
		$source = self::source( $source_id );
		if ( ! $source ) {
			return false;
		}
		$mode   = 'import' === $mode ? 'import' : 'check';
		$groups = 'check' === $mode ? array_keys( self::GROUPS ) : array_values( array_intersect( array_keys( self::GROUPS ), $groups ) );
		if ( array() === $groups ) {
			return false;
		}

		$wants_objects = array() !== array_intersect( $groups, array( 'text', 'visibility', 'social' ) );
		update_option(
			self::STATE,
			array(
				'source'     => $source_id,
				'mode'       => $mode,
				'groups'     => $groups,
				'phase'      => $wants_objects ? 'posts' : 'site',
				'cursor'     => 0,
				'totals'     => array(
					'posts'     => $wants_objects ? $source->post_count() : 0,
					'terms'     => $wants_objects ? $source->term_count() : 0,
					'redirects' => in_array( 'redirects', $groups, true ) ? $source->redirect_count() : 0,
				),
				'done'       => array(
					'posts'     => 0,
					'terms'     => 0,
					'redirects' => 0,
				),
				'counts'     => array(),
				'changed'    => array(
					'posts' => 0,
					'terms' => 0,
				),
				'simplified' => 0,
				'focus'      => 0,
				'samples'    => array(),
				'batch'      => 'import' === $mode ? History::new_batch() : '',
				'started'    => time(),
				'finished'   => 0,
				'rescan'     => false,
			),
			false
		);
		Lock::release( self::LOCK );
		return true;
	}

	/**
	 * Works on the job for up to $seconds.
	 *
	 * @param int $seconds Time budget.
	 * @return bool False when another request is already working on it.
	 */
	public static function run( $seconds ) {
		$state = self::state();
		if ( ! $state || 'done' === $state['phase'] ) {
			return true;
		}
		if ( ! Lock::acquire( self::LOCK, MINUTE_IN_SECONDS ) ) {
			return false;
		}

		global $wpdb;
		$source = self::source( $state['source'] );
		$until  = microtime( true ) + $seconds;
		while ( 'done' !== $state['phase'] && microtime( true ) < $until ) {
			// One transaction per step: values, their history rows and the new position are saved
			// together (or not at all if the request dies), and the database commits once, not per value.
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control, no data.
			$state = self::step( $source, $state, $until );
			update_option( self::STATE, $state, false );
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control, no data.
		}

		Lock::release( self::LOCK );
		return true;
	}

	/**
	 * Progress for the screen: percent and a plain-English line.
	 *
	 * @param array $state Job.
	 * @return array{percent: int, done: bool, label: string}
	 */
	public static function progress( array $state ) {
		$total = array_sum( $state['totals'] );
		$done  = array_sum( $state['done'] );
		$over  = 'done' === $state['phase'];
		return array(
			'percent' => $over ? 100 : (int) min( 99, $total > 0 ? floor( 100 * $done / $total ) : 50 ),
			'done'    => $over,
			'label'   => sprintf(
				/* translators: 1: number of items handled so far, 2: total number of items. */
				'check' === $state['mode'] ? __( 'Checking… %1$s of %2$s', 'seo-fixer' ) : __( 'Importing… %1$s of %2$s', 'seo-fixer' ),
				number_format_i18n( min( $done, $total ) ),
				number_format_i18n( $total )
			),
		);
	}

	/**
	 * One unit of work.
	 *
	 * @param Source $source Source.
	 * @param array  $state  Job.
	 * @param float  $until  Deadline (microtime).
	 * @return array New state.
	 */
	private static function step( Source $source, array $state, $until ) {
		switch ( $state['phase'] ) {
			case 'posts':
				$ids = $source->post_ids( (int) $state['cursor'], self::BATCH );
				if ( array() === $ids ) {
					return self::next_phase( $state, 'terms' );
				}
				_prime_post_caches( $ids, false, false );
				$records = $source->posts( $ids );
				foreach ( $ids as $id ) {
					$post = get_post( $id );
					if ( $post && isset( $records[ $id ] ) ) {
						$state = self::object( $source, $state, $post, $records[ $id ] );
					}
					$state['cursor'] = $id;
					++$state['done']['posts'];
					if ( microtime( true ) >= $until ) {
						break;
					}
				}
				return $state;

			case 'terms':
				$ids = $source->term_ids( (int) $state['cursor'], self::BATCH );
				if ( array() === $ids ) {
					return self::next_phase( $state, 'site' );
				}
				$records    = $source->terms( $ids );
				$taxonomies = array_keys( Templates::taxonomies() );
				foreach ( $ids as $id ) {
					$term = get_term( $id );
					if ( $term instanceof \WP_Term && in_array( $term->taxonomy, $taxonomies, true ) && isset( $records[ $id ] ) ) {
						$state = self::object( $source, $state, $term, $records[ $id ] );
					}
					$state['cursor'] = $id;
					++$state['done']['terms'];
					if ( microtime( true ) >= $until ) {
						break;
					}
				}
				return $state;

			case 'site':
				if ( in_array( 'site', $state['groups'], true ) ) {
					$state = self::site( $source, $state );
				}
				return self::next_phase( $state, 'redirects' );

			case 'redirects':
				if ( ! in_array( 'redirects', $state['groups'], true ) ) {
					return self::finish( $state );
				}
				$page = $source->redirects( (int) $state['cursor'], self::BATCH );
				foreach ( $page['items'] as $item ) {
					$state = self::redirect( $source, $state, $item );
				}
				$state['done']['redirects'] = min( $state['totals']['redirects'], $state['done']['redirects'] + count( $page['items'] ) );
				if ( 0 === (int) $page['next'] ) {
					return self::finish( $state );
				}
				$state['cursor'] = (int) $page['next'];
				return $state;
		}
		return self::finish( $state );
	}

	/**
	 * Moves to another phase.
	 *
	 * @param array  $state Job.
	 * @param string $phase Phase.
	 * @return array
	 */
	private static function next_phase( array $state, $phase ) {
		$state['phase']  = $phase;
		$state['cursor'] = 0;
		return $state;
	}

	/**
	 * Wraps up: refreshes sitemaps and the health results after an import.
	 *
	 * @param array $state Job.
	 * @return array
	 */
	private static function finish( array $state ) {
		$state['phase']    = 'done';
		$state['finished'] = time();
		if ( 'import' === $state['mode'] && self::written( $state ) > 0 ) {
			Sitemaps::bump_version();
			if ( $state['changed']['posts'] > 0 && ( Scanner::summary() || Scanner::state() ) ) {
				Scanner::start(); // One background scan beats rescanning page by page; a scan already running may have read pages before the import, so it starts over.
				$state['rescan'] = true;
			}
		}
		return $state;
	}

	/**
	 * Number of values written (or, for a check, that would be written).
	 *
	 * @param array    $state  Job.
	 * @param string[] $fields Limit to these fields (default all).
	 * @return int
	 */
	public static function written( array $state, array $fields = array() ) {
		$total = 0;
		foreach ( $state['counts'] as $field => $outcomes ) {
			if ( array() === $fields || in_array( $field, $fields, true ) ) {
				$total += isset( $outcomes['new'] ) ? (int) $outcomes['new'] : 0;
			}
		}
		return $total;
	}

	/**
	 * Counts an outcome: new, kept (already set in SEO Fixer), same (matches SEO Fixer's own default) or bad (can't be used).
	 *
	 * @param array  $state   Job.
	 * @param string $field   Field.
	 * @param string $outcome Outcome.
	 * @return array
	 */
	private static function count( array $state, $field, $outcome ) {
		if ( ! isset( $state['counts'][ $field ][ $outcome ] ) ) {
			$state['counts'][ $field ][ $outcome ] = 0;
		}
		++$state['counts'][ $field ][ $outcome ];
		return $state;
	}

	/**
	 * Group a field belongs to.
	 *
	 * @param string $field Field.
	 * @return string
	 */
	public static function group_of( $field ) {
		foreach ( self::GROUPS as $group => $fields ) {
			if ( in_array( $field, $fields, true ) ) {
				return $group;
			}
		}
		return '';
	}

	/**
	 * Imports (or checks) one post or term.
	 *
	 * @param Source            $source Source.
	 * @param array             $state  Job.
	 * @param \WP_Post|\WP_Term $object Post or term.
	 * @param array             $record Record from the source.
	 * @return array
	 */
	private static function object( Source $source, array $state, $object, array $record ) {
		$is_post = $object instanceof \WP_Post;
		$type    = $is_post ? 'post' : 'term';
		$id      = $is_post ? $object->ID : $object->term_id;
		$write   = 'import' === $state['mode'];
		$changed = false;

		// Read once: every write clears the object's meta cache, so asking per field would reload it each time.
		$existing = (array) get_metadata( $type, $id );

		if ( isset( $record['focus'] ) ) {
			++$state['focus'];
		}

		foreach ( self::META as $field => $key ) {
			if ( ! isset( $record[ $field ] ) || ! in_array( self::group_of( $field ), $state['groups'], true ) ) {
				continue;
			}
			$result = self::evaluate( $source, $field, $record[ $field ], $object );
			if ( null === $result ) {
				continue; // Empty after conversion: nothing to report.
			}
			if ( 'new' === $result['outcome'] && isset( $existing[ $key ][0] ) && '' !== (string) $existing[ $key ][0] ) {
				$result['outcome'] = 'kept';
			}

			if ( 'new' === $result['outcome'] && $write ) {
				if ( ! History::record( $state['batch'], $id, 'import_' . $type, '', $result['value'], self::detail( $source, $key ) ) ) {
					continue; // No record, no change: everything must be undoable.
				}
				if ( array_key_exists( $key, $existing ) ) {
					update_metadata( $type, $id, $key, wp_slash( $result['value'] ) ); // An empty value is stored: replace it.
				} else {
					add_metadata( $type, $id, $key, wp_slash( $result['value'] ) ); // Nothing stored: one insert, no lookup.
				}
				$changed = true;
			}

			$state = self::count( $state, $field, $result['outcome'] );
			if ( 'new' === $result['outcome'] && ! empty( $result['simplified'] ) ) {
				++$state['simplified'];
			}
			if ( ! $write && 'new' === $result['outcome'] && in_array( $field, array( 'title', 'description' ), true ) && count( $state['samples'] ) < self::SAMPLES ) {
				$state['samples'][] = array(
					'type'  => $type,
					'id'    => $id,
					'field' => $field,
					'from'  => mb_substr( (string) $record[ $field ], 0, 300 ),
					'to'    => $result['value'],
				);
			}
		}

		if ( $changed ) {
			++$state['changed'][ $type . 's' ];
		}
		return $state;
	}

	/**
	 * History detail (enough to undo, nothing more).
	 *
	 * @param Source $source Source.
	 * @param string $key    Meta key or setting.
	 * @return array
	 */
	private static function detail( Source $source, $key ) {
		return array(
			's' => $source->id(),
			'k' => $key,
		);
	}

	/**
	 * Works out what one value becomes.
	 *
	 * @param Source            $source Source.
	 * @param string            $field  Field.
	 * @param mixed             $raw    Value from the source.
	 * @param \WP_Post|\WP_Term $object Post or term.
	 * @return array{outcome: string, value: string, simplified?: bool}|null
	 */
	private static function evaluate( Source $source, $field, $raw, $object ) {
		$is_post = $object instanceof \WP_Post;

		switch ( $field ) {
			case 'title':
			case 'description':
			case 'social_title':
			case 'social_description':
				$converted = $source->convert( (string) $raw );
				$value     = Sanitize::template( $converted['text'] );
				if ( '' === $value ) {
					return null;
				}
				$outcome = 'new';
				if ( 'title' === $field || 'description' === $field ) {
					$prefix  = 'title' === $field ? 'title_tpl_' : 'desc_tpl_';
					$key     = $is_post ? $prefix . $object->post_type : $prefix . 'tax_' . $object->taxonomy;
					$outcome = self::same_template( $value, Templates::get( $key ) ) ? 'same' : 'new';
				}
				return array(
					'outcome'    => $outcome,
					'value'      => $value,
					'simplified' => $converted['simplified'],
				);

			case 'noindex':
				$type_shown = $is_post ? Indexing::type_indexed( $object->post_type ) : Indexing::taxonomy_indexed( $object->taxonomy );
				// Hidden while its type is shown, or shown while its type is hidden; anything else is the default already.
				if ( (bool) $raw === $type_shown ) {
					return array(
						'outcome' => 'new',
						'value'   => $raw ? '1' : '0',
					);
				}
				return array(
					'outcome' => 'same',
					'value'   => '',
				);

			case 'nofollow':
				return $raw ? array(
					'outcome' => 'new',
					'value'   => '1',
				) : null;

			case 'canonical':
				$value = Sanitize::web_url( (string) $raw );
				if ( '' === $value ) {
					return array(
						'outcome' => 'bad',
						'value'   => '',
					);
				}
				$own = $is_post ? get_permalink( $object ) : get_term_link( $object );
				return array(
					'outcome' => is_string( $own ) && untrailingslashit( $own ) === untrailingslashit( $value ) ? 'same' : 'new',
					'value'   => $value,
				);

			case 'social_image':
				$id    = is_int( $raw ) ? $raw : attachment_url_to_postid( (string) $raw );
				$value = Post_Meta::sanitize_image( $id );
				return array(
					'outcome' => '' === $value ? 'bad' : 'new',
					'value'   => $value,
				);
		}
		return null;
	}

	/**
	 * Whether a title or description template does the same as another
	 * ("%title% %sep% %sitename%" and "%title% %sep% %page% %sep% %sitename%"
	 * are the same: SEO Fixer adds the page number on later pages anyway).
	 *
	 * @param string $value    Imported template.
	 * @param string $template SEO Fixer's template.
	 * @return bool
	 */
	private static function same_template( $value, $template ) {
		$normalize = static function ( $text ) {
			return strtolower( Variables::cleanup( str_replace( '%page%', '', $text ), '%sep%' ) );
		};
		return $normalize( $value ) === $normalize( $template );
	}

	/**
	 * Site settings: homepage title and description, separator, verification codes.
	 *
	 * @param Source $source Source.
	 * @param array  $state  Job.
	 * @return array
	 */
	private static function site( Source $source, array $state ) {
		$stored = get_option( Options::KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$write  = 'import' === $state['mode'];

		foreach ( $source->settings() as $key => $raw ) {
			if ( '' === $raw || ! in_array( $key, self::GROUPS['site'], true ) ) {
				continue;
			}
			$current = isset( $stored[ $key ] ) && is_scalar( $stored[ $key ] ) ? (string) $stored[ $key ] : '';

			if ( 'home_title' === $key || 'home_description' === $key ) {
				if ( 'posts' !== get_option( 'show_on_front' ) ) {
					continue; // A static homepage uses that page's own title and description, imported with the pages.
				}
				$converted = $source->convert( $raw );
				$value     = Sanitize::template( $converted['text'] );
				$outcome   = self::same_template( $value, Templates::default_for( $key ) ) ? 'same' : 'new';
			} elseif ( 'title_separator' === $key ) {
				$value   = $raw;
				$current = array_key_exists( $key, $stored ) ? $current : ''; // Never chosen in SEO Fixer: the default may be replaced.
				$outcome = ( '' === $current && '-' === $value ) ? 'same' : 'new';
			} else {
				$value   = Sanitize::verification( $raw );
				$outcome = '' === $value ? 'bad' : 'new';
			}

			if ( 'bad' === $outcome ) {
				$state = self::count( $state, $key, $outcome );
				continue;
			}
			if ( '' === $value ) {
				continue;
			}
			if ( 'new' === $outcome && '' !== $current ) {
				$outcome = $current === $value ? 'same' : 'kept';
			}

			if ( 'new' === $outcome && $write ) {
				$detail = self::detail( $source, $key );
				$before = (string) Options::get( $key );
				if ( ! History::record( $state['batch'], 0, 'import_site', $before, $value, $detail ) ) {
					continue;
				}
				Options::update( array( $key => $value ) );
			}
			$state = self::count( $state, $key, $outcome );
		}
		return $state;
	}

	/**
	 * Imports (or checks) one redirect.
	 *
	 * @param Source $source Source.
	 * @param array  $state  Job.
	 * @param array  $item   source, target, status, match_type.
	 * @return array
	 */
	private static function redirect( Source $source, array $state, array $item ) {
		$checked = Store::validate( Sanitize::address( $item['source'] ), Sanitize::address( $item['target'] ), (int) $item['status'], $item['match_type'] );
		if ( '' !== $checked['error'] ) {
			return self::count( $state, 'redirect', 'bad' );
		}
		$data = $checked['data'];
		if ( Store::find_source( $data['source'], $data['match_type'] ) ) {
			return self::count( $state, 'redirect', 'kept' );
		}
		if ( 'import' === $state['mode'] ) {
			$id = Store::save( $data + array( 'origin' => 'import' ) );
			if ( ! $id ) {
				return self::count( $state, 'redirect', 'bad' );
			}
			if ( ! History::record( $state['batch'], $id, 'import_redirect', '', $data['target'], array( 's' => $source->id() ) ) ) {
				Store::delete( array( $id ) );
				return $state;
			}
		}
		return self::count( $state, 'redirect', 'new' );
	}
}
