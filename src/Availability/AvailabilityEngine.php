<?php
/**
 * Availability calculation engine.
 *
 * @package YoBooking
 */

namespace YoBooking\Availability;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use YoBooking\Database\Migrator;
use YoBooking\Repositories\AvailabilityExceptionRepository;
use YoBooking\Repositories\AvailabilityRuleRepository;
use YoBooking\Repositories\ServiceRepository;
use YoBooking\Repositories\StaffRepository;
use YoBooking\Repositories\StaffServiceRepository;
use YoBooking\Settings\Repository as SettingsRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Calculates bookable dates and times.
 */
final class AvailabilityEngine {
	/** @var array|null Request-scoped bulk-loaded data. */
	private $preloaded;
	/**
	 * Availability rules.
	 *
	 * @var AvailabilityRuleRepository
	 */
	private $rules;

	/**
	 * Availability exceptions.
	 *
	 * @var AvailabilityExceptionRepository
	 */
	private $exceptions;

	/**
	 * Service repository.
	 *
	 * @var ServiceRepository
	 */
	private $services;

	/**
	 * Staff repository.
	 *
	 * @var StaffRepository
	 */
	private $staff;

	/**
	 * Staff-service repository.
	 *
	 * @var StaffServiceRepository
	 */
	private $staff_services;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private $settings;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->rules          = new AvailabilityRuleRepository();
		$this->exceptions     = new AvailabilityExceptionRepository();
		$this->services       = new ServiceRepository();
		$this->staff          = new StaffRepository();
		$this->staff_services = new StaffServiceRepository();
		$this->settings       = new SettingsRepository();
	}

	/**
	 * Return dates that have at least one available slot.
	 *
	 * @param array $args Query arguments.
	 * @return array|WP_Error
	 */
	public function available_dates( array $args ) {
		$timezone = $this->timezone( isset( $args['timezone'] ) ? $args['timezone'] : '' );
		$range    = $this->date_range( $args, $timezone );

		if ( is_wp_error( $range ) ) {
			return $range;
		}
		$cache_key = 'yo_booking_dates_' . md5( wp_json_encode( array( $args, $range['from']->format( 'Y-m-d' ), $range['to']->format( 'Y-m-d' ), Migrator::SCHEMA_VERSION ) ) );
		if ( empty( $args['exclude_appointment_id'] ) ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$service = $this->services->find( isset( $args['service_id'] ) ? absint( $args['service_id'] ) : 0 );
		if ( ! $service || 'active' !== $service->status ) {
			return new WP_Error( 'yo_booking_service_unavailable', __( 'The selected service is unavailable.', 'yo-booking' ) );
		}
		$staff_ids = $this->candidate_staff_ids( (int) $service->id, isset( $args['staff_id'] ) ? absint( $args['staff_id'] ) : 0 );
		if ( is_wp_error( $staff_ids ) ) {
			return $staff_ids;
		}
		$this->preloaded = array(
			'service'     => $service,
			'staff_ids'   => $staff_ids,
			'settings'    => $this->settings->all(),
			'rules'       => $this->rules->for_context( $staff_ids, $range['from']->format( 'Y-m-d' ), $range['to']->format( 'Y-m-d' ) ),
			'exceptions'  => $this->exceptions->for_context( $staff_ids, $range['from']->format( 'Y-m-d' ), $range['to']->format( 'Y-m-d' ) ),
			'conflicts'   => $this->conflicts_for_range( $service, $staff_ids, $range['from'], $range['to'], $timezone, isset( $args['exclude_appointment_id'] ) ? absint( $args['exclude_appointment_id'] ) : 0 ),
		);
		$dates  = array();
		$cursor = $range['from'];

		try {
		while ( $cursor <= $range['to'] ) {
			$date  = $cursor->format( 'Y-m-d' );
			$times = $this->available_times(
				array_merge(
					$args,
					array(
						'date'     => $date,
						'timezone' => $timezone->getName(),
					)
				)
			);

			if ( is_wp_error( $times ) ) {
				return $times;
			}

			if ( ! empty( $times['slots'] ) ) {
				$dates[] = array(
					'date'       => $date,
					'slot_count' => count( $times['slots'] ),
				);
			}

			$cursor = $cursor->add( new DateInterval( 'P1D' ) );
		}
		} finally {
			$this->preloaded = null;
		}

		$result = array(
			'timezone' => $timezone->getName(),
			'from'     => $range['from']->format( 'Y-m-d' ),
			'to'       => $range['to']->format( 'Y-m-d' ),
			'dates'    => $dates,
		);
		if ( empty( $args['exclude_appointment_id'] ) ) {
			set_transient( $cache_key, $result, (int) apply_filters( 'yo_booking_availability_cache_ttl', 60 ) );
		}
		return $result;
	}

	/**
	 * Return available slots for one date.
	 *
	 * @param array $args Query arguments.
	 * @return array|WP_Error
	 */
	public function available_times( array $args ) {
		$timezone = $this->timezone( isset( $args['timezone'] ) ? $args['timezone'] : '' );
		$date     = $this->date( isset( $args['date'] ) ? $args['date'] : '' );

		if ( is_wp_error( $date ) ) {
			return $date;
		}

		$range_check = $this->date_range(
			array(
				'from' => $date->format( 'Y-m-d' ),
				'to'   => $date->format( 'Y-m-d' ),
			),
			$timezone
		);

		if ( is_wp_error( $range_check ) ) {
			return $range_check;
		}

		$service = $this->preloaded ? $this->preloaded['service'] : $this->services->find( isset( $args['service_id'] ) ? absint( $args['service_id'] ) : 0 );

		if ( ! $service || 'active' !== $service->status ) {
			return new WP_Error( 'yo_booking_service_unavailable', __( 'The selected service is unavailable.', 'yo-booking' ) );
		}

		$staff_ids = $this->preloaded ? $this->preloaded['staff_ids'] : $this->candidate_staff_ids( (int) $service->id, isset( $args['staff_id'] ) ? absint( $args['staff_id'] ) : 0 );

		if ( is_wp_error( $staff_ids ) ) {
			return $staff_ids;
		}

		$settings = $this->preloaded ? $this->preloaded['settings'] : $this->settings->all();
		$slots    = array();
		$exclude_appointment_id = isset( $args['exclude_appointment_id'] ) ? absint( $args['exclude_appointment_id'] ) : 0;
		$conflicts = $this->preloaded ? $this->preloaded['conflicts'] : $this->conflicts_for_date( $service, $staff_ids, $date, $timezone, $exclude_appointment_id );

		foreach ( $staff_ids as $staff_id ) {
			foreach ( $this->slots_for_staff( $service, $staff_id, $date, $timezone, $settings, $conflicts ) as $slot ) {
				$key = $slot['start_utc'] . '|' . $slot['end_utc'];

				if ( ! isset( $slots[ $key ] ) ) {
					$slots[ $key ] = $slot;
					continue;
				}

				$slots[ $key ]['staff_ids'] = array_values( array_unique( array_merge( $slots[ $key ]['staff_ids'], $slot['staff_ids'] ) ) );
			}
		}

		$slots = array_values( $slots );
		usort(
			$slots,
			static function ( $a, $b ) {
				return strcmp( $a['start_utc'], $b['start_utc'] );
			}
		);

		return array(
			'date'       => $date->format( 'Y-m-d' ),
			'timezone'   => $timezone->getName(),
			'service_id' => (int) $service->id,
			'staff_id'   => isset( $args['staff_id'] ) ? absint( $args['staff_id'] ) : 0,
			'slots'      => $slots,
		);
	}

	/**
	 * Return slots for a single staff candidate.
	 *
	 * @param object            $service Service row.
	 * @param int               $staff_id Staff ID, or 0 for unassigned/global.
	 * @param DateTimeImmutable $date Local date.
	 * @param DateTimeZone      $timezone Output timezone.
	 * @param array             $settings Plugin settings.
	 * @param array             $conflicts Preloaded occupied intervals.
	 * @return array
	 */
	private function slots_for_staff( $service, $staff_id, DateTimeImmutable $date, DateTimeZone $timezone, array $settings, array $conflicts = array() ) {
		$weekday          = (int) $date->format( 'w' );
		$date_string      = $date->format( 'Y-m-d' );
		$default_interval = max( 5, absint( $settings['booking']['slot_interval_minutes'] ) );
		$service_minutes  = max( 5, absint( $service->duration_minutes ) );
		$lead_minutes     = max( 0, absint( $settings['booking']['lead_time_minutes'] ) );
		$windows          = $this->windows_for_owner( 'global', 0, $weekday, $date_string, $timezone, $default_interval );

		if ( $staff_id && $this->owner_has_rules_for_weekday( 'staff', $staff_id, $weekday, $date_string ) ) {
			$windows = $this->windows_for_owner( 'staff', $staff_id, $weekday, $date_string, $timezone, $default_interval );
		}

		$windows = $this->apply_exceptions(
			$windows,
			$this->exceptions_for_owner_on_date( 'global', 0, $date_string ),
			$date_string,
			$timezone,
			$default_interval
		);

		if ( $staff_id ) {
			$windows = $this->apply_exceptions(
				$windows,
					$this->exceptions_for_owner_on_date( 'staff', $staff_id, $date_string ),
				$date_string,
				$timezone,
				$default_interval
			);
		}

		$now_utc         = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$lead_cutoff_utc = $now_utc->add( new DateInterval( 'PT' . $lead_minutes . 'M' ) );
		$slots           = array();

		foreach ( $windows as $window ) {
			$cursor = $window['start'];
			$step   = new DateInterval( 'PT' . max( 5, absint( $window['interval'] ) ) . 'M' );

			while ( $cursor->add( new DateInterval( 'PT' . $service_minutes . 'M' ) ) <= $window['end'] ) {
				$end       = $cursor->add( new DateInterval( 'PT' . $service_minutes . 'M' ) );
				$start_utc = $cursor->setTimezone( new DateTimeZone( 'UTC' ) );
				$end_utc   = $end->setTimezone( new DateTimeZone( 'UTC' ) );

				if ( $start_utc >= $lead_cutoff_utc && ! $this->has_preloaded_conflict( $service, $staff_id, $cursor, $end, $conflicts ) ) {
					$slots[] = array(
						'start'     => $cursor->format( 'Y-m-d H:i:s' ),
						'end'       => $end->format( 'Y-m-d H:i:s' ),
						'start_utc' => $start_utc->format( 'Y-m-d H:i:s' ),
						'end_utc'   => $end_utc->format( 'Y-m-d H:i:s' ),
						'staff_ids' => $staff_id ? array( $staff_id ) : array(),
					);
				}

				$cursor = $cursor->add( $step );
			}
		}

		return $slots;
	}

	/**
	 * Build windows for one owner.
	 *
	 * @param string       $owner_type Owner type.
	 * @param int          $owner_id Owner ID.
	 * @param int          $weekday Weekday.
	 * @param string       $date Date in Y-m-d format.
	 * @param DateTimeZone $output_timezone Output timezone.
	 * @param int          $default_interval Default slot interval.
	 * @return array
	 */
	private function windows_for_owner( $owner_type, $owner_id, $weekday, $date, DateTimeZone $output_timezone, $default_interval ) {
		$rules   = $this->rules_for_owner_on_weekday( $owner_type, $owner_id, $weekday, $date );
		$windows = array();

		foreach ( $rules as $rule ) {
			$rule_timezone = $this->timezone( $rule->timezone ? $rule->timezone : $output_timezone->getName() );
			$start         = new DateTimeImmutable( $date . ' ' . $rule->start_time, $rule_timezone );
			$end           = new DateTimeImmutable( $date . ' ' . $rule->end_time, $rule_timezone );

			$windows[] = array(
				'start'    => $start->setTimezone( $output_timezone ),
				'end'      => $end->setTimezone( $output_timezone ),
				'interval' => $rule->slot_interval_minutes ? absint( $rule->slot_interval_minutes ) : $default_interval,
			);
		}

		return $this->sort_windows( $windows );
	}

	/** Resolve rules from request preload when available. */
	private function rules_for_owner_on_weekday( $owner_type, $owner_id, $weekday, $date ) {
		if ( ! $this->preloaded ) {
			return $this->rules->for_owner_on_weekday( $owner_type, $owner_id, $weekday, $date );
		}
		return array_values(
			array_filter(
				$this->preloaded['rules'],
				static function ( $rule ) use ( $owner_type, $owner_id, $weekday, $date ) {
					return $rule->owner_type === $owner_type && (int) $rule->owner_id === (int) $owner_id && (int) $rule->weekday === (int) $weekday
						&& ( empty( $rule->valid_from ) || $rule->valid_from <= $date ) && ( empty( $rule->valid_to ) || $rule->valid_to >= $date );
				}
			)
		);
	}

	/** Check staff overrides without issuing another query. */
	private function owner_has_rules_for_weekday( $owner_type, $owner_id, $weekday, $date ) {
		return ! empty( $this->rules_for_owner_on_weekday( $owner_type, $owner_id, $weekday, $date ) );
	}

	/** Resolve exceptions from request preload when available. */
	private function exceptions_for_owner_on_date( $owner_type, $owner_id, $date ) {
		if ( ! $this->preloaded ) {
			return $this->exceptions->for_owner_on_date( $owner_type, $owner_id, $date );
		}
		return array_values(
			array_filter(
				$this->preloaded['exceptions'],
				static function ( $exception ) use ( $owner_type, $owner_id, $date ) {
					return $exception->owner_type === $owner_type && (int) $exception->owner_id === (int) $owner_id && $exception->exception_date === $date;
				}
			)
		);
	}

	/**
	 * Apply exceptions to availability windows.
	 *
	 * @param array        $windows Windows.
	 * @param array        $exceptions Exceptions.
	 * @param string       $date Date in Y-m-d format.
	 * @param DateTimeZone $output_timezone Output timezone.
	 * @param int          $default_interval Default slot interval.
	 * @return array
	 */
	private function apply_exceptions( array $windows, array $exceptions, $date, DateTimeZone $output_timezone, $default_interval ) {
		foreach ( $exceptions as $exception ) {
			$exception_timezone = $this->timezone( $exception->timezone ? $exception->timezone : $output_timezone->getName() );

			if ( 'available' === $exception->availability_type ) {
				$windows[] = array(
					'start'    => ( new DateTimeImmutable( $date . ' ' . $exception->start_time, $exception_timezone ) )->setTimezone( $output_timezone ),
					'end'      => ( new DateTimeImmutable( $date . ' ' . $exception->end_time, $exception_timezone ) )->setTimezone( $output_timezone ),
					'interval' => $default_interval,
				);
				continue;
			}

			if ( empty( $exception->start_time ) || empty( $exception->end_time ) ) {
				$windows = array();
				continue;
			}

			$block_start = ( new DateTimeImmutable( $date . ' ' . $exception->start_time, $exception_timezone ) )->setTimezone( $output_timezone );
			$block_end   = ( new DateTimeImmutable( $date . ' ' . $exception->end_time, $exception_timezone ) )->setTimezone( $output_timezone );
			$next        = array();

			foreach ( $windows as $window ) {
				foreach ( $this->subtract_window( $window, $block_start, $block_end ) as $piece ) {
					$next[] = $piece;
				}
			}

			$windows = $next;
		}

		return $this->sort_windows( $windows );
	}

	/**
	 * Subtract one blocked interval from a window.
	 *
	 * @param array             $window Availability window.
	 * @param DateTimeImmutable $block_start Block start.
	 * @param DateTimeImmutable $block_end Block end.
	 * @return array
	 */
	private function subtract_window( array $window, DateTimeImmutable $block_start, DateTimeImmutable $block_end ) {
		if ( $block_end <= $window['start'] || $block_start >= $window['end'] ) {
			return array( $window );
		}

		$pieces = array();

		if ( $block_start > $window['start'] ) {
			$pieces[] = array(
				'start'    => $window['start'],
				'end'      => $block_start < $window['end'] ? $block_start : $window['end'],
				'interval' => $window['interval'],
			);
		}

		if ( $block_end < $window['end'] ) {
			$pieces[] = array(
				'start'    => $block_end > $window['start'] ? $block_end : $window['start'],
				'end'      => $window['end'],
				'interval' => $window['interval'],
			);
		}

		return $pieces;
	}

	/**
	 * Check whether a proposed slot conflicts with an existing appointment.
	 *
	 * @param object            $service Service row.
	 * @param int               $staff_id Staff ID.
	 * @param DateTimeImmutable $start Local start.
	 * @param DateTimeImmutable $end Local end.
	 * @param int               $exclude_appointment_id Appointment ID to ignore.
	 * @return bool
	 */
	private function has_preloaded_conflict( $service, $staff_id, DateTimeImmutable $start, DateTimeImmutable $end, array $conflicts ) {
		$buffer_start       = $start->sub( new DateInterval( 'PT' . absint( $service->buffer_before_minutes ) . 'M' ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		$buffer_end         = $end->add( new DateInterval( 'PT' . absint( $service->buffer_after_minutes ) . 'M' ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		$count = 0;
		foreach ( $conflicts as $occupied ) {
			if ( $staff_id && (int) $occupied['staff_id'] !== (int) $staff_id ) {
				continue;
			}
			if ( ! $staff_id && (int) $occupied['service_id'] !== (int) $service->id ) {
				continue;
			}
			if ( $occupied['start'] < $buffer_end && $occupied['end'] > $buffer_start ) {
				$count++;
				if ( $staff_id || $count >= max( 1, absint( $service->capacity ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Load all occupied intervals needed to evaluate one local date. */
	private function conflicts_for_date( $service, array $staff_ids, DateTimeImmutable $date, DateTimeZone $timezone, $exclude_id ) {
		return $this->conflicts_for_range( $service, $staff_ids, $date, $date, $timezone, $exclude_id );
	}

	/** Load occupied intervals for a complete local date range in one query. */
	private function conflicts_for_range( $service, array $staff_ids, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeZone $timezone, $exclude_id ) {
		global $wpdb;
		$appointments = Migrator::table_name( 'appointments' );
		$services     = Migrator::table_name( 'services' );
		$day_start    = $from->setTime( 0, 0 )->modify( '-1 day' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$day_end      = $to->setTime( 23, 59, 59 )->modify( '+1 day' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$where_scope  = 'a.service_id = %d';
		$params       = array( (int) $service->id );
		$staff_ids    = array_values( array_filter( array_map( 'absint', $staff_ids ) ) );
		if ( $staff_ids ) {
			$where_scope .= ' OR a.staff_id IN (' . implode( ',', array_fill( 0, count( $staff_ids ), '%d' ) ) . ')';
			$params       = array_merge( $params, $staff_ids );
		}
		$params[] = absint( $exclude_id );
		$params[] = $day_end;
		$params[] = $day_start;
		$sql = "SELECT a.staff_id, a.service_id, a.start_at, a.end_at, COALESCE(s.buffer_before_minutes,0) AS before_minutes, COALESCE(s.buffer_after_minutes,0) AS after_minutes
			FROM {$appointments} a LEFT JOIN {$services} s ON s.id = a.service_id
			WHERE ({$where_scope}) AND a.id <> %d AND a.status IN ('pending','confirmed','completed') AND a.start_at < %s AND a.end_at > %s";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		$result = array();
		foreach ( $rows as $row ) {
			$start = new DateTimeImmutable( $row->start_at, new DateTimeZone( 'UTC' ) );
			$end   = new DateTimeImmutable( $row->end_at, new DateTimeZone( 'UTC' ) );
			$result[] = array(
				'staff_id' => (int) $row->staff_id,
				'service_id' => (int) $row->service_id,
				'start' => $start->sub( new DateInterval( 'PT' . absint( $row->before_minutes ) . 'M' ) ),
				'end' => $end->add( new DateInterval( 'PT' . absint( $row->after_minutes ) . 'M' ) ),
			);
		}
		return $result;
	}

	/**
	 * Resolve candidate staff IDs for a service.
	 *
	 * @param int $service_id Service ID.
	 * @param int $staff_id Requested staff ID.
	 * @return array|WP_Error
	 */
	private function candidate_staff_ids( $service_id, $staff_id ) {
		if ( $staff_id ) {
			$staff = $this->staff->find( $staff_id );

			if ( ! $staff || 'active' !== $staff->status ) {
				return new WP_Error( 'yo_booking_staff_unavailable', __( 'The selected staff member is unavailable.', 'yo-booking' ) );
			}

			$assigned_staff_ids = $this->staff_services->staff_ids_for_service( $service_id );

			if ( ! empty( $assigned_staff_ids ) && ! in_array( $staff_id, $assigned_staff_ids, true ) ) {
				return new WP_Error( 'yo_booking_staff_not_assigned', __( 'The selected staff member is not assigned to this service.', 'yo-booking' ) );
			}

			return array( $staff_id );
		}

		$staff_ids = $this->staff_services->staff_ids_for_service( $service_id );

		return ! empty( $staff_ids ) ? $staff_ids : array( 0 );
	}

	/**
	 * Calculate and cap the date range.
	 *
	 * @param array        $args Query args.
	 * @param DateTimeZone $timezone Timezone.
	 * @return array|WP_Error
	 */
	private function date_range( array $args, DateTimeZone $timezone ) {
		$settings            = $this->settings->all();
		$booking_window_days = max( 1, absint( $settings['booking']['booking_window_days'] ) );
		$today               = new DateTimeImmutable( 'today', $timezone );
		$max                 = $today->add( new DateInterval( 'P' . $booking_window_days . 'D' ) );
		$from                = ! empty( $args['from'] ) ? $this->date( $args['from'], $timezone ) : $today;
		$to                  = ! empty( $args['to'] ) ? $this->date( $args['to'], $timezone ) : $max;

		if ( is_wp_error( $from ) ) {
			return $from;
		}

		if ( is_wp_error( $to ) ) {
			return $to;
		}

		if ( $from < $today ) {
			$from = $today;
		}

		if ( $to > $max ) {
			$to = $max;
		}

		if ( $to < $from ) {
			return new WP_Error( 'yo_booking_date_range_invalid', __( 'The requested date range is invalid.', 'yo-booking' ) );
		}

		return array(
			'from' => $from,
			'to'   => $to,
		);
	}

	/**
	 * Normalize a date.
	 *
	 * @param string            $date Date string.
	 * @param DateTimeZone|null $timezone Timezone.
	 * @return DateTimeImmutable|WP_Error
	 */
	private function date( $date, ?DateTimeZone $timezone = null ) {
		$date = sanitize_text_field( $date );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'yo_booking_date_invalid', __( 'The requested date is invalid.', 'yo-booking' ) );
		}

		return new DateTimeImmutable( $date . ' 00:00:00', $timezone ? $timezone : $this->timezone( '' ) );
	}

	/**
	 * Resolve a timezone.
	 *
	 * @param string $timezone Timezone name.
	 * @return DateTimeZone
	 */
	private function timezone( $timezone ) {
		$settings = $this->settings->all();
		$name     = $settings['company']['timezone'];

		try {
			return new DateTimeZone( $name ? $name : 'UTC' );
		} catch ( Exception $exception ) {
			return new DateTimeZone( 'UTC' );
		}
	}

	/**
	 * Sort windows by start time.
	 *
	 * @param array $windows Windows.
	 * @return array
	 */
	private function sort_windows( array $windows ) {
		usort(
			$windows,
			static function ( $a, $b ) {
				return $a['start']->getTimestamp() <=> $b['start']->getTimestamp();
			}
		);

		return $windows;
	}
}
