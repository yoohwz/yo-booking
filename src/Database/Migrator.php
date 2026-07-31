<?php
/**
 * Versioned database migrator.
 *
 * @package YoBooking
 */

namespace YoBooking\Database;

use YoBooking\Repositories\AvailabilityRuleRepository;
use YoBooking\Repositories\PaymentRepository;
use YoBooking\Settings\Repository as SettingsRepository;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Owns schema installation and future migrations.
 */
final class Migrator {
	/**
	 * Current schema version.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION = '2026073101';

	/** Maximum age for a stale migration lock. */
	const MIGRATION_LOCK_TTL = 300;

	/**
	 * Run pending migrations when the stored version is stale.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		$installed_version = get_option( 'yo_booking_schema_version', '' );

		if ( self::SCHEMA_VERSION !== $installed_version ) {
			$lock = (int) get_option( 'yo_booking_migration_lock', 0 );
			if ( $lock && time() - $lock < self::MIGRATION_LOCK_TTL ) {
				return;
			}

			delete_option( 'yo_booking_migration_lock' );
			if ( ! add_option( 'yo_booking_migration_lock', time(), '', false ) ) {
				return;
			}

			try {
				$this->install();
			} finally {
				delete_option( 'yo_booking_migration_lock' );
			}
			return;
		}

		if ( YO_BOOKING_VERSION !== get_option( 'yo_booking_version', '' ) ) {
			$this->set_option( 'yo_booking_version', YO_BOOKING_VERSION );
		}
	}

	/**
	 * Install or upgrade the current schema.
	 *
	 * @return void
	 */
	public function install() {
		$this->create_tables();
		$this->migrate_payment_data();
		$this->backfill_appointment_snapshots();
		$this->set_option( 'yo_booking_schema_version', self::SCHEMA_VERSION );
		$this->set_option( 'yo_booking_version', YO_BOOKING_VERSION );
		$this->set_option( 'yo_booking_clean_schema_installed', 'yes' );

		if ( false === get_option( 'yo_booking_installed_at', false ) ) {
			$this->set_option( 'yo_booking_installed_at', gmdate( 'Y-m-d H:i:s' ) );
		}

		( new SettingsRepository() )->seed_defaults();
		$this->seed_notification_templates();
		$this->seed_default_availability_rules();
	}

	/**
	 * Return a plugin table name with the WordPress prefix.
	 *
	 * @param string $name Logical table suffix.
	 * @return string
	 */
	public static function table_name( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'yo_booking_' . sanitize_key( $name );
	}

	/**
	 * Return the tables owned by the plugin.
	 *
	 * @return array
	 */
	public static function managed_tables() {
		return array(
			self::table_name( 'service_categories' ),
			self::table_name( 'services' ),
			self::table_name( 'staff' ),
			self::table_name( 'staff_services' ),
			self::table_name( 'locations' ),
			self::table_name( 'resources' ),
			self::table_name( 'availability_rules' ),
			self::table_name( 'availability_exceptions' ),
			self::table_name( 'customers' ),
			self::table_name( 'appointments' ),
			self::table_name( 'appointment_meta' ),
			self::table_name( 'payments' ),
			self::table_name( 'notifications' ),
			self::table_name( 'notification_logs' ),
			self::table_name( 'audit_logs' ),
			self::table_name( 'webhook_endpoints' ),
			self::table_name( 'webhook_deliveries' ),
			self::table_name( 'api_keys' ),
		);
	}

