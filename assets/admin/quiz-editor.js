/**
 * DeutschLMS quiz builder and question editor.
 *
 * Two modes (data-mode on the mount point):
 * - quiz: the quiz's items, bank questions (edited in place; a change reaches
 *   every quiz that uses the question) and random rules. Questions can be
 *   added new, picked from the question bank, or drawn at random.
 * - question: one bank question on its own screen.
 *
 * Edits go into a hidden JSON field (#dlms-questions-json) that is saved with
 * the post and sanitized on the server. All text is set through
 * textContent/value, never as HTML. Problems are flagged with the same rules
 * the server uses, so authors see why a question would be left out.
 *
 * @param {Object} wp WordPress script globals.
 */
( function ( wp ) {
	'use strict';

	const root = document.getElementById( 'dlms-quiz-editor' );
	const field = document.getElementById( 'dlms-questions-json' );
	if ( ! root || ! field || ! wp || ! wp.i18n ) {
		return;
	}

	const { __, _n, sprintf } = wp.i18n;
	const speak = wp.a11y && wp.a11y.speak ? wp.a11y.speak : function () {};
	const isQuiz = 'question' !== root.getAttribute( 'data-mode' );
	const canCreate = '0' !== root.getAttribute( 'data-can-create' );
	const types = parseJson( root.getAttribute( 'data-types' ), {} );
	// Noun picture library and picker (noun-pictures.js).
	const pictures = window.dlmsNounPictures || null;
	const pictureFields = [];
	if ( pictures ) {
		pictures.onChange( function () {
			pictureFields.forEach( function ( refresh ) {
				refresh();
			} );
		} );
	}
	const ARTICLES = [ 'der', 'die', 'das' ];
	const termOptions = Object.assign(
		{ category: [], difficulty: [], level: [] },
		parseJson( root.getAttribute( 'data-terms' ), {} )
	);
	const termNames = {};
	Object.keys( termOptions ).forEach( function ( name ) {
		termNames[ name ] = {};
		termOptions[ name ].forEach( function ( term ) {
			termNames[ name ][ term.id ] = term.name;
		} );
	} );

	let items = parseJson( field.value, [] );
	if ( ! Array.isArray( items ) ) {
		items = [];
	}
	items = items.map( normalize ).filter( Boolean );
	if ( ! isQuiz ) {
		const postId = items.length ? items[ 0 ].post_id : 0;
		if ( ! items.length || ! items[ 0 ].question ) {
			items = [ newQuestionItem() ];
			items[ 0 ].post_id = postId;
		}
		items = items.slice( 0, 1 );
	}

	const list = el( 'div', { className: 'dlms-qe__list' } );
	const pickerBox = el( 'div', { className: 'dlms-qe__picker-box' } );
	const picker = {
		open: false,
		filters: { search: '', type: '', category: 0, difficulty: 0, level: 0 },
		page: 0,
		pages: 0,
		total: 0,
		results: [],
		chosen: {},
		loading: false,
		error: '',
	};
	const counts = {};
	// Only the newest bank search may fill the picker (an older, slower one is ignored).
	let searchRun = 0;

	function parseJson( value, fallback ) {
		try {
			const parsed = JSON.parse( value || '' );
			return null === parsed ? fallback : parsed;
		} catch {
			return fallback;
		}
	}

	function uid( prefix ) {
		return prefix + Math.random().toString( 36 ).slice( 2, 10 );
	}

	function el( tag, attrs, children ) {
		const node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( 'text' === key ) {
				node.textContent = attrs[ key ];
			} else if ( 'className' === key ) {
				node.className = attrs[ key ];
			} else if ( 'checked' === key || 'disabled' === key ) {
				node[ key ] = !! attrs[ key ];
			} else if ( 'value' === key ) {
				node.value = attrs[ key ];
			} else {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( child );
			}
		} );
		return node;
	}

	function emptyTerms() {
		return { category: [], difficulty: 0, level: 0 };
	}

	function newQuestionItem() {
		return {
			kind: 'question',
			post_id: 0,
			status: '',
			editable: true,
			changed: true,
			edit_link: '',
			used_in: 0,
			terms: emptyTerms(),
			question: {
				id: uid( 'q_' ),
				type: 'single',
				text: '',
				points: 1,
				explanation: '',
				answers: [
					{ id: uid( 'a_' ), text: '', correct: true },
					{ id: uid( 'a_' ), text: '', correct: false },
				],
			},
		};
	}

	/**
	 * Accepts stored items and plain questions (older format).
	 *
	 * @param {Object} item Raw item.
	 * @return {Object|null} Item.
	 */
	function normalize( item ) {
		if ( ! item || 'object' !== typeof item ) {
			return null;
		}
		if ( 'random' === item.kind ) {
			return {
				kind: 'random',
				count: Math.max(
					1,
					Math.min( 50, parseInt( item.count, 10 ) || 1 )
				),
				category: parseInt( item.category, 10 ) || 0,
				difficulty: parseInt( item.difficulty, 10 ) || 0,
				level: parseInt( item.level, 10 ) || 0,
				type: item.type || '',
			};
		}
		if ( ! item.kind ) {
			item = { kind: 'question', post_id: 0, question: item };
		}
		item.post_id = parseInt( item.post_id, 10 ) || 0;
		item.editable = false !== item.editable;
		item.changed = ! item.post_id;
		item.used_in = parseInt( item.used_in, 10 ) || 0;
		item.terms = Object.assign( emptyTerms(), item.terms || {} );
		if ( item.question ) {
			item.question.answers = item.question.answers || [];
		}
		return item;
	}

	/**
	 * Same rules as Questions::problems() on the server.
	 *
	 * @param {Object} question Question.
	 * @return {string} Problem, or '' when fine.
	 */
	function problem( question ) {
		if ( ! question.text || ! question.text.trim() ) {
			return isQuiz
				? __(
						'Enter the question text, or this question is removed on save.',
						'deutschlms'
					)
				: __(
						'Enter the question text, or the question is not saved.',
						'deutschlms'
					);
		}
		if ( 'fill_blank' === question.type ) {
			const gaps = gapsOf( question.text );
			if ( ! gaps.length ) {
				return __(
					'Put at least one gap in curly braces, e.g. Ich {bin} müde.',
					'deutschlms'
				);
			}
			if ( gaps.length > 10 ) {
				return __( 'Use at most 10 gaps per question.', 'deutschlms' );
			}
			if (
				gaps.some( function ( accepted ) {
					return ! accepted.length;
				} )
			) {
				return __(
					'Every gap needs its answer inside the braces.',
					'deutschlms'
				);
			}
			return '';
		}
		if ( 'word_order' === question.type ) {
			const blocks = question.answers.filter( function ( answer ) {
				return ( answer.text || '' ).trim();
			} );
			return blocks.length < 2
				? __( 'Add at least two words or blocks.', 'deutschlms' )
				: '';
		}
		if ( 'article' === question.type ) {
			if ( /^(der|die|das)\s/i.test( question.text.trim() ) ) {
				return __(
					'Enter the noun without its article (Tisch, not der Tisch).',
					'deutschlms'
				);
			}
			return question.answers.some( function ( answer ) {
				return answer.correct;
			} )
				? ''
				: __( 'Mark the correct article.', 'deutschlms' );
		}
		const answers = question.answers.filter( function ( answer ) {
			return (
				'true_false' === question.type || ( answer.text || '' ).trim()
			);
		} );
		const correct = answers.filter( function ( answer ) {
			return answer.correct;
		} ).length;
		if ( answers.length < 2 ) {
			return __( 'Add at least two answers.', 'deutschlms' );
		}
		if ( 0 === correct ) {
			return __( 'Mark the correct answer.', 'deutschlms' );
		}
		if ( 'multiple' !== question.type && correct > 1 ) {
			return __(
				'Only one answer may be correct for this question type.',
				'deutschlms'
			);
		}
		return '';
	}

	/**
	 * Accepted answers per gap, like Questions::gaps() on the server.
	 *
	 * @param {string} text Text with {gaps}.
	 * @return {Array<string[]>} One list per gap.
	 */
	function gapsOf( text ) {
		const gaps = [];
		( text || '' ).replace( /\{([^{}]*)\}/g, function ( match, inner ) {
			gaps.push(
				inner
					.split( '|' )
					.map( function ( option ) {
						return option.replace( /\s+/g, ' ' ).trim();
					} )
					.filter( Boolean )
			);
			return match;
		} );
		return gaps;
	}

	function sync() {
		field.value = JSON.stringify( items );
		// Let the block editor (quiz screen) know there is something to save.
		if (
			isQuiz &&
			wp.data &&
			wp.data.dispatch &&
			wp.data.select &&
			wp.data.select( 'core/editor' )
		) {
			wp.data
				.dispatch( 'core/editor' )
				.editPost( { dlms_quiz_changed: Date.now() } );
		}
	}

	function setType( question, type ) {
		question.type = type;
		if ( 'fill_blank' === type ) {
			question.answers = [];
			return;
		}
		if ( 'word_order' === type ) {
			question.answers = question.answers
				.filter( function ( answer ) {
					return 'true' !== answer.id && 'false' !== answer.id;
				} )
				.map( function ( answer ) {
					return { id: answer.id, text: answer.text };
				} );
			while ( question.answers.length < 2 ) {
				question.answers.push( { id: uid( 'a_' ), text: '' } );
			}
			question.alternatives = question.alternatives || [];
			question.display = question.display || 'drag';
			return;
		}
		if ( 'article' === type ) {
			const marked = question.answers.find( function ( answer ) {
				return answer.correct && ARTICLES.includes( answer.id );
			} );
			question.answers = ARTICLES.map( function ( article ) {
				return {
					id: article,
					text: article,
					correct: !! marked && marked.id === article,
				};
			} );
			question.picture = question.picture || '';
			return;
		}
		if ( 'true_false' === type ) {
			const falseCorrect = question.answers.some( function ( answer ) {
				return 'false' === answer.id && answer.correct;
			} );
			question.answers = [
				{ id: 'true', text: '', correct: ! falseCorrect },
				{ id: 'false', text: '', correct: falseCorrect },
			];
		} else {
			question.answers = question.answers.filter( function ( answer ) {
				return 'true' !== answer.id && 'false' !== answer.id;
			} );
			while ( question.answers.length < 2 ) {
				question.answers.push( {
					id: uid( 'a_' ),
					text: '',
					correct: false,
				} );
			}
			if ( 'single' === type ) {
				let seen = false;
				question.answers.forEach( function ( answer ) {
					if ( answer.correct && seen ) {
						answer.correct = false;
					}
					seen = seen || answer.correct;
				} );
			}
		}
	}

	function moveButtons( label, index, length, onMove ) {
		return [ 'up', 'down' ].map( function ( direction ) {
			const move = el( 'button', {
				type: 'button',
				className: 'button-link',
				'data-move': direction,
				'aria-label': 'up' === direction ? label.up : label.down,
			} );
			move.appendChild(
				el( 'span', {
					className:
						'dashicons dashicons-arrow-' + direction + '-alt2',
					'aria-hidden': 'true',
				} )
			);
			move.addEventListener( 'click', function () {
				const target = 'up' === direction ? index - 1 : index + 1;
				if ( target >= 0 && target < length ) {
					onMove( target );
				}
			} );
			return move;
		} );
	}

	/**
	 * One block of a word-order question: text, move up/down, remove.
	 *
	 * @param {Object}     question Question.
	 * @param {Object}     answer   Block.
	 * @param {number}     qIndex   Question index.
	 * @param {number}     aIndex   Block index.
	 * @param {() => void} changed  Marks the question as changed.
	 * @return {HTMLElement} Row.
	 */
	function blockRow( question, answer, qIndex, aIndex, changed ) {
		const input = el( 'input', {
			type: 'text',
			className: 'regular-text',
			value: answer.text,
			maxlength: '500',
			'aria-label': sprintf(
				/* translators: %d: position of the word/block in the sentence. */
				__( 'Block %d', 'deutschlms' ),
				aIndex + 1
			),
		} );
		input.addEventListener( 'input', function () {
			answer.text = input.value;
			changed();
		} );
		input.addEventListener( 'change', function () {
			render( qIndex );
		} );

		const children = [
			el( 'span', {
				className: 'dlms-qe__position',
				text: String( aIndex + 1 ) + '.',
				'aria-hidden': 'true',
			} ),
			input,
		].concat(
			moveButtons(
				{
					up: __( 'Move block up', 'deutschlms' ),
					down: __( 'Move block down', 'deutschlms' ),
				},
				aIndex,
				question.answers.length,
				function ( target ) {
					question.answers.splice(
						target,
						0,
						question.answers.splice( aIndex, 1 )[ 0 ]
					);
					changed();
					render( qIndex );
					speak( __( 'Block moved.', 'deutschlms' ) );
				}
			)
		);
		const remove = el( 'button', {
			type: 'button',
			className: 'button-link button-link-delete',
			text: __( 'Remove', 'deutschlms' ),
		} );
		remove.addEventListener( 'click', function () {
			question.answers.splice( aIndex, 1 );
			changed();
			render( qIndex );
		} );
		children.push( remove );
		return el( 'div', { className: 'dlms-qe__answer' }, children );
	}

	function answerRow( question, answer, qIndex, aIndex, changed ) {
		if ( 'word_order' === question.type ) {
			return blockRow( question, answer, qIndex, aIndex, changed );
		}
		const isTrueFalse = 'true_false' === question.type;
		const isArticle = 'article' === question.type;
		const groupName = 'dlms-q-' + qIndex + '-' + question.id;
		const toggle = el( 'input', {
			type: 'multiple' === question.type ? 'checkbox' : 'radio',
			name: groupName,
			checked: answer.correct,
			'aria-label': __( 'Correct answer', 'deutschlms' ),
		} );
		toggle.addEventListener( 'change', function () {
			if ( 'multiple' === question.type ) {
				answer.correct = toggle.checked;
			} else {
				question.answers.forEach( function ( other ) {
					other.correct = other === answer;
				} );
			}
			changed();
			render( qIndex );
		} );

		const children = [ toggle ];
		if ( isArticle ) {
			children.push(
				el( 'span', {
					className:
						'dlms-qe__article dlms-qe__article--' + answer.id,
					text: answer.id,
				} )
			);
		} else if ( isTrueFalse ) {
			children.push(
				el( 'span', {
					text:
						'true' === answer.id
							? __( 'True', 'deutschlms' )
							: __( 'False', 'deutschlms' ),
				} )
			);
		} else {
			const input = el( 'input', {
				type: 'text',
				className: 'regular-text',
				value: answer.text,
				maxlength: '500',
				'aria-label': sprintf(
					/* translators: %d: answer number. */
					__( 'Answer %d', 'deutschlms' ),
					aIndex + 1
				),
			} );
			input.addEventListener( 'input', function () {
				answer.text = input.value;
				changed();
			} );
			input.addEventListener( 'change', function () {
				render( qIndex );
			} );
			const remove = el( 'button', {
				type: 'button',
				className: 'button-link button-link-delete',
				text: __( 'Remove', 'deutschlms' ),
			} );
			remove.addEventListener( 'click', function () {
				question.answers.splice( aIndex, 1 );
				changed();
				render( qIndex );
			} );
			children.push( input, remove );
		}
		return el( 'label', { className: 'dlms-qe__answer' }, children );
	}

	/**
	 * A select of terms (or question types) with an "any/none" option.
	 *
	 * @param {string}                           name     category, difficulty, level or type.
	 * @param {number|string}                    value    Selected value.
	 * @param {string}                           empty    Label of the empty option.
	 * @param {string}                           label    Accessible label.
	 * @param {(value: (number|string)) => void} onChange Receives the new value.
	 * @return {HTMLElement} Select.
	 */
	function termSelect( name, value, empty, label, onChange ) {
		const select = el( 'select', { 'aria-label': label } );
		select.appendChild(
			el( 'option', { value: 'type' === name ? '' : '0', text: empty } )
		);
		if ( 'type' === name ) {
			Object.keys( types ).forEach( function ( key ) {
				select.appendChild(
					el( 'option', { value: key, text: types[ key ] } )
				);
			} );
		} else {
			termOptions[ name ].forEach( function ( term ) {
				select.appendChild(
					el( 'option', {
						value: String( term.id ),
						text: '— '.repeat( term.depth ) + term.name,
					} )
				);
			} );
		}
		select.value = String( value || ( 'type' === name ? '' : 0 ) );
		select.addEventListener( 'change', function () {
			onChange(
				'type' === name
					? select.value
					: parseInt( select.value, 10 ) || 0
			);
		} );
		return select;
	}

	function termsRow( item, changed ) {
		const row = el( 'div', { className: 'dlms-qe__terms' } );
		const categories = item.terms.category || [];
		if ( categories.length > 1 ) {
			row.appendChild(
				el( 'span', {
					text: sprintf(
						/* translators: %s: category names. */
						__( 'Categories: %s', 'deutschlms' ),
						categories
							.map( function ( id ) {
								return termNames.category[ id ] || '?';
							} )
							.join( ', ' )
					),
				} )
			);
		} else {
			row.appendChild(
				el( 'label', {}, [
					document.createTextNode(
						__( 'Category', 'deutschlms' ) + ' '
					),
					termSelect(
						'category',
						categories[ 0 ] || 0,
						__( '— None —', 'deutschlms' ),
						__( 'Category', 'deutschlms' ),
						function ( value ) {
							item.terms.category = value ? [ value ] : [];
							changed();
						}
					),
				] )
			);
		}
		[
			[ 'difficulty', __( 'Difficulty', 'deutschlms' ) ],
			[ 'level', __( 'CEFR level', 'deutschlms' ) ],
		].forEach( function ( pair ) {
			row.appendChild(
				el( 'label', {}, [
					document.createTextNode( pair[ 1 ] + ' ' ),
					termSelect(
						pair[ 0 ],
						item.terms[ pair[ 0 ] ],
						__( '— None —', 'deutschlms' ),
						pair[ 1 ],
						function ( value ) {
							item.terms[ pair[ 0 ] ] = value;
							changed();
						}
					),
				] )
			);
		} );
		return row;
	}

	function bankNote( item ) {
		const note = el( 'p', { className: 'dlms-qe__bank' } );
		if ( ! item.post_id ) {
			note.textContent = __(
				'New: saved to the question bank when you save the quiz.',
				'deutschlms'
			);
			return note;
		}
		if ( 'trash' === item.status ) {
			note.classList.add( 'is-warning' );
			note.textContent = __(
				'This question is in the trash, so students do not see it. Restore it in the question bank, or remove it from this quiz.',
				'deutschlms'
			);
			return note;
		}
		if ( item.used_in > 0 ) {
			note.classList.add( 'is-shared' );
			note.appendChild(
				document.createTextNode(
					sprintf(
						/* translators: %d: number of other quizzes. */
						_n(
							'Also used in %d other quiz: changes appear there too.',
							'Also used in %d other quizzes: changes appear there too.',
							item.used_in,
							'deutschlms'
						),
						item.used_in
					) + ' '
				)
			);
		} else {
			note.appendChild(
				document.createTextNode(
					__( 'From the question bank.', 'deutschlms' ) + ' '
				)
			);
		}
		if ( item.status && 'publish' !== item.status ) {
			note.appendChild(
				document.createTextNode(
					__(
						'(Draft: shown in this quiz, but not drawn as a random question.)',
						'deutschlms'
					) + ' '
				)
			);
		}
		if ( item.edit_link ) {
			note.appendChild(
				el( 'a', {
					href: item.edit_link,
					target: '_blank',
					rel: 'noopener',
					text: __( 'Open in question bank', 'deutschlms' ),
				} )
			);
		}
		return note;
	}

	function questionCard( item, index ) {
		const question = item.question;
		const changed = function () {
			item.changed = true;
			sync();
		};
		const card = el( 'div', {
			className: 'dlms-qe__question',
			'data-index': String( index ),
		} );

		const typeSelect = el( 'select', {
			'aria-label': __( 'Question type', 'deutschlms' ),
		} );
		Object.keys( types ).forEach( function ( key ) {
			const option = el( 'option', { value: key, text: types[ key ] } );
			option.selected = key === question.type;
			typeSelect.appendChild( option );
		} );
		typeSelect.addEventListener( 'change', function () {
			setType( question, typeSelect.value );
			changed();
			render( index );
		} );

		const points = el( 'input', {
			type: 'number',
			min: '1',
			max: '100',
			className: 'small-text',
			value: String( question.points || 1 ),
			'aria-label': __( 'Points', 'deutschlms' ),
		} );
		points.addEventListener( 'change', function () {
			question.points = Math.max(
				1,
				Math.min( 100, parseInt( points.value, 10 ) || 1 )
			);
			changed();
		} );

		const toolbar = el( 'div', { className: 'dlms-qe__toolbar' }, [
			isQuiz
				? el( 'strong', {
						text: sprintf(
							/* translators: %d: position in the quiz. */
							__( 'Question %d', 'deutschlms' ),
							index + 1
						),
					} )
				: null,
			typeSelect,
			el( 'label', {}, [
				document.createTextNode( __( 'Points', 'deutschlms' ) + ' ' ),
				points,
			] ),
		] );

		if ( isQuiz ) {
			moveItemButtons( index, __( 'question', 'deutschlms' ) ).forEach(
				function ( button ) {
					toolbar.appendChild( button );
				}
			);
			const remove = el( 'button', {
				type: 'button',
				className: 'button-link button-link-delete',
				text: item.post_id
					? __( 'Remove from quiz', 'deutschlms' )
					: __( 'Remove question', 'deutschlms' ),
			} );
			remove.addEventListener( 'click', function () {
				/* eslint-disable no-alert -- A native confirm is the simplest accessible guard here. */
				const confirmed = window.confirm(
					item.post_id
						? __(
								'Remove this question from the quiz? It stays in the question bank.',
								'deutschlms'
							)
						: __( 'Remove this question?', 'deutschlms' )
				);
				/* eslint-enable no-alert */
				if ( ! confirmed ) {
					return;
				}
				items.splice( index, 1 );
				sync();
				render( Math.min( index, items.length - 1 ) );
				speak( __( 'Question removed from the quiz.', 'deutschlms' ) );
			} );
			toolbar.appendChild( remove );
		}
		card.appendChild( toolbar );

		if ( isQuiz ) {
			card.appendChild( bankNote( item ) );
		} else if ( item.used_in > 1 ) {
			card.appendChild(
				el( 'p', {
					className: 'dlms-qe__bank is-shared',
					text: sprintf(
						/* translators: %d: number of quizzes. */
						__(
							'Used in %d quizzes: changes appear in all of them.',
							'deutschlms'
						),
						item.used_in
					),
				} )
			);
		}

		const body = el( 'fieldset', { className: 'dlms-qe__body' } );
		if ( ! item.editable ) {
			body.disabled = true;
			card.appendChild(
				el( 'p', {
					className: 'dlms-qe__bank is-warning',
					text: __(
						'You cannot change this question (it belongs to another author or is in the trash).',
						'deutschlms'
					),
				} )
			);
			typeSelect.disabled = true;
			points.disabled = true;
		}
		card.appendChild( body );

		const textId = 'dlms-qe-text-' + index + '-' + question.id;
		const text = el( 'textarea', {
			id: textId,
			className: 'large-text',
			rows: '2',
			maxlength: '2000',
		} );
		text.value = question.text || '';
		text.addEventListener( 'input', function () {
			question.text = text.value;
			changed();
		} );
		text.addEventListener( 'change', function () {
			render( index );
		} );
		let textLabel = __( 'Question', 'deutschlms' );
		if ( 'fill_blank' === question.type ) {
			textLabel = __( 'Text with gaps', 'deutschlms' );
		} else if ( 'word_order' === question.type ) {
			textLabel = __( 'Instruction', 'deutschlms' );
		} else if ( 'article' === question.type ) {
			textLabel = __( 'Noun (without article)', 'deutschlms' );
		}
		body.appendChild(
			el( 'label', {
				for: textId,
				className: 'dlms-qe__label',
				text: textLabel,
			} )
		);
		body.appendChild( text );

		if ( 'fill_blank' === question.type ) {
			body.appendChild(
				el( 'p', {
					className: 'description',
					text: __(
						'Put each gap in curly braces with its answer. Separate other accepted answers with |. Example: Ich {bin} gestern nach Berlin {gefahren}. / Er {ist|war} müde. Capital letters and ä, ö, ü, ß count.',
						'deutschlms'
					),
				} )
			);
			body.appendChild(
				el( 'p', {
					className: 'dlms-qe__gaps',
					text: sprintf(
						/* translators: %d: number of gaps found. */
						__( 'Gaps found: %d', 'deutschlms' ),
						gapsOf( question.text ).length
					),
				} )
			);
		}

		if ( 'word_order' === question.type ) {
			body.appendChild(
				el( 'p', {
					className: 'description',
					text: __(
						'Students see the blocks shuffled and put them in order. Enter them here in the correct order; write the first word in lower case unless it is a noun, so it does not give the answer away.',
						'deutschlms'
					),
				} )
			);
		}

		if ( 'article' === question.type ) {
			body.appendChild(
				el( 'p', {
					className: 'description',
					text: __(
						'Students see the noun with its picture and choose der (blue), die (red) or das (green). Write the noun with a capital letter and without its article, e.g. Tisch.',
						'deutschlms'
					),
				} )
			);
		}

		const answers = el( 'div', { className: 'dlms-qe__answers' } );
		let answersLabel = __(
			'Answers (select the correct one)',
			'deutschlms'
		);
		if ( 'article' === question.type ) {
			answersLabel = __( 'Correct article', 'deutschlms' );
		} else if ( 'multiple' === question.type ) {
			answersLabel = __(
				'Answers (tick every correct one)',
				'deutschlms'
			);
		} else if ( 'word_order' === question.type ) {
			answersLabel = __(
				'Words or blocks, in the correct order',
				'deutschlms'
			);
		}
		if ( 'fill_blank' !== question.type ) {
			answers.appendChild(
				el( 'p', { className: 'dlms-qe__label', text: answersLabel } )
			);
		}
		question.answers.forEach( function ( answer, aIndex ) {
			answers.appendChild(
				answerRow( question, answer, index, aIndex, changed )
			);
		} );
		if (
			'true_false' !== question.type &&
			'fill_blank' !== question.type &&
			'article' !== question.type &&
			question.answers.length < 20
		) {
			const add = el( 'button', {
				type: 'button',
				className: 'button',
				text:
					'word_order' === question.type
						? __( 'Add block', 'deutschlms' )
						: __( 'Add answer', 'deutschlms' ),
			} );
			add.addEventListener( 'click', function () {
				question.answers.push(
					'word_order' === question.type
						? { id: uid( 'a_' ), text: '' }
						: { id: uid( 'a_' ), text: '', correct: false }
				);
				changed();
				render( index );
				const inputs = list.querySelectorAll(
					'[data-index="' +
						index +
						'"] .dlms-qe__answer input[type="text"]'
				);
				if ( inputs.length ) {
					inputs[ inputs.length - 1 ].focus();
				}
			} );
			answers.appendChild( add );
		}
		body.appendChild( answers );

		if ( 'article' === question.type ) {
			body.appendChild( pictureField( question, index, changed ) );
		}

		if ( 'word_order' === question.type ) {
			const altId = 'dlms-qe-alt-' + index + '-' + question.id;
			const alternatives = el( 'textarea', {
				id: altId,
				className: 'large-text',
				rows: '2',
			} );
			alternatives.value = ( question.alternatives || [] ).join( '\n' );
			alternatives.addEventListener( 'input', function () {
				question.alternatives = alternatives.value
					.split( '\n' )
					.map( function ( line ) {
						return line.trim();
					} )
					.filter( Boolean );
				changed();
			} );
			body.appendChild(
				el( 'label', {
					for: altId,
					className: 'dlms-qe__label',
					text: __(
						'Other correct orders (optional, one sentence per line)',
						'deutschlms'
					),
				} )
			);
			body.appendChild( alternatives );

			const displayId = 'dlms-qe-display-' + index + '-' + question.id;
			const display = el( 'select', { id: displayId } );
			[
				[
					'drag',
					__( 'Drag and drop (or click the words)', 'deutschlms' ),
				],
				[
					'select',
					__(
						'Dropdowns (each word can be chosen once)',
						'deutschlms'
					),
				],
			].forEach( function ( choice ) {
				display.appendChild(
					el( 'option', { value: choice[ 0 ], text: choice[ 1 ] } )
				);
			} );
			display.value = 'select' === question.display ? 'select' : 'drag';
			display.addEventListener( 'change', function () {
				question.display = display.value;
				changed();
			} );
			body.appendChild(
				el( 'label', {
					for: displayId,
					className: 'dlms-qe__label',
					text: __( 'How students answer', 'deutschlms' ),
				} )
			);
			body.appendChild( display );
		}

		const explanationId = 'dlms-qe-expl-' + index + '-' + question.id;
		const explanation = el( 'textarea', {
			id: explanationId,
			className: 'large-text',
			rows: '2',
			maxlength: '2000',
		} );
		explanation.value = question.explanation || '';
		explanation.addEventListener( 'input', function () {
			question.explanation = explanation.value;
			changed();
		} );
		body.appendChild(
			el( 'label', {
				for: explanationId,
				className: 'dlms-qe__label',
				text: __(
					'Explanation (optional, shown with the correct answers)',
					'deutschlms'
				),
			} )
		);
		body.appendChild( explanation );

		if ( isQuiz ) {
			body.appendChild( termsRow( item, changed ) );
		}

		const issue = problem( question );
		if ( issue ) {
			card.classList.add( 'has-problem' );
			card.appendChild(
				el( 'p', { className: 'dlms-qe__problem', text: issue } )
			);
		}
		return card;
	}

	/**
	 * Picture of an article question. It comes from the noun picture library
	 * (one picture per noun): the buttons change the noun's picture there,
	 * saved right away, for every question and card with that noun. A
	 * compound noun can use another noun's picture (die Tischlampe → Lampe).
	 *
	 * @param {Object}     question Article question.
	 * @param {number}     index    Item index.
	 * @param {() => void} changed  Marks the question as changed.
	 * @return {HTMLElement} Field.
	 */
	function pictureField( question, index, changed ) {
		const box = el( 'div', { className: 'dlms-qe__picture' } );
		if ( ! pictures ) {
			return box;
		}
		const selectId = 'dlms-qe-picture-' + index + '-' + question.id;
		const own = question.picture || '';

		function refresh() {
			box.textContent = '';
			const nounKey = pictures.keyFor( question.text );
			const usesOther = own && -1 === own.indexOf( ':' ) ? own : '';
			const targetKey = usesOther || nounKey;
			const found = targetKey ? pictures.entry( targetKey ) : null;
			const targetName = found
				? found.noun
				: usesOther || ( question.text || '' ).trim();
			const article = ( question.answers || [] ).find(
				function ( answer ) {
					return answer.correct;
				}
			);

			box.appendChild(
				el( 'span', {
					className: 'dlms-qe__label',
					text: __( 'Picture', 'deutschlms' ),
				} )
			);

			const row = el( 'span', { className: 'dlms-qe__picture-row' } );
			if ( own && -1 !== own.indexOf( ':' ) ) {
				// Set elsewhere (import or code): an own icon or image.
				const url = pictures.previewUrl( own );
				if ( url ) {
					row.appendChild(
						el( 'img', { src: url, alt: '', width: '48' } )
					);
				}
				row.appendChild(
					el( 'span', {
						text: __(
							'This question has its own picture.',
							'deutschlms'
						),
					} )
				);
			} else if ( ! targetKey ) {
				row.appendChild(
					el( 'span', {
						className: 'description',
						text: __( 'Enter the noun first.', 'deutschlms' ),
					} )
				);
			} else {
				if ( found && found.url ) {
					row.appendChild(
						pictures.preview( found, article ? article.id : '' )
					);
				}
				row.appendChild(
					el( 'span', {
						text: found
							? sprintf(
									/* translators: %s: noun. */
									__( 'Picture of %s', 'deutschlms' ),
									targetName
								)
							: sprintf(
									/* translators: %s: noun. */
									__(
										'%s has no picture yet.',
										'deutschlms'
									),
									targetName
								),
					} )
				);
				const chooseIcon = el( 'button', {
					type: 'button',
					className: 'button',
					text: __( 'Choose icon', 'deutschlms' ),
				} );
				chooseIcon.addEventListener( 'click', function () {
					pictures
						.chooseIcon(
							sprintf(
								/* translators: %s: noun. */
								__( 'Icon for %s', 'deutschlms' ),
								targetName
							)
						)
						.then( function ( name ) {
							return name
								? pictures.save( targetName, 'icon:' + name )
								: null;
						} )
						.catch( saveFailed );
				} );
				row.appendChild( chooseIcon );
				if ( pictures.canUpload ) {
					const chooseImage = el( 'button', {
						type: 'button',
						className: 'button',
						text: __( 'Upload or choose image', 'deutschlms' ),
					} );
					chooseImage.addEventListener( 'click', function () {
						pictures
							.chooseImage(
								sprintf(
									/* translators: %s: noun. */
									__( 'Image for %s', 'deutschlms' ),
									targetName
								)
							)
							.then( function ( image ) {
								return image
									? pictures.save(
											targetName,
											'media:' + image.id
										)
									: null;
							} )
							.catch( saveFailed );
					} );
					row.appendChild( chooseImage );
				}
			}
			box.appendChild( row );
			if ( targetKey ) {
				box.appendChild(
					el( 'p', {
						className: 'description',
						text: sprintf(
							/* translators: %s: noun. */
							__(
								'Saved right away for every question and noun card with %s (Courses → Noun pictures).',
								'deutschlms'
							),
							targetName
						),
					} )
				);
			}

			// Compound nouns: use another noun's picture.
			const select = el( 'select', { id: selectId } );
			select.appendChild(
				el( 'option', {
					value: '',
					text: __( '— This noun —', 'deutschlms' ),
				} )
			);
			pictures.nouns().forEach( function ( item ) {
				if ( item.key !== nounKey ) {
					select.appendChild(
						el( 'option', { value: item.key, text: item.noun } )
					);
				}
			} );
			if (
				usesOther &&
				! select.querySelector( 'option[value="' + usesOther + '"]' )
			) {
				select.appendChild(
					el( 'option', { value: usesOther, text: usesOther } )
				);
			}
			select.value = usesOther;
			select.disabled = !! own && -1 !== own.indexOf( ':' );
			select.addEventListener( 'change', function () {
				question.picture = select.value;
				changed();
				render( index );
			} );
			box.appendChild(
				el( 'p', { className: 'dlms-qe__picture-other' }, [
					el( 'label', {
						for: selectId,
						text: __(
							'Use the picture of another noun (for compound nouns, e.g. die Tischlampe → Lampe):',
							'deutschlms'
						),
					} ),
					document.createTextNode( ' ' ),
					select,
				] )
			);
		}

		function saveFailed( error ) {
			speak(
				( error && error.message ) ||
					__( 'The picture could not be saved.', 'deutschlms' ),
				'assertive'
			);
			// eslint-disable-next-line no-alert -- A native alert is the simplest accessible error message here.
			window.alert(
				( error && error.message ) ||
					__( 'The picture could not be saved.', 'deutschlms' )
			);
		}

		refresh();
		// Library changes (here or in another question) update every box.
		pictureFields.push( function () {
			if ( box.isConnected ) {
				refresh();
			}
		} );
		return box;
	}

	function moveItemButtons( index, what ) {
		return moveButtons(
			{
				/* translators: %s: "question" or "rule". */
				up: sprintf( __( 'Move %s up', 'deutschlms' ), what ),
				/* translators: %s: "question" or "rule". */
				down: sprintf( __( 'Move %s down', 'deutschlms' ), what ),
			},
			index,
			items.length,
			function ( target ) {
				items.splice( target, 0, items.splice( index, 1 )[ 0 ] );
				sync();
				render( target );
				speak( __( 'Moved.', 'deutschlms' ) );
			}
		);
	}

	function ruleQuery( rule ) {
		const params = new URLSearchParams();
		[ 'type', 'category', 'difficulty', 'level' ].forEach(
			function ( key ) {
				if ( rule[ key ] ) {
					params.set( key, String( rule[ key ] ) );
				}
			}
		);
		return params.toString();
	}

	/**
	 * "N matching questions" for a random rule (fetched once per filter set).
	 *
	 * @param {Object}      rule Rule.
	 * @param {HTMLElement} out  Where to write the result.
	 */
	function showMatches( rule, out ) {
		const query = ruleQuery( rule );
		const write = function () {
			const count = counts[ query ];
			out.classList.toggle( 'is-warning', count < rule.count );
			if ( count < rule.count ) {
				out.textContent = sprintf(
					/* translators: 1: matching questions, 2: questions asked for. */
					__(
						'Only %1$d complete questions match, so students get %1$d instead of %2$d.',
						'deutschlms'
					),
					count,
					rule.count
				);
			} else {
				out.textContent = sprintf(
					/* translators: %d: number of matching questions. */
					_n(
						'%d complete question in the bank matches.',
						'%d complete questions in the bank match.',
						count,
						'deutschlms'
					),
					count
				);
			}
		};
		if ( 'number' === typeof counts[ query ] ) {
			write();
			return;
		}
		if ( ! wp.apiFetch ) {
			return;
		}
		out.textContent = __( 'Counting matching questions…', 'deutschlms' );
		wp.apiFetch( {
			path: '/dlms/v1/questions/count' + ( query ? '?' + query : '' ),
		} )
			.then( function ( response ) {
				counts[ query ] = parseInt( response.count, 10 ) || 0;
				// The rule may have changed while this count was loading.
				if ( ruleQuery( rule ) === query ) {
					write();
				}
			} )
			.catch( function () {
				out.textContent = '';
			} );
	}

	function randomCard( rule, index ) {
		const card = el( 'div', {
			className: 'dlms-qe__question dlms-qe__random',
			'data-index': String( index ),
		} );
		const toolbar = el( 'div', { className: 'dlms-qe__toolbar' }, [
			el( 'strong', {
				text: sprintf(
					/* translators: %d: position in the quiz. */
					__( 'Random questions (position %d)', 'deutschlms' ),
					index + 1
				),
			} ),
		] );
		moveItemButtons( index, __( 'rule', 'deutschlms' ) ).forEach(
			function ( button ) {
				toolbar.appendChild( button );
			}
		);
		const remove = el( 'button', {
			type: 'button',
			className: 'button-link button-link-delete',
			text: __( 'Remove', 'deutschlms' ),
		} );
		remove.addEventListener( 'click', function () {
			items.splice( index, 1 );
			sync();
			render( Math.min( index, items.length - 1 ) );
			speak( __( 'Random questions removed.', 'deutschlms' ) );
		} );
		toolbar.appendChild( remove );
		card.appendChild( toolbar );

		card.appendChild(
			el( 'p', {
				className: 'description',
				text: __(
					'Each student gets different questions from the bank, drawn when they open the quiz and kept until they submit. Questions already in this quiz are never drawn twice.',
					'deutschlms'
				),
			} )
		);

		const matches = el( 'p', {
			className: 'dlms-qe__matches',
			'aria-live': 'polite',
		} );
		const update = function () {
			sync();
			showMatches( rule, matches );
		};

		const count = el( 'input', {
			type: 'number',
			min: '1',
			max: '50',
			className: 'small-text',
			value: String( rule.count ),
		} );
		count.addEventListener( 'change', function () {
			rule.count = Math.max(
				1,
				Math.min( 50, parseInt( count.value, 10 ) || 1 )
			);
			count.value = String( rule.count );
			update();
		} );

		const row = el( 'div', { className: 'dlms-qe__terms' }, [
			el( 'label', {}, [
				document.createTextNode(
					__( 'Number of questions', 'deutschlms' ) + ' '
				),
				count,
			] ),
		] );
		[
			[
				'category',
				__( 'Category', 'deutschlms' ),
				__( 'Any category', 'deutschlms' ),
			],
			[
				'difficulty',
				__( 'Difficulty', 'deutschlms' ),
				__( 'Any difficulty', 'deutschlms' ),
			],
			[
				'level',
				__( 'CEFR level', 'deutschlms' ),
				__( 'Any level', 'deutschlms' ),
			],
			[
				'type',
				__( 'Type', 'deutschlms' ),
				__( 'Any type', 'deutschlms' ),
			],
		].forEach( function ( spec ) {
			row.appendChild(
				el( 'label', {}, [
					document.createTextNode( spec[ 1 ] + ' ' ),
					termSelect(
						spec[ 0 ],
						rule[ spec[ 0 ] ],
						spec[ 2 ],
						spec[ 1 ],
						function ( value ) {
							rule[ spec[ 0 ] ] = value;
							update();
						}
					),
				] )
			);
		} );
		card.appendChild( row );
		card.appendChild( matches );
		showMatches( rule, matches );
		return card;
	}

	function linkedIds() {
		const ids = {};
		items.forEach( function ( item ) {
			if ( 'question' === item.kind && item.post_id ) {
				ids[ item.post_id ] = true;
			}
		} );
		return ids;
	}

	function search( more ) {
		if ( ! wp.apiFetch ) {
			return;
		}
		const params = new URLSearchParams();
		Object.keys( picker.filters ).forEach( function ( key ) {
			if ( picker.filters[ key ] ) {
				params.set( key, String( picker.filters[ key ] ) );
			}
		} );
		params.set( 'page', String( more ? picker.page + 1 : 1 ) );
		params.set( 'per_page', '20' );
		const run = ++searchRun;
		picker.loading = true;
		picker.error = '';
		renderPicker();
		wp.apiFetch( { path: '/dlms/v1/questions?' + params.toString() } )
			.then( function ( response ) {
				if ( run !== searchRun ) {
					return;
				}
				picker.page = more ? picker.page + 1 : 1;
				picker.pages = response.pages || 0;
				picker.total = response.total || 0;
				picker.results = ( more ? picker.results : [] ).concat(
					( response.items || [] ).map( normalize )
				);
				picker.loading = false;
				renderPicker();
				speak(
					sprintf(
						/* translators: %d: number of questions found. */
						_n(
							'%d question found.',
							'%d questions found.',
							picker.total,
							'deutschlms'
						),
						picker.total
					)
				);
			} )
			.catch( function ( error ) {
				if ( run !== searchRun ) {
					return;
				}
				picker.loading = false;
				picker.error =
					( error && error.message ) ||
					__(
						'The question bank could not be loaded.',
						'deutschlms'
					);
				renderPicker();
			} );
	}

	function describe( item ) {
		const parts = [ types[ item.question.type ] || item.question.type ];
		( item.terms.category || [] ).forEach( function ( id ) {
			if ( termNames.category[ id ] ) {
				parts.push( termNames.category[ id ] );
			}
		} );
		[ 'difficulty', 'level' ].forEach( function ( name ) {
			if ( termNames[ name ][ item.terms[ name ] ] ) {
				parts.push( termNames[ name ][ item.terms[ name ] ] );
			}
		} );
		parts.push(
			item.used_in
				? sprintf(
						/* translators: %d: number of quizzes. */
						_n(
							'used in %d quiz',
							'used in %d quizzes',
							item.used_in,
							'deutschlms'
						),
						item.used_in
					)
				: __( 'not used yet', 'deutschlms' )
		);
		if ( item.status && 'publish' !== item.status ) {
			parts.push( __( 'draft', 'deutschlms' ) );
		}
		return parts.join( ' · ' );
	}

	function questionPreview( question ) {
		let text = ( question.text || '' ).replace( /\{([^{}]*)\}/g, '[$1]' );
		if ( 'article' === question.type ) {
			const correct = ( question.answers || [] ).find(
				function ( answer ) {
					return answer.correct;
				}
			);
			text = ( correct ? correct.id + ' ' : '' ) + text;
		}
		return text.length > 160 ? text.slice( 0, 159 ) + '…' : text;
	}

	function renderPicker() {
		const active = pickerBox.ownerDocument.activeElement;
		const keepFocus =
			!! active &&
			pickerBox.contains( active ) &&
			'search' === active.type;
		pickerBox.textContent = '';
		if ( ! picker.open ) {
			return;
		}
		const box = el( 'div', {
			className: 'dlms-qe__picker',
			role: 'region',
			'aria-label': __( 'Add from question bank', 'deutschlms' ),
		} );
		box.appendChild(
			el( 'h3', { text: __( 'Add from question bank', 'deutschlms' ) } )
		);

		const searchInput = el( 'input', {
			type: 'search',
			className: 'regular-text',
			value: picker.filters.search,
			placeholder: __( 'Search question text or answers', 'deutschlms' ),
			'aria-label': __( 'Search questions', 'deutschlms' ),
		} );
		searchInput.addEventListener( 'input', function () {
			picker.filters.search = searchInput.value;
		} );
		searchInput.addEventListener( 'keydown', function ( event ) {
			// Enter would submit the whole post form.
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				search( false );
			}
		} );
		const filters = el( 'div', { className: 'dlms-qe__terms' }, [
			searchInput,
		] );
		[
			[ 'type', __( 'All types', 'deutschlms' ) ],
			[ 'category', __( 'All categories', 'deutschlms' ) ],
			[ 'difficulty', __( 'All difficulty levels', 'deutschlms' ) ],
			[ 'level', __( 'All CEFR levels', 'deutschlms' ) ],
		].forEach( function ( spec ) {
			filters.appendChild(
				termSelect(
					spec[ 0 ],
					picker.filters[ spec[ 0 ] ],
					spec[ 1 ],
					spec[ 1 ],
					function ( value ) {
						picker.filters[ spec[ 0 ] ] = value;
						search( false );
					}
				)
			);
		} );
		const go = el( 'button', {
			type: 'button',
			className: 'button',
			text: __( 'Search', 'deutschlms' ),
		} );
		go.addEventListener( 'click', function () {
			search( false );
		} );
		filters.appendChild( go );
		box.appendChild( filters );

		const status = el( 'p', {
			className: 'dlms-qe__picker-status',
			'aria-live': 'polite',
		} );
		if ( picker.error ) {
			status.classList.add( 'is-warning' );
			status.textContent = picker.error;
		} else if ( picker.loading ) {
			status.textContent = __( 'Loading…', 'deutschlms' );
		} else if ( picker.page ) {
			status.textContent = sprintf(
				/* translators: 1: questions shown, 2: questions found. */
				__( 'Showing %1$d of %2$d questions.', 'deutschlms' ),
				picker.results.length,
				picker.total
			);
		}
		box.appendChild( status );

		const linked = linkedIds();
		const results = el( 'ul', { className: 'dlms-qe__results' } );
		picker.results.forEach( function ( result ) {
			const inQuiz = !! linked[ result.post_id ];
			const box2 = el( 'input', {
				type: 'checkbox',
				checked: inQuiz || !! picker.chosen[ result.post_id ],
				disabled: inQuiz,
			} );
			box2.addEventListener( 'change', function () {
				if ( box2.checked ) {
					picker.chosen[ result.post_id ] = result;
				} else {
					delete picker.chosen[ result.post_id ];
				}
				renderPickerFooter();
			} );
			results.appendChild(
				el( 'li', {}, [
					el( 'label', {}, [
						box2,
						el( 'span', { className: 'dlms-qe__result' }, [
							el( 'span', {
								className: 'dlms-qe__result-text',
								text: questionPreview( result.question ),
							} ),
							el( 'span', {
								className: 'dlms-qe__result-meta',
								text: inQuiz
									? describe( result ) +
										' · ' +
										__(
											'already in this quiz',
											'deutschlms'
										)
									: describe( result ),
							} ),
						] ),
					] ),
				] )
			);
		} );
		if ( picker.results.length ) {
			box.appendChild( results );
		}
		if ( picker.page && picker.page < picker.pages && ! picker.loading ) {
			const more = el( 'button', {
				type: 'button',
				className: 'button',
				text: __( 'Show more', 'deutschlms' ),
			} );
			more.addEventListener( 'click', function () {
				search( true );
			} );
			box.appendChild( el( 'p', {}, [ more ] ) );
		}
		box.appendChild( el( 'div', { className: 'dlms-qe__picker-footer' } ) );
		pickerBox.appendChild( box );
		if ( keepFocus ) {
			searchInput.focus();
		}
		renderPickerFooter();
	}

	function renderPickerFooter() {
		const footer = pickerBox.querySelector( '.dlms-qe__picker-footer' );
		if ( ! footer ) {
			return;
		}
		footer.textContent = '';
		const chosen = Object.keys( picker.chosen ).length;
		const add = el( 'button', {
			type: 'button',
			className: 'button button-primary',
			disabled: ! chosen,
			text: sprintf(
				/* translators: %d: number of selected questions. */
				__( 'Add selected (%d)', 'deutschlms' ),
				chosen
			),
		} );
		add.addEventListener( 'click', function () {
			const linked = linkedIds();
			let added = 0;
			picker.results.forEach( function ( result ) {
				if (
					picker.chosen[ result.post_id ] &&
					! linked[ result.post_id ]
				) {
					result.changed = false;
					items.push( result );
					added++;
				}
			} );
			picker.chosen = {};
			sync();
			render();
			renderPicker();
			speak(
				sprintf(
					/* translators: %d: number of questions added. */
					_n(
						'%d question added to the quiz.',
						'%d questions added to the quiz.',
						added,
						'deutschlms'
					),
					added
				)
			);
		} );
		const close = el( 'button', {
			type: 'button',
			className: 'button',
			text: __( 'Close', 'deutschlms' ),
		} );
		close.addEventListener( 'click', function () {
			picker.open = false;
			picker.chosen = {};
			renderPicker();
			render();
		} );
		footer.appendChild( add );
		footer.appendChild( close );
	}

	function render( focusIndex ) {
		list.textContent = '';
		if ( isQuiz ) {
			list.appendChild(
				el( 'p', {
					className: 'description',
					text: __(
						'Students see the questions in this order. Every question is kept in the question bank (Courses → Questions), so it can be used in other quizzes too. Only complete questions are shown to students; incomplete ones are marked below.',
						'deutschlms'
					),
				} )
			);
		}

		items.forEach( function ( item, index ) {
			list.appendChild(
				'random' === item.kind
					? randomCard( item, index )
					: questionCard( item, index )
			);
		} );

		if ( isQuiz && ! items.length ) {
			list.appendChild(
				el( 'p', {
					className: 'dlms-qe__empty',
					text: __(
						'No questions yet. Students cannot take this quiz until it has at least one complete question.',
						'deutschlms'
					),
				} )
			);
		}

		if ( isQuiz ) {
			const buttons = el( 'p', { className: 'dlms-qe__add' } );
			if ( canCreate ) {
				const add = el( 'button', {
					type: 'button',
					className: 'button button-primary',
					text: __( 'Add new question', 'deutschlms' ),
				} );
				add.addEventListener( 'click', function () {
					items.push( newQuestionItem() );
					sync();
					render( items.length - 1 );
					speak( __( 'Question added.', 'deutschlms' ) );
				} );
				buttons.appendChild( add );
			}
			const fromBank = el( 'button', {
				type: 'button',
				className: 'button',
				'aria-expanded': picker.open ? 'true' : 'false',
				text: __( 'Add from question bank', 'deutschlms' ),
			} );
			fromBank.addEventListener( 'click', function () {
				picker.open = ! picker.open;
				renderPicker();
				render();
				if ( picker.open ) {
					if ( ! picker.page ) {
						search( false );
					}
					const input = pickerBox.querySelector(
						'input[type="search"]'
					);
					if ( input ) {
						input.focus();
					}
				}
			} );
			const random = el( 'button', {
				type: 'button',
				className: 'button',
				text: __( 'Add random questions', 'deutschlms' ),
			} );
			random.addEventListener( 'click', function () {
				items.push(
					normalize( {
						kind: 'random',
						count: 5,
						category: 0,
						difficulty: 0,
						level: 0,
						type: '',
					} )
				);
				sync();
				render( items.length - 1 );
				speak( __( 'Random questions added.', 'deutschlms' ) );
			} );
			buttons.appendChild( fromBank );
			buttons.appendChild( random );
			list.appendChild( buttons );
		}

		if ( 'number' === typeof focusIndex && focusIndex >= 0 ) {
			const target = list.querySelector(
				'[data-index="' +
					focusIndex +
					'"] textarea, [data-index="' +
					focusIndex +
					'"] input'
			);
			const doc = root.ownerDocument;
			if ( target && doc.activeElement === doc.body ) {
				target.focus();
			}
		}
	}

	root.textContent = '';
	root.appendChild( list );
	root.appendChild( pickerBox );
	render();
} )( window.wp );
