/**
 * Shared editor implementation for DeutschLMS's dynamic blocks: settings in
 * the sidebar, server-side rendered preview in the canvas. The preview is
 * rendered for the post being edited, so blocks on a lesson show that
 * lesson's course.
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, Placeholder } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import ServerSideRender from '@wordpress/server-side-render';

export function createEdit( {
	name,
	title,
	icon,
	emptyMessage,
	renderControls,
} ) {
	return function Edit( { attributes, setAttributes } ) {
		const blockProps = useBlockProps();
		const postId = useSelect( ( select ) => {
			const editor = select( 'core/editor' );
			return editor ? editor.getCurrentPostId() : 0;
		}, [] );

		const Empty = () => (
			<Placeholder
				icon={ icon }
				label={ title }
				instructions={ emptyMessage }
			/>
		);

		return (
			<>
				{ renderControls && (
					<InspectorControls>
						<PanelBody title={ title }>
							{ renderControls( attributes, setAttributes ) }
						</PanelBody>
					</InspectorControls>
				) }
				<div { ...blockProps }>
					<ServerSideRender
						block={ name }
						attributes={ attributes }
						urlQueryArgs={ postId ? { post_id: postId } : {} }
						EmptyResponsePlaceholder={ Empty }
					/>
				</div>
			</>
		);
	};
}
