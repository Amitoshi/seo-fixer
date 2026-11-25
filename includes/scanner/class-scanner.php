<?php
/**
 * Runs the health scan in small steps so it works on shared hosting and on
 * sites with thousands of pages.
 *
 * Phases: site (settings + homepage checks) → analyze (facts per page, in
 * batches) → score (issues per page, using site-wide data such as duplicates
 * and links) → finish (summary). The browser drives the steps while the
 * dashboard is open; a cron event finishes the scan if the tab is closed.
 *
 * After the first scan, saving, unpublishing or deleting a page updates its
 * results (and the pages it affects) without a new full scan.
 *
 * @package SeoFixer
 */

namespace Seofx\Scanner;

use Seofx\Indexing\Indexing;
use Seofx\Lock;
use Seofx\Meta\Templates;
use Seofx\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Scan engine.
 */
final class Scanner {

	/**
	 * Option holding a scan in progress.
	 */
	const STATE = 'seofx_scan_state';

	/**
	 * Option holding the last finished scan's summary.
	 */
	const SUMMARY = 'seofx_scan_summary';

	/**
	 * Transient that stops two steps running at once.
	 */
	const LOCK = 'seofx_scan_lock';

	/**
	 * Background continuation.
	 */
	const CRON = 'seofx_scan_cron';

	/**
	 * Nonce action for the scan requests.
	 */
	const NONCE = 'seofx_scan';

	/**
	 * Pages analyzed per batch.
	 */
	const BATCH = 25;

	/**
	 * Pages scored per batch (scoring reads stored facts only).
	 */
	const SCORE_BATCH = 200;

	/**
	 * Pages saved during this request, rescanned on shutdown.
	 *
	 * @var array<int, true>
	 */
	private static $queue = array();

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_seofx_scan_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_seofx_scan_step', array( $this, 'ajax_step' ) );
		add_action( 'wp_ajax_seofx_scan_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_seofx_scan_dismiss', array( $this, 'ajax_dismiss' ) );
		add_action( 'admin_post_seofx_scan_begin', array( $this, 'handle_begin' ) );
		add_action( self::CRON, array( $this, 'cron' ) );

		add_action( 'save_post', array( $this, 'queue' ), 20, 2 );
		add_action( 'transition_post_status', array( $this, 'status_changed' ), 20, 3 );
		add_action( 'deleted_post', array( $this, 'queue' ), 10, 2 );
		add_action( 'shutdown', array( $this, 'process_queue' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Requests.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Checks the nonce and capability of a scan request.
	 *
	 * @return void
	 */
	private static function verify() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page and try again.', 'seo-fixer' ) ), 403 );
		}
		if ( ! Security::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to scan this site.', 'seo-fixer' ) ), 403 );
		}
	}

	/**
	 * Starts a full scan.
	 *
	 * @return void
	 */
	public function ajax_start() {
		self::verify();
		self::start();
		wp_send_json_success( self::progress() );
	}

	/**
	 * Starts a full scan from the plain form (no JavaScript): the scheduler
	 * finishes it in the background.
	 *
	 * @return void
	 */
	public function handle_begin() {
		Security::require_manage();
		check_admin_referer( 'seofx_scan_begin' );
		self::start();
		self::run( 10 );
		wp_safe_redirect( admin_url( 'admin.php?page=seo-fixer' ) );
		exit;
	}

	/**
	 * Runs one step.
	 *
	 * @return void
	 */
	public function ajax_step() {
		self::verify();
		$busy             = ! self::run( 4 );
		$progress         = self::progress();
		$progress['busy'] = $busy;
		wp_send_json_success( $progress );
	}

	/**
	 * Reports progress without doing work.
	 *
	 * @return void
	 */
	public function ajax_status() {
		self::verify();
		wp_send_json_success( self::progress() );
	}

