<?php
/**
 * Live SEO checklist for the post editors: checks the post as it is on
 * screen (unsaved changes included) with the same rules as the site scan,
 * and suggests fixes. Nothing is saved.
 *
 * Endpoint: POST /wp-json/seofx/v1/check
 *
 * @package SeoFixer
 */

namespace Seofx\Editor;

use Seofx\Fixes\Fixer;
use Seofx\Fixes\Generator;
use Seofx\Meta\Context;
use Seofx\Meta\Post_Meta;
use Seofx\Meta\Resolver;
use Seofx\Meta\Templates;
use Seofx\Sanitize;
use Seofx\Scanner\Checks;
use Seofx\Scanner\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Live check.
 */
final class Live_Check {

	/**
	 * REST namespace.
	 */
	const NAMESPACE_V1 = 'seofx/v1';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function routes() {
		$text = array(
			'type'     => 'string',
			'required' => false,
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/check',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => static function ( \WP_REST_Request $request ) {
					return current_user_can( 'edit_post', (int) $request['id'] );
				},
				'args'                => array(
					'id'              => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'title'           => $text,
					'content'         => $text,
					'excerpt'         => $text,
					'slug'            => $text,
					'seo_title'       => $text,
					'seo_description' => $text,
					'featured_media'  => array(
						'type'     => 'integer',
						'required' => false,
					),
					'html'            => array(
						'type'     => 'boolean',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * Runs the check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( \WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || ! array_key_exists( $post->post_type, Templates::post_types() ) ) {
			return new \WP_Error( 'seofx_not_found', __( 'This content can’t be checked.', 'seo-fixer' ), array( 'status' => 404 ) );
		}

		$values = array();
		foreach ( array( 'title', 'content', 'excerpt', 'slug', 'seo_title', 'seo_description', 'featured_media' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$values[ $key ] = $request->get_param( $key );
			}
		}

		$result = self::run( $post, $values );
		if ( $request->get_param( 'html' ) ) {
			ob_start();
			self::render( $result );
			$result['html'] = (string) ob_get_clean();
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Checks a post with on-screen values applied in memory only.
	 *
	 * @param \WP_Post $post   Saved post.
	 * @param array    $values title, content, excerpt, slug, seo_title, seo_description, featured_media (each optional).
	 * @return array
	 */
	public static function run( \WP_Post $post, array $values = array() ) {
		$virtual = clone $post;
		foreach ( array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
		) as $key => $field ) {
			if ( isset( $values[ $key ] ) ) {
				$virtual->{$field} = (string) $values[ $key ];
			}
		}
		if ( isset( $values['slug'] ) && '' !== trim( (string) $values['slug'] ) ) {
			$virtual->post_name = sanitize_title( (string) $values['slug'] );
		} elseif ( '' === $virtual->post_name ) {
			$virtual->post_name = sanitize_title( $virtual->post_title ); // What WordPress will use when it's published.
		}

		// Unsaved SEO fields and featured image, for this request only.
		$overrides = array();
		// Cleaned exactly as saving would, so the preview shows what will be stored.
		if ( isset( $values['seo_title'] ) ) {
			$overrides[ Post_Meta::TITLE ] = Sanitize::template( $values['seo_title'] );
		}
		if ( isset( $values['seo_description'] ) ) {
			$overrides[ Post_Meta::DESCRIPTION ] = Sanitize::template( $values['seo_description'] );
		}
		if ( isset( $values['featured_media'] ) ) {
			$overrides['_thumbnail_id'] = (int) $values['featured_media'] ? (string) (int) $values['featured_media'] : '';
		}
		$filter = static function ( $value, $object_id, $meta_key ) use ( $post, $overrides ) {
			if ( (int) $object_id === $post->ID && array_key_exists( (string) $meta_key, $overrides ) ) {
				return array( $overrides[ $meta_key ] );
			}
			return $value;
		};
		add_filter( 'get_post_metadata', $filter, 10, 3 );

		$check   = Scanner::check( $virtual );
		$context = Context::for_post( $virtual );
		// Drafts show the address they will have once published, not "?p=123".
		$public              = clone $virtual;
		$public->post_status = 'publish';
		$result  = array(
			'score'       => $check['score'],
			'published'   => 'publish' === $post->post_status,
			'hidden'      => ! empty( $check['facts']['noindex'] ),
			'title'       => Resolver::title( $context ),
			'description' => Resolver::description( $context ),
			'url'         => (string) get_permalink( $public ),
			'problems'    => self::problems( $check['issues'], $virtual ),
			'passed'      => self::passed( $check['issues'], ! empty( $check['facts']['noindex'] ) ),
		);

		remove_filter( 'get_post_metadata', $filter, 10 );
		return $result;
	}

	/**
	 * Problems, most serious first, with a ready-made fix where there is one.
	 *
	 * @param array[]  $issues Issues from the check.
	 * @param \WP_Post $post   Post as on screen.
	 * @return array[]
	 */
	private static function problems( array $issues, \WP_Post $post ) {
		$checks   = Checks::page_checks();
		$guidance = Checks::guidance();
		$order    = array_flip( array( 'critical', 'important', 'minor', 'hint' ) );
		$tones    = array(
			'critical'  => 'bad',
			'important' => 'warn',
			'minor'     => 'info',
			'hint'      => 'neutral',
		);

		$list = array();
		foreach ( $issues as $issue ) {
			$id = $issue['id'];
			if ( ! isset( $checks[ $id ] ) ) {
				continue;
			}
			$check = $checks[ $id ];
			$item  = array(
				'id'         => $id,
				'severity'   => $check['severity'],
				'tone'       => $tones[ $check['severity'] ],
				'label'      => $check['label'],
				'text'       => $check['page'],
				'why'        => isset( $guidance[ $id ] ) ? $guidance[ $id ]['why'] . ' ' . $guidance[ $id ]['how'] : '',
				'items'      => isset( $issue['items'] ) ? array_values( array_map( array( __CLASS__, 'item_text' ), (array) $issue['items'] ) ) : array(),
				'fix'        => Fixer::fix_for( $id ),
				'suggestion' => null,
			);

			if ( 'description' === $item['fix'] && ! Fixer::has_own( $post->ID, 'description' ) ) {
				$item['suggestion'] = Generator::description( $post );
			} elseif ( 'title' === $item['fix'] && ! Fixer::has_own( $post->ID, 'title' ) ) {
				$template = Generator::title( $post );
				if ( '' !== $template ) {
					$item['suggestion'] = $template;
					$item['preview']    = Fixer::preview( $template, $post );
				}
			} elseif ( 'alt' === $item['fix'] ) {
				$item['suggestion'] = array();
				foreach ( Generator::images_without_alt( $post ) as $image ) {
					if ( '' !== $image['alt'] ) {
						$item['suggestion'][] = array(
							'src' => $image['src'],
							'alt' => $image['alt'],
						);
					}
				}
			}
			$list[] = $item;
		}

		usort(
			$list,
			static function ( $a, $b ) use ( $order ) {
				return $order[ $a['severity'] ] - $order[ $b['severity'] ];
			}
		);
		return $list;
	}

	/**
	 * Detail item as text.
	 *
	 * @param mixed $item Item.
	 * @return string
	 */
	private static function item_text( $item ) {
		if ( is_array( $item ) && isset( $item['src'] ) ) {
			/* translators: 1: image address, 2: size in KB. */
			return sprintf( __( '%1$s (%2$s KB)', 'seo-fixer' ), $item['src'], number_format_i18n( (int) $item['kb'] ) );
		}
		return is_scalar( $item ) ? (string) $item : '';
	}

	/**
	 * Checks that passed.
	 *
	 * @param array[] $issues Issues.
	 * @param bool    $hidden Whether the page is hidden from search (then only links are checked).
	 * @return string[]
	 */
	private static function passed( array $issues, $hidden ) {
		$failed = array_flip( wp_list_pluck( $issues, 'id' ) );
		$labels = Checks::passed_labels();
		if ( $hidden ) {
			$labels = array_intersect_key( $labels, array( 'broken_links' => true ) );
		}
		return array_values( array_diff_key( $labels, $failed ) );
	}

	/**
	 * Score tone (same bands as the dashboard).
	 *
	 * @param int $score Score.
	 * @return string good|warn|bad
	 */
	public static function tone( $score ) {
		if ( $score >= 80 ) {
			return 'good';
		}
		return $score >= 50 ? 'warn' : 'bad';
	}

	/**
	 * The checklist as HTML (classic editor box).
	 *
	 * @param array $result From run().
	 * @return void
	 */
	public static function render( array $result ) {
		$tone     = self::tone( (int) $result['score'] );
		$captions = array(
			'good' => __( 'Healthy', 'seo-fixer' ),
			'warn' => __( 'Needs work', 'seo-fixer' ),
			'bad'  => __( 'Needs attention', 'seo-fixer' ),
		);
		?>
		<div class="seofx-livecheck__head">
			<span class="seofx-pill seofx-pill--<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( (string) (int) $result['score'] ); ?></span>
			<span>
				<strong><?php echo esc_html( $captions[ $tone ] ); ?></strong>
				<span class="seofx-muted">
					<?php
					echo esc_html(
						$result['published']
							? __( 'SEO score for this page, including changes you haven’t saved yet.', 'seo-fixer' )
							: __( 'SEO score for this page once it’s published.', 'seo-fixer' )
					);
					?>
				</span>
			</span>
		</div>
		<?php if ( $result['problems'] ) : ?>
			<ul class="seofx-checks seofx-livecheck__list">
				<?php foreach ( $result['problems'] as $problem ) : ?>
					<li class="seofx-checks__item seofx-checks__item--<?php echo esc_attr( $problem['tone'] ); ?>">
						<span class="seofx-checks__icon"><?php \Seofx\Admin\UI::icon( \Seofx\Admin\UI::tone_icon( 'neutral' === $problem['tone'] ? 'info' : $problem['tone'] ), 16 ); ?></span>
						<span class="seofx-checks__body">
							<strong><?php echo esc_html( $problem['label'] ); ?></strong>
							<span><?php echo esc_html( $problem['text'] ); ?></span>
							<?php if ( $problem['items'] ) : ?>
								<span class="seofx-livecheck__items"><?php echo esc_html( implode( ', ', array_slice( $problem['items'], 0, 5 ) ) ); ?></span>
							<?php endif; ?>
							<?php if ( is_string( $problem['suggestion'] ) && '' !== $problem['suggestion'] ) : ?>
								<span class="seofx-livecheck__fix">
									<button type="button" class="seofx-btn seofx-btn--secondary seofx-btn--sm" data-seofx-use="<?php echo esc_attr( $problem['fix'] ); ?>" data-value="<?php echo esc_attr( $problem['suggestion'] ); ?>">
										<?php echo 'title' === $problem['fix'] ? esc_html__( 'Leave the site name off', 'seo-fixer' ) : esc_html__( 'Use a suggested description', 'seo-fixer' ); ?>
									</button>
									<span class="seofx-muted"><?php echo esc_html( isset( $problem['preview'] ) ? $problem['preview'] : $problem['suggestion'] ); ?></span>
								</span>
							<?php endif; ?>
							<?php \Seofx\Admin\UI::why( $problem['why'] ); ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<?php \Seofx\Admin\UI::callout( __( 'Everything checks out. Nice work!', 'seo-fixer' ), 'good' ); ?>
		<?php endif; ?>
		<?php if ( $result['passed'] ) : ?>
			<details class="seofx-livecheck__passed">
				<summary>
					<?php
					/* translators: %d: number of passed checks. */
					echo esc_html( sprintf( _n( '%d check passed', '%d checks passed', count( $result['passed'] ), 'seo-fixer' ), count( $result['passed'] ) ) );
					?>
				</summary>
				<ul>
					<?php foreach ( $result['passed'] as $label ) : ?>
						<li><?php echo esc_html( $label ); ?></li>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endif; ?>
		<?php
	}
}
