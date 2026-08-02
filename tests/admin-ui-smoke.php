<?php
/**
 * Admin UI server-render smoke test.
 *
 * Run with wp eval-file tests/admin-ui-smoke.php.
 *
 * @package YoBooking
 */

use YoBooking\Admin\AdminMenu;
use YoBooking\Admin\AppointmentsPage;
use YoBooking\Admin\AppearancePage;
use YoBooking\Admin\AvailabilityPage;
use YoBooking\Admin\DashboardPage;
use YoBooking\Admin\NotificationsPage;
use YoBooking\Admin\ServicesPage;
use YoBooking\Admin\StaffPage;

defined( 'ABSPATH' ) || exit;
defined( 'YO_BOOKING_RUNNING_TESTS' ) || define( 'YO_BOOKING_RUNNING_TESTS', true );

$administrators = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);

if ( empty( $administrators ) ) {
	throw new RuntimeException( 'No administrator is available for the admin UI smoke test.' );
}

wp_set_current_user( (int) $administrators[0]->ID );

$render = static function ( $callback, array $query = array() ) {
	$original_get = $_GET;
	$_GET         = $query;
	ob_start();
	call_user_func( $callback );
	$html = ob_get_clean();
	$_GET = $original_get;

	return $html;
};

$assert_contains = static function ( $needle, $haystack, $context ) {
	if ( false === strpos( $haystack, $needle ) ) {
		throw new RuntimeException( $context . ' is missing expected markup: ' . $needle );
	}
};

$assert_not_contains = static function ( $needle, $haystack, $context ) {
	if ( false !== strpos( $haystack, $needle ) ) {
		throw new RuntimeException( $context . ' unexpectedly contains markup: ' . $needle );
	}
};

$dashboard = $render( array( new DashboardPage(), 'render' ) );
$assert_contains( 'Booking overview', $dashboard, 'Dashboard' );
$assert_contains( 'yo-grid--stats', $dashboard, 'Dashboard' );
$assert_contains( 'Needs attention', $dashboard, 'Dashboard action queue' );
$assert_contains( 'yo-dashboard-chart', $dashboard, 'Dashboard activity chart' );
$assert_contains( 'Booking outcomes', $dashboard, 'Dashboard status mix' );
$assert_contains( 'Top services', $dashboard, 'Dashboard service ranking' );

$appointments = $render( array( new AppointmentsPage(), 'render' ), array( 'view' => 'list' ) );
$assert_contains( 'yo-toolbar', $appointments, 'Appointment list' );
$assert_contains( 'payment_status', $appointments, 'Appointment filters' );
$assert_contains( 'Add appointment', $appointments, 'Appointment list' );
$assert_contains( 'yo-booking-bulk-status-form', $appointments, 'Appointment bulk actions' );
$assert_contains( 'yo-appointment-drawer', $appointments, 'Appointment details drawer' );

$appointment_editor = $render( array( new AppointmentsPage(), 'render' ), array( 'action' => 'new' ) );
$assert_contains( 'class="yo-editor"', $appointment_editor, 'Appointment editor' );
$assert_contains( 'yo_booking_save_appointment', $appointment_editor, 'Appointment editor' );
$assert_contains( 'data-customer-autocomplete', $appointment_editor, 'Customer autocomplete' );
$assert_contains( 'id="yo_booking_total_amount" name="total_amount" type="text" inputmode="decimal"', $appointment_editor, 'Formatted appointment amount input' );
$assert_contains( 'data-yo-money-input', $appointment_editor, 'Formatted appointment amount input' );

$service_editor = $render( array( new ServicesPage(), 'render_services' ), array( 'action' => 'new' ) );
$assert_contains( 'id="yo_booking_service_price" name="price" type="text" inputmode="decimal"', $service_editor, 'Formatted service price input' );
$assert_contains( 'data-yo-money-raw', $service_editor, 'Service price precision preservation' );
$assert_contains( 'id="yo_booking_service_color_hex"', $service_editor, 'Service HEX color input' );