	/**
	 * Marks a reminder as done (only "sitemap sent to Google" for now).
	 *
	 * @return void
	 */
	public function ajax_dismiss() {
		// Checked here (not only in verify()) so the nonce check sits next to the input it protects.
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) || ! Security::can_manage() ) {
			self::verify(); // Sends the matching error.
		}
		$check = isset( $_POST['check'] ) ? sanitize_key( wp_unslash( $_POST['check'] ) ) : '';
		if ( 'sitemap_submitted' !== $check ) {
			wp_send_json_error( array( 'message' => __( 'Unknown check.', 'seo-fixer' ) ), 400 );
		}
		update_option( Site_Checks::SUBMITTED_OPTION, time(), false );

		$summary = self::summary();
		if ( $summary ) {
			$summary['site'][ $check ] = Site_Checks::run_one( $check );
			self::save_summary( $summary['site'], (int) $summary['scanned_at'], (int) $summary['duration'] );
		}
		wp_send_json_success( self::progress() );
	}

	/**
	 * Cron continuation: keeps going for up to 20 seconds, then reschedules.
	 *
	 * @return void
	 */
	public function cron() {
		self::run( 20 );
		if ( self::state() ) {
			self::schedule();
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Full scan.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Post types that are scored: public types that are shown in search.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = array();
		foreach ( array_keys( Templates::post_types() ) as $type ) {
			if ( Indexing::type_indexed( $type ) ) {
				$types[] = $type;
			}
		}
		return $types;
	}

	/**
	 * Begins a new full scan (replacing one in progress).
	 *
	 * @return void
	 */
	public static function start() {
		Results::clear();
		update_option(
			self::STATE,
			array(
				'phase'   => 'site',
				'cursor'  => 0,
				'done'    => 0,
				'total'   => Results::published_count( self::post_types() ),
				'started' => time(),
				'site'    => array(),
			),
			false
		);
		Lock::release( self::LOCK );
		self::schedule();
	}

	/**
	 * Scan in progress, if any.
	 *
	 * @return array|null
	 */
	public static function state() {
		$state = get_option( self::STATE );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Last finished scan.
	 *
	 * @return array|null
	 */
	public static function summary() {
		$summary = get_option( self::SUMMARY );
		return is_array( $summary ) ? $summary : null;
	}

	/**
	 * Schedules the background continuation in a minute.
	 *
	 * @return void
	 */
	private static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON );
		}
	}

	/**
	 * Works on the scan for up to $seconds.
	 *
	 * @param int $seconds Time budget.
	 * @return bool False when another request is already working on it.
	 */
	public static function run( $seconds ) {
		$state = self::state();
		if ( ! $state ) {
			return true;
		}
		if ( ! Lock::acquire( self::LOCK, MINUTE_IN_SECONDS ) ) {
			return false;
		}

		$until = microtime( true ) + $seconds;
		do {
			$phase = $state['phase'];
			$state = self::step( $state, $until );
			if ( null === $state ) {
				break; // Finished.
			}
			update_option( self::STATE, $state, false );
			// The site checks load two pages, so they get a request of their own.
		} while ( 'site' !== $phase && microtime( true ) < $until );

		Lock::release( self::LOCK );
		return true;
	}

	/**
	 * One unit of work. Batches stop early at the deadline (checked after every
	 * page), so a step never runs long on a slow database or host.
	 *
	 * @param array $state Scan state.
	 * @param float $until Deadline (microtime).
	 * @return array|null New state, or null once finished.
	 */
	private static function step( array $state, $until ) {
		switch ( $state['phase'] ) {
			case 'site':
				$state['site']  = Site_Checks::run();
				$state['phase'] = 'analyze';
				return $state;

			case 'analyze':
				$ids = Results::published_ids( self::post_types(), (int) $state['cursor'], self::BATCH );
				if ( array() === $ids ) {
					$state['phase']  = 'score';
					$state['cursor'] = 0;
					$state['done']   = 0;
					return $state;
				}
				foreach ( $ids as $id ) {
					self::analyze( $id );
					$state['cursor'] = $id;
					++$state['done'];
					if ( microtime( true ) >= $until ) {
						break;
					}
				}
				// Pages published while the scan runs are picked up too (their ids are higher).
				$state['total'] = max( $state['done'], Results::published_count( self::post_types() ) );
				return $state;

			case 'score':
				$rows = Results::batch( (int) $state['cursor'], self::SCORE_BATCH );
				if ( array() === $rows ) {
					self::save_summary( $state['site'], time(), time() - (int) $state['started'] );
					delete_option( self::STATE );
					wp_clear_scheduled_hook( self::CRON );
					return null;
				}
				$scored          = self::score_rows( $rows, self::aggregates(), $until );
				$state['cursor'] = $scored['last'];
				$state['done']  += $scored['count'];
				return $state;
		}

		delete_option( self::STATE );
		return null;
	}

	/**
	 * Analyzes one page and stores its facts.
	 *
	 * @param int $post_id Post id.
	 * @return bool Whether the page was stored.
	 */
	private static function analyze( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		$result = Analyzer::analyze( $post );
		Results::save_facts( $post, $result['facts'], $result['links'] );
		return true;
	}

	/**
	 * Site-wide data needed to score pages.
	 *
	 * @return array{dupes: array, inbound: array<int, int>, menu: array}
	 */
	private static function aggregates() {
		return array(
			'dupes'   => Results::duplicate_hashes(),
			'inbound' => Results::inbound_counts(),
			'menu'    => self::menu_links(),
			'listed'  => self::listed_types(),
		);
	}

	/**
	 * Content types whose items are always linked from a list: the blog,
	 * their own archive, or a category/tag page. Only the rest (such as pages)
	 * can end up with no links at all.
	 *
	 * @return array<string, true>
	 */
	private static function listed_types() {
		$listed     = array( 'post' => true );
		$taxonomies = array_keys( Templates::taxonomies() );
		foreach ( Templates::post_types() as $name => $type ) {
			if ( ! empty( $type->has_archive ) || array_intersect( get_object_taxonomies( $name ), $taxonomies ) ) {
				$listed[ $name ] = true;
			}
		}
		return $listed;
	}

	/**
	 * Scores stored rows.
	 *
	 * @param array[]    $rows       Rows from Results::batch().
	 * @param array      $aggregates From aggregates().
	 * @param float|null $until      Stop after the page that passes this deadline.
	 * @return array{last: int, count: int} Last post id handled and how many.
	 */
	private static function score_rows( array $rows, array $aggregates, $until = null ) {
		$handled = array(
			'last'  => 0,
			'count' => 0,
		);
		foreach ( $rows as $row ) {
			$post_id          = (int) $row['post_id'];
			$handled['last']  = $post_id;
			$handled['count'] = $handled['count'] + 1;
			if ( ! is_array( $row['facts'] ) ) {
				continue;
			}
			$linked = isset( $aggregates['listed'][ $row['post_type'] ] )
				|| isset( $aggregates['inbound'][ $post_id ] )
				|| isset( $aggregates['menu']['ids'][ $post_id ] )
				|| ( 'page' === $row['post_type'] && $aggregates['menu']['all_pages'] );

			$issues = self::issues(
				$row['facts'],
				array(
					'title_dupe' => isset( $aggregates['dupes']['title'][ $row['title_hash'] ] ),
					'desc_dupe'  => isset( $aggregates['dupes']['desc'][ $row['desc_hash'] ] ),
					'linked'     => $linked,
				)
			);
			Results::save_score( $post_id, $issues, self::page_score( $issues ) );
			if ( null !== $until && microtime( true ) >= $until ) {
				break;
			}
		}
		return $handled;
	}

	/**
	 * Pages reachable from the site's menus.
	 *
	 * Block themes use Navigation blocks; a "Page List" block (or no menu at all,
	 * where themes fall back to listing pages) links every page.
	 *
	 * @return array{ids: array<int, true>, all_pages: bool}
	 */
	private static function menu_links() {
		$ids       = array();
		$all_pages = false;

		if ( wp_is_block_theme() ) {
			$navigations = get_posts(
				array(
					'post_type'      => 'wp_navigation',
					'post_status'    => 'publish',
					'posts_per_page' => 20,
				)
			);
			if ( array() === $navigations ) {
				$all_pages = true;
			}
			foreach ( $navigations as $navigation ) {
				if ( false !== strpos( $navigation->post_content, '<!-- wp:page-list' ) ) {
					$all_pages = true;
				}
				if ( preg_match_all( '/"id":(\d+)/', $navigation->post_content, $matches ) ) {
					foreach ( $matches[1] as $id ) {
						$ids[ (int) $id ] = true;
					}
				}
			}
		} else {
			$locations = array_filter( get_nav_menu_locations() );
			if ( array() === $locations ) {
				$all_pages = true;
			}
			foreach ( wp_get_nav_menus() as $menu ) {
				foreach ( (array) wp_get_nav_menu_items( $menu ) as $item ) {
					if ( isset( $item->type, $item->object_id ) && 'post_type' === $item->type ) {
						$ids[ (int) $item->object_id ] = true;
					}
				}
			}
		}

		return array(
			'ids'       => $ids,
			'all_pages' => $all_pages,
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Scoring.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Issues of one page.
	 *
	 * @param array $facts   Facts from Analyzer.
	 * @param array $context title_dupe, desc_dupe, linked.
	 * @return array[] Each: id, and count + items where useful.
	 */
	public static function issues( array $facts, array $context ) {
		$issues = array();
		$add    = static function ( $id, $count = 0, $items = array() ) use ( &$issues ) {
			$issue = array( 'id' => $id );
			if ( $count ) {
				$issue['count'] = $count;
				$issue['items'] = $items;
			}
			$issues[] = $issue;
		};

		// Hidden pages only need to know they're hidden (and about broken links).
		if ( ! empty( $facts['noindex'] ) ) {
			$add( 'noindex' );
			if ( $facts['broken_n'] > 0 ) {
				$add( 'broken_links', $facts['broken_n'], $facts['broken'] );
			}
			return $issues;
		}

		$special = ! empty( $facts['is_front'] ) || ! empty( $facts['is_blog'] );

		if ( 0 === $facts['desc_len'] ) {
			$add( 'desc_missing' );
		} elseif ( $facts['desc_len'] > 160 ) {
			$add( 'desc_too_long' );
		} elseif ( $facts['desc_len'] < 70 ) {
			$add( 'desc_too_short' );
		}
		if ( $context['title_dupe'] ) {
			$add( 'title_duplicate' );
		}
		if ( $context['desc_dupe'] ) {
			$add( 'desc_duplicate' );
		}
		if ( $facts['no_alt_n'] > 0 ) {
			$add( 'img_no_alt', $facts['no_alt_n'], $facts['no_alt'] );
		}
		if ( $facts['broken_n'] > 0 ) {
			$add( 'broken_links', $facts['broken_n'], $facts['broken'] );
		}
		if ( ! $context['linked'] && ! $special ) {
			$add( 'orphan' );
		}
		if ( $facts['title_len'] > 65 ) {
			$add( 'title_too_long' );
		}
		if ( $facts['h1'] > 0 ) {
			$add( 'h1_in_content' );
		}
		if ( $facts['large_n'] > 0 ) {
			$add( 'img_large', $facts['large_n'], $facts['large'] );
		}
		if ( empty( $facts['has_image'] ) ) {
			$add( 'no_image' );
		}
		if ( $facts['slug_len'] > 60 && empty( $facts['is_front'] ) ) {
			$add( 'slug_long' );
		}
		if ( $facts['desc_len'] > 0 && empty( $facts['desc_custom'] ) ) {
			$add( 'desc_auto' );
		}
		if ( $facts['words'] < 300 && ! $special && empty( $facts['password'] ) ) {
			$add( 'thin_content' );
		}

		return $issues;
	}

	/**
	 * Checks one page on the spot, without storing anything (the editor's live
	 * checklist). Same rules as the full scan, compared with the stored results
	 * of every other page.
	 *
	 * @param \WP_Post $post Post (may hold unsaved changes).
	 * @return array{facts: array, issues: array[], score: int}
	 */
	public static function check( \WP_Post $post ) {
		$result = Analyzer::analyze( $post );
		$facts  = $result['facts'];
		$hidden = ! empty( $facts['noindex'] );
		$menu   = self::menu_links();
		$listed = self::listed_types();

		$issues = self::issues(
			$facts,
			array(
				'title_dupe' => ! $hidden && '' !== $facts['title'] && Results::hash_shared( 'title_hash', md5( mb_strtolower( $facts['title'] ) ), $post->ID ),
				'desc_dupe'  => ! $hidden && '' !== $facts['desc'] && Results::hash_shared( 'desc_hash', md5( mb_strtolower( $facts['desc'] ) ), $post->ID ),
				'linked'     => isset( $listed[ $post->post_type ] )
					|| Results::inbound_count( $post->ID ) > 0
					|| isset( $menu['ids'][ $post->ID ] )
					|| ( 'page' === $post->post_type && $menu['all_pages'] ),
			)
		);

		return array(
			'facts'  => $facts,
			'issues' => $issues,
			'score'  => self::page_score( $issues ),
		);
	}

	/**
	 * Page score: 100 minus the weight of each issue.
	 *
	 * @param array[] $issues Issues.
	 * @return int
	 */
	public static function page_score( array $issues ) {
		$checks = Checks::page_checks();
		$score  = 100;
		foreach ( $issues as $issue ) {
			if ( isset( $checks[ $issue['id'] ] ) ) {
				$score -= Checks::PAGE_WEIGHTS[ $checks[ $issue['id'] ]['severity'] ];
			}
		}
		return max( 0, $score );
	}

	/**
	 * Stores the summary from site results and the current page rows.
	 *
	 * Overall = 40% site settings + 60% average page score, and at most 45
	 * while any critical problem remains (so a site hidden from Google never
	 * looks healthy).
	 *
	 * @param array $site       Site check results.
	 * @param int   $scanned_at When the full scan finished.
	 * @param int   $duration   Seconds the full scan took.
	 * @return array Summary.
	 */
	public static function save_summary( array $site, $scanned_at, $duration ) {
		$site_checks = Checks::site_checks();
		$site_score  = 100;
		$critical    = 0;
		$failed      = 0;
		foreach ( $site as $id => $result ) {
			if ( ! isset( $site_checks[ $id ] ) || false !== $result['pass'] ) {
				continue;
			}
			$severity    = $site_checks[ $id ]['severity'];
			$site_score -= Checks::SITE_WEIGHTS[ $severity ];
			++$failed;
			if ( 'critical' === $severity ) {
				++$critical;
			}
		}
		$site_score = max( 0, $site_score );

		$totals  = Results::totals( array_keys( Checks::page_checks() ) );
		$content = $totals['average'];
		$overall = null === $content ? $site_score : (int) round( 0.4 * $site_score + 0.6 * $content );
		if ( $critical ) {
			$overall = min( $overall, Checks::CRITICAL_CAP );
		}

		$summary = array(
			'score'         => $overall,
			'site_score'    => $site_score,
			'content_score' => $content,
			'pages'         => $totals['pages'],
			'issues'        => array_filter( $totals['issues'] ),
			'site'          => $site,
			'site_failed'   => $failed,
			'critical'      => $critical,
			'scanned_at'    => (int) $scanned_at,
			'updated_at'    => time(),
			'duration'      => (int) $duration,
		);
		update_option( self::SUMMARY, $summary, false );
		return $summary;
	}

	/**
	 * What the dashboard needs to show progress.
	 *
	 * @return array
	 */
	public static function progress() {
		$state = self::state();
		if ( ! $state ) {
			$summary = self::summary();
			return array(
				'running' => false,
				'percent' => 100,
				'label'   => '',
				'score'   => $summary ? $summary['score'] : null,
			);
		}

		$total = max( 1, (int) $state['total'] );
		$done  = min( $total, (int) $state['done'] );
		switch ( $state['phase'] ) {
			case 'site':
				$percent = 2;
				$label   = __( 'Checking your site settings and homepage…', 'seo-fixer' );
				break;
			case 'analyze':
				$percent = 5 + (int) floor( 80 * $done / $total );
				/* translators: 1: pages checked so far, 2: total pages. */
				$label = sprintf( __( 'Checking pages: %1$s of %2$s…', 'seo-fixer' ), number_format_i18n( $done ), number_format_i18n( (int) $state['total'] ) );
				break;
			default:
				$percent = 85 + (int) floor( 14 * $done / $total );
				$label   = __( 'Comparing pages and adding up your score…', 'seo-fixer' );
		}

		return array(
			'running' => true,
			'percent' => min( 99, $percent ),
			'label'   => $label,
			'score'   => null,
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Keeping results current between scans.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Queues a saved or deleted page for rescanning at the end of the request
	 * (after the editor has also saved its SEO fields).
	 *
	 * @param int           $post_id Post id.
	 * @param \WP_Post|null $post    Post (passed by save_post and deleted_post).
	 * @return void
	 */
	public function queue( $post_id, $post = null ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		// Only scanned content: menu items, orders and other internal types are saved all the time.
		$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( $post_id );
		if ( ! in_array( $type, self::post_types(), true ) ) {
			return;
		}
		self::$queue[ (int) $post_id ] = true;
	}

	/**
	 * Queues pages leaving or entering "published".
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public function status_changed( $new_status, $old_status, $post ) {
		if ( $new_status !== $old_status && ( 'publish' === $new_status || 'publish' === $old_status ) ) {
			$this->queue( $post->ID, $post );
		}
	}

	/**
	 * Rescans queued pages, then the pages whose results depend on them.
	 *
	 * @return void
	 */
	public function process_queue() {
		if ( array() === self::$queue || ! self::summary() || self::state() ) {
			return; // Nothing changed, never scanned, or a full scan will pick it up.
		}

		// Imports and bulk edits: one background scan beats rescanning page by page.
		if ( count( self::$queue ) > self::BATCH ) {
			self::$queue = array();
			self::start();
			return;
		}

		self::rescan( array_keys( self::$queue ) );
	}

	/**
	 * Rescans pages now (after a fix, for instance), then the pages whose
	 * results depend on them, and refreshes the summary.
	 *
	 * @param int[] $post_ids Post ids.
	 * @return void
	 */
	public static function rescan( array $post_ids ) {
		foreach ( $post_ids as $post_id ) {
			unset( self::$queue[ (int) $post_id ] ); // Done here, not again on shutdown.
		}
		if ( array() === $post_ids || ! self::summary() || self::state() ) {
			return;
		}
		// Many pages at once (e.g. undoing a big fix): a background scan is safer than one long request.
		if ( count( $post_ids ) > 2 * self::BATCH ) {
			self::start();
			return;
		}

		$types    = self::post_types();
		$affected = array();
		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			// Pages that shared a title/description or were linked from it before the change.
			$old = Results::get( $post_id );
			if ( $old ) {
				$affected = array_merge( $affected, Results::dupe_partners( 'title_hash', $old['title_hash'] ), Results::dupe_partners( 'desc_hash', $old['desc_hash'] ), Results::targets_of( $post_id ) );
			}

			$post = get_post( $post_id );
			if ( $post && 'publish' === $post->post_status && in_array( $post->post_type, $types, true ) ) {
				self::analyze( $post_id );
				$new        = Results::get( $post_id );
				$affected[] = $post_id;
				if ( ! $new ) {
					continue;
				}
				$affected   = array_merge( $affected, Results::dupe_partners( 'title_hash', $new['title_hash'] ), Results::dupe_partners( 'desc_hash', $new['desc_hash'] ), Results::targets_of( $post_id ) );
			} else {
				Results::forget( $post_id );
			}
		}

		$rows = array();
		foreach ( array_unique( $affected ) as $post_id ) {
			$row = Results::get( (int) $post_id );
			if ( $row ) {
				$rows[] = $row;
			}
		}
		if ( $rows ) {
			self::score_rows( $rows, self::aggregates() );
		}

		$summary = self::summary();
		self::save_summary( $summary['site'], (int) $summary['scanned_at'], (int) $summary['duration'] );
	}
}
