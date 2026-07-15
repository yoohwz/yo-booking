<?php
/**
 * Public self-service appointment page.
 *
 * @package YoBooking
 */

namespace YoBooking\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a standalone appointment management shell for email action links.
 */
final class SelfServicePage {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
	}

	/**
	 * Render the standalone page when a notification action URL is opened.
	 *
	 * @return void
	 */
	public function maybe_render() {
		$action = isset( $_GET['yo_booking_action'] ) ? sanitize_key( wp_unslash( $_GET['yo_booking_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$uuid   = isset( $_GET['appointment'] ) ? sanitize_text_field( wp_unslash( $_GET['appointment'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token  = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $action, array( 'cancel', 'reschedule' ), true ) || ! wp_is_uuid( $uuid ) || '' === $token ) {
			return;
		}

		$this->enqueue_assets( $action, $uuid, $token );

		status_header( 200 );
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );

		?><!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>" />
			<meta name="viewport" content="width=device-width, initial-scale=1" />
			<meta name="robots" content="noindex,nofollow,noarchive" />
			<meta name="referrer" content="no-referrer" />
			<?php wp_head(); ?>
		</head>
		<body <?php body_class( 'yo-booking-self-service-page' ); ?>>
			<?php wp_body_open(); ?>
			<main class="yo-booking-self-service">
				<div class="yo-booking-app" data-yo-booking-root="1" data-yo-booking-mode="manage">
					<div class="yo-booking-loading"><?php echo esc_html__( 'Loading appointment...', 'yo-booking' ); ?></div>
				</div>
			</main>
			<?php wp_footer(); ?>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Register and enqueue frontend assets with action context.
	 *
	 * @param string $action Action name.
	 * @param string $uuid Appointment UUID.
	 * @param string $token Action token.
	 * @return void
	 */
	private function enqueue_assets( $action, $uuid, $token ) {
		wp_register_style(
			'yo-booking-frontend',
			YO_BOOKING_URL . 'assets/css/frontend.css',
			array(),
			filemtime( YO_BOOKING_PATH . 'assets/css/frontend.css' )
		);

		wp_register_script(
			'yo-booking-frontend',
			YO_BOOKING_URL . 'assets/js/frontend.js',
			array(),
			filemtime( YO_BOOKING_PATH . 'assets/js/frontend.js' ),
			true
		);

		wp_localize_script(
			'yo-booking-frontend',
			'YoBookingFrontend',
			array(
				'restUrl'  => esc_url_raw( rest_url( 'yo-booking/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'timezone' => wp_timezone_string() ? wp_timezone_string() : 'UTC',
				'today'    => current_time( 'Y-m-d' ),
				'appearance' => Appearance::frontend_config(),
				'i18n'       => Shortcode::frontend_strings(),
				'action'   => array(
					'type'        => $action,
					'appointment' => $uuid,
					'token'       => $token,
				),
			)
		);

		wp_add_inline_style( 'yo-booking-frontend', Appearance::inline_css() );

		wp_enqueue_style( 'yo-booking-frontend' );
		wp_enqueue_script( 'yo-booking-frontend' );
	}
}
