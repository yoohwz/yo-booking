<?php
/**
 * Phase 6 notification smoke test for WP-CLI.
 *
 * Run with:
 * wp eval-file wp-content/plugins/yo-booking/tests/phase6-notifications-smoke.php
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
$template_repository      = new YoBooking\Repositories\NotificationTemplateRepository();
$payment_repository       = new YoBooking\Repositories\PaymentRepository();
$settings                 = $settings_repository->all();
$original_settings        = $settings;
$timezone_name            = ! empty( $settings['company']['timezone'] ) ? $settings['company']['timezone'] : 'UTC';
$timezone                 = new DateTimeZone( $timezone_name );
$suffix                   = gmdate( 'YmdHis' );
$service_id               = 0;
$staff_id                 = 0;
$appointment_id           = 0;
$customer_id              = 0;
$template_snapshots       = array();
$mails                    = array();
$ics_seen                 = false;
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

$sent_log_count = static function ( $appointment_id, $key ) {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM ' . YoBooking\Database\Migrator::table_name( 'notification_logs' ) . ' WHERE appointment_id = %d AND notification_key = %s AND status = %s',
			absint( $appointment_id ),
			sanitize_key( $key ),
			'sent'
		)
	);
};

$snapshot_template = static function ( $key ) use ( &$template_snapshots, $template_repository ) {
	$template = $template_repository->find_by_key( $key );

	if ( $template ) {
		$template_snapshots[ $key ] = array(
			'enabled'               => (int) $template->enabled,
			'send_ics'              => (int) $template->send_ics,
			'timing_offset_minutes' => (int) $template->timing_offset_minutes,
		);
	}

	return $template;
};

try {
	$settings['notifications']['enabled']    = true;
	$settings['notifications']['from_name']  = 'Yo Booking Smoke';
	$settings['notifications']['from_email'] = 'booking-smoke@example.test';
	$settings['notifications']['admin_to']   = 'booking-admin@example.test';
	$settings_repository->save( $settings );

	foreach (
		array(
			'admin_new_appointment',
			'staff_new_appointment',
			'customer_booking_received',
			'customer_booking_confirmed',
			'customer_booking_cancelled',
			'customer_booking_rescheduled',
			'customer_booking_completed',
			'customer_booking_reminder',
		) as $key
	) {
		$template = $snapshot_template( $key );

		if ( ! $template ) {
			$fail( 'missing template ' . $key );
		}
	}

	global $wpdb;
	$wpdb->update(
		YoBooking\Database\Migrator::table_name( 'notifications' ),
		array(
			'enabled'               => 1,
			'send_ics'              => 1,
			'timing_offset_minutes' => 1440,
		),
		array( 'notification_key' => 'customer_booking_reminder' ),
		array( '%d', '%d', '%d' ),
		array( '%s' )
	);
	$notifications_table = YoBooking\Database\Migrator::table_name( 'notifications' );
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$notifications_table} SET enabled = %d WHERE notification_key <> %s",
			1,
			'customer_booking_reminder'
		)
	);

	add_filter(
		'pre_wp_mail',
		static function ( $return, $atts ) use ( &$mails, &$ics_seen ) {
			$mails[] = $atts;

			if ( ! empty( $atts['attachments'] ) ) {
				foreach ( (array) $atts['attachments'] as $attachment ) {
					if ( is_readable( $attachment ) && false !== strpos( file_get_contents( $attachment ), 'BEGIN:VCALENDAR' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
						$ics_seen = true;
					}
				}
			}

			return true;
		},
		10,
		2
	);

	$date = $next_weekday();

	$service_id = $guard(
		$service_repository->save(
			array(
				'name'             => 'Notification Smoke Service ' . $suffix,
				'duration_minutes' => 60,
				'price'            => '100.00',
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
				'name'   => 'Notification Smoke Staff ' . $suffix,
				'email'  => 'notification-staff-' . $suffix . '@example.test',
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
				'start_time'            => '13:00',
				'end_time'              => '17:00',
				'slot_interval_minutes' => 60,
			),
		),
		$timezone_name
	);

	$appointment_id = $guard(
		$appointment_repository->save(
			array(
				'service_id'       => $service_id,
				'staff_id'         => $staff_id,
				'customer_name'    => 'Notification Smoke Customer ' . $suffix,
				'customer_email'   => 'notification-customer-' . $suffix . '@example.test',
				'customer_phone'   => '+15550000006',
				'date'             => $date->format( 'Y-m-d' ),
				'start_time'       => '13:00',
				'duration_minutes' => 60,
				'timezone'         => $timezone_name,
				'status'           => 'pending',
				'source'           => 'smoke',
			)
		),
		'create appointment'
	);
	$appointment = $appointment_repository->find( $appointment_id );
	$customer_id = (int) $appointment->customer_id;

	if ( $sent_log_count( $appointment_id, 'admin_new_appointment' ) < 1 || $sent_log_count( $appointment_id, 'customer_booking_received' ) < 1 ) {
		$fail( 'created notification logs were not recorded' );
	}

	$result = $appointment_repository->update_status( $appointment_id, 'confirmed' );
	if ( is_wp_error( $result ) || $sent_log_count( $appointment_id, 'customer_booking_confirmed' ) < 1 ) {
		$fail( 'confirmed notification did not send' );
	}

	$appointment_repository->save(
		array(
			'id'               => $appointment_id,
			'service_id'       => $service_id,
			'staff_id'         => $staff_id,
			'customer_id'      => $customer_id,
			'customer_name'    => 'Notification Smoke Customer ' . $suffix,
			'customer_email'   => 'notification-customer-' . $suffix . '@example.test',
			'customer_phone'   => '+15550000006',
			'date'             => $date->format( 'Y-m-d' ),
			'start_time'       => '14:00',
			'duration_minutes' => 60,
			'timezone'         => $timezone_name,
			'status'           => 'confirmed',
		)
	);

	if ( $sent_log_count( $appointment_id, 'customer_booking_rescheduled' ) < 1 ) {
		$fail( 'rescheduled notification did not send' );
	}

	$appointment = $appointment_repository->find( $appointment_id );
	$reference_time = strtotime( $appointment->start_at . ' UTC' ) - DAY_IN_SECONDS;
	$reminder_sent = ( new YoBooking\Notifications\NotificationService() )->send_due_reminders( $reference_time );

	if ( $reminder_sent < 1 || $sent_log_count( $appointment_id, 'customer_booking_reminder' ) < 1 ) {
		$fail( 'reminder notification did not send' );
	}

	$second_reminder_sent = ( new YoBooking\Notifications\NotificationService() )->send_due_reminders( $reference_time );
	if ( $second_reminder_sent > 0 || $sent_log_count( $appointment_id, 'customer_booking_reminder' ) !== 1 ) {
		$fail( 'reminder dedupe did not work' );
	}

	$appointment_repository->update_status( $appointment_id, 'completed' );
	if ( $sent_log_count( $appointment_id, 'customer_booking_completed' ) < 1 ) {
		$fail( 'completed notification did not send' );
	}

	$appointment_repository->update_status( $appointment_id, 'cancelled', 'Smoke cancellation', true );
	if ( $sent_log_count( $appointment_id, 'customer_booking_cancelled' ) < 1 ) {
		$fail( 'cancelled notification did not send' );
	}

	if ( count( $mails ) < 7 ) {
		$fail( 'wp_mail interception did not capture expected emails' );
	}

	if ( ! $ics_seen ) {
		$fail( 'ICS attachment was not generated' );
	}
} catch ( Throwable $exception ) {
	$error = $exception->getMessage();
} finally {
	global $wpdb;

	$settings_repository->save( $original_settings );

	foreach ( $template_snapshots as $key => $snapshot ) {
		$wpdb->update(
			YoBooking\Database\Migrator::table_name( 'notifications' ),
			$snapshot,
			array( 'notification_key' => $key ),
			array( '%d', '%d', '%d' ),
			array( '%s' )
		);
	}

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

echo "phase6_notifications_smoke=pass\n";
echo 'mail_count=' . count( $mails ) . "\n";
echo 'ics_seen=' . ( $ics_seen ? 'yes' : 'no' ) . "\n";
