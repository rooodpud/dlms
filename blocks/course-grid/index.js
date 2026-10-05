/**
 * Course Grid block (editor). Rendered on the server by render.php.
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import {
	RangeControl,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { createEdit } from '../shared/server-block';
import metadata from './block.json';

const renderControls = ( attributes, setAttributes ) => (
	<>
		<RangeControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Columns', 'deutschlms' ) }
			value={ attributes.columns }
			min={ 1 }
			max={ 4 }
			onChange={ ( columns ) => setAttributes( { columns } ) }
		/>
		<RangeControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Number of courses', 'deutschlms' ) }
			value={ attributes.perPage }
			min={ 1 }
			max={ 48 }
			onChange={ ( perPage ) => setAttributes( { perPage } ) }
		/>
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Order by', 'deutschlms' ) }
			value={ attributes.orderBy }
			options={ [
				{ label: __( 'Newest first', 'deutschlms' ), value: 'date' },
				{ label: __( 'Title', 'deutschlms' ), value: 'title' },
				{
					label: __( 'Menu order', 'deutschlms' ),
					value: 'menu_order',
				},
			] }
			onChange={ ( orderBy ) => setAttributes( { orderBy } ) }
		/>
		<ToggleControl
			__nextHasNoMarginBottom
			label={ __( 'Show progress for enrolled students', 'deutschlms' ) }
			checked={ attributes.showProgress }
			onChange={ ( showProgress ) => setAttributes( { showProgress } ) }
		/>
	</>
);

registerBlockType( metadata.name, {
	edit: createEdit( {
		name: metadata.name,
		title: __( 'Course Grid', 'deutschlms' ),
		icon: 'grid-view',
		emptyMessage: __( 'No published courses yet.', 'deutschlms' ),
		renderControls,
	} ),
	save: () => null,
} );
