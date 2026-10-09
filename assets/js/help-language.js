/**
 * DeutschLMS help language switch (see src/Frontend/HelpLanguage.php).
 *
 * Courses with help languages print every instruction, button text and
 * help text once per language (`<span class="dlms-l" data-dlms-l="en">`);
 * the stylesheet shows the language in `<html data-dlms-help="en">`. This
 * script handles the switch buttons, remembers the choice (browser, and the
 * account of logged-in learners) and keeps attributes (aria-label, title)
 * in the chosen language. The switch that stays on screen
 * ([data-dlms-help-dock]) opens with a tap or a swipe to the left on its tab.
 *
 * The other DeutschLMS scripts use window.dlmsHelp for their texts:
 * - put( element, strings, key, args ): fills the element with the text in
 *   every language (or plain text without a choice of languages);
 * - get( strings, key, args ): the text in the current language;
 * - attr( element, name, strings, key, args ): sets an attribute (or the
 *   text, name '') and keeps it in the current language;
 * - instruction( element, strings, key, args ): an exercise instruction:
 *   the German text and under it the translation in the current language
 *   (in German only the German text);
 * - on( callback ): called with the new language after a switch.
 * `strings` is a script's configuration with `i18n` (the site language)
 * and `i18nAll` (language => key => text, empty without a choice).
 */
