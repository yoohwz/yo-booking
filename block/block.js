( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var useBlockProps = blockEditor.useBlockProps;
	var Placeholder = components.Placeholder;
	var __ = i18n.__;

	blocks.registerBlockType( 'yo-booking/booking', {
		edit: function () {
			return el(
				'div',
				useBlockProps(),
				el(
					Placeholder,
					{
						icon: 'calendar-alt',
						label: __( 'Yo Booking', 'yo-booking' ),
					},
					__( 'The booking flow will render here on the frontend.', 'yo-booking' )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
