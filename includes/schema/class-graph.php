<?php
/**
 * Structured data (JSON-LD): one connected graph per page.
 *
 * Always: the site's owner (Organization, LocalBusiness or Person) and WebSite.
 * Per page: WebPage (or CollectionPage/ProfilePage/SearchResultsPage), BreadcrumbList,
 * Article for posts, and any extra type chosen in the editor (software app,
 * product, FAQ). Nodes reference each other by @id, the way Google recommends.
 *
 * @package SeoFixer
 */

namespace Seofx\Schema;

use Seofx\Indexing\Indexing;
use Seofx\Meta\Context;
use Seofx\Meta\Images;
use Seofx\Meta\Post_Meta;
use Seofx\Meta\Resolver;
use Seofx\Options;
use Seofx\Presets;

defined( 'ABSPATH' ) || exit;

/**
 * JSON-LD graph builder.
 */
final class Graph {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_head', array( $this, 'print_graph' ), 6 );
	}

	/**
	 * SoftwareApplication categories Google accepts, with plain labels.
	 *
	 * @return array<string, string>
	 */
	public static function software_categories() {
		return array(
			'BusinessApplication'      => __( 'Business', 'seo-fixer' ),
			'DesignApplication'        => __( 'Design', 'seo-fixer' ),
			'DeveloperApplication'     => __( 'Developer tools', 'seo-fixer' ),
			'EducationalApplication'   => __( 'Education', 'seo-fixer' ),
			'EntertainmentApplication' => __( 'Entertainment', 'seo-fixer' ),
			'FinanceApplication'       => __( 'Finance', 'seo-fixer' ),
			'GameApplication'          => __( 'Game', 'seo-fixer' ),
			'HealthApplication'        => __( 'Health & fitness', 'seo-fixer' ),
			'LifestyleApplication'     => __( 'Lifestyle', 'seo-fixer' ),
			'MultimediaApplication'    => __( 'Photo, video & music', 'seo-fixer' ),
			'SecurityApplication'      => __( 'Security', 'seo-fixer' ),
			'ShoppingApplication'      => __( 'Shopping', 'seo-fixer' ),
			'SocialNetworkingApplication' => __( 'Social', 'seo-fixer' ),
			'TravelApplication'        => __( 'Travel', 'seo-fixer' ),
			'UtilitiesApplication'     => __( 'Utilities', 'seo-fixer' ),
		);
	}

	/**
	 * Address of the page described by a context.
	 *
	 * @param Context $context Context.
	 * @return string
	 */
	public static function page_url( Context $context ) {
		switch ( $context->type ) {
			case 'singular':
				$url = wp_get_canonical_url( $context->object );
				return $url ? $url : (string) get_permalink( $context->object );
			case 'term':
				$custom = Indexing::custom_canonical( $context->object );
				if ( '' !== $custom ) {
					return $custom;
				}
				$link = get_term_link( $context->object );
				return is_wp_error( $link ) ? home_url( '/' ) : Indexing::paged( $link, $context->paged );
			case 'author':
				return Indexing::paged( get_author_posts_url( $context->object->ID ), $context->paged );
			case 'archive':
				$link = get_post_type_archive_link( $context->post_type );
				return $link ? Indexing::paged( $link, $context->paged ) : home_url( '/' );
			case 'home':
				return Indexing::paged( home_url( '/' ), $context->paged );
			case 'search':
				return (string) get_search_link();
			case 'date':
				$year  = (int) get_query_var( 'year' );
				$month = (int) get_query_var( 'monthnum' );
				if ( is_day() ) {
					$link = get_day_link( $year, $month, (int) get_query_var( 'day' ) );
				} elseif ( is_month() ) {
					$link = get_month_link( $year, $month );
				} else {
					$link = get_year_link( $year );
				}
				return Indexing::paged( $link, $context->paged );
		}
		return home_url( '/' );
	}

	/**
	 * Builds the graph for a context.
	 *
	 * @param Context $context Context.
	 * @return array[] Nodes.
	 */
	public static function build( Context $context ) {
		if ( 'other' === $context->type ) {
			return array();
		}

		$nodes   = array();
		$nodes[] = self::owner();
		$nodes[] = self::website();

		if ( '404' !== $context->type ) {
			$url     = self::page_url( $context );
			$image   = Images::for_context( $context );
			$nodes[] = self::webpage( $context, $url, $image );

			if ( $image ) {
				$nodes[] = self::image_object( $url . '#primaryimage', $image );
			}
			if ( Breadcrumbs::has_schema( $context ) ) {
				$nodes[] = self::breadcrumb_list( $context, $url );
			}
			if ( 'singular' === $context->type ) {
				$nodes = array_merge( $nodes, self::post_nodes( $context->object, $url, $image ) );
			}
			if ( 'author' === $context->type ) {
				$nodes[] = self::author( $context->object );
			}
		}

		/**
		 * Filters the structured data graph.
		 *
		 * @param array[] $nodes   Graph nodes.
		 * @param Context $context Page context.
		 */
		return array_values( array_filter( (array) apply_filters( 'seofx_schema_graph', $nodes, $context ) ) );
	}

	/**
	 * Prints the JSON-LD script.
	 *
	 * @return void
	 */
	public function print_graph() {
		$nodes = self::build( Context::current() );
		if ( array() === $nodes ) {
			return;
		}

		$json = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $nodes,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
		);

		if ( $json ) {
			// JSON_HEX_TAG turns < and > into </>, so the script tag cannot be closed early.
			echo '<script type="application/ld+json" class="seofx-schema">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with JSON_HEX_TAG.
		}
	}

	/**
	 * @id of the site's owner node.
	 *
	 * @return string
	 */
	public static function owner_id() {
		return home_url( '/' ) . ( 'person' === Options::get( 'entity_type' ) ? '#person' : '#organization' );
	}

	/**
	 * Organization, LocalBusiness or Person behind the site.
	 *
	 * @return array
	 */
	private static function owner() {
		$name    = trim( (string) Options::get( 'entity_name' ) );
		$name    = '' !== $name ? $name : wp_strip_all_tags( get_bloginfo( 'name' ) );
		$logo_id = (int) Options::get( 'entity_logo' );
		$logo    = $logo_id ? Images::from_attachment( $logo_id ) : null;
		$same_as = self::same_as();

		if ( 'person' === Options::get( 'entity_type' ) ) {
			$node = array(
				'@type' => 'Person',
				'@id'   => self::owner_id(),
				'name'  => $name,
				'url'   => home_url( '/' ),
			);
			if ( $logo ) {
				$node['image'] = self::image_object( home_url( '/' ) . '#personlogo', $logo, $name );
			}
		} else {
			$local = Options::get( 'local_business' ) && Presets::is_local_type( (string) Options::get( 'site_type' ) );
			$node  = array(
				'@type' => $local ? (string) Options::get( 'local_type' ) : 'Organization',
				'@id'   => self::owner_id(),
				'name'  => $name,
				'url'   => home_url( '/' ),
			);
			if ( $logo ) {
				$node['logo']  = self::image_object( home_url( '/' ) . '#logo', $logo, $name );
				$node['image'] = array( '@id' => home_url( '/' ) . '#logo' );
			}
			if ( $local ) {
				$node = array_merge( $node, self::local_details() );
			}
		}

		if ( $same_as ) {
			$node['sameAs'] = $same_as;
		}
		return $node;
	}

	/**
	 * Address, phone and opening hours of a local business.
	 *
	 * @return array
	 */
	private static function local_details() {
		$details = array();
		$address = array_filter(
			array(
				'streetAddress'   => (string) Options::get( 'local_street' ),
				'addressLocality' => (string) Options::get( 'local_city' ),
				'addressRegion'   => (string) Options::get( 'local_region' ),
				'postalCode'      => (string) Options::get( 'local_postcode' ),
				'addressCountry'  => (string) Options::get( 'local_country' ),
			),
			'strlen'
		);
		if ( $address ) {
			$details['address'] = array( '@type' => 'PostalAddress' ) + $address;
		}

		$phone = (string) Options::get( 'local_phone' );
		if ( '' !== $phone ) {
			$details['telephone'] = $phone;
		}

		$hours = self::opening_hours( (array) Options::get( 'local_hours' ) );
		if ( $hours ) {
			$details['openingHoursSpecification'] = $hours;
		}
		return $details;
	}

	/**
	 * Opening hours grouped by identical times.
	 *
	 * @param array $hours day => {open, close}.
	 * @return array[]
	 */
	public static function opening_hours( array $hours ) {
		$names  = array(
			'mon' => 'Monday',
			'tue' => 'Tuesday',
			'wed' => 'Wednesday',
			'thu' => 'Thursday',
			'fri' => 'Friday',
			'sat' => 'Saturday',
			'sun' => 'Sunday',
		);
		$groups = array();
		foreach ( $names as $day => $name ) {
			if ( empty( $hours[ $day ]['open'] ) || empty( $hours[ $day ]['close'] ) ) {
				continue;
			}
			$key                         = $hours[ $day ]['open'] . '-' . $hours[ $day ]['close'];
			$groups[ $key ]['days'][]    = $name;
			$groups[ $key ]['opens']     = $hours[ $day ]['open'];
			$groups[ $key ]['closes']    = $hours[ $day ]['close'];
		}

		$specs = array();
		foreach ( $groups as $group ) {
			$specs[] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => $group['days'],
				'opens'     => $group['opens'],
				'closes'    => $group['closes'],
			);
		}
		return $specs;
	}

	/**
	 * Social profile links.
	 *
	 * @return string[]
	 */
	private static function same_as() {
		$links = array();
		foreach ( array( 'facebook', 'instagram', 'x', 'linkedin', 'youtube', 'tiktok', 'pinterest' ) as $network ) {
			$url = (string) Options::get( 'social_' . $network );
			if ( '' !== $url ) {
				$links[] = $url;
			}
		}
		$other = trim( (string) Options::get( 'social_other' ) );
		if ( '' !== $other ) {
			$links = array_merge( $links, explode( "\n", $other ) );
		}
		return array_values( array_unique( $links ) );
	}

	/**
	 * WebSite node (gives Google the site name).
	 *
	 * @return array
	 */
	private static function website() {
		return array(
			'@type'      => 'WebSite',
			'@id'        => home_url( '/' ) . '#website',
			'url'        => home_url( '/' ),
			'name'       => wp_strip_all_tags( get_bloginfo( 'name' ) ),
			'publisher'  => array( '@id' => self::owner_id() ),
			'inLanguage' => get_bloginfo( 'language' ),
		);
	}

	/**
	 * WebPage-type node for the current page.
	 *
	 * @param Context    $context Context.
	 * @param string     $url     Page URL.
	 * @param array|null $image   Representative image.
	 * @return array
	 */
	private static function webpage( Context $context, $url, $image ) {
		$types = array(
			'term'    => 'CollectionPage',
			'archive' => 'CollectionPage',
			'date'    => 'CollectionPage',
			'author'  => 'ProfilePage',
			'search'  => 'SearchResultsPage',
		);
		$node  = array(
			'@type'      => isset( $types[ $context->type ] ) ? $types[ $context->type ] : 'WebPage',
			'@id'        => $url . '#webpage',
			'url'        => $url,
			'name'       => Resolver::title( $context ),
			'isPartOf'   => array( '@id' => home_url( '/' ) . '#website' ),
			'inLanguage' => get_bloginfo( 'language' ),
		);

		$description = Resolver::description( $context );
		if ( '' !== $description ) {
			$node['description'] = $description;
		}
		if ( $context->object instanceof \WP_Post ) {
			$node['datePublished'] = (string) get_post_time( 'c', true, $context->object );
			$node['dateModified']  = (string) get_post_modified_time( 'c', true, $context->object );
		}
		if ( 'home' === $context->type || ( $context->object instanceof \WP_Post && 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $context->object->ID ) ) {
			$node['about'] = array( '@id' => self::owner_id() );
		}
		if ( 'author' === $context->type ) {
			$node['mainEntity'] = array( '@id' => self::person_id( $context->object->ID ) );
		}
		if ( $image ) {
			$node['primaryImageOfPage'] = array( '@id' => $url . '#primaryimage' );
		}
		if ( Breadcrumbs::has_schema( $context ) ) {
			$node['breadcrumb'] = array( '@id' => $url . '#breadcrumb' );
		}
		return $node;
	}

	/**
	 * ImageObject node.
	 *
	 * @param string $id      @id.
	 * @param array  $image   Image data.
	 * @param string $caption Optional caption.
	 * @return array
	 */
	private static function image_object( $id, array $image, $caption = '' ) {
		$node = array(
			'@type'      => 'ImageObject',
			'@id'        => $id,
			'url'        => $image['url'],
			'contentUrl' => $image['url'],
		);
		if ( $image['width'] && $image['height'] ) {
			$node['width']  = $image['width'];
			$node['height'] = $image['height'];
		}
		$caption = '' !== $caption ? $caption : $image['alt'];
		if ( '' !== $caption ) {
			$node['caption'] = $caption;
		}
		return $node;
	}

	/**
	 * BreadcrumbList node.
	 *
	 * @param Context $context Context.
	 * @param string  $url     Page URL.
	 * @return array
	 */
	private static function breadcrumb_list( Context $context, $url ) {
		$items = array();
		foreach ( Breadcrumbs::trail( $context ) as $index => $crumb ) {
			$item = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => $crumb['name'],
			);
			if ( '' !== $crumb['url'] ) {
				$item['item'] = $crumb['url'];
			}
			$items[] = $item;
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $url . '#breadcrumb',
			'itemListElement' => $items,
		);
	}

	/**
	 * @id of a post author.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private static function person_id( $user_id ) {
		return home_url( '/' ) . '#/schema/person/' . md5( 'seofx-author-' . $user_id );
	}

	/**
	 * Person node for a post author.
	 *
	 * @param \WP_User $user User.
	 * @return array
	 */
	private static function author( \WP_User $user ) {
		$node = array(
			'@type' => 'Person',
			'@id'   => self::person_id( $user->ID ),
			'name'  => $user->display_name,
			'url'   => get_author_posts_url( $user->ID ),
		);
		if ( '' !== (string) $user->user_url ) {
			$node['sameAs'] = array( $user->user_url );
		}
		return $node;
	}

	/**
	 * Nodes for one post: Article (for posts) and the extra type chosen in the editor.
	 *
	 * @param \WP_Post   $post  Post.
	 * @param string     $url   Page URL.
	 * @param array|null $image Representative image.
	 * @return array[]
	 */
	private static function post_nodes( \WP_Post $post, $url, $image ) {
		$nodes = array();

		if ( 'post' === $post->post_type ) {
			$author  = get_userdata( (int) $post->post_author );
			$article = array(
				'@type'            => (string) Options::get( 'article_type' ),
				'@id'              => $url . '#article',
				'isPartOf'         => array( '@id' => $url . '#webpage' ),
				'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
				'headline'         => wp_strip_all_tags( get_the_title( $post ) ),
				'datePublished'    => (string) get_post_time( 'c', true, $post ),
				'dateModified'     => (string) get_post_modified_time( 'c', true, $post ),
				'publisher'        => array( '@id' => self::owner_id() ),
				'inLanguage'       => get_bloginfo( 'language' ),
				'wordCount'        => str_word_count( \Seofx\Text::plain( $post->post_content ) ),
			);
			if ( $author ) {
				$article['author'] = array( '@id' => self::person_id( $author->ID ) );
				$nodes[]           = self::author( $author );
			}
			if ( $image ) {
				$article['image'] = array( '@id' => $url . '#primaryimage' );
			}
			$categories = wp_list_pluck( get_the_category( $post->ID ), 'name' );
			if ( $categories ) {
				$article['articleSection'] = array_values( $categories );
			}
			$tags = get_the_tags( $post->ID );
			if ( is_array( $tags ) && $tags ) {
				$article['keywords'] = array_values( wp_list_pluck( $tags, 'name' ) );
			}
			array_unshift( $nodes, $article );
		}

		$extra = self::extra_node( $post, $url, $image );
		if ( $extra ) {
			$nodes[] = $extra;
		}
		return $nodes;
	}

	/**
	 * Software app, product or FAQ node chosen in the editor.
	 *
	 * @param \WP_Post   $post  Post.
	 * @param string     $url   Page URL.
	 * @param array|null $image Representative image.
	 * @return array|null
	 */
	private static function extra_node( \WP_Post $post, $url, $image ) {
		$settings = json_decode( (string) get_post_meta( $post->ID, Post_Meta::SCHEMA, true ), true );
		if ( ! is_array( $settings ) || empty( $settings['type'] ) || empty( $settings[ $settings['type'] ] ) ) {
			return null;
		}
		$data = $settings[ $settings['type'] ];
		$name = wp_strip_all_tags( get_the_title( $post ) );

		switch ( $settings['type'] ) {
			case 'faq':
				$questions = array();
				foreach ( $data as $pair ) {
					$questions[] = array(
						'@type'          => 'Question',
						'name'           => $pair['q'],
						'acceptedAnswer' => array(
							'@type' => 'Answer',
							'text'  => $pair['a'],
						),
					);
				}
				return array(
					'@type'      => 'FAQPage',
					'@id'        => $url . '#faq',
					'isPartOf'   => array( '@id' => $url . '#webpage' ),
					'mainEntity' => $questions,
				);

			case 'software':
				$node = array(
					'@type'               => 'SoftwareApplication',
					'@id'                 => $url . '#software',
					'name'                => $name,
					'url'                 => $url,
					'applicationCategory' => $data['category'],
				);
				if ( '' !== $data['os'] ) {
					$node['operatingSystem'] = $data['os'];
				}
				break;

			case 'product':
				if ( 'product' === $post->post_type && class_exists( 'WooCommerce' ) ) {
					return null; // WooCommerce describes its own products.
				}
				$node = array(
					'@type' => 'Product',
					'@id'   => $url . '#product',
					'name'  => $name,
					'url'   => $url,
				);
				$description = Resolver::description( Context::for_post( $post ) );
				if ( '' !== $description ) {
					$node['description'] = $description;
				}
				if ( '' !== $data['brand'] ) {
					$node['brand'] = array(
						'@type' => 'Brand',
						'name'  => $data['brand'],
					);
				}
				if ( '' !== $data['sku'] ) {
					$node['sku'] = $data['sku'];
				}
				break;

			default:
				return null;
		}

		if ( $image ) {
			$node['image'] = array( '@id' => $url . '#primaryimage' );
		}
		if ( '' !== $data['price'] ) {
			$node['offers'] = array(
				'@type'         => 'Offer',
				'price'         => $data['price'],
				'priceCurrency' => '' !== $data['currency'] ? $data['currency'] : self::default_currency(),
				'url'           => $url,
			);
			if ( isset( $data['availability'] ) ) {
				$node['offers']['availability'] = 'https://schema.org/' . $data['availability'];
			}
		}
		if ( ! empty( $data['rating'] ) && ! empty( $data['rating_count'] ) ) {
			$node['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => $data['rating'],
				'ratingCount' => (int) $data['rating_count'],
			);
		}
		return $node;
	}

	/**
	 * Currency of the site's language/country (USD if unknown).
	 *
	 * @return string
	 */
	public static function default_currency() {
		if ( class_exists( 'NumberFormatter' ) ) {
			$formatter = new \NumberFormatter( get_locale(), \NumberFormatter::CURRENCY );
			$code      = (string) $formatter->getTextAttribute( \NumberFormatter::CURRENCY_CODE );
			if ( preg_match( '/^[A-Z]{3}$/', $code ) && 'XXX' !== $code ) {
				return $code;
			}
		}
		return 'USD';
	}
}
