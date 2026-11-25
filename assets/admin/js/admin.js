/**
 * SEO Fixer — admin behaviours (progressive enhancement; every screen works without it).
 */
( function () {
	'use strict';

	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
	var __ = i18n ? i18n.__ : function ( text ) {
		return text;
	};
	var sprintf = i18n ? i18n.sprintf : function ( format ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return format.replace( /%(\d+)\$[ds]/g, function ( match, index ) {
			return args[ index - 1 ];
		} );
	};

	/* "Why it matters": only one open at a time; Esc or outside click closes. */
	function closeWhy( except ) {
		document.querySelectorAll( '.seofx-why[open]' ).forEach( function ( el ) {
			if ( el !== except ) {
				el.removeAttribute( 'open' );
			}
		} );
	}

	document.addEventListener(
		'toggle',
		function ( event ) {
			if ( event.target.classList && event.target.classList.contains( 'seofx-why' ) && event.target.open ) {
				closeWhy( event.target );
			}
		},
		true
	);

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key ) {
			var open = document.querySelector( '.seofx-why[open]' );
			if ( open ) {
				open.removeAttribute( 'open' );
				open.querySelector( 'summary' ).focus();
			}
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest( '.seofx-why' ) ) {
			closeWhy( null );
		}
	} );

	/* Character bars: green inside the range, amber too short, red too long or empty. */
	function updateCharbar( bar ) {
		var input = document.getElementById( bar.getAttribute( 'data-seofx-charbar' ) );
		if ( ! input ) {
			return;
		}
		var min = parseInt( bar.getAttribute( 'data-min' ), 10 );
		var max = parseInt( bar.getAttribute( 'data-max' ), 10 );
		var length = Array.from( input.value.trim() ).length;
		var tone;
		var status;

		if ( 0 === length ) {
			tone = 'bad';
			status = __( 'Empty', 'seo-fixer' );
		} else if ( length < min ) {
			tone = 'warn';
			status = __( 'Too short', 'seo-fixer' );
		} else if ( length > max ) {
			tone = 'bad';
			status = __( 'Too long', 'seo-fixer' );
		} else {
			tone = 'good';
			status = __( 'Good length', 'seo-fixer' );
		}

		bar.classList.remove( 'seofx-charbar--good', 'seofx-charbar--warn', 'seofx-charbar--bad' );
		bar.classList.add( 'seofx-charbar--' + tone );
		bar.style.setProperty( '--seofx-fill', Math.min( 100, ( length / max ) * 100 ) + '%' );
		/* translators: 1: status such as "Good length", 2: characters used, 3: recommended maximum. */
		bar.querySelector( '.seofx-charbar__text' ).textContent = sprintf( __( '%1$s · %2$d / %3$d', 'seo-fixer' ), status, length, max );
	}

	document.querySelectorAll( '[data-seofx-charbar]' ).forEach( function ( bar ) {
		var input = document.getElementById( bar.getAttribute( 'data-seofx-charbar' ) );
		if ( input ) {
			input.addEventListener( 'input', function () {
				updateCharbar( bar );
			} );
			updateCharbar( bar );
		}
	} );

	/* Unsaved-changes indicator and leave warning. */
	document.querySelectorAll( '[data-seofx-form]' ).forEach( function ( form ) {
		var dirty = false;
		var status = form.querySelector( '[data-seofx-dirty-text]' );

		function markDirty() {
			if ( dirty ) {
				return;
			}
			dirty = true;
			if ( status ) {
				status.textContent = __( 'You have unsaved changes', 'seo-fixer' );
			}
		}

		form.addEventListener( 'input', markDirty );
		form.addEventListener( 'change', markDirty );
		form.addEventListener( 'submit', function () {
			dirty = false;
		} );

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( dirty ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );
	} );

	/* Conditional fields: data-seofx-show-if="local_business" follows that toggle. */
	document.querySelectorAll( '[data-seofx-show-if]' ).forEach( function ( field ) {
		var control = document.querySelector( '[name="seofx[' + field.getAttribute( 'data-seofx-show-if' ) + ']"]' );
		if ( ! control ) {
			return;
		}
		var sync = function () {
			field.hidden = ! control.checked;
		};
		control.addEventListener( 'change', sync );
		sync();
	} );

	/* Image picker (Media Library). */
	document.querySelectorAll( '[data-seofx-media]' ).forEach( function ( box ) {
		var input = box.querySelector( '[data-seofx-media-input]' );
		var preview = box.querySelector( '[data-seofx-media-preview]' );
		var choose = box.querySelector( '[data-seofx-media-choose]' );
		var remove = box.querySelector( '[data-seofx-media-remove]' );
		var warning = box.querySelector( '[data-seofx-media-warning]' );
		var minSize = parseInt( box.getAttribute( 'data-min-size' ), 10 ) || 0;
		var empty = box.querySelector( '[data-seofx-media-empty]' );
		var frame = null;

		function changed() {
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		choose.addEventListener( 'click', function () {
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			if ( ! frame ) {
				frame = window.wp.media( {
					title: __( 'Choose an image', 'seo-fixer' ),
					button: { text: __( 'Use this image', 'seo-fixer' ) },
					library: { type: 'image' },
					multiple: false,
				} );
				frame.on( 'select', function () {
					var image = frame.state().get( 'selection' ).first().toJSON();
					var size = image.sizes && ( image.sizes.medium || image.sizes.thumbnail );
					var img = document.createElement( 'img' );
					img.src = size ? size.url : image.url;
					img.alt = '';
					preview.textContent = '';
					preview.appendChild( img );
					input.value = String( image.id );
					remove.hidden = false;
					choose.textContent = __( 'Change image', 'seo-fixer' );
					warning.hidden = ! ( image.width && image.height && Math.min( image.width, image.height ) < minSize );
					changed();
				} );
			}
			frame.open();
		} );

		remove.addEventListener( 'click', function () {
			input.value = '0';
			preview.textContent = '';
			preview.appendChild( empty.content.cloneNode( true ) );
			remove.hidden = true;
			warning.hidden = true;
			choose.textContent = __( 'Choose image', 'seo-fixer' );
			choose.focus();
			changed();
		} );
	} );

	/*
	 * Google preview.
	 *
	 * Mirrors the PHP rules in Meta\Variables: %variables% are replaced, unknown
	 * ones kept, and repeated or dangling separators removed. Lengths are
	 * measured in pixels, the way Google cuts titles off.
	 */
	// Google's desktop result is 600px wide; descriptions get about two lines (~990px of text).
	var LIMITS = {
		title: { font: '20px Arial', maxPx: 600, goodChars: 30 },
		desc: { font: '14px Arial', maxPx: 990, goodChars: 120, shortChars: 70 },
	};
	var canvas = document.createElement( 'canvas' ).getContext( '2d' );

	function textWidth( text, font ) {
		canvas.font = font;
		return canvas.measureText( text ).width;
	}

	function truncate( text, font, maxPx ) {
		if ( textWidth( text, font ) <= maxPx ) {
			return text;
		}
		var words = text.split( ' ' );
		while ( words.length > 1 && textWidth( words.join( ' ' ) + ' …', font ) > maxPx ) {
			words.pop();
		}
		var out = words.join( ' ' );
		while ( out.length && textWidth( out + ' …', font ) > maxPx ) {
			out = out.slice( 0, -1 );
		}
		return out + ' …';
	}

	function escapeRegExp( text ) {
		return text.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	function cleanup( text, sep ) {
		text = text.replace( /\s+/g, ' ' ).trim();
		if ( sep ) {
			var s = escapeRegExp( sep );
			text = text
				.replace( new RegExp( '(\\s*' + s + '\\s*){2,}', 'g' ), ' ' + sep + ' ' )
				.replace( new RegExp( '^\\s*' + s + '\\s*|\\s*' + s + '\\s*$', 'g' ), '' );
		}
		return text.trim();
	}

	function resolve( template, vars, sep ) {
		var out = String( template || '' ).replace( /%([a-z_]+)%/g, function ( match, name ) {
			return Object.prototype.hasOwnProperty.call( vars, name ) ? String( vars[ name ] ) : match;
		} );
		return cleanup( out, sep );
	}

	function meter( bar, kind, text ) {
		if ( ! bar ) {
			return;
		}
		var limit = LIMITS[ kind ];
		var chars = Array.from( text ).length;
		var px = textWidth( text, limit.font );
		var tone = 'good';
		var status = __( 'Good length', 'seo-fixer' );

		if ( 0 === chars ) {
			tone = 'bad';
			status = 'title' === kind ? __( 'Empty', 'seo-fixer' ) : __( 'Empty — Google will pick text from the page', 'seo-fixer' );
		} else if ( px > limit.maxPx ) {
			tone = 'bad';
			status = __( 'Too long — Google will cut it off', 'seo-fixer' );
		} else if ( limit.shortChars && chars < limit.shortChars ) {
			tone = 'warn';
			status = __( 'Too short', 'seo-fixer' );
		} else if ( chars < limit.goodChars ) {
			tone = 'warn';
			status = __( 'Could be longer', 'seo-fixer' );
		}

		bar.classList.remove( 'seofx-charbar--good', 'seofx-charbar--warn', 'seofx-charbar--bad' );
		bar.classList.add( 'seofx-charbar--' + tone );
		bar.style.setProperty( '--seofx-fill', Math.min( 100, ( px / limit.maxPx ) * 100 ) + '%' );
		/* translators: 1: status such as "Good length", 2: number of characters. */
		bar.querySelector( '.seofx-charbar__text' ).textContent = sprintf( __( '%1$s · %2$d characters', 'seo-fixer' ), status, chars );
	}

	document.querySelectorAll( '[data-seofx-serp]' ).forEach( function ( root ) {
		var config = JSON.parse( root.getAttribute( 'data-seofx-serp' ) );
		var vars = config.vars || {};
		var titleInput = document.getElementById( config.titleInput );
		var descInput = document.getElementById( config.descInput );
		var titleEl = root.querySelector( '[data-seofx-serp-title]' );
		var descEl = root.querySelector( '[data-seofx-serp-desc]' );
		var mobile = false;

		function render() {
			var titleTemplate = titleInput && titleInput.value.trim() ? titleInput.value : config.titleTemplate;
			var descTemplate = descInput && descInput.value.trim() ? descInput.value : config.descTemplate;
			var title = resolve( titleTemplate, vars, config.sep ) || vars.sitename || '';
			var desc = resolve( descTemplate, vars, config.sep );

			titleEl.textContent = mobile ? title : truncate( title, LIMITS.title.font, LIMITS.title.maxPx );
			descEl.textContent = mobile ? desc : truncate( desc, LIMITS.desc.font, LIMITS.desc.maxPx );
			descEl.hidden = '' === desc;

			meter( root.querySelector( '[data-seofx-serp-bar="title"]' ), 'title', title );
			meter( root.querySelector( '[data-seofx-serp-bar="desc"]' ), 'desc', desc );

			// The grey automatic text follows the live post title.
			if ( titleInput ) {
				titleInput.placeholder = resolve( config.titleTemplate, vars, config.sep );
			}
		}

		[ titleInput, descInput ].forEach( function ( input ) {
			if ( input ) {
				input.addEventListener( 'input', render );
			}
		} );

		root.querySelectorAll( '[data-seofx-serp-device]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				mobile = 'mobile' === button.getAttribute( 'data-seofx-serp-device' );
				root.classList.toggle( 'seofx-serp--mobile', mobile );
				root.querySelectorAll( '[data-seofx-serp-device]' ).forEach( function ( other ) {
					var active = other === button;
					other.classList.toggle( 'is-active', active );
					other.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
				} );
				render();
			} );
		} );

		if ( 'post' === config.live ) {
			var classicTitle = document.getElementById( 'title' );
			if ( classicTitle ) {
				classicTitle.addEventListener( 'input', function () {
					vars.title = classicTitle.value.trim();
					render();
				} );
			} else if ( window.wp && window.wp.data ) {
				var lastTitle = null;
				window.wp.data.subscribe( function () {
					var editor = window.wp.data.select( 'core/editor' );
					var current = editor && editor.getEditedPostAttribute ? editor.getEditedPostAttribute( 'title' ) : null;
					if ( 'string' === typeof current && current !== lastTitle ) {
						lastTitle = current;
						vars.title = current.trim();
						render();
					}
				} );
			}
		}

		render();
	} );

	/* "Show in search results" switch greys out the preview when turned off. */
	document.querySelectorAll( '[data-seofx-noindex-toggle]' ).forEach( function ( toggle ) {
		var box = toggle.closest( '.seofx-metabox' );
		var preview = box ? box.querySelector( '[data-seofx-serp]' ) : null;
		if ( preview ) {
			toggle.addEventListener( 'change', function () {
				preview.classList.toggle( 'seofx-serp--hidden', ! toggle.checked );
			} );
		}
	} );

	/* Structured data: show the fields of the chosen type. */
	document.querySelectorAll( '[data-seofx-schema]' ).forEach( function ( section ) {
		var select = section.querySelector( '[data-seofx-schema-type]' );
		if ( ! select ) {
			return;
		}
		var sync = function () {
			section.querySelectorAll( '[data-seofx-schema-group]' ).forEach( function ( group ) {
				group.hidden = group.getAttribute( 'data-seofx-schema-group' ) !== select.value;
			} );
		};
		select.addEventListener( 'change', sync );
		sync();
	} );

	/* FAQ repeater. */
	document.querySelectorAll( '[data-seofx-faq]' ).forEach( function ( box ) {
		var list = box.querySelector( '[data-seofx-faq-list]' );
		var template = box.querySelector( '[data-seofx-faq-template]' );
		var next = parseInt( box.getAttribute( 'data-next-index' ), 10 ) || 0;

		box.querySelector( '[data-seofx-faq-add]' ).addEventListener( 'click', function () {
			var holder = document.createElement( 'div' );
			holder.innerHTML = template.innerHTML.replace( /__INDEX__/g, String( next++ ) );
			var row = holder.firstElementChild;
			list.appendChild( row );
			row.querySelector( 'input' ).focus();
		} );

		box.addEventListener( 'click', function ( event ) {
			var remove = event.target.closest( '[data-seofx-faq-remove]' );
			if ( ! remove ) {
				return;
			}
			var row = remove.closest( '.seofx-faq__item' );
			if ( list.children.length > 1 ) {
				row.remove();
			} else {
				row.querySelectorAll( 'input, textarea' ).forEach( function ( field ) {
					field.value = '';
				} );
			}
			box.querySelector( '[data-seofx-faq-add]' ).focus();
		} );
	} );

	/* Tables: "select all" checkbox. */
	document.querySelectorAll( '[data-seofx-check-all]' ).forEach( function ( all ) {
		all.addEventListener( 'change', function () {
			all.closest( 'table' ).querySelectorAll( 'tbody input[type="checkbox"]' ).forEach( function ( box ) {
				box.checked = all.checked;
			} );
		} );
	} );

	/* Buttons that ask before doing something drastic. */
	document.querySelectorAll( '[data-seofx-confirm]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function ( event ) {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( button.getAttribute( 'data-seofx-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	} );

	/* Redirect form: "Gone (410)" needs no target. */
	document.querySelectorAll( '[data-seofx-redirect-form]' ).forEach( function ( form ) {
		var target = form.querySelector( '[data-seofx-redirect-target]' );
		var sync = function () {
			var checked = form.querySelector( 'input[name="status"]:checked' );
			var gone = checked && '410' === checked.value;
			target.hidden = gone;
			target.querySelector( 'input' ).disabled = gone;
		};
		form.querySelectorAll( 'input[name="status"]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', sync );
		} );
		sync();
	} );

	/* Health scan: start, then run steps until finished, then reload to show the results. */
	document.querySelectorAll( '[data-seofx-scan]' ).forEach( function ( form ) {
		var nonce = form.getAttribute( 'data-nonce' );
		var button = form.querySelector( 'button' );
		var box = document.querySelector( '[data-seofx-scan-progress]' );
		var track = box ? box.querySelector( '[role="progressbar"]' ) : null;
		var fill = box ? box.querySelector( '.seofx-progress__fill' ) : null;
		var label = box ? box.querySelector( '.seofx-progress__label' ) : null;
		var failures = 0;

		var request = function ( action, extra ) {
			var body = new window.URLSearchParams( { action: action, nonce: nonce } );
			Object.keys( extra || {} ).forEach( function ( key ) {
				body.append( key, extra[ key ] );
			} );
			return window.fetch( window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} ).then( function ( response ) {
				return response.json().then( function ( json ) {
					if ( ! json || ! json.success ) {
						throw new Error( json && json.data && json.data.message ? json.data.message : __( 'The scan stopped unexpectedly.', 'seo-fixer' ) );
					}
					return json.data;
				} );
			} );
		};

		var show = function ( progress ) {
			if ( ! box ) {
				return;
			}
			box.hidden = false;
			fill.style.width = progress.percent + '%';
			track.setAttribute( 'aria-valuenow', progress.percent );
			if ( progress.label ) {
				label.textContent = progress.label;
			}
		};

		var step = function () {
			request( 'seofx_scan_step' )
				.then( function ( progress ) {
					failures = 0;
					show( progress );
					if ( progress.running ) {
						// Another tab or the background job holds the scan: check back shortly.
						window.setTimeout( step, progress.busy ? 2000 : 0 );
					} else {
						label.textContent = __( 'Done! Loading your results…', 'seo-fixer' );
						window.location.reload();
					}
				} )
				.catch( function ( error ) {
					// A slow host can time out once; try a few times before giving up.
					failures++;
					if ( failures < 4 ) {
						window.setTimeout( step, 3000 );
						return;
					}
					label.textContent = error.message + ' ' + __( 'The scan will carry on in the background — reload this page in a few minutes.', 'seo-fixer' );
					button.disabled = false;
				} );
		};

		form.addEventListener( 'submit', function ( event ) {
			if ( ! window.fetch || ! window.ajaxurl ) {
				return; // Plain form post.
			}
			event.preventDefault();
			button.disabled = true;
			show( { percent: 1, label: __( 'Starting the scan…', 'seo-fixer' ) } );
			request( 'seofx_scan_start' )
				.then( function ( progress ) {
					show( progress );
					step();
				} )
				.catch( function ( error ) {
					label.textContent = error.message;
					button.disabled = false;
				} );
		} );

		// A scan already running (started in another tab, or before a reload): keep it going.
		if ( '1' === form.getAttribute( 'data-running' ) && window.fetch && window.ajaxurl ) {
			step();
		}

		document.querySelectorAll( '[data-seofx-scan-dismiss]' ).forEach( function ( dismiss ) {
			dismiss.addEventListener( 'click', function () {
				dismiss.disabled = true;
				request( 'seofx_scan_dismiss', { check: dismiss.getAttribute( 'data-seofx-scan-dismiss' ) } )
					.then( function () {
						window.location.reload();
					} )
					.catch( function () {
						dismiss.disabled = false;
					} );
			} );
		} );
	} );

	/* "Fix all": 20 pages per request with progress, then show the result (with Undo). */
	document.querySelectorAll( '[data-seofx-fixall]' ).forEach( function ( form ) {
		var box = form.parentNode.querySelector( '[data-seofx-fixall-progress]' );
		var button = form.querySelector( 'button' );
		if ( ! box || ! window.fetch || ! window.ajaxurl ) {
			return; // The plain form fixes up to 100 pages per click.
		}
		var fill = box.querySelector( '.seofx-progress__fill' );
		var track = box.querySelector( '[role="progressbar"]' );
		var label = box.querySelector( '.seofx-progress__label' );
		var total = parseInt( form.getAttribute( 'data-total' ), 10 ) || 1;

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			button.disabled = true;
			box.hidden = false;
			var batch = '';
			var changed = 0;
			var pages = 0;

			var finish = function () {
				var url = new window.URL( form.getAttribute( 'data-done-url' ), window.location.href );
				url.searchParams.set( 'done', batch );
				url.searchParams.set( 'changed', String( changed ) );
				window.location.href = url.toString();
			};

			var step = function ( after ) {
				var body = new window.URLSearchParams( {
					action: 'seofx_fix_all',
					nonce: form.getAttribute( 'data-nonce' ),
					issue: form.getAttribute( 'data-issue' ),
					after: String( after ),
					batch: batch,
				} );
				window.fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( json ) {
						if ( ! json || ! json.success ) {
							throw new Error( json && json.data && json.data.message ? json.data.message : __( 'Something went wrong.', 'seo-fixer' ) );
						}
						batch = json.data.batch;
						changed += json.data.changed;
						pages += 20;
						var percent = Math.min( 99, Math.round( ( 100 * pages ) / total ) );
						fill.style.width = percent + '%';
						track.setAttribute( 'aria-valuenow', percent );
						/* translators: %s: number of changes made so far. */
						label.textContent = sprintf( __( 'Fixing… %s changes so far', 'seo-fixer' ), changed );
						if ( json.data.more ) {
							step( json.data.after );
						} else {
							finish();
						}
					} )
					.catch( function ( error ) {
						label.textContent = error.message + ( batch ? ' ' + __( 'The changes made so far are kept and can be undone under Changes.', 'seo-fixer' ) : '' );
						button.disabled = false;
					} );
			};
			step( 0 );
		} );
	} );

	/* Import from another SEO plugin: a few seconds of work per request, with progress. */
	document.querySelectorAll( '[data-seofx-import]' ).forEach( function ( form ) {
		var box = form.parentNode.querySelector( '[data-seofx-import-progress]' );
		var button = form.querySelector( 'button[type="submit"]' );
		if ( ! box || ! window.fetch || ! window.ajaxurl || ! window.FormData ) {
			return; // The plain form does about 20 seconds of work per click.
		}
		var fill = box.querySelector( '.seofx-progress__fill' );
		var track = box.querySelector( '[role="progressbar"]' );
		var label = box.querySelector( '.seofx-progress__label' );

		var run = function () {
			button.disabled = true;
			box.hidden = false;
			var first = new window.FormData( form );
			first.set( 'action', 'seofx_import' );

			var step = function ( body ) {
				window.fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( json ) {
						if ( ! json || ! json.success ) {
							throw new Error( json && json.data && json.data.message ? json.data.message : __( 'Something went wrong.', 'seo-fixer' ) );
						}
						fill.style.width = json.data.percent + '%';
						track.setAttribute( 'aria-valuenow', json.data.percent );
						label.textContent = json.data.label;
						if ( json.data.done ) {
							/* A changed query string makes this a real page load, not just a jump to #seofx-import. */
							var url = new window.URL( form.getAttribute( 'data-done-url' ), window.location.href );
							url.searchParams.set( 'seofx_t', String( Date.now() ) );
							window.location.href = url.toString();
							return;
						}
						var next = new window.FormData();
						next.set( 'action', 'seofx_import' );
						next.set( '_wpnonce', first.get( '_wpnonce' ) );
						/* Another request holds the lock: wait a moment before asking again. */
						window.setTimeout( function () {
							step( next );
						}, json.data.busy ? 2000 : 0 );
					} )
					.catch( function ( error ) {
						label.textContent = error.message;
						button.disabled = false;
					} );
			};
			step( first );
		};

		form.addEventListener( 'submit', function ( event ) {
			if ( event.defaultPrevented ) {
				return; // A confirmation was declined.
			}
			event.preventDefault();
			run();
		} );
		if ( '1' === form.getAttribute( 'data-running' ) ) {
			run(); // Picks up a job that was interrupted (closed tab, reload).
		}
	} );

	/* Character count under suggested descriptions. */
	document.querySelectorAll( '[data-seofx-charcount]' ).forEach( function ( field ) {
		var out = document.createElement( 'span' );
		out.className = 'seofx-fix-table__hint';
		field.parentNode.insertBefore( out, field.nextSibling );
		var update = function () {
			var length = field.value.length;
			/* translators: %d: number of characters. */
			out.textContent = sprintf( __( '%d characters', 'seo-fixer' ), length ) + ( length > 160 ? ' — ' + __( 'Google may cut this off', 'seo-fixer' ) : '' );
		};
		field.addEventListener( 'input', update );
		update();
	} );

	/* Classic editor: live SEO checklist (server check with what's on screen, after typing pauses). */
	document.querySelectorAll( '[data-seofx-livecheck]' ).forEach( function ( box ) {
		var body = box.querySelector( '.seofx-livecheck__body' );
		var postId = parseInt( box.getAttribute( 'data-post-id' ), 10 );
		var timer = null;
		var last = '';
		var value = function ( id ) {
			var field = document.getElementById( id );
			return field ? field.value : undefined;
		};
		var content = function () {
			var editor = window.tinymce && window.tinymce.get ? window.tinymce.get( 'content' ) : null;
			return editor && ! editor.isHidden() ? editor.getContent() : value( 'content' );
		};

		var run = function ( force ) {
			if ( ! window.wp || ! window.wp.apiFetch ) {
				return;
			}
			var data = {
				id: postId,
				title: value( 'title' ),
				content: content(),
				excerpt: value( 'excerpt' ),
				slug: value( 'post_name' ),
				seo_title: value( 'seofx-seo-title' ),
				seo_description: value( 'seofx-seo-description' ),
				featured_media: parseInt( value( '_thumbnail_id' ), 10 ) > 0 ? parseInt( value( '_thumbnail_id' ), 10 ) : 0,
				html: true,
			};
			Object.keys( data ).forEach( function ( key ) {
				if ( undefined === data[ key ] ) {
					delete data[ key ];
				}
			} );
			var key = JSON.stringify( data );
			if ( key === last && ! force ) {
				return;
			}
			last = key;
			box.classList.add( 'is-busy' );
			window.wp.apiFetch( { path: '/seofx/v1/check', method: 'POST', data: data } )
				.then( function ( result ) {
					body.innerHTML = result.html; // Built and escaped on the server.
				} )
				.catch( function () {
					last = '';
				} )
				.then( function () {
					box.classList.remove( 'is-busy' );
				} );
		};
		var later = function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( run, 1500 );
		};

		box.querySelector( '[data-seofx-livecheck-run]' ).addEventListener( 'click', function () {
			run( true );
		} );
		[ 'title', 'content', 'excerpt', 'seofx-seo-title', 'seofx-seo-description' ].forEach( function ( id ) {
			var field = document.getElementById( id );
			if ( field ) {
				field.addEventListener( 'input', later );
			}
		} );
		// The visual editor (TinyMCE) doesn't fire input events on the textarea.
		if ( window.jQuery ) {
			window.jQuery( document ).on( 'tinymce-editor-init', function ( event, editor ) {
				if ( 'content' === editor.id ) {
					editor.on( 'keyup change', later );
				}
			} );
		}

		// "Use this" buttons in the checklist fill the field; the preview and checklist follow.
		box.addEventListener( 'click', function ( event ) {
			var button = event.target.closest ? event.target.closest( '[data-seofx-use]' ) : null;
			if ( ! button ) {
				return;
			}
			var field = document.getElementById( 'title' === button.getAttribute( 'data-seofx-use' ) ? 'seofx-seo-title' : 'seofx-seo-description' );
			if ( field ) {
				field.value = button.getAttribute( 'data-value' );
				field.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
				field.focus();
			}
		} );
	} );

	/* Printable report: the browser's print dialog also offers "Save as PDF". */
	document.querySelectorAll( '[data-seofx-print]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			window.print();
		} );
	} );

	/* Copy buttons. */
	document.querySelectorAll( '[data-seofx-copy]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var source = document.querySelector( button.getAttribute( 'data-seofx-copy' ) );
			var label = button.querySelector( '[data-seofx-copy-label]' );
			if ( ! source ) {
				return;
			}
			var text = source.value || source.textContent;
			var done = function () {
				if ( label ) {
					var original = label.textContent;
					label.textContent = __( 'Copied!', 'seo-fixer' );
					window.setTimeout( function () {
						label.textContent = original;
					}, 2000 );
				}
			};

			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then( done );
			} else {
				// Older browsers / plain-http sites: copy through a temporary textarea.
				var temp = document.createElement( 'textarea' );
				temp.value = text;
				temp.setAttribute( 'readonly', '' );
				temp.style.position = 'fixed';
				temp.style.opacity = '0';
				document.body.appendChild( temp );
				temp.select();
				document.execCommand( 'copy' );
				document.body.removeChild( temp );
				done();
			}
		} );
	} );
} )();
