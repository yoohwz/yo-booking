<?php
/**
 * Booking dashboard aggregate queries.
 *
 * @package YoBooking
 */

namespace YoBooking\Repositories;

use YoBooking\Database\Migrator;
use YoBooking\Payments\Currency;
use YoBooking\Support\StaffAccess;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Provides bounded, ownership-aware dashboard metrics.
 */
final class DashboardRepository {
	/**
	 * Return headline and status metrics for the dashboard windows.
	 *
	 * @param string $today_from Today start in UTC.
	 * @param string $today_to Today end in UTC.
	 * @param string $week_to Seven-day window end in UTC.
	 * @param string $month_to Thirty-day future window end in UTC.
	 * @param string $history_from Thirty-day history start in UTC.
	 * @param string $currency Reporting currency.
	 * @return object
	 */
	public function summary( $today_from, $today_to, $week_to, $month_to, $history_from, $currency ) {
		global $wpdb;

		$table        = Migrator::table_name( 'appointments' );
		$today_from   = sanitize_text_field( $today_from );
		$today_to     = sanitize_text_field( $today_to );
		$week_to      = sanitize_text_field( $week_to );
		$month_to     = sanitize_text_field( $month_to );
		$history_from = sanitize_text_field( $history_from );
		$currency     = Currency::normalize( $currency );
		$currency     = $currency ? $currency : 'USD';
		$staff_id     = StaffAccess::restricted() ? StaffAccess::current_staff_id() : 0;
		$staff_sql    = StaffAccess::restricted() ? ' AND a.staff_id = %d' : '';

		$sql = "SELECT
			COALESCE(SUM(CASE WHEN a.start_at >= %s AND a.start_at < %s AND a.status <> 'rescheduled' THEN 1 ELSE 0 END), 0) AS today_total,
			COALESCE(SUM(CASE WHEN a.start_at >= %s AND a.start_at < %s AND a.status = 'pending' THEN 1 ELSE 0 END), 0) AS today_pending,
			COALESCE(SUM(CASE WHEN a.start_at >= %s AND a.start_at < %s AND a.status NOT IN ('cancelled', 'rescheduled') THEN 1 ELSE 0 END), 0) AS next_7_total,
			COALESCE(SUM(CASE WHEN a.start_at >= %s AND a.start_at < %s AND a.status = 'pending' THEN 1 ELSE 0 END), 0) AS upcoming_pending,
			COALESCE(SUM(CASE WHEN a.start_at >= %s AND a.start_at < %s AND a.status NOT IN ('cancelled', 'rescheduled') AND a.balance_amount > 0 AND a.payment_status IN ('pending', 'authorized', 'partially_paid', 'failed') THEN 1 ELSE 0 END), 0) AS payment_followup,
			COALESCE(SUM(CASE WHEN a.start_at >= %s AND a.start_at < %s AND a.currency = %s AND a.status NOT IN ('cancelled', 'rescheduled') THEN GREATEST(a.balance_amount, 0) ELSE 0 END), 0) AS outstanding,
			COALESCE(SUM(CASE WHEN a.start_at >= %s AND a.start_at < %s AND a.currency = %s THEN GREATEST(a.paid_amount - a.refunded_amount, 0) ELSE 0 END), 0) AS paid_revenue,
			COALESCE(SUM(CASE WHEN a.start_at >= %s AND a.start_at < %s AND a.status <> 'rescheduled' THEN 1 ELSE 0 END), 0) AS history_total,
			COALESCE(SUM(CASE WHEN a.start_at >= %s AND a.start_at < %s AND a.status = 'pending' THEN 1 ELSE 0 END), 0) AS history_pending,
			COALESCE(SUM(CASE WHEN a.start_at >= %s AND a.start_at < %s AND a.status = 'confirmed' THEN 1 ELSE 0 END), 0) AS history_confirmed,
			COALESCE(SUM(CASE WHEN a.start_at >= %s AND a.start_at < %s AND a.status = 'completed' THEN 1 ELSE 0 END), 0) AS history_completed,
			COALESCE(SUM(CASE WHEN a.start_at >= %s AND a.start_at < %s AND a.status = 'cancelled' THEN 1 ELSE 0 END), 0) AS history_cancelled,
			COALESCE(SUM(CASE WHEN a.start_at >= %s AND a.start_at < %s AND a.status = 'no_show' THEN 1 ELSE 0 END), 0) AS history_no_show
			FROM {$table} a
			WHERE a.start_at >= %s AND a.start_at < %s{$staff_sql}";

		$params = array(
			$today_from,
			$today_to,
			$today_from,
			$today_to,
			$today_from,
			$week_to,
			$today_from,
			$month_to,
			$today_from,
			$month_to,
			$today_from,
			$month_to,
			$currency,
			$history_from,
			$today_to,
			$currency,
			$history_from,
			$today_to,
			$history_from,
			$today_to,
			$history_from,
			$today_to,
			$history_from,
			$today_to,
			$history_from,
			$today_to,
			$history_from,
			$today_to,
			$history_from,
			$month_to,
		);

		if ( StaffAccess::restricted() ) {
			$params[] = $staff_id;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dashboard metrics must reflect current operational state.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $params ) );

