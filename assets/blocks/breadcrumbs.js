/**
 * SEO Fixer — "Breadcrumbs" block (editor side, no build step).
 *
 * The block is rendered by PHP (Seofx\Schema\Breadcrumbs::render_block); the
 * editor shows an example trail through ServerSideRender.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'seo-fixer/breadcrumbs', {
		apiVersion: 3,
		title: __( 'Breadcrumbs', 'seo-fixer' ),
		description: __( 'Shows where the visitor is, for example Home › Bread › Sourdough.', 'seo-fixer' ),
		category: 'theme',
		icon: 'arrow-right-alt2',
		supports: {
			html: false,
			align: [ 'wide', 'full' ],
		},
		edit: function () {
			return el( 'div', useBlockProps(), el( ServerSideRender, { block: 'seo-fixer/breadcrumbs' } ) );
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
