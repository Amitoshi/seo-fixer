<?php
/**
 * Block editor: the SEO Fixer sidebar (score, live checklist with one-click
 * fixes, Google preview, SEO title and description) and the score in the
 * post summary panel. Plain JavaScript, no build step.
 *
 * @package SeoFixer
 */

namespace Seofx\Editor;

use Seofx\Admin\Admin;
use Seofx\Admin\Health_Report;
use Seofx\Meta\Context;
use Seofx\Meta\Templates;
use Seofx\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Editor sidebar.
 */
final class Sidebar {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'enqueue_block_editor_assets', array( $this, 'assets' ) );
	}

	/**
	 * Loads the sidebar for posts, pages and other content with SEO fields.
	 *
	 * @return void
	 */
	public function assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! array_key_exists( (string) $screen->post_type, Templates::post_types() ) ) {
			return; // Not in the site editor, widgets or content without SEO fields.
		}

		$file = 'assets/editor/sidebar.js';
		wp_enqueue_script(
			'seofx-sidebar',
			SEOFX_URL . $file,
			array( 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-block-editor', 'wp-core-data' ),
			Admin::asset_version( $file ),
			array( 'in_footer' => true )
		);
		wp_set_script_translations( 'seofx-sidebar', 'seo-fixer' );
		wp_enqueue_style( 'seofx-sidebar', SEOFX_URL . 'assets/editor/sidebar.css', array(), Admin::asset_version( 'assets/editor/sidebar.css' ) );

		$post    = get_post();
		$context = $post ? Context::for_post( $post ) : null;
		wp_add_inline_script(
			'seofx-sidebar',
			'window.seofxEditor = ' . wp_json_encode(
				array(
					'titleTemplate' => $context ? Templates::get( Templates::key_for( $context, 'title' ) ) : '',
					'descTemplate'  => $context ? Templates::get( Templates::key_for( $context, 'description' ) ) : '',
					'paused'        => ! Plugin::output_enabled(),
					'reportUrl'     => Health_Report::url( 'pages' ),
					'settingsUrl'   => admin_url( 'admin.php?page=' . Admin::SLUG . '-settings' ),
				)
			) . ';',
			'before'
		);
	}
}
