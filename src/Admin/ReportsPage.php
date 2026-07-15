<?php
/**
 * Operational reports admin page.
 *
 * @package YoBooking
 */

namespace YoBooking\Admin;

use DateTimeImmutable;
use DateTimeZone;
use YoBooking\Payments\Currency;
use YoBooking\Repositories\ReportRepository;
use YoBooking\Settings\Repository as SettingsRepository;
use YoBooking\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Renders booking and revenue reporting.
 */
final class ReportsPage extends AbstractAdminPage {
	/** @return void */
	public function boot() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/** @return void */
	public function register_menu() {
		add_submenu_page( 'yo-booking', __( 'Reports', 'yo-booking' ), __( 'Reports', 'yo-booking' ), Capabilities::reports(), 'yo-booking-reports', array( $this, 'render' ) );
	}

	/** @return void */
	public function render() {
		$this->ensure_capability( Capabilities::reports() );
		$default_from = wp_date( 'Y-m-01' );
		$default_to   = wp_date( 'Y-m-d' );
		$date_from    = isset( $_GET['date_from'] ) ? $this->valid_date( sanitize_text_field( wp_unslash( $_GET['date_from'] ) ), $default_from ) : $default_from; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_to      = isset( $_GET['date_to'] ) ? $this->valid_date( sanitize_text_field( wp_unslash( $_GET['date_to'] ) ), $default_to ) : $default_to; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$timezone     = $this->timezone();
		$from_utc     = ( new DateTimeImmutable( $date_from . ' 00:00:00', $timezone ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$to_utc       = ( new DateTimeImmutable( $date_to . ' 23:59:59', $timezone ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$repository   = new ReportRepository();
		$currency     = Currency::normalize( ( new SettingsRepository() )->get( 'company.currency', 'USD' ) );
		$summary      = $repository->summary( $from_utc, $to_utc );
		$daily        = $repository->daily( $from_utc, $to_utc );
		$services     = $repository->top_services( $from_utc, $to_utc );
		$staff        = $repository->top_staff( $from_utc, $to_utc );
		$financials   = $repository->financial_by_currency( $from_utc, $to_utc );
		$export_url   = wp_nonce_url( add_query_arg( array( 'action' => 'yo_booking_export_report', 'date_from' => $date_from, 'date_to' => $date_to ), admin_url( 'admin-post.php' ) ), 'yo_booking_export_report' );
		$cancel_rate  = $summary->total_bookings ? ( (int) $summary->cancelled / (int) $summary->total_bookings ) * 100 : 0;
		?>
		<div class="wrap yo-booking-admin">
			<?php $this->render_page_header( __( 'Reports', 'yo-booking' ), __( 'Track booking volume, completion, cancellations, and collected revenue.', 'yo-booking' ), current_user_can( Capabilities::export() ) ? '<a class="button" href="' . esc_url( $export_url ) . '"><span class="fi fi-rr-download" aria-hidden="true"></span>' . esc_html__( 'Export CSV', 'yo-booking' ) . '</a>' : '' ); ?>
			<form method="get" class="yo-toolbar"><input type="hidden" name="page" value="yo-booking-reports" /><label><?php echo esc_html__( 'From', 'yo-booking' ); ?><input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" /></label><label><?php echo esc_html__( 'To', 'yo-booking' ); ?><input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" /></label><button class="button button-primary" type="submit"><?php echo esc_html__( 'Update report', 'yo-booking' ); ?></button></form>
			<div class="yo-grid yo-grid--stats">
				<?php $this->metric( __( 'Bookings', 'yo-booking' ), (int) $summary->total_bookings ); ?>
				<?php $this->metric( __( 'Completed', 'yo-booking' ), (int) $summary->completed ); ?>
				<?php $this->metric( __( 'Paid revenue', 'yo-booking' ), Currency::format( $summary->paid_revenue, $currency ) ); ?>
				<?php $this->metric( __( 'Cancellation rate', 'yo-booking' ), number_format_i18n( $cancel_rate, 1 ) . '%' ); ?>
			</div>
			<div class="yo-grid yo-grid--2 yo-section">
				<section class="yo-card"><h2><?php echo esc_html__( 'Daily performance', 'yo-booking' ); ?></h2><?php $this->daily_table( $daily, $currency ); ?></section>
				<section class="yo-card"><h2><?php echo esc_html__( 'Payment position', 'yo-booking' ); ?></h2><p class="description"><?php echo esc_html__( 'Financial totals are separated by currency and are never added together.', 'yo-booking' ); ?></p><div class="yo-report-table"><table class="widefat striped"><thead><tr><th><?php echo esc_html__( 'Currency', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Paid revenue', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Outstanding value', 'yo-booking' ); ?></th></tr></thead><tbody><?php foreach ( $financials as $financial ) : ?><tr><td><?php echo esc_html( $financial->currency ); ?></td><td><?php echo esc_html( Currency::format( $financial->paid_revenue, $financial->currency ) ); ?></td><td><?php echo esc_html( Currency::format( $financial->outstanding, $financial->currency ) ); ?></td></tr><?php endforeach; ?><?php if ( ! $financials ) : ?><tr><td colspan="3"><?php echo esc_html__( 'No data.', 'yo-booking' ); ?></td></tr><?php endif; ?></tbody></table></div></section>
			</div>
			<div class="yo-grid yo-grid--2 yo-section"><section class="yo-card"><h2><?php echo esc_html__( 'Top services', 'yo-booking' ); ?></h2><?php $this->ranking_table( $services, $currency ); ?></section><section class="yo-card"><h2><?php echo esc_html__( 'Top staff', 'yo-booking' ); ?></h2><?php $this->ranking_table( $staff, $currency ); ?></section></div>
		</div>
		<?php
	}

	/** @param string $label Label. @param mixed $value Value. @return void */
	private function metric( $label, $value ) {
		?><div class="yo-card yo-stat"><span class="yo-stat__label"><?php echo esc_html( $label ); ?></span><strong class="yo-stat__value"><?php echo esc_html( (string) $value ); ?></strong></div><?php
	}

	/** @param array $rows Daily rows. @return void */
	private function daily_table( array $rows, $currency ) {
		?><div class="yo-report-table"><table class="widefat striped"><thead><tr><th><?php echo esc_html__( 'Date (UTC)', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Bookings', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Revenue', 'yo-booking' ); ?></th></tr></thead><tbody><?php if ( ! $rows ) : ?><tr><td colspan="3"><?php echo esc_html__( 'No activity in this period.', 'yo-booking' ); ?></td></tr><?php endif; ?><?php foreach ( $rows as $row ) : ?><tr><td><?php echo esc_html( $row->report_date ); ?></td><td><?php echo esc_html( (string) $row->bookings ); ?></td><td><?php echo esc_html( Currency::format( $row->revenue, $currency ) ); ?></td></tr><?php endforeach; ?></tbody></table></div><?php
	}

	/** @param array $rows Ranking rows. @return void */
	private function ranking_table( array $rows, $currency ) {
		?><table class="widefat striped"><thead><tr><th><?php echo esc_html__( 'Name', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Bookings', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Revenue', 'yo-booking' ); ?></th></tr></thead><tbody><?php if ( ! $rows ) : ?><tr><td colspan="3"><?php echo esc_html__( 'No data.', 'yo-booking' ); ?></td></tr><?php endif; ?><?php foreach ( $rows as $row ) : ?><tr><td><?php echo esc_html( $row->name ); ?></td><td><?php echo esc_html( (string) $row->bookings ); ?></td><td><?php echo esc_html( Currency::format( $row->revenue, $currency ) ); ?></td></tr><?php endforeach; ?></tbody></table><?php
	}

	/** @param string $date Raw date. @param string $fallback Fallback date. @return string */
	private function valid_date( $date, $fallback ) {
		$date = sanitize_text_field( $date );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : $fallback;
	}

	/** @return DateTimeZone */
	private function timezone() {
		try {
			return new DateTimeZone( ( new SettingsRepository() )->get( 'company.timezone', wp_timezone_string() ) );
		} catch ( \Exception $exception ) {
			return wp_timezone();
		}
	}
}
