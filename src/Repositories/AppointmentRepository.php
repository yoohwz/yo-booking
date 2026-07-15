<?php
/**
 * Appointment repository.
 *
 * @package YoBooking
 */

namespace YoBooking\Repositories;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use YoBooking\Availability\AvailabilityEngine;
use YoBooking\Database\Migrator;
use YoBooking\Payments\Currency;
use YoBooking\Payments\Money;
use YoBooking\Payments\PaymentProviderRegistry;
use YoBooking\Payments\PaymentSnapshot;
use YoBooking\Settings\Repository as SettingsRepository;
use YoBooking\Support\StaffAccess;
use YoBooking\Support\Capabilities;
use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Stores appointment lifecycle records.
 */
final class AppointmentRepository extends BaseRepository {
	/**
	 * Logical table suffix.
	 *
	 * @var string
	 */
	protected $table_suffix = 'appointments';

	/**
	 * Return appointment statuses.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'pending'     => __( 'Pending', 'yo-booking' ),
			'confirmed'   => __( 'Confirmed', 'yo-booking' ),
			'cancelled'   => __( 'Cancelled', 'yo-booking' ),
			'completed'   => __( 'Completed', 'yo-booking' ),
			'no_show'     => __( 'No-show', 'yo-booking' ),
			'rescheduled' => __( 'Rescheduled', 'yo-booking' ),
		);
	}

	/**
	 * Return status keys that hold a slot.
	 *
	 * @return array
	 */
	public static function blocking_statuses() {
		return array( 'pending', 'confirmed', 'completed' );
	}

	/**
	 * List appointments with related names.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public function all( array $args = array() ) {
		global $wpdb;

		$table           = $this->table();
		$services_table  = Migrator::table_name( 'services' );
		$staff_table     = Migrator::table_name( 'staff' );
		$customers_table = Migrator::table_name( 'customers' );
		list( $where, $params ) = $this->list_conditions( $args );
		$limit                  = isset( $args['limit'] ) ? max( 1, min( 500, absint( $args['limit'] ) ) ) : 100;
		$offset                 = isset( $args['offset'] ) ? max( 0, absint( $args['offset'] ) ) : 0;

		$sql = "SELECT a.*, COALESCE(NULLIF(a.service_name_snapshot, ''), s.name, '') AS service_name, s.color AS service_color, COALESCE(NULLIF(a.staff_name_snapshot, ''), st.name, '') AS staff_name, st.color AS staff_color, COALESCE(NULLIF(a.customer_name_snapshot, ''), c.name, '') AS customer_name, COALESCE(NULLIF(a.customer_email_snapshot, ''), c.email, '') AS customer_email, COALESCE(NULLIF(a.customer_phone_snapshot, ''), c.phone, '') AS customer_phone
			FROM {$table} a
			LEFT JOIN {$services_table} s ON s.id = a.service_id
			LEFT JOIN {$staff_table} st ON st.id = a.staff_id
			LEFT JOIN {$customers_table} c ON c.id = a.customer_id
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY a.start_at ASC
			LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Count appointments matching list filters.
	 *
	 * @param array $args Query args.
	 * @return int
	 */
	public function count_matching( array $args = array() ) {
		global $wpdb;

		$table           = $this->table();
		$services_table  = Migrator::table_name( 'services' );
		$staff_table     = Migrator::table_name( 'staff' );
		$customers_table = Migrator::table_name( 'customers' );
		list( $where, $params ) = $this->list_conditions( $args );
		$sql = "SELECT COUNT(*)
			FROM {$table} a
			LEFT JOIN {$services_table} s ON s.id = a.service_id
			LEFT JOIN {$staff_table} st ON st.id = a.staff_id
			LEFT JOIN {$customers_table} c ON c.id = a.customer_id
			WHERE " . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) ( empty( $params ) ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) );
	}

