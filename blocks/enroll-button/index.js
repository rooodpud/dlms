/**
 * Enroll Button block (editor). Rendered on the server by render.php.
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { createEdit } from '../shared/server-block';
import CourseSelect from '../shared/course-select';
import metadata from './block.json';

const renderControls = ( attributes, setAttributes ) => (
	<CourseSelect
		value={ attributes.courseId }
		onChange={ ( courseId ) => setAttributes( { courseId } ) }
	/>
);

registerBlockType( metadata.name, {
	edit: createEdit( {
		name: metadata.name,
		title: __( 'Enroll Button', 'deutschlms' ),
		icon: 'welcome-learn-more',
		emptyMessage: __(
			'Shows the enroll button of a course. Pick a course, or add this block to a course page.',
			'deutschlms'
		),
		renderControls,
	} ),
	save: () => null,
} );
