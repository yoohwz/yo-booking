<?php
/**
 * Booking operations dashboard.
 *
 * @package YoBooking
 */

namespace YoBooking\Admin;

use DateTimeImmutable;
use DateTimeZone;
use YoBooking\Payments\Currency;
use YoBooking\Repositories\AppointmentRepository;
use YoBooking\Repositories\AvailabilityRuleRepository;
use YoBooking\Repositories\DashboardRepository;
use YoBooking\Repositories\PaymentRepository;
use YoBooking\Repositories\ServiceRepository;
use YoBooking\Repositories\StaffRepository;
use YoBooking\Settings\Repository as SettingsRepository;
use YoBooking\Support\Capabilities;
use YoBooking\Support\DateTimeFormatter;
use YoBooking\Support\StaffAccess;

defined( 'ABSPATH' ) || exit;

/**
 * Renders an actionable overview of booking activity.
 */
final class DashboardPage extends AbstractAdminPage {
	/**
	 * Render dashboard.
	 *
	 * @return void
	 */
	public function render() {
		$this->ensure_capability( Capabilities::appointments() );

		$timezone          = wp_timezone();
		$utc               = new DateTimeZone( 'UTC' );
		$today_local       = new DateTimeImmutable( 'today', $timezone );
		$today_end_local   = $today_local->modify( '+1 day' );
		$week_end_local    = $today_local->modify( '+7 days' );
		$month_end_local   = $today_local->modify( '+30 days' );
		$history_local     = $today_local->modify( '-30 days' );
		$today_from        = $today_local->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$today_to          = $today_end_local->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$week_to           = $week_end_local->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$month_to          = $month_end_local->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$history_from      = $history_local->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$settings          = new SettingsRepository();
		$currency          = Currency::normalize( $settings->get( 'company.currency', 'USD' ) );
		$currency          = $currency ? $currency : 'USD';
		$dashboard         = new DashboardRepository();
		$summary           = $dashboard->summary( $today_from, $today_to, $week_to, $month_to, $history_from, $currency );
		$today_schedule    = ( new AppointmentRepository() )->all(
			array(
				'from'  => $today_from,
				'to'    => $today_end_local->modify( '-1 second' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'limit' => 10,
			)
		);
		$day_buckets       = $this->day_buckets( $today_local, $utc );
		$day_counts        = $dashboard->upcoming_days( $day_buckets );
		$top_services      = $dashboard->top_services( $today_from, $month_to, $currency, 5 );
		$failed_emails     = $dashboard->failed_notifications( $today_local->modify( '-7 days' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
		$can_manage        = current_user_can( Capabilities::manage() ) && ! StaffAccess::restricted();
		$setup_items       = $can_manage ? $this->incomplete_setup_items() : array();
		$today_machine     = $today_local->format( 'Y-m-d' );
		$week_last_machine = $week_end_local->modify( '-1 day' )->format( 'Y-m-d' );
		$month_last        = $month_end_local->modify( '-1 day' )->format( 'Y-m-d' );
		$history_machine   = $history_local->format( 'Y-m-d' );
		$today_url         = $this->appointments_url(
			array(
				'date_from' => $today_machine,
				'date_to'   => $today_machine,
			)
		);
		$week_url          = $this->appointments_url(
			array(
				'date_from' => $today_machine,
				'date_to'   => $week_last_machine,
			)
		);
		$month_url         = $this->appointments_url(
			array(
				'date_from' => $today_machine,
				'date_to'   => $month_last,
			)
		);
		$history_url       = current_user_can( Capabilities::reports() )
			? add_query_arg(
				array(
					'page'      => 'yo-booking-reports',
					'date_from' => $history_machine,
					'date_to'   => $today_machine,
				),
				admin_url( 'admin.php' )
			)
			: $this->appointments_url(
				array(
					'date_from' => $history_machine,
					'date_to'   => $today_machine,
				)
			);
		$services_url      = $can_manage ? admin_url( 'admin.php?page=yo-booking-services' ) : $month_url;
		$services_link     = $can_manage ? __( 'Services', 'yo-booking' ) : __( 'View bookings', 'yo-booking' );
		/* translators: %d: number of pending appointments. */
		$today_pending_meta = sprintf( _n( '%d appointment awaiting confirmation', '%d appointments awaiting confirmation', (int) $summary->today_pending, 'yo-booking' ), (int) $summary->today_pending );
		/* translators: %d: number of completed appointments. */
		$completed_meta = sprintf( _n( '%d completed appointment', '%d completed appointments', (int) $summary->history_completed, 'yo-booking' ), (int) $summary->history_completed );
		/* translators: %d: number of appointments needing payment. */
		$payment_meta = sprintf( _n( '%d booking needs payment', '%d bookings need payment', (int) $summary->payment_followup, 'yo-booking' ), (int) $summary->payment_followup );
		/* translators: %d: number of appointments. */
		$today_count_meta = sprintf( _n( '%d appointment', '%d appointments', (int) $summary->today_total, 'yo-booking' ), (int) $summary->today_total );
		/* translators: %d: number of incomplete setup steps. */
		$setup_meta = sprintf( _n( '%d step remaining before accepting bookings.', '%d steps remaining before accepting bookings.', count( $setup_items ), 'yo-booking' ), count( $setup_items ) );
		$actions    = '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=yo-booking-appointments&view=calendar' ) ) . '"><span class="fi fi-rr-calendar-days" aria-hidden="true"></span>' . esc_html__( 'Open calendar', 'yo-booking' ) . '</a>';

		if ( $can_manage ) {
			$actions .= '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=yo-booking-appointments&action=new' ) ) . '"><span class="fi fi-rr-plus" aria-hidden="true"></span>' . esc_html__( 'Add appointment', 'yo-booking' ) . '</a>';
		}