	/**
	 * Build shared list query conditions.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	private function list_conditions( array $args ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();
		if ( StaffAccess::restricted() ) {
			$where[]  = 'a.staff_id = %d';
			$params[] = StaffAccess::current_staff_id();
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'a.status = %s';
			$params[] = $this->appointment_status( $args['status'] );
		}

		if ( ! empty( $args['payment_status'] ) && array_key_exists( $args['payment_status'], PaymentRepository::appointment_statuses() ) ) {
			$where[]  = 'a.payment_status = %s';
			$params[] = $args['payment_status'];
		}

		if ( ! empty( $args['service_id'] ) ) {
			$where[]  = 'a.service_id = %d';
			$params[] = absint( $args['service_id'] );
		}

		if ( ! empty( $args['staff_id'] ) ) {
			$where[]  = 'a.staff_id = %d';
			$params[] = absint( $args['staff_id'] );
		}

		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'a.start_at >= %s';
			$params[] = sanitize_text_field( $args['from'] );
		}

		if ( ! empty( $args['to'] ) ) {
			$where[]  = 'a.start_at <= %s';
			$params[] = sanitize_text_field( $args['to'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$like           = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$payments_table = Migrator::table_name( 'payments' );
			$where[]        = "(c.name LIKE %s OR c.email LIKE %s OR c.phone LIKE %s OR s.name LIKE %s OR st.name LIKE %s OR a.payment_reference LIKE %s OR EXISTS (SELECT 1 FROM {$payments_table} p WHERE p.appointment_id = a.id AND p.transaction_id LIKE %s))";
			$params         = array_merge( $params, array( $like, $like, $like, $like, $like, $like, $like ) );
		}

		return array( $where, $params );
	}

	/**
	 * Find one appointment with service, staff, and customer details.
	 *
	 * @param int $id Appointment ID.
	 * @return object|null
	 */
	public function find_with_details( $id ) {
		global $wpdb;

		$id = absint( $id );

		if ( ! $id ) {
			return null;
		}
		if ( ! StaffAccess::can_access_appointment( $id ) ) {
			return null;
		}

		$table           = $this->table();
		$services_table  = Migrator::table_name( 'services' );
		$staff_table     = Migrator::table_name( 'staff' );
		$customers_table = Migrator::table_name( 'customers' );

		$sql = "SELECT a.*, COALESCE(NULLIF(a.service_name_snapshot, ''), s.name, '') AS service_name, s.color AS service_color, COALESCE(NULLIF(a.staff_name_snapshot, ''), st.name, '') AS staff_name, st.color AS staff_color, st.email AS staff_email, COALESCE(NULLIF(a.customer_name_snapshot, ''), c.name, '') AS customer_name, COALESCE(NULLIF(a.customer_email_snapshot, ''), c.email, '') AS customer_email, COALESCE(NULLIF(a.customer_phone_snapshot, ''), c.phone, '') AS customer_phone
			FROM {$table} a
			LEFT JOIN {$services_table} s ON s.id = a.service_id
			LEFT JOIN {$staff_table} st ON st.id = a.staff_id
			LEFT JOIN {$customers_table} c ON c.id = a.customer_id
			WHERE a.id = %d
			LIMIT 1";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_row( $wpdb->prepare( $sql, $id ) );
	}

