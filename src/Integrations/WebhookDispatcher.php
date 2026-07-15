<?php
/**
 * Signed outbound webhook dispatcher.
 *
 * @package YoBooking
 */

namespace YoBooking\Integrations;

use YoBooking\Repositories\AppointmentRepository;
use YoBooking\Repositories\PaymentRepository;
use YoBooking\Repositories\WebhookDeliveryRepository;
use YoBooking\Repositories\WebhookEndpointRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Queues domain events and processes retryable HTTP deliveries.
 */
final class WebhookDispatcher {
	const PROCESS_HOOK = 'yo_booking_process_webhook_delivery';
	const MAX_ATTEMPTS = 5;

	/** @return void */
	public function boot() {
		add_action( 'yo_booking_appointment_created', array( $this, 'appointment_created' ), 20, 2 );
		add_action( 'yo_booking_appointment_updated', array( $this, 'appointment_updated' ), 20, 3 );
		add_action( 'yo_booking_appointment_status_changed', array( $this, 'appointment_status_changed' ), 20, 4 );
		add_action( 'yo_booking_appointment_rescheduled', array( $this, 'appointment_rescheduled' ), 20, 3 );
		add_action( 'yo_booking_payment_status_changed', array( $this, 'payment_status_changed' ), 20, 4 );
		add_action( 'yo_booking_payment_transaction_recorded', array( $this, 'payment_transaction_recorded' ), 20, 2 );
		add_action( self::PROCESS_HOOK, array( $this, 'process' ) );
	}

	/** @param int $id Appointment ID. @return void */
	public function appointment_created( $id ) {
		$this->queue_appointment( 'appointment.created', $id );
	}

	/** @param int $id Appointment ID. @return void */
	public function appointment_updated( $id ) {
		$this->queue_appointment( 'appointment.updated', $id );
	}

	/** @param int $id Appointment ID. @param string $new New status. @param string $old Old status. @return void */
	public function appointment_status_changed( $id, $new, $old ) {
		$this->queue_appointment( 'appointment.status_changed', $id, array( 'previous_status' => sanitize_key( $old ), 'status' => sanitize_key( $new ) ) );
	}

	/** @param int $id Appointment ID. @return void */
	public function appointment_rescheduled( $id ) {
		$this->queue_appointment( 'appointment.rescheduled', $id );
	}

	/** @param int $id Appointment ID. @param string $status Payment status. @param int $payment_id Payment ID. @return void */
	public function payment_status_changed( $id, $status, $payment_id, $old_status = '' ) {
		$this->queue_appointment( 'payment.status_changed', $id, array( 'previous_payment_status' => sanitize_key( $old_status ), 'payment_status' => sanitize_key( $status ), 'payment_id' => absint( $payment_id ) ) );
	}

	/** @param int $payment_id Payment ID. @param array $record Inserted record. @return void */
	public function payment_transaction_recorded( $payment_id, $record ) {
		$payment = ( new PaymentRepository() )->find( absint( $payment_id ) );
		if ( ! $payment ) {
			return;
		}
		$changes = array( 'transaction' => $this->payment_payload( $payment ) );
		$this->queue_appointment( 'payment.transaction_recorded', (int) $payment->appointment_id, $changes );
		if ( 'refund' === $payment->kind && 'refunded' === $payment->status ) {
			$this->queue_appointment( 'payment.refunded', (int) $payment->appointment_id, $changes );
		}
	}

	/** @param string $event Event key. @param int $appointment_id Appointment ID. @param array $changes Event changes. @return array */
	public function queue_appointment( $event, $appointment_id, array $changes = array() ) {
		$appointment = ( new AppointmentRepository() )->find_with_details( absint( $appointment_id ) );
		if ( ! $appointment ) {
			return array();
		}
		return $this->queue( $event, 'appointment', (int) $appointment->id, array( 'appointment' => $this->appointment_payload( $appointment ), 'changes' => $changes ) );
	}