		?>
		<div class="wrap yo-booking-admin yo-dashboard">
			<?php
			$this->render_page_header(
				__( 'Booking overview', 'yo-booking' ),
				sprintf(
					/* translators: 1: local date, 2: timezone name. */
					__( '%1$s · %2$s timezone', 'yo-booking' ),
					DateTimeFormatter::timestamp( time(), 'date' ),
					DateTimeFormatter::timezone_name()
				),
				$actions
			);
			?>

			<div class="yo-grid yo-grid--stats yo-dashboard-stats">
				<?php
				$this->render_stat(
					__( 'Appointments today', 'yo-booking' ),
					(int) $summary->today_total,
					$today_pending_meta,
					'fi-rr-calendar-day',
					$today_url
				);
				$this->render_stat(
					__( 'Next 7 days', 'yo-booking' ),
					(int) $summary->next_7_total,
					__( 'Active appointments on the schedule', 'yo-booking' ),
					'fi-rr-calendar-clock',
					$week_url
				);
				$this->render_stat(
					__( 'Paid revenue · 30 days', 'yo-booking' ),
					Currency::format( $summary->paid_revenue, $currency ),
					$completed_meta,
					'fi-rr-sack-dollar',
					$history_url
				);
				$this->render_stat(
					__( 'Outstanding · next 30 days', 'yo-booking' ),
					Currency::format( $summary->outstanding, $currency ),
					$payment_meta,
					'fi-rr-wallet',
					$month_url
				);
				?>
			</div>

