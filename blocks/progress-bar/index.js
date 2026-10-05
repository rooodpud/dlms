/**
 * Course Progress Bar block (editor). Rendered on the server by render.php.
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { ToggleControl } from '@wordpress/components';
import { createEdit } from '../shared/server-block';
import CourseSelect from '../shared/course-select';
import metadata from './block.json';

const renderControls = ( attributes, setAttributes ) => (
	<>
		<CourseSelect
			value={ attributes.courseId }
			onChange={ ( courseId ) => setAttributes( { courseId } ) }
		/>
		<ToggleControl
			__nextHasNoMarginBottom
			label={ __( 'Show text label', 'deutschlms' ) }
			checked={ attributes.showLabel }
			onChange={ ( showLabel ) => setAttributes( { showLabel } ) }
		/>
	</>
);

registerBlockType( metadata.name, {
	edit: createEdit( {
		name: metadata.name,
		title: __( 'Course Progress Bar', 'deutschlms' ),
		icon: 'chart-bar',
		emptyMessage: __(
			"Shows the student's progress. It is only visible to students enrolled in the course.",
			'deutschlms'
		),
		renderControls,
	} ),
	save: () => null,
} );
