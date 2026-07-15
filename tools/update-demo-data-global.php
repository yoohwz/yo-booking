<?php
/**
 * Upgrade the existing Yo Booking demo dataset to the global US/EU profile.
 *
 * Run with: wp eval-file wp-content/plugins/yo-booking/tools/update-demo-data-global.php
 *
 * @package YoBooking
 */

defined( 'ABSPATH' ) || exit;

use YoBooking\Repositories\CustomerRepository;
use YoBooking\Repositories\ServiceCategoryRepository;
use YoBooking\Repositories\ServiceRepository;
use YoBooking\Repositories\StaffRepository;

const YO_BOOKING_DEMO_OPTION = 'yo_booking_demo_dataset_v1';

global $wpdb;

$dataset = get_option( YO_BOOKING_DEMO_OPTION, array() );
$ids     = isset( $dataset['ids'] ) && is_array( $dataset['ids'] ) ? $dataset['ids'] : array();

if ( empty( $ids['categories'] ) || empty( $ids['services'] ) || empty( $ids['staff'] ) || empty( $ids['customers'] ) ) {
	echo "FAIL: The tracked demo dataset was not found. Run seed-demo-data.php first.\n";
	exit( 1 );
}

$guard = static function ( $result, $label ) {
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $label . ': ' . $result->get_error_message() );
	}
	if ( ! absint( $result ) ) {
		throw new RuntimeException( $label . ': missing record ID' );
	}
	return absint( $result );
};

$categories = array(
	array( 'Consultations', 'Discovery, assessment, and planning appointments.', 10 ),
	array( 'Treatment Programs', 'Extended professional service programs.', 20 ),
	array( 'Follow-up Services', 'Short recurring customer appointments.', 30 ),
);
$services = array(
	array( 0, 'Initial Consultation', 'A focused assessment and personalized action plan.' ),
	array( 1, 'Advanced Wellness Session', 'A complete professional session with preparation and aftercare.' ),
	array( 1, 'Recovery Program', 'An extended restorative appointment for complex needs.' ),
	array( 2, 'Follow-up Session', 'A concise progress check and plan adjustment.' ),
);
$staff = array(
	array( 'Olivia Carter', 'olivia.carter@example.test', '+1 212 555 0101', 'US consultation lead and customer experience specialist.', '#2563eb' ),
	array( 'James Bennett', 'james.bennett@example.test', '+44 20 7946 0102', 'UK senior specialist focused on long-term programs.', '#16a34a' ),
	array( 'Sofia Mueller', 'sofia.mueller@example.test', '+49 30 9018 0103', 'EU recovery program and follow-up specialist.', '#9333ea' ),
	array( 'Lucas Martin', 'lucas.martin@example.test', '+33 1 84 80 0104', 'France-based practitioner covering consultations and treatments.', '#ea580c' ),
);
$customers = array(
	array( 'Emily Johnson', 'emily.johnson@example.test', '+1 212 555 0201', 'America/New_York', 'New York customer; prefers morning appointments.', 1 ),
	array( 'Michael Brown', 'michael.brown@example.test', '+1 312 555 0202', 'America/Chicago', 'Chicago-based returning customer.', 0 ),
	array( 'Sophie Dubois', 'sophie.dubois@example.test', '+33 1 84 80 0203', 'Europe/Paris', 'Paris customer; contact before appointment changes.', 1 ),
	array( 'Luca Rossi', 'luca.rossi@example.test', '+39 06 9480 0204', 'Europe/Rome', 'Rome customer who usually books follow-up sessions.', 0 ),
	array( 'Emma Fischer', 'emma.fischer@example.test', '+49 30 9018 0205', 'Europe/Berlin', 'Berlin customer interested in recovery programs.', 1 ),
	array( 'Oliver Smith', 'oliver.smith@example.test', '+44 20 7946 0206', 'Europe/London', 'London customer referred by a partner.', 0 ),
	array( 'Isabella Garcia', 'isabella.garcia@example.test', '+34 91 123 0207', 'Europe/Madrid', 'Madrid customer who prefers email communication.', 1 ),
	array( 'Noah Anderson', 'noah.anderson@example.test', '+1 415 555 0208', 'America/Los_Angeles', 'San Francisco customer with a flexible schedule.', 0 ),
	array( 'Anna Kowalski', 'anna.kowalski@example.test', '+48 22 307 0209', 'Europe/Warsaw', 'Warsaw customer who requires a detailed receipt.', 1 ),
	array( 'Thomas de Vries', 'thomas.devries@example.test', '+31 20 808 0210', 'Europe/Amsterdam', 'Amsterdam customer booking an initial consultation.', 0 ),
);

