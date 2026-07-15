<?php
/**
 * Booking reporting queries.
 *
 * @package YoBooking
 */

namespace YoBooking\Repositories;

use YoBooking\Database\Migrator;
use YoBooking\Payments\Currency;
use YoBooking\Settings\Repository as SettingsRepository;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Read-only aggregate queries for operational reports.
 */
final class ReportRepository {
	/**
	 * Return headline metrics for a UTC range.
	 *
	 * @param string $from Start datetime.
	 * @param string $to End datetime.
	 * @return object
	 */
	public function summary( $from, $to ) {
		global $wpdb;
		$table = Migrator::table_name( 'appointments' );
		$currency = $this->reporting_currency();
		$sql = "SELECT COUNT(*) AS total_bookings,
			SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
			SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
			SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) AS no_show,
			SUM(CASE WHEN currency = %s THEN GREATEST(paid_amount - refunded_amount, 0) ELSE 0 END) AS paid_revenue,
			SUM(CASE WHEN currency = %s AND status <> 'cancelled' THEN balance_amount ELSE 0 END) AS outstanding
			FROM {$table} WHERE start_at BETWEEN %s AND %s";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $currency, $currency, $from, $to ) );
		return $row ? $row : (object) array( 'total_bookings' => 0, 'completed' => 0, 'cancelled' => 0, 'no_show' => 0, 'paid_revenue' => 0, 'outstanding' => 0 );
	}

	/** @param string $from Start datetime. @param string $to End datetime. @return array */
	public function daily( $from, $to ) {
		global $wpdb;
		$table = Migrator::table_name( 'appointments' );
		$currency = $this->reporting_currency();
		$sql = "SELECT DATE(start_at) AS report_date, COUNT(*) AS bookings,
			SUM(CASE WHEN currency = %s THEN GREATEST(paid_amount - refunded_amount, 0) ELSE 0 END) AS revenue
			FROM {$table} WHERE start_at BETWEEN %s AND %s GROUP BY DATE(start_at) ORDER BY report_date ASC";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( $wpdb->prepare( $sql, $currency, $from, $to ) );
	}

	/** Return financial totals separated by currency; values are never combined. */
	public function financial_by_currency( $from, $to ) {
		global $wpdb;
		$table = Migrator::table_name( 'appointments' );
		$sql = "SELECT currency, COUNT(*) AS bookings,
			SUM(GREATEST(paid_amount - refunded_amount, 0)) AS paid_revenue,
			SUM(CASE WHEN status <> 'cancelled' THEN balance_amount ELSE 0 END) AS outstanding
			FROM {$table} WHERE start_at BETWEEN %s AND %s GROUP BY currency ORDER BY currency ASC";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( $wpdb->prepare( $sql, $from, $to ) );
	}

	/** @param string $from Start datetime. @param string $to End datetime. @param int $limit Maximum rows. @return array */
	public function top_services( $from, $to, $limit = 10 ) {
		return $this->top_dimension( 'service', $from, $to, $limit );
	}

	/** @param string $from Start datetime. @param string $to End datetime. @param int $limit Maximum rows. @return array */
	public function top_staff( $from, $to, $limit = 10 ) {
		return $this->top_dimension( 'staff', $from, $to, $limit );
	}

	/** @param string $dimension Dimension name. @param string $from Start datetime. @param string $to End datetime. @param int $limit Maximum rows. @return array */
	private function top_dimension( $dimension, $from, $to, $limit ) {
		global $wpdb;
		$appointments = Migrator::table_name( 'appointments' );
		$table        = Migrator::table_name( 'service' === $dimension ? 'services' : 'staff' );
		$id_column    = 'service' === $dimension ? 'service_id' : 'staff_id';
		$limit        = max( 1, min( 50, absint( $limit ) ) );
		$currency     = $this->reporting_currency();
		$sql = "SELECT COALESCE(d.name, %s) AS name, COUNT(a.id) AS bookings,
			SUM(CASE WHEN a.currency = %s THEN GREATEST(a.paid_amount - a.refunded_amount, 0) ELSE 0 END) AS revenue
			FROM {$appointments} a LEFT JOIN {$table} d ON d.id = a.{$id_column}
			WHERE a.start_at BETWEEN %s AND %s GROUP BY a.{$id_column}, d.name ORDER BY bookings DESC LIMIT %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( $wpdb->prepare( $sql, __( 'Unassigned', 'yo-booking' ), $currency, $from, $to, $limit ) );
	}

	/** @return string */
	private function reporting_currency() {
		$currency = Currency::normalize( ( new SettingsRepository() )->get( 'company.currency', 'USD' ) );
		return $currency ? $currency : 'USD';
	}
}
