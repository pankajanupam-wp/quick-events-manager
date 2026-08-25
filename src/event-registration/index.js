/**
 * Event Registration block — editor UI.
 *
 * Renders in PHP; this file provides only the editor controls and a
 * server-rendered preview.
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

import Empty from '../shared/empty';

import metadata from './block.json';

registerBlockType( metadata.name, {
	edit( { attributes, setAttributes } ) {
		const { id } = attributes;

		return (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Event', 'quick-events-manager' ) }>
						<TextControl
							label={ __( 'Event ID', 'quick-events-manager' ) }
							help={ __( 'Leave at 0 to use the event this block is placed on.', 'quick-events-manager' ) }
							type="number"
							value={ id }
							onChange={ ( value ) => setAttributes( { id: parseInt( value, 10 ) || 0 } ) }
						/>
					</PanelBody>
				</InspectorControls>

				<div { ...useBlockProps() }>
					<ServerSideRender
						block={ metadata.name }
						attributes={ attributes }
						EmptyResponsePlaceholder={ () => (
							<Empty label={ __( 'Event Registration', 'quick-events-manager' ) } />
						) }
					/>
				</div>
			</>
		);
	},

	save() {
		return null;
	},
} );
