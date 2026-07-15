<?php
/**
 * Admin CSV download endpoints.
 *
 * @package YoBooking
 */

namespace YoBooking\Admin;

use DateTimeImmutable;
use DateTimeZone;
use YoBooking\Export\CsvExporter;
use YoBooking\Settings\Repository as SettingsRepository;
use YoBooking\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Streams authorized CSV exports.
 */
final class ExportController {
	/** @return void */
	public function boot() {
		add_action( 'admin_post_yo_booking_export_appointments', array( $this, 'appointments' ) );
		add_action( 'admin_post_yo_booking_export_customers', array( $this, 'customers' ) );
		add_action( 'admin_post_yo_booking_export_report', array( $this, 'report' ) );
	}

	/** @return void */
	public function appointments() {
		$this->authorize( 'yo_booking_export_appointments' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- authorize() verifies this request before filters are read.
		$filters = array(
			'status'         => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'payment_status' => isset( $_GET['payment_status'] ) ? sanitize_key( wp_unslash( $_GET['payment_status'] ) ) : '',
			'service_id'     => isset( $_GET['service_id'] ) ? absint( $_GET['service_id'] ) : 0,
			'staff_id'       => isset( $_GET['staff_id'] ) ? absint( $_GET['staff_id'] ) : 0,
			'search'         => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);
		if ( ! empty( $_GET['date_from'] ) ) {
			$filters['from'] = $this->utc_boundary( sanitize_text_field( wp_unslash( $_GET['date_from'] ) ), false );
		}
		if ( ! empty( $_GET['date_to'] ) ) {
			$filters['to'] = $this->utc_boundary( sanitize_text_field( wp_unslash( $_GET['date_to'] ) ), true );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$this->download( 'yo-booking-appointments-' . gmdate( 'Y-m-d' ) . '.csv', ( new CsvExporter() )->appointments( $filters ) );
	}

	/** @return void */
	public function customers() {
		$this->authorize( 'yo_booking_export_customers' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- authorize() verifies this request before filters are read.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$this->download( 'yo-booking-customers-' . gmdate( 'Y-m-d' ) . '.csv', ( new CsvExporter() )->customers( $search ) );
	}

	/** @return void */
	public function report() {
		$this->authorize( 'yo_booking_export_report' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- authorize() verifies this request before filters are read.
		$from = isset( $_GET['date_from'] ) ? $this->utc_boundary( sanitize_text_field( wp_unslash( $_GET['date_from'] ) ), false ) : gmdate( 'Y-m-01 00:00:00' );
		$to   = isset( $_GET['date_to'] ) ? $this->utc_boundary( sanitize_text_field( wp_unslash( $_GET['date_to'] ) ), true ) : gmdate( 'Y-m-d 23:59:59' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$this->download( 'yo-booking-report-' . gmdate( 'Y-m-d' ) . '.csv', ( new CsvExporter() )->report( $from, $to ) );
	}

	/** @param string $nonce_action Nonce action. @return void */
	private function authorize( $nonce_action ) {
		if ( ! current_user_can( Capabilities::export() ) ) {
			wp_die( esc_html__( 'You do not have permission to export booking data.', 'yo-booking' ) );
		}
		check_admin_referer( $nonce_action );
	}

	/** @param string $date Date. @param bool $end_of_day End boundary. @return string */
	private function utc_boundary( $date, $end_of_day ) {
		$date = sanitize_text_field( $date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = gmdate( 'Y-m-d' );
		}
		try {
			$timezone = new DateTimeZone( ( new SettingsRepository() )->get( 'company.timezone', wp_timezone_string() ) );
		} catch ( \Exception $exception ) {
			$timezone = wp_timezone();
		}
		$time = $end_of_day ? '23:59:59' : '00:00:00';
		return ( new DateTimeImmutable( $date . ' ' . $time, $timezone ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/** @param string $filename Filename. @param string $csv CSV payload. @return void */
	private function download( $filename, $csv ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
