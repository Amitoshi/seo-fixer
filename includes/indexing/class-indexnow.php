<?php
/**
 * IndexNow: tells Bing, Yandex and other participating search engines about
 * new, changed and removed pages.
 *
 * Off by default. Changed addresses are collected while a page is saved and
 * sent a couple of minutes later by WP-Cron, so saving never waits for
 * another server and quick successive edits go out as one request.
 *
 * @package SeoFixer
 */

namespace Seofx\Indexing;

use Seofx\Meta\Templates;
use Seofx\Options;
use Seofx\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * IndexNow key file, change queue and submissions.
 */
final class IndexNow {

	/**
	 * Shared endpoint; it passes every submission on to all IndexNow search engines.
	 */
	const ENDPOINT = 'https://api.indexnow.org/indexnow';

	/**
	 * Option holding the site's key.
	 */
	const KEY_OPTION = 'seofx_indexnow_key';

	/**
	 * Option holding addresses waiting to be sent: {urls: string[], attempts: int}.
	 */
	const QUEUE_OPTION = 'seofx_indexnow_queue';

	/**
	 * Option holding the most recent submissions.
	 */
	const LOG_OPTION = 'seofx_indexnow_log';

	/**
	 * WP-Cron hook that sends the queue.
	 */
	const CRON_HOOK = 'seofx_indexnow_send';

	/**
	 * Seconds to wait after a change before sending (edits in between are combined).
	 */
	const DELAY = 120;

	/**
	 * Seconds to wait before trying again after a failure.
	 */
	const RETRY_DELAY = 3600;

	/**
	 * Tries per batch before it is dropped.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Most addresses IndexNow accepts in one request.
	 */
	const MAX_URLS = 10000;

	/**
	 * Submissions kept in the log.
	 */
	const LOG_SIZE = 10;

	/**
	 * Posts published or updated in this request (id => true); checked at shutdown,
	 * when their final address and "show in search results" setting are saved.
	 *
	 * @var array<int, bool>
	 */
	private static $posts = array();

