/**
 * Event Calendar block — editor UI.
 *
 * Renders in PHP; this file provides only the editor controls and a
 * server-rendered preview, so the editor is looking at the same markup the
 * front end will emit rather than a second implementation of it.
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, TextControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

import Empty from '../shared/empty';

import metadata from './block.json';

registerBlockType( metadata.name, {
	edit( { attributes, setAttributes } ) {
		const { month, view } = attributes;

		return (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Calendar', 'quick-events-manager' ) }>
						<SelectControl
							label={ __( 'Starting view', 'quick-events-manager' ) }
							help={ __(
								'Visitors can switch between the two at any time, and their choice is kept as they move between months.',
								'quick-events-manager'
							) }
							value={ view }
							options={ [
								{ label: __( 'Month grid', 'quick-events-manager' ), value: '' },
								{ label: __( 'List', 'quick-events-manager' ), value: 'list' },
							] }
							onChange={ ( value ) => setAttributes( { view: value } ) }
						/>

						<TextControl
							label={ __( 'Starting month', 'quick-events-manager' ) }
							help={ __(
								'As YYYY-MM. Leave empty to start on the current month, which is almost always what you want.',
								'quick-events-manager'
							) }
							value={ month }
							onChange={ ( value ) => setAttributes( { month: value } ) }
						/>
					</PanelBody>
				</InspectorControls>

				<div { ...useBlockProps() }>
					<ServerSideRender
						block={ metadata.name }
						attributes={ attributes }
						EmptyResponsePlaceholder={ () => (
							<Empty label={ __( 'Event Calendar', 'quick-events-manager' ) } />
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
