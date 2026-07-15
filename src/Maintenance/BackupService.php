<?php
/**
 * Encrypted same-site backup and restore.
 *
 * @package YoBooking
 */

namespace YoBooking\Maintenance;

use YoBooking\Database\Migrator;
use YoBooking\Integrations\WebhookDispatcher;
use YoBooking\Settings\Repository as SettingsRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Creates password-encrypted disaster-recovery snapshots.
 */
final class BackupService {
	const FORMAT = 'yo-booking-encrypted-backup';
	const VERSION = 1;
	const STREAM_VERSION = 2;
	const ITERATIONS = 200000;
	const BATCH_SIZE = 500;

	/** Create a chunked encrypted archive without loading all table rows into memory. */
	public function create_archive_file( $password ) {
		global $wpdb;
		$password_error = $this->validate_password( $password );
		if ( is_wp_error( $password_error ) ) return $password_error;
		if ( ! function_exists( 'openssl_encrypt' ) ) return new WP_Error( 'yo_booking_backup_openssl_missing', __( 'OpenSSL is required to create encrypted backups.', 'yo-booking' ) );

		$path = wp_tempnam( 'yo-booking-backup-' );
		$handle = $path ? fopen( $path, 'wb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) return new WP_Error( 'yo_booking_backup_file_failed', __( 'A temporary backup file could not be created.', 'yo-booking' ) );

		$salt = random_bytes( 16 );
		$key  = hash_pbkdf2( 'sha256', (string) $password, $salt, self::ITERATIONS, 32, true );
		$header = array( 'format' => self::FORMAT, 'version' => self::STREAM_VERSION, 'kdf' => 'pbkdf2-sha256', 'iterations' => self::ITERATIONS, 'salt' => base64_encode( $salt ) );
		fwrite( $handle, wp_json_encode( $header ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$sequence = 0;
		$counts   = array();

		try {
			$this->write_encrypted_record(
				$handle,
				array(
					'type' => 'meta', 'schema_version' => Migrator::SCHEMA_VERSION, 'plugin_version' => YO_BOOKING_VERSION,
					'created_at' => gmdate( 'c' ), 'site_fingerprint' => $this->site_fingerprint(), 'settings' => ( new SettingsRepository() )->all(),
				),
				$key,
				$sequence++
			);

			foreach ( $this->table_map() as $logical => $table ) {
				$last_id = 0;
				$counts[ $logical ] = 0;
				do {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $last_id, self::BATCH_SIZE ), ARRAY_A );
					foreach ( $rows as $row ) {
						$this->write_encrypted_record( $handle, array( 'type' => 'row', 'table' => $logical, 'data' => $row ), $key, $sequence++ );
						$last_id = (int) $row['id'];
						$counts[ $logical ]++;
					}
				} while ( count( $rows ) === self::BATCH_SIZE );
			}
			$this->write_encrypted_record( $handle, array( 'type' => 'end', 'counts' => $counts ), $key, $sequence++ );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return $path;
		} catch ( \Throwable $exception ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			wp_delete_file( $path );
			return new WP_Error( 'yo_booking_backup_stream_failed', $exception->getMessage() );
		}
	}

	/** Restore a stream archive, falling back to the version-one JSON format. */
	public function restore_archive_file( $path, $password ) {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) return new WP_Error( 'yo_booking_backup_invalid', __( 'The selected backup could not be read.', 'yo-booking' ) );
		$first = fgets( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
		$header = json_decode( (string) $first, true );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( is_array( $header ) && self::FORMAT === ( $header['format'] ?? '' ) && self::STREAM_VERSION === (int) ( $header['version'] ?? 0 ) ) {
			return $this->restore_stream_archive( $path, $password, $header );
		}
		$archive = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return $this->restore_archive( (string) $archive, $password );
	}

	/** @param string $password Encryption password. @return string|WP_Error */
	public function create_archive( $password ) {
		global $wpdb;
		$password_error = $this->validate_password( $password );
		if ( is_wp_error( $password_error ) ) return $password_error;
		if ( ! function_exists( 'openssl_encrypt' ) ) return new WP_Error( 'yo_booking_backup_openssl_missing', __( 'OpenSSL is required to create encrypted backups.', 'yo-booking' ) );

		$tables = array();
		foreach ( $this->table_map() as $logical => $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$tables[ $logical ] = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
		}
		$payload = array(
			'format'         => 'yo-booking-snapshot',
			'version'        => self::VERSION,
			'plugin_version' => YO_BOOKING_VERSION,
			'schema_version' => Migrator::SCHEMA_VERSION,
			'created_at'     => gmdate( 'c' ),
			'site_fingerprint' => $this->site_fingerprint(),
			'settings'       => ( new SettingsRepository() )->all(),
			'tables'         => $tables,
		);
		$plaintext = wp_json_encode( $payload );
		if ( false === $plaintext ) return new WP_Error( 'yo_booking_backup_json_failed', __( 'The backup payload could not be encoded.', 'yo-booking' ) );
		$salt = random_bytes( 16 );
		$iv   = random_bytes( 12 );
		$key  = hash_pbkdf2( 'sha256', (string) $password, $salt, self::ITERATIONS, 32, true );
		$tag  = '';
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ciphertext ) return new WP_Error( 'yo_booking_backup_encrypt_failed', __( 'The backup could not be encrypted.', 'yo-booking' ) );

		return (string) wp_json_encode(
			array(
				'format' => self::FORMAT, 'version' => self::VERSION, 'kdf' => 'pbkdf2-sha256', 'iterations' => self::ITERATIONS,
				'salt' => base64_encode( $salt ), 'iv' => base64_encode( $iv ), 'tag' => base64_encode( $tag ), 'ciphertext' => base64_encode( $ciphertext ),
			)
		);
	}

