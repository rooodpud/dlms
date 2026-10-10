/**
 * Courses → Arrange courses: drag and drop plus arrow buttons.
 *
 * The order is the order of the list items; the form posts the hidden
 * course_order[] inputs in that order.
 */
( function ( $ ) {
	'use strict';

	var $list = $( '#dlms-arrange-list' );
	if ( ! $list.length ) {
		return;
	}
	var strings = window.dlmsCourseArrange || {};

	function announce( $item ) {
		if ( ! window.wp || ! window.wp.a11y || ! strings.moved ) {
			return;
		}
		var message = strings.moved
			.replace( '%1$s', $item.data( 'title' ) )
			.replace( '%2$d', $item.index() + 1 )
			.replace( '%3$d', $list.children().length );
		window.wp.a11y.speak( message );
	}

	function refreshButtons() {
		var $items = $list.children();
		$items.find( '.dlms-arrange__up' ).prop( 'disabled', false );
		$items.find( '.dlms-arrange__down' ).prop( 'disabled', false );
		$items.first().find( '.dlms-arrange__up' ).prop( 'disabled', true );
		$items.last().find( '.dlms-arrange__down' ).prop( 'disabled', true );
	}

	$list.sortable( {
		handle: '.dlms-arrange__handle',
		items: '> li',
		axis: 'y',
		placeholder: 'dlms-arrange__placeholder',
		forcePlaceholderSize: true,
		update: function ( event, ui ) {
			refreshButtons();
			announce( ui.item );
		},
	} );

	$list.on( 'click', '.dlms-arrange__up, .dlms-arrange__down', function () {
		var $button = $( this );
		var $item = $button.closest( 'li' );
		if ( $button.hasClass( 'dlms-arrange__up' ) ) {
			$item.insertBefore( $item.prev() );
		} else {
			$item.insertAfter( $item.next() );
		}
		refreshButtons();
		announce( $item );
		// Keep keyboard focus on the same kind of button of the moved item.
		$item.find( $button.hasClass( 'dlms-arrange__up' ) ? '.dlms-arrange__up' : '.dlms-arrange__down' ).filter( ':enabled' ).trigger( 'focus' );
	} );

	refreshButtons();
} )( window.jQuery );
