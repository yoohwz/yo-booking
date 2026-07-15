<?php
/**
 * Booking roles and capabilities.
 *
 * @package YoBooking
 */

namespace YoBooking\Installer;

use YoBooking\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps plugin capabilities synchronized with WordPress roles.
 */
final class RoleManager {
	const VERSION = '2026071301';

	/** @return void */
	public function maybe_install() {
		if ( self::VERSION !== get_option( 'yo_booking_roles_version', '' ) ) {
			self::install();
		}
	}

	/** @return void */
	public static function install() {
		$all = array_fill_keys( Capabilities::all(), true );

		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				foreach ( array_keys( $all ) as $capability ) {
					$role->add_cap( $capability );
				}
			}
		}

		add_role(
			'yo_booking_manager',
			__( 'Booking Manager', 'yo-booking' ),
			array_merge( array( 'read' => true, 'upload_files' => true ), $all )
		);
		add_role(
			'yo_booking_staff',
			__( 'Booking Staff', 'yo-booking' ),
			array( 'read' => true, Capabilities::appointments() => true )
		);

		$manager = get_role( 'yo_booking_manager' );
		if ( $manager ) {
			foreach ( array_keys( $all ) as $capability ) {
				$manager->add_cap( $capability );
			}
		}
		$staff = get_role( 'yo_booking_staff' );
		if ( $staff ) {
			$staff->add_cap( Capabilities::appointments() );
		}

		update_option( 'yo_booking_roles_version', self::VERSION, false );
	}

	/** @return void */
	public static function uninstall() {
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				foreach ( Capabilities::all() as $capability ) {
					$role->remove_cap( $capability );
				}
			}
		}

		remove_role( 'yo_booking_manager' );
		remove_role( 'yo_booking_staff' );
	}
}
