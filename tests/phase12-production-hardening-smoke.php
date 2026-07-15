<?php
/**
 * Phase 12 roles, audit, reports, export, and pagination smoke test.
 *
 * @package YoBooking
 */

defined( 'ABSPATH' ) || exit;
defined( 'YO_BOOKING_RUNNING_TESTS' ) || define( 'YO_BOOKING_RUNNING_TESTS', true );

global $wpdb;

$customer_ids  = array();
$appointment_ids = array();
$service_id    = 0;
$staff_id      = 0;
$audit_id      = 0;
$error         = '';
$suffix        = gmdate( 'YmdHis' );
$customers     = new YoBooking\Repositories\CustomerRepository();
$services      = new YoBooking\Repositories\ServiceRepository();
$staff         = new YoBooking\Repositories\StaffRepository();
$audit         = new YoBooking\Repositories\AuditLogRepository();
$reports       = new YoBooking\Repositories\ReportRepository();
$fail          = static function ( $message ) { throw new RuntimeException( $message ); };

try {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	if ( ! $admins ) $fail( 'administrator user is required' );
	wp_set_current_user( (int) $admins[0]->ID );

	if ( ! current_user_can( YoBooking\Support\Capabilities::appointments() ) || ! current_user_can( YoBooking\Support\Capabilities::reports() ) || ! current_user_can( YoBooking\Support\Capabilities::export() ) ) {
		$fail( 'administrator booking capabilities are missing' );
	}
	if ( ! get_role( 'yo_booking_manager' ) || ! get_role( 'yo_booking_staff' ) || ! get_role( 'yo_booking_staff' )->has_cap( YoBooking\Support\Capabilities::appointments() ) ) {
		$fail( 'booking roles were not installed' );
	}

	$audit_table = YoBooking\Database\Migrator::table_name( 'audit_logs' );
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $audit_table ) ) !== $audit_table ) {
		$fail( 'audit table is missing' );
	}
	$audit_id = $audit->record( 'phase12.test_event', 'test', 12, 'Phase 12 audit event', array( 'safe' => true ) );
	$audit_row = $audit->find( $audit_id );
	if ( ! $audit_row || 'phase12.test_event' !== $audit_row->action || (int) $admins[0]->ID !== (int) $audit_row->actor_user_id ) {
		$fail( 'audit record contract failed' );
	}

	$service_id = $services->save( array( 'name' => 'Phase 12 Service ' . $suffix, 'duration_minutes' => 30, 'price' => 100, 'currency' => 'USD' ) );
	$staff_id   = $staff->save( array( 'name' => 'Phase 12 Staff ' . $suffix, 'email' => 'phase12-staff-' . $suffix . '@example.test' ) );
	if ( is_wp_error( $service_id ) || is_wp_error( $staff_id ) ) $fail( 'fixture creation failed' );

	for ( $i = 0; $i < 130; $i++ ) {
		$name = 0 === $i ? '=Phase 12 Formula' : 'Phase 12 Customer ' . $suffix . ' ' . $i;
		$id = $customers->save( array( 'name' => $name, 'email' => 'phase12-' . $suffix . '-' . $i . '@example.test', 'timezone' => 'UTC' ) );
		if ( is_wp_error( $id ) ) $fail( 'customer fixture creation failed' );
		$customer_ids[] = (int) $id;
	}

	$appointments_table = YoBooking\Database\Migrator::table_name( 'appointments' );
	$now = current_time( 'mysql', true );
	$baseline = $reports->summary( '2026-07-01 00:00:00', '2026-07-31 23:59:59' );
	for ( $i = 0; $i < 20; $i++ ) {
		$start = gmdate( 'Y-m-d H:i:s', strtotime( '2026-07-01 09:00:00 UTC +' . $i . ' days' ) );
		$wpdb->insert(
			$appointments_table,
			array( 'uuid' => wp_generate_uuid4(), 'customer_id' => $customer_ids[ $i ], 'service_id' => $service_id, 'staff_id' => $staff_id, 'start_at' => $start, 'end_at' => gmdate( 'Y-m-d H:i:s', strtotime( $start . ' +30 minutes' ) ), 'timezone' => 'UTC', 'status' => $i < 15 ? 'completed' : 'cancelled', 'source' => 'test', 'subtotal_amount' => '100.00', 'total_amount' => '100.00', 'paid_amount' => $i < 10 ? '100.00' : '0.00', 'balance_amount' => $i < 10 ? '0.00' : '100.00', 'currency' => 'USD', 'payment_status' => $i < 10 ? 'paid' : 'pending', 'created_at' => $now, 'updated_at' => $now ),
			array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$appointment_ids[] = (int) $wpdb->insert_id;
	}

	$query_start = microtime( true );
	$page = $customers->page_with_stats( array( 'search' => 'Phase 12 Customer ' . $suffix, 'limit' => 25, 'offset' => 25 ) );
	$query_time = microtime( true ) - $query_start;
	if ( 25 !== count( $page ) || 129 !== $customers->count_matching( 'Phase 12 Customer ' . $suffix ) ) {
		$fail( 'server-side customer pagination is incorrect' );
	}
	if ( $query_time > 2.0 ) $fail( 'customer page query exceeded performance budget' );

	$report_start = microtime( true );
	$summary = $reports->summary( '2026-07-01 00:00:00', '2026-07-31 23:59:59' );
	$report_time = microtime( true ) - $report_start;
	if ( (int) $baseline->total_bookings + 20 !== (int) $summary->total_bookings || (float) $baseline->paid_revenue + 1000.0 !== (float) $summary->paid_revenue || (int) $baseline->cancelled + 5 !== (int) $summary->cancelled ) {
		$fail( 'report aggregates are incorrect' );
	}
	if ( $report_time > 2.0 ) $fail( 'report query exceeded performance budget' );

	$csv = ( new YoBooking\Export\CsvExporter() )->customers( '=Phase 12 Formula' );
	if ( false === strpos( $csv, "'=Phase 12 Formula" ) || false === strpos( $csv, 'Marketing consent' ) ) {
		$fail( 'CSV export or formula protection failed' );
	}

	$_GET = array( 'date_from' => '2026-07-01', 'date_to' => '2026-07-31' );
	ob_start();
	( new YoBooking\Admin\ReportsPage() )->render();
	$report_html = ob_get_clean();
	$_GET = array();
	ob_start();
	( new YoBooking\Admin\AuditLogPage() )->render();
	$audit_html = ob_get_clean();
	if ( false === strpos( $report_html, 'Daily performance' ) || false === strpos( $report_html, 'Export CSV' ) || false === strpos( $audit_html, 'Phase 12 audit event' ) ) {
		$fail( 'production admin page markup is incomplete' );
	}
} catch ( Throwable $exception ) {
	$error = $exception->getMessage();
} finally {
	$_GET = array();
	if ( $appointment_ids ) {
		$ids = implode( ',', array_map( 'absint', $appointment_ids ) );
		$wpdb->query( "DELETE FROM " . YoBooking\Database\Migrator::table_name( 'appointments' ) . " WHERE id IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
	foreach ( $customer_ids as $id ) $customers->delete( $id );
	if ( $staff_id && ! is_wp_error( $staff_id ) ) $staff->delete( $staff_id );
	if ( $service_id && ! is_wp_error( $service_id ) ) $services->delete( $service_id );
	if ( $audit_id ) $audit->delete( $audit_id );
}

if ( $error ) {
	echo 'FAIL: ' . $error . "\n";
	exit( 1 );
}

echo "phase12_production_hardening_smoke=pass\n";
echo 'customer_page_query_ms=' . round( $query_time * 1000, 2 ) . "\n";
echo 'report_query_ms=' . round( $report_time * 1000, 2 ) . "\n";
