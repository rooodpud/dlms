/**
 * DeutschLMS noun pictures: the shared picker for Courses → Noun pictures and
 * the question editor.
 *
 * window.dlmsNounPictures offers:
 * - keyFor( noun )          Library key of a noun (Kühlschrank → kuehlschrank).
 * - entry( key )            The library entry { noun, picture, url } or null.
 * - previewUrl( ref )       Preview of a picture reference (icon:…, media:…, key).
 * - chooseIcon( title )     Promise of an icon name (null when cancelled).
 * - chooseImage( title )    Promise of { id, url } from the Media Library (null when cancelled).
 * - save( noun, picture )   Saves a noun's picture ('' clears it); Promise of the entry.
 * - onChange( callback )    Called with ( key, entry|null ) after every save.
 *
 * Icons are the bundled Tabler set (MIT); their search index has English
 * names and tags, so the search is in English.
 *
 * @param {Object} wp   WordPress script globals.
 * @param {Object} data Library and icon locations (NounPictures::editor_data()).
 */
( function ( wp, data ) {
	'use strict';

	if ( ! wp || ! wp.i18n || ! data ) {
		return;
	}

	const { __, _n, sprintf } = wp.i18n;
	const speak = wp.a11y && wp.a11y.speak ? wp.a11y.speak : function () {};
	const library = Object.assign( {}, data.library || {} );
	const listeners = [];
	const MAX_RESULTS = 120;
	// Shown before anything is typed: everyday things.
	const SUGGESTED = [
		'home',
		'building',
		'door',
		'window',
		'stairs',
		'bed',
		'sofa',
		'armchair',
		'desk',
		'lamp',
		'clock',
		'plant',
		'fridge',
		'cooker',
		'microwave',
		'bath',
		'wash-machine',
		'glass',
		'cup',
		'tools-kitchen-2',
		'car',
		'bus',
		'bike',
		'train',
		'apple',
		'bread',
		'coffee',
		'shirt',
		'shoe',
		'book',
		'pencil',
		'phone',
		'dog',
		'cat',
		'tree',
		'sun',
	];

	let index = null;
	let dialog = null;

	function el( tag, props, children ) {
		const node = document.createElement( tag );
		Object.keys( props || {} ).forEach( function ( key ) {
			if ( 'className' === key ) {
				node.className = props[ key ];
			} else if ( 'text' === key ) {
				node.textContent = props[ key ];
			} else {
				node.setAttribute( key, props[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( child );
			}
		} );
		return node;
	}

	function keyFor( noun ) {
		return ( noun || '' )
			.trim()
			.toLowerCase()
			.replace( /ä/g, 'ae' )
			.replace( /ö/g, 'oe' )
			.replace( /ü/g, 'ue' )
			.replace( /ß/g, 'ss' )
			.replace( /[^a-z0-9_-]/g, '' );
	}

	function entry( key ) {
		return Object.prototype.hasOwnProperty.call( library, key )
			? library[ key ]
			: null;
	}

	function iconUrl( name ) {
		return data.iconBase + name + '.svg';
	}

	function previewUrl( ref ) {
		ref = ref || '';
		if ( 0 === ref.indexOf( 'icon:' ) ) {
			return iconUrl( ref.slice( 5 ) );
		}
		const found = entry( keyFor( ref ) );
		return found ? found.url : '';
	}

	/**
	 * Preview of a library entry: icons in the colour of the article (a CSS
	 * mask, as an <img> can't take a colour), images as they are.
	 *
	 * @param {Object} found   Library entry { picture, url }.
	 * @param {string} article der, die, das or ''.
	 * @return {HTMLElement} Preview.
	 */
	function preview( found, article ) {
		if ( 0 === found.picture.indexOf( 'icon:' ) ) {
			const icon = el( 'span', {
				className:
					'dlms-np-icon dlms-np-icon--' + ( article || 'none' ),
				'aria-hidden': 'true',
			} );
			icon.style.setProperty(
				'--dlms-np-icon',
				'url("' + found.url + '")'
			);
			return icon;
		}
		return el( 'img', {
			className: 'dlms-np-image',
			src: found.url,
			alt: '',
			width: '48',
			height: '48',
		} );
	}

	function loadIndex() {
		if ( ! index ) {
			index = window
				.fetch( data.iconIndex, { credentials: 'same-origin' } )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( response.statusText );
					}
					return response.json();
				} );
			index.catch( function () {
				index = null;
			} );
		}
		return index;
	}

	/**
	 * Icons matching a search: every word must appear in the name or the
	 * tags; names that start with the search come first.
	 *
	 * @param {Array}  icons [ name, tags ] pairs.
	 * @param {string} query Search.
	 * @return {string[]} Icon names.
	 */
	function search( icons, query ) {
		const words = query
			.toLowerCase()
			.split( /[\s,]+/ )
			.filter( Boolean );
		if ( ! words.length ) {
			const known = {};
			icons.forEach( function ( icon ) {
				known[ icon[ 0 ] ] = true;
			} );
			return SUGGESTED.filter( function ( name ) {
				return known[ name ];
			} );
		}
		const scored = [];
		icons.forEach( function ( icon ) {
			const name = icon[ 0 ];
			const haystack = name + ' ' + icon[ 1 ];
			if (
				! words.every( function ( word ) {
					return -1 !== haystack.indexOf( word );
				} )
			) {
				return;
			}
			let score = 3;
			if ( name === words.join( '-' ) ) {
				score = 0;
			} else if ( 0 === name.indexOf( words[ 0 ] ) ) {
				score = 1;
			} else if ( -1 !== name.indexOf( words[ 0 ] ) ) {
				score = 2;
			}
			// Crossed-out variants ("…-off") are rarely wanted.
			if ( /-off$/.test( name ) ) {
				score += 4;
			}
			scored.push( [ score, name ] );
		} );
		scored.sort( function ( a, b ) {
			return a[ 0 ] - b[ 0 ] || a[ 1 ].localeCompare( b[ 1 ] );
		} );
		return scored.map( function ( item ) {
			return item[ 1 ];
		} );
	}

	function buildDialog() {
		const heading = el( 'h2', {
			className: 'dlms-np-dialog__title',
			id: 'dlms-np-dialog-title',
		} );
		const input = el( 'input', {
			type: 'search',
			className: 'regular-text dlms-np-dialog__search',
			id: 'dlms-np-dialog-search',
			autocomplete: 'off',
			spellcheck: 'false',
		} );
		const status = el( 'p', {
			className: 'dlms-np-dialog__status',
			'aria-live': 'polite',
		} );
		const grid = el( 'div', { className: 'dlms-np-dialog__grid' } );
		const close = el( 'button', {
			type: 'button',
			className: 'button dlms-np-dialog__close',
			text: __( 'Cancel', 'deutschlms' ),
		} );
		const node = el(
			'dialog',
			{
				className: 'dlms-np-dialog',
				'aria-labelledby': 'dlms-np-dialog-title',
			},
			[
				el( 'div', { className: 'dlms-np-dialog__head' }, [
					heading,
					close,
				] ),
				el( 'label', {
					for: 'dlms-np-dialog-search',
					className: 'dlms-np-dialog__label',
					text: __(
						'Search icons (in English, e.g. bed, chair, fridge, car)',
						'deutschlms'
					),
				} ),
				input,
				status,
				grid,
			]
		);
		document.body.appendChild( node );

		const state = { resolve: null, icons: [], timer: null };

		function finish( value ) {
			const done = state.resolve;
			state.resolve = null;
			if ( node.open ) {
				node.close();
			}
			if ( done ) {
				done( value );
			}
		}

		function show() {
			const found = search( state.icons, input.value );
			grid.textContent = '';
			found.slice( 0, MAX_RESULTS ).forEach( function ( name ) {
				grid.appendChild(
					el(
						'button',
						{
							type: 'button',
							className: 'dlms-np-dialog__icon',
							'data-icon': name,
							title: name,
						},
						[
							el( 'img', {
								src: iconUrl( name ),
								alt: '',
								width: '40',
								height: '40',
								loading: 'lazy',
							} ),
							el( 'span', { text: name } ),
						]
					)
				);
			} );
			if ( ! input.value.trim() ) {
				status.textContent = __(
					'Suggestions. Type to search all icons.',
					'deutschlms'
				);
			} else if ( ! found.length ) {
				status.textContent = __( 'No icons found.', 'deutschlms' );
			} else {
				status.textContent = sprintf(
					/* translators: 1: icons shown, 2: icons found. */
					_n(
						'%1$d of %2$d icon',
						'%1$d of %2$d icons',
						found.length,
						'deutschlms'
					),
					Math.min( found.length, MAX_RESULTS ),
					found.length
				);
			}
		}

		input.addEventListener( 'input', function () {
			window.clearTimeout( state.timer );
			state.timer = window.setTimeout( show, 150 );
		} );
		grid.addEventListener( 'click', function ( event ) {
			const button = event.target.closest( '[data-icon]' );
			if ( button ) {
				finish( button.getAttribute( 'data-icon' ) );
			}
		} );
		close.addEventListener( 'click', function () {
			finish( null );
		} );
		node.addEventListener( 'close', function () {
			finish( null );
		} );

		return {
			open( title ) {
				heading.textContent = title;
				input.value = '';
				grid.textContent = '';
				status.textContent = __( 'Loading icons…', 'deutschlms' );
				node.showModal();
				input.focus();
				return new Promise( function ( resolve ) {
					state.resolve = resolve;
					loadIndex().then(
						function ( icons ) {
							state.icons = icons;
							show();
						},
						function () {
							status.textContent = __(
								'The icons could not be loaded.',
								'deutschlms'
							);
						}
					);
				} );
			},
		};
	}

	function chooseIcon( title ) {
		if ( ! dialog ) {
			dialog = buildDialog();
		}
		return dialog.open( title || __( 'Choose an icon', 'deutschlms' ) );
	}

	function chooseImage( title ) {
		return new Promise( function ( resolve ) {
			if ( ! data.canUpload || ! wp.media ) {
				resolve( null );
				return;
			}
			const frame = wp.media( {
				title: title || __( 'Choose an image', 'deutschlms' ),
				library: { type: 'image' },
				button: { text: __( 'Use this image', 'deutschlms' ) },
				multiple: false,
			} );
			let chosen = null;
			frame.on( 'select', function () {
				const attachment = frame
					.state()
					.get( 'selection' )
					.first()
					.toJSON();
				const sizes = attachment.sizes || {};
				const size = sizes.thumbnail || sizes.medium || sizes.full;
				chosen = {
					id: attachment.id,
					url: size ? size.url : attachment.url,
				};
			} );
			frame.on( 'close', function () {
				// "select" fires before "close".
				window.setTimeout( function () {
					resolve( chosen );
				}, 0 );
			} );
			frame.open();
		} );
	}

	function save( noun, picture ) {
		return wp
			.apiFetch( {
				path: data.restPath,
				method: 'POST',
				data: { noun, picture: picture || '' },
			} )
			.then( function ( response ) {
				if ( response.picture ) {
					library[ response.key ] = {
						noun: response.noun,
						picture: response.picture,
						url: response.url,
					};
				} else {
					delete library[ response.key ];
				}
				const saved = entry( response.key );
				speak(
					saved
						? sprintf(
								/* translators: %s: noun. */
								__( 'Picture of %s saved.', 'deutschlms' ),
								response.noun
							)
						: sprintf(
								/* translators: %s: noun. */
								__( 'Picture of %s removed.', 'deutschlms' ),
								response.noun
							)
				);
				listeners.forEach( function ( callback ) {
					callback( response.key, saved );
				} );
				return saved;
			} );
	}

	window.dlmsNounPictures = {
		keyFor,
		entry,
		previewUrl,
		preview,
		chooseIcon,
		chooseImage,
		save,
		canUpload: !! data.canUpload,
		/**
		 * Nouns of the library, sorted.
		 *
		 * @return {Array<{key: string, noun: string}>} Nouns.
		 */
		nouns() {
			return Object.keys( library )
				.map( function ( key ) {
					return { key, noun: library[ key ].noun };
				} )
				.sort( function ( a, b ) {
					return a.noun.localeCompare( b.noun, 'de' );
				} );
		},
		libraryPage: data.libraryPage,
		onChange( callback ) {
			listeners.push( callback );
		},
	};
} )( window.wp, window.dlmsNounPictureData );
