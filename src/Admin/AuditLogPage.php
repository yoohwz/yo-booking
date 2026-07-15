<?php
/**
 * Audit log admin page.
 *
 * @package YoBooking
 */

namespace YoBooking\Admin;

use YoBooking\Repositories\AuditLogRepository;
use YoBooking\Support\Capabilities;
use YoBooking\Support\DateTimeFormatter;

defined( 'ABSPATH' ) || exit;

/**
 * Renders immutable operational history.
 */
final class AuditLogPage extends AbstractAdminPage {
	/** @return void */
	public function boot() {}

	/** @return void */
	public function render() {
		$this->render_screen( false );
	}

	/** @return void */
	public function render_embedded() {
		$this->render_screen( true );
	}

	/** @param bool $embedded Whether rendered inside Settings. @return void */
	private function render_screen( $embedded ) {
		$this->ensure_capability();
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['event'] ) ? sanitize_text_field( wp_unslash( $_GET['event'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$args   = array( 'search' => $search, 'action' => $action, 'limit' => 50, 'offset' => ( $paged - 1 ) * 50 );
		$repo   = new AuditLogRepository();
		$total  = $repo->count_matching( $args );
		$logs   = $repo->all( $args );
		?>
		<div class="<?php echo esc_attr( $embedded ? 'yo-settings-view' : 'wrap yo-booking-admin' ); ?>">
			<?php if ( $embedded ) : ?><div class="yo-settings-section-header"><div><h2><?php echo esc_html__( 'Audit log', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Review important booking and payment changes. Entries are read-only.', 'yo-booking' ); ?></p></div></div><?php else : ?><?php $this->render_page_header( __( 'Audit log', 'yo-booking' ), __( 'Review important booking and payment changes. Entries are read-only.', 'yo-booking' ) ); ?><?php endif; ?>
			<form method="get" class="yo-toolbar"><input type="hidden" name="page" value="yo-booking-settings" /><input type="hidden" name="settings_tab" value="audit" /><label><?php echo esc_html__( 'Search', 'yo-booking' ); ?><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" /></label><label><?php echo esc_html__( 'Event', 'yo-booking' ); ?><select name="event"><option value=""><?php echo esc_html__( 'All events', 'yo-booking' ); ?></option><?php foreach ( array( 'appointment.created', 'appointment.updated', 'appointment.status_changed', 'appointment.rescheduled', 'payment.status_changed', 'payment.transaction_recorded', 'customer.created', 'customer.updated', 'customer.deleted', 'service.created', 'service.updated', 'service.deleted', 'staff.created', 'staff.updated', 'staff.deleted', 'settings.updated', 'appearance.updated', 'appearance.reset', 'demo.appointment_seeded', 'demo.dataset_created' ) as $event ) : ?><option value="<?php echo esc_attr( $event ); ?>" <?php selected( $action, $event ); ?>><?php echo esc_html( $event ); ?></option><?php endforeach; ?></select></label><button class="button" type="submit"><?php echo esc_html__( 'Filter', 'yo-booking' ); ?></button></form>
			<div class="yo-table-scroll"><table class="widefat striped yo-table--audit"><thead><tr><th><?php echo esc_html__( 'Time', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Actor', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Event', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Object', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Summary', 'yo-booking' ); ?></th></tr></thead><tbody><?php if ( ! $logs ) : ?><?php $this->render_empty_row( 5, __( 'No audit entries found', 'yo-booking' ) ); ?><?php endif; ?><?php foreach ( $logs as $log ) : ?><tr><td><?php echo esc_html( DateTimeFormatter::utc( $log->created_at ) ); ?></td><td><?php echo esc_html( $log->actor_name ? $log->actor_name : __( 'System / guest', 'yo-booking' ) ); ?></td><td><code><?php echo esc_html( $log->action ); ?></code></td><td><?php echo esc_html( $log->object_type . ' #' . $log->object_id ); ?></td><td><?php echo esc_html( $log->summary ); ?></td></tr><?php endforeach; ?></tbody></table></div>
			<?php if ( $total > 50 ) : ?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => (int) ceil( $total / 50 ), 'prev_text' => '&lsaquo;', 'next_text' => '&rsaquo;' ) ) ); ?></div></div><?php endif; ?>
		</div>
		<?php
	}
}
