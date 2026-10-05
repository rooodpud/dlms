/**
 * DeutschLMS course builder.
 *
 * Drag-and-drop (jQuery UI Sortable, bundled with WordPress) plus "Move up" /
 * "Move down" buttons for keyboard users. Lessons, topics and quizzes can be
 * reordered; topics can move to another lesson, and quizzes between the
 * course (final quizzes), lessons and topics. Section headings sit between
 * lessons and are saved together with the order. Talks to the dlms/v1 structure
 * endpoints through wp.apiFetch, which sends the REST nonce automatically.
 * All text is inserted with textContent, never as HTML.
 *
 * @param {Object} $  jQuery.
 * @param {Object} wp WordPress script globals.
 */
( function ( $, wp ) {
	'use strict';

	const root = document.getElementById( 'dlms-course-builder' );
	if ( ! root || ! wp || ! wp.apiFetch ) {
		return;
	}

	const { __, sprintf } = wp.i18n;
	const speak = wp.a11y && wp.a11y.speak ? wp.a11y.speak : function () {};
	const courseId = parseInt( root.getAttribute( 'data-course-id' ), 10 );
	const basePath = '/dlms/v1/courses/' + courseId;
	let dirty = false;
	let lessonsList;
	let courseQuizzes;
	let saveButton;
	let statusEl;

	function el( tag, attrs, children ) {
		const node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( 'text' === key ) {
				node.textContent = attrs[ key ];
			} else if ( 'className' === key ) {
				node.className = attrs[ key ];
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

	function setStatus( message, isError ) {
		statusEl.textContent = message;
		statusEl.classList.toggle( 'is-error', !! isError );
		if ( message ) {
			speak( message, isError ? 'assertive' : 'polite' );
		}
	}

	function errorMessage( error ) {
		return error && error.message
			? error.message
			: __( 'Something went wrong. Please try again.', 'deutschlms' );
	}

	function markDirty() {
		dirty = true;
		saveButton.disabled = false;
		setStatus(
			__( 'You have unsaved changes to the order.', 'deutschlms' )
		);
	}

	function moveButton( label, direction ) {
		const button = el( 'button', {
			type: 'button',
			className: 'button-link dlms-builder__move',
			'aria-label': label,
			'data-direction': direction,
		} );
		button.appendChild(
			el( 'span', {
				className: 'dashicons dashicons-arrow-' + direction + '-alt2',
				'aria-hidden': 'true',
			} )
		);
		return button;
	}

	function typeLabel( type ) {
		if ( 'heading' === type ) {
			return __( 'Heading', 'deutschlms' );
		}
		if ( 'dlms_quiz' === type ) {
			return __( 'Quiz', 'deutschlms' );
		}
		return 'dlms_topic' === type
			? __( 'Topic', 'deutschlms' )
			: __( 'Lesson', 'deutschlms' );
	}

	function row( item, extraActions ) {
		const title = item.title || __( '(no title)', 'deutschlms' );
		const children = [
			el( 'span', {
				className: 'dlms-builder__handle dashicons dashicons-move',
				'aria-hidden': 'true',
				title: __( 'Drag to reorder', 'deutschlms' ),
			} ),
			el( 'span', {
				className: 'dlms-builder__type',
				text: typeLabel( item.type ),
			} ),
			el( 'span', { className: 'dlms-builder__title', text: title } ),
		];
		if ( 'publish' !== item.status ) {
			children.push(
				el( 'span', {
					className: 'dlms-builder__status',
					text: item.status_label,
				} )
			);
		}
		if ( 'dlms_quiz' === item.type && ! item.question_count ) {
			children.push(
				el( 'span', {
					className:
						'dlms-builder__status dlms-builder__status--warning',
					text: __( 'No questions yet', 'deutschlms' ),
				} )
			);
		}
		const actions = el( 'span', { className: 'dlms-builder__actions' } );
		( extraActions || [] ).forEach( function ( action ) {
			actions.appendChild( action );
		} );
		if ( item.edit_link ) {
			actions.appendChild(
				el(
					'a',
					{ href: item.edit_link, className: 'dlms-builder__edit' },
					[
						document.createTextNode( __( 'Edit', 'deutschlms' ) ),
						el( 'span', {
							className: 'screen-reader-text',
							text: ' ' + title,
						} ),
					]
				)
			);
		}
		/* translators: %s: lesson, topic or quiz title. */
		const upLabel = sprintf( __( 'Move “%s” up', 'deutschlms' ), title );
		const downLabel = sprintf(
			/* translators: %s: lesson, topic or quiz title. */
			__( 'Move “%s” down', 'deutschlms' ),
			title
		);
		actions.appendChild( moveButton( upLabel, 'up' ) );
		actions.appendChild( moveButton( downLabel, 'down' ) );
		children.push( actions );
		return el( 'div', { className: 'dlms-builder__row' }, children );
	}

	/**
	 * An input with one or more "Add …" buttons.
	 *
	 * @param {string} placeholder Input label/placeholder.
	 * @param {Array}  buttons     [ { label, onSubmit( title ) => Promise } ].
	 * @return {HTMLElement} Form element.
	 */
	function addForm( placeholder, buttons ) {
		const inputId =
			'dlms-builder-input-' + Math.random().toString( 36 ).slice( 2 );
		const input = el( 'input', {
			type: 'text',
			id: inputId,
			className: 'regular-text',
			maxlength: '200',
			placeholder,
		} );
		const nodes = [
			el( 'label', {
				for: inputId,
				className: 'screen-reader-text',
				text: placeholder,
			} ),
			input,
		];
		const buttonEls = buttons.map( function ( spec ) {
			const button = el( 'button', {
				type: 'button',
				className: 'button',
				text: spec.label,
			} );
			button.addEventListener( 'click', function () {
				const title = input.value.trim();
				if ( ! title ) {
					input.focus();
					return;
				}
				buttonEls.forEach( function ( b ) {
					b.disabled = true;
				} );
				spec.onSubmit( title )
					.then( function () {
						input.value = '';
					} )
					.catch( function ( error ) {
						setStatus( errorMessage( error ), true );
					} )
					.finally( function () {
						buttonEls.forEach( function ( b ) {
							b.disabled = false;
						} );
						input.focus();
					} );
			} );
			return button;
		} );
		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault(); // Don't submit the post form.
				buttonEls[ 0 ].click();
			}
		} );
		return el(
			'div',
			{ className: 'dlms-builder__add' },
			nodes.concat( buttonEls )
		);
	}

	function createQuiz( parentId, title, list ) {
		return wp
			.apiFetch( {
				path: basePath + '/quizzes',
				method: 'POST',
				data: { title, parent_id: parentId },
			} )
			.then( function ( quiz ) {
				list.appendChild( quizNode( quiz ) );
				initSortables();
				setStatus(
					sprintf(
						/* translators: %s: quiz title. */
						__(
							'Quiz “%s” added as a draft. Open it to add questions.',
							'deutschlms'
						),
						quiz.title
					)
				);
			} );
	}

	function quizList( parentId, quizzes ) {
		const list = el( 'ol', {
			className: 'dlms-builder__quizzes',
			'data-parent-id': String( parentId ),
		} );
		( quizzes || [] ).forEach( function ( quiz ) {
			list.appendChild( quizNode( quiz ) );
		} );
		return list;
	}

	function quizNode( quiz ) {
		return el(
			'li',
			{ className: 'dlms-builder__quiz', 'data-id': String( quiz.id ) },
			[ row( quiz ) ]
		);
	}

	function topicNode( topic ) {
		const quizzes = quizList( topic.id, topic.quizzes );
		const form = addForm( __( 'New quiz title', 'deutschlms' ), [
			{
				label: __( 'Add quiz', 'deutschlms' ),
				onSubmit( title ) {
					return createQuiz( topic.id, title, quizzes );
				},
			},
		] );
		form.hidden = true;

		const toggle = el( 'button', {
			type: 'button',
			className: 'button-link',
			text: __( '+ Quiz', 'deutschlms' ),
			'aria-expanded': 'false',
		} );
		toggle.addEventListener( 'click', function () {
			form.hidden = ! form.hidden;
			toggle.setAttribute(
				'aria-expanded',
				form.hidden ? 'false' : 'true'
			);
			if ( ! form.hidden ) {
				form.querySelector( 'input' ).focus();
			}
		} );

		return el(
			'li',
			{ className: 'dlms-builder__topic', 'data-id': String( topic.id ) },
			[ row( topic, [ toggle ] ), quizzes, form ]
		);
	}

	function lessonNode( lesson ) {
		const topics = el( 'ol', {
			className: 'dlms-builder__topics',
			'data-lesson-id': String( lesson.id ),
		} );
		( lesson.topics || [] ).forEach( function ( topic ) {
			topics.appendChild( topicNode( topic ) );
		} );
		const quizzes = quizList( lesson.id, lesson.quizzes );

		const form = addForm( __( 'New topic or quiz title', 'deutschlms' ), [
			{
				label: __( 'Add topic', 'deutschlms' ),
				onSubmit( title ) {
					return wp
						.apiFetch( {
							path: '/dlms/v1/lessons/' + lesson.id + '/topics',
							method: 'POST',
							data: { title },
						} )
						.then( function ( topic ) {
							topic.quizzes = [];
							topics.appendChild( topicNode( topic ) );
							initSortables();
							setStatus(
								sprintf(
									/* translators: %s: topic title. */
									__(
										'Topic “%s” added as a draft.',
										'deutschlms'
									),
									topic.title
								)
							);
						} );
				},
			},
			{
				label: __( 'Add quiz', 'deutschlms' ),
				onSubmit( title ) {
					return createQuiz( lesson.id, title, quizzes );
				},
			},
		] );

		return el(
			'li',
			{
				className: 'dlms-builder__lesson',
				'data-id': String( lesson.id ),
			},
			[ row( lesson ), topics, quizzes, form ]
		);
	}

	/**
	 * A section heading row. Its text is edited in place and saved with the order.
	 *
	 * @param {Object} heading { id, title }.
	 * @return {HTMLElement} List item.
	 */
	function headingNode( heading ) {
		const inputId =
			'dlms-builder-heading-' + Math.random().toString( 36 ).slice( 2 );
		const input = el( 'input', {
			type: 'text',
			id: inputId,
			className: 'dlms-builder__heading-input',
			maxlength: '200',
		} );
		input.value = heading.title || '';
		input.addEventListener( 'input', markDirty );
		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault(); // Don't submit the post form.
			}
		} );

		const remove = el( 'button', {
			type: 'button',
			className: 'button-link button-link-delete',
			text: __( 'Remove', 'deutschlms' ),
		} );
		const actions = el( 'span', { className: 'dlms-builder__actions' }, [
			remove,
			moveButton( __( 'Move heading up', 'deutschlms' ), 'up' ),
			moveButton( __( 'Move heading down', 'deutschlms' ), 'down' ),
		] );

		const item = el(
			'li',
			{
				className: 'dlms-builder__heading',
				'data-heading-id': heading.id || '',
			},
			[
				el( 'div', { className: 'dlms-builder__row' }, [
					el( 'span', {
						className:
							'dlms-builder__handle dashicons dashicons-move',
						'aria-hidden': 'true',
						title: __( 'Drag to reorder', 'deutschlms' ),
					} ),
					el( 'span', {
						className: 'dlms-builder__type',
						text: typeLabel( 'heading' ),
					} ),
					el( 'label', {
						for: inputId,
						className: 'screen-reader-text',
						text: __( 'Heading text', 'deutschlms' ),
					} ),
					input,
					actions,
				] ),
			]
		);

		remove.addEventListener( 'click', function () {
			const next = item.nextElementSibling || item.previousElementSibling;
			item.remove();
			markDirty();
			setStatus(
				__(
					'Heading removed. Save the order to apply the change.',
					'deutschlms'
				)
			);
			( next
				? next.querySelector( 'button, input' )
				: saveButton
			).focus();
		} );

		return item;
	}

	function initSortables() {
		$( lessonsList ).sortable( {
			items: '> li',
			handle: '> .dlms-builder__row .dlms-builder__handle',
			axis: 'y',
			placeholder: 'dlms-builder__placeholder',
			forcePlaceholderSize: true,
			update: markDirty,
		} );
		[
			[ '.dlms-builder__topics', '.dlms-builder__topics' ],
			[ '.dlms-builder__quizzes', '.dlms-builder__quizzes' ],
		].forEach( function ( pair ) {
			$( root )
				.find( pair[ 0 ] )
				.each( function () {
					if ( $( this ).sortable( 'instance' ) ) {
						return;
					}
					$( this ).sortable( {
						items: '> li',
						handle: '> .dlms-builder__row .dlms-builder__handle',
						connectWith: pair[ 1 ],
						placeholder: 'dlms-builder__placeholder',
						forcePlaceholderSize: true,
						update( event, ui ) {
							// Fires on both lists when moving between them; count once.
							if ( this === ui.item.parent()[ 0 ] ) {
								markDirty();
							}
						},
					} );
				} );
		} );
	}

	function ids( list ) {
		return Array.prototype.map.call( list.children, function ( item ) {
			return parseInt( item.getAttribute( 'data-id' ), 10 );
		} );
	}

	/**
	 * Headings in list order, each anchored to the lesson below it (0 when no
	 * lesson follows).
	 *
	 * @return {Array} [ { id, title, before } ].
	 */
	function collectHeadings() {
		const result = [];
		let pending = [];
		Array.prototype.forEach.call( lessonsList.children, function ( item ) {
			if ( item.classList.contains( 'dlms-builder__heading' ) ) {
				pending.push( {
					id: item.getAttribute( 'data-heading-id' ) || '',
					title: item
						.querySelector( '.dlms-builder__heading-input' )
						.value.trim(),
				} );
				return;
			}
			const before = parseInt( item.getAttribute( 'data-id' ), 10 );
			pending.forEach( function ( heading ) {
				heading.before = before;
				result.push( heading );
			} );
			pending = [];
		} );
		pending.forEach( function ( heading ) {
			heading.before = 0;
			result.push( heading );
		} );
		return result;
	}

	function collect() {
		return {
			headings: collectHeadings(),
			lessons: Array.prototype.filter
				.call( lessonsList.children, function ( item ) {
					return item.classList.contains( 'dlms-builder__lesson' );
				} )
				.map( function ( lesson ) {
					const topics = lesson.querySelector(
						':scope > .dlms-builder__topics'
					);
					return {
						id: parseInt( lesson.getAttribute( 'data-id' ), 10 ),
						topics: Array.prototype.map.call(
							topics.children,
							function ( topic ) {
								return {
									id: parseInt(
										topic.getAttribute( 'data-id' ),
										10
									),
									quizzes: ids(
										topic.querySelector(
											':scope > .dlms-builder__quizzes'
										)
									),
								};
							}
						),
						quizzes: ids(
							lesson.querySelector(
								':scope > .dlms-builder__quizzes'
							)
						),
					};
				} ),
			quizzes: ids( courseQuizzes ),
		};
	}

	function render( data ) {
		root.textContent = '';
		dirty = false;

		root.appendChild(
			el( 'p', {
				className: 'description',
				text: __(
					'Drag lessons, topics and quizzes to change their order. Topics can be moved to another lesson; quizzes can be moved to another lesson or topic, or to the end of the course. New items are created as drafts: open them to write content and publish.',
					'deutschlms'
				),
			} )
		);

		root.appendChild(
			el( 'p', {
				className: 'description',
				text: __(
					'Headings divide the lessons into sections: each one appears in large bold letters above the lesson below it in the course outline. Headings are saved with “Save order”.',
					'deutschlms'
				),
			} )
		);

		const headings = data.headings || [];
		const lessonIds = data.lessons.map( function ( lesson ) {
			return lesson.id;
		} );
		lessonsList = el( 'ol', { className: 'dlms-builder__lessons' } );
		data.lessons.forEach( function ( lesson ) {
			headings
				.filter( function ( heading ) {
					return heading.before === lesson.id;
				} )
				.forEach( function ( heading ) {
					lessonsList.appendChild( headingNode( heading ) );
				} );
			lessonsList.appendChild( lessonNode( lesson ) );
		} );
		headings
			.filter( function ( heading ) {
				return -1 === lessonIds.indexOf( heading.before );
			} )
			.forEach( function ( heading ) {
				lessonsList.appendChild( headingNode( heading ) );
			} );
		root.appendChild( lessonsList );

		if ( ! data.lessons.length ) {
			root.appendChild(
				el( 'p', {
					className: 'dlms-builder__empty',
					text: __(
						'This course has no lessons yet. Add the first one below.',
						'deutschlms'
					),
				} )
			);
		}

		root.appendChild(
			el( 'h3', {
				className: 'dlms-builder__subheading',
				text: __( 'Final quizzes (end of course)', 'deutschlms' ),
			} )
		);
		courseQuizzes = quizList( 0, data.quizzes );
		courseQuizzes.classList.add( 'dlms-builder__quizzes--course' );
		root.appendChild( courseQuizzes );

		root.appendChild(
			addForm( __( 'New lesson, final quiz or heading', 'deutschlms' ), [
				{
					label: __( 'Add lesson', 'deutschlms' ),
					onSubmit( title ) {
						return wp
							.apiFetch( {
								path: basePath + '/lessons',
								method: 'POST',
								data: { title },
							} )
							.then( function ( lesson ) {
								lesson.topics = [];
								lesson.quizzes = [];
								lessonsList.appendChild( lessonNode( lesson ) );
								const empty = root.querySelector(
									'.dlms-builder__empty'
								);
								if ( empty ) {
									empty.remove();
								}
								initSortables();
								setStatus(
									sprintf(
										/* translators: %s: lesson title. */
										__(
											'Lesson “%s” added as a draft.',
											'deutschlms'
										),
										lesson.title
									)
								);
							} );
					},
				},
				{
					label: __( 'Add final quiz', 'deutschlms' ),
					onSubmit( title ) {
						return createQuiz( 0, title, courseQuizzes );
					},
				},
				{
					label: __( 'Add heading', 'deutschlms' ),
					onSubmit( title ) {
						lessonsList.appendChild(
							headingNode( { id: '', title } )
						);
						initSortables();
						markDirty();
						setStatus(
							sprintf(
								/* translators: %s: heading text. */
								__(
									'Heading “%s” added below the last lesson. Drag it to its place, then save the order.',
									'deutschlms'
								),
								title
							)
						);
						return Promise.resolve();
					},
				},
			] )
		);

		saveButton = el( 'button', {
			type: 'button',
			className: 'button button-primary',
			text: __( 'Save order', 'deutschlms' ),
		} );
		saveButton.disabled = true;
		saveButton.addEventListener( 'click', save );
		statusEl = el( 'span', {
			className: 'dlms-builder__message',
			role: 'status',
			tabindex: '-1',
		} );
		root.appendChild(
			el( 'div', { className: 'dlms-builder__footer' }, [
				saveButton,
				statusEl,
			] )
		);

		initSortables();
	}

	function save() {
		const empty = Array.prototype.find.call(
			lessonsList.querySelectorAll( '.dlms-builder__heading-input' ),
			function ( input ) {
				return ! input.value.trim();
			}
		);
		if ( empty ) {
			setStatus(
				__(
					'Please enter a text for every heading, or remove the empty one.',
					'deutschlms'
				),
				true
			);
			empty.focus();
			return;
		}
		saveButton.disabled = true;
		setStatus( __( 'Saving…', 'deutschlms' ) );
		wp.apiFetch( {
			path: basePath + '/structure',
			method: 'PUT',
			data: collect(),
		} )
			.then( function ( data ) {
				render( data );
				setStatus( __( 'Order saved.', 'deutschlms' ) );
				// The list was rebuilt; keep keyboard focus inside the builder.
				statusEl.focus();
			} )
			.catch( function ( error ) {
				saveButton.disabled = false;
				setStatus( errorMessage( error ), true );
			} );
	}

	// Keyboard alternative to dragging: moves within the same list.
	root.addEventListener( 'click', function ( event ) {
		const button = event.target.closest( '.dlms-builder__move' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		const item = button.closest( 'li' );
		const up = 'up' === button.getAttribute( 'data-direction' );
		const sibling = up
			? item.previousElementSibling
			: item.nextElementSibling;
		if ( ! sibling ) {
			return;
		}
		item.parentNode.insertBefore(
			item,
			up ? sibling : sibling.nextElementSibling
		);
		button.focus();
		markDirty();
	} );

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( dirty ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	wp.apiFetch( { path: basePath + '/structure' } )
		.then( render )
		.catch( function ( error ) {
			root.textContent = '';
			root.appendChild(
				el( 'p', {
					className: 'notice notice-error inline',
					text: errorMessage( error ),
				} )
			);
		} );
} )( window.jQuery, window.wp );
