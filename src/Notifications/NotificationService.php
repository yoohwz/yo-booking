<?php
/**
 * Notification dispatcher.
 *
 * @package YoBooking
 */

namespace YoBooking\Notifications;

use YoBooking\Repositories\AppointmentRepository;
use YoBooking\Payments\Money;
use YoBooking\Repositories\NotificationLogRepository;
use YoBooking\Repositories\NotificationTemplateRepository;
use YoBooking\Settings\Repository as SettingsRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Sends appointment emails and scheduled reminders.
 */
final class NotificationService {
	/**
	 * Reminder cron hook.
	 *
	 * @var string
	 */
	const REMINDER_HOOK = 'yo_booking_process_reminders';

	/** @var string */
	const REMINDER_LOCK = 'yo_booking_reminder_worker_lock';

	/** @var string */
	const ASYNC_HOOK = 'yo_booking_send_notification_event';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'yo_booking_appointment_created', array( $this, 'send_created' ), 10, 2 );
		add_action( 'yo_booking_appointment_status_changed', array( $this, 'send_status_changed' ), 10, 4 );
		add_action( 'yo_booking_appointment_rescheduled', array( $this, 'send_rescheduled' ), 10, 3 );
		add_action( 'yo_booking_payment_transaction_recorded', array( $this, 'send_payment_transaction' ), 10, 2 );
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
		add_action( 'init', array( $this, 'ensure_reminder_schedule' ) );
		add_action( self::REMINDER_HOOK, array( $this, 'send_due_reminders' ) );
		add_action( self::ASYNC_HOOK, array( $this, 'send_event' ), 10, 3 );
	}

	/**
	 * Send booking-created notifications.
	 *
	 * @param int         $appointment_id Appointment ID.
	 * @param object|null $previous Previous appointment.
	 * @return int
	 */
	public function send_created( $appointment_id, $previous = null ) {
		return $this->dispatch_event( 'appointment.created', $appointment_id );
	}

	/**
	 * Send status-based notifications.
	 *
	 * @param int         $appointment_id Appointment ID.
	 * @param string      $new_status New status.
	 * @param string      $old_status Old status.
	 * @param object|null $previous Previous appointment.
	 * @return int
	 */
	public function send_status_changed( $appointment_id, $new_status, $old_status = '', $previous = null ) {
		$events = array(
			'confirmed'   => 'appointment.confirmed',
			'cancelled'   => 'appointment.cancelled',
			'completed'   => 'appointment.completed',
			'rescheduled' => 'appointment.rescheduled',
		);

		if ( empty( $events[ $new_status ] ) ) {
			return 0;
		}

		return $this->dispatch_event( $events[ $new_status ], $appointment_id );
	}

	/**
	 * Send reschedule notifications after a date/time edit.
	 *
	 * @param int         $appointment_id Appointment ID.
	 * @param object|null $previous Previous appointment.
	 * @param object|null $current Current appointment.
	 * @return int
	 */
	public function send_rescheduled( $appointment_id, $previous = null, $current = null ) {
		return $this->dispatch_event( 'appointment.rescheduled', $appointment_id );
	}

	/**
	 * Send customer payment notifications for every finalized ledger transaction.
	 *
	 * @param int   $payment_id Payment ID.
	 * @param array $record Inserted payment record.
	 * @return int
	 */
	public function send_payment_transaction( $payment_id, $record ) {
		if ( defined( 'YO_BOOKING_RUNNING_TESTS' ) && YO_BOOKING_RUNNING_TESTS ) {
			return 0;
		}
		$appointment_id = isset( $record['appointment_id'] ) ? absint( $record['appointment_id'] ) : 0;
		$status         = isset( $record['status'] ) ? sanitize_key( $record['status'] ) : 'pending';
		$kind           = isset( $record['kind'] ) ? sanitize_key( $record['kind'] ) : 'payment';
		$event          = '';

		if ( 'refund' === $kind && 'refunded' === $status ) {
			$event = 'payment.refunded';
		} elseif ( 'payment' === $kind && in_array( $status, array( 'paid', 'partially_paid' ), true ) ) {
			$event = 'payment.received';
		} elseif ( 'payment' === $kind && 'failed' === $status ) {
			$event = 'payment.failed';
		}

		return $event && $appointment_id ? $this->dispatch_event( $event, $appointment_id ) : 0;
	}

	/** Queue transactional email outside the booking request. */
	private function dispatch_event( $event, $appointment_id ) {
		if ( defined( 'YO_BOOKING_RUNNING_TESTS' ) && YO_BOOKING_RUNNING_TESTS ) {
			return $this->send_event( $event, $appointment_id, true );
		}
		$args = array( sanitize_key( $event ), absint( $appointment_id ), true );
		if ( ! wp_next_scheduled( self::ASYNC_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 1, self::ASYNC_HOOK, $args );
		}
		return 1;
	}

	/**
	 * Add a 15-minute cron interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['yo_booking_15_minutes'] ) ) {
			$schedules['yo_booking_15_minutes'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes', 'yo-booking' ),
			);
		}

		return $schedules;
	}

	/**
	 * Ensure reminder processing is scheduled.
	 *
	 * @return void
	 */
	public function ensure_reminder_schedule() {
		if ( ! wp_next_scheduled( self::REMINDER_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'yo_booking_15_minutes', self::REMINDER_HOOK );
		}
	}

	/**
	 * Send reminders for appointments entering each template window.
	 *
	 * @param int|null $reference_time Unix timestamp.
	 * @return int
	 */
	public function send_due_reminders( $reference_time = null ) {
		global $wpdb;
		$lock_name = 'yo_booking:reminders';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 0 ) ) ) {
			return 0;
		}

		try {
			return $this->process_due_reminders( $reference_time );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Process reminder templates while holding the worker lock.
	 *
	 * @param int|null $reference_time Unix timestamp.
	 * @return int
	 */
	private function process_due_reminders( $reference_time = null ) {
		$repository = new NotificationTemplateRepository();
		$templates  = array_merge(
			$repository->enabled_for_event( 'appointment.reminder' ),
			$repository->enabled_for_event( 'payment.balance_reminder' )
		);
		$sent      = 0;

		if ( empty( $templates ) ) {
			return 0;
		}

		$reference_time = $reference_time ? absint( $reference_time ) : time();
		$window_seconds = (int) apply_filters( 'yo_booking_reminder_window_seconds', 20 * MINUTE_IN_SECONDS );
		$appointments   = new AppointmentRepository();

		foreach ( $templates as $template ) {
			$offset_seconds = max( 0, absint( $template->timing_offset_minutes ) ) * MINUTE_IN_SECONDS;
			$from           = gmdate( 'Y-m-d H:i:s', $reference_time + $offset_seconds );
			$to             = gmdate( 'Y-m-d H:i:s', $reference_time + $offset_seconds + $window_seconds );

			$offset = 0;
			do {
				$batch = $appointments->for_reminder_window( $from, $to, 500, $offset );
				foreach ( $batch as $appointment ) {
					if ( 'payment.balance_reminder' === $template->event && ( ! isset( $appointment->balance_amount ) || Money::to_minor( $appointment->balance_amount, $appointment->currency ) <= 0 ) ) {
						continue;
					}
					$sent += $this->send_template( $template, $appointment, true ) ? 1 : 0;
				}
				$offset += count( $batch );
			} while ( 500 === count( $batch ) );
		}

		return $sent;
	}

	/**
	 * Send all enabled templates for an event.
	 *
	 * @param string $event Event key.
	 * @param int    $appointment_id Appointment ID.
	 * @param bool   $dedupe Whether to avoid previously sent templates.
	 * @return int
	 */
	public function send_event( $event, $appointment_id, $dedupe = false ) {
		$appointment = ( new AppointmentRepository() )->find_with_details( $appointment_id );

		if ( ! $appointment ) {
			return 0;
		}

		$sent = 0;

		foreach ( ( new NotificationTemplateRepository() )->enabled_for_event( $event ) as $template ) {
			$sent += $this->send_template( $template, $appointment, $dedupe ) ? 1 : 0;
		}

		return $sent;
	}

	/**
	 * Preview one saved template with a real or sample appointment.
	 *
	 * @param int $template_id Template ID.
	 * @param int $appointment_id Optional appointment ID.
	 * @return array|WP_Error
	 */
	public function preview( $template_id, $appointment_id = 0 ) {
		$template = ( new NotificationTemplateRepository() )->find( absint( $template_id ) );
		if ( ! $template ) {
			return new WP_Error( 'yo_booking_notification_not_found', __( 'Notification template not found.', 'yo-booking' ) );
		}

		$appointment = $appointment_id ? ( new AppointmentRepository() )->find_with_details( absint( $appointment_id ) ) : $this->sample_appointment();
		if ( ! $appointment ) {
			return new WP_Error( 'yo_booking_appointment_not_found', __( 'Appointment not found.', 'yo-booking' ) );
		}

		$renderer = new TemplateRenderer();
		$rendered = $renderer->render( $template, $appointment );

		return array(
			'template'    => $template,
			'appointment' => $appointment,
			'subject'     => $rendered['subject'],
			'message'     => $renderer->message( $rendered, $template->email_type ),
		);
	}

	/**
	 * Send one template to a specific test recipient.
	 *
	 * @param int    $template_id Template ID.
	 * @param int    $appointment_id Optional appointment ID.
	 * @param string $recipient Recipient email.
	 * @return bool|WP_Error
	 */
	public function send_test( $template_id, $appointment_id, $recipient ) {
		$preview = $this->preview( $template_id, $appointment_id );
		$email   = sanitize_email( $recipient );

		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		if ( ! $email || ! is_email( $email ) ) {
			return new WP_Error( 'yo_booking_test_recipient_invalid', __( 'Enter a valid test email recipient.', 'yo-booking' ) );
		}

		return $this->send_template( $preview['template'], $preview['appointment'], false, array( $email ) );
	}

	/**
	 * Retry a failed delivery using its original template, appointment, and recipients.
	 *
	 * @param int $log_id Notification log ID.
	 * @return bool|WP_Error
	 */
	public function retry_log( $log_id ) {
		$logs = new NotificationLogRepository();
		$log  = $logs->find( absint( $log_id ) );

		if ( ! $log || 'failed' !== $log->status ) {
			return new WP_Error( 'yo_booking_notification_retry_invalid', __( 'Only failed notification logs can be retried.', 'yo-booking' ) );
		}

		$template    = ( new NotificationTemplateRepository() )->find_by_key( $log->notification_key );
		$appointment = ( new AppointmentRepository() )->find_with_details( (int) $log->appointment_id );
		$recipients  = $this->emails( $log->recipient_email );

		if ( ! $template || ! $appointment || empty( $recipients ) ) {
			return new WP_Error( 'yo_booking_notification_retry_missing_data', __( 'The template, appointment, or recipient is no longer available.', 'yo-booking' ) );
		}

		return $this->send_template( $template, $appointment, false, $recipients );
	}

	/**
	 * Send a single rendered template.
	 *
	 * @param object $template Template row.
	 * @param object $appointment Appointment row with details.
	 * @param bool   $dedupe Whether to avoid previously sent templates.
	 * @param array  $override_recipients Optional explicit recipients.
	 * @return bool
	 */
	private function send_template( $template, $appointment, $dedupe, array $override_recipients = array() ) {
		$logs = new NotificationLogRepository();

		$recipients = $override_recipients ? $override_recipients : $this->recipients( $template, $appointment );
		$renderer   = new TemplateRenderer();
		$rendered   = $renderer->render( $template, $appointment );
		$recipient_log = implode( ', ', $recipients );

		if ( empty( $recipients ) ) {
			$logs->create(
				array(
					'notification_key' => $template->notification_key,
					'event'            => $template->event,
					'appointment_id'   => (int) $appointment->id,
					'recipient_type'   => $template->recipient_type,
					'recipient_email'  => '',
					'subject'          => $rendered['subject'],
					'status'           => 'skipped',
					'error_message'    => __( 'Missing recipient email.', 'yo-booking' ),
				)
			);
			return false;
		}

		$log_id = $logs->create(
			array(
				'notification_key' => $template->notification_key,
				'event'            => $template->event,
				'appointment_id'   => (int) $appointment->id,
				'recipient_type'   => $template->recipient_type,
				'recipient_email'  => $recipient_log,
				'subject'          => $rendered['subject'],
					'status'           => 'pending',
					'occurrence_key'   => $dedupe ? hash( 'sha256', (int) $appointment->id . '|' . $template->notification_key . '|' . $appointment->start_at . '|' . (int) $template->timing_offset_minutes ) : null,
				)
		);
		if ( is_wp_error( $log_id ) && $dedupe ) {
			return false;
		}

		$attachments = array();
		$ics_path    = '';

		if ( ! empty( $template->send_ics ) ) {
			$ics_path = ( new IcsGenerator() )->temporary_file( $appointment );

			if ( $ics_path ) {
				$attachments[] = $ics_path;
			}
		}

		$email_type = 'plain' === $template->email_type ? 'plain' : 'html';
		$headers    = array(
			'Content-Type: ' . ( 'plain' === $email_type ? 'text/plain' : 'text/html' ) . '; charset=UTF-8',
			'From: ' . $this->from_header(),
		);

		$result = wp_mail(
			$recipients,
			$rendered['subject'],
			$renderer->message( $rendered, $email_type ),
			$headers,
			$attachments
		);

		if ( $ics_path && file_exists( $ics_path ) ) {
			unlink( $ics_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		if ( is_wp_error( $log_id ) ) {
			return (bool) $result;
		}

		if ( $result ) {
			$logs->mark_sent( $log_id );
			return true;
		}

		$logs->mark_failed( $log_id, __( 'wp_mail returned false.', 'yo-booking' ) );
		return false;
	}

	/**
	 * Build safe sample data for template previews.
	 *
	 * @return object
	 */
	private function sample_appointment() {
		$settings = ( new SettingsRepository() )->all();
		$start    = time() + DAY_IN_SECONDS;

		return (object) array(
			'id'             => 0,
			'uuid'           => '00000000-0000-4000-8000-000000000000',
			'customer_id'    => 0,
			'customer_name'  => __( 'Sample Customer', 'yo-booking' ),
			'customer_email' => 'customer@example.com',
			'customer_phone' => '+1 555 0100',
			'service_name'   => __( 'Sample Service', 'yo-booking' ),
			'staff_name'     => __( 'Sample Staff', 'yo-booking' ),
			'start_at'       => gmdate( 'Y-m-d H:i:s', $start ),
			'end_at'         => gmdate( 'Y-m-d H:i:s', $start + HOUR_IN_SECONDS ),
			'timezone'       => ! empty( $settings['company']['timezone'] ) ? $settings['company']['timezone'] : 'UTC',
			'status'         => 'confirmed',
			'subtotal_amount' => '125.00',
			'discount_amount' => '0.00',
			'tax_amount'      => '0.00',
			'total_amount'    => '125.00',
			'currency'        => ! empty( $settings['company']['currency'] ) ? $settings['company']['currency'] : 'USD',
			'payment_method'  => 'bank_transfer',
			'payment_method_title' => __( 'Bank transfer', 'yo-booking' ),
			'payment_collection_mode' => 'full',
			'payment_instructions' => __( 'Use the booking reference with your transfer.', 'yo-booking' ),
			'payment_reference' => 'YB-SAMPLE-1001',
			'payment_due_amount' => '125.00',
			'paid_amount'     => '50.00',
			'refunded_amount' => '0.00',
			'balance_amount'  => '75.00',
			'payment_status'  => 'partially_paid',
			'created_at'     => current_time( 'mysql', true ),
		);
	}

	/**
	 * Determine recipients for a template.
	 *
	 * @param object $template Template row.
	 * @param object $appointment Appointment row.
	 * @return array
	 */
	private function recipients( $template, $appointment ) {
		$settings = ( new SettingsRepository() )->all();

		if ( 'customer' === $template->recipient_type ) {
			return $this->emails( isset( $appointment->customer_email ) ? $appointment->customer_email : '' );
		}

		if ( 'staff' === $template->recipient_type ) {
			return $this->emails( isset( $appointment->staff_email ) ? $appointment->staff_email : '' );
		}

		$admin_to = isset( $settings['notifications']['admin_to'] ) ? $settings['notifications']['admin_to'] : get_option( 'admin_email' );

		return $this->emails( $admin_to );
	}

	/**
	 * Parse a recipient string into valid emails.
	 *
	 * @param string $value Raw value.
	 * @return array
	 */
	private function emails( $value ) {
		$emails = array();
		$parts  = preg_split( '/[,;\s]+/', (string) $value );

		foreach ( $parts as $part ) {
			$email = sanitize_email( $part );

			if ( $email && is_email( $email ) ) {
				$emails[] = $email;
			}
		}

		return array_values( array_unique( $emails ) );
	}

	/**
	 * Build the From header value.
	 *
	 * @return string
	 */
	private function from_header() {
		$settings   = ( new SettingsRepository() )->all();
		$from_name  = ! empty( $settings['notifications']['from_name'] ) ? $settings['notifications']['from_name'] : get_bloginfo( 'name' );
		$from_email = ! empty( $settings['notifications']['from_email'] ) ? sanitize_email( $settings['notifications']['from_email'] ) : sanitize_email( get_option( 'admin_email' ) );

		if ( ! is_email( $from_email ) ) {
			$from_email = sanitize_email( get_option( 'admin_email' ) );
		}

		return sprintf( '%s <%s>', wp_specialchars_decode( $from_name, ENT_QUOTES ), $from_email );
	}

}
