<?php
/**
 * Payment manager.
 *
 * @package YoBooking
 */

namespace YoBooking\Payments;

use YoBooking\Repositories\AppointmentRepository;
use YoBooking\Repositories\PaymentRepository;
use YoBooking\Settings\Repository as SettingsRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates payment providers and appointment payment records.
 */
final class PaymentManager {
	/**
	 * Return customer-facing payment configuration.
	 *
	 * @return array
	 */
	public function frontend_config() {
		$settings = ( new SettingsRepository() )->all();
		$enabled  = ! empty( $settings['payments']['enabled'] ) && 'none' !== $settings['payments']['collection_mode'];
		$registry = new PaymentProviderRegistry();
		$methods  = array();

		if ( $enabled ) {
			foreach ( $registry->enabled() as $provider ) {
				if ( 'local' === $provider->id() ) {
					$description = __( 'Pay in person when you attend the appointment.', 'yo-booking' );
				} elseif ( 'bank_transfer' === $provider->id() ) {
					$description = __( 'Receive bank transfer instructions after confirming the booking.', 'yo-booking' );
				} else {
					// translators: %s: payment provider title.
					$description = sprintf( __( 'Continue with %s after confirming the booking.', 'yo-booking' ), $provider->title() );
				}
				$description = apply_filters( 'yo_booking_payment_method_description', $description, $provider );
				$methods[] = array(
					'id'          => $provider->id(),
					'title'       => $provider->title(),
					'description' => wp_strip_all_tags( (string) $description ),
				);
			}
		}

		return array(
			'enabled'        => $enabled && ! empty( $methods ),
			'default_method' => $registry->default_id(),
			'methods'        => $methods,
		);
	}

	/**
	 * Validate a payment method submitted by a customer.
	 *
	 * @param string $method_id Submitted method ID.
	 * @param string $currency Service currency.
	 * @param mixed  $amount Service amount.
	 * @return string|WP_Error
	 */
	public function validate_method( $method_id, $currency = '', $amount = 0 ) {
		$config = $this->frontend_config();

		if ( empty( $config['enabled'] ) ) {
			return '';
		}

		$method_id = sanitize_key( $method_id ? $method_id : $config['default_method'] );

		$registry = new PaymentProviderRegistry();
		if ( ! $registry->get( $method_id ) ) {
			return new WP_Error( 'yo_booking_payment_method_invalid', __( 'Select a valid payment method.', 'yo-booking' ), array( 'status' => 400 ) );
		}
		if ( $currency ) {
			$supported = $registry->supports( $method_id, $currency, $amount );
			if ( is_wp_error( $supported ) ) {
				return $supported;
			}
		}

		return $method_id;
	}

	/** Create a checkout session for an online add-on, when selected. */
	public function create_checkout( $appointment, array $context = array() ) {
		$provider = ( new PaymentProviderRegistry() )->get( isset( $appointment->payment_method ) ? $appointment->payment_method : '' );
		if ( ! $provider instanceof OnlinePaymentGatewayInterface ) {
			return array();
		}
		$result = $provider->create_checkout( $appointment, $context );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$url = is_array( $result ) && isset( $result['checkout_url'] ) ? esc_url_raw( $result['checkout_url'] ) : '';
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'yo_booking_checkout_url_invalid', __( 'The payment gateway returned an invalid checkout URL.', 'yo-booking' ) );
		}
		$result['checkout_url'] = $url;
		return $result;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'yo_booking_appointment_created', array( $this, 'sync_pending_payment' ), 20 );
		add_action( 'yo_booking_appointment_updated', array( $this, 'sync_pending_payment' ), 20 );
	}

	/**
	 * Return payment summary for an appointment.
	 *
	 * @param int|object $appointment Appointment ID or row.
	 * @return array
	 */
	public function summary_for_appointment( $appointment ) {
		if ( is_numeric( $appointment ) ) {
			$appointment = ( new AppointmentRepository() )->find_with_details( absint( $appointment ) );
		}

		if ( ! $appointment ) {
			return array(
				'enabled'        => false,
				'provider'       => '',
				'collection_mode' => 'none',
				'total_amount'   => '0.00',
				'amount_due'     => '0.00',
				'currency'       => '',
				'payment_status' => 'pending',
				'instructions'   => '',
			);
		}

		return ( new PaymentCalculator() )->summary( $appointment );
	}

	/**
	 * Ensure a pending payment record exists after create/update.
	 *
	 * @param int $appointment_id Appointment ID.
	 * @return void
	 */
	public function sync_pending_payment( $appointment_id ) {
		if ( ! ( new SettingsRepository() )->get( 'payments.enabled', false ) ) {
			return;
		}

		$appointment = ( new AppointmentRepository() )->find_with_details( $appointment_id );

		if ( ! $appointment ) {
			return;
		}

		$summary = $this->summary_for_appointment( $appointment );
		( new PaymentRepository() )->ensure_pending_for_appointment( $appointment, $summary );
	}

	/**
	 * Record a normalized transaction supplied by an online-payment add-on.
	 *
	 * @param int   $appointment_id Appointment ID.
	 * @param array $transaction Provider, status, amount, IDs, and metadata.
	 * @return int|WP_Error
	 */
	public function record_transaction( $appointment_id, array $transaction ) {
		$appointment = ( new AppointmentRepository() )->find( absint( $appointment_id ) );
		if ( ! $appointment ) {
			return new WP_Error( 'yo_booking_appointment_not_found', __( 'Appointment not found.', 'yo-booking' ) );
		}

		$idempotency_key = isset( $transaction['idempotency_key'] ) ? sanitize_text_field( $transaction['idempotency_key'] ) : '';
		if ( ! $idempotency_key ) {
			return new WP_Error( 'yo_booking_payment_idempotency_required', __( 'Online payment transactions require an idempotency key.', 'yo-booking' ) );
		}

		return ( new PaymentRepository() )->create(
			array(
				'appointment_id'  => (int) $appointment->id,
				'provider'        => isset( $transaction['provider'] ) ? sanitize_key( $transaction['provider'] ) : $appointment->payment_method,
				'transaction_id'  => isset( $transaction['transaction_id'] ) ? sanitize_text_field( $transaction['transaction_id'] ) : '',
				'kind'            => isset( $transaction['kind'] ) ? sanitize_key( $transaction['kind'] ) : 'payment',
				'amount'          => isset( $transaction['amount'] ) ? $transaction['amount'] : 0,
				'currency'        => $appointment->currency,
				'status'          => isset( $transaction['status'] ) ? sanitize_key( $transaction['status'] ) : 'pending',
				'idempotency_key' => $idempotency_key,
				'method_title'    => isset( $transaction['method_title'] ) ? sanitize_text_field( $transaction['method_title'] ) : $appointment->payment_method_title,
				'note'            => isset( $transaction['note'] ) ? wp_kses_post( $transaction['note'] ) : '',
				'gateway_metadata' => isset( $transaction['gateway_metadata'] ) && is_array( $transaction['gateway_metadata'] ) ? $transaction['gateway_metadata'] : array(),
			)
		);
	}
}
