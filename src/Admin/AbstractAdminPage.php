<?php
/**
 * Admin page helpers.
 *
 * @package YoBooking
 */

namespace YoBooking\Admin;

use YoBooking\Support\Capabilities;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for admin CRUD pages.
 */
abstract class AbstractAdminPage {
	/**
	 * Render a consistent page heading.
	 *
	 * @param string $title Page title.
	 * @param string $description Supporting text.
	 * @param string $actions Escaped action HTML.
	 * @return void
	 */
	protected function render_page_header( $title, $description = '', $actions = '' ) {
		?>
		<div class="yo-page-header">
			<div>
				<h1><?php echo esc_html( $title ); ?></h1>
				<?php if ( $description ) : ?>
					<p><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( $actions ) : ?>
				<div class="yo-page-actions"><?php echo wp_kses_post( $actions ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a semantic status badge.
	 *
	 * @param string $status Status key.
	 * @param string $label Status label.
	 * @return void
	 */
	protected function render_status_badge( $status, $label = '' ) {
		$status = sanitize_key( $status );
		printf(
			'<span class="yo-status yo-status--%1$s">%2$s</span>',
			esc_attr( $status ),
			esc_html( $label ? $label : ucwords( str_replace( '_', ' ', $status ) ) )
		);
	}

	/**
	 * Render a synchronized native color picker and editable HEX field.
	 *
	 * @param string $id Input ID.
	 * @param string $name Input name.
	 * @param string $value Current color.
	 * @param string $label Accessible field label.
	 * @param string $data_attribute Optional supported data attribute.
	 * @param string $data_value Optional data attribute value.
	 * @return void
	 */
	protected function render_color_control( $id, $name, $value, $label, $data_attribute = '', $data_value = '' ) {
		$color           = sanitize_hex_color( $value );
		$color           = $color ? $color : '#000000';
		$data_attributes = array( 'data-appearance-variable', 'data-yo-email-color' );
		$data_attribute  = in_array( $data_attribute, $data_attributes, true ) ? $data_attribute : '';
		/* translators: %s: Color field label. */
		$picker_label = sprintf( __( '%s color picker', 'yo-booking' ), $label );
		/* translators: %s: Color field label. */
		$hex_label = sprintf( __( '%s HEX code', 'yo-booking' ), $label );
		?>
		<span class="yo-color-control" data-yo-color-control>
			<input id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" type="color" value="<?php echo esc_attr( $color ); ?>" data-yo-color-picker <?php if ( $data_attribute ) : ?><?php echo esc_attr( $data_attribute ); ?>="<?php echo esc_attr( $data_value ); ?>"<?php endif; ?> aria-label="<?php echo esc_attr( $picker_label ); ?>" />
			<input id="<?php echo esc_attr( $id . '_hex' ); ?>" type="text" class="yo-hex-color-input" value="<?php echo esc_attr( strtoupper( $color ) ); ?>" maxlength="7" pattern="#?[0-9A-Fa-f]{6}" spellcheck="false" autocomplete="off" data-yo-color-hex aria-label="<?php echo esc_attr( $hex_label ); ?>" />
		</span>
		<?php
	}

	/**
	 * Render a table empty state.
	 *
	 * @param int    $columns Table column count.
	 * @param string $title Empty state title.
	 * @param string $description Empty state description.
	 * @return void
	 */
	protected function render_empty_row( $columns, $title, $description = '' ) {
		?>
		<tr>
			<td colspan="<?php echo esc_attr( (string) absint( $columns ) ); ?>">
				<div class="yo-empty">
					<span class="fi fi-rr-calendar-days" aria-hidden="true"></span>
					<h3><?php echo esc_html( $title ); ?></h3>
					<?php if ( $description ) : ?><p><?php echo esc_html( $description ); ?></p><?php endif; ?>
				</div>
			</td>
		</tr>
		<?php
	}
	/**
	 * Verify the current user can manage the plugin.
	 *
	 * @param string $capability Capability name. Defaults to full management.
	 * @return void
	 */
	protected function ensure_capability( $capability = '' ) {
		$capability = $capability ? $capability : Capabilities::manage();
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'yo-booking' ) );
		}
	}

	/**
	 * Render a standard admin notice from query args.
	 *
	 * @return void
	 */
	protected function render_notice() {
		if ( empty( $_GET['yo_booking_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['yo_booking_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$class  = 'notice-success';
		$text   = __( 'Changes saved.', 'yo-booking' );

		if ( 'deleted' === $notice ) {
			$text = __( 'Record deleted.', 'yo-booking' );
		} elseif ( 'error' === $notice ) {
			$class = 'notice-error';
			$text  = ! empty( $_GET['yo_booking_message'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				? sanitize_text_field( wp_unslash( $_GET['yo_booking_message'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				: __( 'The request could not be completed.', 'yo-booking' );
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $text )
		);
	}

	/**
	 * Redirect back to an admin page.
	 *
	 * @param string $page Page slug.
	 * @param string $notice Notice key.
	 * @param array  $args Extra query args.
	 * @return void
	 */
	protected function redirect( $page, $notice = 'saved', array $args = array() ) {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'page'              => $page,
						'yo_booking_notice' => $notice,
					),
					$args
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Redirect after a repository result.
	 *
	 * @param string       $page Page slug.
	 * @param int|WP_Error $result Save result.
	 * @param array        $args Extra query args.
	 * @return void
	 */
	protected function redirect_result( $page, $result, array $args = array() ) {
		if ( is_wp_error( $result ) ) {
			$this->redirect(
				$page,
				'error',
				array_merge(
					$args,
					array(
						'yo_booking_message' => $result->get_error_message(),
					)
				)
			);
		}

		$this->redirect( $page, 'saved', $args );
	}
}
