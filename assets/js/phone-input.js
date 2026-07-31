( function () {
	'use strict';

	var config = window.YoBookingPhoneConfig || {};
	var instances = new Map();
	var sessionKey = 'yo_booking_phone_country';

	function cleanCountry( value ) {
		value = String( value || '' ).toLowerCase();
		return /^[a-z]{2}$/.test( value ) ? value : '';
	}

	function rememberedCountry() {
		if ( ! config.rememberCountry ) return '';
		try {
			return cleanCountry( window.sessionStorage.getItem( sessionKey ) );
		} catch ( error ) {
			return '';
		}
	}

	function countryField( input ) {
		var id = input.dataset.phoneCountryField;
		return id ? document.getElementById( id ) : null;
	}

	function syncCountry( input ) {
		var country = cleanCountry( instances.get( input )?.getSelectedCountryData()?.iso2 );
		var field = countryField( input );
		if ( field ) field.value = country;
		if ( config.rememberCountry && country ) {
			try {
				window.sessionStorage.setItem( sessionKey, country );
			} catch ( error ) {}
		}
	}

	function canonicalNumber( input ) {
		var instance = instances.get( input );
		if ( ! instance || ! String( input.value || '' ).trim() ) {
			return String( input.value || '' ).trim();
		}
		return instance.getNumber() || String( input.value || '' ).trim();
	}

	function isValid( input ) {
		var instance = instances.get( input );
		return ! String( input.value || '' ).trim() || Boolean( instance && instance.isValidNumber() );
	}

	function announceChange( input ) {
		input.dispatchEvent( new CustomEvent( 'yo-phone-change', {
			bubbles: true,
			detail: {
				number: canonicalNumber( input ),
				valid: isValid( input ),
				country: instances.get( input )?.getSelectedCountryData()?.iso2 || '',
			},
		} ) );
	}

	function validate( input ) {
		var valid = isValid( input );
		input.setCustomValidity( valid ? '' : ( config.invalidMessage || 'Enter a valid phone number.' ) );
		input.setAttribute( 'aria-invalid', valid ? 'false' : 'true' );
		return valid;
	}

	function initialize( input ) {
		if ( instances.has( input ) || input.dataset.yoPhoneInitialized === '1' || typeof window.intlTelInput !== 'function' ) {
			return;
		}

		var initialCountry = cleanCountry( input.dataset.phoneCountry || rememberedCountry() || config.defaultCountry );
		var instance = window.intlTelInput( input, {
			initialCountry: initialCountry,
			separateDialCode: true,
			showFlags: true,
			countrySearch: true,
			formatAsYouType: true,
			strictMode: true,
			placeholderNumberPolicy: 'POLITE',
		} );

		instances.set( input, instance );
		input.dataset.yoPhoneInitialized = '1';
		input.autocomplete = input.autocomplete || 'tel';
		if ( String( input.value || '' ).trim().charAt( 0 ) === '+' ) {
			instance.setNumber( input.value );
		}
		syncCountry( input );

		input.addEventListener( 'input', function () {
			input.setCustomValidity( '' );
			input.removeAttribute( 'aria-invalid' );
			announceChange( input );
		} );
		input.addEventListener( 'countrychange', function () {
			input.setCustomValidity( '' );
			syncCountry( input );
			announceChange( input );
		} );
		input.addEventListener( 'blur', function () {
			validate( input );
			announceChange( input );
		} );
		announceChange( input );
	}

	function scan( root ) {
		if ( root.matches?.( '[data-yo-phone]' ) ) initialize( root );
		root.querySelectorAll?.( '[data-yo-phone]' ).forEach( initialize );
		root.querySelectorAll?.( '[data-phone-country-setting]' ).forEach( populateCountrySetting );
	}

	function populateCountrySetting( select ) {
		if ( select.dataset.phoneCountryReady === '1' || ! window.intlTelInput?.getCountryData ) return;
		var selected = cleanCountry( select.dataset.selectedCountry || select.value );
		window.intlTelInput.getCountryData().forEach( function ( country ) {
			var option = document.createElement( 'option' );
			option.value = country.iso2;
			option.textContent = country.name + ' (+' + country.dialCode + ')';
			option.selected = country.iso2 === selected;
			select.appendChild( option );
		} );
		select.dataset.phoneCountryReady = '1';
	}

	document.addEventListener( 'submit', function ( event ) {
		var valid = true;
		event.target.querySelectorAll?.( '[data-yo-phone]' ).forEach( function ( input ) {
			if ( ! validate( input ) ) valid = false;
			var canonical = canonicalNumber( input );
			if ( canonical ) input.value = canonical;
		} );
		if ( ! valid ) {
			event.preventDefault();
			event.target.querySelector( '[data-yo-phone][aria-invalid="true"]' )?.reportValidity();
		}
	}, true );

	new MutationObserver( function ( mutations ) {
		mutations.forEach( function ( mutation ) {
			mutation.addedNodes.forEach( function ( node ) {
				if ( node.nodeType === 1 ) scan( node );
			} );
		} );
	} ).observe( document.documentElement, { childList: true, subtree: true } );

	window.YoBookingPhone = {
		getNumber: canonicalNumber,
		isValid: isValid,
		setNumber: function ( input, number ) {
			input.value = number || '';
			var instance = instances.get( input );
			if ( instance ) instance.setNumber( input.value );
			syncCountry( input );
			announceChange( input );
		},
		refresh: function () { scan( document ); },
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () { scan( document ); } );
	} else {
		scan( document );
	}
}() );
