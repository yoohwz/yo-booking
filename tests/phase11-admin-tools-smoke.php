<?php
/**
 * Phase 11 customer, notification, and multi-range availability smoke test.
 *
 * @package YoBooking
 */

defined( 'ABSPATH' ) || exit;
defined( 'YO_BOOKING_RUNNING_TESTS' ) || define( 'YO_BOOKING_RUNNING_TESTS', true );

$service_repository       = new YoBooking\Repositories\ServiceRepository();
$staff_repository         = new YoBooking\Repositories\StaffRepository();
$staff_service_repository = new YoBooking\Repositories\StaffServiceRepository();
$rule_repository          = new YoBooking\Repositories\AvailabilityRuleRepository();
$appointment_repository   = new YoBooking\Repositories\AppointmentRepository();
$customer_repository      = new YoBooking\Repositories\CustomerRepository();
$log_repository           = new YoBooking\Repositories\NotificationLogRepository();
$payment_repository       = new YoBooking\Repositories\PaymentRepository();
$template_repository      = new YoBooking\Repositories\NotificationTemplateRepository();
$notification_service     = new YoBooking\Notifications\NotificationService();
$settings                 = ( new YoBooking\Settings\Repository() )->all();
$timezone_name            = ! empty( $settings['company']['timezone'] ) ? $settings['company']['timezone'] : 'UTC';
$timezone                 = new DateTimeZone( $timezone_name );
$suffix                   = gmdate( 'YmdHis' );
$service_id               = 0;
$staff_id                 = 0;
$appointment_id           = 0;
$customer_id              = 0;
$extra_log_ids            = array();
$mails                    = array();
$error                    = '';

$fail = static function ( $message ) { throw new RuntimeException( $message ); };
$guard = static function ( $result, $label ) use ( $fail ) {
	if ( is_wp_error( $result ) ) $fail( $label . ': ' . $result->get_error_message() );
	if ( ! absint( $result ) ) $fail( $label . ': missing ID' );
	return absint( $result );
};

