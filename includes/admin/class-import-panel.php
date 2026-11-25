<?php
/**
 * Tools → "Move from another SEO plugin": pick a plugin, see what would be
 * imported, then import (with progress, and undo under SEO Health → Changes).
 *
 * @package SeoFixer
 */

namespace Seofx\Admin;

use Seofx\Import\Importer;
use Seofx\Import\Source;
use Seofx\Meta\Context;
use Seofx\Meta\Variables;
use Seofx\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Import card and its requests.
 */
final class Import_Panel {

	/**
	 * Nonce action for starting and continuing a job.
	 */
	const NONCE = 'seofx_import';

	/**
	 * Seconds of work per request without JavaScript (well under PHP's usual 30 s limit).
	 */
	const NOJS_SECONDS = 15;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_post_seofx_import', array( __CLASS__, 'handle_run' ) );
		add_action( 'wp_ajax_seofx_import', array( __CLASS__, 'ajax_run' ) );
		add_action( 'admin_post_seofx_import_reset', array( __CLASS__, 'handle_reset' ) );
	}

	/**
	 * Address of the card.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'admin.php?page=' . Admin::SLUG . '-tools' ) . '#seofx-import';
	}

	/**
	 * Plain-English name of each field.
	 *
	 * @return array<string, string>
	 */
	public static function field_labels() {
		return array(
			'title'              => __( 'SEO titles', 'seo-fixer' ),
			'description'        => __( 'Meta descriptions', 'seo-fixer' ),
			'noindex'            => __( 'Pages hidden from (or shown in) search results', 'seo-fixer' ),
			'nofollow'           => __( '“Don’t follow links” settings', 'seo-fixer' ),
			'canonical'          => __( 'Canonical addresses', 'seo-fixer' ),
			'social_title'       => __( 'Social sharing titles', 'seo-fixer' ),
			'social_description' => __( 'Social sharing descriptions', 'seo-fixer' ),
			'social_image'       => __( 'Social sharing images', 'seo-fixer' ),
			'home_title'         => __( 'Homepage title', 'seo-fixer' ),
			'home_description'   => __( 'Homepage description', 'seo-fixer' ),
			'title_separator'    => __( 'Title separator', 'seo-fixer' ),
			'verify_google'      => __( 'Google Search Console code', 'seo-fixer' ),
			'verify_bing'        => __( 'Bing Webmaster Tools code', 'seo-fixer' ),
			'verify_yandex'      => __( 'Yandex Webmaster code', 'seo-fixer' ),
			'verify_pinterest'   => __( 'Pinterest code', 'seo-fixer' ),
			'redirect'           => __( 'Redirects', 'seo-fixer' ),
		);
	}

	/**
	 * Name of each group the user can choose.
	 *
	 * @return array<string, string>
	 */
	public static function group_labels() {
		return array(
			'text'       => __( 'Titles and descriptions', 'seo-fixer' ),
			'visibility' => __( 'Search visibility: hidden pages, “don’t follow links” and canonical addresses', 'seo-fixer' ),
			'social'     => __( 'Social sharing titles, descriptions and images', 'seo-fixer' ),
			'site'       => __( 'Site settings: homepage title and description, title separator, verification codes', 'seo-fixer' ),
			'redirects'  => __( 'Redirects', 'seo-fixer' ),
		);
	}

	/**
	 * Renders the card.
	 *
	 * @return void
	 */
	public static function render() {
		$state = Importer::state();
		?>
		<section class="seofx-card" id="seofx-import" aria-labelledby="seofx-import-title">
			<header class="seofx-card__header">
				<h2 class="seofx-card__title" id="seofx-import-title"><?php esc_html_e( 'Move from another SEO plugin', 'seo-fixer' ); ?></h2>
				<p class="seofx-card__desc"><?php esc_html_e( 'Bring your titles, descriptions and settings over from Yoast SEO, Rank Math, All in One SEO, SEOPress or The SEO Framework. You see what will happen before anything changes, nothing you already set in SEO Fixer is replaced, and the import can be undone.', 'seo-fixer' ); ?></p>
			</header>
			<?php
			if ( ! $state ) {
				self::render_sources();
			} elseif ( 'done' !== $state['phase'] ) {
				self::render_running( $state );
			} elseif ( 'check' === $state['mode'] ) {
				self::render_report( $state );
			} else {
				self::render_result( $state );
			}
			?>
		</section>
		<?php
	}

	/**
	 * The plugins that have data here.
	 *
	 * @return void
	 */
	private static function render_sources() {
		$found = array();
		foreach ( Importer::sources() as $source ) {
			if ( $source->has_data() ) {
				$found[] = $source;
			}
		}

		if ( array() === $found ) {
			echo '<p class="seofx-empty">' . esc_html__( 'No data from another SEO plugin was found on this site, so there is nothing to bring over.', 'seo-fixer' ) . '</p>';
			return;
		}
		?>
		<div class="seofx-table-wrap">
			<table class="seofx-table seofx-table--list seofx-import-sources">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Plugin', 'seo-fixer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'What it has', 'seo-fixer' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'seo-fixer' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $found as $source ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $source->name() ); ?></strong>
								<?php $source->is_active() ? UI::badge( __( 'Active', 'seo-fixer' ), 'info' ) : UI::badge( __( 'Not active', 'seo-fixer' ), 'neutral' ); ?>
							</td>
							<td><?php echo esc_html( self::describe_source( $source ) ); ?></td>
							<td class="seofx-row-actions">
								<?php
								self::job_form(
									$source->id(),
									'check',
									array(),
									__( 'Check what will be imported', 'seo-fixer' ),
									'secondary'
								);
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="seofx-card__desc"><?php esc_html_e( 'Checking only reads. The other plugin can stay active while you check and import; its own data is never changed.', 'seo-fixer' ); ?></p>
		<?php
	}

	/**
	 * "120 pages, 4 categories and tags, 15 redirects, site settings".
	 *
	 * @param Source $source Source.
	 * @return string
	 */
	private static function describe_source( Source $source ) {
		$parts = array();
		$posts = $source->post_count();
		if ( $posts ) {
			/* translators: %s: number of pages and posts. */
			$parts[] = sprintf( _n( '%s page or post', '%s pages and posts', $posts, 'seo-fixer' ), number_format_i18n( $posts ) );
		}
		$terms = $source->term_count();
		if ( $terms ) {
			/* translators: %s: number of categories and tags. */
			$parts[] = sprintf( _n( '%s category or tag', '%s categories and tags', $terms, 'seo-fixer' ), number_format_i18n( $terms ) );
		}
		$redirects = $source->redirect_count();
		if ( $redirects ) {
			/* translators: %s: number of redirects. */
			$parts[] = sprintf( _n( '%s redirect', '%s redirects', $redirects, 'seo-fixer' ), number_format_i18n( $redirects ) );
		}
		if ( array() !== array_filter( $source->settings() ) ) {
			$parts[] = array() === $parts ? __( 'Site settings', 'seo-fixer' ) : __( 'site settings', 'seo-fixer' );
		}
		return wp_sprintf( '%l', $parts );
	}

	/**
	 * A form that starts (or continues) a job, with its progress bar.
	 *
	 * @param string   $source_id Source id ('' = continue the current job).
	 * @param string   $mode      check|import.
	 * @param string[] $groups    Group checkboxes to show (import only), id => checked.
	 * @param string   $label     Button text.
	 * @param string   $style     primary|secondary.
	 * @param string   $confirm   Confirmation question ('' = none).
	 * @return void
	 */
	private static function job_form( $source_id, $mode, array $groups, $label, $style, $confirm = '' ) {
		?>
		<div class="seofx-import-run">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-seofx-import data-done-url="<?php echo esc_url( self::url() ); ?>"<?php echo '' === $source_id ? ' data-running="1"' : ''; ?>>
				<input type="hidden" name="action" value="seofx_import" />
				<?php wp_nonce_field( self::NONCE ); ?>
				<?php if ( '' !== $source_id ) : ?>
					<input type="hidden" name="source" value="<?php echo esc_attr( $source_id ); ?>" />
					<input type="hidden" name="mode" value="<?php echo esc_attr( $mode ); ?>" />
				<?php endif; ?>
				<?php if ( $groups ) : ?>
					<fieldset class="seofx-import-groups">
						<legend><?php esc_html_e( 'What to import', 'seo-fixer' ); ?></legend>
						<?php foreach ( $groups as $group => $count ) : ?>
							<label class="seofx-check">
								<input type="checkbox" name="groups[]" value="<?php echo esc_attr( $group ); ?>" <?php checked( $count > 0 ); ?> <?php disabled( 0 === $count ); ?> />
								<span>
									<?php echo esc_html( self::group_labels()[ $group ] ); ?>
									<span class="seofx-muted">
										<?php
										/* translators: %s: number of values. */
										echo esc_html( sprintf( __( '(%s to import)', 'seo-fixer' ), number_format_i18n( $count ) ) );
										?>
									</span>
								</span>
							</label>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>
				<button type="submit" class="seofx-btn seofx-btn--<?php echo esc_attr( $style ); ?><?php echo 'secondary' === $style ? ' seofx-btn--sm' : ''; ?>"<?php echo '' !== $confirm ? ' data-seofx-confirm="' . esc_attr( $confirm ) . '"' : ''; ?>><?php echo esc_html( $label ); ?></button>
			</form>
			<div class="seofx-progress" data-seofx-import-progress hidden>
				<div class="seofx-progress__track" role="progressbar" aria-label="<?php esc_attr_e( 'Import progress', 'seo-fixer' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
					<span class="seofx-progress__fill" style="width: 0%"></span>
				</div>
				<p class="seofx-progress__label" aria-live="polite"></p>
			</div>
		</div>
		<?php
	}

	/**
	 * A job that hasn't finished (closed tab, no JavaScript, or a slow site).
	 *
	 * @param array $state Job.
	 * @return void
	 */
	private static function render_running( array $state ) {
		$source   = Importer::source( $state['source'] );
		$progress = Importer::progress( $state );
		$message  = 'check' === $state['mode']
			/* translators: 1: plugin name, 2: percentage done. */
			? __( 'Checking what %1$s has… %2$s%% done.', 'seo-fixer' )
			/* translators: 1: plugin name, 2: percentage done. */
			: __( 'Importing from %1$s… %2$s%% done. What has been imported so far is kept.', 'seo-fixer' );
		UI::callout( sprintf( $message, $source->name(), number_format_i18n( $progress['percent'] ) ), 'info', 'status' );
		echo '<div class="seofx-import-actions">';
		self::job_form( '', $state['mode'], array(), __( 'Continue', 'seo-fixer' ), 'primary' );
		self::reset_form( __( 'Stop', 'seo-fixer' ) );
		echo '</div>';
	}

	/**
	 * Result of a check: what would happen, with examples and the import form.
	 *
	 * @param array $state Job.
	 * @return void
	 */
	private static function render_report( array $state ) {
		$source = Importer::source( $state['source'] );
		$total  = Importer::written( $state );
		?>
		<h3 class="seofx-import-heading">
			<?php
			/* translators: %s: plugin name, e.g. "Yoast SEO". */
			echo esc_html( sprintf( __( 'What SEO Fixer found in %s', 'seo-fixer' ), $source->name() ) );
			?>
		</h3>
		<?php
		if ( 0 === $total ) {
			UI::callout( __( 'There is nothing new to import: everything is either already set in SEO Fixer or the same as what SEO Fixer does automatically.', 'seo-fixer' ), 'good', 'status' );
		}
		self::render_counts( $state, true );

		if ( $state['simplified'] ) {
			UI::callout(
				sprintf(
					/* translators: 1: number of titles or descriptions, 2: plugin name. */
					_n( '%1$s title or description uses a variable from %2$s that SEO Fixer doesn’t have (such as a custom field). The variable will be left out, and the rest of the text is kept.', '%1$s titles or descriptions use a variable from %2$s that SEO Fixer doesn’t have (such as a custom field). The variable will be left out, and the rest of the text is kept.', $state['simplified'], 'seo-fixer' ),
					number_format_i18n( $state['simplified'] ),
					$source->name()
				),
				'info'
			);
		}
		if ( $state['focus'] ) {
			UI::callout(
				sprintf(
					/* translators: %s: number of focus keyphrases. */
					_n( '%s focus keyphrase won’t be imported. SEO Fixer doesn’t use focus keyphrases: it checks every page’s title, description, headings, images and links instead.', '%s focus keyphrases won’t be imported. SEO Fixer doesn’t use focus keyphrases: it checks every page’s title, description, headings, images and links instead.', $state['focus'], 'seo-fixer' ),
					number_format_i18n( $state['focus'] )
				),
				'info'
			);
		}

		self::render_samples( $state, $source );

		if ( $total > 0 ) {
			$groups = array();
			foreach ( array_keys( Importer::GROUPS ) as $group ) {
				$count = Importer::written( $state, Importer::GROUPS[ $group ] );
				if ( $count > 0 || isset( self::group_counts( $state )[ $group ] ) ) {
					$groups[ $group ] = $count;
				}
			}
			?>
			<p class="seofx-card__desc"><?php esc_html_e( 'Nothing you already set in SEO Fixer is replaced. The other plugin’s data stays as it is, so you can switch back at any time. You can undo the import under SEO Health → Changes.', 'seo-fixer' ); ?></p>
			<?php
		}
		?>
		<div class="seofx-import-actions">
			<?php
			if ( $total > 0 ) {
				self::job_form(
					$source->id(),
					'import',
					$groups,
					__( 'Import now', 'seo-fixer' ),
					'primary',
					__( 'Import now? Nothing you set in SEO Fixer is replaced, and you can undo it afterwards.', 'seo-fixer' )
				);
			}
			self::reset_form( $total > 0 ? __( 'Cancel', 'seo-fixer' ) : __( 'Close', 'seo-fixer' ) );
			?>
		</div>
		<?php
	}

	/**
	 * Groups that came up in the check at all (any outcome).
	 *
	 * @param array $state Job.
	 * @return array<string, true>
	 */
	private static function group_counts( array $state ) {
		$groups = array();
		foreach ( array_keys( $state['counts'] ) as $field ) {
			$groups[ Importer::group_of( $field ) ] = true;
		}
		return $groups;
	}

	/**
	 * Table of outcomes per field.
	 *
	 * @param array $state Job.
	 * @param bool  $check Whether this is a check (future tense) or the result.
	 * @return void
	 */
	private static function render_counts( array $state, $check ) {
		if ( array() === $state['counts'] ) {
			return;
		}
		$labels = self::field_labels();
		$cell   = static function ( $outcomes, $key ) {
			$value = isset( $outcomes[ $key ] ) ? (int) $outcomes[ $key ] : 0;
			return $value ? number_format_i18n( $value ) : '–';
		};
		?>
		<div class="seofx-table-wrap">
			<table class="seofx-table seofx-table--list seofx-import-counts">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'What', 'seo-fixer' ); ?></th>
						<th scope="col"><?php echo $check ? esc_html__( 'Will be imported', 'seo-fixer' ) : esc_html__( 'Imported', 'seo-fixer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Kept as set in SEO Fixer', 'seo-fixer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Not needed', 'seo-fixer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Can’t be used', 'seo-fixer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $labels as $field => $label ) : ?>
						<?php
						if ( ! isset( $state['counts'][ $field ] ) ) {
							continue;
						}
						$outcomes = $state['counts'][ $field ];
						?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td><strong><?php echo esc_html( $cell( $outcomes, 'new' ) ); ?></strong></td>
							<td><?php echo esc_html( $cell( $outcomes, 'kept' ) ); ?></td>
							<td><?php echo esc_html( $cell( $outcomes, 'same' ) ); ?></td>
							<td><?php echo esc_html( $cell( $outcomes, 'bad' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="seofx-muted seofx-import-legend">
			<?php esc_html_e( '“Not needed” means the value is the same as what SEO Fixer already does by default. “Can’t be used” means, for example, an image that isn’t in your Media Library, an address that isn’t valid, or a redirect for your homepage.', 'seo-fixer' ); ?>
		</p>
		<?php
	}

	/**
	 * Examples of converted titles and descriptions.
	 *
	 * @param array  $state  Job.
	 * @param Source $source Source.
	 * @return void
	 */
	private static function render_samples( array $state, Source $source ) {
		if ( array() === $state['samples'] ) {
			return;
		}
		$labels = self::field_labels();
		?>
		<details class="seofx-import-samples" open>
			<summary><?php esc_html_e( 'Examples', 'seo-fixer' ); ?></summary>
			<div class="seofx-table-wrap">
				<table class="seofx-table seofx-table--list">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Page', 'seo-fixer' ); ?></th>
							<th scope="col">
								<?php
								/* translators: %s: plugin name, e.g. "Yoast SEO". */
								echo esc_html( sprintf( __( 'In %s', 'seo-fixer' ), $source->name() ) );
								?>
							</th>
							<th scope="col"><?php esc_html_e( 'Shows as, with SEO Fixer', 'seo-fixer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $state['samples'] as $sample ) : ?>
							<?php
							$object = 'post' === $sample['type'] ? get_post( (int) $sample['id'] ) : get_term( (int) $sample['id'] );
							if ( ! $object instanceof \WP_Post && ! $object instanceof \WP_Term ) {
								continue;
							}
							$is_post = $object instanceof \WP_Post;
							$name    = $is_post ? get_the_title( $object ) : $object->name;
							$edit    = $is_post ? get_edit_post_link( $object->ID, 'raw' ) : get_edit_term_link( $object->term_id, $object->taxonomy );
							$context = $is_post ? Context::for_post( $object ) : Context::for_term( $object );
							?>
							<tr>
								<td>
									<?php if ( $edit ) : ?>
										<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( '' !== $name ? $name : __( '(no title)', 'seo-fixer' ) ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $name ); ?>
									<?php endif; ?>
									<br /><span class="seofx-muted"><?php echo esc_html( $labels[ $sample['field'] ] ); ?></span>
								</td>
								<td><code class="seofx-import-raw"><?php echo esc_html( $sample['from'] ); ?></code></td>
								<td><?php echo esc_html( Variables::replace( $sample['to'], $context ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</details>
		<?php
	}

	/**
	 * Result of an import.
	 *
	 * @param array $state Job.
	 * @return void
	 */
	private static function render_result( array $state ) {
		$source  = Importer::source( $state['source'] );
		$written = Importer::written( $state );

		if ( $written ) {
			$parts = array();
			if ( $state['changed']['posts'] ) {
				/* translators: %s: number of pages and posts. */
				$parts[] = sprintf( _n( '%s page or post', '%s pages and posts', $state['changed']['posts'], 'seo-fixer' ), number_format_i18n( $state['changed']['posts'] ) );
			}
			if ( $state['changed']['terms'] ) {
				/* translators: %s: number of categories and tags. */
				$parts[] = sprintf( _n( '%s category or tag', '%s categories and tags', $state['changed']['terms'], 'seo-fixer' ), number_format_i18n( $state['changed']['terms'] ) );
			}
			$settings = Importer::written( $state, Importer::GROUPS['site'] );
			if ( $settings ) {
				/* translators: %s: number of settings. */
				$parts[] = sprintf( _n( '%s site setting', '%s site settings', $settings, 'seo-fixer' ), number_format_i18n( $settings ) );
			}
			$redirects = Importer::written( $state, array( 'redirect' ) );
			if ( $redirects ) {
				/* translators: %s: number of redirects. */
				$parts[] = sprintf( _n( '%s redirect', '%s redirects', $redirects, 'seo-fixer' ), number_format_i18n( $redirects ) );
			}
			UI::callout(
				sprintf(
					/* translators: 1: plugin name, 2: list such as "120 pages and posts, 2 site settings". */
					__( 'Done. Imported from %1$s: %2$s.', 'seo-fixer' ),
					$source->name(),
					wp_sprintf( '%l', $parts )
				),
				'good',
				'status',
				Health_Report::url( 'changes' ),
				__( 'See or undo it under Changes', 'seo-fixer' )
			);
		} else {
			UI::callout( __( 'Nothing was imported: everything was already set in SEO Fixer.', 'seo-fixer' ), 'info', 'status' );
		}

		self::render_counts( $state, false );

		if ( ! empty( $state['rescan'] ) ) {
			UI::callout( __( 'A new health scan has started in the background, so your SEO Health score reflects the imported titles and descriptions.', 'seo-fixer' ), 'info', '', admin_url( 'admin.php?page=' . Admin::SLUG ), __( 'Open SEO Health', 'seo-fixer' ) );
		}
		if ( $source->is_active() ) {
			UI::callout(
				sprintf(
					/* translators: %s: plugin name, e.g. "Yoast SEO". */
					__( '%s is still active, so SEO Fixer stays paused to avoid duplicate tags. When you’re ready, deactivate it: SEO Fixer takes over right away, and the old plugin’s data stays in place in case you switch back.', 'seo-fixer' ),
					$source->name()
				),
				'warn',
				'',
				admin_url( 'plugins.php' ),
				__( 'Open Plugins', 'seo-fixer' )
			);
		}
		echo '<div class="seofx-import-actions">';
		self::reset_form( __( 'Done', 'seo-fixer' ) );
		echo '</div>';
	}

	/**
	 * Button that forgets the job (imported values stay).
	 *
	 * @param string $label Button text.
	 * @return void
	 */
	private static function reset_form( $label ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="seofx_import_reset" />
			<?php wp_nonce_field( 'seofx_import_reset' ); ?>
			<button type="submit" class="seofx-btn seofx-btn--ghost"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/*
	 * ---------------------------------------------------------------------
	 * Requests.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Starts a job when the request asks for one (nonce already checked).
	 *
	 * @return void
	 */
	private static function maybe_start() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked by the callers.
		if ( empty( $_POST['source'] ) || empty( $_POST['mode'] ) ) {
			return;
		}
		$source = sanitize_key( wp_unslash( $_POST['source'] ) );
		$mode   = sanitize_key( wp_unslash( $_POST['mode'] ) );
		$groups = isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['groups'] ) ) : array();
		// phpcs:enable
		Importer::start( $source, $mode, $groups );
	}

	/**
	 * Without JavaScript: about 20 seconds of work per click.
	 *
	 * @return void
	 */
	public static function handle_run() {
		Security::require_manage();
		check_admin_referer( self::NONCE );

		self::maybe_start();
		if ( ! Importer::run( self::NOJS_SECONDS ) ) {
			UI::flash( __( 'The import is already running in another tab or request. Wait a moment, then click Continue.', 'seo-fixer' ), 'warn' );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * With JavaScript: a few seconds of work per request, with progress.
	 *
	 * @return void
	 */
	public static function ajax_run() {
		if ( ! check_ajax_referer( self::NONCE, '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page and try again.', 'seo-fixer' ) ), 403 );
		}
		if ( ! Security::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'seo-fixer' ) ), 403 );
		}

		self::maybe_start();
		$worked = Importer::run( 4 );
		$state  = Importer::state();
		if ( ! $state ) {
			wp_send_json_error( array( 'message' => __( 'The import couldn’t start. Reload the page and try again.', 'seo-fixer' ) ), 400 );
		}
		wp_send_json_success( Importer::progress( $state ) + array( 'busy' => ! $worked ) );
	}

	/**
	 * Forgets the job.
	 *
	 * @return void
	 */
	public static function handle_reset() {
		Security::require_manage();
		check_admin_referer( 'seofx_import_reset' );
		Importer::reset();
		wp_safe_redirect( self::url() );
		exit;
	}
}
