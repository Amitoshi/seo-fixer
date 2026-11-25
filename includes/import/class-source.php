<?php
/**
 * A plugin SEO Fixer can import from. Each source reads its own storage and
 * returns the same plain records, so the importer never needs to know where
 * a value came from.
 *
 * Record fields (all optional):
 * - title, description, social_title, social_description: text, possibly with the source's own variables;
 * - noindex: true = hidden from search, false = shown even if its type is hidden;
 * - nofollow: true = don't follow links;
 * - canonical: URL;
 * - social_image: attachment id (int) or image URL (string);
 * - focus: focus keyword (counted, not imported).
 *
 * Sources only read. Nothing in the other plugin's data is ever changed.
 *
 * @package SeoFixer
 */

namespace Seofx\Import;

use Seofx\Meta\Templates;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading another plugin's stored data once, during an import the user started.

/**
 * Base class for import sources.
 */
abstract class Source {

	/**
	 * Id (matches Conflicts::known()).
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Plugin name.
	 *
	 * @return string
	 */
	abstract public function name();

	/**
	 * Post meta keys holding SEO data ([] when the source uses its own table).
	 *
	 * @return string[]
	 */
	abstract protected function post_keys();

	/**
	 * Records for posts, keyed by post id.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int, array>
	 */
	abstract public function posts( array $ids );

	/**
	 * Site-wide settings: home_title, home_description, title_separator and the
	 * verify_* codes, as stored by the source (still with its variables).
	 *
	 * @return array<string, string>
	 */
	abstract public function settings();

	/**
	 * Source variable => SEO Fixer variable name ('' = drop it).
	 *
	 * @return array<string, string>
	 */
	abstract protected function variables();

	/**
	 * Term meta keys holding SEO data ([] when stored elsewhere).
	 *
	 * @return string[]
	 */
	protected function term_keys() {
		return array();
	}

	/**
	 * Records for terms, keyed by term id.
	 *
	 * @param int[] $ids Term ids.
	 * @return array<int, array>
	 */
	public function terms( array $ids ) {
		unset( $ids );
		return array();
	}

	/**
	 * Redirects: arrays of source, target, status, match_type (exact|regex).
	 *
	 * @param int $after Position to continue after.
	 * @param int $limit Maximum number.
	 * @return array{items: array[], next: int} next = 0 when finished.
	 */
	public function redirects( $after, $limit ) {
		unset( $after, $limit );
		return array(
			'items' => array(),
			'next'  => 0,
		);
	}

	/**
	 * Number of redirects stored by the source.
	 *
	 * @return int
	 */
	public function redirect_count() {
		return 0;
	}

	/**
	 * Whether the source's plugin is active right now.
	 *
	 * @return bool
	 */
	public function is_active() {
		$known = \Seofx\Conflicts::known();
		if ( ! isset( $known[ $this->id() ] ) ) {
			return false;
		}
		$plugin = $known[ $this->id() ];
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return in_array( $plugin['file'], $active, true ) || ( isset( $plugin['constant'] ) && defined( $plugin['constant'] ) ) || ( isset( $plugin['class'] ) && class_exists( $plugin['class'], false ) );
	}