	/** @param int $endpoint_id Endpoint ID. @return int|\WP_Error */
	public function queue_test( $endpoint_id ) {
		$endpoint = ( new WebhookEndpointRepository() )->find( absint( $endpoint_id ) );
		if ( ! $endpoint ) {
			return new \WP_Error( 'yo_booking_webhook_endpoint_missing', __( 'Webhook endpoint not found.', 'yo-booking' ) );
		}
		$payload = $this->envelope( 'webhook.test', 'endpoint', (int) $endpoint->id, array( 'message' => 'Yo Booking webhook test' ) );
		$id = ( new WebhookDeliveryRepository() )->create( array( 'endpoint_id' => (int) $endpoint->id, 'event' => 'webhook.test', 'object_type' => 'endpoint', 'object_id' => (int) $endpoint->id, 'payload' => $payload ) );
		if ( ! is_wp_error( $id ) ) {
			$this->schedule( $id, 1 );
		}
		return $id;
	}

	/** @param string $event Event. @param string $object_type Object type. @param int $object_id Object ID. @param array $data Payload data. @return array */
	public function queue( $event, $object_type, $object_id, array $data ) {
		$ids = array();
		$deliveries = new WebhookDeliveryRepository();
		foreach ( ( new WebhookEndpointRepository() )->active_for_event( $event ) as $endpoint ) {
			$id = $deliveries->create( array( 'endpoint_id' => (int) $endpoint->id, 'event' => $event, 'object_type' => $object_type, 'object_id' => $object_id, 'payload' => $this->envelope( $event, $object_type, $object_id, $data ) ) );
			if ( ! is_wp_error( $id ) ) {
				$ids[] = $id;
				$this->schedule( $id, 1 );
			}
		}
		return $ids;
	}

	/** @param int $delivery_id Delivery ID. @return bool */
	public function process( $delivery_id ) {
		$deliveries = new WebhookDeliveryRepository();
		$delivery   = $deliveries->find( absint( $delivery_id ) );
		if ( ! $delivery || 'delivered' === $delivery->status ) {
			return false;
		}
		$endpoints = new WebhookEndpointRepository();
		$endpoint  = $endpoints->find( (int) $delivery->endpoint_id );
		$attempts  = (int) $delivery->attempts + 1;
		if ( ! $endpoint || 'active' !== $endpoint->status ) {
			$deliveries->mark_failed( $delivery->id, $attempts, 0, '', __( 'Webhook endpoint is missing or inactive.', 'yo-booking' ) );
			return false;
		}
		$secret = $endpoints->secret( $endpoint );
		if ( ! $secret ) {
			$deliveries->mark_failed( $delivery->id, $attempts, 0, '', __( 'Webhook signing secret could not be decrypted.', 'yo-booking' ) );
			return false;
		}

		$timestamp = time();
		$body      = (string) $delivery->payload;
		$response  = wp_safe_remote_post(
			$endpoint->url,
			array(
				'timeout'     => max( 3, min( 30, (int) $endpoint->timeout_seconds ) ),
				'redirects'   => 0,
				'httpversion' => '1.1',
				'headers'     => array(
					'Content-Type'           => 'application/json',
					'User-Agent'             => 'Yo-Booking/' . YO_BOOKING_VERSION,
					'X-Yo-Booking-Event'     => $delivery->event,
					'X-Yo-Booking-Delivery'  => (string) $delivery->id,
					'X-Yo-Booking-Timestamp' => (string) $timestamp,
					'X-Yo-Booking-Signature' => self::signature( $timestamp, $body, $secret ),
				),
				'body'        => $body,
			),
		);
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$response_body = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
		if ( $code >= 200 && $code < 300 ) {
			$deliveries->mark_delivered( $delivery->id, $attempts, $code, $response_body );
			return true;
		}

		// translators: %d: HTTP response status code.
		$error = is_wp_error( $response ) ? $response->get_error_message() : sprintf( __( 'Endpoint returned HTTP %d.', 'yo-booking' ), $code );
		$delay = $this->retry_delay( $attempts );
		$next  = $delay ? gmdate( 'Y-m-d H:i:s', time() + $delay ) : null;
		$deliveries->mark_failed( $delivery->id, $attempts, $code, $response_body, $error, $next );
		if ( $delay ) {
			$this->schedule( $delivery->id, $delay );
		}
		return false;
	}

