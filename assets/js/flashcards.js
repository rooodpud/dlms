/**
 * DeutschLMS flashcard decks ([dlms_flashcards]).
 *
 * Turns the grid of cards into a deck: one card at a time, with "turn over",
 * "next", "previous", "shuffle", a choice of the front side (picture and
 * meanings, or the German word) and a switch back to the grid. Turning a
 * picture card over plays the German word (audio.js handles the button).
 * Without JavaScript the cards stay a grid.
 *
 * Texts follow the help language switch (help-language.js): every label is
 * printed in each of the course's languages.
 *
 * Needs audio.js and help-language.js; works for visitors who are not
 * logged in.
 */
( function () {
	'use strict';

	const strings = window.dlmsFlashcards || {};
	const help = window.dlmsHelp;

	/**
	 * Puts a text into an element, in every help language (help-language.js).
	 *
	 * @param {Element} element Element.
	 * @param {string}  key     Text key (see Assets::enqueue_flashcards()).
	 * @param {Array}   args    Values for the placeholders.
	 * @return {Element} The element.
	 */
	function put( element, key, args ) {
		if ( help ) {
			return help.put( element, strings, key, args );
		}
		element.textContent = ( strings.i18n && strings.i18n[ key ] ) || key;
		return element;
	}

	function makeButton( className, key, before, after ) {
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'dlms-flashcards__button ' + className;
		if ( before ) {
			button.appendChild( document.createTextNode( before ) );
		}
		button.appendChild( put( document.createElement( 'span' ), key ) );
		if ( after ) {
			button.appendChild( document.createTextNode( after ) );
		}
		return button;
	}

	function setup( deck, number ) {
		const list = deck.querySelector( '.dlms-flashcards__cards' );
		const cards = list
			? Array.from( list.querySelectorAll( ':scope > .dlms-card' ) )
			: [];
		if ( ! cards.length ) {
			return;
		}
		let index = 0;
		let flipped = false;
		let front =
			'german' === deck.getAttribute( 'data-front' )
				? 'german'
				: 'picture';

		// Toolbar: front side, shuffle, all cards.
		const bar = document.createElement( 'div' );
		bar.className = 'dlms-flashcards__bar';

		const label = document.createElement( 'label' );
		label.className = 'dlms-flashcards__front';
		const select = document.createElement( 'select' );
		select.id = 'dlms-flashcards-front-' + number;
		[ 'picture', 'german' ].forEach( function ( value ) {
			const item = document.createElement( 'option' );
			item.value = value;
			// Options hold plain text: kept in the current language.
			if ( help ) {
				help.attr( item, '', strings, value );
			} else {
				put( item, value );
			}
			select.appendChild( item );
		} );
		select.value = front;
		label.htmlFor = select.id;
		put( label, 'front' );
		label.appendChild( document.createTextNode( ': ' ) );
		bar.appendChild( label );
		bar.appendChild( select );

		const shuffle = makeButton( 'dlms-flashcards__shuffle', 'shuffle' );
		const all = makeButton( 'dlms-flashcards__all', 'all' );
		all.setAttribute( 'aria-pressed', 'false' );
		bar.appendChild( shuffle );
		bar.appendChild( all );

		// Navigation under the card.
		const nav = document.createElement( 'div' );
		nav.className = 'dlms-flashcards__nav';
		const previous = makeButton(
			'dlms-flashcards__previous',
			'previous',
			'‹'
		);
		const flip = makeButton( 'dlms-flashcards__flip', 'flip' );
		const next = makeButton( 'dlms-flashcards__next', 'next', '', '›' );
		nav.appendChild( previous );
		nav.appendChild( flip );
		nav.appendChild( next );

		const status = document.createElement( 'p' );
		status.className = 'dlms-flashcards__status';
		status.setAttribute( 'aria-live', 'polite' );

		const hint = document.createElement( 'p' );
		hint.className = 'dlms-flashcards__hint';
		if ( help && help.instruction ) {
			help.instruction( hint, strings, 'hint', [] );
		} else {
			put( hint, 'hint' );
		}

		list.parentNode.insertBefore( bar, list );
		list.parentNode.insertBefore( status, list );
		list.parentNode.insertBefore( nav, list.nextSibling );
		nav.parentNode.insertBefore( hint, nav.nextSibling );
		deck.classList.add( 'is-deck' );

		cards.forEach( function ( card ) {
			card.setAttribute( 'tabindex', '0' );
		} );

		function render() {
			cards.forEach( function ( card, position ) {
				const current = position === index;
				card.classList.toggle( 'is-current', current );
				card.classList.toggle( 'is-flipped', current && flipped );
			} );
			deck.setAttribute( 'data-front', front );
			flip.setAttribute( 'aria-pressed', flipped ? 'true' : 'false' );
			put( status, 'position', [ index + 1, cards.length ] );
		}

		function stopSound() {
			const playing = deck.querySelector( '.dlms-play.is-playing' );
			if ( playing ) {
				playing.click();
			}
		}

		function turn() {
			flipped = ! flipped;
			render();
			if ( flipped && 'picture' === front ) {
				const play = cards[ index ].querySelector( '.dlms-play' );
				if ( play && ! play.classList.contains( 'is-playing' ) ) {
					play.click();
				}
			}
		}

		function go( step ) {
			stopSound();
			index = ( index + step + cards.length ) % cards.length;
			flipped = false;
			render();
		}

		previous.addEventListener( 'click', function () {
			go( -1 );
		} );
		next.addEventListener( 'click', function () {
			go( 1 );
		} );
		flip.addEventListener( 'click', turn );

		select.addEventListener( 'change', function () {
			front = 'german' === select.value ? 'german' : 'picture';
			flipped = false;
			render();
		} );

		shuffle.addEventListener( 'click', function () {
			stopSound();
			for ( let i = cards.length - 1; i > 0; i-- ) {
				const j = Math.floor( Math.random() * ( i + 1 ) );
				const swap = cards[ i ];
				cards[ i ] = cards[ j ];
				cards[ j ] = swap;
			}
			cards.forEach( function ( card ) {
				list.appendChild( card );
			} );
			index = 0;
			flipped = false;
			render();
			const shuffled = put(
				document.createElement( 'span' ),
				'shuffled'
			);
			status.insertBefore(
				document.createTextNode( ' ' ),
				status.firstChild
			);
			status.insertBefore( shuffled, status.firstChild );
		} );

		all.addEventListener( 'click', function () {
			const showAll = 'true' !== all.getAttribute( 'aria-pressed' );
			all.setAttribute( 'aria-pressed', showAll ? 'true' : 'false' );
			put( all.firstChild, showAll ? 'one' : 'all' );
			deck.classList.toggle( 'is-deck', ! showAll );
		} );

		// A click on the card (not on its play button) turns it over.
		list.addEventListener( 'click', function ( event ) {
			if ( ! deck.classList.contains( 'is-deck' ) ) {
				return;
			}
			if ( event.target.closest( '.dlms-play' ) ) {
				return;
			}
			if ( event.target.closest( '.dlms-card.is-current' ) ) {
				turn();
			}
		} );

		deck.addEventListener( 'keydown', function ( event ) {
			if ( ! deck.classList.contains( 'is-deck' ) ) {
				return;
			}
			const tag = event.target.tagName;
			if ( 'SELECT' === tag || 'BUTTON' === tag ) {
				return;
			}
			if ( 'ArrowRight' === event.key ) {
				event.preventDefault();
				go( 1 );
				cards[ index ].focus();
			} else if ( 'ArrowLeft' === event.key ) {
				event.preventDefault();
				go( -1 );
				cards[ index ].focus();
			} else if (
				( ' ' === event.key || 'Enter' === event.key ) &&
				event.target.classList.contains( 'dlms-card' )
			) {
				event.preventDefault();
				turn();
			}
		} );

		render();
	}

	function start() {
		document
			.querySelectorAll( '.dlms-flashcards' )
			.forEach( function ( deck, number ) {
				setup( deck, number + 1 );
			} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