$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
try {
	$category_repository = new ServiceCategoryRepository();
	foreach ( $categories as $index => $record ) {
		$guard( $category_repository->save( array( 'id' => absint( $ids['categories'][ $index] ), 'name' => $record[0], 'description' => $record[1], 'sort_order' => $record[2], 'status' => 'active' ) ), 'Update category' );
	}

	$service_repository = new ServiceRepository();
	foreach ( $services as $index => $record ) {
		$current = $service_repository->find( absint( $ids['services'][ $index] ) );
		if ( ! $current ) {
			throw new RuntimeException( 'Demo service record is missing.' );
		}
		$guard( $service_repository->save( array( 'id' => $current->id, 'category_id' => absint( $ids['categories'][ $record[0] ] ), 'name' => $record[1], 'description' => $record[2], 'duration_minutes' => $current->duration_minutes, 'buffer_before_minutes' => $current->buffer_before_minutes, 'buffer_after_minutes' => $current->buffer_after_minutes, 'price' => $current->price, 'currency' => $current->currency, 'capacity' => $current->capacity, 'color' => $current->color, 'sort_order' => $current->sort_order, 'status' => $current->status ) ), 'Update service' );
	}

	$staff_repository = new StaffRepository();
	foreach ( $staff as $index => $record ) {
		$current = $staff_repository->find( absint( $ids['staff'][ $index] ) );
		$guard( $staff_repository->save( array( 'id' => $current->id, 'user_id' => $current->user_id, 'name' => $record[0], 'email' => $record[1], 'phone' => $record[2], 'bio' => $record[3], 'avatar_id' => $current->avatar_id, 'color' => $record[4], 'sort_order' => $current->sort_order, 'status' => $current->status ) ), 'Update staff' );
	}

	$customer_repository = new CustomerRepository();
	foreach ( $customers as $index => $record ) {
		$current = $customer_repository->find( absint( $ids['customers'][ $index] ) );
		$guard( $customer_repository->save( array( 'id' => $current->id, 'user_id' => $current->user_id, 'name' => $record[0], 'email' => $record[1], 'phone' => $record[2], 'timezone' => $record[3], 'notes' => $record[4], 'marketing_consent' => $record[5] ) ), 'Update customer' );
	}

	$log_ids = array_values( array_map( 'absint', isset( $ids['notification_logs'] ) ? $ids['notification_logs'] : array() ) );
	foreach ( $log_ids as $index => $log_id ) {
		$email = $customers[ $index % count( $customers ) ][1];
		$wpdb->update( YoBooking\Database\Migrator::table_name( 'notification_logs' ), array( 'recipient_email' => $email ), array( 'id' => $log_id ), array( '%s' ), array( '%d' ) );
	}

	$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
} catch ( Throwable $exception ) {
	$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	echo 'FAIL: ' . $exception->getMessage() . "\n";
	exit( 1 );
}

$dataset['version']    = 2;
$dataset['profile']    = 'global-us-eu';
$dataset['updated_at'] = current_time( 'mysql', true );
update_option( YO_BOOKING_DEMO_OPTION, $dataset, false );

echo "yo_booking_demo_global_update=pass\n";
echo 'staff=' . count( $staff ) . "\n";
echo 'customers=' . count( $customers ) . "\n";
echo "profile=global-us-eu\n";
