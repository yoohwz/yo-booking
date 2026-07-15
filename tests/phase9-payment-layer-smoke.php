<?php
/**
 * Phase 9 payment layer smoke test for WP-CLI.
 *
 * Run with:
 * wp eval-file wp-content/plugins/yo-booking/tests/phase9-payment-layer-smoke.php
 *
 * @package YoBooking
 */

defined( 'ABSPATH' ) || exit;
defined( 'YO_BOOKING_RUNNING_TESTS' ) || define( 'YO_BOOKING_RUNNING_TESTS', true );

$settings_repository      = new YoBooking\Settings\Repository();
$service_repository       = new YoBooking\Repositories\ServiceRepository();
$staff_repository         = new YoBooking\Repositories\StaffRepository();
$staff_service_repository = new YoBooking\Repositories\StaffServiceRepository();
$rule_repository          = new YoBooking\Repositories\AvailabilityRuleRepository();
$appointment_repository   = new YoBooking\Repositories\AppointmentRepository();
$customer_repository      = new YoBooking\Repositories\CustomerRepository();
$log_repository           = new YoBooking\Repositories\NotificationLogRepository();
$payment_repository       = new YoBooking\Repositories\PaymentRepository();
$payment_manager          = new YoBooking\Payments\PaymentManager();
$settings                 = $settings_repository->all();
$original_settings        = $settings;
$timezone_name            = ! empty( $settings['company']['timezone'] ) ? $settings['company']['timezone'] : 'UTC';
$timezone                 = new DateTimeZone( $timezone_name );
$suffix                   = gmdate( 'YmdHis' );
$service_id               = 0;
$staff_id                 = 0;
$appointment_id           = 0;
$customer_id              = 0;
$error                    = '';

$fail = static function ( $message ) {
	throw new RuntimeException( $message );
};

$guard = static function ( $result, $label ) use ( $fail ) {
	if ( is_wp_error( $result ) ) {
		$fail( $label . ': ' . $result->get_error_message() );
	}

	if ( ! absint( $result ) ) {
		$fail( $label . ': missing ID' );
	}

	return absint( $result );
};

$next_weekday = static function () use ( $timezone ) {
	$date = new DateTimeImmutable( 'tomorrow', $timezone );

	for ( $i = 0; $i < 30; $i++ ) {
		if ( in_array( (int) $date->format( 'w' ), array( 1, 2, 3, 4, 5 ), true ) ) {
			return $date;
		}

		$date = $date->add( new DateInterval( 'P1D' ) );
	}

	return $date;
};

$rest_request = static function ( $method, $route, $params = array(), $nonce = '' ) {
	$request = new WP_REST_Request( $method, $route );

	if ( 'GET' === $method ) {
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
	} else {
		$request->set_header( 'X-WP-Nonce', $nonce );
		$request->set_body_params( $params );
	}

	return rest_do_request( $request );
};

