/**
 * DeutschLMS front end: submits the enroll, mark-complete and quiz forms
 * through the REST API (dlms/v1) instead of a full admin-post.php round trip.
 * Without JavaScript, or if this script fails to load, the forms still work as
 * plain POST forms.
 */
( function () {
	'use strict';

	const config = window.dlmsFrontend;
	if ( ! config || ! window.fetch ) {
		return;
	}

	const routes = {
		enroll( id ) {
			return 'courses/' + id + '/enroll';
		},
		complete( id ) {
			return 'steps/' + id + '/complete';
		},
		quiz( id ) {
			return 'quizzes/' + id + '/attempts';
		},
	};

	/**
	 * Quiz answers as { questionId: [ value, ... ] } from fields named
	 * dlms_answers[questionId][]: checked answer IDs, typed gap answers (one per
	 * gap, empty ones included so positions line up) and word-order block IDs
	 * (one per position). Unanswered questions are sent as empty lists.
	 *
	 * @param {HTMLFormElement} form Quiz form.
	 * @return {Object} Answers.
	 */
	function quizAnswers( form ) {
		const answers = {};
		form.querySelectorAll( '[name^="dlms_answers["]' ).forEach(
			function ( field ) {
				const match = field.name.match( /^dlms_answers\[([^\]]+)\]/ );
				if ( ! match ) {
					return;
				}
				answers[ match[ 1 ] ] = answers[ match[ 1 ] ] || [];
				if ( 'checkbox' === field.type || 'radio' === field.type ) {
					if ( field.checked ) {
						answers[ match[ 1 ] ].push( field.value );
					}
				} else {
					answers[ match[ 1 ] ].push( field.value );
				}
			}
		);
		return answers;
	}

	function format( text, value ) {
		return String( text ).replace( '%s', value );
	}

	/**
	 * Word-order questions. The per-position selects are the source of truth,
	 * so the form submits the same way with or without this script.
	 *
	 * - `drag` (default): the selects are replaced by word tiles. Tiles can be
	 *   dragged (mouse, pen or finger) into the sentence, within it and back,
	 *   or clicked/tapped: a click adds a word at the end of the sentence, a
	 *   click on a word in the sentence puts it back.
	 * - `select`: the selects stay; a word chosen in one of them is no longer
	 *   offered in the others.
	 *
	 * @param {HTMLFieldSetElement} fieldset Word-order question.
	 */
	function enhanceOrder( fieldset ) {
		if ( 'select' === fieldset.getAttribute( 'data-dlms-order' ) ) {
			enhancePick( fieldset );
		} else {
			enhanceDrag( fieldset );
		}
	}

	/**
	 * A word-order block at the start of the sentence: first letter upper case.
	 *
	 * @param {string} text Block text.
	 * @return {string} Text.
	 */
	function capitalize( text ) {
		return text.charAt( 0 ).toUpperCase() + text.slice( 1 );
	}

	/**
	 * A word-order block at the end of the sentence: a trailing , ; or : is
	 * replaced by the end mark; one that already ends with . ? or ! is kept.
	 *
	 * @param {string} text   Block text.
	 * @param {string} ending ".", "?" or "!".
	 * @return {string} Text.
	 */
	function finish( text, ending ) {
		const trimmed = text.replace( /[,;:]\s*$/, '' ).replace( /\s+$/, '' );
		return /[.?!]$/.test( trimmed ) ? trimmed : trimmed + ending;
	}

	/**
	 * Word blocks of a word-order question, from its first select.
	 *
	 * @param {HTMLSelectElement} select Any of the question's selects.
	 * @return {Array} [ { id, text } ].
	 */
	function blocksOf( select ) {
		return Array.prototype.slice
			.call( select.options )
			.filter( function ( option ) {
				return option.value;
			} )
			.map( function ( option ) {
				return {
					id: option.value,
					text:
						option.getAttribute( 'data-text' ) ||
						option.textContent,
				};
			} );
	}

	/**
	 * `select` display: each word can be chosen in one position only.
	 *
	 * @param {HTMLFieldSetElement} fieldset Word-order question.
	 */
	function enhancePick( fieldset ) {
		const selects = Array.prototype.slice.call(
			fieldset.querySelectorAll( 'select' )
		);
		if ( ! selects.length || selects[ 0 ].disabled ) {
			return;
		}
		const doc = fieldset.ownerDocument;
		const blocks = blocksOf( selects[ 0 ] );
		const empty = selects[ 0 ].options[ 0 ].textContent;
		const ending = fieldset.getAttribute( 'data-dlms-ending' ) || '.';
		const last = selects.length - 1;

		function label( text, position ) {
			let shown = 0 === position ? capitalize( text ) : text;
			if ( last === position ) {
				shown = finish( shown, ending );
			}
			return shown;
		}

		function refresh() {
			const chosen = selects.map( function ( select ) {
				return select.value;
			} );
			selects.forEach( function ( select, position ) {
				const own = select.value;
				select.textContent = '';
				const none = doc.createElement( 'option' );
				none.value = '';
				none.textContent = empty;
				select.appendChild( none );
				blocks.forEach( function ( block ) {
					const elsewhere =
						chosen.indexOf( block.id ) !== -1 &&
						chosen.indexOf( block.id ) !== position &&
						block.id !== own;
					if ( ! elsewhere ) {
						const option = doc.createElement( 'option' );
						option.value = block.id;
						option.setAttribute( 'data-text', block.text );
						option.textContent = label( block.text, position );
						select.appendChild( option );
					}
				} );
				select.value = own;
			} );
		}

		// The same word may be preselected twice (e.g. after "back"): keep the first.
		const seen = [];
		selects.forEach( function ( select ) {
			if ( select.value && seen.indexOf( select.value ) !== -1 ) {
				select.value = '';
			}
			seen.push( select.value );
		} );

		const hint = fieldset.querySelector( '[data-dlms-order-hint]' );
		if ( hint ) {
			hint.textContent = config.i18n.orderHintPick;
		}
		fieldset.addEventListener( 'change', function ( event ) {
			if ( 'SELECT' === event.target.tagName ) {
				refresh();
			}
		} );
		refresh();
	}

	/**
	 * `drag` display: word tiles that can be dragged or clicked.
	 *
	 * @param {HTMLFieldSetElement} fieldset Word-order question.
	 */
	function enhanceDrag( fieldset ) {
		const slots = fieldset.querySelector( '.dlms-order__slots' );
		const selects = Array.prototype.slice.call(
			fieldset.querySelectorAll( 'select' )
		);
		if ( ! slots || ! selects.length ) {
			return;
		}
		const doc = fieldset.ownerDocument;
		const blocks = blocksOf( selects[ 0 ] );
		const disabled = selects[ 0 ].disabled;
		const ending = fieldset.getAttribute( 'data-dlms-ending' ) || '.';
		const placed = [];
		selects.forEach( function ( select ) {
			if ( select.value && placed.indexOf( select.value ) === -1 ) {
				placed.push( select.value );
			}
		} );

		const answer = doc.createElement( 'div' );
		answer.className = 'dlms-order__answer';
		answer.setAttribute( 'role', 'group' );
		answer.setAttribute( 'aria-label', config.i18n.orderSentence );
		const bank = doc.createElement( 'div' );
		bank.className = 'dlms-order__bank';
		bank.setAttribute( 'role', 'group' );
		bank.setAttribute( 'aria-label', config.i18n.orderWords );
		const live = doc.createElement( 'p' );
		live.className = 'dlms-sr';
		live.setAttribute( 'aria-live', 'polite' );

		// Set while a drag is in progress (see startDrag()).
		let drag = null;
		// A finished drag must not also count as a click.
		let suppressClick = false;

		function textOf( id ) {
			for ( let i = 0; i < blocks.length; i++ ) {
				if ( blocks[ i ].id === id ) {
					return blocks[ i ].text;
				}
			}
			return '';
		}

		function focusTile( area, index ) {
			const tiles = area.querySelectorAll( '.dlms-order__tile' );
			if ( tiles.length ) {
				tiles[
					Math.max( 0, Math.min( index, tiles.length - 1 ) )
				].focus();
			}
		}

		/**
		 * How a word looks in the sentence: the first one starts with a capital
		 * letter, the last one gets the end mark once every word is placed.
		 *
		 * @param {string}  id         Block ID.
		 * @param {boolean} inSentence Whether the tile is in the sentence.
		 * @return {string} Text.
		 */
		function shown( id, inSentence ) {
			let text = textOf( id );
			if ( ! inSentence ) {
				return text;
			}
			const index = placed.indexOf( id );
			if ( 0 === index ) {
				text = capitalize( text );
			}
			if (
				placed.length === blocks.length &&
				index === placed.length - 1
			) {
				text = finish( text, ending );
			}
			return text;
		}

		function tile( id, inSentence ) {
			const button = doc.createElement( 'button' );
			button.type = 'button';
			button.className = 'dlms-order__tile';
			button.textContent = shown( id, inSentence );
			button.disabled = disabled;
			button.setAttribute( 'data-id', id );
			button.setAttribute(
				'aria-label',
				format(
					inSentence ? config.i18n.orderRemove : config.i18n.orderAdd,
					textOf( id )
				)
			);
			button.addEventListener( 'click', function () {
				if ( suppressClick ) {
					suppressClick = false;
					return;
				}
				let focusIn;
				let index;
				if ( inSentence ) {
					index = placed.indexOf( id );
					placed.splice( index, 1 );
					live.textContent = format(
						config.i18n.orderRemoved,
						textOf( id )
					);
					focusIn = placed.length ? answer : bank;
				} else {
					index = blocks
						.filter( function ( block ) {
							return placed.indexOf( block.id ) === -1;
						} )
						.map( function ( block ) {
							return block.id;
						} )
						.indexOf( id );
					placed.push( id );
					live.textContent = format(
						config.i18n.orderAdded,
						textOf( id )
					);
					focusIn = placed.length < blocks.length ? bank : answer;
					if ( focusIn === answer ) {
						index = placed.length - 1;
					}
				}
				update();
				focusTile( focusIn, index );
			} );
			if ( ! disabled ) {
				button.addEventListener( 'pointerdown', function ( event ) {
					if ( event.isPrimary && 0 === event.button ) {
						drag = {
							id,
							tile: button,
							fromSentence: inSentence,
							x: event.clientX,
							y: event.clientY,
							started: false,
						};
						doc.addEventListener( 'pointermove', onMove );
						doc.addEventListener( 'pointerup', onUp );
						doc.addEventListener( 'pointercancel', onCancel );
					}
				} );
			}
			return button;
		}

		function update() {
			selects.forEach( function ( select, position ) {
				select.value = placed[ position ] || '';
			} );
			answer.textContent = '';
			bank.textContent = '';
			if ( ! placed.length ) {
				const empty = doc.createElement( 'span' );
				empty.className = 'dlms-order__empty';
				empty.textContent = config.i18n.orderEmpty;
				answer.appendChild( empty );
			}
			placed.forEach( function ( id ) {
				answer.appendChild( tile( id, true ) );
			} );
			blocks.forEach( function ( block ) {
				if ( placed.indexOf( block.id ) === -1 ) {
					bank.appendChild( tile( block.id, false ) );
				}
			} );
		}

		/**
		 * Area under the pointer (answer, bank or null).
		 *
		 * @param {number} x Client X.
		 * @param {number} y Client Y.
		 * @return {HTMLElement|null} Drop area.
		 */
		function areaAt( x, y ) {
			const target = doc.elementFromPoint( x, y );
			if ( ! target ) {
				return null;
			}
			if ( answer.contains( target ) ) {
				return answer;
			}
			return bank.contains( target ) ? bank : null;
		}

		/**
		 * Where in the sentence a word dropped at (x, y) goes: the index among
		 * the sentence's words, the dragged one left out.
		 *
		 * @param {number} x Client X.
		 * @param {number} y Client Y.
		 * @return {number} Index.
		 */
		function dropIndex( x, y ) {
			const tiles = Array.prototype.filter.call(
				answer.querySelectorAll( '.dlms-order__tile' ),
				function ( node ) {
					return node !== drag.tile;
				}
			);
			for ( let i = 0; i < tiles.length; i++ ) {
				const rect = tiles[ i ].getBoundingClientRect();
				if (
					y < rect.top ||
					( y <= rect.bottom && x < rect.left + rect.width / 2 )
				) {
					return i;
				}
			}
			return tiles.length;
		}

		function clearMarks() {
			const marker = answer.querySelector( '.dlms-order__marker' );
			if ( marker ) {
				marker.remove();
			}
			answer.classList.remove( 'is-over' );
			bank.classList.remove( 'is-over' );
		}

		function startDrag() {
			drag.started = true;
			drag.ghost = drag.tile.cloneNode( true );
			drag.ghost.className = 'dlms-order__tile dlms-order__ghost';
			drag.ghost.removeAttribute( 'aria-label' );
			drag.ghost.setAttribute( 'aria-hidden', 'true' );
			drag.ghost.style.width = drag.tile.offsetWidth + 'px';
			fieldset.appendChild( drag.ghost );
			drag.tile.classList.add( 'is-dragging' );
		}

		function onMove( event ) {
			if ( ! drag ) {
				return;
			}
			if ( ! drag.started ) {
				if (
					Math.abs( event.clientX - drag.x ) +
						Math.abs( event.clientY - drag.y ) <
					6
				) {
					return;
				}
				startDrag();
			}
			event.preventDefault();
			drag.ghost.style.left = event.clientX + 'px';
			drag.ghost.style.top = event.clientY + 'px';

			clearMarks();
			const area = areaAt( event.clientX, event.clientY );
			if ( area ) {
				area.classList.add( 'is-over' );
			}
			if ( area === answer ) {
				const marker = doc.createElement( 'span' );
				marker.className = 'dlms-order__marker';
				marker.setAttribute( 'aria-hidden', 'true' );
				const tiles = Array.prototype.filter.call(
					answer.querySelectorAll( '.dlms-order__tile' ),
					function ( node ) {
						return node !== drag.tile;
					}
				);
				const index = dropIndex( event.clientX, event.clientY );
				answer.insertBefore( marker, tiles[ index ] || null );
			}
		}

		function endDrag() {
			doc.removeEventListener( 'pointermove', onMove );
			doc.removeEventListener( 'pointerup', onUp );
			doc.removeEventListener( 'pointercancel', onCancel );
			if ( drag && drag.ghost ) {
				drag.ghost.remove();
				drag.tile.classList.remove( 'is-dragging' );
			}
			clearMarks();
		}

		function onUp( event ) {
			if ( ! drag ) {
				return;
			}
			const current = drag;
			if ( ! current.started ) {
				// A plain click: the click handler does the work.
				endDrag();
				drag = null;
				return;
			}
			suppressClick = true;
			window.setTimeout( function () {
				suppressClick = false;
			}, 0 );
			const area = areaAt( event.clientX, event.clientY );
			let index = -1;
			if ( area === answer ) {
				index = dropIndex( event.clientX, event.clientY );
			}
			endDrag();
			drag = null;

			if ( area === answer ) {
				const from = placed.indexOf( current.id );
				if ( from !== -1 ) {
					placed.splice( from, 1 );
				}
				placed.splice( index, 0, current.id );
				live.textContent = config.i18n.orderMoved
					.replace( '%1$s', textOf( current.id ) )
					.replace( '%2$d', String( index + 1 ) );
				update();
				focusTile( answer, index );
			} else if ( area === bank && current.fromSentence ) {
				placed.splice( placed.indexOf( current.id ), 1 );
				live.textContent = format(
					config.i18n.orderRemoved,
					textOf( current.id )
				);
				update();
				focusTile(
					bank,
					blocks
						.filter( function ( block ) {
							return placed.indexOf( block.id ) === -1;
						} )
						.map( function ( block ) {
							return block.id;
						} )
						.indexOf( current.id )
				);
			}
		}

		function onCancel() {
			endDrag();
			drag = null;
		}

		const hint = fieldset.querySelector( '[data-dlms-order-hint]' );
		if ( hint ) {
			hint.textContent = config.i18n.orderHint;
		}
		slots.hidden = true;
		slots.parentNode.insertBefore( answer, slots );
		slots.parentNode.insertBefore( bank, slots );
		slots.parentNode.insertBefore( live, slots );
		update();
	}

	/**
	 * Quiz time limit. Shows the time left; when it runs out, the answers lock
	 * and a dialog asks the student to submit, counting down to an automatic
	 * submission. The server knows when the attempt started and marks
	 * submissions that arrive too late, so this is only the visible part.
	 *
	 * @param {HTMLFormElement} form Quiz form with data-dlms-time-remaining.
	 */
	function enhanceTimer( form ) {
		const remaining = parseInt(
			form.getAttribute( 'data-dlms-time-remaining' ),
			10
		);
		const bar = form.querySelector( '[data-dlms-timer]' );
		const clock = form.querySelector( '[data-dlms-timer-clock]' );
		const dialog = form.querySelector( '[data-dlms-timeup]' );
		if ( isNaN( remaining ) || ! bar || ! clock || ! dialog ) {
			return;
		}
		const doc = form.ownerDocument;
		const graceSeconds =
			parseInt( form.getAttribute( 'data-dlms-time-up' ), 10 ) || 15;
		const deadline = Date.now() + remaining * 1000;
		const countdown = dialog.querySelector(
			'[data-dlms-timeup-countdown]'
		);
		const announce = doc.createElement( 'p' );
		announce.className = 'dlms-sr';
		announce.setAttribute( 'aria-live', 'assertive' );
		bar.appendChild( announce );

		let ticker = null;
		let autoSubmit = null;
		let warned = false;
		let timeUp = false;
		let submitting = false;

		function pad( number ) {
			return ( number < 10 ? '0' : '' ) + number;
		}

		function show( seconds ) {
			const left = Math.max( 0, seconds );
			clock.textContent =
				Math.floor( left / 60 ) + ':' + pad( left % 60 );
			if ( left <= 60 && ! bar.classList.contains( 'is-low' ) ) {
				bar.classList.add( 'is-low' );
			}
			if ( left <= 60 && left > 0 && ! warned ) {
				warned = true;
				announce.textContent = config.i18n.timeOneMinute;
			}
		}

		function lock() {
			// `inert` blocks typing, clicking and dragging but, unlike
			// `disabled`, keeps the answers in the submitted form.
			form.querySelectorAll(
				'.dlms-quiz__questions, .dlms-chars'
			).forEach( function ( part ) {
				part.inert = true;
			} );
		}

		function submit() {
			if ( submitting ) {
				return;
			}
			submitting = true;
			window.clearInterval( autoSubmit );
			countdown.textContent = config.i18n.working;
			const button = dialog.querySelector( '[type="submit"]' );
			if ( form.requestSubmit ) {
				form.requestSubmit( button );
			} else {
				button.click();
			}
		}

		function expire() {
			timeUp = true;
			window.clearInterval( ticker );
			show( 0 );
			lock();
			let left = graceSeconds;
			const template = countdown.getAttribute( 'data-template' ) || '%d';
			countdown.textContent = template.replace( '%d', left );
			if ( dialog.showModal ) {
				dialog.showModal();
			} else {
				dialog.setAttribute( 'open', '' );
			}
			autoSubmit = window.setInterval( function () {
				left -= 1;
				if ( left <= 0 ) {
					submit();
					return;
				}
				countdown.textContent = template.replace( '%d', left );
			}, 1000 );
		}

		function tick() {
			const seconds = Math.ceil( ( deadline - Date.now() ) / 1000 );
			if ( seconds <= 0 ) {
				expire();
				return;
			}
			show( seconds );
		}

		// Escape must not close the dialog and unlock the answers.
		dialog.addEventListener( 'cancel', function ( event ) {
			event.preventDefault();
		} );

		// Stop the clock while answers are on their way; resume if that failed.
		form.addEventListener( 'submit', function () {
			submitting = true;
			window.clearInterval( ticker );
			window.clearInterval( autoSubmit );
			if ( timeUp ) {
				countdown.textContent = config.i18n.working;
				dialog
					.querySelectorAll( '[type="submit"]' )
					.forEach( function ( button ) {
						button.disabled = true;
					} );
			}
		} );
		form.addEventListener( 'dlms:submit-failed', function ( event ) {
			submitting = false;
			if ( timeUp ) {
				countdown.textContent = event.detail || config.i18n.error;
				dialog
					.querySelectorAll( '[type="submit"]' )
					.forEach( function ( button ) {
						button.disabled = false;
					} );
			} else {
				ticker = window.setInterval( tick, 250 );
				tick();
			}
		} );

		bar.hidden = false;
		tick();
		if ( ! timeUp ) {
			ticker = window.setInterval( tick, 250 );
		}
	}

	/**
	 * Gap-fill questions: the ä/ö/ü/ß buttons insert into the gap that had
	 * focus last (or the first gap).
	 *
	 * @param {HTMLElement} bar Character bar.
	 */
	function enhanceChars( bar ) {
		const form = bar.closest( 'form' );
		if ( ! form ) {
			return;
		}
		let last = null;
		form.addEventListener( 'focusin', function ( event ) {
			if (
				event.target.classList &&
				event.target.classList.contains( 'dlms-gap' )
			) {
				last = event.target;
			}
		} );
		// Keep the caret in the gap when the button is clicked with a mouse.
		bar.addEventListener( 'mousedown', function ( event ) {
			if ( event.target.closest( '.dlms-chars__key' ) ) {
				event.preventDefault();
			}
		} );
		bar.addEventListener( 'click', function ( event ) {
			const key = event.target.closest( '.dlms-chars__key' );
			const input = last || form.querySelector( '.dlms-gap' );
			if ( ! key || ! input || input.disabled ) {
				return;
			}
			const char = key.getAttribute( 'data-char' );
			const start =
				null === input.selectionStart
					? input.value.length
					: input.selectionStart;
			const end =
				null === input.selectionEnd ? start : input.selectionEnd;
			input.setRangeText( char, start, end, 'end' );
			input.focus();
		} );
		bar.hidden = false;
	}

	document.querySelectorAll( '[data-dlms-order]' ).forEach( enhanceOrder );
	document.querySelectorAll( '[data-dlms-chars]' ).forEach( enhanceChars );
	document
		.querySelectorAll( 'form[data-dlms-time-remaining]' )
		.forEach( enhanceTimer );

	function sameOrigin( url ) {
		try {
			return (
				new URL( url, window.location.href ).origin ===
				window.location.origin
			);
		} catch {
			return false;
		}
	}

	document.addEventListener( 'submit', function ( event ) {
		const form = event.target.closest( 'form[data-dlms-action]' );
		if ( ! form ) {
			return;
		}

		const action = form.getAttribute( 'data-dlms-action' );
		const route = routes[ action ];
		const id = parseInt( form.getAttribute( 'data-dlms-id' ), 10 );
		if ( ! route || ! id ) {
			return;
		}

		event.preventDefault();

		const button = form.querySelector( '[type="submit"]' );
		const status = form.querySelector( '.dlms-form-status' );
		if ( button ) {
			button.disabled = true;
			button.setAttribute( 'aria-busy', 'true' );
		}
		if ( status ) {
			status.textContent = config.i18n.working;
		}

		const request = {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce,
				Accept: 'application/json',
			},
		};
		if ( 'quiz' === action ) {
			request.headers[ 'Content-Type' ] = 'application/json';
			request.body = JSON.stringify( { answers: quizAnswers( form ) } );
		}

		window
			.fetch( config.restUrl + route( id ), request )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					if ( ! response.ok ) {
						throw new Error(
							( data && data.message ) || config.i18n.error
						);
					}
					return data;
				} );
			} )
			.then( function ( data ) {
				if ( data && data.redirect && sameOrigin( data.redirect ) ) {
					window.location.assign( data.redirect );
				} else {
					window.location.reload();
				}
			} )
			.catch( function ( error ) {
				const message =
					error && error.message ? error.message : config.i18n.error;
				if ( button ) {
					button.disabled = false;
					button.removeAttribute( 'aria-busy' );
				}
				if ( status ) {
					status.textContent = message;
				}
				form.dispatchEvent(
					new window.CustomEvent( 'dlms:submit-failed', {
						detail: message,
					} )
				);
			} );
	} );

	// After a quiz attempt, move focus to the result so screen readers hear it.
	const result = document.querySelector( '.dlms-quiz-result' );
	if ( result ) {
		result.focus();
	}
} )();
