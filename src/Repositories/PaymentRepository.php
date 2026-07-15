<?php
/**
 * Payment repository.
 *
 * @package YoBooking
 */

namespace YoBooking\Repositories;

use YoBooking\Database\Migrator;
use YoBooking\Payments\Currency;
use YoBooking\Payments\Money;
use YoBooking\Payments\PaymentProviderRegistry;
use YoBooking\Support\StaffAccess;
use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Stores appointment payment records.
 */
final class PaymentRepository extends BaseRepository {
	/**
	 * Logical table suffix.
	 *
	 * @var string
	 */
	protected $table_suffix = 'payments';

	/**
	 * Return supported payment record statuses.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'pending'        => __( 'Pending', 'yo-booking' ),
			'authorized'     => __( 'Authorized', 'yo-booking' ),
			'paid'           => __( 'Paid', 'yo-booking' ),
			'partially_paid' => __( 'Partially paid', 'yo-booking' ),
			'refunded'       => __( 'Refunded', 'yo-booking' ),
			'failed'         => __( 'Failed', 'yo-booking' ),
			'cancelled'      => __( 'Cancelled', 'yo-booking' ),
		);
	}

	/** @return array */
	public static function appointment_statuses() {
		return array(
			'pending'            => __( 'Pending', 'yo-booking' ),
			'authorized'         => __( 'Authorized', 'yo-booking' ),
			'partially_paid'     => __( 'Partially paid', 'yo-booking' ),
			'paid'               => __( 'Paid', 'yo-booking' ),
			'failed'             => __( 'Failed', 'yo-booking' ),
			'cancelled'          => __( 'Cancelled', 'yo-booking' ),
			'partially_refunded' => __( 'Partially refunded', 'yo-booking' ),
			'refunded'           => __( 'Refunded', 'yo-booking' ),
		);
	}