$staff_editor = $render( array( new StaffPage(), 'render' ), array( 'action' => 'new' ) );
$assert_contains( 'id="yo_booking_staff_color_hex"', $staff_editor, 'Staff HEX color input' );

$appointment_calendar = $render( array( new AppointmentsPage(), 'render' ), array( 'view' => 'calendar' ) );
$assert_contains( 'id="yo-booking-calendar"', $appointment_calendar, 'Interactive calendar' );
$assert_contains( 'data-calendar-filter', $appointment_calendar, 'Calendar filters' );

$appearance = $render( array( new AppearancePage(), 'render' ) );
$assert_contains( 'yo_booking_save_appearance', $appearance, 'Appearance settings' );
$assert_contains( 'data-appearance-preview', $appearance, 'Appearance live preview' );
$assert_contains( 'name="primary_color"', $appearance, 'Appearance colors' );
$assert_contains( 'id="yo_booking_appearance_primary_color_hex"', $appearance, 'Appearance HEX color input' );
$assert_contains( 'data-yo-color-control', $appearance, 'Appearance synchronized color control' );
$assert_contains( 'name="show_progress"', $appearance, 'Appearance experience controls' );
$assert_contains( 'data-appearance-preset="clean"', $appearance, 'Appearance presets' );
$assert_contains( 'data-preview-device="mobile"', $appearance, 'Appearance device preview' );
$assert_contains( 'data-contrast-status', $appearance, 'Appearance contrast check' );
$assert_contains( 'data-copy-value="[yo-booking]"', $appearance, 'Booking shortcode guidance' );
$assert_contains( 'data-copy-value="[yo-booking-portal]"', $appearance, 'Customer portal shortcode guidance' );
$assert_contains( 'yo-copy-feedback', $appearance, 'Shortcode copy feedback' );

$availability = $render( array( new AvailabilityPage(), 'render' ) );
$assert_contains( 'data-yo-tab="business"', $availability, 'Availability tabs' );
$assert_contains( 'data-yo-panel="exceptions"', $availability, 'Availability tabs' );
$assert_contains( 'yo-weekly-schedule', $availability, 'Availability schedule' );
$assert_contains( 'data-add-range', $availability, 'Multiple availability ranges' );
$assert_contains( '[ranges][0][start_time]', $availability, 'Availability range input contract' );
$assert_contains( 'button-small yo-copy-feedback', $availability, 'Availability copy feedback' );

$notifications = $render( array( new NotificationsPage(), 'render' ) );
$assert_contains( 'data-yo-tab="templates"', $notifications, 'Notification tabs' );
$assert_contains( 'data-yo-panel="logs"', $notifications, 'Notification tabs' );
$assert_contains( 'data-yo-tab="settings"', $notifications, 'Notification settings tab' );
$assert_contains( 'data-yo-panel="settings"', $notifications, 'Notification settings panel' );
$assert_contains( 'yo-email-preview', $notifications, 'Notification preview' );
$assert_contains( 'yo_booking_send_test_notification', $notifications, 'Test notification form' );
$assert_contains( 'yo_booking_save_notification_settings', $notifications, 'Notification settings form' );
$assert_not_contains( 'name="notifications_enabled"', $notifications, 'Notification settings form' );
$assert_contains( 'name="email_primary_color"', $notifications, 'Email template colors' );
$assert_contains( 'id="yo_booking_email_primary_color_hex"', $notifications, 'Email HEX color input' );
$assert_contains( 'name="email_footer_text"', $notifications, 'Email footer text setting' );
$assert_contains( 'href="https://yoohw.com"', $notifications, 'Email footer link' );
$assert_contains( 'data-yo-email-style-preview', $notifications, 'Email design preview' );
$assert_contains( 'yo-email-style-preview__brand', $notifications, 'Email design preview' );
$assert_contains( 'yo-email-style-preview__header', $notifications, 'Email design preview' );