	/** @param string $archive Encrypted archive. @param string $password Password. @return array|WP_Error */
	public function restore_archive( $archive, $password ) {
		global $wpdb;
		$password_error = $this->validate_password( $password );
		if ( is_wp_error( $password_error ) ) return $password_error;
		$payload = $this->decrypt_archive( $archive, $password );
		if ( is_wp_error( $payload ) ) return $payload;
		if ( ! isset( $payload['format'], $payload['schema_version'], $payload['site_fingerprint'], $payload['settings'], $payload['tables'] ) || 'yo-booking-snapshot' !== $payload['format'] ) {
			return new WP_Error( 'yo_booking_backup_payload_invalid', __( 'The decrypted backup payload is invalid.', 'yo-booking' ) );
		}
		if ( ! hash_equals( $this->site_fingerprint(), (string) $payload['site_fingerprint'] ) ) {
			return new WP_Error( 'yo_booking_backup_site_mismatch', __( 'This encrypted snapshot belongs to a different WordPress installation.', 'yo-booking' ) );
		}
		if ( Migrator::SCHEMA_VERSION !== (string) $payload['schema_version'] ) {
			return new WP_Error( 'yo_booking_backup_schema_mismatch', __( 'The backup schema does not match the installed plugin schema.', 'yo-booking' ) );
		}
		$table_map = $this->table_map();
		if ( array_diff( array_keys( $table_map ), array_keys( (array) $payload['tables'] ) ) ) {
			return new WP_Error( 'yo_booking_backup_tables_missing', __( 'The backup does not contain every managed table.', 'yo-booking' ) );
		}

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		try {
			foreach ( array_reverse( $table_map, true ) as $table ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
				if ( false === $wpdb->query( "DELETE FROM {$table}" ) ) throw new \RuntimeException( $wpdb->last_error );
			}
			foreach ( $table_map as $logical => $table ) {
				$allowed_columns = $this->columns( $table );
				foreach ( (array) $payload['tables'][ $logical ] as $row ) {
					if ( ! is_array( $row ) ) throw new \RuntimeException( 'Invalid table row.' );
					$row = array_intersect_key( $row, array_flip( $allowed_columns ) );
					if ( ! $row || false === $wpdb->insert( $table, $row ) ) throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : 'Database insert failed.' );
				}
			}
			if ( ! is_array( $payload['settings'] ) ) throw new \RuntimeException( 'Invalid settings payload.' );
			( new SettingsRepository() )->save( $payload['settings'] );
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		} catch ( \Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			// translators: %s: database error returned while restoring a backup.
			return new WP_Error( 'yo_booking_backup_restore_failed', sprintf( __( 'Restore failed and was rolled back: %s', 'yo-booking' ), $exception->getMessage() ) );
		}

		do_action( 'yo_booking_backup_restored', $payload );
		wp_clear_scheduled_hook( WebhookDispatcher::PROCESS_HOOK );
		$delivery_table = Migrator::table_name( 'webhook_deliveries' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		foreach ( $wpdb->get_col( "SELECT id FROM {$delivery_table} WHERE status IN ('pending','retrying')" ) as $delivery_id ) {
			( new WebhookDispatcher() )->schedule( (int) $delivery_id, 1 );
		}
		return array( 'restored_at' => current_time( 'mysql', true ), 'tables' => count( $table_map ) );
	}