try {
	if ( 1999 !== YoBooking\Payments\Money::to_minor( '19.99', 'USD' ) || '19.99' !== YoBooking\Payments\Money::from_minor( 1999, 'USD' ) ) {
		$fail( 'USD minor-unit conversion is not exact' );
	}
	if ( 2000 !== YoBooking\Payments\Money::to_minor( '1999.6', 'JPY' ) || '2000' !== YoBooking\Payments\Money::from_minor( 2000, 'JPY' ) ) {
		$fail( 'zero-decimal currency conversion is not exact' );
	}

	$settings['notifications']['enabled']            = false;
	$settings['payments']['enabled']                 = true;
	$settings['payments']['provider']                = 'bank_transfer';
	$settings['payments']['methods']                 = array( 'local', 'bank_transfer' );
	$settings['payments']['default_method']          = 'local';
	$settings['payments']['collection_mode']         = 'deposit';
	$settings['payments']['deposit_type']            = 'percent';
	$settings['payments']['deposit_amount']          = 25;
	$settings['payments']['bank_transfer_title']     = 'Bank transfer';
	$settings['payments']['bank_transfer_instructions'] = 'Pay the deposit by bank transfer before arrival.';
	$settings['payments']['bank_iban']               = 'GB82WEST12345698765432';
	$settings['payments']['require_payment_to_hold'] = false;
	$settings_repository->save( $settings );

	$date = $next_weekday();

	$service_id = $guard(
		$service_repository->save(
			array(
				'name'             => 'Payment Smoke Service ' . $suffix,
				'duration_minutes' => 60,
				'price'            => '200.00',
				'currency'         => 'USD',
				'capacity'         => 1,
				'status'           => 'active',
			)
		),
		'create service'
	);

	$staff_id = $guard(
		$staff_repository->save(
			array(
				'name'   => 'Payment Smoke Staff ' . $suffix,
				'email'  => 'payment-staff-' . $suffix . '@example.test',
				'status' => 'active',
			)
		),
		'create staff'
	);

	$staff_service_repository->sync_for_staff( $staff_id, array( $service_id ) );
	$rule_repository->replace_weekly(
		'staff',
		$staff_id,
		array(
			(int) $date->format( 'w' ) => array(
				'enabled'               => 1,
				'start_time'            => '11:00',
				'end_time'              => '13:00',
				'slot_interval_minutes' => 60,
			),
		),
		$timezone_name
	);

	do_action( 'rest_api_init' );

	$methods_response = $rest_request( 'GET', '/yo-booking/v1/booking/services' );
	$payment_config   = $methods_response->get_data()['payment'];
	if ( empty( $payment_config['enabled'] ) || 2 !== count( $payment_config['methods'] ) || 'local' !== $payment_config['default_method'] ) {
		$fail( 'frontend payment method configuration is incomplete' );
	}
	if ( isset( $payment_config['methods'][1]['instructions'] ) || false !== strpos( wp_json_encode( $payment_config ), 'GB82WEST' ) ) {
		$fail( 'public payment method configuration exposed bank account details before booking' );
	}

	$booking_response = $rest_request(
		'POST',
		'/yo-booking/v1/booking/appointments',
		array(
			'service_id'       => $service_id,
			'staff_id'         => $staff_id,
			'customer_name'    => 'Payment Smoke Customer ' . $suffix,
			'customer_email'   => 'payment-customer-' . $suffix . '@example.test',
			'customer_phone'   => '+15550000009',
			'payment_method'   => 'bank_transfer',
			'date'             => $date->format( 'Y-m-d' ),
			'start_time'       => '11:00',
			'duration_minutes' => 60,
			'timezone'         => $timezone_name,
		),
		wp_create_nonce( 'wp_rest' )
	);

	if ( $booking_response->is_error() ) {
		$fail( 'booking endpoint failed: ' . $booking_response->as_error()->get_error_message() );
	}

	$booking_data   = $booking_response->get_data();
	$appointment_id = absint( $booking_data['appointment_id'] );
	$appointment    = $appointment_repository->find_with_details( $appointment_id );
	$customer_id    = $appointment ? (int) $appointment->customer_id : 0;
	$payment        = isset( $booking_data['payment'] ) ? $booking_data['payment'] : array();

	if ( ! $appointment ) {
		$fail( 'booking did not persist appointment' );
	}

	if ( empty( $payment['enabled'] ) || 'deposit' !== $payment['collection_mode'] || '50.00' !== $payment['amount_due'] || 'USD' !== $payment['currency'] ) {
		$fail( 'booking response returned an unexpected payment summary' );
	}

	if ( 'bank_transfer' !== $appointment->payment_method || 'Bank transfer' !== $payment['provider_title'] || false === strpos( $payment['instructions'], 'GB82WEST' ) ) {
		$fail( 'selected bank transfer provider details were not returned' );
	}

	if ( 'pending' !== $appointment->payment_status || 'YB-' !== substr( $appointment->payment_reference, 0, 3 ) ) {
		$fail( 'new appointment did not persist its pending payment snapshot' );
	}

	$pending = $payment_repository->latest_for_appointment( $appointment_id );
	if ( ! $pending || 'bank_transfer' !== $pending->provider || 'pending' !== $pending->status || '50.00' !== YoBooking\Payments\Money::normalize( $pending->amount, 'USD' ) ) {
		$fail( 'pending payment record was not created for deposit amount' );
	}

	$changed_settings = $settings_repository->all();
	$changed_settings['payments']['collection_mode'] = 'full';
	$changed_settings['payments']['deposit_amount'] = 75;
	$settings_repository->save( $changed_settings );
	$unchanged_summary = $payment_manager->summary_for_appointment( $appointment_id );
	if ( 'deposit' !== $unchanged_summary['collection_mode'] || '50.00' !== $unchanged_summary['payment_due_amount'] ) {
		$fail( 'payment snapshot changed after global settings changed' );
	}

	$idempotency_key = 'phase9:' . $appointment_id . ':paid';
	$paid = $payment_manager->record_transaction(
		$appointment_id,
		array( 'provider' => 'bank_transfer', 'status' => 'paid', 'kind' => 'payment', 'amount' => '50.00', 'transaction_id' => 'BANK-' . $suffix, 'idempotency_key' => $idempotency_key, 'note' => 'Smoke paid' )
	);
	if ( is_wp_error( $paid ) || 'partially_paid' !== $appointment_repository->find( $appointment_id )->payment_status ) {
		$fail( 'partial payment aggregate failed' );
	}
	$count_before_duplicate = count( $payment_repository->for_appointment( $appointment_id ) );
	$duplicate = $payment_manager->record_transaction(
		$appointment_id,
		array( 'provider' => 'bank_transfer', 'status' => 'paid', 'kind' => 'payment', 'amount' => '50.00', 'transaction_id' => 'BANK-' . $suffix, 'idempotency_key' => $idempotency_key )
	);
	if ( is_wp_error( $duplicate ) || (int) $duplicate !== (int) $paid || $count_before_duplicate !== count( $payment_repository->for_appointment( $appointment_id ) ) ) {
		$fail( 'idempotent transaction created a duplicate ledger row' );
	}
	$conflict = $payment_manager->record_transaction(
		$appointment_id,
		array( 'provider' => 'bank_transfer', 'status' => 'paid', 'kind' => 'payment', 'amount' => '51.00', 'transaction_id' => 'BANK-' . $suffix, 'idempotency_key' => $idempotency_key )
	);
	if ( ! is_wp_error( $conflict ) || 'yo_booking_payment_idempotency_conflict' !== $conflict->get_error_code() ) {
		$fail( 'conflicting idempotent transaction was not rejected' );
	}

	$paid_record = $payment_repository->latest_for_appointment( $appointment_id );
	if ( ! $paid_record || 'paid' !== $paid_record->status || empty( $paid_record->paid_at ) ) {
		$fail( 'paid payment audit record was not stored' );
	}
	$aggregate = $appointment_repository->find( $appointment_id );
	if ( '50.00' !== YoBooking\Payments\Money::normalize( $aggregate->paid_amount, 'USD' ) || '150.00' !== YoBooking\Payments\Money::normalize( $aggregate->balance_amount, 'USD' ) ) {
		$fail( 'paid and remaining aggregates are incorrect' );
	}

	$over_refund = $payment_repository->mark_appointment( $appointment_id, 'refunded', '50.01', 'USD', 'Invalid refund' );
	if ( ! is_wp_error( $over_refund ) || 'yo_booking_refund_amount_invalid' !== $over_refund->get_error_code() ) {
		$fail( 'refund greater than net paid was not rejected' );
	}

	$refunded = $payment_repository->mark_appointment( $appointment_id, 'refunded', '50.00', 'USD', 'Smoke refunded' );
	if ( is_wp_error( $refunded ) || 'refunded' !== $appointment_repository->find( $appointment_id )->payment_status ) {
		$fail( 'mark refunded failed' );
	}

	$refunded_record = $payment_repository->latest_for_appointment( $appointment_id );
	if ( ! $refunded_record || 'refunded' !== $refunded_record->status || empty( $refunded_record->refunded_at ) ) {
		$fail( 'refunded payment audit record was not stored' );
	}
	$aggregate = $appointment_repository->find( $appointment_id );
	if ( '50.00' !== YoBooking\Payments\Money::normalize( $aggregate->refunded_amount, 'USD' ) || '200.00' !== YoBooking\Payments\Money::normalize( $aggregate->balance_amount, 'USD' ) ) {
		$fail( 'refund aggregates are incorrect' );
	}
} catch ( Throwable $exception ) {
	$error = $exception->getMessage();
} finally {
	global $wpdb;

	$settings_repository->save( $original_settings );

	if ( $appointment_id ) {
		$log_repository->delete_for_appointment( $appointment_id );
		$payment_repository->delete_for_appointment( $appointment_id );
		$wpdb->delete( YoBooking\Database\Migrator::table_name( 'appointments' ), array( 'id' => $appointment_id ), array( '%d' ) );
	}

	if ( $customer_id ) {
		$customer_repository->delete( $customer_id );
	}

	if ( $staff_id ) {
		$staff_service_repository->sync_for_staff( $staff_id, array() );
		$rule_repository->delete_for_owner( 'staff', $staff_id );
		$staff_repository->delete( $staff_id );
	}

	if ( $service_id ) {
		$service_repository->delete( $service_id );
	}
}

if ( $error ) {
	echo 'FAIL: ' . $error . "\n";
	exit( 1 );
}

echo "phase9_payment_layer_smoke=pass\n";
echo 'appointment_id=' . $appointment_id . "\n";
