( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var useBlockProps = blockEditor.useBlockProps;
	var Placeholder = components.Placeholder;
	var __ = i18n.__;

	function createIcon( iconClass ) {
		return el( 'span', {
			className: 'fi ' + iconClass,
			'aria-hidden': true,
			style: {
				fontSize: '18px',
				lineHeight: 1,
			},
		} );
	}

	var bookingIcon = createIcon( 'fi-rr-calendar-lines' );
	var portalIcon = createIcon( 'fi-rr-circle-user' );

	blocks.registerBlockType( 'yo-booking/booking', {
		icon: bookingIcon,
		edit: function () {
			return el(
				'div',
				useBlockProps(),
				el(
					Placeholder,
					{
						icon: bookingIcon,
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

	blocks.registerBlockType( 'yo-booking/portal', {
		icon: portalIcon,
		edit: function () {
			return el(
				'div',
				useBlockProps(),
				el(
					Placeholder,
					{
						icon: portalIcon,
						label: __( 'Customer Portal', 'yo-booking' ),
					},
					__( 'The customer portal will render here on the frontend.', 'yo-booking' )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
