( function () {
	'use strict';

	var form = document.querySelector( '[data-appearance-form]' );
	var preview = document.querySelector( '[data-appearance-preview]' );
	var previewWrap = document.querySelector( '[data-preview-wrap]' );
	var contrastStatus = document.querySelector( '[data-contrast-status]' );

	if ( ! form || ! preview ) {
		return;
	}

	form.querySelectorAll( '[data-appearance-variable]' ).forEach( function ( input ) {
		input.addEventListener( 'input', function () {
			var value = input.value + ( input.getAttribute( 'data-appearance-unit' ) || '' );
			preview.style.setProperty( input.getAttribute( 'data-appearance-variable' ), value );
			if ( input.getAttribute( 'data-appearance-variable' ) === '--yo-booking-primary' ) {
				preview.style.setProperty( '--yo-booking-primary-rgb', hexRgb( input.value ).join( ',' ) );
			}

			var output = input.parentElement.querySelector( '[data-color-value]' );
			if ( output ) {
				output.textContent = input.value.toUpperCase();
			}
			updateContrast();
		} );
	} );

	form.querySelectorAll( '[data-appearance-class]' ).forEach( function ( input ) {
		input.addEventListener( 'change', function () {
			var group = input.getAttribute( 'data-appearance-class' );
			Array.from( preview.classList ).forEach( function ( className ) {
				if ( className.indexOf( 'yo-booking-' + group + '-' ) === 0 ) {
					preview.classList.remove( className );
				}
			} );
			preview.classList.add( 'yo-booking-' + group + '-' + input.value );
		} );
	} );

	var title = form.querySelector( '[data-appearance-title]' );
	var previewTitle = preview.querySelector( '[data-appearance-preview-title]' );
	if ( title && previewTitle ) {
		title.addEventListener( 'input', function () {
			previewTitle.textContent = title.value || title.defaultValue;
		} );
	}

	var progress = form.querySelector( '[data-appearance-progress]' );
	var previewProgress = preview.querySelectorAll( '[data-appearance-preview-progress]' );
	if ( progress && previewProgress.length ) {
		progress.addEventListener( 'change', function () {
			previewProgress.forEach( function ( item ) { item.hidden = ! progress.checked; } );
		} );
	}

	[
		{ input: '[data-appearance-prices]', targets: '[data-appearance-preview-price]' },
		{ input: '[data-appearance-details]', targets: '[data-appearance-preview-detail]' },
	].forEach( function ( binding ) {
		var input = form.querySelector( binding.input );
		if ( ! input ) return;
		input.addEventListener( 'change', function () {
			preview.querySelectorAll( binding.targets ).forEach( function ( target ) {
				target.hidden = ! input.checked;
			} );
		} );
	} );

	var presets = {
		clean: { primary_color: '#2563EB', accent_color: '#16A34A', background_color: '#F7F8FB', surface_color: '#FFFFFF', text_color: '#1F2937', muted_color: '#64748B', border_color: '#D9DEE8', button_text_color: '#FFFFFF', border_radius: '8', density: 'comfortable', shadow: 'subtle' },
		minimal: { primary_color: '#111827', accent_color: '#047857', background_color: '#FFFFFF', surface_color: '#FFFFFF', text_color: '#111827', muted_color: '#6B7280', border_color: '#D1D5DB', button_text_color: '#FFFFFF', border_radius: '0', density: 'comfortable', shadow: 'none' },
		compact: { primary_color: '#0F766E', accent_color: '#15803D', background_color: '#F8FAFC', surface_color: '#FFFFFF', text_color: '#172554', muted_color: '#64748B', border_color: '#CBD5E1', button_text_color: '#FFFFFF', border_radius: '4', density: 'compact', shadow: 'none' },
	};

	form.querySelectorAll( '[data-appearance-preset]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var values = presets[ button.getAttribute( 'data-appearance-preset' ) ];
			Object.keys( values ).forEach( function ( name ) {
				var input = form.elements[ name ];
				if ( ! input ) return;
				input.value = values[ name ];
				input.dispatchEvent( new Event( input.matches( 'select' ) ? 'change' : 'input', { bubbles: true } ) );
			} );
		} );
	} );

	document.querySelectorAll( '[data-preview-device]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var mobile = button.getAttribute( 'data-preview-device' ) === 'mobile';
			previewWrap.classList.toggle( 'is-mobile-preview', mobile );
			document.querySelectorAll( '[data-preview-device]' ).forEach( function ( item ) {
				var active = item === button;
				item.classList.toggle( 'is-active', active );
				item.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			} );
		} );
	} );

	var stepSelect = document.querySelector( '[data-preview-step]' );
	var stepPanels = preview.querySelectorAll( '[data-preview-step-panel]' );
	var progressSteps = preview.querySelectorAll( '[data-preview-progress-step]' );
	var compactProgressLabel = preview.querySelector( '[data-preview-progress-label]' );
	var compactProgressBar = preview.querySelector( '[data-preview-progress-bar]' );
	var previousButton = preview.querySelector( '[data-preview-prev]' );
	var nextButton = preview.querySelector( '[data-preview-next]' );
	var currentStep = 1;
	var continueLabel = nextButton ? nextButton.textContent : '';
	var bookingLabel = stepSelect ? stepSelect.getAttribute( 'data-booking-label' ) : 'Book appointment';

	function setPreviewStep( step ) {
		currentStep = Math.max( 1, Math.min( 6, Number( step ) || 1 ) );
		stepPanels.forEach( function ( panel ) {
			panel.hidden = Number( panel.getAttribute( 'data-preview-step-panel' ) ) !== currentStep;
		} );
		progressSteps.forEach( function ( item ) {
			item.classList.toggle( 'is-active', Number( item.getAttribute( 'data-preview-progress-step' ) ) === currentStep );
		} );
		if ( stepSelect ) {
			stepSelect.value = String( currentStep );
		}
		var stepLabel = stepSelect && stepSelect.options[ currentStep - 1 ] ? stepSelect.options[ currentStep - 1 ].textContent : '';
		if ( compactProgressLabel ) {
			compactProgressLabel.textContent = ( stepSelect?.getAttribute( 'data-step-label' ) || 'Step' ) + ' ' + currentStep + ' ' + ( stepSelect?.getAttribute( 'data-step-of-label' ) || 'of' ) + ' 6: ' + stepLabel;
		}
		if ( compactProgressBar ) {
			compactProgressBar.style.width = ( currentStep / 6 * 100 ) + '%';
		}
		if ( previousButton ) {
			previousButton.disabled = currentStep === 1;
		}
		if ( nextButton ) {
			nextButton.textContent = currentStep === 6 ? bookingLabel : continueLabel;
		}
	}

	if ( stepSelect ) {
		stepSelect.addEventListener( 'change', function () {
			setPreviewStep( stepSelect.value );
		} );
	}
	if ( previousButton ) {
		previousButton.addEventListener( 'click', function () {
			setPreviewStep( currentStep - 1 );
		} );
	}
	if ( nextButton ) {
		nextButton.addEventListener( 'click', function () {
			if ( currentStep < 6 ) setPreviewStep( currentStep + 1 );
		} );
	}

	preview.querySelectorAll( '.yo-booking-card, .yo-booking-date, .yo-booking-time' ).forEach( function ( option ) {
		option.addEventListener( 'click', function () {
			var panel = option.closest( '[data-preview-step-panel]' );
			if ( ! panel ) return;
			panel.querySelectorAll( '.yo-booking-card, .yo-booking-date, .yo-booking-time' ).forEach( function ( item ) {
				item.classList.toggle( 'is-selected', item === option );
			} );
		} );
	} );

	setPreviewStep( 1 );

	function hexRgb( value ) {
		var hex = String( value || '' ).replace( '#', '' );
		return [ parseInt( hex.slice( 0, 2 ), 16 ), parseInt( hex.slice( 2, 4 ), 16 ), parseInt( hex.slice( 4, 6 ), 16 ) ];
	}

	function luminance( value ) {
		return hexRgb( value ).map( function ( channel ) {
			channel /= 255;
			return channel <= 0.03928 ? channel / 12.92 : Math.pow( ( channel + 0.055 ) / 1.055, 2.4 );
		} ).reduce( function ( total, channel, index ) {
			return total + channel * [ 0.2126, 0.7152, 0.0722 ][ index ];
		}, 0 );
	}

	function contrast( first, second ) {
		var light = Math.max( luminance( first ), luminance( second ) );
		var dark = Math.min( luminance( first ), luminance( second ) );
		return ( light + 0.05 ) / ( dark + 0.05 );
	}

	function updateContrast() {
		if ( ! contrastStatus ) return;
		var buttonRatio = contrast( form.elements.primary_color.value, form.elements.button_text_color.value );
		var textRatio = contrast( form.elements.surface_color.value, form.elements.text_color.value );
		var passes = buttonRatio >= 4.5 && textRatio >= 4.5;
		contrastStatus.classList.toggle( 'has-warning', ! passes );
		contrastStatus.textContent = passes ? 'Contrast check passed for text and primary buttons.' : 'Contrast needs attention. Use a stronger difference between text and background colors.';
	}

	updateContrast();
}() );
