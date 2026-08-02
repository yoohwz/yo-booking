<?php
/**
 * Main plugin runtime.
 *
 * @package YoBooking
 */

namespace YoBooking;

use YoBooking\Audit\AuditLogger;
use YoBooking\Admin\AdminMenu;
use YoBooking\Admin\AdminAssets;
use YoBooking\Admin\AppointmentsPage;
use YoBooking\Admin\AppearancePage;
use YoBooking\Admin\AvailabilityPage;
use YoBooking\Admin\CustomersPage;
use YoBooking\Admin\AuditLogPage;
use YoBooking\Admin\ExportController;
use YoBooking\Admin\NotificationsPage;
use YoBooking\Admin\ReportsPage;
use YoBooking\Admin\IntegrationsPage;
use YoBooking\Admin\MaintenancePage;
use YoBooking\Admin\ServicesPage;
use YoBooking\Admin\StaffPage;
use YoBooking\Database\Migrator;
use YoBooking\Diagnostics\SiteHealth;
use YoBooking\Frontend\SelfServicePage;
use YoBooking\Frontend\Shortcode;
use YoBooking\Installer\RoleManager;
use YoBooking\Integrations\WebhookDispatcher;
use YoBooking\Maintenance\CleanupService;
use YoBooking\Notifications\NotificationService;
use YoBooking\Payments\PaymentManager;
use YoBooking\Privacy\PrivacyManager;
use YoBooking\Rest\AvailabilityController;
use YoBooking\Rest\AdminAppointmentController;
use YoBooking\Rest\BookingController;
use YoBooking\Rest\IntegrationController;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates plugin services.
 */
final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Bootable services.
	 *
	 * @var array
	 */
	private $services = array();

	/**
	 * Return the shared plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {}

	/**
	 * Boot plugin services after WordPress is loaded.
	 *
	 * @return void
	 */
	public function boot() {
		( new Migrator() )->maybe_upgrade();
		( new RoleManager() )->maybe_install();

		$this->services = array(
			new AuditLogger(),
			new SiteHealth(),
			new PrivacyManager(),
			new WebhookDispatcher(),
			new CleanupService(),
			new AdminAssets(),
			new AdminMenu(),
			new AppointmentsPage(),
			new AvailabilityPage(),
			new ServicesPage(),
			new StaffPage(),
			new CustomersPage(),
			new NotificationsPage(),
			new AppearancePage(),
			new ReportsPage(),
			new AuditLogPage(),
			new IntegrationsPage(),
			new MaintenancePage(),
			new ExportController(),
			new AvailabilityController(),
			new AdminAppointmentController(),
			new BookingController(),
			new IntegrationController(),
			new PaymentManager(),
			new NotificationService(),
			new SelfServicePage(),
			new Shortcode(),
		);

		foreach ( $this->services as $service ) {
			if ( method_exists( $service, 'boot' ) ) {
				$service->boot();
			}
		}

		do_action( 'yo_booking_loaded', $this );
	}
}