	/** @param string $archive Archive JSON. @param string $password Password. @return array|WP_Error */
	private function decrypt_archive( $archive, $password ) {
		$container = json_decode( (string) $archive, true );
		if ( ! is_array( $container ) || self::FORMAT !== ( $container['format'] ?? '' ) || self::VERSION !== (int) ( $container['version'] ?? 0 ) ) {
			return new WP_Error( 'yo_booking_backup_invalid', __( 'The selected file is not a supported Yo Booking backup.', 'yo-booking' ) );
		}
		foreach ( array( 'salt', 'iv', 'tag', 'ciphertext' ) as $field ) {
			if ( empty( $container[ $field ] ) ) return new WP_Error( 'yo_booking_backup_invalid', __( 'The encrypted backup is incomplete.', 'yo-booking' ) );
			$container[ $field ] = base64_decode( $container[ $field ], true );
			if ( false === $container[ $field ] ) return new WP_Error( 'yo_booking_backup_invalid', __( 'The encrypted backup is malformed.', 'yo-booking' ) );
		}
		$iterations = max( 100000, min( 1000000, absint( $container['iterations'] ?? self::ITERATIONS ) ) );
		$key = hash_pbkdf2( 'sha256', (string) $password, $container['salt'], $iterations, 32, true );
		$plaintext = openssl_decrypt( $container['ciphertext'], 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $container['iv'], $container['tag'] );
		if ( false === $plaintext ) return new WP_Error( 'yo_booking_backup_password_invalid', __( 'The backup password is incorrect or the file has been modified.', 'yo-booking' ) );
		$payload = json_decode( $plaintext, true );
		return is_array( $payload ) ? $payload : new WP_Error( 'yo_booking_backup_payload_invalid', __( 'The decrypted backup payload is invalid.', 'yo-booking' ) );
	}

