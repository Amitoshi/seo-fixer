/**
 * SEO Fixer — block editor sidebar and post summary score (no build step).
 *
 * The post is checked on the server with the same rules as the site scan
 * (POST /seofx/v1/check), about 1.5 seconds after typing stops. Fixes from
 * the checklist are ordinary editor changes: they can be undone with the
 * editor's Undo and are saved with the post.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.data || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useState = wp.element.useState;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var components = wp.components;
	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var settings = window.seofxEditor || {};

	// Newer WordPress moved these to wp.editor; older versions have them in wp.editPost.
	var slots = wp.editor && wp.editor.PluginSidebar ? wp.editor : wp.editPost;
	var PluginSidebar = slots.PluginSidebar;
	var PluginSidebarMoreMenuItem = slots.PluginSidebarMoreMenuItem;
	var PluginPostStatusInfo = slots.PluginPostStatusInfo;

	var SIDEBAR = 'seofx-sidebar/seofx-sidebar';
	var STORE = 'seofx/editor';
	var META_TITLE = '_seofx_title';
	var META_DESC = '_seofx_description';

	/* ------------------------------------------------------------------ *
	 * Shared state: the latest check result.
	 * ------------------------------------------------------------------ */
	var store = wp.data.createReduxStore( STORE, {
		reducer: function ( state, action ) {
			state = state || { result: null, loading: false, error: '' };
			if ( 'SET' === action.type ) {
				return Object.assign( {}, state, action.values );
			}
			return state;
		},
		actions: {
			set: function ( values ) {
				return { type: 'SET', values: values };
			},
		},
		selectors: {
			get: function ( state ) {
				return state;
			},
		},
	} );
	wp.data.register( store );

	function openSidebar() {
		var editPost = wp.data.dispatch( 'core/edit-post' );
		if ( editPost && editPost.openGeneralSidebar ) {
			editPost.openGeneralSidebar( SIDEBAR );
			return;
		}
		var areas = wp.data.dispatch( 'core/interface' );
		if ( areas && areas.enableComplementaryArea ) {
			areas.enableComplementaryArea( 'core', SIDEBAR );
		}
	}

	// The "Open the SEO Fixer sidebar" button in the box below the editor.
	document.addEventListener( 'click', function ( event ) {
		if ( event.target && event.target.closest && event.target.closest( '[data-seofx-open-sidebar]' ) ) {
			event.preventDefault();
			openSidebar();
		}
	} );

	function tone( score ) {
		if ( score >= 80 ) {
			return 'good';
		}
		return score >= 50 ? 'warn' : 'bad';
	}

	function toneLabel( score ) {
		var labels = {
			good: __( 'Healthy', 'seo-fixer' ),
			warn: __( 'Needs work', 'seo-fixer' ),
			bad: __( 'Needs attention', 'seo-fixer' ),
		};
		return labels[ tone( score ) ];
	}

	/* ------------------------------------------------------------------ *
	 * Checker: watches the editor and asks the server for a fresh check.
	 * ------------------------------------------------------------------ */
	function Checker() {
		var state = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			var meta = editor.getEditedPostAttribute( 'meta' ) || {};
			return {
				id: editor.getCurrentPostId(),
				title: editor.getEditedPostAttribute( 'title' ) || '',
				excerpt: editor.getEditedPostAttribute( 'excerpt' ) || '',
				slug: editor.getEditedPostAttribute( 'slug' ) || '',
				seoTitle: meta[ META_TITLE ] || '',
				seoDesc: meta[ META_DESC ] || '',
				featured: editor.getEditedPostAttribute( 'featured_media' ) || 0,
				// A new array whenever any block changes; the content is only built when checking.
				blocks: select( 'core/block-editor' ).getBlocks(),
			};
		}, [] );
		var set = useDispatch( STORE ).set;
		var timer = useRef( null );
		var last = useRef( '' );
		var request = useRef( 0 );

		useEffect(
			function () {
				window.clearTimeout( timer.current );
				timer.current = window.setTimeout(
					function () {
						if ( ! state.id ) {
							return;
						}
						var data = {
							id: state.id,
							title: state.title,
							content: wp.data.select( 'core/editor' ).getEditedPostContent(),
							excerpt: state.excerpt,
							slug: state.slug,
							seo_title: state.seoTitle,
							seo_description: state.seoDesc,
							featured_media: state.featured,
						};
						var key = JSON.stringify( data );
						if ( key === last.current ) {
							return;
						}
						last.current = key;
						var mine = ++request.current;
						set( { loading: true } );
						wp.apiFetch( { path: '/seofx/v1/check', method: 'POST', data: data } )
							.then( function ( result ) {
								if ( mine === request.current ) {
									set( { result: result, loading: false, error: '' } );
								}
							} )
							.catch( function ( error ) {
								if ( mine === request.current ) {
									last.current = '';
									set( { loading: false, error: ( error && error.message ) || __( 'The check could not run.', 'seo-fixer' ) } );
								}
							} );
					},
					last.current ? 1500 : 200
				);
				return function () {
					window.clearTimeout( timer.current );
				};
			},
			[ state.id, state.title, state.excerpt, state.slug, state.seoTitle, state.seoDesc, state.featured, state.blocks ]
		);
		return null;
	}

	/* ------------------------------------------------------------------ *
	 * Pieces of the sidebar.
	 * ------------------------------------------------------------------ */
	function Score( props ) {
		var result = props.result;
		if ( ! result ) {
			return el( 'p', { className: 'seofx-sb__muted' }, props.loading ? __( 'Checking…', 'seo-fixer' ) : '' );
		}
		return el(
			'div',
			{ className: 'seofx-sb__score' },
			el(
				'span',
				{ className: 'seofx-sb__ring is-' + tone( result.score ), role: 'img', 'aria-label': sprintf( /* translators: %d: score from 0 to 100. */ __( 'SEO score: %d out of 100', 'seo-fixer' ), result.score ) },
				result.score
			),
			el(
				'span',
				null,
				el( 'strong', null, toneLabel( result.score ) ),
				el(
					'span',
					{ className: 'seofx-sb__muted' },
					result.published ? __( 'Includes changes you haven’t saved yet.', 'seo-fixer' ) : __( 'Your score once this is published.', 'seo-fixer' )
				),
				props.loading ? el( 'span', { className: 'seofx-sb__muted' }, el( components.Spinner ), __( 'Updating…', 'seo-fixer' ) ) : null
			)
		);
	}

	function sameImage( a, b ) {
		var strip = function ( url ) {
			return String( url || '' ).replace( /^https?:\/\/[^/]+/i, '' ).split( '?' )[ 0 ];
		};
		return a === b || strip( a ) === strip( b );
	}

	function Problem( props ) {
		var problem = props.problem;
		var editPost = useDispatch( 'core/editor' ).editPost;
		var updateBlockAttributes = useDispatch( 'core/block-editor' ).updateBlockAttributes;
		var noticeState = useState( '' );
		var notice = noticeState[ 0 ];
		var setNotice = noticeState[ 1 ];

		var fix = null;
		if ( 'description' === problem.fix && problem.suggestion ) {
			fix = el(
				'div',
				{ className: 'seofx-sb__fix' },
				el( 'p', { className: 'seofx-sb__suggestion' }, problem.suggestion ),
				el(
					components.Button,
					{
						variant: 'secondary',
						onClick: function () {
							var meta = {};
							meta[ META_DESC ] = problem.suggestion;
							editPost( { meta: meta } );
						},
					},
					__( 'Use this description', 'seo-fixer' )
				)
			);
		} else if ( 'title' === problem.fix && problem.suggestion ) {
			fix = el(
				'div',
				{ className: 'seofx-sb__fix' },
				el( 'p', { className: 'seofx-sb__suggestion' }, problem.preview ),
				el(
					components.Button,
					{
						variant: 'secondary',
						onClick: function () {
							var meta = {};
							meta[ META_TITLE ] = problem.suggestion;
							editPost( { meta: meta } );
						},
					},
					__( 'Leave the site name off', 'seo-fixer' )
				)
			);
		} else if ( 'alt' === problem.fix && problem.suggestion && problem.suggestion.length ) {
			fix = el(
				'div',
				{ className: 'seofx-sb__fix' },
				el(
					components.Button,
					{
						variant: 'secondary',
						onClick: function () {
							var done = 0;
							var walk = function ( blocks ) {
								blocks.forEach( function ( block ) {
									if ( 'core/image' === block.name && ! String( block.attributes.alt || '' ).trim() ) {
										problem.suggestion.some( function ( item ) {
											if ( sameImage( item.src, block.attributes.url ) ) {
												updateBlockAttributes( block.clientId, { alt: item.alt } );
												done++;
												return true;
											}
											return false;
										} );
									}
									if ( block.innerBlocks && block.innerBlocks.length ) {
										walk( block.innerBlocks );
									}
								} );
							};
							walk( wp.data.select( 'core/block-editor' ).getBlocks() );
							if ( done < problem.suggestion.length ) {
								setNotice( __( 'Some images are inside Custom HTML or Classic blocks. Add their alt text in those blocks.', 'seo-fixer' ) );
							}
						},
					},
					sprintf(
						/* translators: %d: number of images. */
						_n( 'Add alt text to %d image', 'Add alt text to %d images', problem.suggestion.length, 'seo-fixer' ),
						problem.suggestion.length
					)
				),
				el( 'p', { className: 'seofx-sb__muted' }, __( 'You can change any of them by clicking the image.', 'seo-fixer' ) )
			);
		}

		return el(
			'li',
			{ className: 'seofx-sb__problem is-' + problem.tone },
			el( 'strong', null, problem.label ),
			el( 'span', null, problem.text ),
			problem.items && problem.items.length ? el( 'code', { className: 'seofx-sb__items' }, problem.items.slice( 0, 5 ).join( '\n' ) ) : null,
			fix,
			notice ? el( 'p', { className: 'seofx-sb__muted' }, notice ) : null,
			problem.why ? el( 'details', { className: 'seofx-sb__why' }, el( 'summary', null, __( 'Why it matters', 'seo-fixer' ) ), el( 'p', null, problem.why ) ) : null
		);
	}

	function counter( text, min, max ) {
		var length = String( text || '' ).length;
		var state = length > max ? 'is-bad' : length && length < min ? 'is-warn' : 'is-good';
		/* translators: %d: number of characters. */
		return el( 'span', { className: 'seofx-sb__count ' + state }, sprintf( __( '%d characters', 'seo-fixer' ), length ) );
	}

	function displayUrl( url ) {
		var match = String( url || '' ).match( /^https?:\/\/([^/]+)(\/.*)?$/i );
		if ( ! match ) {
			return url;
		}
		var parts = ( match[ 2 ] || '' ).split( '/' ).filter( Boolean );
		return [ match[ 1 ] ].concat( parts ).join( ' › ' );
	}

	function SeoSidebar() {
		var data = useSelect( function ( select ) {
			return select( STORE ).get();
		}, [] );
		var meta = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );
		var editPost = useDispatch( 'core/editor' ).editPost;
		var result = data.result;
		var setMeta = function ( key ) {
			return function ( value ) {
				var change = {};
				change[ key ] = value;
				editPost( { meta: change } );
			};
		};
		var problems = result ? result.problems : [];
		var scored = problems.filter( function ( problem ) {
			return 'hint' !== problem.severity;
		} );

		return el(
			PluginSidebar,
			{ name: 'seofx-sidebar', title: __( 'SEO Fixer', 'seo-fixer' ), icon: icon, className: 'seofx-sb' },
			settings.paused ? el( components.Notice, { status: 'warning', isDismissible: false }, __( 'SEO Fixer is paused, so these settings are saved but not used on your site yet.', 'seo-fixer' ) ) : null,
			data.error ? el( components.Notice, { status: 'error', isDismissible: false }, data.error ) : null,
			el( components.PanelBody, { title: __( 'SEO score', 'seo-fixer' ), initialOpen: true }, el( Score, { result: result, loading: data.loading } ) ),
			result
				? el(
						components.PanelBody,
						{
							title: scored.length
								? sprintf( /* translators: %d: number of problems. */ _n( '%d thing to improve', '%d things to improve', scored.length, 'seo-fixer' ), scored.length )
								: __( 'Checklist', 'seo-fixer' ),
							initialOpen: true,
						},
						problems.length
							? el(
									'ul',
									{ className: 'seofx-sb__problems' },
									problems.map( function ( problem ) {
										return el( Problem, { key: problem.id, problem: problem } );
									} )
							  )
							: el( 'p', null, __( 'Everything checks out. Nice work!', 'seo-fixer' ) )
				  )
				: null,
			el(
				components.PanelBody,
				{ title: __( 'Google preview', 'seo-fixer' ), initialOpen: true },
				result
					? el(
							'div',
							{ className: 'seofx-sb__serp' + ( result.hidden ? ' is-hidden' : '' ) },
							el( 'span', { className: 'seofx-sb__serp-url' }, displayUrl( result.url ) ),
							el( 'span', { className: 'seofx-sb__serp-title' }, result.title ),
							el( 'span', { className: 'seofx-sb__serp-desc' }, result.description || __( 'No description: Google will pick some text from the page.', 'seo-fixer' ) ),
							result.hidden ? el( 'em', null, __( 'Hidden from search results (see the SEO Fixer box below the editor).', 'seo-fixer' ) ) : null
					  )
					: null,
				el( components.TextControl, {
					label: __( 'SEO title', 'seo-fixer' ),
					help: el( Fragment, null, __( 'Leave empty to use the automatic title. ', 'seo-fixer' ), counter( result && ! meta[ META_TITLE ] ? result.title : meta[ META_TITLE ], 0, 65 ) ),
					value: meta[ META_TITLE ] || '',
					placeholder: settings.titleTemplate || '',
					onChange: setMeta( META_TITLE ),
					maxLength: 200,
				} ),
				el( components.TextareaControl, {
					label: __( 'Meta description', 'seo-fixer' ),
					help: el( Fragment, null, __( 'Leave empty to use the first sentences of your content. ', 'seo-fixer' ), counter( result && ! meta[ META_DESC ] ? result.description : meta[ META_DESC ], 70, 160 ) ),
					value: meta[ META_DESC ] || '',
					placeholder: result && ! meta[ META_DESC ] ? result.description : '',
					onChange: setMeta( META_DESC ),
					rows: 4,
					maxLength: 400,
				} )
			),
			result && result.passed.length
				? el(
						components.PanelBody,
						{
							title: sprintf( /* translators: %d: number of passed checks. */ _n( '%d check passed', '%d checks passed', result.passed.length, 'seo-fixer' ), result.passed.length ),
							initialOpen: false,
						},
						el(
							'ul',
							{ className: 'seofx-sb__passed' },
							result.passed.map( function ( label ) {
								return el( 'li', { key: label }, label );
							} )
						)
				  )
				: null,
			el(
				'p',
				{ className: 'seofx-sb__footer' },
				el( 'a', { href: settings.reportUrl }, __( 'SEO Health for the whole site', 'seo-fixer' ) )
			)
		);
	}

	function StatusScore() {
		var data = useSelect( function ( select ) {
			return select( STORE ).get();
		}, [] );
		if ( ! data.result ) {
			return null;
		}
		return el(
			PluginPostStatusInfo,
			{ className: 'seofx-sb__status' },
			el( 'span', null, __( 'SEO score', 'seo-fixer' ) ),
			el(
				components.Button,
				{ variant: 'link', onClick: openSidebar, className: 'seofx-sb__status-button' },
				el( 'span', { className: 'seofx-sb__pill is-' + tone( data.result.score ) }, data.result.score ),
				el( 'span', null, toneLabel( data.result.score ) )
			)
		);
	}

	var icon = el(
		'svg',
		{ xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 20 20', width: 20, height: 20, 'aria-hidden': true, focusable: false },
		el( 'path', {
			fill: 'currentColor',
			d: 'M8.5 2a6.5 6.5 0 0 1 5.17 10.44l3.95 3.94a1 1 0 0 1-1.42 1.42l-3.94-3.95A6.5 6.5 0 1 1 8.5 2Zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9Zm2.2 2.3a1 1 0 0 1 .1 1.4l-2.5 2.8a1 1 0 0 1-1.46.04l-1.25-1.25a1 1 0 1 1 1.42-1.42l.5.5 1.8-1.97a1 1 0 0 1 1.4-.1Z',
		} )
	);

	wp.plugins.registerPlugin( 'seofx-sidebar', {
		icon: icon,
		render: function () {
			return el(
				Fragment,
				null,
				el( Checker ),
				el( PluginSidebarMoreMenuItem, { target: 'seofx-sidebar', icon: icon }, __( 'SEO Fixer', 'seo-fixer' ) ),
				el( SeoSidebar ),
				el( StatusScore )
			);
		},
	} );
} )( window.wp );