			<section class="yo-dashboard-queue yo-section" aria-labelledby="yo-dashboard-queue-title">
				<div class="yo-section-header">
					<div><h2 id="yo-dashboard-queue-title"><?php echo esc_html__( 'Needs attention', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Items that may need an operational follow-up.', 'yo-booking' ); ?></p></div>
				</div>
				<div class="yo-dashboard-queue__grid">
					<?php
					$this->render_queue_item(
						(int) $summary->upcoming_pending,
						__( 'Pending confirmations', 'yo-booking' ),
						__( 'Next 30 days', 'yo-booking' ),
						'fi-rr-time-check',
						$this->appointments_url(
							array(
								'status'    => 'pending',
								'date_from' => $today_machine,
								'date_to'   => $month_last,
							)
						)
					);
					$this->render_queue_item( (int) $summary->payment_followup, __( 'Payment follow-up', 'yo-booking' ), __( 'Unpaid or partially paid', 'yo-booking' ), 'fi-rr-receipt', $month_url );
					$this->render_queue_item(
						(int) $summary->history_no_show,
						__( 'No-shows', 'yo-booking' ),
						__( 'Previous 30 days', 'yo-booking' ),
						'fi-rr-user-slash',
						$this->appointments_url(
							array(
								'status'    => 'no_show',
								'date_from' => $history_machine,
								'date_to'   => $today_machine,
							)
						)
					);
					if ( $can_manage ) {
						$this->render_queue_item(
							$failed_emails,
							__( 'Failed emails', 'yo-booking' ),
							__( 'Previous 7 days', 'yo-booking' ),
							'fi-rr-envelope-ban',
							add_query_arg(
								array(
									'page'             => 'yo-booking-notifications',
									'notification_tab' => 'logs',
								),
								admin_url( 'admin.php' )
							)
						);
					} else {
						$this->render_queue_item(
							(int) $summary->history_completed,
							__( 'Completed', 'yo-booking' ),
							__( 'Previous 30 days', 'yo-booking' ),
							'fi-rr-check-circle',
							$this->appointments_url(
								array(
									'status'    => 'completed',
									'date_from' => $history_machine,
									'date_to'   => $today_machine,
								)
							)
						);
					}
					?>
				</div>
			</section>

			<div class="yo-dashboard-main yo-section">
				<section class="yo-card yo-dashboard-schedule">
					<div class="yo-section-header">
						<div><h2><?php echo esc_html__( "Today's schedule", 'yo-booking' ); ?></h2><p><?php echo esc_html( $today_count_meta ); ?></p></div>
						<a href="<?php echo esc_url( $today_url ); ?>"><?php echo esc_html__( 'View day', 'yo-booking' ); ?></a>
					</div>
					<?php $this->render_schedule( $today_schedule ); ?>
				</section>

				<section class="yo-card yo-dashboard-activity">
					<div class="yo-section-header">
						<div><h2><?php echo esc_html__( 'Booking activity', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Active appointments over the next 7 days.', 'yo-booking' ); ?></p></div>
						<strong><?php echo esc_html( (string) (int) $summary->next_7_total ); ?></strong>
					</div>
					<?php $this->render_activity_chart( $day_buckets, $day_counts ); ?>
				</section>
			</div>

			<div class="yo-grid yo-grid--2 yo-section yo-dashboard-insights">
				<section class="yo-card">
					<div class="yo-section-header"><div><h2><?php echo esc_html__( 'Booking outcomes', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Status distribution for the previous 30 days.', 'yo-booking' ); ?></p></div><a href="<?php echo esc_url( $history_url ); ?>"><?php echo esc_html__( 'Open report', 'yo-booking' ); ?></a></div>
					<?php $this->render_status_mix( $summary ); ?>
				</section>

				<section class="yo-card">
					<div class="yo-section-header"><div><h2><?php echo esc_html__( 'Top services', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Most booked services over the next 30 days.', 'yo-booking' ); ?></p></div><a href="<?php echo esc_url( $services_url ); ?>"><?php echo esc_html( $services_link ); ?></a></div>
					<?php $this->render_top_services( $top_services, $currency ); ?>
				</section>
			</div>

			<?php if ( $setup_items ) : ?>
				<section class="yo-card yo-dashboard-setup yo-section">
					<div class="yo-section-header"><div><h2><?php echo esc_html__( 'Finish setup', 'yo-booking' ); ?></h2><p><?php echo esc_html( $setup_meta ); ?></p></div><a href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-settings' ) ); ?>"><?php echo esc_html__( 'Settings', 'yo-booking' ); ?></a></div>
					<ul class="yo-checklist">
						<?php foreach ( $setup_items as $item ) : ?>
							<?php $this->render_setup_item( $item['label'], $item['page'] ); ?>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Build seven local calendar-day buckets using UTC query boundaries.
	 *
	 * @param DateTimeImmutable $today Local today boundary.
	 * @param DateTimeZone      $utc UTC timezone.
	 * @return array
	 */
	private function day_buckets( DateTimeImmutable $today, DateTimeZone $utc ) {
		$buckets = array();

		for ( $index = 0; $index < 7; ++$index ) {
			$start     = $today->modify( '+' . $index . ' days' );
			$buckets[] = array(
				'from'       => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'to'         => $start->modify( '+1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'day_label'  => wp_date( 'D', $start->getTimestamp(), $start->getTimezone() ),
				'date_label' => wp_date( 'M j', $start->getTimestamp(), $start->getTimezone() ),
			);
		}

		return $buckets;
	}

	/**
	 * Build an appointment list URL.
	 *
	 * @param array $args Appointment filters.
	 * @return string
	 */
	private function appointments_url( array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => 'yo-booking-appointments',
					'view' => 'list',
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Return setup tasks that still need attention.
	 *
	 * @return array
	 */
	private function incomplete_setup_items() {
		$items = array();

		if ( ( new ServiceRepository() )->count() < 1 ) {
			$items[] = array(
				'label' => __( 'Create at least one service', 'yo-booking' ),
				'page'  => 'yo-booking-services',
			);
		}
		if ( count( ( new StaffRepository() )->all() ) < 1 ) {
			$items[] = array(
				'label' => __( 'Add a staff member', 'yo-booking' ),
				'page'  => 'yo-booking-staff',
			);
		}
		$active_rules = array_filter(
			( new AvailabilityRuleRepository() )->all(),
			static function ( $rule ) {
				return 'active' === $rule->status;
			}
		);
		if ( empty( $active_rules ) ) {
			$items[] = array(
				'label' => __( 'Configure business availability', 'yo-booking' ),
				'page'  => 'yo-booking-availability',
			);
		}

		return $items;
	}

	/**
	 * Render one linked headline metric.
	 *
	 * @param string     $label Metric label.
	 * @param int|string $value Metric value.
	 * @param string     $meta Supporting text.
	 * @param string     $icon Icon class.
	 * @param string     $url Target URL.
	 * @return void
	 */
	private function render_stat( $label, $value, $meta, $icon, $url ) {
		?>
		<a class="yo-card yo-stat yo-dashboard-stat" href="<?php echo esc_url( $url ); ?>">
			<span class="yo-dashboard-stat__top"><span class="yo-stat__label"><?php echo esc_html( $label ); ?></span><span class="fi <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span></span>
			<strong class="yo-stat__value"><?php echo esc_html( (string) $value ); ?></strong>
			<span class="yo-stat__meta"><?php echo esc_html( $meta ); ?></span>
		</a>
		<?php
	}

	/**
	 * Render one operational queue item.
	 *
	 * @param int    $count Item count.
	 * @param string $label Item label.
	 * @param string $meta Supporting text.
	 * @param string $icon Icon class.
	 * @param string $url Target URL.
	 * @return void
	 */
	private function render_queue_item( $count, $label, $meta, $icon, $url ) {
		$class = $count > 0 ? 'has-items' : 'is-clear';
		?>
		<a class="yo-dashboard-queue__item <?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $url ); ?>">
			<span class="yo-dashboard-queue__icon fi <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<span class="yo-dashboard-queue__copy"><strong><?php echo esc_html( $label ); ?></strong><span><?php echo esc_html( $meta ); ?></span></span>
			<span class="yo-dashboard-queue__count"><?php echo esc_html( (string) $count ); ?></span>
		</a>
		<?php
	}

	/**
	 * Render today's appointment list.
	 *
	 * @param array $appointments Appointment rows.
	 * @return void
	 */
	private function render_schedule( array $appointments ) {
		if ( empty( $appointments ) ) {
			?>
			<div class="yo-empty"><span class="fi fi-rr-calendar-days" aria-hidden="true"></span><h3><?php echo esc_html__( 'No appointments today', 'yo-booking' ); ?></h3><p><?php echo esc_html__( 'The schedule is clear for the rest of the day.', 'yo-booking' ); ?></p></div>
			<?php
			return;
		}

		$statuses         = AppointmentRepository::statuses();
		$payment_statuses = PaymentRepository::appointment_statuses();
		?>
		<ul class="yo-dashboard-schedule__list">
			<?php foreach ( $appointments as $appointment ) : ?>
				<?php
				$customer = $appointment->customer_name ? $appointment->customer_name : __( 'Guest customer', 'yo-booking' );
				$service  = $appointment->service_name ? $appointment->service_name : __( 'Service unavailable', 'yo-booking' );
				$color    = sanitize_hex_color( $appointment->service_color );
				/* translators: %s: customer name. */
				$open_label = sprintf( __( 'Open appointment for %s', 'yo-booking' ), $customer );
				?>
				<li<?php echo $color ? ' style="--yo-service-color:' . esc_attr( $color ) . '"' : ''; ?>>
					<time datetime="<?php echo esc_attr( mysql2date( 'c', $appointment->start_at, false ) ); ?>"><?php echo esc_html( DateTimeFormatter::utc( $appointment->start_at, 'time' ) ); ?></time>
					<span class="yo-dashboard-schedule__booking"><strong><?php echo esc_html( $customer ); ?></strong><span><?php echo esc_html( $service ); ?></span></span>
					<span class="yo-dashboard-schedule__meta"><span><?php echo esc_html( $appointment->staff_name ? $appointment->staff_name : __( 'Unassigned', 'yo-booking' ) ); ?></span><span><?php echo esc_html( isset( $payment_statuses[ $appointment->payment_status ] ) ? $payment_statuses[ $appointment->payment_status ] : $appointment->payment_status ); ?></span></span>
					<span class="yo-dashboard-schedule__status"><?php $this->render_status_badge( $appointment->status, isset( $statuses[ $appointment->status ] ) ? $statuses[ $appointment->status ] : '' ); ?></span>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=yo-booking-appointments&edit=' . absint( $appointment->id ) ) ); ?>" aria-label="<?php echo esc_attr( $open_label ); ?>"><?php echo esc_html__( 'Open', 'yo-booking' ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Render the seven-day appointment volume chart.
	 *
	 * @param array $buckets Date buckets.
	 * @param array $counts Booking counts.
	 * @return void
	 */
	private function render_activity_chart( array $buckets, array $counts ) {
		$max = max( array_merge( array( 1 ), array_map( 'absint', $counts ) ) );
		?>
		<div class="yo-dashboard-chart" role="img" aria-label="<?php echo esc_attr__( 'Appointment volume for the next seven days', 'yo-booking' ); ?>">
			<?php foreach ( $buckets as $index => $bucket ) : ?>
				<?php $count = isset( $counts[ $index ] ) ? absint( $counts[ $index ] ) : 0; ?>
				<div class="yo-dashboard-chart__column">
					<strong><?php echo esc_html( (string) $count ); ?></strong>
					<span class="yo-dashboard-chart__track"><span class="yo-dashboard-chart__bar" style="height: <?php echo esc_attr( (string) round( ( $count / $max ) * 100 ) ); ?>%"></span></span>
					<span class="yo-dashboard-chart__day"><?php echo esc_html( $bucket['day_label'] ); ?></span>
					<span class="yo-dashboard-chart__date"><?php echo esc_html( $bucket['date_label'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render historical appointment status distribution.
	 *
	 * @param object $summary Dashboard summary.
	 * @return void
	 */
	private function render_status_mix( $summary ) {
		$statuses = array(
			'completed' => array(
				'label' => __( 'Completed', 'yo-booking' ),
				'count' => (int) $summary->history_completed,
			),
			'confirmed' => array(
				'label' => __( 'Confirmed', 'yo-booking' ),
				'count' => (int) $summary->history_confirmed,
			),
			'pending'   => array(
				'label' => __( 'Pending', 'yo-booking' ),
				'count' => (int) $summary->history_pending,
			),
			'cancelled' => array(
				'label' => __( 'Cancelled', 'yo-booking' ),
				'count' => (int) $summary->history_cancelled,
			),
			'no-show'   => array(
				'label' => __( 'No-show', 'yo-booking' ),
				'count' => (int) $summary->history_no_show,
			),
		);
		$total    = max( 1, (int) $summary->history_total );
		?>
		<div class="yo-dashboard-mix">
			<?php foreach ( $statuses as $status => $item ) : ?>
				<div class="yo-dashboard-mix__row">
					<span class="yo-dashboard-mix__label"><i class="yo-dashboard-mix__dot yo-dashboard-mix__dot--<?php echo esc_attr( $status ); ?>"></i><?php echo esc_html( $item['label'] ); ?></span>
					<span class="yo-dashboard-mix__track"><span class="yo-dashboard-mix__fill yo-dashboard-mix__fill--<?php echo esc_attr( $status ); ?>" style="width: <?php echo esc_attr( (string) round( ( $item['count'] / $total ) * 100 ) ); ?>%"></span></span>
					<strong><?php echo esc_html( (string) $item['count'] ); ?></strong>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render future service demand ranking.
	 *
	 * @param array  $services Service metrics.
	 * @param string $currency Currency code.
	 * @return void
	 */
	private function render_top_services( array $services, $currency ) {
		if ( empty( $services ) ) {
			?>
			<div class="yo-empty"><span class="fi fi-rr-calendar-days" aria-hidden="true"></span><h3><?php echo esc_html__( 'No upcoming service data', 'yo-booking' ); ?></h3><p><?php echo esc_html__( 'Service demand will appear after appointments are booked.', 'yo-booking' ); ?></p></div>
			<?php
			return;
		}

		$max = max(
			array_map(
				static function ( $service ) {
					return (int) $service->bookings;
				},
				$services
			)
		);
		?>
		<ol class="yo-dashboard-ranking">
			<?php foreach ( $services as $index => $service ) : ?>
				<li>
					<span class="yo-dashboard-ranking__index"><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>
					<span class="yo-dashboard-ranking__service"><strong><?php echo esc_html( $service->name ); ?></strong><span class="yo-dashboard-ranking__track"><span style="width: <?php echo esc_attr( (string) round( ( (int) $service->bookings / max( 1, $max ) ) * 100 ) ); ?>%"></span></span></span>
					<span class="yo-dashboard-ranking__value"><strong><?php echo esc_html( (string) (int) $service->bookings ); ?></strong><span><?php echo esc_html( Currency::format( $service->revenue, $currency ) ); ?></span></span>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * Render an incomplete setup task.
	 *
	 * @param string $label Item label.
	 * @param string $page Target page.
	 * @return void
	 */
	private function render_setup_item( $label, $page ) {
		?>
		<li><span class="fi fi-rr-triangle-warning" aria-hidden="true"></span><span><?php echo esc_html( $label ); ?></span><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page ) ); ?>"><?php echo esc_html__( 'Configure', 'yo-booking' ); ?></a></li>
		<?php
	}
}