	/**
	 * Addresses that stopped working in this request (url => true).
	 *
	 * @var array<string, bool>
	 */
	private static $removed = array();

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'send_queue' ) );

		if ( ! Options::get( 'indexnow_enabled' ) ) {
			return;
		}

		// Before the redirect manager, which also runs on parse_request.
		add_action( 'parse_request', array( $this, 'serve_key' ), 0 );

		if ( '' === self::blocker() ) {
			add_action( 'transition_post_status', array( $this, 'on_status' ), 10, 3 );
			add_action( 'post_updated', array( $this, 'on_update' ), 10, 3 );
			add_action( 'before_delete_post', array( $this, 'on_delete' ), 10, 2 );
			add_action( 'shutdown', array( $this, 'flush' ), 5 );
		}
	}

	/**
	 * Why nothing can be sent right now ('' = ready).
	 *
	 * @return string off|paused|private|permalinks|local|''
	 */
	public static function blocker() {
		if ( ! Options::get( 'indexnow_enabled' ) ) {
			return 'off';
		}
		// Another SEO plugin is in charge (several of them send IndexNow too).
		if ( ! Plugin::output_enabled() ) {
			return 'paused';
		}
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			return 'private';
		}
		// The key file needs WordPress to answer for /{key}.txt.
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return 'permalinks';
		}
		if ( ! self::is_public_host( self::host() ) ) {
			return 'local';
		}
		return '';
	}

	/**
	 * The site's host name.
	 *
	 * @return string
	 */
	public static function host() {
		return strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}

	/**
	 * Whether search engines could reach this host (not localhost, a private IP or a test domain).
	 *
	 * @param string $host Host name.
	 * @return bool
	 */
	public static function is_public_host( $host ) {
		$host = strtolower( trim( (string) $host, '[]' ) );

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$public = false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		} else {
			$public = false !== strpos( $host, '.' ) && ! preg_match( '/\.(localhost|local|test|example|invalid|internal|lan|home\.arpa)$/', $host );
		}

		/**
		 * Filters whether the site's address is public, so IndexNow may be used.
		 *
		 * @param bool   $public Whether the host looks public.
		 * @param string $host   Host name.
		 */
		return (bool) apply_filters( 'seofx_indexnow_public_site', $public, $host );
	}

	/**
	 * The site's key, created the first time it is needed.
	 *
	 * @return string
	 */
	public static function key() {
		$key = (string) get_option( self::KEY_OPTION, '' );
		if ( ! preg_match( '/^[A-Za-z0-9-]{8,128}$/', $key ) ) {
			$key = wp_generate_password( 32, false );
			update_option( self::KEY_OPTION, $key, true ); // Autoloaded: serve_key() checks it on every request.
		}
		return $key;
	}

	/**
	 * Address of the key file, which proves to search engines that the requests come from this site.
	 *
	 * @return string
	 */
	public static function key_url() {
		return home_url( '/' . self::key() . '.txt' );
	}

	/**
	 * Answers requests for /{key}.txt with the key.
	 *
	 * @param \WP $wp Current request.
	 * @return void
	 */
	public function serve_key( $wp ) {
		$key = (string) get_option( self::KEY_OPTION, '' );
		if ( '' === $key || $key . '.txt' !== $wp->request ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo esc_html( $key );
		exit;
	}

	/**
	 * Whether posts of a type have public pages that SEO Fixer handles.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private static function public_type( $post_type ) {
		return array_key_exists( $post_type, Templates::post_types() );
	}

	/**
	 * Remembers posts that are published (new, updated or scheduled ones going live).
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public function on_status( $new_status, $old_status, $post ) {
		if ( 'publish' === $new_status && $post instanceof \WP_Post && self::public_type( $post->post_type ) ) {
			self::$posts[ (int) $post->ID ] = true;
		}
	}

	/**
	 * Remembers the old address of a published post that was unpublished, trashed or moved.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $after   Post after the update.
	 * @param \WP_Post $before  Post before the update.
	 * @return void
	 */
	public function on_update( $post_id, $after, $before ) {
		if ( ! $before instanceof \WP_Post || 'publish' !== $before->post_status || ! self::public_type( $before->post_type ) ) {
			return;
		}
		$old = (string) get_permalink( $before );
		if ( 'publish' !== $after->post_status || get_permalink( $after ) !== $old ) {
			self::$removed[ $old ] = true;
		}
	}

	/**
	 * Remembers the address of a published post deleted without going to the trash.
	 *
	 * @param int           $post_id Post id.
	 * @param \WP_Post|null $post    Post (WordPress 5.5+).
	 * @return void
	 */
	public function on_delete( $post_id, $post = null ) {
		$post = $post instanceof \WP_Post ? $post : get_post( $post_id );
		if ( $post && 'publish' === $post->post_status && self::public_type( $post->post_type ) ) {
			self::$removed[ (string) get_permalink( $post ) ] = true;
		}
	}

	/**
	 * Queues this request's changes (shutdown).
	 *
	 * @return void
	 */
	public function flush() {
		$urls = array_keys( self::$removed );
		foreach ( array_keys( self::$posts ) as $post_id ) {
			$post = get_post( $post_id );
			// Hidden and password-protected pages are not announced.
			if ( $post && 'publish' === $post->post_status && '' === $post->post_password && Indexing::post_indexable( $post ) ) {
				$urls[] = (string) get_permalink( $post );
			}
		}
		self::$posts   = array();
		self::$removed = array();

		if ( array() !== $urls ) {
			self::queue( $urls );
		}
	}

	/**
	 * Addresses waiting to be sent.
	 *
	 * @return array{urls: string[], attempts: int}
	 */
	public static function queued() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		return array(
			'urls'     => isset( $queue['urls'] ) ? array_values( array_filter( (array) $queue['urls'], 'is_string' ) ) : array(),
			'attempts' => isset( $queue['attempts'] ) ? (int) $queue['attempts'] : 0,
		);
	}

	/**
	 * Adds addresses to the queue and makes sure it will be sent.
	 *
	 * @param string[] $urls Addresses.
	 * @return void
	 */
	public static function queue( array $urls ) {
		$host  = self::host();
		$queue = self::queued();
		foreach ( $urls as $url ) {
			// IndexNow only accepts addresses on the key's own host.
			if ( '' !== $url && strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === $host ) {
				$queue['urls'][] = $url;
			}
		}
		$queue['urls'] = array_slice( array_values( array_unique( $queue['urls'] ) ), -self::MAX_URLS );
		if ( array() === $queue['urls'] ) {
			return;
		}

		update_option( self::QUEUE_OPTION, $queue, false );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + self::DELAY, self::CRON_HOOK );
		}
	}

	/**
	 * Sends the queue (WP-Cron).
	 *
	 * @return void
	 */
	public static function send_queue() {
		$queue = self::queued();
		if ( array() === $queue['urls'] ) {
			return;
		}
		if ( '' !== self::blocker() ) {
			delete_option( self::QUEUE_OPTION );
			return;
		}

		$sent   = $queue['urls'];
		$result = self::submit( $sent, 'auto' );

		// Addresses queued by other requests while this one was sending stay in the queue.
		$latest = self::queued();
		$left   = array_values( array_diff( $latest['urls'], $sent ) );
		if ( $result['retry'] && $queue['attempts'] + 1 < self::MAX_ATTEMPTS ) {
			$left     = array_values( array_unique( array_merge( $sent, $left ) ) );
			$attempts = $queue['attempts'] + 1;
			$delay    = self::RETRY_DELAY;
		} else {
			$attempts = 0;
			$delay    = self::DELAY;
		}

		if ( array() === $left ) {
			delete_option( self::QUEUE_OPTION );
			return;
		}
		update_option(
			self::QUEUE_OPTION,
			array(
				'urls'     => $left,
				'attempts' => $attempts,
			),
			false
		);
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
		}
	}

	/**
	 * Sends addresses to IndexNow and logs the answer.
	 *
	 * @param string[] $urls   Addresses on this site.
	 * @param string   $source auto|test.
	 * @return array{code: int, error: string, retry: bool}
	 */
	public static function submit( array $urls, $source = 'auto' ) {
		$urls = array_slice( array_values( $urls ), 0, self::MAX_URLS );

		/**
		 * Filters the IndexNow endpoint (for example to send to one search engine only).
		 *
		 * @param string $endpoint Endpoint URL.
		 */
		$endpoint = (string) apply_filters( 'seofx_indexnow_endpoint', self::ENDPOINT );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode(
					array(
						'host'        => self::host(),
						'key'         => self::key(),
						'keyLocation' => self::key_url(),
						'urlList'     => $urls,
					)
				),
			)
		);

		$code  = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$error = is_wp_error( $response ) ? $response->get_error_message() : '';
		self::log(
			array(
				'time'   => time(),
				'count'  => count( $urls ),
				'url'    => isset( $urls[0] ) ? $urls[0] : '',
				'code'   => $code,
				'error'  => $error,
				'source' => $source,
			)
		);

		return array(
			'code'  => $code,
			'error' => $error,
			'retry' => 0 === $code || 429 === $code || $code >= 500,
		);
	}

	/**
	 * Adds an entry to the submission log.
	 *
	 * @param array $entry Entry.
	 * @return void
	 */
	private static function log( array $entry ) {
		$log = self::recent();
		array_unshift( $log, $entry );
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_SIZE ), false );
	}

	/**
	 * Recent submissions, newest first.
	 *
	 * @return array[]
	 */
	public static function recent() {
		return array_values( array_filter( (array) get_option( self::LOG_OPTION, array() ), 'is_array' ) );
	}

	/**
	 * What a submission's answer means, in plain English.
	 *
	 * @param array $entry Log entry.
	 * @return array{tone: string, message: string}
	 */
	public static function describe( array $entry ) {
		$code = isset( $entry['code'] ) ? (int) $entry['code'] : 0;

		switch ( $code ) {
			case 200:
				return array(
					'tone'    => 'good',
					'message' => __( 'Accepted. Bing and the other IndexNow search engines were told.', 'seo-fixer' ),
				);
			case 202:
				return array(
					'tone'    => 'good',
					'message' => __( 'Received. The search engines are still checking your key, which is normal the first time.', 'seo-fixer' ),
				);
			case 400:
				return array(
					'tone'    => 'bad',
					'message' => __( 'Refused: the request wasn’t valid.', 'seo-fixer' ),
				);
			case 403:
				return array(
					'tone'    => 'bad',
					'message' => __( 'Refused: the key wasn’t accepted. Check that your key file opens and shows the key.', 'seo-fixer' ),
				);
			case 422:
				return array(
					'tone'    => 'bad',
					'message' => __( 'Refused: the addresses don’t match this site or its key.', 'seo-fixer' ),
				);
			case 429:
				return array(
					'tone'    => 'warn',
					'message' => __( 'Too many requests for now. SEO Fixer will try again later.', 'seo-fixer' ),
				);
			case 0:
				return array(
					'tone'    => 'warn',
					/* translators: %s: technical error message. */
					'message' => sprintf( __( 'IndexNow couldn’t be reached (%s). SEO Fixer will try again later.', 'seo-fixer' ), isset( $entry['error'] ) ? (string) $entry['error'] : '' ),
				);
		}

		return array(
			'tone'    => $code >= 500 ? 'warn' : 'bad',
			/* translators: %d: HTTP status code. */
			'message' => sprintf( __( 'Unexpected answer (code %d).', 'seo-fixer' ), $code ),
		);
	}
}
