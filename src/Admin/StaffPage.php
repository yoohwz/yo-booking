<?php
/**
 * Staff admin page.
 *
 * @package YoBooking
 */

namespace YoBooking\Admin;

use YoBooking\Repositories\AuditLogRepository;
use YoBooking\Repositories\ServiceRepository;
use YoBooking\Repositories\StaffRepository;
use YoBooking\Repositories\StaffServiceRepository;
use YoBooking\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers staff CRUD and service assignment.
 */
final class StaffPage extends AbstractAdminPage {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_yo_booking_save_staff', array( $this, 'save_staff' ) );
		add_action( 'admin_post_yo_booking_delete_staff', array( $this, 'delete_staff' ) );
	}

	/**
	 * Register the staff submenu page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'yo-booking',
			__( 'Staff', 'yo-booking' ),
			__( 'Staff', 'yo-booking' ),
			Capabilities::manage(),
			'yo-booking-staff',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the staff page.
	 *
	 * @return void
	 */
	public function render() {
		$this->ensure_capability();

		$staff_repository        = new StaffRepository();
		$service_repository      = new ServiceRepository();
		$staff_service_repository = new StaffServiceRepository();
		$edit_id                 = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing                 = $edit_id ? $staff_repository->find( $edit_id ) : null;
		$is_new                  = isset( $_GET['action'] ) && 'new' === sanitize_key( wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$staff_members           = $staff_repository->all();
		$services                = $service_repository->all();
		$wordpress_users         = get_users(
			array(
				'fields'  => array( 'ID', 'display_name', 'user_email' ),
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);
		$assigned_service_ids    = $editing ? $staff_service_repository->service_ids_for_staff( (int) $editing->id ) : array();
		$service_counts          = $staff_service_repository->counts_by_staff();

		?>
		<div class="wrap yo-booking-admin">
			<?php $this->render_page_header( __( 'Staff', 'yo-booking' ), __( 'Manage team profiles, assigned services, and availability.', 'yo-booking' ), '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=yo-booking-staff&action=new' ) ) . '"><span class="fi fi-rr-plus" aria-hidden="true"></span>' . esc_html__( 'Add staff member', 'yo-booking' ) . '</a>' ); ?>
			<?php $this->render_notice(); ?>

			<?php if ( $editing || $is_new ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yo-editor">
				<input type="hidden" name="action" value="yo_booking_save_staff" />
				<input type="hidden" name="id" value="<?php echo esc_attr( $editing ? (string) $editing->id : '0' ); ?>" />
				<?php wp_nonce_field( 'yo_booking_save_staff' ); ?>

				<div class="yo-editor__header"><h2><?php echo esc_html( $editing ? __( 'Edit staff member', 'yo-booking' ) : __( 'Add staff member', 'yo-booking' ) ); ?></h2><a href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-staff' ) ); ?>"><?php echo esc_html__( 'Close', 'yo-booking' ); ?></a></div>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="yo_booking_staff_name"><?php echo esc_html__( 'Name', 'yo-booking' ); ?></label></th>
							<td><input id="yo_booking_staff_name" name="name" type="text" class="regular-text" required value="<?php echo esc_attr( $editing ? $editing->name : '' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_staff_slug"><?php echo esc_html__( 'Slug', 'yo-booking' ); ?></label></th>
							<td><input id="yo_booking_staff_slug" name="slug" type="text" class="regular-text" value="<?php echo esc_attr( $editing ? $editing->slug : '' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_staff_user_id"><?php echo esc_html__( 'WordPress user', 'yo-booking' ); ?></label></th>
							<td>
								<select id="yo_booking_staff_user_id" name="user_id" class="regular-text" data-searchable-select data-search-placeholder="<?php echo esc_attr__( 'Search users by name or email...', 'yo-booking' ); ?>" data-no-results="<?php echo esc_attr__( 'No users found', 'yo-booking' ); ?>">
									<option value="0"><?php echo esc_html__( 'No linked user', 'yo-booking' ); ?></option>
									<?php foreach ( $wordpress_users as $wordpress_user ) : ?>
										<option value="<?php echo esc_attr( (string) $wordpress_user->ID ); ?>" <?php selected( $editing ? (int) $editing->user_id : 0, (int) $wordpress_user->ID ); ?>><?php echo esc_html( $wordpress_user->display_name . ' — ' . $wordpress_user->user_email ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php echo esc_html__( 'Search and select the WordPress account linked to this staff member.', 'yo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_staff_email"><?php echo esc_html__( 'Email', 'yo-booking' ); ?></label></th>
							<td><input id="yo_booking_staff_email" name="email" type="email" class="regular-text" value="<?php echo esc_attr( $editing ? $editing->email : '' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_staff_phone"><?php echo esc_html__( 'Phone', 'yo-booking' ); ?></label></th>
							<td>
								<input id="yo_booking_staff_phone" name="phone" type="tel" class="regular-text" value="<?php echo esc_attr( $editing ? $editing->phone : '' ); ?>" data-yo-phone data-phone-country="<?php echo esc_attr( $editing ? $editing->phone_country : '' ); ?>" data-phone-country-field="yo_booking_staff_phone_country" />
								<input id="yo_booking_staff_phone_country" name="phone_country" type="hidden" value="<?php echo esc_attr( $editing ? $editing->phone_country : '' ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_staff_color"><?php echo esc_html__( 'Color', 'yo-booking' ); ?></label></th>
							<td><?php $this->render_color_control( 'yo_booking_staff_color', 'color', $editing && $editing->color ? $editing->color : '#16a34a', __( 'Color', 'yo-booking' ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_staff_sort_order"><?php echo esc_html__( 'Sort order', 'yo-booking' ); ?></label></th>
							<td><input id="yo_booking_staff_sort_order" name="sort_order" type="number" value="<?php echo esc_attr( $editing ? (string) $editing->sort_order : '0' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_staff_status"><?php echo esc_html__( 'Status', 'yo-booking' ); ?></label></th>
							<td><?php $this->status_select( 'yo_booking_staff_status', 'status', $editing ? $editing->status : 'active' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_staff_bio"><?php echo esc_html__( 'Bio', 'yo-booking' ); ?></label></th>
							<td><textarea id="yo_booking_staff_bio" name="bio" class="large-text" rows="4"><?php echo esc_textarea( $editing ? $editing->bio : '' ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Services', 'yo-booking' ); ?></th>
							<td>
								<?php if ( empty( $services ) ) : ?>
									<p><?php echo esc_html__( 'Create services before assigning them to staff.', 'yo-booking' ); ?></p>
								<?php endif; ?>
								<?php foreach ( $services as $service ) : ?>
									<label style="display:block; margin: 0 0 6px;">
								<input name="service_ids[]" type="checkbox" value="<?php echo esc_attr( (string) $service->id ); ?>" <?php checked( in_array( (int) $service->id, $assigned_service_ids, true ) ); ?> />
								<?php echo esc_html( $service->name ); ?>
								<?php // translators: %d: service duration in minutes. ?>
								<span class="description"><?php echo esc_html( sprintf( __( '(%d minutes)', 'yo-booking' ), (int) $service->duration_minutes ) ); ?></span>
									</label>
								<?php endforeach; ?>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( $editing ? __( 'Update Staff Member', 'yo-booking' ) : __( 'Add Staff Member', 'yo-booking' ) ); ?>
			</form>
			<?php endif; ?>

			<?php // translators: %d: number of configured staff members. ?>
			<div class="yo-section-header"><h2><?php echo esc_html__( 'Configured staff', 'yo-booking' ); ?></h2><span class="description"><?php echo esc_html( sprintf( _n( '%d staff member', '%d staff members', count( $staff_members ), 'yo-booking' ), count( $staff_members ) ) ); ?></span></div>
			<div class="yo-toolbar"><label><?php echo esc_html__( 'Search staff', 'yo-booking' ); ?><input type="search" data-yo-table-search="yo-booking-staff-table" placeholder="<?php echo esc_attr__( 'Name, email, phone...', 'yo-booking' ); ?>" /></label></div>
			<table class="widefat striped" id="yo-booking-staff-table">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Name', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Contact', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Services', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Actions', 'yo-booking' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $staff_members ) ) : ?>
						<?php $this->render_empty_row( 5, __( 'No staff members yet', 'yo-booking' ), __( 'Add a staff member and assign services to accept bookings.', 'yo-booking' ) ); ?>
					<?php endif; ?>
					<?php foreach ( $staff_members as $staff ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $staff->name ); ?></strong><br /><code><?php echo esc_html( $staff->slug ); ?></code></td>
							<td>
								<?php echo esc_html( $staff->email ); ?>
								<?php if ( $staff->phone ) : ?>
									<br /><?php echo esc_html( $staff->phone ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) ( isset( $service_counts[ (int) $staff->id ] ) ? $service_counts[ (int) $staff->id ] : 0 ) ); ?></td>
						<td><?php $this->render_status_badge( $staff->status ); ?></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-staff&edit=' . absint( $staff->id ) ) ); ?>"><?php echo esc_html__( 'Edit', 'yo-booking' ); ?></a>
								<?php $this->delete_button( (int) $staff->id ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Save a staff member and service assignment.
	 *
	 * @return void
	 */
	public function save_staff() {
		$this->ensure_capability();
		check_admin_referer( 'yo_booking_save_staff' );

		$data   = wp_unslash( $_POST );
		$result = ( new StaffRepository() )->save( $data );

		if ( is_wp_error( $result ) ) {
			$this->redirect_result( 'yo-booking-staff', $result );
		}

		$service_ids = isset( $data['service_ids'] ) && is_array( $data['service_ids'] ) ? $data['service_ids'] : array();
		( new StaffServiceRepository() )->sync_for_staff( (int) $result, $service_ids );
		$mode = empty( $data['id'] ) ? 'created' : 'updated';
		// translators: 1: staff database ID, 2: action name such as created or updated.
		( new AuditLogRepository() )->record( 'staff.' . $mode, 'staff', $result, sprintf( __( 'Staff #%1$d %2$s', 'yo-booking' ), $result, $mode ) );

		$this->redirect( 'yo-booking-staff', 'saved' );
	}

	/**
	 * Delete a staff member.
	 *
	 * @return void
	 */
	public function delete_staff() {
		$this->ensure_capability();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'yo_booking_delete_staff_' . $id );

		if ( ( new StaffRepository() )->delete( $id ) ) {
			// translators: %d: staff database ID.
			( new AuditLogRepository() )->record( 'staff.deleted', 'staff', $id, sprintf( __( 'Staff #%d deleted', 'yo-booking' ), $id ) );
		}
		$this->redirect( 'yo-booking-staff', 'deleted' );
	}

	/**
	 * Render a status select.
	 *
	 * @param string $id Field ID.
	 * @param string $name Field name.
	 * @param string $value Selected value.
	 * @return void
	 */
	private function status_select( $id, $name, $value ) {
		?>
		<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>">
			<option value="active" <?php selected( $value, 'active' ); ?>><?php echo esc_html__( 'Active', 'yo-booking' ); ?></option>
			<option value="inactive" <?php selected( $value, 'inactive' ); ?>><?php echo esc_html__( 'Inactive', 'yo-booking' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Render a POST delete button.
	 *
	 * @param int $id Staff ID.
	 * @return void
	 */
	private function delete_button( $id ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
			<input type="hidden" name="action" value="yo_booking_delete_staff" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( 'yo_booking_delete_staff_' . $id ); ?>
			<button type="submit" class="button button-small button-link-delete"><?php echo esc_html__( 'Delete', 'yo-booking' ); ?></button>
		</form>
		<?php
	}
}
