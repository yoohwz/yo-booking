<?php
/**
 * CSV data exporter.
 *
 * @package YoBooking
 */

namespace YoBooking\Export;

use YoBooking\Repositories\AppointmentRepository;
use YoBooking\Repositories\CustomerRepository;
use YoBooking\Repositories\ReportRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Produces UTF-8 CSV payloads without coupling to HTTP output.
 */
final class CsvExporter {
	/** @param array $filters Appointment filters. @return string */
	public function appointments( array $filters = array() ) {
		$rows = array( array( 'ID', 'Start UTC', 'End UTC', 'Customer', 'Email', 'Phone', 'Service', 'Staff', 'Status', 'Payment reference', 'Payment method', 'Payment status', 'Subtotal', 'Discount', 'Tax', 'Total', 'Paid', 'Refunded', 'Remaining', 'Currency' ) );
		$repository = new AppointmentRepository();
		$offset = 0;
		do {
			$batch = $repository->all( array_merge( $filters, array( 'limit' => 500, 'offset' => $offset ) ) );
			foreach ( $batch as $item ) {
				$rows[] = array( $item->id, $item->start_at, $item->end_at, $item->customer_name, $item->customer_email, $item->customer_phone, $item->service_name, $item->staff_name, $item->status, $item->payment_reference, $item->payment_method, $item->payment_status, $item->subtotal_amount, $item->discount_amount, $item->tax_amount, $item->total_amount, $item->paid_amount, $item->refunded_amount, $item->balance_amount, $item->currency );
			}
			$offset += 500;
		} while ( 500 === count( $batch ) );
		return $this->encode( $rows );
	}

	/** @param string $search Customer search. @return string */
	public function customers( $search = '' ) {
		$rows = array( array( 'ID', 'Name', 'Email', 'Phone', 'Timezone', 'Bookings', 'Paid total', 'Marketing consent', 'Created UTC' ) );
		$repository = new CustomerRepository();
		$offset = 0;
		do {
			$batch = $repository->page_with_stats( array( 'search' => $search, 'limit' => 100, 'offset' => $offset ) );
			foreach ( $batch as $item ) {
				$rows[] = array( $item->id, $item->name, $item->email, $item->phone, $item->timezone, $item->booking_count, $item->paid_total, $item->marketing_consent ? 'yes' : 'no', $item->created_at );
			}
			$offset += 100;
		} while ( 100 === count( $batch ) );
		return $this->encode( $rows );
	}

	/** @param string $from Start UTC. @param string $to End UTC. @return string */
	public function report( $from, $to ) {
		$report = new ReportRepository();
		$rows = array( array( 'Date UTC', 'Bookings', 'Paid revenue' ) );
		foreach ( $report->daily( $from, $to ) as $day ) {
			$rows[] = array( $day->report_date, $day->bookings, $day->revenue );
		}
		return $this->encode( $rows );
	}

	/** @param array $rows CSV rows. @return string */
	private function encode( array $rows ) {
		$lines = array();
		foreach ( $rows as $row ) {
			$lines[] = implode( ',', array_map( array( $this, 'encode_cell' ), $row ) );
		}
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/** @param mixed $value Cell value. @return string */
	private function encode_cell( $value ) {
		$value = $this->safe_cell( $value );
		return '"' . str_replace( '"', '""', $value ) . '"';
	}

	/** @param mixed $value Cell value. @return string */
	private function safe_cell( $value ) {
		$value = (string) $value;
		return preg_match( '/^[=+\-@]/', $value ) ? "'" . $value : $value;
	}
}