		return $row ? $row : (object) array(
			'today_total'       => 0,
			'today_pending'     => 0,
			'next_7_total'      => 0,
			'upcoming_pending'  => 0,
			'payment_followup'  => 0,
			'outstanding'       => 0,
			'paid_revenue'      => 0,
			'history_total'     => 0,
			'history_pending'   => 0,
			'history_confirmed' => 0,
			'history_completed' => 0,
			'history_cancelled' => 0,
			'history_no_show'   => 0,
		);
	}

	/**
	 * Return booking counts for local-day UTC buckets.
	 *
	 * @param array $buckets Rows containing from and to UTC values.
	 * @return array
	 */
	public function upcoming_days( array $buckets ) {
		global $wpdb;

		if ( empty( $buckets ) ) {
			return array();
		}

		$table     = Migrator::table_name( 'appointments' );
		$select    = array();
		$params    = array();
		$staff_id  = StaffAccess::restricted() ? StaffAccess::current_staff_id() : 0;
		$staff_sql = StaffAccess::restricted() ? ' AND a.staff_id = %d' : '';

		foreach ( array_values( $buckets ) as $index => $bucket ) {
			$select[] = "COALESCE(SUM(CASE WHEN a.start_at >= %s AND a.start_at < %s AND a.status NOT IN ('cancelled', 'rescheduled') THEN 1 ELSE 0 END), 0) AS day_{$index}";
			$params[] = sanitize_text_field( $bucket['from'] );
			$params[] = sanitize_text_field( $bucket['to'] );
		}

		$first_from = sanitize_text_field( reset( $buckets )['from'] );
		$last       = end( $buckets );
		$last_to    = sanitize_text_field( $last['to'] );
		$params[]   = $first_from;
		$params[]   = $last_to;

		if ( StaffAccess::restricted() ) {
			$params[] = $staff_id;
		}

		$sql = 'SELECT ' . implode( ', ', $select ) . " FROM {$table} a WHERE a.start_at >= %s AND a.start_at < %s{$staff_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dashboard metrics must reflect current operational state.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$counts       = array();
		$bucket_count = count( $buckets );
		for ( $index = 0; $index < $bucket_count; ++$index ) {
			$counts[] = isset( $row[ 'day_' . $index ] ) ? (int) $row[ 'day_' . $index ] : 0;
		}

		return $counts;
	}

	/**
	 * Return the busiest services in a future window.
	 *
	 * @param string $from Start in UTC.
	 * @param string $to End in UTC.
	 * @param string $currency Reporting currency.
	 * @param int    $limit Maximum rows.
	 * @return array
	 */
	public function top_services( $from, $to, $currency, $limit = 5 ) {
		global $wpdb;

		$appointments = Migrator::table_name( 'appointments' );
		$services     = Migrator::table_name( 'services' );
		$currency     = Currency::normalize( $currency );
		$currency     = $currency ? $currency : 'USD';
		$limit        = max( 1, min( 10, absint( $limit ) ) );
		$staff_id     = StaffAccess::restricted() ? StaffAccess::current_staff_id() : 0;
		$staff_sql    = StaffAccess::restricted() ? ' AND a.staff_id = %d' : '';
		$sql          = "SELECT COALESCE(MAX(NULLIF(a.service_name_snapshot, '')), MAX(s.name), %s) AS name,
			COUNT(a.id) AS bookings,
			COALESCE(SUM(CASE WHEN a.currency = %s THEN GREATEST(a.paid_amount - a.refunded_amount, 0) ELSE 0 END), 0) AS revenue
			FROM {$appointments} a
			LEFT JOIN {$services} s ON s.id = a.service_id
			WHERE a.start_at >= %s AND a.start_at < %s
				AND a.status NOT IN ('cancelled', 'rescheduled'){$staff_sql}
			GROUP BY a.service_id
			ORDER BY bookings DESC, name ASC
			LIMIT %d";
		$params       = array( __( 'Deleted service', 'yo-booking' ), $currency, sanitize_text_field( $from ), sanitize_text_field( $to ) );

		if ( StaffAccess::restricted() ) {
			$params[] = $staff_id;
		}
		$params[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dashboard metrics must reflect current operational state.
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Count recent failed notifications visible to the current user.
	 *
	 * @param string $since Start in UTC.
	 * @return int
	 */
	public function failed_notifications( $since ) {
		global $wpdb;

		$logs         = Migrator::table_name( 'notification_logs' );
		$appointments = Migrator::table_name( 'appointments' );
		$since        = sanitize_text_field( $since );

		if ( StaffAccess::restricted() ) {
			$staff_id = StaffAccess::current_staff_id();
			if ( ! $staff_id ) {
				return 0;
			}
			$sql = "SELECT COUNT(*) FROM {$logs} l INNER JOIN {$appointments} a ON a.id = l.appointment_id WHERE l.status = 'failed' AND l.updated_at >= %s AND a.staff_id = %d";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dashboard alerts must reflect current delivery state.
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $since, $staff_id ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dashboard alerts must reflect current delivery state.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs} WHERE status = 'failed' AND updated_at >= %s", $since ) );
	}
}
