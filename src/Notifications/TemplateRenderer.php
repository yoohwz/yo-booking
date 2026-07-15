<?php
/**
 * Email template renderer.
 *
 * @package YoBooking
 */

namespace YoBooking\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use YoBooking\Payments\PaymentManager;
use YoBooking\Settings\Repository as SettingsRepository;
use YoBooking\Support\DateTimeFormatter;
use YoBooking\Support\ActionToken;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces appointment placeholders in notification templates.
 */
final class TemplateRenderer {
	/**
	 * Return supported placeholder names.
	 *
	 * @return array
	 */
	public static function placeholder_names() {
		return array(
			'{appointment_id}',
			'{appointment_uuid}',
			'{appointment_status}',
			'{appointment_date}',
			'{appointment_time}',
			'{appointment_start}',
			'{appointment_end}',
			'{timezone}',
			'{customer_name}',
			'{customer_email}',
			'{customer_phone}',
			'{service_name}',
			'{staff_name}',
			'{payment_method}',
			'{payment_reference}',
			'{payment_amount_due}',
			'{payment_paid}',
			'{payment_refunded}',
			'{payment_balance}',
			'{payment_instructions}',
			'{cancel_link}',
			'{reschedule_link}',
			'{company_name}',
			'{company_email}',
			'{company_phone}',
			'{company_address}',
			'{site_url}',
		);
	}

	/**
	 * Render a template object into subject/body strings.
	 *
	 * @param object $template Template row.
	 * @param object $appointment Appointment row with details.
	 * @return array
	 */
	public function render( $template, $appointment ) {
		$placeholders = $this->placeholders( $appointment );

		return array(
			'subject'      => wp_strip_all_tags( strtr( (string) $template->subject, $placeholders ) ),
			'heading'      => wp_strip_all_tags( strtr( (string) $template->heading, $placeholders ) ),
			'body'         => strtr( (string) $template->body, $placeholders ),
			'placeholders' => $placeholders,
		);
	}

	/**
	 * Build an email message body.
	 *
	 * @param array  $rendered Rendered template data.
	 * @param string $email_type html or plain.
	 * @return string
	 */
	public function message( array $rendered, $email_type ) {
		if ( 'plain' === $email_type ) {
			return trim( $rendered['heading'] . "\n\n" . wp_strip_all_tags( $rendered['body'] ) );
		}

		$heading = '' !== $rendered['heading'] ? '<h1 style="font-size:20px;line-height:1.35;margin:0 0 16px;">' . esc_html( $rendered['heading'] ) . '</h1>' : '';
		$body    = wpautop( wp_kses_post( $rendered['body'] ) );

		return '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.55;color:#1f2937;">' . $heading . $body . '</div>';
	}

	/**
	 * Build placeholder values for an appointment.
	 *
	 * @param object $appointment Appointment row with details.
	 * @return array
	 */
	private function placeholders( $appointment ) {
		$settings = ( new SettingsRepository() )->all();
		$timezone = DateTimeFormatter::timezone();
		$start    = $this->local_datetime( $appointment->start_at, $timezone );
		$end      = $this->local_datetime( $appointment->end_at, $timezone );
		$uuid     = ! empty( $appointment->uuid ) ? $appointment->uuid : (string) $appointment->id;
		$payment  = ( new PaymentManager() )->summary_for_appointment( $appointment );

		return array(
			'{appointment_id}'     => (string) absint( $appointment->id ),
			'{appointment_uuid}'   => $uuid,
			'{appointment_status}' => isset( $appointment->status ) ? (string) $appointment->status : '',
			'{appointment_date}'   => wp_date( get_option( 'date_format' ), $start->getTimestamp(), $timezone ),
			'{appointment_time}'   => wp_date( get_option( 'time_format' ), $start->getTimestamp(), $timezone ),
			'{appointment_start}'  => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $start->getTimestamp(), $timezone ),
			'{appointment_end}'    => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $end->getTimestamp(), $timezone ),
			'{timezone}'           => $timezone->getName(),
			'{customer_name}'      => isset( $appointment->customer_name ) ? (string) $appointment->customer_name : '',
			'{customer_email}'     => isset( $appointment->customer_email ) ? (string) $appointment->customer_email : '',
			'{customer_phone}'     => isset( $appointment->customer_phone ) ? (string) $appointment->customer_phone : '',
			'{service_name}'       => isset( $appointment->service_name ) ? (string) $appointment->service_name : '',
			'{staff_name}'         => isset( $appointment->staff_name ) ? (string) $appointment->staff_name : '',
			'{payment_method}'     => isset( $payment['provider_title'] ) ? (string) $payment['provider_title'] : '',
			'{payment_reference}'  => isset( $payment['reference'] ) ? (string) $payment['reference'] : '',
			'{payment_amount_due}' => isset( $payment['amount_due_display'] ) ? (string) $payment['amount_due_display'] : '',
			'{payment_paid}'       => isset( $payment['paid_display'] ) ? (string) $payment['paid_display'] : '',
			'{payment_refunded}'   => isset( $payment['refunded_display'] ) ? (string) $payment['refunded_display'] : '',
			'{payment_balance}'    => isset( $payment['balance_display'] ) ? (string) $payment['balance_display'] : '',
			'{payment_instructions}' => isset( $payment['instructions'] ) ? (string) $payment['instructions'] : '',
			'{cancel_link}'        => $this->action_link( 'cancel', $uuid, ActionToken::generate( $appointment, 'cancel' ) ),
			'{reschedule_link}'    => $this->action_link( 'reschedule', $uuid, ActionToken::generate( $appointment, 'reschedule' ) ),
			'{company_name}'       => isset( $settings['company']['name'] ) ? (string) $settings['company']['name'] : get_bloginfo( 'name' ),
			'{company_email}'      => isset( $settings['company']['email'] ) ? (string) $settings['company']['email'] : get_option( 'admin_email' ),
			'{company_phone}'      => isset( $settings['company']['phone'] ) ? (string) $settings['company']['phone'] : '',
			'{company_address}'    => isset( $settings['company']['address'] ) ? (string) $settings['company']['address'] : '',
			'{site_url}'           => home_url( '/' ),
		);
	}

	/**
	 * Create a future frontend action URL.
	 *
	 * @param string $action Action name.
	 * @param string $uuid Appointment UUID.
	 * @return string
	 */
	private function action_link( $action, $uuid, $token ) {
		return add_query_arg(
			array(
				'yo_booking_action' => sanitize_key( $action ),
				'appointment'       => rawurlencode( $uuid ),
				'token'             => rawurlencode( $token ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Convert a UTC datetime to local time.
	 *
	 * @param string       $datetime UTC datetime string.
	 * @param DateTimeZone $timezone Timezone.
	 * @return DateTimeImmutable
	 */
	private function local_datetime( $datetime, DateTimeZone $timezone ) {
		try {
			return ( new DateTimeImmutable( $datetime, new DateTimeZone( 'UTC' ) ) )->setTimezone( $timezone );
		} catch ( Exception $exception ) {
			return new DateTimeImmutable( 'now', $timezone );
		}
	}

	/**
	 * Resolve a timezone safely.
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