try {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	if ( empty( $admins ) ) $fail( 'administrator user is required' );
	wp_set_current_user( (int) $admins[0]->ID );

	$date = new DateTimeImmutable( 'tomorrow', $timezone );
	for ( $i = 0; $i < 30 && ! in_array( (int) $date->format( 'w' ), array( 1, 2, 3, 4, 5 ), true ); $i++ ) {
		$date = $date->add( new DateInterval( 'P1D' ) );
	}
	$weekday = (int) $date->format( 'w' );

	$service_id = $guard( $service_repository->save( array( 'name' => 'Phase 11 Service ' . $suffix, 'duration_minutes' => 60, 'price' => '200.00', 'currency' => 'USD', 'status' => 'active' ) ), 'create service' );
	$staff_id   = $guard( $staff_repository->save( array( 'name' => 'Phase 11 Staff ' . $suffix, 'email' => 'phase11-staff-' . $suffix . '@example.test', 'status' => 'active' ) ), 'create staff' );
	$staff_service_repository->sync_for_staff( $staff_id, array( $service_id ) );

	$ranges = array(
		$weekday => array(
			'enabled' => 1,
			'slot_interval_minutes' => 60,
			'ranges' => array(
				array( 'start_time' => '09:00', 'end_time' => '12:00' ),
				array( 'start_time' => '14:00', 'end_time' => '17:00' ),
			),
		),
	);
	$result = $rule_repository->replace_weekly( 'staff', $staff_id, $ranges, $timezone_name );
	if ( is_wp_error( $result ) || 2 !== count( $rule_repository->for_owner_on_weekday( 'staff', $staff_id, $weekday, $date->format( 'Y-m-d' ) ) ) ) {
		$fail( 'multiple availability ranges were not stored' );
	}

	$overlap = $rule_repository->replace_weekly(
		'staff',
		$staff_id,
		array( $weekday => array( 'enabled' => 1, 'ranges' => array( array( 'start_time' => '09:00', 'end_time' => '12:00' ), array( 'start_time' => '11:00', 'end_time' => '13:00' ) ) ) ),
		$timezone_name
	);
	if ( ! is_wp_error( $overlap ) || 'yo_booking_availability_overlap' !== $overlap->get_error_code() ) {
		$fail( 'overlapping ranges were not rejected' );
	}
	if ( 2 !== count( $rule_repository->for_owner_on_weekday( 'staff', $staff_id, $weekday, $date->format( 'Y-m-d' ) ) ) ) {
		$fail( 'invalid range update removed the previous schedule' );
	}

	$appointment_id = $guard(
		$appointment_repository->save(
			array(
				'service_id' => $service_id,
				'staff_id' => $staff_id,
				'customer_name' => 'Phase 11 Customer ' . $suffix,
				'customer_email' => 'phase11-customer-' . $suffix . '@example.test',
				'customer_phone' => '+15550110101',
				'date' => $date->format( 'Y-m-d' ),
				'start_time' => '14:00',
				'duration_minutes' => 60,
				'timezone' => $timezone_name,
				'status' => 'confirmed',
				'payment_status' => 'paid',
				'total_amount' => '200.00',
				'currency' => 'USD',
			)
		),
		'create afternoon appointment'
	);
	$appointment = $appointment_repository->find( $appointment_id );
	$customer_id = (int) $appointment->customer_id;
	global $wpdb;
	$wpdb->update(
		YoBooking\Database\Migrator::table_name( 'appointments' ),
		array( 'paid_amount' => '200.00', 'balance_amount' => '0.00', 'payment_status' => 'paid' ),
		array( 'id' => $appointment_id ),
		array( '%s', '%s', '%s' ),
		array( '%d' )
	);

	$stats = $appointment_repository->customer_stats( $customer_id );
	if ( 1 !== (int) $stats->booking_count || 1 !== (int) $stats->upcoming_count || 200.0 !== (float) $stats->paid_total ) {
		$fail( 'customer metrics are incorrect' );
	}
	if ( 1 !== count( $appointment_repository->for_customer_id( $customer_id ) ) ) {
		$fail( 'customer booking history is missing' );
	}

	$original_get = $_GET;
	$_GET = array( 'profile' => $customer_id );
	ob_start();
	( new YoBooking\Admin\CustomersPage() )->render();
	$profile_html = ob_get_clean();
	$_GET = $original_get;
	if ( false === strpos( $profile_html, 'Booking history' ) || false === strpos( $profile_html, 'Paid total' ) || false === strpos( $profile_html, 'Phase 11 Service' ) ) {
		$fail( 'customer profile markup is incomplete' );
	}

	$template = $template_repository->find_by_key( 'customer_booking_received' );
	if ( ! $template ) $fail( 'notification template is missing' );
	$preview = $notification_service->preview( (int) $template->id, $appointment_id );
	if ( is_wp_error( $preview ) || false === strpos( $preview['message'], 'Phase 11 Customer' ) ) {
		$fail( 'notification preview did not render appointment placeholders' );
	}

	add_filter( 'pre_wp_mail', static function ( $return, $atts ) use ( &$mails ) { $mails[] = $atts; return true; }, 10, 2 );
	$sent = $notification_service->send_test( (int) $template->id, $appointment_id, 'phase11-test@example.test' );
	if ( is_wp_error( $sent ) || true !== $sent || empty( $mails ) || 'phase11-test@example.test' !== $mails[0]['to'][0] ) {
		$fail( 'test notification was not sent to the override recipient' );
	}

	$failed_log_id = $guard(
		$log_repository->create(
			array(
				'notification_key' => $template->notification_key,
				'event' => $template->event,
				'appointment_id' => $appointment_id,
				'recipient_type' => 'customer',
				'recipient_email' => 'phase11-retry@example.test',
				'subject' => 'Retry smoke',
				'status' => 'failed',
				'error_message' => 'Simulated failure',
			)
		),
		'create failed log'
	);
	$extra_log_ids[] = $failed_log_id;
	$retried = $notification_service->retry_log( $failed_log_id );
	if ( is_wp_error( $retried ) || true !== $retried || count( $mails ) < 2 ) {
		$fail( 'failed notification retry did not send' );
	}
} catch ( Throwable $exception ) {
	$error = $exception->getMessage();
} finally {
	global $wpdb;
	remove_all_filters( 'pre_wp_mail' );
	if ( $appointment_id ) {
		$log_repository->delete_for_appointment( $appointment_id );
		$payment_repository->delete_for_appointment( $appointment_id );
		$wpdb->delete( YoBooking\Database\Migrator::table_name( 'appointments' ), array( 'id' => $appointment_id ), array( '%d' ) );
	}
	if ( $customer_id ) $customer_repository->delete( $customer_id );
	if ( $staff_id ) {
		$rule_repository->delete_for_owner( 'staff', $staff_id );
		$staff_repository->delete( $staff_id );
	}
	if ( $service_id ) $service_repository->delete( $service_id );
}

if ( $error ) {
	echo 'FAIL: ' . $error . "\n";
	exit( 1 );
}

echo "phase11_admin_tools_smoke=pass\n";
echo 'mail_count=' . count( $mails ) . "\n";
