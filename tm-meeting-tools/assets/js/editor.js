/**
 * Block editor registration for the three dynamic blocks (rendered in PHP, previewed with ServerSideRender).
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, TextControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const { __ } = wp.i18n;

	function meetingControls( props ) {
		return el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: __( 'Meeting', 'tm-meeting-tools' ) },
				el( TextControl, {
					label: __( 'Meeting ID', 'tm-meeting-tools' ),
					help: __( 'Leave at 0 to always show the next meeting (or the latest one). Find IDs under Meeting Tools in wp-admin.', 'tm-meeting-tools' ),
					type: 'number',
					min: 0,
					value: props.attributes.meetingId || 0,
					onChange: ( v ) => props.setAttributes( { meetingId: parseInt( v, 10 ) || 0 } ),
				} )
			)
		);
	}

	function edit( name, withMeeting ) {
		return function ( props ) {
			const blockProps = useBlockProps();
			return el(
				Fragment,
				null,
				withMeeting ? meetingControls( props ) : null,
				el( 'div', blockProps, el( ServerSideRender, { block: name, attributes: props.attributes } ) )
			);
		};
	}

	[
		[ 'tm-meeting-tools/meeting', true ],
		[ 'tm-meeting-tools/audience-feedback', true ],
		[ 'tm-meeting-tools/report', false ],
	].forEach( ( [ name, withMeeting ] ) => {
		registerBlockType( name, { edit: edit( name, withMeeting ), save: () => null } );
	} );
} )( window.wp );
