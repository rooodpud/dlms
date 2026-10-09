/**
 * Courses → Audio: every German text with a play button, and its recording.
 * Choosing or removing a recording is saved right away
 * (POST /dlms/v1/audio-clips). The play buttons use audio.js.
 *
 * @param {Object} wp WordPress script globals.
 */
( function ( wp ) {
	'use strict';

	const root = document.getElementById( 'dlms-audio-page' );
	const data = window.dlmsAudioPage || {};
	if ( ! root || ! wp || ! wp.i18n || ! wp.apiFetch ) {
		return;
	}

	const { __, _n, sprintf } = wp.i18n;
	const speak = wp.a11y && wp.a11y.speak ? wp.a11y.speak : function () {};
	let texts = [];
	try {
		texts = JSON.parse( root.getAttribute( 'data-texts' ) || '[]' );
	} catch {
		texts = [];
	}

	const kinds = {
		sentence: __( 'Sentence', 'deutschlms' ),
		word: __( 'Noun card', 'deutschlms' ),
		question: __( 'Listening question', 'deutschlms' ),
		unused: __( 'Not used any more', 'deutschlms' ),
	};

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
				node.appendChild(
					'string' === typeof child
						? document.createTextNode( child )
						: child
				);
			}
		} );
		return node;
	}

	function playButton( row ) {
		const button = el( 'button', {
			type: 'button',
			className: 'dlms-play',
			'aria-label': sprintf(
				/* translators: %s: German text. */
				__( 'Listen: %s', 'deutschlms' ),
				row.text
			),
		} );
		if ( row.url ) {
			button.setAttribute( 'data-dlms-src', row.url );
		}
		button.setAttribute( 'data-dlms-say', row.text );
		button.innerHTML =
			'<svg class="dlms-play__icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="18" height="18"><path class="dlms-play__start" d="M8 5.5v13l10.5-6.5z" fill="currentColor"/><path class="dlms-play__stop" d="M7 7h10v10H7z" fill="currentColor"/></svg>';
		return el( 'span', { className: 'dlms' }, [ button ] );
	}

	function chooseFile( row ) {
		return new Promise( function ( resolve ) {
			if ( ! data.canUpload || ! wp.media ) {
				resolve( null );
				return;
			}
			const frame = wp.media( {
				title: sprintf(
					/* translators: %s: German text. */
					__( 'Recording for “%s”', 'deutschlms' ),
					row.text
				),
				library: { type: 'audio' },
				button: { text: __( 'Use this recording', 'deutschlms' ) },
				multiple: false,
			} );
			let chosen = null;
			frame.on( 'select', function () {
				chosen = frame.state().get( 'selection' ).first().toJSON();
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

	function save( row, media ) {
		return wp
			.apiFetch( {
				path: data.restPath,
				method: 'POST',
				data: { text: row.text, media },
			} )
			.then( function ( response ) {
				row.media = response.media;
				row.url = response.url;
				row.file = response.file;
				speak(
					media
						? __( 'Recording saved.', 'deutschlms' )
						: __( 'Recording removed.', 'deutschlms' )
				);
				render();
			} )
			.catch( function ( error ) {
				const message =
					( error && error.message ) ||
					__( 'The recording could not be saved.', 'deutschlms' );
				notice.textContent = message;
				notice.hidden = false;
				speak( message, 'assertive' );
			} );
	}

	const filter = el( 'input', {
		type: 'search',
		id: 'dlms-audio-filter',
		className: 'regular-text',
	} );
	const missingOnly = el( 'input', {
		type: 'checkbox',
		id: 'dlms-audio-missing',
	} );
	const kindSelect = el( 'select', { id: 'dlms-audio-kind' } );
	kindSelect.appendChild(
		el( 'option', { value: '', text: __( 'All kinds', 'deutschlms' ) } )
	);
	Object.keys( kinds ).forEach( function ( kind ) {
		kindSelect.appendChild(
			el( 'option', { value: kind, text: kinds[ kind ] } )
		);
	} );
	const count = el( 'p', { className: 'description' } );
	const notice = el( 'div', {
		className: 'notice notice-error inline',
		role: 'alert',
	} );
	notice.hidden = true;
	const tbody = el( 'tbody' );

	function tableRow( item ) {
		const where = el( 'td', {} );
		item.where.forEach( function ( place, index ) {
			if ( index ) {
				where.appendChild( document.createTextNode( ', ' ) );
			}
			where.appendChild(
				place.link
					? el( 'a', { href: place.link, text: place.title } )
					: el( 'span', { text: place.title } )
			);
		} );
		if ( ! item.where.length ) {
			where.appendChild(
				el( 'span', { className: 'dlms-np-none', text: '—' } )
			);
		}

		const status = el( 'td', {} );
		if ( item.url ) {
			status.appendChild(
				el( 'strong', { text: __( 'Recording', 'deutschlms' ) } )
			);
			status.appendChild(
				el( 'span', { className: 'dlms-np-source', text: item.file } )
			);
		} else {
			status.appendChild(
				el( 'span', {
					className: 'dlms-np-none',
					text: __( 'Browser voice', 'deutschlms' ),
				} )
			);
		}

		const actions = el( 'td', { className: 'dlms-np-table__actions' } );
		if ( data.canUpload ) {
			const choose = el( 'button', {
				type: 'button',
				className: 'button',
				text: item.url
					? __( 'Replace recording', 'deutschlms' )
					: __( 'Add recording', 'deutschlms' ),
			} );
			choose.addEventListener( 'click', function () {
				chooseFile( item ).then( function ( attachment ) {
					if ( attachment && attachment.id ) {
						save( item, attachment.id );
					}
				} );
			} );
			actions.appendChild( choose );
		}
		if ( item.url ) {
			const remove = el( 'button', {
				type: 'button',
				className: 'button-link dlms-np-remove',
				text: __( 'Remove recording', 'deutschlms' ),
			} );
			remove.addEventListener( 'click', function () {
				save( item, 0 );
			} );
			actions.appendChild( remove );
		}

		return el( 'tr', {}, [
			el( 'td', {}, [ playButton( item ) ] ),
			el( 'td', { lang: 'de' }, [
				el( 'strong', { text: item.text } ),
				el( 'span', {
					className: 'dlms-np-source',
					text: kinds[ item.kind ] || '',
				} ),
			] ),
			where,
			status,
			actions,
		] );
	}

	function render() {
		const needle = filter.value.trim().toLowerCase();
		const shown = texts.filter( function ( item ) {
			if ( missingOnly.checked && item.url ) {
				return false;
			}
			if ( kindSelect.value && item.kind !== kindSelect.value ) {
				return false;
			}
			return ! needle || item.text.toLowerCase().includes( needle );
		} );
		tbody.textContent = '';
		shown.forEach( function ( item ) {
			tbody.appendChild( tableRow( item ) );
		} );
		const recorded = texts.filter( function ( item ) {
			return item.url;
		} ).length;
		count.textContent =
			sprintf(
				/* translators: 1: number of texts, 2: number with a recording. */
				_n(
					'%1$d text, %2$d with a recording.',
					'%1$d texts, %2$d with a recording.',
					texts.length,
					'deutschlms'
				),
				texts.length,
				recorded
			) +
			' ' +
			sprintf(
				/* translators: %d: number of texts shown. */
				_n( '%d shown.', '%d shown.', shown.length, 'deutschlms' ),
				shown.length
			);
	}

	[ filter, missingOnly, kindSelect ].forEach( function ( input ) {
		input.addEventListener( 'input', render );
		input.addEventListener( 'change', render );
	} );

	root.textContent = '';
	root.appendChild(
		el( 'div', { className: 'dlms-np-toolbar' }, [
			el( 'p', {}, [
				el( 'label', {
					for: 'dlms-audio-filter',
					className: 'dlms-np-toolbar__label',
					text: __( 'Search', 'deutschlms' ),
				} ),
				filter,
			] ),
			el( 'p', {}, [
				el( 'label', {
					for: 'dlms-audio-kind',
					className: 'dlms-np-toolbar__label',
					text: __( 'Kind', 'deutschlms' ),
				} ),
				kindSelect,
			] ),
			el( 'p', {}, [
				el( 'label', { for: 'dlms-audio-missing' }, [
					missingOnly,
					' ' + __( 'Only texts without a recording', 'deutschlms' ),
				] ),
			] ),
		] )
	);
	if ( ! data.canUpload ) {
		root.appendChild(
			el( 'p', {
				className: 'description',
				text: __(
					'You need permission to upload files to add recordings.',
					'deutschlms'
				),
			} )
		);
	}
	root.appendChild( notice );
	root.appendChild( count );
	root.appendChild(
		el( 'table', { className: 'widefat striped dlms-np-table' }, [
			el( 'thead', {}, [
				el( 'tr', {}, [
					el( 'th', {
						scope: 'col',
						className: 'dlms-np-table__picture',
						text: __( 'Play', 'deutschlms' ),
					} ),
					el( 'th', {
						scope: 'col',
						text: __( 'Text', 'deutschlms' ),
					} ),
					el( 'th', {
						scope: 'col',
						text: __( 'Used in', 'deutschlms' ),
					} ),
					el( 'th', {
						scope: 'col',
						text: __( 'Audio', 'deutschlms' ),
					} ),
					el( 'th', {
						scope: 'col',
						text: __( 'Actions', 'deutschlms' ),
					} ),
				] ),
			] ),
			tbody,
		] )
	);
	render();
} )( window.wp );
