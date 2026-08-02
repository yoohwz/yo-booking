<?php
/**
 * Services and categories admin pages.
 *
 * @package YoBooking
 */

namespace YoBooking\Admin;

use YoBooking\Payments\Currency;
use YoBooking\Repositories\AuditLogRepository;
use YoBooking\Repositories\ServiceCategoryRepository;
use YoBooking\Repositories\ServiceRepository;
use YoBooking\Settings\Repository as SettingsRepository;
use YoBooking\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers services and service categories CRUD.
 */
final class ServicesPage extends AbstractAdminPage {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_yo_booking_save_service', array( $this, 'save_service' ) );
		add_action( 'admin_post_yo_booking_delete_service', array( $this, 'delete_service' ) );
		add_action( 'admin_post_yo_booking_save_category', array( $this, 'save_category' ) );
		add_action( 'admin_post_yo_booking_delete_category', array( $this, 'delete_category' ) );
	}

	/**
	 * Register submenu pages.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'yo-booking',
			__( 'Services', 'yo-booking' ),
			__( 'Services', 'yo-booking' ),
			Capabilities::manage(),
			'yo-booking-services',
			array( $this, 'render_services' )
		);

		add_submenu_page(
			'yo-booking',
			__( 'Service Categories', 'yo-booking' ),
			__( 'Categories', 'yo-booking' ),
			Capabilities::manage(),
			'yo-booking-categories',
			array( $this, 'render_categories' )
		);
	}

	/**
	 * Render the services page.
	 *
	 * @return void
	 */
	public function render_services() {
		$this->ensure_capability();

		$services_repository   = new ServiceRepository();
		$categories_repository = new ServiceCategoryRepository();
		$edit_id               = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing               = $edit_id ? $services_repository->find( $edit_id ) : null;
		$is_new                = isset( $_GET['action'] ) && 'new' === sanitize_key( wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$services              = $services_repository->all();
		$categories            = $categories_repository->all();
		$category_names        = array();
		$default_currency      = ( new SettingsRepository() )->get( 'company.currency', 'USD' );

		foreach ( $categories as $category ) {
			$category_names[ (int) $category->id ] = $category->name;
		}

		?>
		<div class="wrap yo-booking-admin">
			<?php $this->render_page_header( __( 'Services', 'yo-booking' ), __( 'Define duration, pricing, capacity, and booking presentation.', 'yo-booking' ), '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=yo-booking-categories' ) ) . '">' . esc_html__( 'Categories', 'yo-booking' ) . '</a><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=yo-booking-services&action=new' ) ) . '"><span class="fi fi-rr-plus" aria-hidden="true"></span>' . esc_html__( 'Add service', 'yo-booking' ) . '</a>' ); ?>
			<?php $this->render_notice(); ?>

			<?php if ( $editing || $is_new ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yo-editor">
				<input type="hidden" name="action" value="yo_booking_save_service" />
				<input type="hidden" name="id" value="<?php echo esc_attr( $editing ? (string) $editing->id : '0' ); ?>" />
				<?php wp_nonce_field( 'yo_booking_save_service' ); ?>

				<div class="yo-editor__header"><h2><?php echo esc_html( $editing ? __( 'Edit service', 'yo-booking' ) : __( 'Add service', 'yo-booking' ) ); ?></h2><a href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-services' ) ); ?>"><?php echo esc_html__( 'Close', 'yo-booking' ); ?></a></div>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="yo_booking_service_name"><?php echo esc_html__( 'Name', 'yo-booking' ); ?></label></th>
							<td><input id="yo_booking_service_name" name="name" type="text" class="regular-text" required value="<?php echo esc_attr( $editing ? $editing->name : '' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_service_slug"><?php echo esc_html__( 'Slug', 'yo-booking' ); ?></label></th>
							<td><input id="yo_booking_service_slug" name="slug" type="text" class="regular-text" value="<?php echo esc_attr( $editing ? $editing->slug : '' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_service_category"><?php echo esc_html__( 'Category', 'yo-booking' ); ?></label></th>
							<td>
								<select id="yo_booking_service_category" name="category_id">
									<option value="0"><?php echo esc_html__( 'Uncategorized', 'yo-booking' ); ?></option>
									<?php foreach ( $categories as $category ) : ?>
										<option value="<?php echo esc_attr( (string) $category->id ); ?>" <?php selected( $editing ? (int) $editing->category_id : 0, (int) $category->id ); ?>>
											<?php echo esc_html( $category->name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_service_duration"><?php echo esc_html__( 'Duration', 'yo-booking' ); ?></label></th>
							<td>
								<input id="yo_booking_service_duration" name="duration_minutes" type="number" min="5" max="1440" step="5" value="<?php echo esc_attr( $editing ? (string) $editing->duration_minutes : '60' ); ?>" />
								<span><?php echo esc_html__( 'minutes', 'yo-booking' ); ?></span>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Buffer', 'yo-booking' ); ?></th>
							<td>
								<div class="yo-form-row">
									<div class="yo-form-field">
										<label for="yo_booking_service_buffer_before"><?php echo esc_html__( 'Before', 'yo-booking' ); ?></label>
										<div class="yo-form-unit"><input id="yo_booking_service_buffer_before" name="buffer_before_minutes" type="number" min="0" step="5" value="<?php echo esc_attr( $editing ? (string) $editing->buffer_before_minutes : '0' ); ?>" /><span><?php echo esc_html__( 'minutes', 'yo-booking' ); ?></span></div>
									</div>
									<div class="yo-form-field">
										<label for="yo_booking_service_buffer_after"><?php echo esc_html__( 'After', 'yo-booking' ); ?></label>
										<div class="yo-form-unit"><input id="yo_booking_service_buffer_after" name="buffer_after_minutes" type="number" min="0" step="5" value="<?php echo esc_attr( $editing ? (string) $editing->buffer_after_minutes : '0' ); ?>" /><span><?php echo esc_html__( 'minutes', 'yo-booking' ); ?></span></div>
									</div>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Price', 'yo-booking' ); ?></th>
							<td>
								<?php $selected_currency = $editing ? $editing->currency : $default_currency; ?>
								<div class="yo-form-row">
									<div class="yo-form-field"><label for="yo_booking_service_price"><?php echo esc_html__( 'Amount', 'yo-booking' ); ?></label><input id="yo_booking_service_price" name="price" type="text" inputmode="decimal" value="<?php echo esc_attr( Currency::format_number( $editing ? $editing->price : '0', $selected_currency ) ); ?>" data-yo-money-input data-yo-money-raw="<?php echo esc_attr( $editing ? (string) $editing->price : '0' ); ?>" /></div>
									<div class="yo-form-field"><label for="yo_booking_service_currency"><?php echo esc_html__( 'Currency', 'yo-booking' ); ?></label><select id="yo_booking_service_currency" name="currency"><?php foreach ( Currency::choices( $selected_currency ) as $currency_code => $currency_data ) : ?><option value="<?php echo esc_attr( $currency_code ); ?>" <?php selected( $selected_currency, $currency_code ); ?>><?php echo esc_html( $currency_code . ' - ' . $currency_data['name'] ); ?></option><?php endforeach; ?></select></div>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_service_capacity"><?php echo esc_html__( 'Capacity', 'yo-booking' ); ?></label></th>
							<td><input id="yo_booking_service_capacity" name="capacity" type="number" min="1" value="<?php echo esc_attr( $editing ? (string) $editing->capacity : '1' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_service_color"><?php echo esc_html__( 'Color', 'yo-booking' ); ?></label></th>
							<td><?php $this->render_color_control( 'yo_booking_service_color', 'color', $editing && $editing->color ? $editing->color : '#2563eb', __( 'Color', 'yo-booking' ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_service_sort_order"><?php echo esc_html__( 'Sort order', 'yo-booking' ); ?></label></th>
							<td><input id="yo_booking_service_sort_order" name="sort_order" type="number" value="<?php echo esc_attr( $editing ? (string) $editing->sort_order : '0' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_service_status"><?php echo esc_html__( 'Status', 'yo-booking' ); ?></label></th>
							<td><?php $this->status_select( 'yo_booking_service_status', 'status', $editing ? $editing->status : 'active' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_service_description"><?php echo esc_html__( 'Description', 'yo-booking' ); ?></label></th>
							<td><textarea id="yo_booking_service_description" name="description" class="large-text" rows="4"><?php echo esc_textarea( $editing ? $editing->description : '' ); ?></textarea></td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( $editing ? __( 'Update Service', 'yo-booking' ) : __( 'Add Service', 'yo-booking' ) ); ?>
			</form>
			<?php endif; ?>

			<?php // translators: %d: number of configured services. ?>
			<div class="yo-section-header"><h2><?php echo esc_html__( 'Configured services', 'yo-booking' ); ?></h2><span class="description"><?php echo esc_html( sprintf( _n( '%d service', '%d services', count( $services ), 'yo-booking' ), count( $services ) ) ); ?></span></div>
			<div class="yo-toolbar"><label><?php echo esc_html__( 'Search services', 'yo-booking' ); ?><input type="search" data-yo-table-search="yo-booking-services-table" placeholder="<?php echo esc_attr__( 'Name, category, status...', 'yo-booking' ); ?>" /></label></div>
			<table class="widefat striped" id="yo-booking-services-table">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Name', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Category', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Duration', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Price', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Actions', 'yo-booking' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $services ) ) : ?>
						<?php $this->render_empty_row( 6, __( 'No services yet', 'yo-booking' ), __( 'Create a service before accepting bookings.', 'yo-booking' ) ); ?>
					<?php endif; ?>
					<?php foreach ( $services as $service ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $service->name ); ?></strong><br /><code><?php echo esc_html( $service->slug ); ?></code></td>
							<td><?php echo esc_html( isset( $category_names[ (int) $service->category_id ] ) ? $category_names[ (int) $service->category_id ] : __( 'Uncategorized', 'yo-booking' ) ); ?></td>
							<?php // translators: %d: service duration in minutes. ?>
							<td><?php echo esc_html( sprintf( __( '%d minutes', 'yo-booking' ), (int) $service->duration_minutes ) ); ?></td>
							<td><?php echo esc_html( Currency::format( $service->price, $service->currency ) ); ?></td>
						<td><?php $this->render_status_badge( $service->status ); ?></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-services&edit=' . absint( $service->id ) ) ); ?>"><?php echo esc_html__( 'Edit', 'yo-booking' ); ?></a>
								<?php $this->delete_button( 'yo_booking_delete_service', 'yo_booking_delete_service_' . (int) $service->id, (int) $service->id ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the service categories page.
	 *
	 * @return void
	 */
	public function render_categories() {
		$this->ensure_capability();

		$repository = new ServiceCategoryRepository();
		$edit_id    = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing    = $edit_id ? $repository->find( $edit_id ) : null;
		$is_new     = isset( $_GET['action'] ) && 'new' === sanitize_key( wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$categories = $repository->all();

		?>
		<div class="wrap yo-booking-admin">
			<?php $this->render_page_header( __( 'Service categories', 'yo-booking' ), __( 'Organize services for faster administration and booking.', 'yo-booking' ), '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=yo-booking-services' ) ) . '">' . esc_html__( 'Services', 'yo-booking' ) . '</a><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=yo-booking-categories&action=new' ) ) . '"><span class="fi fi-rr-plus" aria-hidden="true"></span>' . esc_html__( 'Add category', 'yo-booking' ) . '</a>' ); ?>
			<?php $this->render_notice(); ?>

			<?php if ( $editing || $is_new ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yo-editor">
				<input type="hidden" name="action" value="yo_booking_save_category" />
				<input type="hidden" name="id" value="<?php echo esc_attr( $editing ? (string) $editing->id : '0' ); ?>" />
				<?php wp_nonce_field( 'yo_booking_save_category' ); ?>

				<div class="yo-editor__header"><h2><?php echo esc_html( $editing ? __( 'Edit category', 'yo-booking' ) : __( 'Add category', 'yo-booking' ) ); ?></h2><a href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-categories' ) ); ?>"><?php echo esc_html__( 'Close', 'yo-booking' ); ?></a></div>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="yo_booking_category_name"><?php echo esc_html__( 'Name', 'yo-booking' ); ?></label></th>
							<td><input id="yo_booking_category_name" name="name" type="text" class="regular-text" required value="<?php echo esc_attr( $editing ? $editing->name : '' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_category_slug"><?php echo esc_html__( 'Slug', 'yo-booking' ); ?></label></th>
							<td><input id="yo_booking_category_slug" name="slug" type="text" class="regular-text" value="<?php echo esc_attr( $editing ? $editing->slug : '' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_category_parent"><?php echo esc_html__( 'Parent', 'yo-booking' ); ?></label></th>
							<td>
								<select id="yo_booking_category_parent" name="parent_id">
									<option value="0"><?php echo esc_html__( 'None', 'yo-booking' ); ?></option>
									<?php foreach ( $categories as $category ) : ?>
										<?php if ( $editing && (int) $editing->id === (int) $category->id ) : ?>
											<?php continue; ?>
										<?php endif; ?>
										<option value="<?php echo esc_attr( (string) $category->id ); ?>" <?php selected( $editing ? (int) $editing->parent_id : 0, (int) $category->id ); ?>>
											<?php echo esc_html( $category->name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_category_sort_order"><?php echo esc_html__( 'Sort order', 'yo-booking' ); ?></label></th>
							<td><input id="yo_booking_category_sort_order" name="sort_order" type="number" value="<?php echo esc_attr( $editing ? (string) $editing->sort_order : '0' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_category_status"><?php echo esc_html__( 'Status', 'yo-booking' ); ?></label></th>
							<td><?php $this->status_select( 'yo_booking_category_status', 'status', $editing ? $editing->status : 'active' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_category_description"><?php echo esc_html__( 'Description', 'yo-booking' ); ?></label></th>
							<td><textarea id="yo_booking_category_description" name="description" class="large-text" rows="3"><?php echo esc_textarea( $editing ? $editing->description : '' ); ?></textarea></td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( $editing ? __( 'Update Category', 'yo-booking' ) : __( 'Add Category', 'yo-booking' ) ); ?>
			</form>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Configured Categories', 'yo-booking' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Name', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Parent ID', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Actions', 'yo-booking' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $categories ) ) : ?>
						<?php $this->render_empty_row( 4, __( 'No categories yet', 'yo-booking' ) ); ?>
					<?php endif; ?>
					<?php foreach ( $categories as $category ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $category->name ); ?></strong><br /><code><?php echo esc_html( $category->slug ); ?></code></td>
							<td><?php echo esc_html( (string) $category->parent_id ); ?></td>
						<td><?php $this->render_status_badge( $category->status ); ?></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-categories&edit=' . absint( $category->id ) ) ); ?>"><?php echo esc_html__( 'Edit', 'yo-booking' ); ?></a>
								<?php $this->delete_button( 'yo_booking_delete_category', 'yo_booking_delete_category_' . (int) $category->id, (int) $category->id ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Save a service.
	 *
	 * @return void
	 */
	public function save_service() {
		$this->ensure_capability();
		check_admin_referer( 'yo_booking_save_service' );

		$data             = wp_unslash( $_POST );
		$currency         = isset( $data['currency'] ) ? Currency::normalize( $data['currency'] ) : '';
		$currency         = $currency ? $currency : ( new SettingsRepository() )->get( 'company.currency', 'USD' );
		$data['price']     = Currency::parse_number( isset( $data['price'] ) ? $data['price'] : 0, $currency );
		$result            = ( new ServiceRepository() )->save( $data );
		if ( ! is_wp_error( $result ) ) {
			$mode = empty( $_POST['id'] ) ? 'created' : 'updated';
			// translators: 1: service database ID, 2: action name such as created or updated.
			( new AuditLogRepository() )->record( 'service.' . $mode, 'service', $result, sprintf( __( 'Service #%1$d %2$s', 'yo-booking' ), $result, $mode ) );
		}

		$this->redirect_result( 'yo-booking-services', $result );
	}

	/**
	 * Delete a service.
	 *
	 * @return void
	 */
	public function delete_service() {
		$this->ensure_capability();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'yo_booking_delete_service_' . $id );

		if ( ( new ServiceRepository() )->delete( $id ) ) {
			// translators: %d: service database ID.
			( new AuditLogRepository() )->record( 'service.deleted', 'service', $id, sprintf( __( 'Service #%d deleted', 'yo-booking' ), $id ) );
		}
		$this->redirect( 'yo-booking-services', 'deleted' );
	}

	/**
	 * Save a category.
	 *
	 * @return void
	 */
	public function save_category() {
		$this->ensure_capability();
		check_admin_referer( 'yo_booking_save_category' );

		$result = ( new ServiceCategoryRepository() )->save( wp_unslash( $_POST ) );
		if ( ! is_wp_error( $result ) ) {
			$mode = empty( $_POST['id'] ) ? 'created' : 'updated';
			// translators: 1: service category database ID, 2: action name such as created or updated.
			( new AuditLogRepository() )->record( 'category.' . $mode, 'service_category', $result, sprintf( __( 'Service category #%1$d %2$s', 'yo-booking' ), $result, $mode ) );
		}

		$this->redirect_result( 'yo-booking-categories', $result );
	}

	/**
	 * Delete a category.
	 *
	 * @return void
	 */
	public function delete_category() {
		$this->ensure_capability();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'yo_booking_delete_category_' . $id );

		if ( ( new ServiceCategoryRepository() )->delete( $id ) ) {
			// translators: %d: service category database ID.
			( new AuditLogRepository() )->record( 'category.deleted', 'service_category', $id, sprintf( __( 'Service category #%d deleted', 'yo-booking' ), $id ) );
		}
		$this->redirect( 'yo-booking-categories', 'deleted' );
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
	 * @param string $action Admin-post action.
	 * @param string $nonce_action Nonce action.
	 * @param int    $id Row ID.
	 * @return void
	 */
	private function delete_button( $action, $nonce_action, $id ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( $nonce_action ); ?>
			<button type="submit" class="button button-small button-link-delete"><?php echo esc_html__( 'Delete', 'yo-booking' ); ?></button>
		</form>
		<?php
	}
}