( function () {
	'use strict';

	const config = window.dlmsHelpConfig || {};
	const root = document.documentElement;
	const languages = Array.isArray( config.languages ) ? config.languages : [];
	const attrs = config.attrs || {};
	const listeners = [];
	const WATCH = 'data-dlms-help-watch';
	const SERVER_ATTRIBUTES = [ 'aria-label', 'title' ];

	function current() {
		const language = root.getAttribute( 'data-dlms-help' );
		return languages.indexOf( language ) > -1
			? language
			: config.current || '';
	}

	/**
	 * Fills %s, %d and %1$s placeholders (texts without values stay as they are).
	 *
	 * @param {string} text Text.
	 * @param {Array}  args Values.
	 * @return {string} Text.
	 */
	function format( text, args ) {
		text = String( text || '' );
		if ( ! args || ! args.length ) {
			return text;
		}
		let next = 0;
		return text.replace(
			/%(?:(\d+)\$)?([sd%])/g,
			function ( match, position, type ) {
				if ( '%' === type ) {
					return '%';
				}
				const value = position ? args[ position - 1 ] : args[ next++ ];
				return undefined === value ? '' : String( value );
			}
		);
	}

	function hasAll( strings ) {
		return !! (
			languages.length &&
			strings &&
			strings.i18nAll &&
			Object.keys( strings.i18nAll ).length
		);
	}

	function textIn( strings, key, args, language ) {
		let text = '';
		if (
			hasAll( strings ) &&
			strings.i18nAll[ language ] &&
			undefined !== strings.i18nAll[ language ][ key ]
		) {
			text = strings.i18nAll[ language ][ key ];
		} else if ( strings && strings.i18n ) {
			text = strings.i18n[ key ] || '';
		}
		return format( text, args );
	}

	function get( strings, key, args ) {
		return textIn( strings, key, args, current() );
	}

	function put( element, strings, key, args ) {
		element.textContent = '';
		if ( ! hasAll( strings ) ) {
			element.textContent = get( strings, key, args );
			return element;
		}
		const groups = [];
		languages.forEach( function ( language ) {
			const text = textIn( strings, key, args, language );
			let group = null;
			for ( let i = 0; i < groups.length; i++ ) {
				if ( groups[ i ].text === text ) {
					group = groups[ i ];
				}
			}
			if ( ! group ) {
				group = { text, languages: [] };
				groups.push( group );
			}
			group.languages.push( language );
		} );
		if ( 1 === groups.length ) {
			element.textContent = groups[ 0 ].text;
			return element;
		}
		groups.forEach( function ( group ) {
			if ( '' === group.text ) {
				return;
			}
			const span = document.createElement( 'span' );
			span.className = 'dlms-l';
			span.setAttribute( 'data-dlms-l', group.languages.join( ' ' ) );
			span.lang = group.languages[ 0 ];
			span.textContent = group.text;
			element.appendChild( span );
		} );
		return element;
	}

	function instruction( element, strings, key, args ) {
		if ( ! hasAll( strings ) || languages.indexOf( 'de' ) === -1 ) {
			return put( element, strings, key, args );
		}
		element.textContent = '';
		const german = textIn( strings, key, args, 'de' );
		const first = document.createElement( 'span' );
		first.className = 'dlms-instruction';
		first.lang = 'de';
		first.setAttribute( 'translate', 'no' );
		first.textContent = german;
		element.appendChild( first );
		const groups = [];
		languages.forEach( function ( language ) {
			const text = textIn( strings, key, args, language );
			if ( 'de' === language || text === german || '' === text ) {
				return;
			}
			let group = null;
			for ( let i = 0; i < groups.length; i++ ) {
				if ( groups[ i ].text === text ) {
					group = groups[ i ];
				}
			}
			if ( ! group ) {
				group = { text, languages: [] };
				groups.push( group );
			}
			group.languages.push( language );
		} );
		groups.forEach( function ( group ) {
			const span = document.createElement( 'span' );
			span.className = 'dlms-l dlms-instruction__help';
			span.setAttribute( 'data-dlms-l', group.languages.join( ' ' ) );
			span.lang = group.languages[ 0 ];
			span.textContent = group.text;
			element.appendChild( span );
		} );
		return element;
	}

	function apply( element, name, value ) {
		if ( '' === name ) {
			element.textContent = value;
		} else {
			element.setAttribute( name, value );
		}
	}

	function attr( element, name, strings, key, args ) {
		apply( element, name, get( strings, key, args ) );
		if ( ! hasAll( strings ) ) {
			return element;
		}
		element.dlmsHelp = element.dlmsHelp || {};
		element.dlmsHelp[ name ] = [ strings, key, args ];
		element.setAttribute( WATCH, '' );
		return element;
	}

	/**
	 * Puts every watched attribute and the server's attributes
	 * (data-dlms-i18n-aria-label='["key", …values]') into the current language.
	 */
	function refresh() {
		const language = current();
		document
			.querySelectorAll( '[' + WATCH + ']' )
			.forEach( function ( el ) {
				Object.keys( el.dlmsHelp || {} ).forEach( function ( name ) {
					const watched = el.dlmsHelp[ name ];
					apply(
						el,
						name,
						textIn(
							watched[ 0 ],
							watched[ 1 ],
							watched[ 2 ],
							language
						)
					);
				} );
			} );
		SERVER_ATTRIBUTES.forEach( function ( name ) {
			document
				.querySelectorAll( '[data-dlms-i18n-' + name + ']' )
				.forEach( function ( el ) {
					let data;
					try {
						data = JSON.parse(
							el.getAttribute( 'data-dlms-i18n-' + name )
						);
					} catch {
						return;
					}
					const formats = attrs[ data[ 0 ] ];
					if ( ! formats ) {
						return;
					}
					const text =
						formats[ language ] || formats.en || formats.de || '';
					el.setAttribute( name, format( text, data.slice( 1 ) ) );
				} );
		} );
		document
			.querySelectorAll( '[data-dlms-help-set]' )
			.forEach( function ( button ) {
				button.setAttribute(
					'aria-pressed',
					button.getAttribute( 'data-dlms-help-set' ) === language
						? 'true'
						: 'false'
				);
			} );
	}

	function save( language ) {
		try {
			window.localStorage.setItem( config.storageKey, language );
		} catch {}
		if ( ! config.save || ! window.fetch ) {
			return;
		}
		window
			.fetch( config.save, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce,
				},
				body: JSON.stringify( { language } ),
			} )
			.catch( function () {} );
	}

	function set( language, remember ) {
		if ( languages.indexOf( language ) === -1 ) {
			return;
		}
		root.setAttribute( 'data-dlms-help', language );
		refresh();
		if ( false !== remember ) {
			save( language );
		}
		listeners.forEach( function ( callback ) {
			callback( language );
		} );
		document.dispatchEvent(
			new window.CustomEvent( 'dlms:helplanguage', {
				detail: { language },
			} )
		);
	}

	function on( callback ) {
		listeners.push( callback );
	}

	window.dlmsHelp = {
		languages,
		current,
		format,
		put,
		instruction,
		get,
		attr,
		on,
		set,
	};

	/**
	 * The switch at the right edge: the tab opens and closes the panel (tap,
	 * or swipe left / right); a choice, Escape or a tap elsewhere closes it.
	 * Wide screens show the panel all the time (CSS).
	 */
	function dock() {
		const box = document.querySelector( '[data-dlms-help-dock]' );
		if ( ! box ) {
			return;
		}
		const tab = box.querySelector( '.dlms-help-dock__tab' );
		let startX = null;
		let startY = 0;
		let swiped = 0;

		function isOpen() {
			return box.classList.contains( 'is-open' );
		}

		function open( state, focus ) {
			box.classList.toggle( 'is-open', state );
			tab.setAttribute( 'aria-expanded', state ? 'true' : 'false' );
			if ( state && focus ) {
				const button =
					box.querySelector(
						'[data-dlms-help-set][aria-pressed="true"]'
					) || box.querySelector( '[data-dlms-help-set]' );
				if ( button ) {
					button.focus();
				}
			}
		}

		tab.addEventListener( 'click', function () {
			// A swipe may end with a click: the swipe already decided.
			if ( Date.now() - swiped < 500 ) {
				return;
			}
			open( ! isOpen(), true );
		} );

		box.addEventListener(
			'touchstart',
			function ( event ) {
				startX = event.touches[ 0 ].clientX;
				startY = event.touches[ 0 ].clientY;
			},
			{ passive: true }
		);
		box.addEventListener(
			'touchend',
			function ( event ) {
				if ( null === startX ) {
					return;
				}
				const dx = event.changedTouches[ 0 ].clientX - startX;
				const dy = event.changedTouches[ 0 ].clientY - startY;
				startX = null;
				if ( Math.abs( dx ) < 30 || Math.abs( dx ) < Math.abs( dy ) ) {
					return;
				}
				swiped = Date.now();
				open( dx < 0, false );
			},
			{ passive: true }
		);

		box.addEventListener( 'click', function ( event ) {
			if ( isOpen() && event.target.closest( '[data-dlms-help-set]' ) ) {
				// Long enough to see the page change language.
				window.setTimeout( function () {
					open( false );
				}, 400 );
			}
		} );
		document.addEventListener( 'click', function ( event ) {
			if ( isOpen() && ! box.contains( event.target ) ) {
				open( false );
			}
		} );
		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && isOpen() ) {
				open( false );
				tab.focus();
			}
		} );
	}

	function start() {
		if ( ! languages.length ) {
			return;
		}
		if ( ! root.hasAttribute( 'data-dlms-help' ) ) {
			root.setAttribute( 'data-dlms-help', current() );
		}
		document.addEventListener( 'click', function ( event ) {
			const button = event.target.closest
				? event.target.closest( '[data-dlms-help-set]' )
				: null;
			if ( button ) {
				set( button.getAttribute( 'data-dlms-help-set' ) );
			}
		} );
		dock();
		// The page was printed in the account's or the course's language; the
		// browser may remember another one (see the head script).
		refresh();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
