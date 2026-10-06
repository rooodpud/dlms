/**
 * Courses → Noun pictures: the list of nouns with their pictures. Every
 * change is saved right away (see noun-pictures.js).
 *
 * @param {Object} wp       WordPress script globals.
 * @param {Object} pictures The picker (window.dlmsNounPictures).
 */
( function ( wp, pictures ) {
	'use strict';

	const root = document.getElementById( 'dlms-noun-pictures' );
	if ( ! root || ! pictures || ! wp || ! wp.i18n ) {
		return;
	}

	const { __, _n, sprintf } = wp.i18n;
	let nouns = [];
	try {
		nouns = JSON.parse( root.getAttribute( 'data-nouns' ) || '[]' );
	} catch {
		nouns = [];
	}

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

	const filter = el( 'input', {
		type: 'search',
		id: 'dlms-np-filter',
		className: 'regular-text',
	} );
	const missingOnly = el( 'input', {
		type: 'checkbox',
		id: 'dlms-np-missing',
	} );
	const newNoun = el( 'input', {
		type: 'text',
		id: 'dlms-np-new',
		className: 'regular-text',
		maxlength: '100',
		placeholder: __( 'e.g. Sessel', 'deutschlms' ),
	} );
	const add = el( 'button', {
		type: 'button',
		className: 'button',
		text: __( 'Add noun', 'deutschlms' ),
	} );
	const summary = el( 'p', {
		className: 'dlms-np-summary',
		'aria-live': 'polite',
	} );
	const body = el( 'tbody' );
	const table = el( 'table', { className: 'widefat striped dlms-np-table' }, [
		el( 'thead', {}, [
			el( 'tr', {}, [
				el( 'th', {
					scope: 'col',
					className: 'dlms-np-table__picture',
					text: __( 'Picture', 'deutschlms' ),
				} ),
				el( 'th', { scope: 'col', text: __( 'Noun', 'deutschlms' ) } ),
				el( 'th', {
					scope: 'col',
					text: __( 'Article questions', 'deutschlms' ),
				} ),
				el( 'th', {
					scope: 'col',
					text: __( 'Actions', 'deutschlms' ),
				} ),
			] ),
		] ),
		body,
	] );

	root.textContent = '';
	root.appendChild(
		el( 'div', { className: 'dlms-np-toolbar' }, [
			el( 'p', {}, [
				el( 'label', {
					for: 'dlms-np-new',
					className: 'dlms-np-toolbar__label',
					text: __( 'New noun (without article)', 'deutschlms' ),
				} ),
				newNoun,
				document.createTextNode( ' ' ),
				add,
			] ),
			el( 'p', {}, [
				el( 'label', {
					for: 'dlms-np-filter',
					className: 'dlms-np-toolbar__label',
					text: __( 'Filter', 'deutschlms' ),
				} ),
				filter,
				document.createTextNode( ' ' ),
				el( 'label', { for: 'dlms-np-missing' }, [
					missingOnly,
					document.createTextNode(
						' ' + __( 'Only nouns without a picture', 'deutschlms' )
					),
				] ),
			] ),
		] )
	);
	root.appendChild( summary );
	root.appendChild( table );

	function rowFor( item ) {
		const found = pictures.entry( item.key );
		const preview = el( 'td', { className: 'dlms-np-table__picture' } );
		if ( found && found.url ) {
			preview.appendChild( pictures.preview( found, item.article ) );
		} else {
			preview.appendChild(
				el( 'span', {
					className: 'dlms-np-none',
					text: __( 'No picture', 'deutschlms' ),
				} )
			);
		}

		const name = el( 'td', { className: 'dlms-np-table__noun' } );
		if ( item.article ) {
			name.appendChild(
				el( 'strong', {
					className:
						'dlms-np-article dlms-np-article--' + item.article,
					text: item.article,
				} )
			);
			name.appendChild( document.createTextNode( ' ' ) );
		}
		name.appendChild( el( 'span', { text: item.noun } ) );
		if ( found && 0 === found.picture.indexOf( 'icon:' ) ) {
			name.appendChild(
				el( 'span', {
					className: 'dlms-np-source',
					text: sprintf(
						/* translators: %s: icon name. */
						__( 'Icon: %s', 'deutschlms' ),
						found.picture.slice( 5 )
					),
				} )
			);
		} else if ( found ) {
			name.appendChild(
				el( 'span', {
					className: 'dlms-np-source',
					text: __( 'Own image', 'deutschlms' ),
				} )
			);
		}

		const icon = el( 'button', {
			type: 'button',
			className: 'button',
			'data-act': 'icon',
			text: __( 'Choose icon', 'deutschlms' ),
		} );
		const actions = el( 'td', { className: 'dlms-np-table__actions' }, [
			icon,
		] );
		if ( pictures.canUpload ) {
			actions.appendChild(
				el( 'button', {
					type: 'button',
					className: 'button',
					'data-act': 'image',
					text: __( 'Upload or choose image', 'deutschlms' ),
				} )
			);
		}
		if ( found ) {
			actions.appendChild(
				el( 'button', {
					type: 'button',
					className: 'button-link dlms-np-remove',
					'data-act': 'remove',
					text: __( 'Remove picture', 'deutschlms' ),
				} )
			);
		}
		actions.querySelectorAll( 'button' ).forEach( function ( button ) {
			button.setAttribute(
				'aria-label',
				button.textContent + ': ' + item.noun
			);
		} );

		return el( 'tr', { 'data-key': item.key }, [
			preview,
			name,
			el( 'td', {
				text: item.questions ? String( item.questions ) : '—',
			} ),
			actions,
		] );
	}

	function render( focusKey, focusAct ) {
		const query = pictures.keyFor( filter.value );
		body.textContent = '';
		let shown = 0;
		let missing = 0;
		nouns.forEach( function ( item ) {
			const has = !! pictures.entry( item.key );
			if ( ! has ) {
				missing++;
			}
			if ( missingOnly.checked && has ) {
				return;
			}
			if ( query && -1 === item.key.indexOf( query ) ) {
				return;
			}
			shown++;
			body.appendChild( rowFor( item ) );
		} );
		if ( ! shown ) {
			body.appendChild(
				el( 'tr', {}, [
					el( 'td', {
						colspan: '4',
						text: __( 'No nouns found.', 'deutschlms' ),
					} ),
				] )
			);
		}
		summary.textContent =
			sprintf(
				/* translators: %d: number of nouns. */
				_n( '%d noun', '%d nouns', nouns.length, 'deutschlms' ),
				nouns.length
			) +
			' · ' +
			sprintf(
				/* translators: %d: number of nouns without a picture. */
				_n(
					'%d without a picture',
					'%d without a picture',
					missing,
					'deutschlms'
				),
				missing
			);
		if ( focusKey ) {
			const row = body.querySelector( 'tr[data-key="' + focusKey + '"]' );
			const target = row
				? row.querySelector( '[data-act="' + focusAct + '"]' ) ||
					row.querySelector( '[data-act="icon"]' )
				: null;
			if ( target ) {
				target.focus();
			}
		}
	}

	function itemOf( key ) {
		return nouns.find( function ( item ) {
			return item.key === key;
		} );
	}

	function failed( error ) {
		// eslint-disable-next-line no-alert -- A native alert is the simplest accessible error message here.
		window.alert(
			( error && error.message ) ||
				__( 'The picture could not be saved.', 'deutschlms' )
		);
	}

	body.addEventListener( 'click', function ( event ) {
		const button = event.target.closest( '[data-act]' );
		const row = event.target.closest( 'tr[data-key]' );
		if ( ! button || ! row ) {
			return;
		}
		const item = itemOf( row.getAttribute( 'data-key' ) );
		if ( ! item ) {
			return;
		}
		const act = button.getAttribute( 'data-act' );
		let chosen;
		if ( 'icon' === act ) {
			chosen = pictures
				.chooseIcon(
					/* translators: %s: noun. */
					sprintf( __( 'Icon for %s', 'deutschlms' ), item.noun )
				)
				.then( function ( name ) {
					return name ? 'icon:' + name : null;
				} );
		} else if ( 'image' === act ) {
			chosen = pictures
				.chooseImage(
					/* translators: %s: noun. */
					sprintf( __( 'Image for %s', 'deutschlms' ), item.noun )
				)
				.then( function ( image ) {
					return image ? 'media:' + image.id : null;
				} );
		} else {
			chosen = Promise.resolve( '' );
		}
		chosen
			.then( function ( picture ) {
				if ( null === picture ) {
					button.focus();
					return null;
				}
				return pictures.save( item.noun, picture ).then( function () {
					render( item.key, 'remove' === act ? 'icon' : act );
				} );
			} )
			.catch( failed );
	} );

	function addNoun() {
		const key = pictures.keyFor( newNoun.value );
		if ( ! key ) {
			newNoun.focus();
			return;
		}
		const noun = newNoun.value.trim();
		if ( ! itemOf( key ) ) {
			nouns.push( { key, noun, article: '', questions: 0 } );
			nouns.sort( function ( a, b ) {
				return a.noun.localeCompare( b.noun, 'de' );
			} );
		}
		newNoun.value = '';
		filter.value = '';
		missingOnly.checked = false;
		render( key, 'icon' );
	}

	add.addEventListener( 'click', addNoun );
	newNoun.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
			addNoun();
		}
	} );
	filter.addEventListener( 'input', function () {
		render();
	} );
	missingOnly.addEventListener( 'change', function () {
		render();
	} );

	render();
} )( window.wp, window.dlmsNounPictures );
