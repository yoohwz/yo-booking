<?php
/**
 * Appointments admin page.
 *
 * @package YoBooking
 */

namespace YoBooking\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use YoBooking\Payments\Currency;
use YoBooking\Payments\Money;
use YoBooking\Payments\PaymentManager;
use YoBooking\Payments\PaymentProviderRegistry;
use YoBooking\Repositories\AppointmentRepository;
use YoBooking\Repositories\CustomerRepository;
use YoBooking\Repositories\PaymentRepository;
use YoBooking\Repositories\ServiceRepository;
use YoBooking\Repositories\StaffRepository;
use YoBooking\Settings\Repository as SettingsRepository;
use YoBooking\Support\Capabilities;
use YoBooking\Support\DateTimeFormatter;
use YoBooking\Support\StaffAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Registers appointment lifecycle management.
 */
final class AppointmentsPage extends AbstractAdminPage {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_yo_booking_save_appointment', array( $this, 'save_appointment' ) );
		add_action( 'admin_post_yo_booking_update_appointment_status', array( $this, 'update_status' ) );
		add_action( 'admin_post_yo_booking_update_appointment_payment', array( $this, 'update_payment' ) );
		add_action( 'admin_post_yo_booking_update_appointment_note', array( $this, 'update_note' ) );
	}

	/**
	 * Register submenu page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'yo-booking',
			__( 'Appointments', 'yo-booking' ),
			__( 'Appointments', 'yo-booking' ),
			Capabilities::appointments(),
			'yo-booking-appointments',
			array( $this, 'render' )
		);
	}

	/**
	 * Render appointments page.
	 *
	 * @return void
	 */
	public function render() {
		$this->ensure_capability( Capabilities::appointments() );

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$export_args = array( 'action' => 'yo_booking_export_appointments' );
		foreach ( array( 'status', 'payment_status', 'service_id', 'staff_id', 's', 'date_from', 'date_to' ) as $export_filter ) {
			if ( isset( $_GET[ $export_filter ] ) && '' !== $_GET[ $export_filter ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$export_args[ $export_filter ] = sanitize_text_field( wp_unslash( $_GET[ $export_filter ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
		$export_url = wp_nonce_url( add_query_arg( $export_args, admin_url( 'admin-post.php' ) ), 'yo_booking_export_appointments' );
		$actions = current_user_can( Capabilities::export() ) ? '<a class="button" href="' . esc_url( $export_url ) . '"><span class="fi fi-rr-download" aria-hidden="true"></span>' . esc_html__( 'Export CSV', 'yo-booking' ) . '</a>' : '';
		if ( ! StaffAccess::restricted() ) {
			$actions .= '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=yo-booking-appointments&action=new' ) ) . '"><span class="fi fi-rr-plus" aria-hidden="true"></span>' . esc_html__( 'Add appointment', 'yo-booking' ) . '</a>';
		}

		?>
		<div class="wrap yo-booking-admin">
			<?php $this->render_page_header( __( 'Appointments', 'yo-booking' ), __( 'Manage bookings, customer details, status, and payments.', 'yo-booking' ), $actions ); ?>
			<?php $this->render_notice(); ?>
			<div class="yo-segmented yo-appointment-views" aria-label="<?php echo esc_attr__( 'Appointment view', 'yo-booking' ); ?>">
				<a class="button <?php echo 'list' === $view ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-appointments&view=list' ) ); ?>"><span class="fi fi-rr-list" aria-hidden="true"></span><?php echo esc_html__( 'List', 'yo-booking' ); ?></a>
				<a class="button <?php echo 'calendar' === $view ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-appointments&view=calendar' ) ); ?>"><span class="fi fi-rr-calendar-days" aria-hidden="true"></span><?php echo esc_html__( 'Calendar', 'yo-booking' ); ?></a>
			</div>
			<?php
			if ( 'calendar' === $view ) {
				$this->render_calendar();
			} else {
				$this->render_list();
			}
			$this->render_appointment_drawer();
			?>
		</div>
		<?php
	}

	/**
	 * Render list and appointment form.
	 *
	 * @return void
	 */
	private function render_list() {
		$appointments_repository = new AppointmentRepository();
		$services                = ( new ServiceRepository() )->all();
		$staff_members           = ( new StaffRepository() )->all();
		$settings                = ( new SettingsRepository() )->all();
		$edit_id                 = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing                 = $edit_id ? $appointments_repository->find_with_details( $edit_id ) : null;
		$is_new                  = ! StaffAccess::restricted() && isset( $_GET['action'] ) && 'new' === sanitize_key( wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status_filter           = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$payment_filter          = isset( $_GET['payment_status'] ) ? sanitize_key( wp_unslash( $_GET['payment_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$service_filter          = isset( $_GET['service_id'] ) ? absint( $_GET['service_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$staff_filter            = isset( $_GET['staff_id'] ) ? absint( $_GET['staff_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search                  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_from               = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_to                 = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sort_options            = array(
			'start_asc'    => __( 'Appointment time: earliest first', 'yo-booking' ),
			'start_desc'   => __( 'Appointment time: latest first', 'yo-booking' ),
			'created_desc' => __( 'Created: newest first', 'yo-booking' ),
			'created_asc'  => __( 'Created: oldest first', 'yo-booking' ),
		);
		$sort                    = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'start_asc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sort                    = array_key_exists( $sort, $sort_options ) ? $sort : 'start_asc';
		$paged                   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page                = 25;
		$filter_timezone          = $this->timezone( $settings['company']['timezone'] );
		$filter_from              = $date_from ? ( new DateTimeImmutable( $date_from . ' 00:00:00', $filter_timezone ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : '';
		$filter_to                = $date_to ? ( new DateTimeImmutable( $date_to . ' 23:59:59', $filter_timezone ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : '';
		$query_args              = array(
			'status'         => $status_filter,
			'payment_status' => $payment_filter,
			'service_id'     => $service_filter,
			'staff_id'       => $staff_filter,
			'search'         => $search,
			'from'           => $filter_from,
			'to'             => $filter_to,
			'sort'           => $sort,
		);
		$total                   = $appointments_repository->count_matching( $query_args );
		$appointments            = $appointments_repository->all( array_merge( $query_args, array( 'limit' => $per_page, 'offset' => ( $paged - 1 ) * $per_page ) ) );
		$has_active_filters      = array_filter( array_diff_key( $query_args, array( 'sort' => true ) ) ) || 'start_asc' !== $sort;

		?>
		<?php if ( $editing || $is_new ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yo-editor">
				<input type="hidden" name="action" value="yo_booking_save_appointment" />
				<input type="hidden" name="id" value="<?php echo esc_attr( $editing ? (string) $editing->id : '0' ); ?>" />
				<?php wp_nonce_field( 'yo_booking_save_appointment' ); ?>
				<div class="yo-editor__header"><h2><?php echo esc_html( $editing ? __( 'Edit appointment', 'yo-booking' ) : __( 'Add appointment', 'yo-booking' ) ); ?></h2><a href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-appointments' ) ); ?>"><?php echo esc_html__( 'Close', 'yo-booking' ); ?></a></div>
				<?php $this->render_appointment_form_fields( $editing, $services, $staff_members, $settings ); ?>
				<div class="yo-editor__footer"><?php submit_button( $editing ? __( 'Update appointment', 'yo-booking' ) : __( 'Create appointment', 'yo-booking' ), 'primary', 'submit', false ); ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-appointments' ) ); ?>"><?php echo esc_html__( 'Cancel', 'yo-booking' ); ?></a></div>
				</form>
				<?php if ( $editing ) : ?><?php $this->render_payment_management( $editing ); ?><?php endif; ?>
			<?php endif; ?>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="yo-toolbar">
			<input type="hidden" name="page" value="yo-booking-appointments" />
			<input type="hidden" name="view" value="list" />
			<label><?php echo esc_html__( 'Search', 'yo-booking' ); ?><input name="s" type="search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Customer, service, staff...', 'yo-booking' ); ?>" /></label>
			<label><?php echo esc_html__( 'From', 'yo-booking' ); ?><input name="date_from" type="date" value="<?php echo esc_attr( $date_from ); ?>" /></label>
			<label><?php echo esc_html__( 'To', 'yo-booking' ); ?><input name="date_to" type="date" value="<?php echo esc_attr( $date_to ); ?>" /></label>
			<label><?php echo esc_html__( 'Status', 'yo-booking' ); ?><select name="status"><option value=""><?php echo esc_html__( 'All statuses', 'yo-booking' ); ?></option><?php foreach ( AppointmentRepository::statuses() as $status => $label ) : ?><option value="<?php echo esc_attr( $status ); ?>" <?php selected( $status_filter, $status ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
			<label><?php echo esc_html__( 'Payment', 'yo-booking' ); ?><select name="payment_status"><option value=""><?php echo esc_html__( 'All payments', 'yo-booking' ); ?></option><?php foreach ( PaymentRepository::appointment_statuses() as $payment_status => $payment_label ) : ?><option value="<?php echo esc_attr( $payment_status ); ?>" <?php selected( $payment_filter, $payment_status ); ?>><?php echo esc_html( $payment_label ); ?></option><?php endforeach; ?></select></label>
			<label><?php echo esc_html__( 'Service', 'yo-booking' ); ?><select name="service_id"><option value="0"><?php echo esc_html__( 'All services', 'yo-booking' ); ?></option><?php foreach ( $services as $service ) : ?><option value="<?php echo esc_attr( (string) $service->id ); ?>" <?php selected( $service_filter, (int) $service->id ); ?>><?php echo esc_html( $service->name ); ?></option><?php endforeach; ?></select></label>
			<label><?php echo esc_html__( 'Staff', 'yo-booking' ); ?><select name="staff_id"><option value="0"><?php echo esc_html__( 'All staff', 'yo-booking' ); ?></option><?php foreach ( $staff_members as $staff ) : ?><option value="<?php echo esc_attr( (string) $staff->id ); ?>" <?php selected( $staff_filter, (int) $staff->id ); ?>><?php echo esc_html( $staff->name ); ?></option><?php endforeach; ?></select></label>
			<label><?php echo esc_html__( 'Sort by', 'yo-booking' ); ?><select name="sort"><?php foreach ( $sort_options as $sort_key => $sort_label ) : ?><option value="<?php echo esc_attr( $sort_key ); ?>" <?php selected( $sort, $sort_key ); ?>><?php echo esc_html( $sort_label ); ?></option><?php endforeach; ?></select></label>
			<button class="button" type="submit"><?php echo esc_html__( 'Apply filters', 'yo-booking' ); ?></button>
			<?php if ( $has_active_filters ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-appointments' ) ); ?>"><?php echo esc_html__( 'Reset', 'yo-booking' ); ?></a><?php endif; ?>
		</form>
		<form id="yo-booking-bulk-status-form" class="yo-bulk-bar" data-yo-async>
			<label for="yo-booking-bulk-status"><?php echo esc_html__( 'Bulk status', 'yo-booking' ); ?></label>
			<select id="yo-booking-bulk-status" name="status"><?php foreach ( AppointmentRepository::statuses() as $status => $label ) : ?><option value="<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
			<button class="button" type="submit"><?php echo esc_html__( 'Apply to selected', 'yo-booking' ); ?></button>
			<?php // translators: %d: number of appointments matching the current filters. ?>
			<span class="description"><?php echo esc_html( sprintf( _n( '%d matching appointment', '%d matching appointments', $total, 'yo-booking' ), $total ) ); ?></span>
		</form>

		<table class="widefat striped">
			<thead>
				<tr>
					<td class="check-column"><input id="yo-booking-select-all" type="checkbox" aria-label="<?php echo esc_attr__( 'Select all appointments', 'yo-booking' ); ?>" /></td>
					<th><?php echo esc_html__( 'When', 'yo-booking' ); ?></th>
					<th><?php echo esc_html__( 'Customer', 'yo-booking' ); ?></th>
					<th><?php echo esc_html__( 'Booking', 'yo-booking' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'yo-booking' ); ?></th>
					<th><?php echo esc_html__( 'Payment', 'yo-booking' ); ?></th>
					<th><?php echo esc_html__( 'Note', 'yo-booking' ); ?></th>
					<th><?php echo esc_html__( 'Actions', 'yo-booking' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $appointments ) ) : ?>
					<?php $this->render_empty_row( 8, __( 'No appointments found', 'yo-booking' ), __( 'Adjust the filters or create a new appointment.', 'yo-booking' ) ); ?>
				<?php endif; ?>
				<?php foreach ( $appointments as $appointment ) : ?>
					<?php $local_start = $this->local_datetime( $appointment->start_at, DateTimeFormatter::timezone_name() ); ?>
					<?php $local_end = $this->local_datetime( $appointment->end_at, DateTimeFormatter::timezone_name() ); ?>
					<tr data-appointment-row="<?php echo esc_attr( (string) $appointment->id ); ?>">
						<?php // translators: %d: appointment database ID. ?>
						<th class="check-column"><input name="appointment_ids[]" value="<?php echo esc_attr( (string) $appointment->id ); ?>" type="checkbox" aria-label="<?php echo esc_attr( sprintf( __( 'Select appointment %d', 'yo-booking' ), (int) $appointment->id ) ); ?>" /></th>
						<td class="yo-appointment-primary">
							<strong><?php echo esc_html( DateTimeFormatter::timestamp( $local_start->getTimestamp(), 'date' ) ); ?></strong><br />
							<?php echo esc_html( DateTimeFormatter::timestamp( $local_start->getTimestamp(), 'time' ) . ' - ' . DateTimeFormatter::timestamp( $local_end->getTimestamp(), 'time' ) ); ?><br />
							<span class="description"><?php echo esc_html( DateTimeFormatter::timezone_name() ); ?></span>
						</td>
						<td>
							<strong><?php echo esc_html( $appointment->customer_name ? $appointment->customer_name : __( 'Unknown customer', 'yo-booking' ) ); ?></strong><br />
							<span class="yo-appointment-contact"><?php echo esc_html( $appointment->customer_email ); ?>
							<?php if ( $appointment->customer_phone ) : ?>
								<br /><?php echo esc_html( $appointment->customer_phone ); ?>
							<?php endif; ?>
							</span>
						</td>
						<td><strong><?php echo esc_html( $appointment->service_name ); ?></strong><br /><span class="description"><?php echo esc_html( $appointment->staff_name ? $appointment->staff_name : __( 'Unassigned', 'yo-booking' ) ); ?></span></td>
						<td><span data-booking-status><?php $this->render_status_badge( $appointment->status, $this->status_label( $appointment->status ) ); ?></span></td>
						<td><?php $this->render_payment_cell( $appointment ); ?></td>
						<td><div class="yo-note-preview"><?php echo esc_html( $appointment->internal_note ? wp_trim_words( wp_strip_all_tags( $appointment->internal_note ), 12 ) : '-' ); ?></div></td>
						<td>
							<div class="yo-row-actions"><button class="button button-small" type="button" data-appointment-drawer="<?php echo esc_attr( (string) $appointment->id ); ?>"><?php echo esc_html__( 'View', 'yo-booking' ); ?></button><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-appointments&view=list&edit=' . absint( $appointment->id ) ) ); ?>"><?php echo esc_html__( 'Edit', 'yo-booking' ); ?></a><?php $this->render_status_buttons( $appointment ); ?></div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $total > $per_page ) : ?>
			<div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => (int) ceil( $total / $per_page ), 'prev_text' => '‹', 'next_text' => '›' ) ) ); ?></div></div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render appointment form fields.
	 *
	 * @param object|null $appointment Appointment row.
	 * @param array       $services Service rows.
	 * @param array       $staff_members Staff rows.
	 * @param array       $settings Settings.
	 * @return void
	 */
	private function render_appointment_form_fields( $appointment, array $services, array $staff_members, array $settings ) {
		$timezone    = DateTimeFormatter::timezone_name();
		$local_start = $appointment ? $this->local_datetime( $appointment->start_at, $timezone ) : null;
		$local_end   = $appointment ? $this->local_datetime( $appointment->end_at, $timezone ) : null;
		$duration    = $appointment && $local_start && $local_end ? max( 5, (int) ( ( $local_end->getTimestamp() - $local_start->getTimestamp() ) / 60 ) ) : 60;
		$requested_customer_id = isset( $_GET['customer_id'] ) ? absint( $_GET['customer_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected_customer_id  = $appointment ? (int) $appointment->customer_id : $requested_customer_id;
		$customer              = $selected_customer_id ? ( new CustomerRepository() )->find( $selected_customer_id ) : null;
		$requested_date_input = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested_time_input = isset( $_GET['start_time'] ) ? sanitize_text_field( wp_unslash( $_GET['start_time'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested_date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $requested_date_input ) ? $requested_date_input : current_time( 'Y-m-d' );
		$requested_time = preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $requested_time_input ) ? $requested_time_input : '09:00';
		$payment_methods = ( new PaymentProviderRegistry() )->all();
		$selected_payment_method = $appointment && isset( $appointment->payment_method ) ? $appointment->payment_method : $settings['payments']['default_method'];
		$appointment_currency = $appointment && isset( $appointment->currency ) ? $appointment->currency : $settings['company']['currency'];
		$appointment_total    = $appointment ? $appointment->total_amount : '0';

		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="yo_booking_appointment_service"><?php echo esc_html__( 'Service', 'yo-booking' ); ?></label></th>
					<td>
						<select id="yo_booking_appointment_service" name="service_id" required>
							<option value=""><?php echo esc_html__( 'Select service', 'yo-booking' ); ?></option>
							<?php foreach ( $services as $service ) : ?>
								<option value="<?php echo esc_attr( (string) $service->id ); ?>" <?php selected( $appointment ? (int) $appointment->service_id : 0, (int) $service->id ); ?>>
									<?php echo esc_html( $service->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="yo_booking_appointment_staff"><?php echo esc_html__( 'Staff', 'yo-booking' ); ?></label></th>
					<td>
						<select id="yo_booking_appointment_staff" name="staff_id">
							<option value="0"><?php echo esc_html__( 'Any available staff', 'yo-booking' ); ?></option>
							<?php foreach ( $staff_members as $staff ) : ?>
								<option value="<?php echo esc_attr( (string) $staff->id ); ?>" <?php selected( $appointment ? (int) $appointment->staff_id : 0, (int) $staff->id ); ?>>
									<?php echo esc_html( $staff->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="yo_booking_appointment_customer_id"><?php echo esc_html__( 'Existing customer', 'yo-booking' ); ?></label></th>
					<td>
						<input id="yo_booking_appointment_customer_id" name="customer_id" type="hidden" value="<?php echo esc_attr( (string) $selected_customer_id ); ?>" />
						<input type="search" class="regular-text" list="yo-booking-customer-options" data-customer-autocomplete value="<?php echo esc_attr( $customer ? $customer->name . ( $customer->email ? ' - ' . $customer->email : '' ) : '' ); ?>" placeholder="<?php echo esc_attr__( 'Search name, email, or phone...', 'yo-booking' ); ?>" autocomplete="off" />
						<datalist id="yo-booking-customer-options"></datalist>
						<p class="description"><?php echo esc_html__( 'Select a match or enter new contact details below.', 'yo-booking' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="yo_booking_appointment_customer_name"><?php echo esc_html__( 'Customer name', 'yo-booking' ); ?></label></th>
					<td><input id="yo_booking_appointment_customer_name" name="customer_name" type="text" class="regular-text" value="<?php echo esc_attr( $customer ? $customer->name : '' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="yo_booking_appointment_customer_email"><?php echo esc_html__( 'Customer email', 'yo-booking' ); ?></label></th>
					<td><input id="yo_booking_appointment_customer_email" name="customer_email" type="email" class="regular-text" value="<?php echo esc_attr( $customer ? $customer->email : '' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="yo_booking_appointment_customer_phone"><?php echo esc_html__( 'Customer phone', 'yo-booking' ); ?></label></th>
					<td>
						<input id="yo_booking_appointment_customer_phone" name="customer_phone" type="tel" class="regular-text" value="<?php echo esc_attr( $customer ? $customer->phone : ( $appointment ? $appointment->customer_phone : '' ) ); ?>" data-yo-phone data-phone-country="<?php echo esc_attr( $customer ? $customer->phone_country : ( $appointment ? $appointment->customer_phone_country_snapshot : '' ) ); ?>" data-phone-country-field="yo_booking_appointment_customer_phone_country" />
						<input id="yo_booking_appointment_customer_phone_country" name="customer_phone_country" type="hidden" value="<?php echo esc_attr( $customer ? $customer->phone_country : ( $appointment ? $appointment->customer_phone_country_snapshot : '' ) ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Date and time', 'yo-booking' ); ?></th>
					<td>
						<div class="yo-form-row">
							<div class="yo-form-field"><label for="yo_booking_appointment_date"><?php echo esc_html__( 'Date', 'yo-booking' ); ?></label><input id="yo_booking_appointment_date" name="date" type="date" required value="<?php echo esc_attr( $local_start ? $local_start->format( 'Y-m-d' ) : $requested_date ); ?>" /></div>
							<div class="yo-form-field"><label for="yo_booking_appointment_start_time"><?php echo esc_html__( 'Start time', 'yo-booking' ); ?></label><input id="yo_booking_appointment_start_time" name="start_time" type="time" required value="<?php echo esc_attr( $local_start ? $local_start->format( 'H:i' ) : $requested_time ); ?>" /></div>
							<div class="yo-form-field"><label for="yo_booking_appointment_duration"><?php echo esc_html__( 'Duration', 'yo-booking' ); ?></label><div class="yo-form-unit"><input id="yo_booking_appointment_duration" name="duration_minutes" type="number" min="5" max="1440" step="5" value="<?php echo esc_attr( (string) $duration ); ?>" /><span><?php echo esc_html__( 'minutes', 'yo-booking' ); ?></span></div></div>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="yo_booking_appointment_timezone"><?php echo esc_html__( 'Timezone', 'yo-booking' ); ?></label></th>
					<td><input name="timezone" type="hidden" value="<?php echo esc_attr( $timezone ); ?>" /><strong id="yo_booking_appointment_timezone"><?php echo esc_html( $timezone ); ?></strong><p class="description"><?php echo esc_html__( 'Inherited from WordPress Settings.', 'yo-booking' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="yo_booking_appointment_status"><?php echo esc_html__( 'Status', 'yo-booking' ); ?></label></th>
					<td><?php $this->status_select( 'yo_booking_appointment_status', 'status', $appointment ? $appointment->status : $settings['booking']['default_status'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="yo_booking_payment_method"><?php echo esc_html__( 'Payment method', 'yo-booking' ); ?></label></th>
					<td><select id="yo_booking_payment_method" name="payment_method"><?php foreach ( $payment_methods as $method_id => $provider ) : ?><option value="<?php echo esc_attr( $method_id ); ?>" <?php selected( $selected_payment_method, $method_id ); ?>><?php echo esc_html( $provider->title() ); ?></option><?php endforeach; ?></select></td>
				</tr>
				<tr>
					<th scope="row"><label for="yo_booking_payment_status"><?php echo esc_html__( 'Payment status', 'yo-booking' ); ?></label></th>
					<td><strong id="yo_booking_payment_status"><?php echo esc_html( $appointment ? $this->payment_label( $appointment->payment_status ) : __( 'Pending', 'yo-booking' ) ); ?></strong><p class="description"><?php echo esc_html__( 'Calculated automatically from payment transactions.', 'yo-booking' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="yo_booking_total_amount"><?php echo esc_html__( 'Total amount', 'yo-booking' ); ?></label></th>
					<td><input id="yo_booking_total_amount" name="total_amount" type="text" inputmode="decimal" value="<?php echo esc_attr( Currency::format_number( $appointment_total, $appointment_currency ) ); ?>" data-yo-money-input data-yo-money-raw="<?php echo esc_attr( (string) $appointment_total ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="yo_booking_customer_note"><?php echo esc_html__( 'Customer note', 'yo-booking' ); ?></label></th>
					<td><textarea id="yo_booking_customer_note" name="customer_note" class="large-text" rows="3"><?php echo esc_textarea( $appointment ? $appointment->customer_note : '' ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="yo_booking_internal_note"><?php echo esc_html__( 'Internal note', 'yo-booking' ); ?></label></th>
					<td><textarea id="yo_booking_internal_note" name="internal_note" class="large-text" rows="3"><?php echo esc_textarea( $appointment ? $appointment->internal_note : '' ); ?></textarea></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render ledger totals and bank/payment reconciliation controls.
	 *
	 * @param object $appointment Appointment row.
	 * @return void
	 */
	private function render_payment_management( $appointment ) {
		$appointment = ( new AppointmentRepository() )->find( (int) $appointment->id );
		$transactions = ( new PaymentRepository() )->for_appointment( (int) $appointment->id );
		$paid_minor   = Money::to_minor( $appointment->paid_amount, $appointment->currency );
		$refund_minor = Money::to_minor( $appointment->refunded_amount, $appointment->currency );
		$balance_minor = Money::to_minor( $appointment->balance_amount, $appointment->currency );
		$default_amount = Money::from_minor( $balance_minor > 0 ? $balance_minor : max( 0, $paid_minor - $refund_minor ), $appointment->currency );
		$actions = array(
			'paid'           => __( 'Record payment', 'yo-booking' ),
			'partially_paid' => __( 'Record partial payment', 'yo-booking' ),
			'authorized'     => __( 'Record authorization', 'yo-booking' ),
			'failed'         => __( 'Record failed payment', 'yo-booking' ),
			'cancelled'      => __( 'Record cancelled payment', 'yo-booking' ),
			'refunded'       => __( 'Record refund', 'yo-booking' ),
		);
		?>
		<section class="yo-section yo-payment-management">
			<div class="yo-section-header"><div><h2><?php echo esc_html__( 'Payment ledger', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Balances and status are calculated from immutable transaction records.', 'yo-booking' ); ?></p></div></div>
			<div class="yo-payment-summary-grid">
				<div><span><?php echo esc_html__( 'Reference', 'yo-booking' ); ?></span><strong><?php echo esc_html( $appointment->payment_reference ); ?></strong></div>
				<div><span><?php echo esc_html__( 'Total', 'yo-booking' ); ?></span><strong><?php echo esc_html( Currency::format( $appointment->total_amount, $appointment->currency ) ); ?></strong></div>
				<div><span><?php echo esc_html__( 'Paid', 'yo-booking' ); ?></span><strong><?php echo esc_html( Currency::format( $appointment->paid_amount, $appointment->currency ) ); ?></strong></div>
				<div><span><?php echo esc_html__( 'Refunded', 'yo-booking' ); ?></span><strong><?php echo esc_html( Currency::format( $appointment->refunded_amount, $appointment->currency ) ); ?></strong></div>
				<div><span><?php echo esc_html__( 'Remaining', 'yo-booking' ); ?></span><strong><?php echo esc_html( Currency::format( $appointment->balance_amount, $appointment->currency ) ); ?></strong></div>
				<div><span><?php echo esc_html__( 'Status', 'yo-booking' ); ?></span><strong><?php echo esc_html( $this->payment_label( $appointment->payment_status ) ); ?></strong></div>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yo-card yo-payment-entry-form">
				<input type="hidden" name="action" value="yo_booking_update_appointment_payment" />
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $appointment->id ); ?>" />
				<input type="hidden" name="idempotency_key" value="admin-<?php echo esc_attr( wp_generate_uuid4() ); ?>" />
				<?php wp_nonce_field( 'yo_booking_update_appointment_payment_' . (int) $appointment->id ); ?>
				<div class="yo-form-row">
					<div class="yo-form-field"><label for="yo_booking_payment_action"><?php echo esc_html__( 'Action', 'yo-booking' ); ?></label><select id="yo_booking_payment_action" name="payment_status"><?php foreach ( $actions as $status => $label ) : ?><option value="<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></div>
					<div class="yo-form-field"><label for="yo_booking_payment_amount"><?php echo esc_html__( 'Amount', 'yo-booking' ); ?></label><input id="yo_booking_payment_amount" name="amount" type="text" inputmode="decimal" value="<?php echo esc_attr( Currency::format_number( $default_amount, $appointment->currency ) ); ?>" data-yo-money-input data-yo-money-raw="<?php echo esc_attr( (string) $default_amount ); ?>" /></div>
					<div class="yo-form-field"><label for="yo_booking_transaction_id"><?php echo esc_html__( 'Transaction/reference ID', 'yo-booking' ); ?></label><input id="yo_booking_transaction_id" name="transaction_id" type="text" /></div>
				</div>
				<div class="yo-form-field"><label for="yo_booking_payment_note"><?php echo esc_html__( 'Reconciliation note', 'yo-booking' ); ?></label><textarea id="yo_booking_payment_note" name="note" rows="2"></textarea></div>
				<?php submit_button( __( 'Add transaction', 'yo-booking' ), 'secondary', 'submit', false ); ?>
			</form>
			<div class="yo-table-scroll"><table class="widefat striped"><thead><tr><th><?php echo esc_html__( 'Type', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Status', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Amount', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Transaction ID', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Note', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Created', 'yo-booking' ); ?></th></tr></thead><tbody>
			<?php if ( ! $transactions ) : ?><tr><td colspan="6"><?php echo esc_html__( 'No payment transactions yet.', 'yo-booking' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $transactions as $transaction ) : ?><tr><td><?php echo esc_html( ucfirst( $transaction->kind ) ); ?></td><td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $transaction->status ) ) ); ?></td><td><?php echo esc_html( Currency::format( $transaction->amount, $transaction->currency ) ); ?></td><td><code><?php echo esc_html( $transaction->transaction_id ? $transaction->transaction_id : '-' ); ?></code></td><td><?php echo esc_html( wp_strip_all_tags( $transaction->note ) ); ?></td><td><?php echo esc_html( DateTimeFormatter::utc( $transaction->created_at ) ); ?></td></tr><?php endforeach; ?>
			</tbody></table></div>
		</section>
		<?php
	}

	/**
	 * Render month calendar view.
	 *
	 * @return void
	 */
	private function render_calendar() {
		$services      = ( new ServiceRepository() )->all();
		$staff_members = ( new StaffRepository() )->all();

		?>
		<div class="yo-toolbar yo-calendar-filters">
			<label><?php echo esc_html__( 'Status', 'yo-booking' ); ?><select name="status" data-calendar-filter><option value=""><?php echo esc_html__( 'All statuses', 'yo-booking' ); ?></option><?php foreach ( AppointmentRepository::statuses() as $status => $label ) : ?><option value="<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
			<label><?php echo esc_html__( 'Payment', 'yo-booking' ); ?><select name="payment_status" data-calendar-filter><option value=""><?php echo esc_html__( 'All payments', 'yo-booking' ); ?></option><?php foreach ( PaymentRepository::appointment_statuses() as $payment_status => $payment_label ) : ?><option value="<?php echo esc_attr( $payment_status ); ?>"><?php echo esc_html( $payment_label ); ?></option><?php endforeach; ?></select></label>
			<label><?php echo esc_html__( 'Service', 'yo-booking' ); ?><select name="service_id" data-calendar-filter><option value="0"><?php echo esc_html__( 'All services', 'yo-booking' ); ?></option><?php foreach ( $services as $service ) : ?><option value="<?php echo esc_attr( (string) $service->id ); ?>"><?php echo esc_html( $service->name ); ?></option><?php endforeach; ?></select></label>
			<label><?php echo esc_html__( 'Staff', 'yo-booking' ); ?><select name="staff_id" data-calendar-filter><option value="0"><?php echo esc_html__( 'All staff', 'yo-booking' ); ?></option><?php foreach ( $staff_members as $staff ) : ?><option value="<?php echo esc_attr( (string) $staff->id ); ?>"><?php echo esc_html( $staff->name ); ?></option><?php endforeach; ?></select></label>
			<span class="description"><?php echo esc_html__( 'Drag or resize an appointment to reschedule it.', 'yo-booking' ); ?></span>
		</div>
		<div id="yo-booking-calendar" class="yo-fullcalendar" aria-label="<?php echo esc_attr__( 'Appointment calendar', 'yo-booking' ); ?>"></div>
		<noscript><p class="notice notice-warning"><?php echo esc_html__( 'JavaScript is required for the interactive calendar.', 'yo-booking' ); ?></p></noscript>
		<?php
	}

	/**
	 * Render the reusable appointment detail drawer.
	 *
	 * @return void
	 */
	private function render_appointment_drawer() {
		?>
		<div id="yo-appointment-drawer" class="yo-drawer" hidden>
			<button type="button" class="yo-drawer__backdrop" data-close-drawer aria-label="<?php echo esc_attr__( 'Close appointment details', 'yo-booking' ); ?>"></button>
			<aside class="yo-drawer__panel" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr__( 'Appointment details', 'yo-booking' ); ?>">
				<button type="button" class="yo-drawer__close" data-close-drawer aria-label="<?php echo esc_attr__( 'Close', 'yo-booking' ); ?>"><span class="fi fi-rr-cross" aria-hidden="true"></span></button>
				<div id="yo-appointment-drawer-body" class="yo-drawer__body"></div>
			</aside>
		</div>
		<?php
	}

	/**
	 * Save appointment.
	 *
	 * @return void
	 */
	public function save_appointment() {
		$this->ensure_capability( Capabilities::appointments() );
		check_admin_referer( 'yo_booking_save_appointment' );

		$data             = wp_unslash( $_POST );
		$existing         = ! empty( $data['id'] ) ? ( new AppointmentRepository() )->find( absint( $data['id'] ) ) : null;
		$currency         = $existing && isset( $existing->currency ) ? $existing->currency : ( new SettingsRepository() )->get( 'company.currency', 'USD' );
		if ( isset( $data['total_amount'] ) ) {
			$data['total_amount'] = Currency::parse_number( $data['total_amount'], $currency );
		}
		$data['timezone'] = DateTimeFormatter::timezone_name();
		$result           = ( new AppointmentRepository() )->save( $data );

		$this->redirect_result( 'yo-booking-appointments', $result );
	}

	/**
	 * Update quick status.
	 *
	 * @return void
	 */
	public function update_status() {
		$this->ensure_capability( Capabilities::appointments() );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'yo_booking_update_appointment_status_' . $id );

		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'pending';
		$reason = isset( $_POST['cancellation_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['cancellation_reason'] ) ) : '';
		$result = ( new AppointmentRepository() )->update_status( $id, $status, $reason );

		$this->redirect_result( 'yo-booking-appointments', is_wp_error( $result ) ? $result : $id );
	}

	/**
	 * Update payment status from quick admin actions.
	 *
	 * @return void
	 */
	public function update_payment() {
		$this->ensure_capability( Capabilities::appointments() );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'yo_booking_update_appointment_payment_' . $id );

		$appointment = ( new AppointmentRepository() )->find( $id );

		if ( ! $appointment ) {
			$this->redirect_result(
				'yo-booking-appointments',
				new \WP_Error( 'yo_booking_appointment_not_found', __( 'Appointment not found.', 'yo-booking' ) )
			);
		}

		$status   = isset( $_POST['payment_status'] ) ? sanitize_key( wp_unslash( $_POST['payment_status'] ) ) : 'pending';
		$amount   = isset( $_POST['amount'] ) ? Currency::parse_number( sanitize_text_field( wp_unslash( $_POST['amount'] ) ), $appointment->currency ) : $appointment->total_amount;
		$note     = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$result   = ( new PaymentRepository() )->mark_appointment(
			$id,
			$status,
			$amount,
			$appointment->currency,
			$note,
			array(
				'transaction_id' => isset( $_POST['transaction_id'] ) ? sanitize_text_field( wp_unslash( $_POST['transaction_id'] ) ) : '',
				'idempotency_key' => isset( $_POST['idempotency_key'] ) ? sanitize_text_field( wp_unslash( $_POST['idempotency_key'] ) ) : '',
			)
		);

		$this->redirect_result( 'yo-booking-appointments', is_wp_error( $result ) ? $result : $id );
	}

	/**
	 * Update internal note.
	 *
	 * @return void
	 */
	public function update_note() {
		$this->ensure_capability( Capabilities::appointments() );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'yo_booking_update_appointment_note_' . $id );

		$note   = isset( $_POST['internal_note'] ) ? wp_kses_post( wp_unslash( $_POST['internal_note'] ) ) : '';
		$result = ( new AppointmentRepository() )->update_internal_note( $id, $note );

		$this->redirect_result( 'yo-booking-appointments', is_wp_error( $result ) ? $result : $id );
	}

	/**
	 * Render note edit form.
	 *
	 * @param object $appointment Appointment row.
	 * @return void
	 */
	private function render_note_form( $appointment ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="yo_booking_update_appointment_note" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $appointment->id ); ?>" />
			<?php wp_nonce_field( 'yo_booking_update_appointment_note_' . (int) $appointment->id ); ?>
			<textarea name="internal_note" rows="2" class="large-text"><?php echo esc_textarea( $appointment->internal_note ); ?></textarea>
			<button type="submit" class="button button-small"><?php echo esc_html__( 'Save Note', 'yo-booking' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render status transition buttons.
	 *
	 * @param object $appointment Appointment row.
	 * @return void
	 */
	private function render_status_buttons( $appointment ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yo-row-actions yo-appointment-status-form" data-appointment-id="<?php echo esc_attr( (string) $appointment->id ); ?>" data-yo-async>
			<input type="hidden" name="action" value="yo_booking_update_appointment_status" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $appointment->id ); ?>" />
			<?php wp_nonce_field( 'yo_booking_update_appointment_status_' . (int) $appointment->id ); ?>
			<select name="status" aria-label="<?php echo esc_attr__( 'Change status', 'yo-booking' ); ?>">
				<?php foreach ( AppointmentRepository::statuses() as $status => $label ) : ?><option value="<?php echo esc_attr( $status ); ?>" <?php selected( $appointment->status, $status ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
			</select>
			<button type="submit" class="button button-small"><?php echo esc_html__( 'Update', 'yo-booking' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render payment status and quick actions.
	 *
	 * @param object $appointment Appointment row.
	 * @return void
	 */
	private function render_payment_cell( $appointment ) {
		$summary       = ( new PaymentManager() )->summary_for_appointment( $appointment );
		$latest        = ( new PaymentRepository() )->latest_for_appointment( (int) $appointment->id );
		$amount_due    = isset( $summary['amount_due'] ) ? (string) $summary['amount_due'] : '0.00';
		?>
		<?php $this->render_status_badge( $appointment->payment_status, $this->payment_label( $appointment->payment_status ) ); ?><br />
		<?php echo esc_html( Currency::format( $appointment->total_amount, $appointment->currency ) ); ?><br />
		<span class="description"><?php echo esc_html( isset( $summary['provider_title'] ) ? $summary['provider_title'] : $appointment->payment_method ); ?></span><br />
		<?php // translators: %s: formatted amount due at booking. ?>
		<span class="description"><?php echo esc_html( sprintf( __( 'Due now: %s', 'yo-booking' ), Currency::format( $amount_due, $appointment->currency ) ) ); ?></span>
		<?php if ( $latest ) : ?>
			<br /><span class="description"><?php echo esc_html( $latest->method_title . ' ' . $latest->status . ' ' . Currency::format( $latest->amount, $latest->currency ) ); ?></span>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render one quick payment form button.
	 *
	 * @param object $appointment Appointment row.
	 * @param string $status Payment status.
	 * @param string $label Button label.
	 * @param string $amount Amount.
	 * @return void
	 */
	private function render_payment_button( $appointment, $status, $label, $amount ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
			<input type="hidden" name="action" value="yo_booking_update_appointment_payment" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $appointment->id ); ?>" />
			<input type="hidden" name="payment_status" value="<?php echo esc_attr( $status ); ?>" />
			<input type="hidden" name="amount" value="<?php echo esc_attr( (string) $amount ); ?>" />
			<input type="hidden" name="note" value="<?php echo esc_attr( 'Admin marked payment ' . $status ); ?>" />
			<?php wp_nonce_field( 'yo_booking_update_appointment_payment_' . (int) $appointment->id ); ?>
			<button type="submit" class="button button-small"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render appointment status select.
	 *
	 * @param string $id Field ID.
	 * @param string $name Field name.
	 * @param string $selected Selected status.
	 * @return void
	 */
	private function status_select( $id, $name, $selected ) {
		?>
		<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>">
			<?php foreach ( AppointmentRepository::statuses() as $status => $label ) : ?>
				<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $selected, $status ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render payment status select.
	 *
	 * @param string $id Field ID.
	 * @param string $name Field name.
	 * @param string $selected Selected status.
	 * @return void
	 */
	private function payment_select( $id, $name, $selected ) {
		$statuses = PaymentRepository::appointment_statuses();

		?>
		<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>">
			<?php foreach ( $statuses as $status => $label ) : ?>
				<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $selected, $status ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Return a payment status label.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private function payment_label( $status ) {
		$statuses = PaymentRepository::appointment_statuses();

		return isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;
	}

	/**
	 * Return a status label.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private function status_label( $status ) {
		$statuses = AppointmentRepository::statuses();

		return isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;
	}

	/**
	 * Convert a UTC datetime to local appointment time.
	 *
	 * @param string $utc_datetime UTC datetime.
	 * @param string $timezone Timezone name.
	 * @return DateTimeImmutable
	 */
	private function local_datetime( $utc_datetime, $timezone ) {
		try {
			return ( new DateTimeImmutable( $utc_datetime, new DateTimeZone( 'UTC' ) ) )->setTimezone( $this->timezone( $timezone ) );
		} catch ( Exception $exception ) {
			return new DateTimeImmutable( 'now', $this->timezone( $timezone ) );
		}
	}

	/**
	 * Resolve timezone.
	 *
	 * @param string $timezone Timezone name.
	 * @return DateTimeZone
	 */
	private function timezone( $timezone ) {
		try {
			return new DateTimeZone( $timezone ? $timezone : 'UTC' );
		} catch ( Exception $exception ) {
			return new DateTimeZone( 'UTC' );
		}
	}
}
