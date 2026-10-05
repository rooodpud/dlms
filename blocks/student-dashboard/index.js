/**
 * Student Dashboard block (editor). Rendered on the server by render.php.
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { ToggleControl } from '@wordpress/components';
import { createEdit } from '../shared/server-block';
import metadata from './block.json';

const renderControls = ( attributes, setAttributes ) => (
	<ToggleControl
		__nextHasNoMarginBottom
		label={ __( 'Include completed courses', 'deutschlms' ) }
		checked={ attributes.showCompleted }
		onChange={ ( showCompleted ) => setAttributes( { showCompleted } ) }
	/>
);

registerBlockType( metadata.name, {
	edit: createEdit( {
		name: metadata.name,
		title: __( 'Student Dashboard', 'deutschlms' ),
		icon: 'id-alt',
		emptyMessage: __( "Shows the student's courses.", 'deutschlms' ),
		renderControls,
	} ),
	save: () => null,
} );