	/**
	 * Create or update plugin tables.
	 *
	 * @return void
	 */
	private function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$tables = array(
			"CREATE TABLE " . self::table_name( 'service_categories' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(191) NOT NULL,
				slug varchar(191) NOT NULL,
				description longtext DEFAULT NULL,
				sort_order int(11) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY parent_id (parent_id),
				KEY status (status),
				KEY sort_order (sort_order)
			) $charset_collate;",
			"CREATE TABLE " . self::table_name( 'services' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				category_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(191) NOT NULL,
				slug varchar(191) NOT NULL,
				description longtext DEFAULT NULL,
				duration_minutes int(10) unsigned NOT NULL DEFAULT 60,
				buffer_before_minutes int(10) unsigned NOT NULL DEFAULT 0,
				buffer_after_minutes int(10) unsigned NOT NULL DEFAULT 0,
				price decimal(16,4) NOT NULL DEFAULT 0.0000,
				currency char(3) NOT NULL DEFAULT '',
				capacity int(10) unsigned NOT NULL DEFAULT 1,
				color varchar(20) NOT NULL DEFAULT '',
				sort_order int(11) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY category_id (category_id),
				KEY status (status),
				KEY sort_order (sort_order)
			) $charset_collate;",
			"CREATE TABLE " . self::table_name( 'staff' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(191) NOT NULL,
				slug varchar(191) NOT NULL,
				email varchar(191) NOT NULL DEFAULT '',
				phone varchar(50) NOT NULL DEFAULT '',
				phone_country char(2) NOT NULL DEFAULT '',
				bio longtext DEFAULT NULL,
				avatar_id bigint(20) unsigned NOT NULL DEFAULT 0,
				color varchar(20) NOT NULL DEFAULT '',
				sort_order int(11) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY user_id (user_id),
				KEY email (email),
				KEY status (status),
				KEY sort_order (sort_order)
			) $charset_collate;",
			"CREATE TABLE " . self::table_name( 'staff_services' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				staff_id bigint(20) unsigned NOT NULL,
				service_id bigint(20) unsigned NOT NULL,
				duration_minutes int(10) unsigned DEFAULT NULL,
					price decimal(16,4) DEFAULT NULL,
				enabled tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY staff_service (staff_id,service_id),
				KEY staff_id (staff_id),
				KEY service_id (service_id),
				KEY enabled (enabled)
			) $charset_collate;",
			"CREATE TABLE " . self::table_name( 'locations' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				slug varchar(191) NOT NULL,
				email varchar(191) NOT NULL DEFAULT '',
				phone varchar(50) NOT NULL DEFAULT '',
				address_line_1 varchar(191) NOT NULL DEFAULT '',
				address_line_2 varchar(191) NOT NULL DEFAULT '',
				city varchar(100) NOT NULL DEFAULT '',
				state varchar(100) NOT NULL DEFAULT '',
				postal_code varchar(30) NOT NULL DEFAULT '',
				country char(2) NOT NULL DEFAULT '',
				timezone varchar(64) NOT NULL DEFAULT '',
				sort_order int(11) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY country (country),
				KEY status (status),
				KEY sort_order (sort_order)
			) $charset_collate;",
			"CREATE TABLE " . self::table_name( 'resources' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				location_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(191) NOT NULL,
				slug varchar(191) NOT NULL,
				type varchar(50) NOT NULL DEFAULT '',
				capacity int(10) unsigned NOT NULL DEFAULT 1,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY location_id (location_id),
				KEY type (type),
				KEY status (status)
			) $charset_collate;",
			"CREATE TABLE " . self::table_name( 'availability_rules' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				owner_type varchar(20) NOT NULL DEFAULT 'global',
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				weekday tinyint(1) unsigned NOT NULL,
				start_time time NOT NULL,
				end_time time NOT NULL,
				slot_interval_minutes int(10) unsigned NOT NULL DEFAULT 15,
				timezone varchar(64) NOT NULL DEFAULT '',
				valid_from date DEFAULT NULL,
				valid_to date DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY owner (owner_type,owner_id),
				KEY weekday (weekday),
				KEY status (status)
			) $charset_collate;",
			"CREATE TABLE " . self::table_name( 'availability_exceptions' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				owner_type varchar(20) NOT NULL DEFAULT 'global',
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				exception_date date NOT NULL,
				start_time time DEFAULT NULL,
				end_time time DEFAULT NULL,
				availability_type varchar(20) NOT NULL DEFAULT 'blocked',
				reason varchar(191) NOT NULL DEFAULT '',
				timezone varchar(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY owner_date (owner_type,owner_id,exception_date),
				KEY exception_date (exception_date),
				KEY availability_type (availability_type)
			) $charset_collate;",
			"CREATE TABLE " . self::table_name( 'customers' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(191) NOT NULL,
				email varchar(191) NOT NULL DEFAULT '',
				phone varchar(50) NOT NULL DEFAULT '',
				phone_country char(2) NOT NULL DEFAULT '',
				timezone varchar(64) NOT NULL DEFAULT '',
				notes longtext DEFAULT NULL,
				marketing_consent tinyint(1) NOT NULL DEFAULT 0,
				last_seen_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY email (email),
				KEY phone (phone)
			) $charset_collate;",
			"CREATE TABLE " . self::table_name( 'appointments' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid varchar(36) NOT NULL,
					customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
					service_id bigint(20) unsigned NOT NULL DEFAULT 0,
					staff_id bigint(20) unsigned NOT NULL DEFAULT 0,
					customer_name_snapshot varchar(191) NOT NULL DEFAULT '',
					customer_email_snapshot varchar(191) NOT NULL DEFAULT '',
					customer_phone_snapshot varchar(50) NOT NULL DEFAULT '',
					customer_phone_country_snapshot char(2) NOT NULL DEFAULT '',
					service_name_snapshot varchar(191) NOT NULL DEFAULT '',
					staff_name_snapshot varchar(191) NOT NULL DEFAULT '',
					location_id bigint(20) unsigned NOT NULL DEFAULT 0,
				resource_id bigint(20) unsigned NOT NULL DEFAULT 0,
				start_at datetime NOT NULL,
				end_at datetime NOT NULL,
				timezone varchar(64) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
				source varchar(20) NOT NULL DEFAULT 'frontend',
				customer_note longtext DEFAULT NULL,
				internal_note longtext DEFAULT NULL,
				subtotal_amount decimal(16,4) NOT NULL DEFAULT 0.0000,
				discount_amount decimal(16,4) NOT NULL DEFAULT 0.0000,
				tax_amount decimal(16,4) NOT NULL DEFAULT 0.0000,
				total_amount decimal(16,4) NOT NULL DEFAULT 0.0000,
				currency char(3) NOT NULL DEFAULT '',
				payment_method varchar(40) NOT NULL DEFAULT 'local',
				payment_method_title varchar(191) NOT NULL DEFAULT '',
				payment_collection_mode varchar(20) NOT NULL DEFAULT 'none',
				payment_instructions longtext DEFAULT NULL,
				payment_reference varchar(64) DEFAULT NULL,
				payment_due_amount decimal(16,4) NOT NULL DEFAULT 0.0000,
				paid_amount decimal(16,4) NOT NULL DEFAULT 0.0000,
				refunded_amount decimal(16,4) NOT NULL DEFAULT 0.0000,
				balance_amount decimal(16,4) NOT NULL DEFAULT 0.0000,
				payment_status varchar(24) NOT NULL DEFAULT 'pending',
				cancellation_reason varchar(191) NOT NULL DEFAULT '',
					cancelled_at datetime DEFAULT NULL,
					action_token_version int(10) unsigned NOT NULL DEFAULT 1,
					created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				KEY customer_id (customer_id),
				KEY service_id (service_id),
				KEY staff_id (staff_id),
				KEY location_id (location_id),
				KEY resource_id (resource_id),
				KEY start_at (start_at),
				KEY end_at (end_at),
				KEY status (status),
					KEY payment_method (payment_method),
					UNIQUE KEY payment_reference (payment_reference),
					KEY payment_status (payment_status),
					KEY staff_schedule (staff_id,status,start_at,end_at),
					KEY service_schedule (service_id,status,start_at,end_at),
					KEY customer_schedule (customer_id,start_at)
			) $charset_collate;",
			"CREATE TABLE " . self::table_name( 'appointment_meta' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				appointment_id bigint(20) unsigned NOT NULL,
				meta_key varchar(191) NOT NULL,
				meta_value longtext DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY appointment_id (appointment_id),
				KEY meta_key (meta_key)
			) $charset_collate;",
			"CREATE TABLE " . self::table_name( 'payments' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				appointment_id bigint(20) unsigned NOT NULL,
				provider varchar(40) NOT NULL DEFAULT 'local',
					transaction_id varchar(191) NOT NULL DEFAULT '',
					provider_transaction_key varchar(191) DEFAULT NULL,
				kind varchar(20) NOT NULL DEFAULT 'payment',
				amount decimal(16,4) NOT NULL DEFAULT 0.0000,
				currency char(3) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
				idempotency_key varchar(191) DEFAULT NULL,
				method_title varchar(191) NOT NULL DEFAULT '',
				note longtext DEFAULT NULL,
				gateway_metadata longtext DEFAULT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				processed_at datetime DEFAULT NULL,
				paid_at datetime DEFAULT NULL,
				refunded_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY appointment_id (appointment_id),
				KEY provider (provider),
					KEY transaction_id (transaction_id),
					UNIQUE KEY provider_transaction_key (provider_transaction_key),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY status (status),
				KEY paid_at (paid_at),
				KEY refunded_at (refunded_at)
			) $charset_collate;",
			"CREATE TABLE " . self::table_name( 'notifications' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				notification_key varchar(64) NOT NULL,
				event varchar(64) NOT NULL,
				recipient_type varchar(20) NOT NULL DEFAULT 'admin',
				enabled tinyint(1) NOT NULL DEFAULT 1,
				subject varchar(191) NOT NULL DEFAULT '',
				heading varchar(191) NOT NULL DEFAULT '',
				body longtext DEFAULT NULL,
				email_type varchar(20) NOT NULL DEFAULT 'html',
				send_ics tinyint(1) NOT NULL DEFAULT 0,
				timing_offset_minutes int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY notification_key (notification_key),
				KEY event (event),
				KEY recipient_type (recipient_type),
				KEY enabled (enabled)
			) $charset_collate;",
			"CREATE TABLE " . self::table_name( 'notification_logs' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				notification_key varchar(64) NOT NULL,
				event varchar(64) NOT NULL,
				appointment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				recipient_type varchar(20) NOT NULL DEFAULT '',
				recipient_email varchar(191) NOT NULL DEFAULT '',
				subject varchar(191) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
					error_message longtext DEFAULT NULL,
					occurrence_key varchar(191) DEFAULT NULL,
				sent_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY notification_key (notification_key),
				KEY event (event),
				KEY appointment_id (appointment_id),
				KEY recipient_email (recipient_email),
					KEY status (status),
					KEY sent_at (sent_at),
					UNIQUE KEY occurrence_key (occurrence_key)
			) $charset_collate;",
			"CREATE TABLE " . self::table_name( 'audit_logs' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				action varchar(64) NOT NULL,
				object_type varchar(40) NOT NULL,
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				summary varchar(191) NOT NULL DEFAULT '',
				context longtext DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY actor_user_id (actor_user_id),
				KEY action (action),
				KEY object (object_type,object_id),
				KEY created_at (created_at)
			) $charset_collate;",
			"CREATE TABLE " . self::table_name( 'webhook_endpoints' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				url varchar(500) NOT NULL,
				secret_encrypted longtext DEFAULT NULL,
				events longtext DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				timeout_seconds int(10) unsigned NOT NULL DEFAULT 10,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY created_by (created_by)
			) $charset_collate;",
			"CREATE TABLE " . self::table_name( 'webhook_deliveries' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				endpoint_id bigint(20) unsigned NOT NULL,
				event varchar(64) NOT NULL,
				object_type varchar(40) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				payload longtext NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts int(10) unsigned NOT NULL DEFAULT 0,
				response_code int(10) unsigned NOT NULL DEFAULT 0,
				response_body longtext DEFAULT NULL,
				error_message longtext DEFAULT NULL,
				next_attempt_at datetime DEFAULT NULL,
				delivered_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY endpoint_id (endpoint_id),
				KEY event (event),
				KEY object (object_type,object_id),
				KEY status_next (status,next_attempt_at),
				KEY created_at (created_at)
			) $charset_collate;",
			"CREATE TABLE " . self::table_name( 'api_keys' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				key_prefix varchar(32) NOT NULL,
				key_hash varchar(64) NOT NULL,
				capabilities longtext DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				expires_at datetime DEFAULT NULL,
				last_used_at datetime DEFAULT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY key_prefix (key_prefix),
				KEY status (status),
				KEY expires_at (expires_at),
				KEY created_by (created_by)
			) $charset_collate;",
		);

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Backfill immutable payment fields introduced after the clean rebuild.
	 *
	 * @return void
	 */
	private function migrate_payment_data() {
		global $wpdb;

		$table = self::table_name( 'appointments' );
		$payments_table = self::table_name( 'payments' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( "UPDATE {$table} SET payment_status = 'pending' WHERE payment_status = 'unpaid'" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( "UPDATE {$table} SET subtotal_amount = total_amount WHERE subtotal_amount = 0 AND total_amount > 0" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( "UPDATE {$table} SET balance_amount = GREATEST(total_amount - paid_amount + refunded_amount, 0) WHERE balance_amount = 0 AND total_amount > 0" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( "UPDATE {$table} SET payment_reference = CONCAT('YB-', DATE_FORMAT(created_at, '%Y%m%d'), '-', id) WHERE payment_reference IS NULL OR payment_reference = ''" );
		// Preserve the historical pending amount as the original amount due when upgrading.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( "UPDATE {$table} a INNER JOIN (SELECT appointment_id, MAX(amount) AS pending_amount FROM {$payments_table} WHERE status = 'pending' GROUP BY appointment_id) p ON p.appointment_id = a.id SET a.payment_due_amount = LEAST(a.total_amount, p.pending_amount) WHERE a.payment_due_amount = 0 AND a.total_amount > 0" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( "UPDATE {$table} SET payment_collection_mode = CASE WHEN payment_due_amount >= total_amount THEN 'full' ELSE 'deposit' END WHERE payment_collection_mode = 'none' AND payment_due_amount > 0" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( "UPDATE {$table} SET payment_method_title = CASE payment_method WHEN 'bank_transfer' THEN 'Bank transfer' WHEN 'local' THEN 'Pay locally' ELSE payment_method END WHERE payment_method_title = ''" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( "UPDATE {$payments_table} SET kind = 'refund' WHERE status = 'refunded'" );

		// Rebuild denormalized balances in one aggregate update instead of one query set per appointment.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			"UPDATE {$table} a
			INNER JOIN (
				SELECT appointment_id,
					SUM(CASE WHEN kind = 'payment' AND status IN ('paid','partially_paid') THEN amount ELSE 0 END) AS paid,
					SUM(CASE WHEN kind = 'refund' AND status = 'refunded' THEN amount ELSE 0 END) AS refunded
				FROM {$payments_table}
				GROUP BY appointment_id
			) p ON p.appointment_id = a.id
			SET a.paid_amount = p.paid,
				a.refunded_amount = p.refunded,
				a.balance_amount = GREATEST(a.total_amount - GREATEST(p.paid - p.refunded, 0), 0),
				a.payment_status = CASE
					WHEN p.refunded > 0 AND GREATEST(p.paid - p.refunded, 0) = 0 THEN 'refunded'
					WHEN p.refunded > 0 THEN 'partially_refunded'
					WHEN a.total_amount > 0 AND p.paid >= a.total_amount THEN 'paid'
					WHEN p.paid > 0 THEN 'partially_paid'
					ELSE a.payment_status
				END"
		);
	}

	/** Backfill immutable labels and contact data for existing appointments. */
	private function backfill_appointment_snapshots() {
		global $wpdb;

		$appointments = self::table_name( 'appointments' );
		$customers    = self::table_name( 'customers' );
		$services     = self::table_name( 'services' );
		$staff        = self::table_name( 'staff' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			"UPDATE {$appointments} a
			LEFT JOIN {$customers} c ON c.id = a.customer_id
			LEFT JOIN {$services} s ON s.id = a.service_id
			LEFT JOIN {$staff} st ON st.id = a.staff_id
			SET a.customer_name_snapshot = COALESCE(NULLIF(a.customer_name_snapshot, ''), c.name, ''),
				a.customer_email_snapshot = COALESCE(NULLIF(a.customer_email_snapshot, ''), c.email, ''),
				a.customer_phone_snapshot = COALESCE(NULLIF(a.customer_phone_snapshot, ''), c.phone, ''),
				a.customer_phone_country_snapshot = COALESCE(NULLIF(a.customer_phone_country_snapshot, ''), c.phone_country, ''),
				a.service_name_snapshot = COALESCE(NULLIF(a.service_name_snapshot, ''), s.name, ''),
				a.staff_name_snapshot = COALESCE(NULLIF(a.staff_name_snapshot, ''), st.name, '')
			WHERE a.customer_name_snapshot = '' OR a.customer_phone_country_snapshot = '' OR a.service_name_snapshot = '' OR (a.staff_id > 0 AND a.staff_name_snapshot = '')"
		);
	}

	/**
	 * Drop all managed tables.
	 *
	 * @return void
	 */
	private function drop_tables() {
		global $wpdb;

		foreach ( self::managed_tables() as $table_name ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
		}
	}

	/**
	 * Delete legacy options from the pre-rebuild plugin.
	 *
	 * @return void
	 */
	private function delete_legacy_options() {
		global $wpdb;

		$like = $wpdb->esc_like( 'yo_booking_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);
	}

	/**
	 * Seed default email notification templates.
	 *
	 * @return void
	 */
	private function seed_notification_templates() {
		global $wpdb;

		$table_name = self::table_name( 'notifications' );
		$now        = current_time( 'mysql', true );

		$templates = array(
			array(
				'notification_key' => 'admin_new_appointment',
				'event'            => 'appointment.created',
				'recipient_type'   => 'admin',
				'send_ics'         => 0,
				'subject'          => '[{company_name}] New appointment #{appointment_id}',
				'heading'          => 'New appointment received',
				'body'             => "A new appointment was booked.\n\nCustomer: {customer_name}\nService: {service_name}\nDate: {appointment_date}\nTime: {appointment_time}",
			),
			array(
				'notification_key' => 'staff_new_appointment',
				'event'            => 'appointment.created',
				'recipient_type'   => 'staff',
				'send_ics'         => 1,
				'subject'          => 'New appointment: {service_name}',
				'heading'          => 'New appointment assigned',
				'body'             => "Hello {staff_name},\n\nA new appointment has been booked.\n\nCustomer: {customer_name}\nService: {service_name}\nDate: {appointment_date}\nTime: {appointment_time}",
			),
			array(
				'notification_key' => 'customer_booking_received',
				'event'            => 'appointment.created',
				'recipient_type'   => 'customer',
				'send_ics'         => 1,
				'subject'          => 'We received your appointment request',
				'heading'          => 'Booking received',
				'body'             => "Hello {customer_name},\n\nWe received your booking request for {service_name} on {appointment_date} at {appointment_time}.\n\nCancel: {cancel_link}\nReschedule: {reschedule_link}",
			),
			array(
				'notification_key' => 'customer_booking_confirmed',
				'event'            => 'appointment.confirmed',
				'recipient_type'   => 'customer',
				'send_ics'         => 1,
				'subject'          => 'Your appointment is confirmed',
				'heading'          => 'Booking confirmed',
				'body'             => "Hello {customer_name},\n\nYour appointment for {service_name} on {appointment_date} at {appointment_time} has been confirmed.\n\nCancel: {cancel_link}\nReschedule: {reschedule_link}",
			),
			array(
				'notification_key' => 'customer_booking_cancelled',
				'event'            => 'appointment.cancelled',
				'recipient_type'   => 'customer',
				'send_ics'         => 0,
				'subject'          => 'Your appointment was cancelled',
				'heading'          => 'Booking cancelled',
				'body'             => "Hello {customer_name},\n\nYour appointment for {service_name} on {appointment_date} at {appointment_time} was cancelled.",
			),
			array(
				'notification_key' => 'customer_booking_rescheduled',
				'event'            => 'appointment.rescheduled',
				'recipient_type'   => 'customer',
				'send_ics'         => 1,
				'subject'          => 'Your appointment was rescheduled',
				'heading'          => 'Booking rescheduled',
				'body'             => "Hello {customer_name},\n\nYour appointment for {service_name} is now scheduled on {appointment_date} at {appointment_time}.\n\nCancel: {cancel_link}\nReschedule: {reschedule_link}",
			),
			array(
				'notification_key' => 'customer_booking_completed',
				'event'            => 'appointment.completed',
				'recipient_type'   => 'customer',
				'send_ics'         => 0,
				'subject'          => 'Your appointment is complete',
				'heading'          => 'Appointment completed',
				'body'             => "Hello {customer_name},\n\nYour appointment for {service_name} on {appointment_date} has been marked complete.",
			),
			array(
				'notification_key'       => 'customer_booking_reminder',
				'event'                  => 'appointment.reminder',
				'recipient_type'         => 'customer',
				'send_ics'               => 1,
				'timing_offset_minutes'  => 1440,
				'subject'                => 'Reminder: {service_name} on {appointment_date}',
				'heading'                => 'Appointment reminder',
				'body'                   => "Hello {customer_name},\n\nThis is a reminder for your appointment for {service_name} on {appointment_date} at {appointment_time}.",
			),
			array(
				'notification_key' => 'customer_payment_received',
				'event'            => 'payment.received',
				'recipient_type'   => 'customer',
				'send_ics'         => 0,
				'subject'          => 'Payment received for {service_name}',
				'heading'          => 'Payment received',
				'body'             => "Hello {customer_name},\n\nWe recorded your payment for booking {payment_reference}.\n\nPaid: {payment_paid}\nRemaining balance: {payment_balance}",
			),
			array(
				'notification_key' => 'customer_payment_failed',
				'event'            => 'payment.failed',
				'recipient_type'   => 'customer',
				'send_ics'         => 0,
				'subject'          => 'Payment could not be completed',
				'heading'          => 'Payment failed',
				'body'             => "Hello {customer_name},\n\nThe payment for booking {payment_reference} could not be completed. Remaining balance: {payment_balance}.",
			),
			array(
				'notification_key' => 'customer_payment_refunded',
				'event'            => 'payment.refunded',
				'recipient_type'   => 'customer',
				'send_ics'         => 0,
				'subject'          => 'Refund update for {payment_reference}',
				'heading'          => 'Refund recorded',
				'body'             => "Hello {customer_name},\n\nA refund has been recorded for booking {payment_reference}.\n\nRefunded: {payment_refunded}\nRemaining balance: {payment_balance}",
			),
			array(
				'notification_key'      => 'customer_payment_balance_reminder',
				'event'                 => 'payment.balance_reminder',
				'recipient_type'        => 'customer',
				'send_ics'              => 0,
				'timing_offset_minutes' => 1440,
				'subject'               => 'Balance reminder for {service_name}',
				'heading'               => 'Payment balance due',
				'body'                  => "Hello {customer_name},\n\nYour remaining balance for booking {payment_reference} is {payment_balance}.\n\nPayment method: {payment_method}\n{payment_instructions}",
			),
		);

		foreach ( $templates as $template ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, subject, heading, body FROM {$table_name} WHERE notification_key = %s",
					$template['notification_key']
				)
			);

			if ( $existing ) {
				$repair = array();
				foreach ( array( 'subject', 'heading', 'body' ) as $field ) {
					$current = trim( (string) $existing->{$field} );
					if ( '' === $current || '0' === $current ) {
						$repair[ $field ] = $template[ $field ];
					}
				}
				$wpdb->update(
					$table_name,
					array_merge(
						array(
						'event'                 => $template['event'],
						'recipient_type'        => $template['recipient_type'],
						'send_ics'              => ! empty( $template['send_ics'] ) ? 1 : 0,
						'timing_offset_minutes' => isset( $template['timing_offset_minutes'] ) ? (int) $template['timing_offset_minutes'] : 0,
						'updated_at'            => $now,
						),
						$repair
					),
					array( 'id' => absint( $existing->id ) ),
					null,
					array( '%d' )
				);
				continue;
			}

			$wpdb->insert(
				$table_name,
				array(
					'notification_key'       => $template['notification_key'],
					'event'                  => $template['event'],
					'recipient_type'         => $template['recipient_type'],
					'enabled'                => 1,
					'subject'                => $template['subject'],
					'heading'                => $template['heading'],
					'body'                   => $template['body'],
					'email_type'             => 'html',
					'send_ics'               => ! empty( $template['send_ics'] ) ? 1 : 0,
					'timing_offset_minutes'  => isset( $template['timing_offset_minutes'] ) ? (int) $template['timing_offset_minutes'] : 0,
					'created_at'             => $now,
					'updated_at'             => $now,
				),
				array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Seed default global business hours on fresh availability installs.
	 *
	 * @return void
	 */
	private function seed_default_availability_rules() {
		global $wpdb;

		$table_name = self::table_name( 'availability_rules' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE owner_type = %s AND owner_id = %d",
				'global',
				0
			)
		);

		if ( $count > 0 ) {
			return;
		}

		$settings = ( new SettingsRepository() )->all();
		$rules    = array();

		foreach ( range( 1, 5 ) as $weekday ) {
			$rules[ $weekday ] = array(
				'enabled'               => 1,
				'start_time'            => '09:00',
				'end_time'              => '17:00',
				'slot_interval_minutes' => $settings['booking']['slot_interval_minutes'],
			);
		}

		( new AvailabilityRuleRepository() )->replace_weekly(
			'global',
			0,
			$rules,
			$settings['company']['timezone']
		);
	}

	/**
	 * Add or update a non-autoloaded plugin option.
	 *
	 * @param string $name Option name.
	 * @param mixed  $value Option value.
	 * @return void
	 */
	private function set_option( $name, $value ) {
		if ( false === get_option( $name, false ) ) {
			add_option( $name, $value, '', 'no' );
			return;
		}

		update_option( $name, $value, 'no' );
	}
}
