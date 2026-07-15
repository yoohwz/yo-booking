<?php
/**
 * Phase 2 CRUD smoke test for WP-CLI.
 *
 * Run with:
 * wp eval-file wp-content/plugins/yo-booking/tests/phase2-crud-smoke.php
 *
 * @package YoBooking
 */

defined( 'ABSPATH' ) || exit;
defined( 'YO_BOOKING_RUNNING_TESTS' ) || define( 'YO_BOOKING_RUNNING_TESTS', true );

$category_repository      = new YoBooking\Repositories\ServiceCategoryRepository();
$service_repository       = new YoBooking\Repositories\ServiceRepository();
$staff_repository         = new YoBooking\Repositories\StaffRepository();
$staff_service_repository = new YoBooking\Repositories\StaffServiceRepository();
$customer_repository      = new YoBooking\Repositories\CustomerRepository();
$suffix                   = gmdate( 'YmdHis' );

$fail = static function ( $message ) {
	echo 'FAIL: ' . $message . "\n";
	exit( 1 );
};

$guard = static function ( $result, $label ) use ( $fail ) {
	if ( is_wp_error( $result ) ) {
		$fail( $label . ': ' . $result->get_error_message() );
	}

	if ( ! absint( $result ) ) {
		$fail( $label . ': missing ID' );
	}

	return absint( $result );
};

$category_id = $guard(
	$category_repository->save(
		array(
			'name'        => 'Smoke Category ' . $suffix,
			'description' => 'Temporary category for Phase 2 smoke testing.',
			'status'      => 'active',
		)
	),
	'create category'
);

$category_id = $guard(
	$category_repository->save(
		array(
			'id'          => $category_id,
			'name'        => 'Smoke Category Updated ' . $suffix,
			'description' => 'Updated temporary category.',
			'status'      => 'active',
		)
	),
	'update category'
);

$service_id = $guard(
	$service_repository->save(
		array(
			'category_id'           => $category_id,
			'name'                  => 'Smoke Service ' . $suffix,
			'description'           => 'Temporary service for Phase 2 smoke testing.',
			'duration_minutes'      => 45,
			'buffer_before_minutes' => 5,
			'buffer_after_minutes'  => 10,
			'price'                 => '89.50',
			'currency'              => 'USD',
			'capacity'              => 1,
			'status'                => 'active',
		)
	),
	'create service'
);

$service_id = $guard(
	$service_repository->save(
		array(
			'id'               => $service_id,
			'category_id'      => $category_id,
			'name'             => 'Smoke Service Updated ' . $suffix,
			'duration_minutes' => 60,
			'price'            => '99.00',
			'currency'         => 'USD',
			'capacity'         => 2,
			'status'           => 'active',
		)
	),
	'update service'
);

$staff_id = $guard(
	$staff_repository->save(
		array(
			'name'   => 'Smoke Staff ' . $suffix,
			'email'  => 'smoke-staff-' . $suffix . '@example.test',
			'phone'  => '+10000000000',
			'status' => 'active',
		)
	),
	'create staff'
);

$staff_service_repository->sync_for_staff( $staff_id, array( $service_id ) );
$assigned_service_ids = $staff_service_repository->service_ids_for_staff( $staff_id );

if ( ! in_array( $service_id, $assigned_service_ids, true ) ) {
	$fail( 'staff service assignment missing' );
}

$customer_id = $guard(
	$customer_repository->save(
		array(
			'name'              => 'Smoke Customer ' . $suffix,
			'email'             => 'smoke-customer-' . $suffix . '@example.test',
			'phone'             => '+19999999999',
			'timezone'          => 'UTC',
			'marketing_consent' => 1,
			'notes'             => 'Temporary customer for Phase 2 smoke testing.',
		)
	),
	'create customer'
);

$customer_id = $guard(
	$customer_repository->save(
		array(
			'id'                => $customer_id,
			'name'              => 'Smoke Customer Updated ' . $suffix,
			'email'             => 'smoke-customer-' . $suffix . '@example.test',
			'phone'             => '+18888888888',
			'timezone'          => 'UTC',
			'marketing_consent' => 0,
		)
	),
	'update customer'
);

$staff_repository->delete( $staff_id );
$service_repository->delete( $service_id );
$category_repository->delete( $category_id );
$customer_repository->delete( $customer_id );

if ( $staff_repository->find( $staff_id ) || $service_repository->find( $service_id ) || $category_repository->find( $category_id ) || $customer_repository->find( $customer_id ) ) {
	$fail( 'temporary records were not deleted' );
}

echo "phase2_crud_smoke=pass\n";
echo 'category_id=' . $category_id . "\n";
echo 'service_id=' . $service_id . "\n";
echo 'staff_id=' . $staff_id . "\n";
echo 'customer_id=' . $customer_id . "\n";