	/** @param int $delivery_id Delivery ID. @param int $delay Delay seconds. @return void */
	public function schedule( $delivery_id, $delay = 1 ) {
		$args = array( absint( $delivery_id ) );
		if ( ! wp_next_scheduled( self::PROCESS_HOOK, $args ) ) {
			wp_schedule_single_event( time() + max( 1, absint( $delay ) ), self::PROCESS_HOOK, $args );
		}
	}

	/** @param int $timestamp Unix timestamp. @param string $body JSON body. @param string $secret Secret. @return string */
	public static function signature( $timestamp, $body, $secret ) {
		return 'sha256=' . hash_hmac( 'sha256', absint( $timestamp ) . '.' . (string) $body, (string) $secret );
	}

	/** @param int $attempts Attempts made. @return int */
	private function retry_delay( $attempts ) {
		$delays = array( 1 => 60, 2 => 300, 3 => 1800, 4 => 7200 );
		return isset( $delays[ $attempts ] ) && $attempts < self::MAX_ATTEMPTS ? $delays[ $attempts ] : 0;
	}

	/** @param string $event Event. @param string $object_type Object type. @param int $object_id Object ID. @param array $data Data. @return array */
	private function envelope( $event, $object_type, $object_id, array $data ) {
		return array( 'id' => wp_generate_uuid4(), 'event' => $event, 'created_at' => gmdate( 'c' ), 'object' => array( 'type' => $object_type, 'id' => absint( $object_id ) ), 'data' => $data );
	}

	/** @param object $appointment Appointment. @return array */
	private function appointment_payload( $appointment ) {
		return array(
			'id' => (int) $appointment->id, 'uuid' => $appointment->uuid, 'status' => $appointment->status,
			'start_at' => gmdate( 'c', strtotime( $appointment->start_at . ' UTC' ) ), 'end_at' => gmdate( 'c', strtotime( $appointment->end_at . ' UTC' ) ), 'timezone' => $appointment->timezone,
			'service' => array( 'id' => (int) $appointment->service_id, 'name' => $appointment->service_name ),
			'staff' => array( 'id' => (int) $appointment->staff_id, 'name' => $appointment->staff_name ),
			'customer' => array( 'id' => (int) $appointment->customer_id, 'name' => $appointment->customer_name, 'email' => $appointment->customer_email, 'phone' => $appointment->customer_phone ),
			'payment' => array(
				'reference' => isset( $appointment->payment_reference ) ? $appointment->payment_reference : '',
				'method' => isset( $appointment->payment_method ) ? $appointment->payment_method : '',
				'method_title' => isset( $appointment->payment_method_title ) ? $appointment->payment_method_title : '',
				'status' => $appointment->payment_status,
				'subtotal' => isset( $appointment->subtotal_amount ) ? (string) $appointment->subtotal_amount : '0',
				'discount' => isset( $appointment->discount_amount ) ? (string) $appointment->discount_amount : '0',
				'tax' => isset( $appointment->tax_amount ) ? (string) $appointment->tax_amount : '0',
				'total' => (string) $appointment->total_amount,
				'paid' => isset( $appointment->paid_amount ) ? (string) $appointment->paid_amount : '0',
				'refunded' => isset( $appointment->refunded_amount ) ? (string) $appointment->refunded_amount : '0',
				'balance' => isset( $appointment->balance_amount ) ? (string) $appointment->balance_amount : (string) $appointment->total_amount,
				'currency' => $appointment->currency,
			),
		);
	}

	/** @param object $payment Payment record. @return array */
	private function payment_payload( $payment ) {
		return array(
			'id' => (int) $payment->id,
			'kind' => $payment->kind,
			'provider' => $payment->provider,
			'transaction_id' => $payment->transaction_id,
			'status' => $payment->status,
			'amount' => (string) $payment->amount,
			'currency' => $payment->currency,
			'processed_at' => $payment->processed_at,
			'created_at' => $payment->created_at,
		);
	}
}