	/**
	 * Find one appointment with details by UUID.
	 *
	 * @param string $uuid Appointment UUID.
	 * @return object|null
	 */
	public function find_with_details_by_uuid( $uuid ) {
		global $wpdb;

		$uuid = sanitize_text_field( $uuid );

		if ( ! wp_is_uuid( $uuid ) ) {
			return null;
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE uuid = %s LIMIT 1", $uuid ) );

		return $id ? $this->find_with_details( $id ) : null;
	}

	/**
	 * Return appointments in a reminder window.
	 *
	 * @param string $from_utc Window start in UTC.
	 * @param string $to_utc Window end in UTC.
	 * @param int    $limit Maximum rows.
	 * @return array
	 */
	public function for_reminder_window( $from_utc, $to_utc, $limit = 100, $offset = 0 ) {
		global $wpdb;

		$table           = $this->table();
		$services_table  = Migrator::table_name( 'services' );
		$staff_table     = Migrator::table_name( 'staff' );
		$customers_table = Migrator::table_name( 'customers' );
		$limit           = max( 1, min( 500, absint( $limit ) ) );
		$offset          = max( 0, absint( $offset ) );

		$sql = "SELECT a.*, COALESCE(NULLIF(a.service_name_snapshot, ''), s.name, '') AS service_name, COALESCE(NULLIF(a.staff_name_snapshot, ''), st.name, '') AS staff_name, st.email AS staff_email, COALESCE(NULLIF(a.customer_name_snapshot, ''), c.name, '') AS customer_name, COALESCE(NULLIF(a.customer_email_snapshot, ''), c.email, '') AS customer_email, COALESCE(NULLIF(a.customer_phone_snapshot, ''), c.phone, '') AS customer_phone
			FROM {$table} a
			LEFT JOIN {$services_table} s ON s.id = a.service_id
			LEFT JOIN {$staff_table} st ON st.id = a.staff_id
			LEFT JOIN {$customers_table} c ON c.id = a.customer_id
			WHERE a.status IN ('pending', 'confirmed')
				AND a.start_at >= %s
				AND a.start_at < %s
			ORDER BY a.start_at ASC
				LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( $wpdb->prepare( $sql, $from_utc, $to_utc, $limit, $offset ) );
	}

	/**
	 * Return appointment history for a logged-in customer.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $email User email.
	 * @param int    $limit Maximum rows.
	 * @return array
	 */
	public function for_customer_user( $user_id, $email, $limit = 100 ) {
		global $wpdb;

		$user_id         = absint( $user_id );
		$email           = sanitize_email( $email );
		$table           = $this->table();
		$services_table  = Migrator::table_name( 'services' );
		$staff_table     = Migrator::table_name( 'staff' );
		$customers_table = Migrator::table_name( 'customers' );
		$limit           = max( 1, min( 500, absint( $limit ) ) );
		$where           = array();
		$params          = array();

		if ( $user_id ) {
			$where[]  = 'c.user_id = %d';
			$params[] = $user_id;
		} elseif ( $email ) {
			$where[]  = 'c.email = %s';
			$params[] = $email;
		}

		if ( empty( $where ) ) {
			return array();
		}

		$sql = "SELECT a.*, COALESCE(NULLIF(a.service_name_snapshot, ''), s.name, '') AS service_name, COALESCE(NULLIF(a.staff_name_snapshot, ''), st.name, '') AS staff_name, st.email AS staff_email, COALESCE(NULLIF(a.customer_name_snapshot, ''), c.name, '') AS customer_name, COALESCE(NULLIF(a.customer_email_snapshot, ''), c.email, '') AS customer_email, COALESCE(NULLIF(a.customer_phone_snapshot, ''), c.phone, '') AS customer_phone
			FROM {$table} a
			INNER JOIN {$customers_table} c ON c.id = a.customer_id
			LEFT JOIN {$services_table} s ON s.id = a.service_id
			LEFT JOIN {$staff_table} st ON st.id = a.staff_id
			WHERE (" . implode( ' OR ', $where ) . ')
			ORDER BY a.start_at DESC
			LIMIT %d';
		$params[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Return appointment history for one customer profile.
	 *
	 * @param int $customer_id Customer ID.
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public function for_customer_id( $customer_id, $limit = 100 ) {
		global $wpdb;

		$customer_id    = absint( $customer_id );
		$limit          = max( 1, min( 500, absint( $limit ) ) );
		$table          = $this->table();
		$services_table = Migrator::table_name( 'services' );
		$staff_table    = Migrator::table_name( 'staff' );

		if ( ! $customer_id ) {
			return array();
		}

		$sql = "SELECT a.*, COALESCE(NULLIF(a.service_name_snapshot, ''), s.name, '') AS service_name,
			COALESCE(NULLIF(a.staff_name_snapshot, ''), st.name, '') AS staff_name
			FROM {$table} a
			LEFT JOIN {$services_table} s ON s.id = a.service_id
			LEFT JOIN {$staff_table} st ON st.id = a.staff_id
			WHERE a.customer_id = %d
			ORDER BY a.start_at DESC
			LIMIT %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( $wpdb->prepare( $sql, $customer_id, $limit ) );
	}

	/**
	 * Return aggregate customer appointment metrics.
	 *
	 * @param int $customer_id Customer ID.
	 * @return object
	 */
	public function customer_stats( $customer_id ) {
		global $wpdb;

		$customer_id = absint( $customer_id );
		$table       = $this->table();
		$now         = current_time( 'mysql', true );
		$currency    = Currency::normalize( ( new SettingsRepository() )->get( 'company.currency', 'USD' ) );
		$currency    = $currency ? $currency : 'USD';

		if ( ! $customer_id ) {
			return (object) array( 'booking_count' => 0, 'upcoming_count' => 0, 'paid_total' => '0.00', 'cancelled_count' => 0 );
		}

		$sql = "SELECT
			COUNT(*) AS booking_count,
			SUM(CASE WHEN start_at >= %s AND status IN ('pending','confirmed') THEN 1 ELSE 0 END) AS upcoming_count,
			COALESCE(SUM(CASE WHEN currency = %s THEN GREATEST(paid_amount - refunded_amount, 0) ELSE 0 END), 0) AS paid_total,
			SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count
			FROM {$table}
			WHERE customer_id = %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_row( $wpdb->prepare( $sql, $now, $currency, $customer_id ) );
	}

	/**
	 * Save an appointment.
	 *
	 * @param array $data Raw appointment data.
	 * @return int|WP_Error
	 */
	public function save( array $data ) {
		global $wpdb;

		$id       = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
		$existing = $id ? $this->find( $id ) : null;
		if ( $id && ! StaffAccess::can_access_appointment( $id ) ) {
			return new WP_Error( 'yo_booking_appointment_forbidden', __( 'You cannot manage this appointment.', 'yo-booking' ) );
		}
		$status   = $this->appointment_status( isset( $data['status'] ) ? $data['status'] : ( new SettingsRepository() )->get( 'booking.default_status', 'pending' ) );
		if ( $existing && ! current_user_can( Capabilities::manage() ) && ! $this->transition_allowed( (string) $existing->status, $status ) ) {
			return new WP_Error( 'yo_booking_status_transition_invalid', __( 'This appointment status transition is not allowed.', 'yo-booking' ) );
		}
		$service  = ( new ServiceRepository() )->find( isset( $data['service_id'] ) ? absint( $data['service_id'] ) : 0 );

		if ( ! $service || 'active' !== $service->status ) {
			return new WP_Error( 'yo_booking_appointment_service_required', __( 'A valid active service is required.', 'yo-booking' ) );
		}

		$timezone = $this->timezone( isset( $data['timezone'] ) ? $data['timezone'] : '' );
		$date     = isset( $data['date'] ) ? sanitize_text_field( $data['date'] ) : '';
		$time     = isset( $data['start_time'] ) ? sanitize_text_field( $data['start_time'] ) : '';

		if ( ! $this->valid_local_date( $date ) || ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			return new WP_Error( 'yo_booking_appointment_time_invalid', __( 'A valid appointment date and time are required.', 'yo-booking' ) );
		}

		try {
			$start_local = new DateTimeImmutable( $date . ' ' . $time . ':00', $timezone );
		} catch ( Exception $exception ) {
			return new WP_Error( 'yo_booking_appointment_time_invalid', __( 'A valid appointment date and time are required.', 'yo-booking' ) );
		}
		if ( $start_local->format( 'Y-m-d H:i' ) !== $date . ' ' . $time ) {
			return new WP_Error( 'yo_booking_appointment_time_invalid', __( 'This local time does not exist in the selected timezone.', 'yo-booking' ) );
		}

		$duration_minutes = isset( $data['duration_minutes'] ) ? absint( $data['duration_minutes'] ) : absint( $service->duration_minutes );
		$duration_minutes = max( 5, min( 1440, $duration_minutes ) );
		$end_local        = $start_local->add( new DateInterval( 'PT' . $duration_minutes . 'M' ) );
		$staff_id         = isset( $data['staff_id'] ) ? absint( $data['staff_id'] ) : 0;
		if ( StaffAccess::restricted() ) {
			$staff_id = StaffAccess::current_staff_id();
			if ( ! $staff_id ) {
				return new WP_Error( 'yo_booking_staff_profile_required', __( 'Your user account is not linked to an active staff profile.', 'yo-booking' ) );
			}
		}

		if ( in_array( $status, self::blocking_statuses(), true ) ) {
			$slot = $this->matching_slot( $service, $staff_id, $start_local, $end_local, $timezone, $id );

			if ( is_wp_error( $slot ) ) {
				return $slot;
			}

			if ( ! $staff_id && ! empty( $slot['staff_ids'] ) ) {
				$staff_id = absint( $slot['staff_ids'][0] );
			}
		}

		$customer_id = $this->resolve_customer_id( $data, $existing );

		if ( is_wp_error( $customer_id ) ) {
			return $customer_id;
		}
		$customer_snapshot = ( new CustomerRepository() )->find( $customer_id );
		$customer_name     = isset( $data['customer_name'] ) && '' !== trim( (string) $data['customer_name'] ) ? sanitize_text_field( $data['customer_name'] ) : ( $customer_snapshot ? $customer_snapshot->name : '' );
		$customer_email    = isset( $data['customer_email'] ) && '' !== trim( (string) $data['customer_email'] ) ? sanitize_email( $data['customer_email'] ) : ( $customer_snapshot ? $customer_snapshot->email : '' );
		$customer_phone    = isset( $data['customer_phone'] ) && '' !== trim( (string) $data['customer_phone'] ) ? sanitize_text_field( $data['customer_phone'] ) : ( $customer_snapshot ? $customer_snapshot->phone : '' );

		$start_utc = $start_local->setTimezone( new DateTimeZone( 'UTC' ) );
		$end_utc   = $end_local->setTimezone( new DateTimeZone( 'UTC' ) );
		$now       = $this->now();
		$submitted_method = isset( $data['payment_method'] ) ? sanitize_key( $data['payment_method'] ) : '';
		$payment_settings = ( new SettingsRepository() )->all();
		if ( ! $existing && ! empty( $payment_settings['payments']['enabled'] ) && 'deposit' === $payment_settings['payments']['collection_mode'] && 'fixed' === $payment_settings['payments']['deposit_type'] && Currency::normalize( $service->currency ) !== Currency::normalize( $payment_settings['company']['currency'] ) ) {
			return new WP_Error( 'yo_booking_fixed_deposit_currency_mismatch', __( 'Fixed deposits can only be used with services in the default currency. Use a percentage deposit for multi-currency services.', 'yo-booking' ) );
		}
		$snapshot = $existing ? $this->existing_payment_snapshot( $existing, $data, $submitted_method ) : ( new PaymentSnapshot() )->build( $service, $submitted_method );

		$record = array(
			'customer_id'         => $customer_id,
			'service_id'          => (int) $service->id,
			'staff_id'            => $staff_id,
			'customer_name_snapshot' => $customer_name,
			'customer_email_snapshot' => $customer_email,
			'customer_phone_snapshot' => $customer_phone,
			'service_name_snapshot' => sanitize_text_field( $service->name ),
			'staff_name_snapshot' => $staff_id ? $this->staff_name( $staff_id ) : '',
			'location_id'         => isset( $data['location_id'] ) ? absint( $data['location_id'] ) : 0,
			'resource_id'         => isset( $data['resource_id'] ) ? absint( $data['resource_id'] ) : 0,
			'start_at'            => $start_utc->format( 'Y-m-d H:i:s' ),
			'end_at'              => $end_utc->format( 'Y-m-d H:i:s' ),
			'timezone'            => $timezone->getName(),
			'status'              => $status,
			'source'              => isset( $data['source'] ) ? sanitize_key( $data['source'] ) : 'admin',
			'customer_note'       => isset( $data['customer_note'] ) ? wp_kses_post( $data['customer_note'] ) : '',
			'internal_note'       => isset( $data['internal_note'] ) ? wp_kses_post( $data['internal_note'] ) : '',
			'subtotal_amount'     => $snapshot['subtotal_amount'],
			'discount_amount'     => $snapshot['discount_amount'],
			'tax_amount'          => $snapshot['tax_amount'],
			'total_amount'        => $snapshot['total_amount'],
			'currency'            => $snapshot['currency'],
			'payment_method'      => $snapshot['payment_method'],
			'payment_method_title' => $snapshot['payment_method_title'],
			'payment_collection_mode' => $snapshot['payment_collection_mode'],
			'payment_instructions' => $snapshot['payment_instructions'],
			'payment_reference'   => $snapshot['payment_reference'],
			'payment_due_amount'  => $snapshot['payment_due_amount'],
			'paid_amount'         => $snapshot['paid_amount'],
			'refunded_amount'     => $snapshot['refunded_amount'],
			'balance_amount'      => $snapshot['balance_amount'],
			'payment_status'      => $snapshot['payment_status'],
			'cancellation_reason' => isset( $data['cancellation_reason'] ) ? sanitize_text_field( $data['cancellation_reason'] ) : '',
			'cancelled_at'        => 'cancelled' === $status ? ( $existing && $existing->cancelled_at ? $existing->cancelled_at : $now ) : null,
			'created_by'          => $existing ? absint( $existing->created_by ) : get_current_user_id(),
			'updated_at'          => $now,
		);

		$formats = array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' );

		$lock_name = $this->acquire_schedule_lock( $service, $staff_id, $start_local );
		if ( is_wp_error( $lock_name ) ) {
			return $lock_name;
		}

		if ( in_array( $status, self::blocking_statuses(), true ) ) {
			$locked_slot = $this->matching_slot( $service, $staff_id, $start_local, $end_local, $timezone, $id );
			if ( is_wp_error( $locked_slot ) ) {
				$this->release_schedule_lock( $lock_name );
				return $locked_slot;
			}
		}

		if ( $id ) {
			$updated = $wpdb->update( $this->table(), $record, array( 'id' => $id ), $formats, array( '%d' ) );

			if ( false === $updated ) {
				$this->release_schedule_lock( $lock_name );
				return $this->database_error();
			}
			$this->release_schedule_lock( $lock_name );

			( new PaymentRepository() )->recalculate_appointment( $id );

			$this->dispatch_save_events( $id, $existing, $record );
			do_action( 'yo_booking_appointment_updated', $id, $existing, $this->find_with_details( $id ) );

			return $id;
		}

		$record['uuid']       = wp_generate_uuid4();
		$record['created_at'] = $now;

		$insert_record = array_merge(
			array( 'uuid' => $record['uuid'] ),
			array_diff_key( $record, array( 'uuid' => true ) )
		);
		$insert_formats = array_merge( array( '%s' ), $formats, array( '%s' ) );

		$inserted = $wpdb->insert( $this->table(), $insert_record, $insert_formats );

		if ( false === $inserted ) {
			$this->release_schedule_lock( $lock_name );
			return $this->database_error();
		}
		$this->release_schedule_lock( $lock_name );

		$appointment_id = (int) $wpdb->insert_id;

		do_action( 'yo_booking_appointment_created', $appointment_id, null );

		return $appointment_id;
	}

	/**
	 * Update lifecycle status.
	 *
	 * @param int    $id Appointment ID.
	 * @param string $status New status.
	 * @param string $reason Optional cancellation reason.
	 * @param bool   $override Allow an administrator to bypass the normal transition graph.
	 * @return bool|WP_Error
	 */
	public function update_status( $id, $status, $reason = '', $override = false ) {
		global $wpdb;

		$id          = absint( $id );
		$appointment = $this->find( $id );

		if ( ! $appointment ) {
			return new WP_Error( 'yo_booking_appointment_not_found', __( 'Appointment not found.', 'yo-booking' ) );
		}
		if ( ! StaffAccess::can_access_appointment( $id ) ) {
			return new WP_Error( 'yo_booking_appointment_forbidden', __( 'You cannot manage this appointment.', 'yo-booking' ) );
		}

		$status = $this->appointment_status( $status );
		if ( ! $override && ! current_user_can( Capabilities::manage() ) && ! $this->transition_allowed( (string) $appointment->status, $status ) ) {
			return new WP_Error( 'yo_booking_status_transition_invalid', __( 'This appointment status transition is not allowed.', 'yo-booking' ) );
		}

		$lock_name = '';
		if ( in_array( $status, self::blocking_statuses(), true ) && ! in_array( $appointment->status, self::blocking_statuses(), true ) ) {
			$service = ( new ServiceRepository() )->find( (int) $appointment->service_id );

			if ( ! $service ) {
				return new WP_Error( 'yo_booking_appointment_service_required', __( 'A valid active service is required.', 'yo-booking' ) );
			}

			$timezone    = $this->timezone( $appointment->timezone );
			$start_local = ( new DateTimeImmutable( $appointment->start_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( $timezone );
			$end_local   = ( new DateTimeImmutable( $appointment->end_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( $timezone );
			$lock_name   = $this->acquire_schedule_lock( $service, (int) $appointment->staff_id, $start_local );
			if ( is_wp_error( $lock_name ) ) {
				return $lock_name;
			}
			$slot        = $this->matching_slot( $service, (int) $appointment->staff_id, $start_local, $end_local, $timezone, $id );

			if ( is_wp_error( $slot ) ) {
				$this->release_schedule_lock( $lock_name );
				return $slot;
			}
		}

		$record = array(
			'status'     => $status,
			'updated_at' => $this->now(),
		);
		$formats = array( '%s', '%s' );

		if ( 'cancelled' === $status ) {
			$record['cancellation_reason'] = sanitize_text_field( $reason );
			$record['cancelled_at']        = $this->now();
			$formats[]                     = '%s';
			$formats[]                     = '%s';
		}

		$old_status = (string) $appointment->status;
		$updated    = $wpdb->update( $this->table(), $record, array( 'id' => $id ), $formats, array( '%d' ) );

		if ( false === $updated ) {
			$this->release_schedule_lock( $lock_name );
			return $this->database_error();
		}
		$this->release_schedule_lock( $lock_name );

		if ( $old_status !== $status ) {
			do_action( 'yo_booking_appointment_status_changed', $id, $status, $old_status, $appointment );
		}

		return true;
	}

	/**
	 * Update internal notes.
	 *
	 * @param int    $id Appointment ID.
	 * @param string $note Internal note.
	 * @return bool|WP_Error
	 */
	public function update_internal_note( $id, $note ) {
		global $wpdb;

		$id = absint( $id );

		if ( ! $this->find( $id ) ) {
			return new WP_Error( 'yo_booking_appointment_not_found', __( 'Appointment not found.', 'yo-booking' ) );
		}
		if ( ! StaffAccess::can_access_appointment( $id ) ) {
			return new WP_Error( 'yo_booking_appointment_forbidden', __( 'You cannot manage this appointment.', 'yo-booking' ) );
		}

		$updated = $wpdb->update(
			$this->table(),
			array(
				'internal_note' => wp_kses_post( $note ),
				'updated_at'    => $this->now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false === $updated ? $this->database_error() : true;
	}

	/**
	 * Find a slot matching the requested appointment time.
	 *
	 * @param object            $service Service row.
	 * @param int               $staff_id Staff ID.
	 * @param DateTimeImmutable $start_local Local start.
	 * @param DateTimeImmutable $end_local Local end.
	 * @param DateTimeZone      $timezone Timezone.
	 * @param int               $exclude_appointment_id Appointment ID to ignore.
	 * @return array|WP_Error
	 */
	private function matching_slot( $service, $staff_id, DateTimeImmutable $start_local, DateTimeImmutable $end_local, DateTimeZone $timezone, $exclude_appointment_id ) {
		$result = ( new AvailabilityEngine() )->available_times(
			array(
				'service_id'              => (int) $service->id,
				'staff_id'                => $staff_id,
				'date'                    => $start_local->format( 'Y-m-d' ),
				'timezone'                => $timezone->getName(),
				'exclude_appointment_id'  => absint( $exclude_appointment_id ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$start_utc = $start_local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$end_utc   = $end_local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

		foreach ( $result['slots'] as $slot ) {
			if ( $slot['start_utc'] !== $start_utc || $slot['end_utc'] !== $end_utc ) {
				continue;
			}

			if ( $staff_id && ! in_array( $staff_id, array_map( 'absint', $slot['staff_ids'] ), true ) ) {
				continue;
			}

			return $slot;
		}

		return new WP_Error( 'yo_booking_appointment_slot_unavailable', __( 'The requested appointment slot is not available.', 'yo-booking' ) );
	}

	/**
	 * Dispatch lifecycle events after saving an existing appointment.
	 *
	 * @param int    $id Appointment ID.
	 * @param object $existing Existing row before update.
	 * @param array  $record Saved record data.
	 * @return void
	 */
	private function dispatch_save_events( $id, $existing, array $record ) {
		if ( ! $existing ) {
			return;
		}

		$time_changed = (string) $existing->start_at !== (string) $record['start_at']
			|| (string) $existing->end_at !== (string) $record['end_at']
			|| (int) $existing->staff_id !== (int) $record['staff_id']
			|| (int) $existing->service_id !== (int) $record['service_id'];
		$status_changed = (string) $existing->status !== (string) $record['status'];

		if ( $time_changed && 'rescheduled' !== $record['status'] ) {
			do_action( 'yo_booking_appointment_rescheduled', $id, $existing, $this->find_with_details( $id ) );
		}

		if ( $status_changed ) {
			do_action( 'yo_booking_appointment_status_changed', $id, $record['status'], $existing->status, $existing );
		}
	}

	/**
	 * Resolve or create a customer for an appointment.
	 *
	 * @param array       $data Raw appointment data.
	 * @param object|null $existing Existing appointment row.
	 * @return int|WP_Error
	 */
	private function resolve_customer_id( array $data, $existing = null ) {
		$customers   = new CustomerRepository();
		$customer_id = isset( $data['customer_id'] ) ? absint( $data['customer_id'] ) : 0;

		if ( $customer_id && $customers->find( $customer_id ) ) {
			return $customer_id;
		}

		if ( $existing && $existing->customer_id ) {
			$customer_id = absint( $existing->customer_id );
		}

		$name    = isset( $data['customer_name'] ) ? sanitize_text_field( $data['customer_name'] ) : '';
		$email   = isset( $data['customer_email'] ) ? sanitize_email( $data['customer_email'] ) : '';
		$phone   = isset( $data['customer_phone'] ) ? sanitize_text_field( $data['customer_phone'] ) : '';
		$user_id = isset( $data['customer_user_id'] ) ? absint( $data['customer_user_id'] ) : ( isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0 );

		$matched_contact = false;
		if ( ! $customer_id ) {
			$existing_customer = $user_id ? $customers->find_by_user_id( $user_id ) : $customers->find_by_contact( $email, $phone );
			$customer_id       = $existing_customer ? absint( $existing_customer->id ) : 0;
			$matched_contact   = $customer_id && ! $user_id;
		}

		if ( '' === $name && ! $customer_id ) {
			return new WP_Error( 'yo_booking_appointment_customer_required', __( 'Customer name is required.', 'yo-booking' ) );
		}

			if ( $customer_id ) {
				// Guest contact matching may associate history, but it must never mutate another profile.
				if ( $matched_contact ) {
					if ( ! empty( $data['marketing_consent'] ) ) {
						$customers->grant_marketing_consent( $customer_id );
					}
					return $customer_id;
			}
			$customer = $customers->find( $customer_id );
			return $customers->save(
				array(
					'id'       => $customer_id,
					'user_id'  => $user_id ? $user_id : ( $customer ? $customer->user_id : 0 ),
					'name'     => $name ? $name : ( $customer ? $customer->name : '' ),
					'email'    => $email ? $email : ( $customer ? $customer->email : '' ),
					'phone'    => $phone ? $phone : ( $customer ? $customer->phone : '' ),
						'timezone' => isset( $data['timezone'] ) ? $data['timezone'] : ( $customer ? $customer->timezone : '' ),
						'notes'    => $customer ? $customer->notes : '',
						'marketing_consent' => ! empty( $data['marketing_consent'] ) || ( $customer && ! empty( $customer->marketing_consent ) ),
				)
			);
		}

		return $customers->save(
			array(
				'name'     => $name,
				'user_id'  => $user_id,
				'email'    => $email,
				'phone'    => $phone,
					'timezone' => isset( $data['timezone'] ) ? $data['timezone'] : '',
					'marketing_consent' => ! empty( $data['marketing_consent'] ),
			)
		);
	}

	/** @param string $from Current status. @param string $to Requested status. @return bool */
	private function transition_allowed( $from, $to ) {
		if ( $from === $to ) {
			return true;
		}

		$graph = array(
			'pending'   => array( 'confirmed', 'cancelled', 'completed', 'no_show', 'rescheduled' ),
			'confirmed' => array( 'cancelled', 'completed', 'no_show', 'rescheduled' ),
		);

		return isset( $graph[ $from ] ) && in_array( $to, $graph[ $from ], true );
	}

	/** @param string $date Date in Y-m-d. @return bool */
	private function valid_local_date( $date ) {
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date );
		return $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}

	/** @param int $staff_id Staff ID. @return string */
	private function staff_name( $staff_id ) {
		$staff = ( new StaffRepository() )->find( absint( $staff_id ) );
		return $staff ? sanitize_text_field( $staff->name ) : '';
	}

	/** @return string|WP_Error */
	private function acquire_schedule_lock( $service, $staff_id, DateTimeImmutable $start ) {
		global $wpdb;
		$scope = $staff_id ? 'staff:' . absint( $staff_id ) : 'service:' . absint( $service->id );
		$name  = 'yo_booking:' . md5( $scope . ':' . $start->format( 'Y-m-d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$locked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) );
		return 1 === $locked ? $name : new WP_Error( 'yo_booking_schedule_busy', __( 'The schedule is busy. Please try again.', 'yo-booking' ) );
	}

	/** @param string $name Lock name. @return void */
	private function release_schedule_lock( $name ) {
		global $wpdb;
		if ( $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * Normalize appointment status.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private function appointment_status( $status ) {
		$status = sanitize_key( $status );

		return array_key_exists( $status, self::statuses() ) ? $status : 'pending';
	}

	/**
	 * Preserve an existing payment snapshot, applying only explicit admin edits.
	 *
	 * @param object $appointment Existing appointment.
	 * @param array  $data Submitted data.
	 * @param string $submitted_method Submitted method ID.
	 * @return array
	 */
	private function existing_payment_snapshot( $appointment, array $data, $submitted_method ) {
		$currency = Currency::normalize( isset( $appointment->currency ) ? $appointment->currency : '' );
		$currency = $currency ? $currency : 'USD';
		$total_changed = isset( $data['total_amount'] );
		$total    = $total_changed ? Money::normalize( $data['total_amount'], $currency ) : Money::normalize( $appointment->total_amount, $currency );
		$method   = $submitted_method ? ( new PaymentProviderRegistry() )->get( $submitted_method, false ) : null;
		$old_method = isset( $appointment->payment_method ) ? sanitize_key( $appointment->payment_method ) : 'local';
		$old_title  = isset( $appointment->payment_method_title ) ? (string) $appointment->payment_method_title : '';
		$old_instructions = isset( $appointment->payment_instructions ) ? (string) $appointment->payment_instructions : '';
		$total_minor = Money::to_minor( $total, $currency );
		$paid_minor = Money::to_minor( isset( $appointment->paid_amount ) ? $appointment->paid_amount : 0, $currency );
		$refunded_minor = Money::to_minor( isset( $appointment->refunded_amount ) ? $appointment->refunded_amount : 0, $currency );
		$balance_minor = max( 0, $total_minor - $paid_minor + $refunded_minor );
		$due_minor = min( $total_minor, Money::to_minor( isset( $appointment->payment_due_amount ) ? $appointment->payment_due_amount : 0, $currency ) );

		return array(
			'subtotal_amount'        => $total_changed ? $total : Money::normalize( isset( $appointment->subtotal_amount ) ? $appointment->subtotal_amount : $total, $currency ),
			'discount_amount'        => $total_changed ? Money::from_minor( 0, $currency ) : Money::normalize( isset( $appointment->discount_amount ) ? $appointment->discount_amount : 0, $currency ),
			'tax_amount'             => $total_changed ? Money::from_minor( 0, $currency ) : Money::normalize( isset( $appointment->tax_amount ) ? $appointment->tax_amount : 0, $currency ),
			'total_amount'           => $total,
			'currency'               => $currency,
			'payment_method'         => $method ? $method->id() : $old_method,
			'payment_method_title'   => $method ? $method->title() : $old_title,
			'payment_collection_mode' => isset( $appointment->payment_collection_mode ) ? sanitize_key( $appointment->payment_collection_mode ) : 'none',
			'payment_instructions'   => $method ? $method->instructions() : $old_instructions,
			'payment_reference'      => ! empty( $appointment->payment_reference ) ? (string) $appointment->payment_reference : 'YB-' . gmdate( 'Ymd', strtotime( $appointment->created_at . ' UTC' ) ) . '-' . (int) $appointment->id,
			'payment_due_amount'     => Money::from_minor( $due_minor, $currency ),
			'paid_amount'            => Money::from_minor( $paid_minor, $currency ),
			'refunded_amount'        => Money::from_minor( $refunded_minor, $currency ),
			'balance_amount'         => Money::from_minor( $balance_minor, $currency ),
			'payment_status'         => $this->payment_status( isset( $appointment->payment_status ) ? $appointment->payment_status : 'pending' ),
		);
	}

	/**
	 * Normalize payment status.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private function payment_status( $status ) {
		$status = sanitize_key( $status );

		$statuses = array( 'pending', 'authorized', 'partially_paid', 'paid', 'failed', 'cancelled', 'partially_refunded', 'refunded' );
		return in_array( $status, $statuses, true ) ? $status : 'pending';
	}

	/**
	 * Resolve a timezone.
	 *
	 * @param string $timezone Raw timezone.
	 * @return DateTimeZone
	 */
	private function timezone( $timezone ) {
		$settings = ( new SettingsRepository() )->all();
		$name     = $timezone ? sanitize_text_field( $timezone ) : $settings['company']['timezone'];

		try {
			return new DateTimeZone( $name ? $name : 'UTC' );
		} catch ( Exception $exception ) {
			return new DateTimeZone( 'UTC' );
		}
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
