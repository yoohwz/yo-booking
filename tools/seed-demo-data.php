<?php
/**
 * Seed a persistent operations demo dataset for Yo Booking.
 *
 * Run with: wp eval-file wp-content/plugins/yo-booking/tools/seed-demo-data.php
 *
 * @package YoBooking
 */

defined( 'ABSPATH' ) || exit;

use YoBooking\Database\Migrator;
use YoBooking\Repositories\AppointmentRepository;
use YoBooking\Repositories\AuditLogRepository;
use YoBooking\Repositories\AvailabilityExceptionRepository;
use YoBooking\Repositories\AvailabilityRuleRepository;
use YoBooking\Repositories\CustomerRepository;
use YoBooking\Repositories\NotificationLogRepository;
use YoBooking\Repositories\PaymentRepository;
use YoBooking\Repositories\ServiceCategoryRepository;
use YoBooking\Repositories\ServiceRepository;
use YoBooking\Repositories\StaffRepository;
use YoBooking\Repositories\StaffServiceRepository;
use YoBooking\Settings\Repository as SettingsRepository;

const YO_BOOKING_DEMO_OPTION = 'yo_booking_demo_dataset_v1';

if ( get_option( YO_BOOKING_DEMO_OPTION, false ) ) {
	echo "yo_booking_demo_seed=skipped\nreason=demo dataset already exists\n";
	exit( 0 );
}

global $wpdb;

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( empty( $admins ) ) {
	throw new RuntimeException( 'An administrator account is required to seed demo data.' );
}
wp_set_current_user( (int) $admins[0]->ID );

// Demo creation must not send customer email or queue outbound integrations.
foreach ( array( 'yo_booking_appointment_created', 'yo_booking_appointment_updated', 'yo_booking_appointment_status_changed', 'yo_booking_appointment_rescheduled', 'yo_booking_payment_status_changed', 'yo_booking_payment_transaction_recorded' ) as $hook ) {
	remove_all_actions( $hook );
}

$settings        = ( new SettingsRepository() )->all();
$timezone_name   = ! empty( $settings['company']['timezone'] ) ? $settings['company']['timezone'] : 'UTC';
$timezone        = new DateTimeZone( $timezone_name );
$currency        = ! empty( $settings['payments']['currency'] ) ? $settings['payments']['currency'] : 'USD';
$today           = new DateTimeImmutable( 'today', $timezone );
$appointments_db = Migrator::table_name( 'appointments' );
$ids             = array(
	'categories'        => array(),
	'services'          => array(),
	'staff'             => array(),
	'customers'         => array(),
	'appointments'      => array(),
	'payments'          => array(),
	'notification_logs' => array(),
	'exceptions'        => array(),
	'audit_logs'        => array(),
);

$guard = static function ( $result, $label ) {
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $label . ': ' . $result->get_error_message() );
	}
	if ( ! absint( $result ) ) {
		throw new RuntimeException( $label . ': missing record ID' );
	}
	return absint( $result );
};

$weekday_date = static function ( $offset ) use ( $today ) {
	$date      = $today->modify( ( $offset >= 0 ? '+' : '' ) . (int) $offset . ' days' );
	$direction = $offset < 0 ? '-1 day' : '+1 day';
	while ( in_array( (int) $date->format( 'N' ), array( 6, 7 ), true ) ) {
		$date = $date->modify( $direction );
	}
	return $date;
};

$category_repository  = new ServiceCategoryRepository();
$service_repository   = new ServiceRepository();
$staff_repository     = new StaffRepository();
$mapping_repository   = new StaffServiceRepository();
$rule_repository      = new AvailabilityRuleRepository();
$exception_repository = new AvailabilityExceptionRepository();
$customer_repository  = new CustomerRepository();
$appointment_repository = new AppointmentRepository();
$payment_repository   = new PaymentRepository();
$notification_logs    = new NotificationLogRepository();
$audit_logs           = new AuditLogRepository();

$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