	/**
	 * Return payment records for an appointment.
	 *
	 * @param int $appointment_id Appointment ID.
	 * @return array
	 */
	public function for_appointment( $appointment_id ) {
		global $wpdb;

		$table          = $this->table();
		$appointment_id = absint( $appointment_id );

		if ( ! $appointment_id ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE appointment_id = %d ORDER BY created_at DESC, id DESC", $appointment_id ) );
	}

	/**
	 * Return latest payment record for an appointment.
	 *
	 * @param int $appointment_id Appointment ID.
	 * @return object|null
	 */
	public function latest_for_appointment( $appointment_id ) {
		global $wpdb;

		$table          = $this->table();
		$appointment_id = absint( $appointment_id );

		if ( ! $appointment_id ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE appointment_id = %d ORDER BY created_at DESC, id DESC LIMIT 1", $appointment_id ) );
	}

	/** @param string $key Idempotency key. @return object|null */
	public function find_by_idempotency_key( $key ) {
		global $wpdb;
		$key = sanitize_text_field( $key );
		if ( ! $key ) {
			return null;
		}
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s LIMIT 1", $key ) );
	}

	/**
	 * Create a payment record.
	 *
	 * @param array $data Raw payment data.
	 * @return int|WP_Error
	 */
	public function create( array $data ) {
		global $wpdb;

		$now             = $this->now();
		$status          = $this->payment_status( isset( $data['status'] ) ? $data['status'] : 'pending' );
		$currency        = Currency::normalize( isset( $data['currency'] ) ? $data['currency'] : '' );
		$idempotency_key = isset( $data['idempotency_key'] ) ? sanitize_text_field( $data['idempotency_key'] ) : '';

		if ( $idempotency_key ) {
			$existing = $this->find_by_idempotency_key( $idempotency_key );
			if ( $existing ) {
				if ( (int) $existing->appointment_id !== absint( isset( $data['appointment_id'] ) ? $data['appointment_id'] : 0 ) ) {
					return new WP_Error( 'yo_booking_payment_idempotency_conflict', __( 'This idempotency key is already assigned to another appointment.', 'yo-booking' ) );
				}
				$requested_kind     = isset( $data['kind'] ) && 'refund' === sanitize_key( $data['kind'] ) ? 'refund' : 'payment';
				$requested_provider = isset( $data['provider'] ) ? sanitize_key( $data['provider'] ) : 'local';
				$requested_amount   = Money::normalize( isset( $data['amount'] ) ? $data['amount'] : 0, $currency ? $currency : 'USD' );
				if ( $existing->kind !== $requested_kind || $existing->provider !== $requested_provider || $existing->status !== $status || Money::normalize( $existing->amount, $existing->currency ) !== $requested_amount || $existing->currency !== $currency ) {
					return new WP_Error( 'yo_booking_payment_idempotency_conflict', __( 'This idempotency key was reused with different transaction data.', 'yo-booking' ) );
				}
				return (int) $existing->id;
			}
		}

		$record = array(
			'appointment_id' => isset( $data['appointment_id'] ) ? absint( $data['appointment_id'] ) : 0,
			'provider'       => isset( $data['provider'] ) ? sanitize_key( $data['provider'] ) : 'local',
			'transaction_id' => isset( $data['transaction_id'] ) ? sanitize_text_field( $data['transaction_id'] ) : '',
			'provider_transaction_key' => null,
			'kind'           => isset( $data['kind'] ) && 'refund' === sanitize_key( $data['kind'] ) ? 'refund' : 'payment',
			'amount'         => Money::normalize( isset( $data['amount'] ) ? $data['amount'] : 0, $currency ? $currency : 'USD' ),
			'currency'       => $currency,
			'status'         => $status,
			'idempotency_key' => $idempotency_key ? $idempotency_key : null,
			'method_title'   => isset( $data['method_title'] ) ? sanitize_text_field( $data['method_title'] ) : '',
			'note'           => isset( $data['note'] ) ? wp_kses_post( $data['note'] ) : '',
			'gateway_metadata' => isset( $data['gateway_metadata'] ) ? wp_json_encode( $data['gateway_metadata'] ) : null,
			'created_by'     => isset( $data['created_by'] ) ? absint( $data['created_by'] ) : get_current_user_id(),
			'processed_at'   => in_array( $status, array( 'paid', 'partially_paid', 'refunded' ), true ) ? $now : null,
			'paid_at'        => in_array( $status, array( 'paid', 'partially_paid' ), true ) ? $now : null,
			'refunded_at'    => 'refunded' === $status ? $now : null,
			'created_at'     => $now,
			'updated_at'     => $now,
		);
		if ( $record['transaction_id'] ) {
			$record['provider_transaction_key'] = hash( 'sha256', $record['provider'] . '|' . $record['transaction_id'] );
		}

		if ( ! $record['appointment_id'] ) {
			return new WP_Error( 'yo_booking_payment_appointment_required', __( 'Appointment is required for payment records.', 'yo-booking' ) );
		}

		$appointment = ( new AppointmentRepository() )->find( $record['appointment_id'] );
		if ( ! $appointment ) {
			return new WP_Error( 'yo_booking_appointment_not_found', __( 'Appointment not found.', 'yo-booking' ) );
		}
		if ( ! StaffAccess::can_access_appointment( $record['appointment_id'] ) ) {
			return new WP_Error( 'yo_booking_appointment_forbidden', __( 'You cannot manage payments for this appointment.', 'yo-booking' ) );
		}

		if ( ! $record['currency'] ) {
			return new WP_Error( 'yo_booking_payment_currency_invalid', __( 'A supported currency is required for payment records.', 'yo-booking' ) );
		}
		if ( Currency::normalize( $appointment->currency ) !== $record['currency'] ) {
			return new WP_Error( 'yo_booking_payment_currency_mismatch', __( 'Transaction currency must match the appointment currency.', 'yo-booking' ) );
		}
		if ( ( 'refunded' === $record['status'] && 'refund' !== $record['kind'] ) || ( 'refund' === $record['kind'] && ! in_array( $record['status'], array( 'pending', 'failed', 'cancelled', 'refunded' ), true ) ) ) {
			return new WP_Error( 'yo_booking_payment_kind_invalid', __( 'Refund transactions must use a compatible refund status.', 'yo-booking' ) );
		}
		if ( in_array( $record['status'], array( 'paid', 'partially_paid', 'refunded' ), true ) && Money::to_minor( $record['amount'], $record['currency'] ) <= 0 ) {
			return new WP_Error( 'yo_booking_payment_amount_required', __( 'Enter an amount greater than zero.', 'yo-booking' ) );
		}
		$lock_name = $this->acquire_payment_lock( $record['appointment_id'] );
		if ( is_wp_error( $lock_name ) ) {
			return $lock_name;
		}

		if ( $idempotency_key ) {
			$existing = $this->find_by_idempotency_key( $idempotency_key );
			if ( $existing ) {
				$this->release_payment_lock( $lock_name );
				return (int) $existing->id;
			}
		}
		$appointment = ( new AppointmentRepository() )->find( $record['appointment_id'] );
		if ( 'refund' === $record['kind'] && 'refunded' === $record['status'] ) {
			$net_paid = max( 0, Money::to_minor( $appointment->paid_amount, $record['currency'] ) - Money::to_minor( $appointment->refunded_amount, $record['currency'] ) );
			if ( Money::to_minor( $record['amount'], $record['currency'] ) > $net_paid ) {
				$this->release_payment_lock( $lock_name );
				return new WP_Error( 'yo_booking_refund_amount_invalid', __( 'Refund amount cannot exceed the net amount paid.', 'yo-booking' ) );
			}
		}

		$inserted = $wpdb->insert(
			$this->table(),
			$record,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			$duplicate = $idempotency_key ? $this->find_by_idempotency_key( $idempotency_key ) : null;
			$this->release_payment_lock( $lock_name );
			if ( $duplicate ) {
				return (int) $duplicate->id;
			}
			return $this->database_error();
		}

		$payment_id = (int) $wpdb->insert_id;
		$this->recalculate_appointment( $record['appointment_id'], $payment_id );
		$this->release_payment_lock( $lock_name );
		do_action( 'yo_booking_payment_transaction_recorded', $payment_id, $record );

		return $payment_id;
	}

	/** @return string|WP_Error */
	private function acquire_payment_lock( $appointment_id ) {
		global $wpdb;
		$name = 'yo_booking:payment:' . absint( $appointment_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$locked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) );
		return 1 === $locked ? $name : new WP_Error( 'yo_booking_payment_busy', __( 'The payment record is busy. Please try again.', 'yo-booking' ) );
	}

	/** @param string $name Lock name. @return void */
	private function release_payment_lock( $name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Ensure there is a pending payment record for an appointment.
	 *
	 * @param object $appointment Appointment row.
	 * @param array  $summary Payment summary.
	 * @return int|WP_Error
	 */
	public function ensure_pending_for_appointment( $appointment, array $summary ) {
		$currency = Currency::normalize( isset( $summary['currency'] ) ? $summary['currency'] : '' );
		$amount   = Money::to_minor( isset( $summary['amount_due'] ) ? $summary['amount_due'] : 0, $currency ? $currency : 'USD' );

		if ( empty( $summary['enabled'] ) || $amount <= 0 || in_array( $appointment->payment_status, array( 'paid', 'refunded' ), true ) ) {
			return 0;
		}

		$latest = $this->latest_for_appointment( (int) $appointment->id );

		if ( $latest && 'pending' === $latest->status && Money::to_minor( $latest->amount, $currency ) === $amount ) {
			return (int) $latest->id;
		}

		return $this->create(
			array(
				'appointment_id' => (int) $appointment->id,
				'provider'       => isset( $summary['provider'] ) ? $summary['provider'] : 'local',
				'kind'           => 'payment',
				'amount'         => Money::from_minor( $amount, $currency ),
				'currency'       => isset( $summary['currency'] ) ? $summary['currency'] : '',
				'status'         => 'pending',
				'idempotency_key' => 'booking-due:' . (int) $appointment->id . ':' . md5( $summary['provider'] . ':' . Money::from_minor( $amount, $currency ) ),
				'method_title'   => isset( $summary['provider_title'] ) ? $summary['provider_title'] : __( 'Pay locally', 'yo-booking' ),
				'note'           => isset( $summary['instructions'] ) ? $summary['instructions'] : '',
			)
		);
	}

	/**
	 * Mark an appointment payment status and create an audit payment record.
	 *
	 * @param int    $appointment_id Appointment ID.
	 * @param string $status Payment status.
	 * @param mixed  $amount Amount.
	 * @param string $currency Currency.
	 * @param string $note Note.
	 * @param array  $transaction_data Gateway transaction data.
	 * @return bool|WP_Error
	 */
	public function mark_appointment( $appointment_id, $status, $amount, $currency, $note = '', array $transaction_data = array() ) {
		$appointment_id = absint( $appointment_id );
		$status         = $this->appointment_payment_status( $status );

		if ( ! $appointment_id ) {
			return new WP_Error( 'yo_booking_payment_appointment_required', __( 'Appointment is required for payment updates.', 'yo-booking' ) );
		}

		$appointment = ( new AppointmentRepository() )->find( $appointment_id );
		if ( ! $appointment ) {
			return new WP_Error( 'yo_booking_appointment_not_found', __( 'Appointment not found.', 'yo-booking' ) );
		}

		$appointment_currency = Currency::normalize( $appointment->currency );
		$currency    = Currency::normalize( $currency );
		if ( $currency !== $appointment_currency ) {
			return new WP_Error( 'yo_booking_payment_currency_mismatch', __( 'Transaction currency must match the appointment currency.', 'yo-booking' ) );
		}
		$amount_minor = Money::to_minor( $amount, $currency );
		$paid_minor   = Money::to_minor( isset( $appointment->paid_amount ) ? $appointment->paid_amount : 0, $currency );
		$refund_minor = Money::to_minor( isset( $appointment->refunded_amount ) ? $appointment->refunded_amount : 0, $currency );

		if ( in_array( $status, array( 'paid', 'partially_paid', 'refunded', 'partially_refunded' ), true ) && $amount_minor <= 0 ) {
			return new WP_Error( 'yo_booking_payment_amount_required', __( 'Enter an amount greater than zero.', 'yo-booking' ) );
		}

		if ( in_array( $status, array( 'refunded', 'partially_refunded' ), true ) && $amount_minor > max( 0, $paid_minor - $refund_minor ) ) {
			return new WP_Error( 'yo_booking_refund_amount_invalid', __( 'Refund amount cannot exceed the net amount paid.', 'yo-booking' ) );
		}

		$method_id   = $appointment && isset( $appointment->payment_method ) ? sanitize_key( $appointment->payment_method ) : 'manual';
		$provider    = ( new PaymentProviderRegistry() )->get( $method_id, false );
		$is_refund   = in_array( $status, array( 'refunded', 'partially_refunded' ), true );
		$record_status = $is_refund ? 'refunded' : ( 'partially_paid' === $status ? 'partially_paid' : $status );

		$created = $this->create(
			array(
				'appointment_id' => $appointment_id,
				'provider'       => $provider ? $provider->id() : $method_id,
				'kind'           => $is_refund ? 'refund' : 'payment',
				'amount'         => Money::from_minor( $amount_minor, $currency ),
				'currency'       => $currency,
				'status'         => $record_status,
				'transaction_id' => isset( $transaction_data['transaction_id'] ) ? $transaction_data['transaction_id'] : '',
				'idempotency_key' => isset( $transaction_data['idempotency_key'] ) ? $transaction_data['idempotency_key'] : '',
				'gateway_metadata' => isset( $transaction_data['gateway_metadata'] ) ? $transaction_data['gateway_metadata'] : null,
				'method_title'   => $provider ? $provider->title() : __( 'Manual payment', 'yo-booking' ),
				'note'           => $note,
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return true;
	}

	/**
	 * Recalculate appointment payment aggregates from successful ledger entries.
	 *
	 * @param int $appointment_id Appointment ID.
	 * @param int $payment_id Triggering payment ID.
	 * @return bool|WP_Error
	 */
	public function recalculate_appointment( $appointment_id, $payment_id = 0 ) {
		global $wpdb;

		$appointment = ( new AppointmentRepository() )->find( $appointment_id );
		if ( ! $appointment ) {
			return new WP_Error( 'yo_booking_appointment_not_found', __( 'Appointment not found.', 'yo-booking' ) );
		}

		$currency       = Currency::normalize( $appointment->currency );
		$total_minor    = Money::to_minor( $appointment->total_amount, $currency );
		$paid_minor     = 0;
		$refunded_minor = 0;
		$latest_state   = 'pending';

		foreach ( array_reverse( $this->for_appointment( $appointment_id ) ) as $transaction ) {
			if ( Currency::normalize( $transaction->currency ) !== $currency ) {
				continue;
			}
			$amount_minor = Money::to_minor( $transaction->amount, $currency );
			if ( 'refund' === $transaction->kind && 'refunded' === $transaction->status ) {
				$refunded_minor += $amount_minor;
			} elseif ( 'payment' === $transaction->kind && in_array( $transaction->status, array( 'paid', 'partially_paid' ), true ) ) {
				$paid_minor += $amount_minor;
			}

			if ( in_array( $transaction->status, array( 'pending', 'authorized', 'failed', 'cancelled' ), true ) ) {
				$latest_state = $transaction->status;
			}
		}

		$net_paid     = max( 0, $paid_minor - $refunded_minor );
		$balance      = max( 0, $total_minor - $net_paid );
		$status       = $latest_state;
		if ( $refunded_minor > 0 ) {
			$status = $paid_minor > 0 && 0 === $net_paid ? 'refunded' : 'partially_refunded';
		} elseif ( $total_minor > 0 && $paid_minor >= $total_minor ) {
			$status = 'paid';
		} elseif ( $paid_minor > 0 ) {
			$status = 'partially_paid';
		}

		$old_status = (string) $appointment->payment_status;
		$table      = Migrator::table_name( 'appointments' );
		$updated    = $wpdb->update(
			$table,
			array(
				'paid_amount'     => Money::from_minor( $paid_minor, $currency ),
				'refunded_amount' => Money::from_minor( $refunded_minor, $currency ),
				'balance_amount'  => Money::from_minor( $balance, $currency ),
				'payment_status'  => $status,
				'updated_at'      => $this->now(),
			),
			array( 'id' => $appointment_id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return $this->database_error();
		}

		if ( $old_status !== $status ) {
			do_action( 'yo_booking_payment_status_changed', $appointment_id, $status, $payment_id, $old_status );
		}

		return true;
	}

	/**
	 * Delete payment records for an appointment.
	 *
	 * @param int $appointment_id Appointment ID.
	 * @return void
	 */
	public function delete_for_appointment( $appointment_id ) {
		global $wpdb;

		$appointment_id = absint( $appointment_id );

		if ( ! $appointment_id ) {
			return;
		}

		$wpdb->delete( $this->table(), array( 'appointment_id' => $appointment_id ), array( '%d' ) );
	}

	/**
	 * Normalize payment record status.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private function payment_status( $status ) {
		$status = sanitize_key( $status );

		return array_key_exists( $status, self::statuses() ) ? $status : 'pending';
	}

	/**
	 * Normalize appointment payment status.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private function appointment_payment_status( $status ) {
		$status = sanitize_key( $status );
		$status = 'unpaid' === $status ? 'pending' : $status;

		return array_key_exists( $status, self::appointment_statuses() ) ? $status : 'pending';
	}

	/**
	 * Return a database error object.
	 *
	 * @return WP_Error
	 */
	private function database_error() {
		global $wpdb;

		return new WP_Error( 'yo_booking_database_error', $wpdb->last_error ? $wpdb->last_error : __( 'Database write failed.', 'yo-booking' ) );
	}
}
