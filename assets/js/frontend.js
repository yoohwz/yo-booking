( function () {
	'use strict';

	var config = window.YoBookingFrontend || {};
	var roots = document.querySelectorAll( '[data-yo-booking-root="1"]' );
	var strings = config.i18n || {};
	var appearance = config.appearance || {};

	function t( value ) {
		return strings[ value ] || value;
	}

	function formatText( template, value ) {
		return t( template ).replace( /%[sd]/, value );
	}

	function formatValues( template, values ) {
		var output = t( template );
		values.forEach( function ( value, index ) {
			output = output.replace( new RegExp( '%' + ( index + 1 ) + '\\$[sd]' ), value );
		} );
		return output;
	}

	function statusLabel( value ) {
		var labels = {
			pending: 'Pending',
			confirmed: 'Confirmed',
			cancelled: 'Cancelled',
			completed: 'Completed',
			no_show: 'No show',
			rescheduled: 'Rescheduled',
			unpaid: 'Unpaid',
			paid: 'Paid',
			partially_paid: 'Partially paid',
			authorized: 'Authorized',
			failed: 'Failed',
			partially_refunded: 'Partially refunded',
			refunded: 'Refunded',
		};

		return t( labels[ value ] || value || '' );
	}

	if ( ! roots.length ) {
		return;
	}

	function request( path, options ) {
		options = options || {};
		options.headers = options.headers || {};
		options.headers.Accept = 'application/json';

		if ( options.body ) {
			options.headers['Content-Type'] = 'application/json';
		}

		if ( config.nonce ) {
			options.headers['X-WP-Nonce'] = config.nonce;
		}

		return fetch( config.restUrl + path, options ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( data && data.message ? data.message : t( 'Request failed.' ) );
				}

				return data;
			} );
		} );
	}

	function create( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( text !== undefined && text !== null ) {
			node.textContent = text;
		}

		return node;
	}

	function formatDateLabel( value ) {
		var date = new Date( value + 'T00:00:00' );

		return date.toLocaleDateString( undefined, {
			weekday: 'short',
			month: 'short',
			day: 'numeric',
		} );
	}

	function formatTimeLabel( value ) {
		var parts = value.split( ' ' );

		return parts.length > 1 ? parts[1].slice( 0, 5 ) : value.slice( 0, 5 );
	}

	function slotTimeLabel( slot ) {
		return slot && slot.start_display ? slot.start_display : formatTimeLabel( slot ? slot.start : '' );
	}

	function selectedDateLabel( dates, value ) {
		var item = ( dates || [] ).find( function ( date ) {
			return date.date === value;
		} );

		return item && item.date_display ? item.date_display : formatDateLabel( value );
	}

	function urlAction( root ) {
		var params = new URLSearchParams( window.location.search );
		var action = config.action || {};
		var type = params.get( 'yo_booking_action' ) || action.type || '';
		var appointment = params.get( 'appointment' ) || action.appointment || '';
		var token = params.get( 'token' ) || action.token || '';

		if ( root.getAttribute( 'data-yo-booking-mode' ) !== 'manage' && ! type ) {
			return null;
		}

		if ( type !== 'cancel' && type !== 'reschedule' ) {
			return null;
		}

		if ( ! appointment || ! token ) {
			return null;
		}

		return {
			type: type,
			appointment: appointment,
			token: token,
		};
	}

	function app( root, rootIndex ) {
		var action = urlAction( root );
		var rootMode = root.getAttribute( 'data-yo-booking-mode' );
		var state = {
			mode: action ? 'manage' : ( rootMode === 'portal' ? 'portal' : 'booking' ),
			action: action,
			step: 0,
			manageStage: action && action.type === 'cancel' ? 'cancel' : 'reschedule-date',
			loading: true,
			busy: false,
			error: '',
			message: '',
			services: [],
			staff: [],
			dates: [],
			times: [],
			manageDates: [],
			manageTimes: [],
			manageAppointment: null,
			portalAppointments: {
				upcoming: [],
				past: [],
			},
			manageSelectedDate: '',
			manageSelectedSlot: null,
			cancelReason: '',
			fieldErrors: {},
				paymentConfig: {
				enabled: false,
				default_method: '',
				methods: [],
				},
				bookingConfig: {
					allow_guest_booking: true,
					allow_staff_selection: true,
					require_email: true,
					require_phone: true,
					marketing_consent_required: false,
				},
				loggedIn: false,
				marketingConsent: false,
			paymentMethod: '',
			customer: {
				name: '',
				email: '',
				phone: '',
				note: '',
			},
			selectedService: null,
			selectedStaffId: 0,
			staffChosen: false,
			selectedDate: '',
			selectedSlot: null,
			confirmation: null,
		};

		var steps = [ 'Service', 'Staff', 'Date', 'Time', 'Details', 'Review' ].map( t );
		root.classList.add( 'yo-booking-density-' + ( appearance.density === 'compact' ? 'compact' : 'comfortable' ) );
		root.classList.add( 'yo-booking-shadow-' + ( appearance.shadow === 'none' ? 'none' : 'subtle' ) );

		function setState( patch ) {
			var previousNavigation = state.mode + ':' + state.step + ':' + state.manageStage;
			Object.keys( patch ).forEach( function ( key ) {
				state[ key ] = patch[ key ];
			} );
			render();

			if ( previousNavigation !== state.mode + ':' + state.step + ':' + state.manageStage ) {
				window.requestAnimationFrame( focusCurrentStep );
			}
		}

		function focusCurrentStep() {
			var heading = root.querySelector( '[data-step-heading]' );
			if ( ! heading ) return;
			heading.focus( { preventScroll: true } );
			var bounds = root.getBoundingClientRect();
			if ( bounds.top < 0 || bounds.top > window.innerHeight * 0.45 ) {
				root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		}

		function stepHeading( body, label, description ) {
			var heading = create( 'h3', 'yo-booking-step-title', label );
			heading.tabIndex = -1;
			heading.setAttribute( 'data-step-heading', '' );
			body.appendChild( heading );
			if ( description ) body.appendChild( create( 'p', 'yo-booking-step-description', description ) );
			return heading;
		}

		function markSelected( button, selected ) {
			button.classList.toggle( 'is-selected', !! selected );
			button.setAttribute( 'aria-pressed', selected ? 'true' : 'false' );
		}

		function init() {
			if ( state.mode === 'manage' ) {
				loadManagedAppointment();
				return;
			}

			if ( state.mode === 'portal' ) {
				loadPortalAppointments();
				return;
			}

			Promise.all( [
				request( 'booking/services' ),
				request( 'booking/current-customer' ),
			] )
					.then( function ( results ) {
						var customer = results[1].customer || state.customer;
						var paymentConfig = results[0].payment || state.paymentConfig;
						var bookingConfig = results[0].booking || state.bookingConfig;
						var guestBlocked = ! results[1].logged_in && bookingConfig.allow_guest_booking === false;
						setState( {
							loading: false,
							services: guestBlocked ? [] : ( results[0].services || [] ),
							error: guestBlocked ? t( 'Sign in to book an appointment.' ) : '',
							paymentConfig: paymentConfig,
							bookingConfig: bookingConfig,
							loggedIn: !! results[1].logged_in,
							marketingConsent: !! customer.marketing_consent,
						paymentMethod: paymentConfig.default_method || ( paymentConfig.methods && paymentConfig.methods[0] ? paymentConfig.methods[0].id : '' ),
						customer: {
							name: customer.name || '',
							email: customer.email || '',
							phone: customer.phone || '',
							note: '',
						},
					} );
				} )
				.catch( function ( error ) {
					setState( { loading: false, error: error.message } );
				} );
		}

		function selectedService() {
			return state.services.find( function ( service ) {
				return service.id === state.selectedService;
			} );
		}

		function selectedStaff() {
			return state.staff.find( function ( staff ) {
				return staff.id === state.selectedStaffId;
			} );
		}

		function selectedPaymentMethod() {
			return ( state.paymentConfig.methods || [] ).find( function ( method ) {
				return method.id === state.paymentMethod;
			} );
		}

		function paymentRequired() {
			var service = selectedService();
			return !! ( state.paymentConfig.enabled && service && parseFloat( service.price || '0' ) > 0 );
		}

		function loadStaff( serviceId ) {
			setState( {
				busy: true,
				error: '',
				selectedService: serviceId,
				selectedStaffId: 0,
				staffChosen: false,
				selectedDate: '',
				selectedSlot: null,
				dates: [],
				times: [],
			} );

			if ( state.bookingConfig.allow_staff_selection === false ) {
				loadDates( 0 );
				return;
			}

			request( 'booking/staff?service_id=' + encodeURIComponent( serviceId ) )
				.then( function ( data ) {
					setState( {
						busy: false,
						staff: data.staff || [],
						step: 1,
					} );
				} )
				.catch( function ( error ) {
					setState( { busy: false, error: error.message } );
				} );
		}

		function loadDates( staffId ) {
			var query = 'availability/dates?service_id=' + encodeURIComponent( state.selectedService ) +
				'&staff_id=' + encodeURIComponent( staffId || 0 ) +
				'&timezone=' + encodeURIComponent( config.timezone || 'UTC' );

			setState( {
				busy: true,
				error: '',
				selectedStaffId: staffId || 0,
				staffChosen: true,
				selectedDate: '',
				selectedSlot: null,
				dates: [],
				times: [],
			} );

			request( query )
				.then( function ( data ) {
					setState( {
						busy: false,
						dates: data.dates || [],
						step: 2,
					} );
				} )
				.catch( function ( error ) {
					setState( { busy: false, error: error.message } );
				} );
		}

		function loadTimes( date ) {
			var query = 'availability/times?service_id=' + encodeURIComponent( state.selectedService ) +
				'&staff_id=' + encodeURIComponent( state.selectedStaffId || 0 ) +
				'&date=' + encodeURIComponent( date ) +
				'&timezone=' + encodeURIComponent( config.timezone || 'UTC' );

			setState( {
				busy: true,
				error: '',
				selectedDate: date,
				selectedSlot: null,
				times: [],
			} );

			request( query )
				.then( function ( data ) {
					setState( {
						busy: false,
						times: data.slots || [],
						step: 3,
					} );
				} )
				.catch( function ( error ) {
					setState( { busy: false, error: error.message } );
				} );
		}

		function chooseSlot( slot ) {
			setState( {
				selectedSlot: slot,
				step: 4,
				error: '',
			} );
		}

		function submitBooking() {
			var service = selectedService();
			var slotStaffId = state.selectedStaffId || ( state.selectedSlot.staff_ids && state.selectedSlot.staff_ids[0] ? state.selectedSlot.staff_ids[0] : 0 );
			var payload = {
				service_id: state.selectedService,
				staff_id: slotStaffId,
				customer_name: state.customer.name,
				customer_email: state.customer.email,
				customer_phone: state.customer.phone,
					customer_note: state.customer.note,
					marketing_consent: state.marketingConsent,
				payment_method: state.paymentMethod,
				date: state.selectedDate,
				start_time: formatTimeLabel( state.selectedSlot.start ),
				duration_minutes: service ? service.duration_minutes : 0,
				timezone: config.timezone || 'UTC',
			};

			if ( state.busy ) {
				return;
			}

			setState( { busy: true, error: '' } );

			request( 'booking/appointments', {
				method: 'POST',
				body: JSON.stringify( payload ),
			} )
				.then( function ( data ) {
					if ( data.checkout_url ) {
						window.location.assign( data.checkout_url );
						return;
					}
					setState( {
						busy: false,
						confirmation: data,
						step: 6,
					} );
				} )
				.catch( function ( error ) {
					setState( { busy: false, error: error.message } );
				} );
		}

		function resetBooking() {
			setState( {
				step: 0,
				error: '',
				busy: false,
				staff: [],
				dates: [],
				times: [],
				selectedService: null,
				selectedStaffId: 0,
				selectedDate: '',
				selectedSlot: null,
				confirmation: null,
				fieldErrors: {},
				staffChosen: false,
				paymentMethod: state.paymentConfig.default_method || ( state.paymentConfig.methods[0] ? state.paymentConfig.methods[0].id : '' ),
			} );
		}

		function managePath( suffix ) {
			return 'booking/appointments/' + encodeURIComponent( state.action.appointment ) + '/' + suffix +
				'?token=' + encodeURIComponent( state.action.token ) + '&action=' + encodeURIComponent( state.action.type );
		}

		function loadManagedAppointment() {
			request( managePath( 'manage' ) )
				.then( function ( data ) {
					setState( {
						loading: false,
						manageAppointment: data.appointment,
						error: '',
					} );

					if ( state.action.type === 'reschedule' ) {
						loadManageDates();
					}
				} )
				.catch( function ( error ) {
					setState( { loading: false, error: error.message } );
				} );
		}

		function loadManageDates() {
			var appointment = state.manageAppointment;

			if ( ! appointment ) {
				return;
			}

			var query = 'availability/dates?service_id=' + encodeURIComponent( appointment.service_id ) +
				'&staff_id=' + encodeURIComponent( appointment.staff_id || 0 ) +
				'&timezone=' + encodeURIComponent( appointment.timezone || config.timezone || 'UTC' ) +
				'&exclude_appointment_id=' + encodeURIComponent( appointment.id );

			setState( {
				busy: true,
				error: '',
				manageSelectedDate: '',
				manageSelectedSlot: null,
				manageDates: [],
				manageTimes: [],
				manageStage: 'reschedule-date',
			} );

			request( query )
				.then( function ( data ) {
					setState( {
						busy: false,
						manageDates: data.dates || [],
					} );
				} )
				.catch( function ( error ) {
					setState( { busy: false, error: error.message } );
				} );
		}

		function loadManageTimes( date ) {
			var appointment = state.manageAppointment;
			var query = 'availability/times?service_id=' + encodeURIComponent( appointment.service_id ) +
				'&staff_id=' + encodeURIComponent( appointment.staff_id || 0 ) +
				'&date=' + encodeURIComponent( date ) +
				'&timezone=' + encodeURIComponent( appointment.timezone || config.timezone || 'UTC' ) +
				'&exclude_appointment_id=' + encodeURIComponent( appointment.id );

			setState( {
				busy: true,
				error: '',
				manageSelectedDate: date,
				manageSelectedSlot: null,
				manageTimes: [],
				manageStage: 'reschedule-time',
			} );

			request( query )
				.then( function ( data ) {
					setState( {
						busy: false,
						manageTimes: data.slots || [],
					} );
				} )
				.catch( function ( error ) {
					setState( { busy: false, error: error.message } );
				} );
		}

		function submitCancel() {
			if ( state.busy ) {
				return;
			}

			setState( { busy: true, error: '', message: '' } );

			request( managePath( 'cancel' ), {
				method: 'POST',
				body: JSON.stringify( {
					reason: state.cancelReason || t( 'Cancelled by customer.' ),
				} ),
			} )
				.then( function ( data ) {
					setState( {
						busy: false,
						message: data.message || t( 'Your appointment has been cancelled.' ),
						manageAppointment: data.appointment,
						manageStage: 'done',
					} );
				} )
				.catch( function ( error ) {
					setState( { busy: false, error: error.message } );
				} );
		}

		function submitReschedule() {
			var appointment = state.manageAppointment;
			var slot = state.manageSelectedSlot;
			var staffId = appointment.staff_id || ( slot.staff_ids && slot.staff_ids[0] ? slot.staff_ids[0] : 0 );

			if ( state.busy || ! slot ) {
				return;
			}

			setState( { busy: true, error: '', message: '' } );

			request( managePath( 'reschedule' ), {
				method: 'POST',
				body: JSON.stringify( {
					date: state.manageSelectedDate,
					start_time: formatTimeLabel( slot.start ),
					staff_id: staffId,
				} ),
			} )
				.then( function ( data ) {
					setState( {
						busy: false,
						message: data.message || t( 'Your appointment has been rescheduled.' ),
						manageAppointment: data.appointment,
						manageStage: 'done',
					} );
				} )
				.catch( function ( error ) {
					setState( { busy: false, error: error.message } );
				} );
		}

		function loadPortalAppointments() {
			request( 'booking/customer/appointments' )
				.then( function ( data ) {
					setState( {
						loading: false,
						error: '',
						portalAppointments: data.appointments || {
							upcoming: [],
							past: [],
						},
					} );
				} )
				.catch( function ( error ) {
					setState( { loading: false, error: error.message } );
				} );
		}

		function renderShell() {
			root.textContent = '';
			root.classList.add( 'yo-booking-ready' );

			var panel = create( 'div', 'yo-booking-panel' );
			var header = create( 'div', 'yo-booking-header' );
			var titleText = state.mode === 'manage' ? ( appearance.manageTitle || t( 'Manage appointment' ) ) : ( state.mode === 'portal' ? ( appearance.portalTitle || t( 'My appointments' ) ) : ( appearance.bookingTitle || t( 'Book an appointment' ) ) );
			var title = create( 'h2', '', titleText );
			title.id = 'yo-booking-title-' + rootIndex;
			root.setAttribute( 'aria-labelledby', title.id );
			panel.setAttribute( 'aria-busy', state.loading || state.busy ? 'true' : 'false' );

			header.appendChild( title );

			if ( state.mode === 'booking' && state.step < steps.length && appearance.showProgress !== false ) {
				var progressSteps = steps.map( function ( label, index ) { return { label: label, stateStep: index }; } );
				if ( state.bookingConfig.allow_staff_selection === false ) {
					progressSteps = progressSteps.filter( function ( item ) { return item.stateStep !== 1; } );
				}
				var progressIndex = progressSteps.findIndex( function ( item ) { return item.stateStep === state.step; } );
				progressIndex = progressIndex < 0 ? progressSteps.length - 1 : progressIndex;
				var progress = create( 'ol', 'yo-booking-progress' );
				progress.setAttribute( 'aria-label', t( 'Booking progress' ) );
				progressSteps.forEach( function ( progressStep, index ) {
					var item = create( 'li', index <= progressIndex ? 'is-active' : '', progressStep.label );
					if ( index === progressIndex ) item.setAttribute( 'aria-current', 'step' );
					progress.appendChild( item );
				} );
				header.appendChild( progress );

				var compactProgress = create( 'div', 'yo-booking-progress-compact' );
				var compactLabel = create( 'span', '', formatValues( 'Step %1$d of %2$d: %3$s', [ progressIndex + 1, progressSteps.length, progressSteps[ progressIndex ].label ] ) );
				var compactTrack = create( 'span', 'yo-booking-progress-track' );
				var compactBar = create( 'span', 'yo-booking-progress-bar' );
				compactBar.style.width = Math.min( 100, ( ( progressIndex + 1 ) / progressSteps.length ) * 100 ) + '%';
				compactTrack.appendChild( compactBar );
				compactProgress.appendChild( compactLabel );
				compactProgress.appendChild( compactTrack );
				header.appendChild( compactProgress );
			}

			panel.appendChild( header );

			if ( state.error ) {
				var alert = create( 'div', 'yo-booking-alert', state.error );
				alert.setAttribute( 'role', 'alert' );
				alert.tabIndex = -1;
				panel.appendChild( alert );
				window.setTimeout( function () { alert.focus(); }, 0 );
			}

			if ( state.message ) {
				var success = create( 'div', 'yo-booking-success', state.message );
				success.setAttribute( 'role', 'status' );
				success.setAttribute( 'aria-live', 'polite' );
				panel.appendChild( success );
			}

			return panel;
		}

		function loadingSkeleton() {
			var skeleton = create( 'div', 'yo-booking-skeleton' );
			skeleton.setAttribute( 'role', 'status' );
			skeleton.setAttribute( 'aria-live', 'polite' );
			skeleton.setAttribute( 'aria-label', t( 'Loading...' ) );
			skeleton.appendChild( create( 'span', 'yo-booking-skeleton-line yo-booking-skeleton-line--title' ) );
			var grid = create( 'div', 'yo-booking-skeleton-grid' );
			for ( var index = 0; index < 3; index++ ) {
				var card = create( 'span', 'yo-booking-skeleton-card' );
				card.appendChild( create( 'span', 'yo-booking-skeleton-line' ) );
				card.appendChild( create( 'span', 'yo-booking-skeleton-line yo-booking-skeleton-line--short' ) );
				grid.appendChild( card );
			}
			skeleton.appendChild( grid );
			return skeleton;
		}

		function renderServices( panel ) {
			var body = create( 'div', 'yo-booking-body' );
			var grid = create( 'div', 'yo-booking-card-grid' );
			stepHeading( body, t( 'Choose a service' ), t( 'Select the appointment type that best fits your needs.' ) );

			if ( ! state.services.length ) {
				body.appendChild( create( 'div', 'yo-booking-empty', t( 'No services are available.' ) ) );
			}

			state.services.forEach( function ( service ) {
				var button = create( 'button', 'yo-booking-card', '' );
				var name = create( 'span', 'yo-booking-card-title', service.name );
				var price = appearance.showServicePrices !== false && service.price ? ' · ' + ( service.price_display || ( service.currency + ' ' + service.price ) ) : '';
				var meta = create( 'span', 'yo-booking-card-meta', formatText( '%d min', service.duration_minutes ) + price );
				var desc = create( 'span', 'yo-booking-card-desc', service.description || '' );

				button.type = 'button';
				markSelected( button, service.id === state.selectedService );
				button.appendChild( name );
				button.appendChild( meta );
				if ( appearance.showServiceDetails !== false && service.description ) {
					button.appendChild( desc );
				}
				button.addEventListener( 'click', function () {
					loadStaff( service.id );
				} );
				grid.appendChild( button );
			} );

			body.appendChild( grid );
			panel.appendChild( body );
		}

		function renderStaff( panel ) {
			var body = create( 'div', 'yo-booking-body' );
			var grid = create( 'div', 'yo-booking-card-grid' );
			var any = create( 'button', 'yo-booking-card', '' );
			stepHeading( body, t( 'Choose a staff member' ), t( 'Select a preferred staff member or choose the first available option.' ) );

			any.type = 'button';
			markSelected( any, state.staffChosen && state.selectedStaffId === 0 );
			any.appendChild( create( 'span', 'yo-booking-card-title', t( 'Any available staff' ) ) );
			any.appendChild( create( 'span', 'yo-booking-card-meta', t( 'First matching appointment slot' ) ) );
			any.addEventListener( 'click', function () {
				loadDates( 0 );
			} );
			grid.appendChild( any );

			state.staff.forEach( function ( staff ) {
				var button = create( 'button', 'yo-booking-card', '' );
				button.type = 'button';
				markSelected( button, state.staffChosen && staff.id === state.selectedStaffId );
				button.appendChild( create( 'span', 'yo-booking-card-title', staff.name ) );
				if ( staff.bio ) {
					button.appendChild( create( 'span', 'yo-booking-card-desc', staff.bio ) );
				}
				button.addEventListener( 'click', function () {
					loadDates( staff.id );
				} );
				grid.appendChild( button );
			} );

			body.appendChild( grid );
			body.appendChild( singleBackAction( backButton( 0 ) ) );
			panel.appendChild( body );
		}

		function renderDates( panel ) {
			var body = create( 'div', 'yo-booking-body' );
			var grid = create( 'div', 'yo-booking-date-grid' );
			stepHeading( body, t( 'Choose a date' ), t( 'Available dates are shown in the site timezone.' ) );

			if ( ! state.dates.length ) {
				body.appendChild( create( 'div', 'yo-booking-empty', t( 'No dates are available.' ) ) );
			}

			state.dates.forEach( function ( item ) {
				var button = create( 'button', 'yo-booking-date', '' );
				button.type = 'button';
				markSelected( button, item.date === state.selectedDate );
				if ( item.date === config.today ) button.classList.add( 'is-today' );
				button.appendChild( create( 'strong', '', item.date_display || formatDateLabel( item.date ) ) );
				if ( item.date === config.today ) button.appendChild( create( 'span', 'yo-booking-today', t( 'Today' ) ) );
				button.appendChild( create( 'span', '', formatText( '%d slots', item.slot_count ) ) );
				button.addEventListener( 'click', function () {
					loadTimes( item.date );
				} );
				grid.appendChild( button );
			} );

			body.appendChild( grid );
			body.appendChild( singleBackAction( backButton( state.bookingConfig.allow_staff_selection === false ? 0 : 1 ) ) );
			panel.appendChild( body );
		}

		function renderTimes( panel ) {
			var body = create( 'div', 'yo-booking-body' );
			var grid = create( 'div', 'yo-booking-time-grid' );
			stepHeading( body, t( 'Choose a time' ), formatText( 'Times shown in %s', config.timezone || 'UTC' ) );

			if ( ! state.times.length ) {
				body.appendChild( create( 'div', 'yo-booking-empty', t( 'No times are available.' ) ) );
			}

			state.times.forEach( function ( slot ) {
				var button = create( 'button', 'yo-booking-time', slotTimeLabel( slot ) );
				button.type = 'button';
				markSelected( button, state.selectedSlot && slot.start === state.selectedSlot.start );
				button.addEventListener( 'click', function () {
					chooseSlot( slot );
				} );
				grid.appendChild( button );
			} );

			body.appendChild( grid );
			body.appendChild( singleBackAction( backButton( 2 ) ) );
			panel.appendChild( body );
		}

		function renderDetails( panel ) {
			var body = create( 'div', 'yo-booking-body' );
			var form = create( 'form', 'yo-booking-form' );
			form.noValidate = true;
			stepHeading( body, t( 'Your details' ), t( 'We will use these details to confirm and manage your appointment.' ) );

			form.appendChild( inputField( t( 'Name' ), 'name', 'text', state.customer.name, true ) );
			form.appendChild( inputField( t( 'Email' ), 'email', 'email', state.customer.email, state.bookingConfig.require_email !== false ) );
			form.appendChild( inputField( t( 'Phone' ), 'phone', 'tel', state.customer.phone, state.bookingConfig.require_phone !== false ) );
			form.appendChild( noteField() );
			if ( state.bookingConfig.marketing_consent_required ) {
				form.appendChild( marketingConsentField() );
			}
			if ( paymentRequired() ) {
				form.appendChild( paymentMethodField() );
			}

			var actions = create( 'div', 'yo-booking-actions' );
			var next = create( 'button', 'yo-booking-primary', t( 'Review' ) );
			next.type = 'submit';
			actions.appendChild( backButton( 3 ) );
			actions.appendChild( next );
			form.appendChild( actions );

			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				var errors = validateCustomer();
				if ( Object.keys( errors ).length ) {
					setState( { fieldErrors: errors, error: '' } );
					window.requestAnimationFrame( function () {
						var invalid = root.querySelector( '[aria-invalid="true"]' );
						if ( invalid ) invalid.focus();
					} );
					return;
				}
				setState( { step: 5, error: '', fieldErrors: {} } );
			} );

			body.appendChild( form );
			panel.appendChild( body );
		}

		function renderReview( panel ) {
			var body = create( 'div', 'yo-booking-body' );
			var service = selectedService();
			var staff = selectedStaff();
			var paymentMethod = selectedPaymentMethod();
			stepHeading( body, t( 'Review your booking' ), t( 'Confirm the details below before submitting your appointment.' ) );
			body.appendChild( summaryGroup( t( 'Booking details' ), [
				[ t( 'Service' ), service ? service.name : '' ],
				[ t( 'Staff' ), staff ? staff.name : t( 'Any available staff' ) ],
				[ t( 'Date' ), selectedDateLabel( state.dates, state.selectedDate ) ],
				[ t( 'Time' ), state.selectedSlot ? slotTimeLabel( state.selectedSlot ) : '' ],
			] ) );
			body.appendChild( summaryGroup( t( 'Customer details' ), [
				[ t( 'Name' ), state.customer.name ],
				[ t( 'Email' ), state.customer.email ],
				[ t( 'Phone' ), state.customer.phone ],
			] ) );
			if ( service && ( ( appearance.showServicePrices !== false && service.price ) || paymentRequired() ) ) {
				var paymentRows = [];
				if ( appearance.showServicePrices !== false && service.price ) paymentRows.push( [ t( 'Total' ), service.price_display || ( ( service.currency ? service.currency + ' ' : '' ) + service.price ) ] );
				if ( paymentRequired() && paymentMethod ) paymentRows.push( [ t( 'Payment method' ), paymentMethod.title ] );
				body.appendChild( summaryGroup( t( 'Payment' ), paymentRows ) );
				if ( paymentRequired() && paymentMethod && paymentMethod.description ) {
					var instructions = create( 'div', 'yo-booking-payment-instructions' );
					instructions.appendChild( create( 'strong', '', paymentMethod.title ) );
					instructions.appendChild( create( 'p', '', paymentMethod.description ) );
					body.appendChild( instructions );
				}
			}

			var actions = create( 'div', 'yo-booking-actions' );
			var confirm = create( 'button', 'yo-booking-primary', state.busy ? t( 'Confirming...' ) : t( 'Confirm booking' ) );
			confirm.type = 'button';
			confirm.disabled = state.busy;
			confirm.addEventListener( 'click', submitBooking );
			actions.appendChild( backButton( 4 ) );
			actions.appendChild( confirm );

			body.appendChild( actions );
			panel.appendChild( body );
		}

		function renderDone( panel ) {
			var body = create( 'div', 'yo-booking-body yo-booking-done' );
			var id = state.confirmation ? state.confirmation.appointment_id : '';
			var appointment = state.confirmation ? state.confirmation.appointment : null;
			var payment = state.confirmation ? state.confirmation.payment : null;
			var paymentReference = payment && payment.reference ? payment.reference : (appointment && appointment.payment_reference ? appointment.payment_reference : '');
			stepHeading( body, t( 'Appointment received' ), state.confirmation && state.confirmation.message ? state.confirmation.message : t( 'Your appointment has been received.' ) );
			body.appendChild( create( 'p', 'yo-booking-reference', paymentReference ? formatText( 'Payment reference: %s', paymentReference ) : formatText( 'Reference #%s', id ) ) );
			if ( appointment ) {
				body.appendChild( summaryGroup( t( 'Booking details' ), [
					[ t( 'Service' ), appointment.service_name ],
					[ t( 'Staff' ), appointment.staff_name || t( 'Any available staff' ) ],
					[ t( 'Date' ), appointment.date_display || appointment.date ],
					[ t( 'Time' ), ( appointment.start_time_display || appointment.start_time ) + ' - ' + ( appointment.end_time_display || appointment.end_time ) ],
					[ t( 'Status' ), statusLabel( appointment.status ) ],
				] ) );
			}
			if ( payment ) {
				body.appendChild( paymentBox( payment ) );
			}
			if ( state.confirmation && state.confirmation.payment_error ) {
				body.appendChild( create( 'div', 'yo-booking-alert', state.confirmation.payment_error ) );
			}
			var actions = create( 'div', 'yo-booking-actions yo-booking-confirmation-actions' );
			if ( state.confirmation && state.confirmation.manage_url ) {
				actions.appendChild( portalLink( state.confirmation.manage_url, t( 'Manage appointment' ), 'yo-booking-secondary' ) );
			}
			var again = create( 'button', 'yo-booking-primary', t( 'Book another appointment' ) );
			again.type = 'button';
			again.addEventListener( 'click', resetBooking );
			actions.appendChild( again );
			body.appendChild( actions );
			panel.appendChild( body );
		}

		function renderManage( panel ) {
			if ( ! state.manageAppointment ) {
				panel.appendChild( create( 'div', 'yo-booking-status', t( 'Loading appointment...' ) ) );
				return;
			}

			if ( state.manageStage === 'cancel' ) {
				renderManageCancel( panel );
			} else if ( state.manageStage === 'reschedule-time' ) {
				renderManageTimes( panel );
			} else if ( state.manageStage === 'reschedule-review' ) {
				renderManageReview( panel );
			} else if ( state.manageStage === 'done' ) {
				renderManageDone( panel );
			} else {
				renderManageDates( panel );
			}
		}

		function renderPortal( panel ) {
			var body = create( 'div', 'yo-booking-body' );

			renderPortalSection( body, t( 'Upcoming' ), state.portalAppointments.upcoming || [] );
			renderPortalSection( body, t( 'Past' ), state.portalAppointments.past || [] );

			panel.appendChild( body );
		}

		function renderPortalSection( body, title, appointments ) {
			var section = create( 'section', 'yo-booking-portal-section' );
			var list = create( 'div', 'yo-booking-appointment-list' );

			section.appendChild( create( 'h3', 'yo-booking-section-title', title ) );

			if ( ! appointments.length ) {
				section.appendChild( create( 'div', 'yo-booking-empty', title === t( 'Upcoming' ) ? t( 'No upcoming appointments.' ) : t( 'No past appointments.' ) ) );
				body.appendChild( section );
				return;
			}

			appointments.forEach( function ( appointment ) {
				list.appendChild( portalAppointmentCard( appointment ) );
			} );

			section.appendChild( list );
			body.appendChild( section );
		}

		function portalAppointmentCard( appointment ) {
			var card = create( 'article', 'yo-booking-appointment-row' );
			var main = create( 'div', 'yo-booking-appointment-main' );
			var meta = create( 'div', 'yo-booking-appointment-meta' );
			var actions = create( 'div', 'yo-booking-appointment-actions' );

			main.appendChild( create( 'strong', '', appointment.service_name ) );
			main.appendChild( create( 'span', '', ( appointment.date_display || appointment.date ) + ' ' + ( appointment.start_time_display || appointment.start_time ) + ' - ' + ( appointment.end_time_display || appointment.end_time ) ) );
			meta.appendChild( create( 'span', '', appointment.staff_name || t( 'Any available staff' ) ) );
			meta.appendChild( create( 'span', '', statusLabel( appointment.status ) ) );
			meta.appendChild( create( 'span', '', formatText( 'Payment: %s', statusLabel( appointment.payment_status || 'pending' ) ) ) );

			if ( appointment.can_reschedule && appointment.reschedule_url ) {
				actions.appendChild( portalLink( appointment.reschedule_url, t( 'Reschedule' ), 'yo-booking-secondary' ) );
			}

			if ( appointment.can_cancel && appointment.cancel_url ) {
				actions.appendChild( portalLink( appointment.cancel_url, t( 'Cancel' ), 'yo-booking-danger' ) );
			}

			card.appendChild( main );
			card.appendChild( meta );
			if ( actions.childNodes.length ) {
				card.appendChild( actions );
			}

			return card;
		}

		function portalLink( href, label, className ) {
			var link = create( 'a', className, label );
			link.href = href;
			return link;
		}

		function paymentBox( payment ) {
			var box = create( 'div', 'yo-booking-payment-box' );
			var title = payment.collection_mode === 'deposit' ? t( 'Deposit due' ) : t( 'Payment due' );
			var amount = payment.amount_due_display || ( payment.amount_due + ( payment.currency ? ' ' + payment.currency : '' ) );

			if ( ! payment.enabled || ! parseFloat( payment.amount_due || '0' ) ) {
				return create( 'div', 'yo-booking-payment-box', t( 'No payment is due at booking.' ) );
			}

			box.appendChild( create( 'strong', '', title + ': ' + amount ) );
			if ( payment.provider_title ) {
				box.appendChild( create( 'span', '', payment.provider_title ) );
			}
			if ( payment.instructions ) {
				box.appendChild( create( 'p', '', payment.instructions ) );
			}

			return box;
		}

		function renderManageCancel( panel ) {
			var body = create( 'div', 'yo-booking-body' );
			var appointment = state.manageAppointment;
			var canCancel = !! appointment.can_cancel;
			var reason = create( 'textarea', '' );
			var reasonWrap = create( 'label', 'yo-booking-field' );
			var actions = create( 'div', 'yo-booking-actions' );
			var submit = create( 'button', 'yo-booking-danger', state.busy ? t( 'Cancelling...' ) : t( 'Cancel appointment' ) );
			stepHeading( body, t( 'Cancel appointment' ), t( 'Review the appointment and optionally tell us why you are cancelling.' ) );

			body.appendChild( appointmentSummary( appointment ) );

			if ( ! canCancel ) {
				body.appendChild( create( 'div', 'yo-booking-empty', t( 'This appointment can no longer be cancelled online.' ) ) );
				panel.appendChild( body );
				return;
			}

			reason.rows = 3;
			reason.value = state.cancelReason;
			reason.addEventListener( 'input', function () {
				state.cancelReason = reason.value;
			} );
			reasonWrap.appendChild( create( 'span', '', t( 'Reason' ) ) );
			reasonWrap.appendChild( reason );
			body.appendChild( reasonWrap );

			submit.type = 'button';
			submit.disabled = state.busy;
			submit.addEventListener( 'click', submitCancel );
			actions.appendChild( submit );
			body.appendChild( actions );
			panel.appendChild( body );
		}

		function renderManageDates( panel ) {
			var body = create( 'div', 'yo-booking-body' );
			var appointment = state.manageAppointment;
			var grid = create( 'div', 'yo-booking-date-grid' );

			stepHeading( body, t( 'Choose a new date' ), t( 'Available dates are shown in the site timezone.' ) );
			body.appendChild( appointmentSummary( appointment ) );

			if ( ! appointment.can_reschedule ) {
				body.appendChild( create( 'div', 'yo-booking-empty', t( 'This appointment can no longer be rescheduled online.' ) ) );
				panel.appendChild( body );
				return;
			}

			if ( ! state.manageDates.length && ! state.busy ) {
				body.appendChild( create( 'div', 'yo-booking-empty', t( 'No dates are available.' ) ) );
			}

			state.manageDates.forEach( function ( item ) {
				var button = create( 'button', 'yo-booking-date', '' );
				button.type = 'button';
				markSelected( button, item.date === state.manageSelectedDate );
				if ( item.date === config.today ) button.classList.add( 'is-today' );
				button.appendChild( create( 'strong', '', item.date_display || formatDateLabel( item.date ) ) );
				button.appendChild( create( 'span', '', formatText( '%d slots', item.slot_count ) ) );
				button.addEventListener( 'click', function () {
					loadManageTimes( item.date );
				} );
				grid.appendChild( button );
			} );

			body.appendChild( grid );
			panel.appendChild( body );
		}

		function renderManageTimes( panel ) {
			var body = create( 'div', 'yo-booking-body' );
			var grid = create( 'div', 'yo-booking-time-grid' );

			stepHeading( body, t( 'Choose a new time' ), formatText( 'Times shown in %s', config.timezone || 'UTC' ) );
			body.appendChild( appointmentSummary( state.manageAppointment ) );

			if ( ! state.manageTimes.length && ! state.busy ) {
				body.appendChild( create( 'div', 'yo-booking-empty', t( 'No times are available.' ) ) );
			}

			state.manageTimes.forEach( function ( slot ) {
				var button = create( 'button', 'yo-booking-time', slotTimeLabel( slot ) );
				button.type = 'button';
				markSelected( button, state.manageSelectedSlot && slot.start === state.manageSelectedSlot.start );
				button.addEventListener( 'click', function () {
					setState( {
						manageSelectedSlot: slot,
						manageStage: 'reschedule-review',
						error: '',
					} );
				} );
				grid.appendChild( button );
			} );

			body.appendChild( grid );
			body.appendChild( singleBackAction( manageBackButton( 'reschedule-date' ) ) );
			panel.appendChild( body );
		}

		function renderManageReview( panel ) {
			var body = create( 'div', 'yo-booking-body' );
			var summary = create( 'dl', 'yo-booking-summary' );
			var actions = create( 'div', 'yo-booking-actions' );
			var submit = create( 'button', 'yo-booking-primary', state.busy ? t( 'Rescheduling...' ) : t( 'Confirm new time' ) );
			stepHeading( body, t( 'Review your new time' ), t( 'Confirm the updated appointment details below.' ) );

			addSummary( summary, t( 'Service' ), state.manageAppointment.service_name );
			addSummary( summary, t( 'Current' ), state.manageAppointment.datetime_display || ( state.manageAppointment.date + ' ' + state.manageAppointment.start_time ) );
			addSummary( summary, t( 'New date' ), selectedDateLabel( state.manageDates, state.manageSelectedDate ) );
			addSummary( summary, t( 'New time' ), state.manageSelectedSlot ? slotTimeLabel( state.manageSelectedSlot ) : '' );

			submit.type = 'button';
			submit.disabled = state.busy || ! state.manageSelectedSlot;
			submit.addEventListener( 'click', submitReschedule );
			actions.appendChild( manageBackButton( 'reschedule-time' ) );
			actions.appendChild( submit );

			body.appendChild( summary );
			body.appendChild( actions );
			panel.appendChild( body );
		}

		function renderManageDone( panel ) {
			var body = create( 'div', 'yo-booking-body yo-booking-done' );
			stepHeading( body, t( 'Appointment updated' ), state.message );
			body.appendChild( appointmentSummary( state.manageAppointment ) );
			panel.appendChild( body );
		}

		function appointmentSummary( appointment ) {
			var summary = create( 'dl', 'yo-booking-summary yo-booking-manage-summary' );
			addSummary( summary, t( 'Service' ), appointment.service_name );
			addSummary( summary, t( 'Staff' ), appointment.staff_name || t( 'Any available staff' ) );
			addSummary( summary, t( 'Date' ), appointment.date_display || appointment.date );
			addSummary( summary, t( 'Time' ), ( appointment.start_time_display || appointment.start_time ) + ' - ' + ( appointment.end_time_display || appointment.end_time ) );
			addSummary( summary, t( 'Status' ), statusLabel( appointment.status ) );
			addSummary( summary, t( 'Payment' ), statusLabel( appointment.payment_status || 'pending' ) );
			return summary;
		}

		function inputField( label, key, type, value, required ) {
			var wrap = create( 'label', 'yo-booking-field' );
			var text = create( 'span', '', label );
			var input = create( 'input', '' );
			var error = state.fieldErrors[ key ] || '';
			var errorId = 'yo-booking-field-error-' + rootIndex + '-' + key;
			input.type = type;
			input.name = key;
			input.value = value || '';
			input.required = !! required;
			input.autocomplete = { name: 'name', email: 'email', phone: 'tel' }[ key ] || '';
			if ( error ) {
				input.setAttribute( 'aria-invalid', 'true' );
				input.setAttribute( 'aria-describedby', errorId );
				wrap.classList.add( 'has-error' );
			}
			input.addEventListener( 'input', function () {
				state.customer[ key ] = input.value;
				if ( state.fieldErrors[ key ] ) {
					delete state.fieldErrors[ key ];
					input.removeAttribute( 'aria-invalid' );
					input.removeAttribute( 'aria-describedby' );
					wrap.classList.remove( 'has-error' );
					var message = wrap.querySelector( '.yo-booking-field-error' );
					if ( message ) message.remove();
				}
			} );
			wrap.appendChild( text );
			wrap.appendChild( input );
			if ( error ) {
				var message = create( 'small', 'yo-booking-field-error', error );
				message.id = errorId;
				wrap.appendChild( message );
			}
			return wrap;
		}

		function validateCustomer() {
			var errors = {};
			if ( ! state.customer.name.trim() ) errors.name = t( 'This field is required.' );
			if ( state.bookingConfig.require_email !== false && ! state.customer.email.trim() ) {
				errors.email = t( 'This field is required.' );
			} else if ( state.customer.email.trim() && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( state.customer.email ) ) {
				errors.email = t( 'Enter a valid email address.' );
			}
			if ( state.bookingConfig.require_phone !== false && ! state.customer.phone.trim() ) errors.phone = t( 'This field is required.' );
			if ( state.bookingConfig.marketing_consent_required && ! state.marketingConsent ) errors.marketing_consent = t( 'Marketing consent is required.' );
			if ( paymentRequired() && ! selectedPaymentMethod() ) errors.payment_method = t( 'Select a payment method.' );
			return errors;
		}

		function paymentMethodField() {
			var fieldset = create( 'fieldset', 'yo-booking-payment-methods' );
			var legend = create( 'legend', '', t( 'Payment method' ) );
			var error = state.fieldErrors.payment_method || '';
			fieldset.appendChild( legend );

			( state.paymentConfig.methods || [] ).forEach( function ( method ) {
				var option = create( 'label', 'yo-booking-payment-method' );
				var input = create( 'input', '' );
				var content = create( 'span', 'yo-booking-payment-method-content' );
				input.type = 'radio';
				input.name = 'payment_method';
				input.value = method.id;
				input.checked = state.paymentMethod === method.id;
				input.addEventListener( 'change', function () {
					state.paymentMethod = method.id;
					delete state.fieldErrors.payment_method;
					fieldset.classList.remove( 'has-error' );
					fieldset.removeAttribute( 'aria-invalid' );
					fieldset.querySelectorAll( '[aria-invalid="true"]' ).forEach( function ( item ) { item.removeAttribute( 'aria-invalid' ); } );
					var message = fieldset.querySelector( '.yo-booking-field-error' );
					if ( message ) message.remove();
				} );
				content.appendChild( create( 'strong', '', method.title ) );
				if ( method.description ) content.appendChild( create( 'small', '', method.description ) );
				option.appendChild( input );
				option.appendChild( content );
				fieldset.appendChild( option );
			} );

			if ( error ) {
				fieldset.classList.add( 'has-error' );
				fieldset.setAttribute( 'aria-invalid', 'true' );
				var firstMethod = fieldset.querySelector( 'input' );
				if ( firstMethod ) firstMethod.setAttribute( 'aria-invalid', 'true' );
				fieldset.appendChild( create( 'small', 'yo-booking-field-error', error ) );
			}

			return fieldset;
		}

		function noteField() {
			var wrap = create( 'label', 'yo-booking-field' );
			var text = create( 'span', '', t( 'Note' ) );
			var input = create( 'textarea', '' );
			input.rows = 3;
			input.value = state.customer.note || '';
			input.addEventListener( 'input', function () {
				state.customer.note = input.value;
			} );
			wrap.appendChild( text );
			wrap.appendChild( input );
			return wrap;
		}

		function marketingConsentField() {
			var wrap = create( 'label', 'yo-booking-consent' );
			var input = create( 'input', '' );
			input.type = 'checkbox';
			input.checked = state.marketingConsent;
			input.required = true;
			if ( state.fieldErrors.marketing_consent ) {
				wrap.classList.add( 'has-error' );
				input.setAttribute( 'aria-invalid', 'true' );
			}
			input.addEventListener( 'change', function () {
				state.marketingConsent = input.checked;
				delete state.fieldErrors.marketing_consent;
				wrap.classList.remove( 'has-error' );
				input.removeAttribute( 'aria-invalid' );
				var message = wrap.querySelector( '.yo-booking-field-error' );
				if ( message ) message.remove();
			} );
			wrap.appendChild( input );
			wrap.appendChild( create( 'span', '', t( 'I agree to receive marketing communications.' ) ) );
			if ( state.fieldErrors.marketing_consent ) wrap.appendChild( create( 'small', 'yo-booking-field-error', state.fieldErrors.marketing_consent ) );
			return wrap;
		}

		function addSummary( summary, label, value ) {
			var dt = create( 'dt', '', label );
			var dd = create( 'dd', '', value );
			summary.appendChild( dt );
			summary.appendChild( dd );
		}

		function summaryGroup( title, rows ) {
			var section = create( 'section', 'yo-booking-review-group' );
			var summary = create( 'dl', 'yo-booking-summary' );
			section.appendChild( create( 'h4', '', title ) );
			rows.forEach( function ( row ) { addSummary( summary, row[0], row[1] ); } );
			section.appendChild( summary );
			return section;
		}

		function backButton( step ) {
			var button = create( 'button', 'yo-booking-secondary', t( 'Back' ) );
			button.type = 'button';
			button.addEventListener( 'click', function () {
				setState( { step: step, error: '' } );
			} );
			return button;
		}

		function singleBackAction( button ) {
			var actions = create( 'div', 'yo-booking-actions yo-booking-step-actions' );
			actions.appendChild( button );
			return actions;
		}

		function manageBackButton( stage ) {
			var button = create( 'button', 'yo-booking-secondary', t( 'Back' ) );
			button.type = 'button';
			button.addEventListener( 'click', function () {
				setState( { manageStage: stage, error: '' } );
			} );
			return button;
		}

		function render() {
			var panel = renderShell();

			if ( state.loading || state.busy ) {
				panel.appendChild( loadingSkeleton() );
				root.appendChild( panel );
				return;
			}

			if ( state.error && state.mode === 'portal' ) {
				root.appendChild( panel );
				return;
			}

			if ( state.mode === 'manage' ) {
				renderManage( panel );
			} else if ( state.mode === 'portal' ) {
				renderPortal( panel );
			} else if ( state.step === 0 ) {
				renderServices( panel );
			} else if ( state.step === 1 ) {
				renderStaff( panel );
			} else if ( state.step === 2 ) {
				renderDates( panel );
			} else if ( state.step === 3 ) {
				renderTimes( panel );
			} else if ( state.step === 4 ) {
				renderDetails( panel );
			} else if ( state.step === 5 ) {
				renderReview( panel );
			} else {
				renderDone( panel );
			}

			root.appendChild( panel );
		}

		init();
	}

	roots.forEach( function ( root, index ) { app( root, index ); } );
} )();