	/** @param resource $handle Output handle. @param array $record Record. @param string $key Key. @param int $sequence Sequence. */
	private function write_encrypted_record( $handle, array $record, $key, $sequence ) {
		$iv = random_bytes( 12 );
		$tag = '';
		$plaintext = wp_json_encode( $record );
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'yo-booking:' . self::STREAM_VERSION . ':' . $sequence );
		if ( false === $ciphertext ) throw new \RuntimeException( esc_html__( 'A backup chunk could not be encrypted.', 'yo-booking' ) );
		$line = wp_json_encode( array( 'iv' => base64_encode( $iv ), 'tag' => base64_encode( $tag ), 'data' => base64_encode( $ciphertext ) ) );
		if ( false === fwrite( $handle, $line . "\n" ) ) throw new \RuntimeException( esc_html__( 'A backup chunk could not be written.', 'yo-booking' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}

	/** @return array|WP_Error */
	private function restore_stream_archive( $path, $password, array $header ) {
		global $wpdb;
		$password_error = $this->validate_password( $password );
		if ( is_wp_error( $password_error ) ) return $password_error;
		$salt = isset( $header['salt'] ) ? base64_decode( $header['salt'], true ) : false;
		if ( false === $salt ) return new WP_Error( 'yo_booking_backup_invalid', __( 'The encrypted backup header is malformed.', 'yo-booking' ) );
		$iterations = max( 100000, min( 1000000, absint( $header['iterations'] ?? self::ITERATIONS ) ) );
		$key = hash_pbkdf2( 'sha256', (string) $password, $salt, $iterations, 32, true );
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fgets( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
		$sequence = 0;
		$meta = $this->read_encrypted_record( $handle, $key, $sequence++ );
		if ( is_wp_error( $meta ) ) { fclose( $handle ); return $meta; } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( 'meta' !== ( $meta['type'] ?? '' ) || Migrator::SCHEMA_VERSION !== (string) ( $meta['schema_version'] ?? '' ) || ! hash_equals( $this->site_fingerprint(), (string) ( $meta['site_fingerprint'] ?? '' ) ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'yo_booking_backup_context_mismatch', __( 'This backup does not match the current site and schema.', 'yo-booking' ) );
		}

		$table_map = $this->table_map();
		$counts = array_fill_keys( array_keys( $table_map ), 0 );
		$allowed_columns = array();
		foreach ( $table_map as $logical => $table ) {
			$allowed_columns[ $logical ] = array_flip( $this->columns( $table ) );
		}
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		try {
			foreach ( array_reverse( $table_map, true ) as $table ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
				if ( false === $wpdb->query( "DELETE FROM {$table}" ) ) throw new \RuntimeException( $wpdb->last_error );
			}
			$ended = false;
			while ( ! feof( $handle ) ) {
				$record = $this->read_encrypted_record( $handle, $key, $sequence++ );
				if ( is_wp_error( $record ) ) throw new \RuntimeException( $record->get_error_message() );
				if ( null === $record ) continue;
				if ( 'end' === ( $record['type'] ?? '' ) ) {
					if ( $counts !== (array) ( $record['counts'] ?? array() ) ) throw new \RuntimeException( __( 'Backup row counts do not match.', 'yo-booking' ) );
					$ended = true;
					break;
				}
				$logical = isset( $record['table'] ) ? (string) $record['table'] : '';
				if ( 'row' !== ( $record['type'] ?? '' ) || ! isset( $table_map[ $logical ] ) || ! is_array( $record['data'] ?? null ) ) throw new \RuntimeException( __( 'The backup contains an invalid row.', 'yo-booking' ) );
				$row = array_intersect_key( $record['data'], $allowed_columns[ $logical ] );
				if ( ! $row || false === $wpdb->insert( $table_map[ $logical ], $row ) ) throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : __( 'Database insert failed.', 'yo-booking' ) );
				$counts[ $logical ]++;
			}
			if ( ! $ended || ! is_array( $meta['settings'] ?? null ) ) throw new \RuntimeException( __( 'The backup ended unexpectedly.', 'yo-booking' ) );
			( new SettingsRepository() )->save( $meta['settings'] );
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		} catch ( \Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			// translators: %s: database error returned while restoring a backup.
			return new WP_Error( 'yo_booking_backup_restore_failed', sprintf( __( 'Restore failed and was rolled back: %s', 'yo-booking' ), $exception->getMessage() ) );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		do_action( 'yo_booking_backup_restored', array( 'stream_version' => self::STREAM_VERSION, 'counts' => $counts ) );
		wp_clear_scheduled_hook( WebhookDispatcher::PROCESS_HOOK );
		$delivery_table = Migrator::table_name( 'webhook_deliveries' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		foreach ( $wpdb->get_col( "SELECT id FROM {$delivery_table} WHERE status IN ('pending','retrying')" ) as $delivery_id ) {
			( new WebhookDispatcher() )->schedule( (int) $delivery_id, 1 );
		}
		return array( 'restored_at' => current_time( 'mysql', true ), 'tables' => count( $table_map ) );
	}

	/** @param resource $handle Input handle. @return array|null|WP_Error */
	private function read_encrypted_record( $handle, $key, $sequence ) {
		$line = fgets( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
		if ( false === $line ) return null;
		$chunk = json_decode( trim( $line ), true );
		if ( ! is_array( $chunk ) ) return new WP_Error( 'yo_booking_backup_chunk_invalid', __( 'An encrypted backup chunk is malformed.', 'yo-booking' ) );
		foreach ( array( 'iv', 'tag', 'data' ) as $field ) {
			$chunk[ $field ] = isset( $chunk[ $field ] ) ? base64_decode( $chunk[ $field ], true ) : false;
			if ( false === $chunk[ $field ] ) return new WP_Error( 'yo_booking_backup_chunk_invalid', __( 'An encrypted backup chunk is malformed.', 'yo-booking' ) );
		}
		$plaintext = openssl_decrypt( $chunk['data'], 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $chunk['iv'], $chunk['tag'], 'yo-booking:' . self::STREAM_VERSION . ':' . $sequence );
		if ( false === $plaintext ) return new WP_Error( 'yo_booking_backup_password_invalid', __( 'The backup password is incorrect or the file has been modified.', 'yo-booking' ) );
		$record = json_decode( $plaintext, true );
		return is_array( $record ) ? $record : new WP_Error( 'yo_booking_backup_chunk_invalid', __( 'An encrypted backup chunk is malformed.', 'yo-booking' ) );
	}

	/** @return array */
	private function table_map() {
		$map = array();
		foreach ( Migrator::managed_tables() as $table ) {
			$prefix = Migrator::table_name( '' );
			$map[ substr( $table, strlen( $prefix ) ) ] = $table;
		}
		return $map;
	}

	/** @param string $table Table name. @return array */
	private function columns( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return array_map( static function ( $column ) { return $column->Field; }, $wpdb->get_results( "DESCRIBE {$table}" ) );
	}

	/** @return string */
	private function site_fingerprint() {
		return hash_hmac( 'sha256', home_url( '/' ), wp_salt( 'secure_auth' ) );
	}

	/** @param string $password Password. @return true|WP_Error */
	private function validate_password( $password ) {
		return strlen( (string) $password ) >= 12 ? true : new WP_Error( 'yo_booking_backup_password_short', __( 'Use a backup password with at least 12 characters.', 'yo-booking' ) );
	}
}
