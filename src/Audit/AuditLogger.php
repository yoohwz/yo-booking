<?php
/**
 * Operational audit event subscriber.
 *
 * @package YoBooking
 */

namespace YoBooking\Audit;

use YoBooking\Repositories\AuditLogRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Converts domain events into immutable audit entries.
 */
final class AuditLogger {
	/** @return void */
	public function boot() {
		add_action( 'yo_booking_appointment_created', array( $this, 'appointment_created' ), 10, 2 );
		add_action( 'yo_booking_appointment_updated', array( $this, 'appointment_updated' ), 10, 3 );
		add_action( 'yo_booking_appointment_status_changed', array( $this, 'appointment_status_changed' ), 10, 4 );
		add_action( 'yo_booking_appointment_rescheduled', array( $this, 'appointment_rescheduled' ), 10, 3 );
		add_action( 'yo_booking_payment_status_changed', array( $this, 'payment_status_changed' ), 10, 4 );
		add_action( 'yo_booking_payment_transaction_recorded', array( $this, 'payment_transaction_recorded' ), 10, 2 );
	}

	/** @param int $id Appointment ID. @return void */
	public function appointment_created( $id ) {
		if ( defined( 'YO_BOOKING_RUNNING_TESTS' ) && YO_BOOKING_RUNNING_TESTS ) {
			return;
		}
		// translators: %d: appointment database ID.
		$this->record( 'appointment.created', $id, sprintf( __( 'Appointment #%d created', 'yo-booking' ), $id ) );
	}

	/** @param int $id Appointment ID. @return void */
	public function appointment_updated( $id ) {
		if ( defined( 'YO_BOOKING_RUNNING_TESTS' ) && YO_BOOKING_RUNNING_TESTS ) {
			return;
		}
		// translators: %d: appointment database ID.
		$this->record( 'appointment.updated', $id, sprintf( __( 'Appointment #%d updated', 'yo-booking' ), $id ) );
	}

	/** @param int $id Appointment ID. @param string $new_status New status. @param string $old_status Old status. @return void */
	public function appointment_status_changed( $id, $new_status, $old_status ) {
		if ( defined( 'YO_BOOKING_RUNNING_TESTS' ) && YO_BOOKING_RUNNING_TESTS ) {
			return;
		}
		$this->record(
			'appointment.status_changed',
			$id,
			// translators: 1: appointment database ID, 2: previous status, 3: new status.
			sprintf( __( 'Appointment #%1$d status changed from %2$s to %3$s', 'yo-booking' ), $id, $old_status, $new_status ),
			array( 'from' => sanitize_key( $old_status ), 'to' => sanitize_key( $new_status ) )
		);
	}

	/** @param int $id Appointment ID. @param object $before Previous row. @param object $after Current row. @return void */
	public function appointment_rescheduled( $id, $before, $after ) {
		if ( defined( 'YO_BOOKING_RUNNING_TESTS' ) && YO_BOOKING_RUNNING_TESTS ) {
			return;
		}
		$this->record(
			'appointment.rescheduled',
			$id,
			// translators: %d: appointment database ID.
			sprintf( __( 'Appointment #%d rescheduled', 'yo-booking' ), $id ),
			array( 'from' => isset( $before->start_at ) ? $before->start_at : '', 'to' => isset( $after->start_at ) ? $after->start_at : '' )
		);
	}

	/** @param int $id Appointment ID. @param string $status Payment status. @param int $payment_id Payment record ID. @return void */
	public function payment_status_changed( $id, $status, $payment_id ) {
		if ( defined( 'YO_BOOKING_RUNNING_TESTS' ) && YO_BOOKING_RUNNING_TESTS ) {
			return;
		}
		( new AuditLogRepository() )->record(
			'payment.status_changed',
			'appointment',
			$id,
			// translators: 1: appointment database ID, 2: payment status.
			sprintf( __( 'Appointment #%1$d payment marked %2$s', 'yo-booking' ), $id, $status ),
			array( 'payment_id' => absint( $payment_id ), 'status' => sanitize_key( $status ) )
		);
	}

	/** @param int $payment_id Payment ID. @param array $record Inserted payment record. @return void */
	public function payment_transaction_recorded( $payment_id, $record ) {
		if ( defined( 'YO_BOOKING_RUNNING_TESTS' ) && YO_BOOKING_RUNNING_TESTS ) {
			return;
		}
		$appointment_id = isset( $record['appointment_id'] ) ? absint( $record['appointment_id'] ) : 0;
		( new AuditLogRepository() )->record(
			'payment.transaction_recorded',
			'appointment',
			$appointment_id,
			// translators: 1: payment transaction ID, 2: appointment database ID.
			sprintf( __( 'Payment transaction #%1$d recorded for appointment #%2$d', 'yo-booking' ), $payment_id, $appointment_id ),
			array(
				'payment_id' => absint( $payment_id ),
				'kind'       => isset( $record['kind'] ) ? sanitize_key( $record['kind'] ) : 'payment',
				'status'     => isset( $record['status'] ) ? sanitize_key( $record['status'] ) : 'pending',
				'amount'     => isset( $record['amount'] ) ? (string) $record['amount'] : '0',
				'currency'   => isset( $record['currency'] ) ? sanitize_text_field( $record['currency'] ) : '',
			)
		);
	}

	/** @param string $action Action key. @param int $id Appointment ID. @param string $summary Summary. @param array $context Context. @return void */
	private function record( $action, $id, $summary, array $context = array() ) {
		( new AuditLogRepository() )->record( $action, 'appointment', $id, $summary, $context );
	}
}