try {
	foreach (
		array(
			array( 'Consultations', 'Discovery, assessment, and planning appointments.', 10 ),
			array( 'Treatment Programs', 'Extended professional service programs.', 20 ),
			array( 'Follow-up Services', 'Short recurring customer appointments.', 30 ),
		) as $category
	) {
		$ids['categories'][] = $guard( $category_repository->save( array( 'name' => $category[0], 'description' => $category[1], 'sort_order' => $category[2], 'status' => 'active' ) ), 'Create category' );
	}

	$service_specs = array(
		array( $ids['categories'][0], 'Initial Consultation', 'A focused assessment and personalized action plan.', 45, 35, '#2563eb' ),
		array( $ids['categories'][1], 'Advanced Wellness Session', 'A complete professional session with preparation and aftercare.', 60, 65, '#16a34a' ),
		array( $ids['categories'][1], 'Recovery Program', 'An extended restorative appointment for complex needs.', 90, 95, '#9333ea' ),
		array( $ids['categories'][2], 'Follow-up Session', 'A concise progress check and plan adjustment.', 30, 25, '#ea580c' ),
	);
	foreach ( $service_specs as $index => $service ) {
		$ids['services'][] = $guard(
			$service_repository->save(
				array(
					'category_id' => $service[0], 'name' => $service[1], 'description' => $service[2],
					'duration_minutes' => $service[3], 'price' => $service[4], 'currency' => $currency,
					'capacity' => 1, 'color' => $service[5], 'sort_order' => ( $index + 1 ) * 10, 'status' => 'active',
				)
			),
			'Create service'
		);
	}

	$staff_specs = array(
		array( 'Olivia Carter', 'olivia.carter@example.test', '+1 212 555 0101', 'US consultation lead and customer experience specialist.', '#2563eb', array( 0, 1, 3 ) ),
		array( 'James Bennett', 'james.bennett@example.test', '+44 20 7946 0102', 'UK senior specialist focused on long-term programs.', '#16a34a', array( 0, 1, 2 ) ),
		array( 'Sofia Mueller', 'sofia.mueller@example.test', '+49 30 9018 0103', 'EU recovery program and follow-up specialist.', '#9333ea', array( 1, 2, 3 ) ),
		array( 'Lucas Martin', 'lucas.martin@example.test', '+33 1 84 80 0104', 'France-based practitioner covering consultations and treatments.', '#ea580c', array( 0, 1, 2, 3 ) ),
	);
	foreach ( $staff_specs as $index => $staff ) {
		$staff_id = $guard( $staff_repository->save( array( 'name' => $staff[0], 'email' => $staff[1], 'phone' => $staff[2], 'bio' => $staff[3], 'color' => $staff[4], 'sort_order' => ( $index + 1 ) * 10, 'status' => 'active' ) ), 'Create staff' );
		$ids['staff'][] = $staff_id;
		$mapping_repository->sync_for_staff( $staff_id, array_map( static function ( $service_index ) use ( $ids ) { return $ids['services'][ $service_index ]; }, $staff[5] ) );
		$weekly = array();
		foreach ( range( 1, 5 ) as $weekday ) {
			$weekly[ $weekday ] = array( 'enabled' => 1, 'start_time' => '09:00', 'end_time' => '17:00', 'slot_interval_minutes' => 30 );
		}
		$result = $rule_repository->replace_weekly( 'staff', $staff_id, $weekly, $timezone_name );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( 'Create staff schedule: ' . $result->get_error_message() );
		}
	}

	$customer_specs = array(
		array( 'Emily Johnson', 'emily.johnson@example.test', '+1 212 555 0201', 'New York customer; prefers morning appointments.', 1, 'America/New_York' ),
		array( 'Michael Brown', 'michael.brown@example.test', '+1 312 555 0202', 'Chicago-based returning customer.', 0, 'America/Chicago' ),
		array( 'Sophie Dubois', 'sophie.dubois@example.test', '+33 1 84 80 0203', 'Paris customer; contact before appointment changes.', 1, 'Europe/Paris' ),
		array( 'Luca Rossi', 'luca.rossi@example.test', '+39 06 9480 0204', 'Rome customer who usually books follow-up sessions.', 0, 'Europe/Rome' ),
		array( 'Emma Fischer', 'emma.fischer@example.test', '+49 30 9018 0205', 'Berlin customer interested in recovery programs.', 1, 'Europe/Berlin' ),
		array( 'Oliver Smith', 'oliver.smith@example.test', '+44 20 7946 0206', 'London customer referred by a partner.', 0, 'Europe/London' ),
		array( 'Isabella Garcia', 'isabella.garcia@example.test', '+34 91 123 0207', 'Madrid customer who prefers email communication.', 1, 'Europe/Madrid' ),
		array( 'Noah Anderson', 'noah.anderson@example.test', '+1 415 555 0208', 'San Francisco customer with a flexible schedule.', 0, 'America/Los_Angeles' ),
		array( 'Anna Kowalski', 'anna.kowalski@example.test', '+48 22 307 0209', 'Warsaw customer who requires a detailed receipt.', 1, 'Europe/Warsaw' ),
		array( 'Thomas de Vries', 'thomas.devries@example.test', '+31 20 808 0210', 'Amsterdam customer booking an initial consultation.', 0, 'Europe/Amsterdam' ),
	);
	foreach ( $customer_specs as $customer ) {
		$ids['customers'][] = $guard( $customer_repository->save( array( 'name' => $customer[0], 'email' => $customer[1], 'phone' => $customer[2], 'timezone' => $customer[5], 'notes' => $customer[3], 'marketing_consent' => $customer[4] ) ), 'Create customer' );
	}

	$appointment_specs = array(
		array( -50, '09:00', 0, 0, 0, 'completed', 'paid' ), array( -42, '11:00', 1, 1, 1, 'completed', 'paid' ),
		array( -35, '14:00', 2, 2, 2, 'cancelled', 'refunded' ), array( -28, '09:00', 3, 3, 3, 'no_show', 'unpaid' ),
		array( -21, '10:30', 0, 0, 4, 'completed', 'paid' ), array( -18, '13:00', 1, 1, 0, 'completed', 'partially_paid' ),
		array( -14, '15:00', 2, 2, 5, 'rescheduled', 'unpaid' ), array( -10, '09:00', 3, 3, 6, 'completed', 'paid' ),
		array( -7, '11:00', 0, 0, 7, 'cancelled', 'unpaid' ), array( -3, '14:00', 1, 1, 8, 'completed', 'paid' ),
		array( 0, '09:00', 0, 0, 1, 'completed', 'paid' ), array( 0, '10:30', 3, 1, 2, 'no_show', 'unpaid' ),
		array( 0, '13:00', 1, 2, 3, 'completed', 'paid' ), array( 0, '15:00', 2, 3, 4, 'cancelled', 'refunded' ),
		array( 1, '09:00', 0, 0, 0, 'confirmed', 'paid' ), array( 1, '11:00', 1, 1, 5, 'pending', 'unpaid' ),
		array( 2, '09:00', 2, 2, 6, 'confirmed', 'partially_paid' ), array( 2, '14:00', 3, 3, 7, 'pending', 'unpaid' ),
		array( 3, '10:30', 1, 0, 8, 'confirmed', 'paid' ), array( 4, '13:00', 0, 1, 9, 'confirmed', 'unpaid' ),
		array( 7, '09:00', 3, 2, 1, 'pending', 'unpaid' ), array( 10, '14:00', 2, 3, 2, 'confirmed', 'paid' ),
		array( 14, '11:00', 1, 0, 3, 'confirmed', 'partially_paid' ), array( 21, '15:00', 0, 1, 4, 'pending', 'unpaid' ),
	);

	foreach ( $appointment_specs as $index => $spec ) {
		$date           = $weekday_date( $spec[0] );
		$target_status  = $spec[5];
		$initial_status = $spec[0] <= 0 && in_array( $target_status, AppointmentRepository::blocking_statuses(), true ) ? 'rescheduled' : $target_status;
		$customer       = $customer_specs[ $spec[4] ];
		$service        = $service_specs[ $spec[2] ];
		$appointment_id = $guard(
			$appointment_repository->save(
				array(
					'service_id' => $ids['services'][ $spec[2] ], 'staff_id' => $ids['staff'][ $spec[3] ], 'customer_id' => $ids['customers'][ $spec[4] ],
					'customer_name' => $customer[0], 'customer_email' => $customer[1], 'customer_phone' => $customer[2],
					'date' => $date->format( 'Y-m-d' ), 'start_time' => $spec[1], 'duration_minutes' => $service[3], 'timezone' => $timezone_name,
					'status' => $initial_status, 'payment_status' => $spec[6], 'total_amount' => $service[4], 'currency' => $currency,
					'source' => 0 === $index % 3 ? 'frontend' : 'admin', 'customer_note' => 0 === $index % 4 ? 'Demo customer note for operational review.' : '',
					'internal_note' => 0 === $index % 5 ? 'Demo internal note: verify follow-up during service.' : '',
					'cancellation_reason' => 'cancelled' === $target_status ? 'Customer schedule changed.' : '',
				)
			),
			'Create appointment ' . ( $index + 1 )
		);
		$ids['appointments'][] = $appointment_id;

		$created_local = $date->setTime( (int) substr( $spec[1], 0, 2 ), (int) substr( $spec[1], 3, 2 ) )->modify( '-' . ( 2 + ( $index % 8 ) ) . ' days' );
		$wpdb->update(
			$appointments_db,
			array( 'status' => $target_status, 'created_at' => $created_local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $appointment_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		$payment_status = 'unpaid' === $spec[6] ? 'pending' : $spec[6];
		$total_minor    = YoBooking\Payments\Money::to_minor( $service[4], $currency );
		$amount         = 'partially_paid' === $payment_status ? YoBooking\Payments\Money::from_minor( YoBooking\Payments\Money::percentage( $total_minor, 50 ), $currency ) : YoBooking\Payments\Money::from_minor( $total_minor, $currency );
		$transaction_id = 'DEMO-' . str_pad( (string) ( $index + 1 ), 4, '0', STR_PAD_LEFT );

		if ( 'refunded' === $payment_status ) {
			$ids['payments'][] = $guard( $payment_repository->create( array( 'appointment_id' => $appointment_id, 'provider' => 'manual', 'kind' => 'payment', 'transaction_id' => $transaction_id . '-PAY', 'idempotency_key' => 'demo:' . $appointment_id . ':payment', 'amount' => $amount, 'currency' => $currency, 'status' => 'paid', 'method_title' => 'Demo manual payment', 'note' => 'Generated for Yo Booking operations review.' ) ), 'Create demo payment record' );
			$ids['payments'][] = $guard( $payment_repository->create( array( 'appointment_id' => $appointment_id, 'provider' => 'manual', 'kind' => 'refund', 'transaction_id' => $transaction_id . '-REFUND', 'idempotency_key' => 'demo:' . $appointment_id . ':refund', 'amount' => $amount, 'currency' => $currency, 'status' => 'refunded', 'method_title' => 'Demo manual refund', 'note' => 'Generated for Yo Booking operations review.' ) ), 'Create demo refund record' );
		} else {
			$ids['payments'][] = $guard( $payment_repository->create( array( 'appointment_id' => $appointment_id, 'provider' => 'manual', 'kind' => 'payment', 'transaction_id' => $transaction_id, 'idempotency_key' => 'demo:' . $appointment_id . ':' . $payment_status, 'amount' => $amount, 'currency' => $currency, 'status' => $payment_status, 'method_title' => 'Demo manual payment', 'note' => 'Generated for Yo Booking operations review.' ) ), 'Create payment record' );
		}
	}

	$exception_specs = array(
		array( 9, 0, '12:00', '13:00', 'blocked', 'Team meeting' ),
		array( 17, 2, '', '', 'blocked', 'Staff leave' ),
		array( 11, 3, '16:00', '18:00', 'available', 'Extended demo hours' ),
	);
	foreach ( $exception_specs as $exception ) {
		$ids['exceptions'][] = $guard( $exception_repository->save( array( 'owner_type' => 'staff', 'owner_id' => $ids['staff'][ $exception[1] ], 'exception_date' => $weekday_date( $exception[0] )->format( 'Y-m-d' ), 'start_time' => $exception[2], 'end_time' => $exception[3], 'availability_type' => $exception[4], 'reason' => $exception[5], 'timezone' => $timezone_name ) ), 'Create availability exception' );
	}

	foreach ( array_slice( $ids['appointments'], 0, 10 ) as $index => $appointment_id ) {
		$status = 7 === $index ? 'failed' : ( 8 === $index ? 'skipped' : 'sent' );
		$ids['notification_logs'][] = $guard( $notification_logs->create( array( 'notification_key' => 7 === $index ? 'customer_reminder_24h' : 'customer_booking_created', 'event' => 7 === $index ? 'appointment.reminder' : 'appointment.created', 'appointment_id' => $appointment_id, 'recipient_type' => 'customer', 'recipient_email' => $customer_specs[ $index % count( $customer_specs ) ][1], 'subject' => 'Demo booking notification #' . $appointment_id, 'status' => $status, 'error_message' => 'failed' === $status ? 'Demo transport timeout for log review.' : '' ) ), 'Create notification log' );
	}

	foreach ( $ids['appointments'] as $index => $appointment_id ) {
		$audit_id = $audit_logs->record( 'demo.appointment_seeded', 'appointment', $appointment_id, sprintf( 'Demo appointment #%d created', $appointment_id ), array( 'dataset' => 'operations-v1', 'sequence' => $index + 1 ) );
		if ( $audit_id ) {
			$ids['audit_logs'][] = (int) $audit_id;
		}
	}
	$dataset_audit = $audit_logs->record( 'demo.dataset_created', 'maintenance', 0, 'Yo Booking operations demo dataset created', array( 'appointments' => count( $ids['appointments'] ) ) );
	if ( $dataset_audit ) {
		$ids['audit_logs'][] = (int) $dataset_audit;
	}

	$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
} catch ( Throwable $exception ) {
	$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	echo 'FAIL: ' . $exception->getMessage() . "\n";
	exit( 1 );
}

update_option(
	YO_BOOKING_DEMO_OPTION,
	array( 'version' => 2, 'profile' => 'global-us-eu', 'created_at' => current_time( 'mysql', true ), 'timezone' => $timezone_name, 'currency' => $currency, 'ids' => $ids ),
	false
);

echo "yo_booking_demo_seed=pass\n";
echo 'categories=' . count( $ids['categories'] ) . "\n";
echo 'services=' . count( $ids['services'] ) . "\n";
echo 'staff=' . count( $ids['staff'] ) . "\n";
echo 'customers=' . count( $ids['customers'] ) . "\n";
echo 'appointments=' . count( $ids['appointments'] ) . "\n";
echo 'payments=' . count( $ids['payments'] ) . "\n";
echo 'notification_logs=' . count( $ids['notification_logs'] ) . "\n";
echo 'availability_exceptions=' . count( $ids['exceptions'] ) . "\n";