	/**
	 * Post ids with SEO data after $after (ascending), in the content types SEO Fixer handles.
	 *
	 * @param int $after Last id handled.
	 * @param int $limit Maximum number.
	 * @return int[]
	 */
	public function post_ids( $after, $limit ) {
		global $wpdb;
		$keys  = $this->post_keys();
		$types = array_keys( Templates::post_types() );
		if ( array() === $keys || array() === $types ) {
			return array();
		}
		$args = array_merge( array( $wpdb->postmeta, $wpdb->posts ), $keys, $types, array( (int) $after, (int) $limit ) );
		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT m.post_id FROM %i m INNER JOIN %i p ON p.ID = m.post_id WHERE m.meta_key IN (" . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ") AND m.meta_value <> '' AND p.post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ") AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit') AND m.post_id > %d ORDER BY m.post_id ASC LIMIT %d",
					$args
				)
			)
		);
	}

	/**
	 * Number of posts with SEO data (for the overview and the progress bar).
	 *
	 * @return int
	 */
	public function post_count() {
		global $wpdb;
		$keys  = $this->post_keys();
		$types = array_keys( Templates::post_types() );
		if ( array() === $keys || array() === $types ) {
			return 0;
		}
		$args = array_merge( array( $wpdb->postmeta, $wpdb->posts ), $keys, $types );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT m.post_id) FROM %i m INNER JOIN %i p ON p.ID = m.post_id WHERE m.meta_key IN (" . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ") AND m.meta_value <> '' AND p.post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ") AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')",
				$args
			)
		);
	}

	/**
	 * Term ids with SEO data after $after (ascending).
	 *
	 * @param int $after Last id handled.
	 * @param int $limit Maximum number.
	 * @return int[]
	 */
	public function term_ids( $after, $limit ) {
		global $wpdb;
		$keys = $this->term_keys();
		if ( array() === $keys ) {
			return array();
		}
		$args = array_merge( array( $wpdb->termmeta ), $keys, array( (int) $after, (int) $limit ) );
		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT term_id FROM %i WHERE meta_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ") AND meta_value <> '' AND term_id > %d ORDER BY term_id ASC LIMIT %d",
					$args
				)
			)
		);
	}

	/**
	 * Number of terms with SEO data.
	 *
	 * @return int
	 */
	public function term_count() {
		global $wpdb;
		$keys = $this->term_keys();
		if ( array() === $keys ) {
			return 0;
		}
		$args = array_merge( array( $wpdb->termmeta ), $keys );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT term_id) FROM %i WHERE meta_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ") AND meta_value <> ''", $args ) );
	}

	/**
	 * Whether there is anything to import.
	 *
	 * @return bool
	 */
	public function has_data() {
		return $this->post_count() > 0 || $this->term_count() > 0 || $this->redirect_count() > 0 || array() !== array_filter( $this->settings() );
	}

	/**
	 * Converts the source's variables to SEO Fixer's.
	 *
	 * @param string $text Text from the source.
	 * @return array{text: string, simplified: bool} simplified = a variable SEO Fixer doesn't have was left out.
	 */
	public function convert( $text ) {
		$simplified = false;
		$map        = $this->variables();
		$text       = (string) preg_replace_callback(
			$this->variable_pattern(),
			static function ( $match ) use ( $map, &$simplified ) {
				$name = strtolower( $match[1] );
				if ( isset( $map[ $name ] ) && '' !== $map[ $name ] ) {
					return '%' . $map[ $name ] . '%';
				}
				if ( array_key_exists( $name, $map ) || '%%' === substr( $match[0], 0, 2 ) ) {
					$simplified = true; // Known to the source but not to SEO Fixer, or any %%variable%%: drop it.
					return '';
				}
				return $match[0];
			},
			$text
		);
		$text = (string) preg_replace( '/\s{2,}/u', ' ', $text );
		if ( $simplified ) {
			$text = (string) preg_replace( '/\s+([.,;:!?])/u', '$1', $text ); // "since %customfield% ." → "since."
		}
		return array(
			'text'       => trim( $text ),
			'simplified' => $simplified,
		);
	}

	/**
	 * Regular expression for the source's variables (group 1 = name).
	 *
	 * @return string
	 */
	protected function variable_pattern() {
		return '/%%([a-z0-9_]+)%%/i';
	}

	/**
	 * Post meta value as a string.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @return string
	 */
	protected static function meta( $post_id, $key ) {
		$value = get_post_meta( $post_id, $key, true );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Term meta value as a string.
	 *
	 * @param int    $term_id Term id.
	 * @param string $key     Meta key.
	 * @return string
	 */
	protected static function term_meta( $term_id, $key ) {
		$value = get_term_meta( $term_id, $key, true );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Option that may be stored as an array or a JSON string.
	 *
	 * @param string $name Option name.
	 * @return array
	 */
	protected static function option( $name ) {
		$value = get_option( $name, array() );
		if ( is_string( $value ) && '' !== $value ) {
			$value = json_decode( $value, true );
		}
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Value at a path in a nested array.
	 *
	 * @param array    $data Data.
	 * @param string[] $path Keys.
	 * @return string
	 */
	protected static function dig( array $data, array $path ) {
		foreach ( $path as $key ) {
			if ( ! is_array( $data ) || ! isset( $data[ $key ] ) ) {
				return '';
			}
			$data = $data[ $key ];
		}
		return is_scalar( $data ) ? trim( (string) $data ) : '';
	}

	/**
	 * Drops empty values from a record.
	 *
	 * @param array $record Record.
	 * @return array
	 */
	protected static function clean( array $record ) {
		return array_filter(
			$record,
			static function ( $value ) {
				return null !== $value && '' !== $value && 0 !== $value;
			}
		);
	}

	/**
	 * Image id or URL, whichever is available.
	 *
	 * @param string $id  Attachment id.
	 * @param string $url Image URL.
	 * @return int|string|null
	 */
	protected static function image( $id, $url ) {
		if ( (int) $id > 0 ) {
			return (int) $id;
		}
		return '' !== $url ? $url : null;
	}

	/**
	 * Converts a regular expression written for a path without the leading
	 * slash ("^blog/(.*)") to SEO Fixer's form, which matches "/blog/…".
	 *
	 * @param string $pattern Pattern.
	 * @return string
	 */
	protected static function regex_with_slash( $pattern ) {
		$pattern = trim( (string) $pattern );
		if ( 0 === strpos( $pattern, '^' ) && '/' !== substr( $pattern, 1, 1 ) ) {
			return '^/' . substr( $pattern, 1 );
		}
		return $pattern;
	}

	/**
	 * Redirect status SEO Fixer supports (451 "unavailable for legal reasons" becomes 410).
	 *
	 * @param mixed $status Status code.
	 * @return int
	 */
	protected static function status( $status ) {
		$status = (int) $status;
		if ( 451 === $status ) {
			return 410;
		}
		return in_array( $status, array( 301, 302, 307, 410 ), true ) ? $status : 301;
	}
}
