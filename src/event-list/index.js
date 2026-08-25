/**
 * Event List block — editor UI.
 *
 * The block renders in PHP, so this file only provides the editor controls and
 * a server-rendered preview. Nothing here reaches the front end.
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, RangeControl, SelectControl, TextControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

import Empty from '../shared/empty';

import metadata from './block.json';

registerBlockType( metadata.name, {
	edit( { attributes, setAttributes } ) {
		const { limit, show, category, columns } = attributes;

		return (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Events', 'quick-events-manager' ) }>
						<SelectControl
							label={ __( 'Show', 'quick-events-manager' ) }
							value={ show }
							options={ [
								{ label: __( 'Upcoming events', 'quick-events-manager' ), value: 'upcoming' },
								{ label: __( 'Past events', 'quick-events-manager' ), value: 'past' },
							] }
							onChange={ ( value ) => setAttributes( { show: value } ) }
						/>
						<RangeControl
							label={ __( 'How many', 'quick-events-manager' ) }
							value={ limit }
							min={ 1 }
							max={ 50 }
							onChange={ ( value ) => setAttributes( { limit: value } ) }
						/>
						<RangeControl
							label={ __( 'Columns', 'quick-events-manager' ) }
							value={ columns }
							min={ 1 }
							max={ 4 }
							onChange={ ( value ) => setAttributes( { columns: value } ) }
						/>
						<TextControl
							label={ __( 'Category slug', 'quick-events-manager' ) }
							help={ __( 'Leave empty to show every category.', 'quick-events-manager' ) }
							value={ category }
							onChange={ ( value ) => setAttributes( { category: value } ) }
						/>
					</PanelBody>
				</InspectorControls>

				<div { ...useBlockProps() }>
					<ServerSideRender
						block={ metadata.name }
						attributes={ attributes }
						EmptyResponsePlaceholder={ () => (
							<Empty label={ __( 'Event List', 'quick-events-manager' ) } />
						) }
					/>
				</div>
			</>
		);
	},

	// Rendered by PHP; nothing is stored in post content.
	save() {
		return null;
	},
} );
