/**
 * Course Outline block (editor). Rendered on the server by render.php.
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
			label={ __( 'Show topics', 'deutschlms' ) }
			checked={ attributes.showTopics }
			onChange={ ( showTopics ) => setAttributes( { showTopics } ) }
		/>
	</>
);

registerBlockType( metadata.name, {
	edit: createEdit( {
		name: metadata.name,
		title: __( 'Course Outline', 'deutschlms' ),
		icon: 'list-view',
		emptyMessage: __(
			'Shows the lessons of a course. Pick a course, or add this block to a course, lesson or topic.',
			'deutschlms'
		),
		renderControls,
	} ),
	save: () => null,
} );