$settings = $render( array( new AdminMenu(), 'render_page' ) );
$assert_contains( 'System status', $settings, 'Settings' );
$assert_not_contains( 'Admin icons', $settings, 'System status' );
$assert_contains( 'yo-settings-layout', $settings, 'Settings' );
$assert_contains( 'settings_tab=payments', $settings, 'Settings navigation' );
$assert_not_contains( 'data-payment-field="deposit"', $settings, 'General settings' );
$assert_contains( 'settings_tab=integrations', $settings, 'Settings navigation' );
$assert_contains( 'settings_tab=audit', $settings, 'Settings navigation' );
$assert_contains( 'settings_tab=maintenance', $settings, 'Settings navigation' );
$assert_contains( 'data-settings-section="company"', $settings, 'Company settings section' );
$assert_contains( 'data-settings-section="regional"', $settings, 'Regional settings section' );
$assert_contains( 'data-settings-section="booking-rules"', $settings, 'Booking rules section' );
$assert_contains( 'data-settings-section="customer-booking"', $settings, 'Customer booking section' );
$assert_not_contains( 'data-settings-section="notifications"', $settings, 'General settings' );
$assert_not_contains( 'name="notification_from_email"', $settings, 'General settings' );
$assert_contains( 'data-settings-section="data-management"', $settings, 'Data management section' );
$assert_not_contains( 'name="company_currency"', $settings, 'General settings' );
$assert_not_contains( 'Core settings', $settings, 'General settings' );
$assert_contains( 'name="company_logo_id"', $settings, 'Company logo setting' );
$assert_contains( 'data-yo-media-field', $settings, 'Company logo media picker' );
$assert_contains( 'data-yo-media-image', $settings, 'Company logo preview' );

$payment_settings = $render( array( new AdminMenu(), 'render_page' ), array( 'settings_tab' => 'payments' ) );
$assert_contains( 'name="settings_tab" value="payments"', $payment_settings, 'Payment settings form' );
$assert_contains( 'data-settings-section="currency"', $payment_settings, 'Currency settings section' );
$assert_contains( 'name="company_currency"', $payment_settings, 'Default currency setting' );
$assert_contains( '<option value="BTC"', $payment_settings, 'WooCommerce-compatible currency choices' );
$assert_not_contains( '<option value="XAU"', $payment_settings, 'WooCommerce-compatible currency choices' );
$assert_contains( 'name="payment_currency_position"', $payment_settings, 'Currency position settings' );
$assert_contains( 'name="payment_thousand_separator"', $payment_settings, 'Currency separator settings' );
$assert_contains( 'name="payment_decimal_separator"', $payment_settings, 'Currency separator settings' );
$assert_contains( 'name="payment_number_of_decimals"', $payment_settings, 'Currency precision settings' );
$assert_contains( 'data-payment-field="deposit"', $payment_settings, 'Payment settings' );
$assert_contains( 'payment_methods[]', $payment_settings, 'Payment method settings' );
$assert_contains( 'payment_bank_iban', $payment_settings, 'Bank transfer settings' );
$assert_not_contains( 'name="company_name"', $payment_settings, 'Payment settings' );

$integrations = $render( array( new AdminMenu(), 'render_page' ), array( 'settings_tab' => 'integrations', 'integration_tab' => 'webhooks' ) );
$assert_contains( 'data-yo-tab="webhooks"', $integrations, 'Integration tabs' );
$assert_contains( 'data-yo-panel="deliveries"', $integrations, 'Webhook deliveries' );
$assert_contains( 'data-yo-panel="api-keys"', $integrations, 'API key management' );
$assert_contains( 'settings_tab=integrations', $integrations, 'Integration links' );

$audit = $render( array( new AdminMenu(), 'render_page' ), array( 'settings_tab' => 'audit' ) );
$assert_contains( 'name="settings_tab" value="audit"', $audit, 'Audit filters' );
$assert_contains( 'yo-table--audit', $audit, 'Audit table' );

$maintenance = $render( array( new AdminMenu(), 'render_page' ), array( 'settings_tab' => 'maintenance' ) );
$assert_contains( 'Retention cleanup', $maintenance, 'Maintenance' );
$assert_contains( 'yo_booking_download_backup', $maintenance, 'Encrypted backup' );
$assert_contains( 'yo_booking_restore_backup', $maintenance, 'Encrypted restore' );

echo "admin_ui_smoke=pass\n";
