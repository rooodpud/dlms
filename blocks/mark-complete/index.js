/**
 * Mark Complete Button block (editor). Rendered on the server by render.php.
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { createEdit } from '../shared/server-block';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit: createEdit( {
		name: metadata.name,
		title: __( 'Mark Complete Button', 'deutschlms' ),
		icon: 'yes-alt',
		emptyMessage: __(
			'Shows a “Mark complete” button. Add this block to a lesson or topic.',
			'deutschlms'
		),
	} ),
	save: () => null,
} );
