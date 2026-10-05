/**
 * DeutschLMS course settings: media picker for the certificate background.
 *
 * @param {Object} wp WordPress script globals.
 */
( function ( wp ) {
	'use strict';

	const field = document.getElementById( 'dlms-certificate-background' );
	const choose = document.getElementById(
		'dlms-certificate-background-choose'
	);
	if ( ! field || ! choose || ! wp || ! wp.media ) {
		return;
	}
	const preview = document.getElementById(
		'dlms-certificate-background-preview'
	);
	const remove = document.getElementById(
		'dlms-certificate-background-remove'
	);

	const { __ } = wp.i18n;
	let frame;

	function markChanged() {
		if ( wp.data && wp.data.select( 'core/editor' ) ) {
			wp.data
				.dispatch( 'core/editor' )
				.editPost( { dlms_settings_changed: Date.now() } );
		}
	}

	choose.addEventListener( 'click', function () {
		if ( ! frame ) {
			frame = wp.media( {
				title: __( 'Certificate background', 'deutschlms' ),
				button: { text: __( 'Use this image', 'deutschlms' ) },
				library: { type: 'image' },
				multiple: false,
			} );
			frame.on( 'select', function () {
				const image = frame.state().get( 'selection' ).first().toJSON();
				field.value = String( image.id );
				const sizes = image.sizes || {};
				preview.src = ( sizes.medium || sizes.full || image ).url;
				preview.style.display = '';
				remove.hidden = false;
				markChanged();
			} );
		}
		frame.open();
	} );

	remove.addEventListener( 'click', function () {
		field.value = '0';
		preview.removeAttribute( 'src' );
		preview.style.display = 'none';
		remove.hidden = true;
		markChanged();
		choose.focus();
	} );
} )( window.wp );
