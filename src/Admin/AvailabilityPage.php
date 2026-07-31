<?php
/**
 * Availability admin page.
 *
 * @package YoBooking
 */

namespace YoBooking\Admin;

use YoBooking\Repositories\AvailabilityExceptionRepository;
use YoBooking\Repositories\AvailabilityRuleRepository;
use YoBooking\Repositories\StaffRepository;
use YoBooking\Settings\Repository as SettingsRepository;
use YoBooking\Support\Capabilities;
use YoBooking\Support\DateTimeFormatter;

defined( 'ABSPATH' ) || exit;

/**
 * Registers availability management screens.
 */
final class AvailabilityPage extends AbstractAdminPage {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_yo_booking_save_global_availability', array( $this, 'save_global_availability' ) );
		add_action( 'admin_post_yo_booking_save_staff_availability', array( $this, 'save_staff_availability' ) );
		add_action( 'admin_post_yo_booking_save_availability_exception', array( $this, 'save_exception' ) );
		add_action( 'admin_post_yo_booking_delete_availability_exception', array( $this, 'delete_exception' ) );
	}

	/**
	 * Register the availability submenu page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'yo-booking',
			__( 'Availability', 'yo-booking' ),
			__( 'Availability', 'yo-booking' ),
			Capabilities::manage(),
			'yo-booking-availability',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the availability page.
	 *
	 * @return void
	 */
	public function render() {
		$this->ensure_capability();

		$settings              = ( new SettingsRepository() )->all();
		$rules_repository      = new AvailabilityRuleRepository();
		$exceptions_repository = new AvailabilityExceptionRepository();
		$staff_repository      = new StaffRepository();
		$staff_members         = $staff_repository->all();
		$selected_staff_id     = isset( $_GET['staff_id'] ) ? absint( $_GET['staff_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab            = isset( $_GET['availability_tab'] ) ? sanitize_key( wp_unslash( $_GET['availability_tab'] ) ) : 'business'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab            = in_array( $active_tab, array( 'business', 'staff', 'exceptions' ), true ) ? $active_tab : 'business';

		if ( ! $selected_staff_id && ! empty( $staff_members ) ) {
			$selected_staff_id = (int) $staff_members[0]->id;
		}

		$global_rules = $this->rules_by_weekday( $rules_repository->all( 'global', 0 ) );
		$staff_rules  = $selected_staff_id ? $this->rules_by_weekday( $rules_repository->all( 'staff', $selected_staff_id ) ) : array();
		$exceptions   = $exceptions_repository->upcoming();
		$staff_names  = array();

		foreach ( $staff_members as $staff ) {
			$staff_names[ (int) $staff->id ] = $staff->name;
		}

		?>
		<div class="wrap yo-booking-admin">
			<?php $this->render_page_header( __( 'Availability', 'yo-booking' ), __( 'Control business hours, staff schedules, and date-specific exceptions.', 'yo-booking' ) ); ?>
			<?php $this->render_notice(); ?>
		<div class="yo-tabs" data-yo-tabs data-default-tab="<?php echo esc_attr( $active_tab ); ?>" role="tablist">
				<button type="button" data-yo-tab="business" role="tab"><?php echo esc_html__( 'Business hours', 'yo-booking' ); ?></button>
				<button type="button" data-yo-tab="staff" role="tab"><?php echo esc_html__( 'Staff schedules', 'yo-booking' ); ?></button>
				<button type="button" data-yo-tab="exceptions" role="tab"><?php echo esc_html__( 'Exceptions', 'yo-booking' ); ?></button>
			</div>

			<section data-yo-panel="business">
			<h2><?php echo esc_html__( 'Business Hours', 'yo-booking' ); ?></h2>
			<p><?php echo esc_html__( 'These global hours are used when a staff member has no schedule for a specific weekday.', 'yo-booking' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yo-card yo-editor" style="max-width: 960px;">
				<input type="hidden" name="action" value="yo_booking_save_global_availability" />
				<?php wp_nonce_field( 'yo_booking_save_global_availability' ); ?>
				<?php $this->render_timezone_field( 'timezone', $this->first_timezone( $global_rules, $settings['company']['timezone'] ) ); ?>
				<?php $this->render_weekly_rules_table( $global_rules, (int) $settings['booking']['slot_interval_minutes'] ); ?>
				<?php submit_button( __( 'Save Business Hours', 'yo-booking' ) ); ?>
			</form>
			</section>

			<section data-yo-panel="staff" hidden>
			<h2><?php echo esc_html__( 'Staff Schedule', 'yo-booking' ); ?></h2>
			<?php if ( empty( $staff_members ) ) : ?>
				<p><?php echo esc_html__( 'Create staff members before configuring staff schedules.', 'yo-booking' ); ?></p>
			<?php else : ?>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin-bottom: 12px;">
					<input type="hidden" name="page" value="yo-booking-availability" />
					<input type="hidden" name="availability_tab" value="staff" />
					<label for="yo_booking_staff_schedule_picker"><?php echo esc_html__( 'Staff member', 'yo-booking' ); ?></label>
					<select id="yo_booking_staff_schedule_picker" name="staff_id">
						<?php foreach ( $staff_members as $staff ) : ?>
							<option value="<?php echo esc_attr( (string) $staff->id ); ?>" <?php selected( $selected_staff_id, (int) $staff->id ); ?>>
								<?php echo esc_html( $staff->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Load', 'yo-booking' ), 'secondary', '', false ); ?>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yo-card yo-editor" style="max-width: 960px;">
					<input type="hidden" name="action" value="yo_booking_save_staff_availability" />
					<input type="hidden" name="staff_id" value="<?php echo esc_attr( (string) $selected_staff_id ); ?>" />
					<?php wp_nonce_field( 'yo_booking_save_staff_availability' ); ?>
					<?php $this->render_timezone_field( 'timezone', $this->first_timezone( $staff_rules, $settings['company']['timezone'] ) ); ?>
					<?php $this->render_weekly_rules_table( $staff_rules, (int) $settings['booking']['slot_interval_minutes'] ); ?>
					<?php submit_button( __( 'Save Staff Schedule', 'yo-booking' ) ); ?>
				</form>
			<?php endif; ?>
			</section>

			<section data-yo-panel="exceptions" hidden>
			<h2><?php echo esc_html__( 'Exceptions', 'yo-booking' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yo-card yo-editor" style="max-width: 960px;">
				<input type="hidden" name="action" value="yo_booking_save_availability_exception" />
				<?php wp_nonce_field( 'yo_booking_save_availability_exception' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Applies to', 'yo-booking' ); ?></th>
							<td>
								<div class="yo-form-row">
									<div class="yo-form-field"><label for="yo_booking_exception_owner_type"><?php echo esc_html__( 'Scope', 'yo-booking' ); ?></label><select id="yo_booking_exception_owner_type" name="owner_type"><option value="global"><?php echo esc_html__( 'Global', 'yo-booking' ); ?></option><option value="staff"><?php echo esc_html__( 'Staff', 'yo-booking' ); ?></option></select></div>
									<div class="yo-form-field" data-exception-staff-field hidden><label for="yo_booking_exception_owner_id"><?php echo esc_html__( 'Staff member', 'yo-booking' ); ?></label><select id="yo_booking_exception_owner_id" name="owner_id" disabled><option value=""><?php echo esc_html__( 'Select staff member', 'yo-booking' ); ?></option><?php foreach ( $staff_members as $staff ) : ?><option value="<?php echo esc_attr( (string) $staff->id ); ?>"><?php echo esc_html( $staff->name ); ?></option><?php endforeach; ?></select></div>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_exception_date"><?php echo esc_html__( 'Date', 'yo-booking' ); ?></label></th>
							<td><input id="yo_booking_exception_date" name="exception_date" type="date" required value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_exception_type"><?php echo esc_html__( 'Type', 'yo-booking' ); ?></label></th>
							<td>
								<select id="yo_booking_exception_type" name="availability_type">
									<option value="blocked"><?php echo esc_html__( 'Blocked time / day off', 'yo-booking' ); ?></option>
									<option value="available"><?php echo esc_html__( 'Special available hours', 'yo-booking' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Time range', 'yo-booking' ); ?></th>
							<td>
								<div class="yo-form-row">
									<div class="yo-form-field"><label for="yo_booking_exception_start_time"><?php echo esc_html__( 'Start time', 'yo-booking' ); ?></label><input id="yo_booking_exception_start_time" name="start_time" type="time" /></div>
									<div class="yo-form-field"><label for="yo_booking_exception_end_time"><?php echo esc_html__( 'End time', 'yo-booking' ); ?></label><input id="yo_booking_exception_end_time" name="end_time" type="time" /></div>
								</div>
								<p class="description"><?php echo esc_html__( 'Leave both empty for a full-day blocked exception. Special available hours require both times.', 'yo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_exception_timezone"><?php echo esc_html__( 'Timezone', 'yo-booking' ); ?></label></th>
							<td><strong><?php echo esc_html( DateTimeFormatter::timezone_name() ); ?></strong><p class="description"><?php echo esc_html__( 'Inherited from WordPress Settings.', 'yo-booking' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="yo_booking_exception_reason"><?php echo esc_html__( 'Reason', 'yo-booking' ); ?></label></th>
							<td><input id="yo_booking_exception_reason" name="reason" type="text" class="regular-text" /></td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Add Exception', 'yo-booking' ) ); ?>
			</form>

			<h3><?php echo esc_html__( 'Upcoming Exceptions', 'yo-booking' ); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Date', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Owner', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Type', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Time', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Reason', 'yo-booking' ); ?></th>
						<th><?php echo esc_html__( 'Actions', 'yo-booking' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $exceptions ) ) : ?>
						<tr><td colspan="6"><?php echo esc_html__( 'No upcoming exceptions.', 'yo-booking' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $exceptions as $exception ) : ?>
						<tr>
							<td><?php echo esc_html( DateTimeFormatter::local_date( $exception->exception_date ) ); ?></td>
							<td><?php echo esc_html( $this->owner_label( $exception, $staff_names ) ); ?></td>
							<td><?php echo esc_html( ucfirst( $exception->availability_type ) ); ?></td>
							<td><?php echo esc_html( $exception->start_time && $exception->end_time ? DateTimeFormatter::local_time( $exception->start_time ) . ' - ' . DateTimeFormatter::local_time( $exception->end_time ) : __( 'Full day', 'yo-booking' ) ); ?></td>
							<td><?php echo esc_html( $exception->reason ); ?></td>
							<td><?php $this->delete_exception_button( (int) $exception->id ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</section>
		</div>
		<?php
	}

	/**
	 * Save global business hours.
	 *
	 * @return void
	 */
	public function save_global_availability() {
		$this->ensure_capability();
		check_admin_referer( 'yo_booking_save_global_availability' );

		$result = ( new AvailabilityRuleRepository() )->replace_weekly(
			'global',
			0,
			$this->posted_rules(),
			$this->posted_timezone()
		);

		$this->redirect_result( 'yo-booking-availability', is_wp_error( $result ) ? $result : 1, array( 'availability_tab' => 'business' ) );
	}

	/**
	 * Save staff schedule.
	 *
	 * @return void
	 */
	public function save_staff_availability() {
		$this->ensure_capability();
		check_admin_referer( 'yo_booking_save_staff_availability' );

		$staff_id = isset( $_POST['staff_id'] ) ? absint( $_POST['staff_id'] ) : 0;

		$result = ( new AvailabilityRuleRepository() )->replace_weekly(
			'staff',
			$staff_id,
			$this->posted_rules(),
			$this->posted_timezone()
		);

		$this->redirect_result( 'yo-booking-availability', is_wp_error( $result ) ? $result : 1, array( 'staff_id' => $staff_id, 'availability_tab' => 'staff' ) );
	}

	/**
	 * Save an availability exception.
	 *
	 * @return void
	 */
	public function save_exception() {
		$this->ensure_capability();
		check_admin_referer( 'yo_booking_save_availability_exception' );

		$data              = wp_unslash( $_POST );
		$data['owner_type'] = isset( $data['owner_type'] ) && 'staff' === sanitize_key( $data['owner_type'] ) ? 'staff' : 'global';
		$data['owner_id']   = 'staff' === $data['owner_type'] && isset( $data['owner_id'] ) ? absint( $data['owner_id'] ) : 0;
		$data['timezone']   = DateTimeFormatter::timezone_name();

		$result = ( new AvailabilityExceptionRepository() )->save( $data );

		$this->redirect_result( 'yo-booking-availability', $result );
	}

	/**
	 * Delete an exception.
	 *
	 * @return void
	 */
	public function delete_exception() {
		$this->ensure_capability();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'yo_booking_delete_availability_exception_' . $id );

		( new AvailabilityExceptionRepository() )->delete( $id );
		$this->redirect( 'yo-booking-availability', 'deleted' );
	}

	/**
	 * Render timezone field.
	 *
	 * @param string $name Field name.
	 * @param string $value Field value.
	 * @return void
	 */
	private function render_timezone_field( $name, $value ) {
		$field_id = wp_unique_id( 'yo_booking_availability_timezone_' );
		?>
		<p class="yo-stacked-field">
			<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html__( 'Timezone', 'yo-booking' ); ?></label>
			<strong id="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( DateTimeFormatter::timezone_name() ); ?></strong>
			<span class="description"><?php echo esc_html__( 'Inherited from WordPress Settings.', 'yo-booking' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Render weekly rule inputs.
	 *
	 * @param array $rules_by_weekday Rules keyed by weekday.
	 * @param int   $default_interval Default slot interval.
	 * @return void
	 */
	private function render_weekly_rules_table( array $rules_by_weekday, $default_interval ) {
		?>
		<table class="widefat striped yo-weekly-schedule">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Day', 'yo-booking' ); ?></th>
					<th><?php echo esc_html__( 'Open', 'yo-booking' ); ?></th>
					<th><?php echo esc_html__( 'Working hours', 'yo-booking' ); ?></th>
					<th><?php echo esc_html__( 'Slot interval', 'yo-booking' ); ?></th>
					<th><span class="screen-reader-text"><?php echo esc_html__( 'Tools', 'yo-booking' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $this->weekdays() as $weekday => $label ) : ?>
				<?php $day_rules = isset( $rules_by_weekday[ $weekday ] ) ? $rules_by_weekday[ $weekday ] : array(); ?>
				<?php $display_rules = $day_rules ? $day_rules : array( (object) array( 'start_time' => '09:00', 'end_time' => '17:00', 'slot_interval_minutes' => $default_interval ) ); ?>
				<?php $interval = ! empty( $day_rules ) ? (int) $day_rules[0]->slot_interval_minutes : (int) $default_interval; ?>
				<tr data-weekday-row="<?php echo esc_attr( (string) $weekday ); ?>">
					<td><?php echo esc_html( $label ); ?></td>
					<?php // translators: %s: weekday name. ?>
					<td><input name="rules[<?php echo esc_attr( (string) $weekday ); ?>][enabled]" type="checkbox" value="1" <?php checked( ! empty( $day_rules ) ); ?> aria-label="<?php echo esc_attr( sprintf( __( 'Open on %s', 'yo-booking' ), $label ) ); ?>" /></td>
					<td>
						<div class="yo-time-ranges" data-time-ranges>
							<?php foreach ( $display_rules as $index => $rule ) : ?>
								<div class="yo-time-range">
									<input name="rules[<?php echo esc_attr( (string) $weekday ); ?>][ranges][<?php echo esc_attr( (string) $index ); ?>][start_time]" type="time" value="<?php echo esc_attr( substr( $rule->start_time, 0, 5 ) ); ?>" aria-label="<?php echo esc_attr__( 'Start time', 'yo-booking' ); ?>" <?php disabled( empty( $day_rules ) ); ?> />
									<span><?php echo esc_html__( 'to', 'yo-booking' ); ?></span>
									<input name="rules[<?php echo esc_attr( (string) $weekday ); ?>][ranges][<?php echo esc_attr( (string) $index ); ?>][end_time]" type="time" value="<?php echo esc_attr( substr( $rule->end_time, 0, 5 ) ); ?>" aria-label="<?php echo esc_attr__( 'End time', 'yo-booking' ); ?>" <?php disabled( empty( $day_rules ) ); ?> />
									<button type="button" class="button button-small" data-remove-range aria-label="<?php echo esc_attr__( 'Remove hours', 'yo-booking' ); ?>" <?php disabled( empty( $day_rules ) ); ?>><span class="fi fi-rr-cross" aria-hidden="true"></span></button>
								</div>
							<?php endforeach; ?>
						</div>
						<button type="button" class="button button-small" data-add-range <?php disabled( empty( $day_rules ) ); ?>><span class="fi fi-rr-plus" aria-hidden="true"></span><?php echo esc_html__( 'Add hours', 'yo-booking' ); ?></button>
					</td>
					<td>
						<input name="rules[<?php echo esc_attr( (string) $weekday ); ?>][slot_interval_minutes]" type="number" min="5" max="240" step="5" value="<?php echo esc_attr( (string) $interval ); ?>" <?php disabled( empty( $day_rules ) ); ?> />
						<?php echo esc_html__( 'minutes', 'yo-booking' ); ?>
					</td>
					<td><button type="button" class="button button-small yo-copy-feedback" data-copy-day aria-label="<?php echo esc_attr__( 'Copy these hours to every open day', 'yo-booking' ); ?>" title="<?php echo esc_attr__( 'Copy these hours to every open day', 'yo-booking' ); ?>" <?php disabled( empty( $day_rules ) ); ?>><span class="fi fi-rr-copy" aria-hidden="true"></span></button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Return posted weekly rules.
	 *
	 * @return array
	 */
	private function posted_rules() {
		// Both callers verify their form nonce before reading this structured field.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$rules = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? map_deep( wp_unslash( $_POST['rules'] ), 'sanitize_text_field' ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Return posted timezone.
	 *
	 * @return string
	 */
	private function posted_timezone() {
		return DateTimeFormatter::timezone_name();
	}

	/**
	 * Index rules by weekday.
	 *
	 * @param array $rules Rule rows.
	 * @return array
	 */
	private function rules_by_weekday( array $rules ) {
		$map = array();

		foreach ( $rules as $rule ) {
			$weekday = (int) $rule->weekday;
			if ( ! isset( $map[ $weekday ] ) ) {
				$map[ $weekday ] = array();
			}
			$map[ $weekday ][] = $rule;
		}

		return $map;
	}

	/**
	 * Return first timezone from rules.
	 *
	 * @param array  $rules_by_weekday Rules keyed by weekday.
	 * @param string $fallback Fallback timezone.
	 * @return string
	 */
	private function first_timezone( array $rules_by_weekday, $fallback ) {
		foreach ( $rules_by_weekday as $day_rules ) {
			foreach ( $day_rules as $rule ) {
				if ( ! empty( $rule->timezone ) ) {
					return $rule->timezone;
				}
			}
		}

		return $fallback ? $fallback : 'UTC';
	}

	/**
	 * Return weekday labels.
	 *
	 * @return array
	 */
	private function weekdays() {
		return array(
			0 => __( 'Sunday', 'yo-booking' ),
			1 => __( 'Monday', 'yo-booking' ),
			2 => __( 'Tuesday', 'yo-booking' ),
			3 => __( 'Wednesday', 'yo-booking' ),
			4 => __( 'Thursday', 'yo-booking' ),
			5 => __( 'Friday', 'yo-booking' ),
			6 => __( 'Saturday', 'yo-booking' ),
		);
	}

	/**
	 * Return owner label for an exception.
	 *
	 * @param object $exception Exception row.
	 * @param array  $staff_names Staff names keyed by ID.
	 * @return string
	 */
	private function owner_label( $exception, array $staff_names ) {
		if ( 'staff' === $exception->owner_type ) {
			return isset( $staff_names[ (int) $exception->owner_id ] ) ? $staff_names[ (int) $exception->owner_id ] : __( 'Unknown staff', 'yo-booking' );
		}

		return __( 'Global', 'yo-booking' );
	}

	/**
	 * Render delete exception button.
	 *
	 * @param int $id Exception ID.
	 * @return void
	 */
	private function delete_exception_button( $id ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
			<input type="hidden" name="action" value="yo_booking_delete_availability_exception" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( 'yo_booking_delete_availability_exception_' . $id ); ?>
			<button type="submit" class="button button-small button-link-delete"><?php echo esc_html__( 'Delete', 'yo-booking' ); ?></button>
		</form>
		<?php
	}
}
