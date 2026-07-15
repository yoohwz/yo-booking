<?php
/**
 * WordPress personal data integration.
 *
 * @package YoBooking
 */

namespace YoBooking\Privacy;

use YoBooking\Database\Migrator;
use YoBooking\Repositories\AuditLogRepository;
use YoBooking\Repositories\PaymentRepository;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Exports and anonymizes customer booking data by email address.
 */
final class PrivacyManager {
	const PAGE_SIZE = 50;

	/** @return void */
	public function boot() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/** @param array $exporters Existing exporters. @return array */
	public function register_exporter( $exporters ) {
		$exporters['yo-booking'] = array( 'exporter_friendly_name' => __( 'Yo Booking customer data', 'yo-booking' ), 'callback' => array( $this, 'export' ) );
		return $exporters;
	}

	/** @param array $erasers Existing erasers. @return array */
	public function register_eraser( $erasers ) {
		$erasers['yo-booking'] = array( 'eraser_friendly_name' => __( 'Yo Booking customer data', 'yo-booking' ), 'callback' => array( $this, 'erase' ) );
		return $erasers;
	}

	/**
	 * Export customer profile and booking history.
	 *
	 * @param string $email_address Request email.
	 * @param int    $page Page number.
	 * @return array
	 */
	public function export( $email_address, $page = 1 ) {
		global $wpdb;
		$email = sanitize_email( $email_address );
		$page  = max( 1, absint( $page ) );
		$data  = array();
		$customers = Migrator::table_name( 'customers' );

		if ( 1 === $page ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$customers} WHERE email = %s ORDER BY id ASC", $email ) ) as $customer ) {
				$data[] = array(
					'group_id'    => 'yo-booking-customer',
					'group_label' => __( 'Booking customer profile', 'yo-booking' ),
					'item_id'     => 'yo-booking-customer-' . (int) $customer->id,
					'data'        => array(
						array( 'name' => __( 'Name', 'yo-booking' ), 'value' => $customer->name ),
						array( 'name' => __( 'Email', 'yo-booking' ), 'value' => $customer->email ),
						array( 'name' => __( 'Phone', 'yo-booking' ), 'value' => $customer->phone ),
						array( 'name' => __( 'Timezone', 'yo-booking' ), 'value' => $customer->timezone ),
						array( 'name' => __( 'Notes', 'yo-booking' ), 'value' => wp_strip_all_tags( $customer->notes ) ),
						array( 'name' => __( 'Marketing consent', 'yo-booking' ), 'value' => $customer->marketing_consent ? __( 'Yes', 'yo-booking' ) : __( 'No', 'yo-booking' ) ),
						array( 'name' => __( 'Created', 'yo-booking' ), 'value' => $customer->created_at ),
					),
				);
			}
		}

		$appointments = Migrator::table_name( 'appointments' );
		$services     = Migrator::table_name( 'services' );
		$staff        = Migrator::table_name( 'staff' );
		$offset       = ( $page - 1 ) * self::PAGE_SIZE;
		$sql = "SELECT a.*, s.name AS service_name, st.name AS staff_name
			FROM {$appointments} a INNER JOIN {$customers} c ON c.id = a.customer_id
			LEFT JOIN {$services} s ON s.id = a.service_id LEFT JOIN {$staff} st ON st.id = a.staff_id
			WHERE c.email = %s ORDER BY a.start_at DESC LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $email, self::PAGE_SIZE, $offset ) );
		foreach ( $rows as $appointment ) {
			$item = array(
				array( 'name' => __( 'Appointment ID', 'yo-booking' ), 'value' => $appointment->id ),
				array( 'name' => __( 'Service', 'yo-booking' ), 'value' => $appointment->service_name ),
				array( 'name' => __( 'Staff', 'yo-booking' ), 'value' => $appointment->staff_name ),
				array( 'name' => __( 'Start time (UTC)', 'yo-booking' ), 'value' => $appointment->start_at ),
				array( 'name' => __( 'End time (UTC)', 'yo-booking' ), 'value' => $appointment->end_at ),
				array( 'name' => __( 'Status', 'yo-booking' ), 'value' => $appointment->status ),
				array( 'name' => __( 'Customer note', 'yo-booking' ), 'value' => wp_strip_all_tags( $appointment->customer_note ) ),
				array( 'name' => __( 'Internal note', 'yo-booking' ), 'value' => wp_strip_all_tags( $appointment->internal_note ) ),
				array( 'name' => __( 'Payment status', 'yo-booking' ), 'value' => $appointment->payment_status ),
				array( 'name' => __( 'Payment method', 'yo-booking' ), 'value' => $appointment->payment_method ),
				array( 'name' => __( 'Payment reference', 'yo-booking' ), 'value' => $appointment->payment_reference ),
				array( 'name' => __( 'Total', 'yo-booking' ), 'value' => trim( $appointment->currency . ' ' . $appointment->total_amount ) ),
				array( 'name' => __( 'Paid', 'yo-booking' ), 'value' => trim( $appointment->currency . ' ' . $appointment->paid_amount ) ),
				array( 'name' => __( 'Refunded', 'yo-booking' ), 'value' => trim( $appointment->currency . ' ' . $appointment->refunded_amount ) ),
				array( 'name' => __( 'Remaining balance', 'yo-booking' ), 'value' => trim( $appointment->currency . ' ' . $appointment->balance_amount ) ),
			);
			foreach ( ( new PaymentRepository() )->for_appointment( (int) $appointment->id ) as $payment ) {
				$item[] = array( 'name' => __( 'Payment record', 'yo-booking' ), 'value' => trim( $payment->status . ' ' . $payment->currency . ' ' . $payment->amount . ' ' . $payment->transaction_id ) );
			}
			$data[] = array( 'group_id' => 'yo-booking-appointments', 'group_label' => __( 'Booking appointments', 'yo-booking' ), 'item_id' => 'yo-booking-appointment-' . (int) $appointment->id, 'data' => $item );
		}

		return array( 'data' => $data, 'done' => count( $rows ) < self::PAGE_SIZE );
	}

	/**
	 * Anonymize personal fields while retaining operational booking records.
	 *
	 * @param string $email_address Request email.
	 * @param int    $page Page number.
	 * @return array
	 */
	public function erase( $email_address, $page = 1 ) {
		global $wpdb;
		$email     = sanitize_email( $email_address );
		$customers = Migrator::table_name( 'customers' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$customers} WHERE email = %s", $email ) ) );

		if ( ! $ids ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}

		$id_list      = implode( ',', $ids );
		$appointments = Migrator::table_name( 'appointments' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$appointment_ids = array_map( 'absint', $wpdb->get_col( "SELECT id FROM {$appointments} WHERE customer_id IN ({$id_list})" ) );

		foreach ( $ids as $customer_id ) {
			// translators: %d: customer database ID.
			$anonymous_name = sprintf( __( 'Anonymous customer #%d', 'yo-booking' ), $customer_id );
			$wpdb->update(
				$customers,
				array( 'user_id' => 0, 'name' => $anonymous_name, 'email' => '', 'phone' => '', 'notes' => '', 'marketing_consent' => 0, 'updated_at' => current_time( 'mysql', true ) ),
				array( 'id' => $customer_id ),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
			// translators: %d: customer database ID.
			$audit_summary = sprintf( __( 'Customer #%d personal data anonymized', 'yo-booking' ), $customer_id );
			( new AuditLogRepository() )->record( 'customer.privacy_erased', 'customer', $customer_id, $audit_summary );
		}

		if ( $appointment_ids ) {
			$appointment_list = implode( ',', $appointment_ids );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( "UPDATE {$appointments} SET customer_note = '', internal_note = '' WHERE id IN ({$appointment_list})" );
			$logs = Migrator::table_name( 'notification_logs' );
			$payments = Migrator::table_name( 'payments' );
			$webhook_deliveries = Migrator::table_name( 'webhook_deliveries' );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( "DELETE FROM {$logs} WHERE appointment_id IN ({$appointment_list})" );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( "UPDATE {$payments} SET note = '', gateway_metadata = NULL WHERE appointment_id IN ({$appointment_list})" );
			// Webhook payload snapshots contain the same customer fields as the appointment.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( "DELETE FROM {$webhook_deliveries} WHERE object_type = 'appointment' AND object_id IN ({$appointment_list})" );
		}

		return array(
			'items_removed'  => true,
			'items_retained' => ! empty( $appointment_ids ),
			'messages'       => $appointment_ids ? array( __( 'Booking and payment totals were retained for operational and accounting records, with customer identity and notes anonymized.', 'yo-booking' ) ) : array(),
			'done'           => true,
		);
	}

	/** @return void */
	public function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p>' . esc_html__( 'When you book an appointment, we store your name, email address, phone number, timezone, booking details, notes, payment method, payment status, and notification delivery history. This data is used to schedule and manage appointments, communicate booking updates, and maintain operational or accounting records.', 'yo-booking' ) . '</p>';
		$content .= '<p>' . esc_html__( 'If webhook integrations are enabled, booking data may be sent to the external endpoint configured by the site administrator. Those external services process data under their own privacy terms.', 'yo-booking' ) . '</p>';
		wp_add_privacy_policy_content( __( 'Yo Booking', 'yo-booking' ), wp_kses_post( wpautop( $content, false ) ) );
	}
}
