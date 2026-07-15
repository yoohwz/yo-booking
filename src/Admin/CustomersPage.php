<?php
/**
 * Customers admin page.
 *
 * @package YoBooking
 */

namespace YoBooking\Admin;

use YoBooking\Payments\Currency;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use YoBooking\Repositories\AuditLogRepository;
use YoBooking\Repositories\AppointmentRepository;
use YoBooking\Repositories\CustomerRepository;
use YoBooking\Settings\Repository as SettingsRepository;
use YoBooking\Support\Capabilities;
use YoBooking\Support\DateTimeFormatter;

defined( 'ABSPATH' ) || exit;

/**
 * Registers customer CRUD.
 */
final class CustomersPage extends AbstractAdminPage {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_yo_booking_save_customer', array( $this, 'save_customer' ) );
		add_action( 'admin_post_yo_booking_delete_customer', array( $this, 'delete_customer' ) );
	}

	/**
	 * Register the customers submenu page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'yo-booking',
			__( 'Customers', 'yo-booking' ),
			__( 'Customers', 'yo-booking' ),
			Capabilities::appointments(),
			'yo-booking-customers',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the customers page.
	 *
	 * @return void
	 */
	public function render() {
		$this->ensure_capability( Capabilities::appointments() );

		$repository = new CustomerRepository();
		$edit_id    = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing    = $edit_id ? $repository->find( $edit_id ) : null;
		$is_new     = isset( $_GET['action'] ) && 'new' === sanitize_key( wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$profile_id = isset( $_GET['profile'] ) ? absint( $_GET['profile'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$profile    = $profile_id ? $repository->find( $profile_id ) : null;
		$search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page   = 25;
		$total      = $repository->count_matching( $search );
		$customers  = $repository->page_with_stats( array( 'search' => $search, 'limit' => $per_page, 'offset' => ( $paged - 1 ) * $per_page ) );

		?>
		<div class="wrap yo-booking-admin">
			<?php if ( $profile ) : ?>
				<?php
				$profile_actions  = '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=yo-booking-customers' ) ) . '"><span class="fi fi-rr-arrow-left" aria-hidden="true"></span>' . esc_html__( 'Customer directory', 'yo-booking' ) . '</a>';
				$profile_actions .= '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=yo-booking-customers&edit=' . absint( $profile->id ) ) ) . '">' . esc_html__( 'Edit profile', 'yo-booking' ) . '</a>';
				$profile_actions .= '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=yo-booking-appointments&action=new&customer_id=' . absint( $profile->id ) ) ) . '"><span class="fi fi-rr-plus" aria-hidden="true"></span>' . esc_html__( 'Add appointment', 'yo-booking' ) . '</a>';
				$this->render_page_header( $profile->name, trim( $profile->email . ( $profile->phone ? ' - ' . $profile->phone : '' ), ' -' ), $profile_actions );
				?>
			<?php else : ?>
				<?php $customer_export = wp_nonce_url( add_query_arg( array( 'action' => 'yo_booking_export_customers', 's' => $search ), admin_url( 'admin-post.php' ) ), 'yo_booking_export_customers' ); ?>
				<?php $customer_actions = ( current_user_can( Capabilities::export() ) ? '<a class="button" href="' . esc_url( $customer_export ) . '"><span class="fi fi-rr-download" aria-hidden="true"></span>' . esc_html__( 'Export CSV', 'yo-booking' ) . '</a>' : '' ) . '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=yo-booking-customers&action=new' ) ) . '"><span class="fi fi-rr-plus" aria-hidden="true"></span>' . esc_html__( 'Add customer', 'yo-booking' ) . '</a>'; ?>
				<?php $this->render_page_header( __( 'Customers', 'yo-booking' ), __( 'Maintain customer contact details and booking preferences.', 'yo-booking' ), $customer_actions ); ?>
			<?php endif; ?>
			<?php $this->render_notice(); ?>
			<?php if ( $profile ) : ?>
				<?php $this->render_customer_profile( $profile ); ?>
			</div>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( $editing || $is_new ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yo-editor">
				<input type="hidden" name="action" value="yo_booking_save_customer" />
				<input type="hidden" name="id" value="<?php echo esc_attr( $editing ? (string) $editing->id : '0' ); ?>" />
				<?php wp_nonce_field( 'yo_booking_save_customer' ); ?>

				<div class="yo-editor__header"><h2><?php echo esc_html( $editing ? __( 'Edit customer', 'yo-booking' ) : __( 'Add customer', 'yo-booking' ) ); ?></h2><a href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-customers' ) ); ?>"><?php echo esc_html__( 'Close', 'yo-booking' ); ?></a></div>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="yo_booking_customer_name"><?php echo esc_html__( 'Name', 'yo-booking' ); ?></label></th>
							<td><input id="yo_booking_customer_name" name="name" type="text" class="regular-text" required value="<?php echo esc_attr( $editing ? $editing->name : '' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_customer_user_id"><?php echo esc_html__( 'WordPress user ID', 'yo-booking' ); ?></label></th>
							<td><input id="yo_booking_customer_user_id" name="user_id" type="number" min="0" value="<?php echo esc_attr( $editing ? (string) $editing->user_id : '0' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_customer_email"><?php echo esc_html__( 'Email', 'yo-booking' ); ?></label></th>
							<td><input id="yo_booking_customer_email" name="email" type="email" class="regular-text" value="<?php echo esc_attr( $editing ? $editing->email : '' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_customer_phone"><?php echo esc_html__( 'Phone', 'yo-booking' ); ?></label></th>
							<td><input id="yo_booking_customer_phone" name="phone" type="text" class="regular-text" value="<?php echo esc_attr( $editing ? $editing->phone : '' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_customer_timezone"><?php echo esc_html__( 'Timezone', 'yo-booking' ); ?></label></th>
							<td><input name="timezone" type="hidden" value="<?php echo esc_attr( DateTimeFormatter::timezone_name() ); ?>" /><strong id="yo_booking_customer_timezone"><?php echo esc_html( DateTimeFormatter::timezone_name() ); ?></strong><p class="description"><?php echo esc_html__( 'Inherited from WordPress Settings.', 'yo-booking' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Marketing consent', 'yo-booking' ); ?></th>
							<td>
								<label>
									<input name="marketing_consent" type="checkbox" value="1" <?php checked( $editing ? (int) $editing->marketing_consent : 0, 1 ); ?> />
									<?php echo esc_html__( 'Customer agreed to marketing messages.', 'yo-booking' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_customer_notes"><?php echo esc_html__( 'Notes', 'yo-booking' ); ?></label></th>
							<td><textarea id="yo_booking_customer_notes" name="notes" class="large-text" rows="4"><?php echo esc_textarea( $editing ? $editing->notes : '' ); ?></textarea></td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( $editing ? __( 'Update Customer', 'yo-booking' ) : __( 'Add Customer', 'yo-booking' ) ); ?>
			</form>
			<?php endif; ?>

			<?php // translators: %d: total number of customers. ?>
			<div class="yo-section-header"><h2><?php echo esc_html__( 'Customer directory', 'yo-booking' ); ?></h2><span class="description"><?php echo esc_html( sprintf( _n( '%d customer', '%d customers', $total, 'yo-booking' ), $total ) ); ?></span></div>
			<form method="get" class="yo-toolbar">
				<input type="hidden" name="page" value="yo-booking-customers" />
				<label><?php echo esc_html__( 'Search customers', 'yo-booking' ); ?><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Name, email, phone...', 'yo-booking' ); ?>" /></label>
				<button class="button" type="submit"><?php echo esc_html__( 'Search', 'yo-booking' ); ?></button>
				<?php if ( $search ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-customers' ) ); ?>"><?php echo esc_html__( 'Clear', 'yo-booking' ); ?></a><?php endif; ?>
			</form>
			<table class="widefat striped" id="yo-booking-customers-table">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Name', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Contact', 'yo-booking' ); ?></th>
					<th><?php echo esc_html__( 'Timezone', 'yo-booking' ); ?></th>
					<th><?php echo esc_html__( 'Bookings', 'yo-booking' ); ?></th>
					<th><?php echo esc_html__( 'Paid total', 'yo-booking' ); ?></th>
					<th><?php echo esc_html__( 'Marketing', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Actions', 'yo-booking' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $customers ) ) : ?>
					<?php $this->render_empty_row( 7, __( 'No customers yet', 'yo-booking' ), __( 'Customers are created automatically from bookings or can be added here.', 'yo-booking' ) ); ?>
					<?php endif; ?>
					<?php foreach ( $customers as $customer ) : ?>
						<tr>
							<?php // translators: %d: WordPress user ID linked to the customer. ?>
							<td><strong><?php echo esc_html( $customer->name ); ?></strong><br /><?php echo esc_html( sprintf( __( 'User ID: %d', 'yo-booking' ), (int) $customer->user_id ) ); ?></td>
							<td>
								<?php echo esc_html( $customer->email ); ?>
								<?php if ( $customer->phone ) : ?>
									<br /><?php echo esc_html( $customer->phone ); ?>
								<?php endif; ?>
							</td>
					<td><?php echo esc_html( DateTimeFormatter::timezone_name() ); ?></td>
					<td><?php echo esc_html( (string) $customer->booking_count ); ?></td>
					<td><?php echo esc_html( Currency::format( $customer->paid_total, $this->reporting_currency() ) ); ?></td>
					<td><?php echo esc_html( $customer->marketing_consent ? __( 'Yes', 'yo-booking' ) : __( 'No', 'yo-booking' ) ); ?></td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-customers&profile=' . absint( $customer->id ) ) ); ?>"><?php echo esc_html__( 'View', 'yo-booking' ); ?></a>
						<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-customers&edit=' . absint( $customer->id ) ) ); ?>"><?php echo esc_html__( 'Edit', 'yo-booking' ); ?></a>
								<?php $this->delete_button( (int) $customer->id ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $total > $per_page ) : ?>
				<div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => (int) ceil( $total / $per_page ), 'prev_text' => '&lsaquo;', 'next_text' => '&rsaquo;' ) ) ); ?></div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render customer metrics and booking history.
	 *
	 * @param object $customer Customer row.
	 * @return void
	 */
	private function render_customer_profile( $customer ) {
		$appointments = new AppointmentRepository();
		$stats        = $appointments->customer_stats( (int) $customer->id );
		$history      = $appointments->for_customer_id( (int) $customer->id, 200 );
		?>
		<div class="yo-grid yo-grid--stats">
			<?php $this->render_customer_stat( __( 'Total bookings', 'yo-booking' ), (int) $stats->booking_count ); ?>
			<?php $this->render_customer_stat( __( 'Upcoming', 'yo-booking' ), (int) $stats->upcoming_count ); ?>
			<?php $this->render_customer_stat( __( 'Paid total', 'yo-booking' ), Currency::format( $stats->paid_total, $this->reporting_currency() ) ); ?>
			<?php $this->render_customer_stat( __( 'Cancelled', 'yo-booking' ), (int) $stats->cancelled_count ); ?>
		</div>
		<div class="yo-grid yo-grid--2 yo-section">
			<section class="yo-card"><h2><?php echo esc_html__( 'Contact details', 'yo-booking' ); ?></h2><dl class="yo-detail-grid"><div class="yo-detail-row"><dt><?php echo esc_html__( 'Email', 'yo-booking' ); ?></dt><dd><?php echo esc_html( $customer->email ? $customer->email : '-' ); ?></dd></div><div class="yo-detail-row"><dt><?php echo esc_html__( 'Phone', 'yo-booking' ); ?></dt><dd><?php echo esc_html( $customer->phone ? $customer->phone : '-' ); ?></dd></div><div class="yo-detail-row"><dt><?php echo esc_html__( 'Timezone', 'yo-booking' ); ?></dt><dd><?php echo esc_html( DateTimeFormatter::timezone_name() ); ?></dd></div><div class="yo-detail-row"><dt><?php echo esc_html__( 'WordPress user', 'yo-booking' ); ?></dt><dd><?php echo esc_html( $customer->user_id ? '#' . (int) $customer->user_id : '-' ); ?></dd></div></dl></section>
			<section class="yo-card"><h2><?php echo esc_html__( 'Customer notes', 'yo-booking' ); ?></h2><div class="yo-customer-notes"><?php echo $customer->notes ? wp_kses_post( wpautop( $customer->notes ) ) : '<p class="description">' . esc_html__( 'No customer notes.', 'yo-booking' ) . '</p>'; ?></div></section>
		</div>
		<?php // translators: %d: number of appointments. ?>
		<div class="yo-section-header yo-section"><h2><?php echo esc_html__( 'Booking history', 'yo-booking' ); ?></h2><span class="description"><?php echo esc_html( sprintf( _n( '%d appointment', '%d appointments', count( $history ), 'yo-booking' ), count( $history ) ) ); ?></span></div>
		<table class="widefat striped">
			<thead><tr><th><?php echo esc_html__( 'Date', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Service', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Staff', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Status', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Payment', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Total', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Actions', 'yo-booking' ); ?></th></tr></thead>
			<tbody>
				<?php if ( empty( $history ) ) : ?><?php $this->render_empty_row( 7, __( 'No booking history', 'yo-booking' ) ); ?><?php endif; ?>
				<?php foreach ( $history as $appointment ) : ?>
					<tr><td><?php echo esc_html( $this->customer_local_date( $appointment->start_at, $appointment->timezone ) ); ?></td><td><?php echo esc_html( $appointment->service_name ); ?></td><td><?php echo esc_html( $appointment->staff_name ? $appointment->staff_name : __( 'Unassigned', 'yo-booking' ) ); ?></td><td><?php $this->render_status_badge( $appointment->status ); ?></td><td><?php $this->render_status_badge( $appointment->payment_status ); ?></td><td><?php echo esc_html( Currency::format( $appointment->total_amount, $appointment->currency ) ); ?></td><td><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-appointments&edit=' . absint( $appointment->id ) ) ); ?>"><?php echo esc_html__( 'Open', 'yo-booking' ); ?></a></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** @return string */
	private function reporting_currency() {
		$currency = Currency::normalize( ( new SettingsRepository() )->get( 'company.currency', 'USD' ) );
		return $currency ? $currency : 'USD';
	}

	/** @param string $label Label. @param mixed $value Value. @return void */
	private function render_customer_stat( $label, $value ) {
		?><div class="yo-card yo-stat"><span class="yo-stat__label"><?php echo esc_html( $label ); ?></span><strong class="yo-stat__value"><?php echo esc_html( (string) $value ); ?></strong></div><?php
	}

	/** @param string $datetime UTC date. @param string $timezone Timezone. @return string */
	private function customer_local_date( $datetime, $timezone ) {
		return DateTimeFormatter::utc( $datetime );
	}

	/**
	 * Save a customer.
	 *
	 * @return void
	 */
	public function save_customer() {
		$this->ensure_capability( Capabilities::appointments() );
		check_admin_referer( 'yo_booking_save_customer' );

		$data             = wp_unslash( $_POST );
		$data['timezone'] = DateTimeFormatter::timezone_name();
		$result           = ( new CustomerRepository() )->save( $data );
		if ( ! is_wp_error( $result ) ) {
			$mode = empty( $_POST['id'] ) ? 'created' : 'updated';
			// translators: 1: customer database ID, 2: action name such as created or updated.
			( new AuditLogRepository() )->record( 'customer.' . $mode, 'customer', $result, sprintf( __( 'Customer #%1$d %2$s', 'yo-booking' ), $result, $mode ) );
		}

		$this->redirect_result( 'yo-booking-customers', $result );
	}

	/**
	 * Delete a customer.
	 *
	 * @return void
	 */
	public function delete_customer() {
		$this->ensure_capability( Capabilities::appointments() );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'yo_booking_delete_customer_' . $id );

		if ( ( new CustomerRepository() )->delete( $id ) ) {
			// translators: %d: customer database ID.
			( new AuditLogRepository() )->record( 'customer.deleted', 'customer', $id, sprintf( __( 'Customer #%d deleted', 'yo-booking' ), $id ) );
		}
		$this->redirect( 'yo-booking-customers', 'deleted' );
	}

	/**
	 * Render a POST delete button.
	 *
	 * @param int $id Customer ID.
	 * @return void
	 */
	private function delete_button( $id ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
			<input type="hidden" name="action" value="yo_booking_delete_customer" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( 'yo_booking_delete_customer_' . $id ); ?>
			<button type="submit" class="button button-small button-link-delete"><?php echo esc_html__( 'Delete', 'yo-booking' ); ?></button>
		</form>
		<?php
	}
}
