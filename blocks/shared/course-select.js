/**
 * Course picker for block settings. "0" means "the course of the page the
 * block is on" (a course, or a lesson/topic inside one).
 */
import { __ } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { decodeEntities } from '@wordpress/html-entities';

export default function CourseSelect( { value, onChange } ) {
	const courses = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'postType', 'dlms_course', {
				per_page: 100,
				orderby: 'title',
				order: 'asc',
				status: [ 'publish', 'draft', 'future', 'pending', 'private' ],
				_fields: 'id,title',
			} ),
		[]
	);

	const options = [
		{ label: __( 'Current course (automatic)', 'deutschlms' ), value: 0 },
	].concat(
		( courses || [] ).map( ( course ) => ( {
			label:
				decodeEntities( course.title?.rendered || '' ) ||
				__( '(no title)', 'deutschlms' ),
			value: course.id,
		} ) )
	);

	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Course', 'deutschlms' ) }
			value={ value || 0 }
			options={ options }
			onChange={ ( next ) => onChange( parseInt( next, 10 ) || 0 ) }
			help={ __(
				'Automatic uses the course of the page this block is on.',
				'deutschlms'
			) }
		/>
	);
}
