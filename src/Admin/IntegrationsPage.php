<?php
/**
 * Integrations admin page.
 *
 * @package YoBooking
 */

namespace YoBooking\Admin;

use YoBooking\Integrations\Events;
use YoBooking\Integrations\WebhookDispatcher;
use YoBooking\Repositories\ApiKeyRepository;
use YoBooking\Repositories\AuditLogRepository;
use YoBooking\Repositories\WebhookDeliveryRepository;
use YoBooking\Repositories\WebhookEndpointRepository;
use YoBooking\Support\Capabilities;
use YoBooking\Support\DateTimeFormatter;
use YoBooking\Support\SecretVault;

defined( 'ABSPATH' ) || exit;

/**
 * Manages outbound webhooks and server API credentials.
 */
final class IntegrationsPage extends AbstractAdminPage {
	/** @return void */
	public function boot() {
		add_action( 'admin_post_yo_booking_save_webhook', array( $this, 'save_webhook' ) );
		add_action( 'admin_post_yo_booking_delete_webhook', array( $this, 'delete_webhook' ) );
		add_action( 'admin_post_yo_booking_test_webhook', array( $this, 'test_webhook' ) );
		add_action( 'admin_post_yo_booking_retry_webhook', array( $this, 'retry_webhook' ) );
		add_action( 'admin_post_yo_booking_create_api_key', array( $this, 'create_api_key' ) );
		add_action( 'admin_post_yo_booking_revoke_api_key', array( $this, 'revoke_api_key' ) );
	}

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
		$endpoints = new WebhookEndpointRepository();
		$edit_id   = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing   = $edit_id ? $endpoints->find( $edit_id ) : null;
		$is_new    = isset( $_GET['action'] ) && 'new' === sanitize_key( wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active    = isset( $_GET['integration_tab'] ) ? sanitize_key( wp_unslash( $_GET['integration_tab'] ) ) : 'webhooks'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active    = in_array( $active, array( 'webhooks', 'deliveries', 'api-keys' ), true ) ? $active : 'webhooks';
		$reveal    = get_transient( 'yo_booking_integration_reveal_' . get_current_user_id() );
		if ( $reveal ) delete_transient( 'yo_booking_integration_reveal_' . get_current_user_id() );
		?>
		<div class="<?php echo esc_attr( $embedded ? 'yo-settings-view' : 'wrap yo-booking-admin' ); ?>">
			<?php if ( $embedded ) : ?><div class="yo-settings-section-header"><div><h2><?php echo esc_html__( 'Integrations', 'yo-booking' ); ?></h2><p><?php echo esc_html__( 'Connect Yo Booking to external systems with signed webhooks and scoped API keys.', 'yo-booking' ); ?></p></div><a class="button button-primary" href="<?php echo esc_url( $this->settings_url( array( 'integration_tab' => 'webhooks', 'action' => 'new' ) ) ); ?>"><span class="fi fi-rr-plus" aria-hidden="true"></span><?php echo esc_html__( 'Add endpoint', 'yo-booking' ); ?></a></div><?php else : ?>
				<?php $this->render_page_header( __( 'Integrations', 'yo-booking' ), __( 'Connect Yo Booking to external systems with signed webhooks and scoped API keys.', 'yo-booking' ), '<a class="button button-primary" href="' . esc_url( $this->settings_url( array( 'integration_tab' => 'webhooks', 'action' => 'new' ) ) ) . '"><span class="fi fi-rr-plus" aria-hidden="true"></span>' . esc_html__( 'Add endpoint', 'yo-booking' ) . '</a>' ); ?>
			<?php endif; ?>
			<?php $this->render_notice(); ?>
			<?php $revealed_value = is_array( $reveal ) && ! empty( $reveal['value'] ) ? SecretVault::decrypt( $reveal['value'] ) : ''; ?>
			<?php if ( $revealed_value ) : ?><div class="notice notice-warning"><p><strong><?php echo esc_html( $reveal['label'] ); ?></strong> <?php echo esc_html__( 'Copy it now. It will not be shown again.', 'yo-booking' ); ?></p><p><code class="yo-secret-value"><?php echo esc_html( $revealed_value ); ?></code></p></div><?php endif; ?>
			<div class="yo-tabs" data-yo-tabs data-default-tab="<?php echo esc_attr( $active ); ?>" role="tablist"><button type="button" data-yo-tab="webhooks" role="tab"><?php echo esc_html__( 'Webhook endpoints', 'yo-booking' ); ?></button><button type="button" data-yo-tab="deliveries" role="tab"><?php echo esc_html__( 'Delivery logs', 'yo-booking' ); ?></button><button type="button" data-yo-tab="api-keys" role="tab"><?php echo esc_html__( 'API keys', 'yo-booking' ); ?></button></div>
			<section data-yo-panel="webhooks"><?php $this->render_webhooks( $endpoints, $editing, $is_new ); ?></section>
			<section data-yo-panel="deliveries"><?php $this->render_deliveries(); ?></section>
			<section data-yo-panel="api-keys"><?php $this->render_api_keys(); ?></section>
		</div>
		<?php
	}

	/** @param WebhookEndpointRepository $repository Repository. @param object|null $editing Editing row. @param bool $is_new New form. @return void */
	private function render_webhooks( $repository, $editing, $is_new ) {
		$selected = $editing ? json_decode( (string) $editing->events, true ) : array_keys( Events::all() );
		if ( $editing || $is_new ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yo-editor"><input type="hidden" name="action" value="yo_booking_save_webhook" /><input type="hidden" name="id" value="<?php echo esc_attr( $editing ? (string) $editing->id : '0' ); ?>" /><?php wp_nonce_field( 'yo_booking_save_webhook' ); ?><div class="yo-editor__header"><h2><?php echo esc_html( $editing ? __( 'Edit webhook endpoint', 'yo-booking' ) : __( 'Add webhook endpoint', 'yo-booking' ) ); ?></h2><a href="<?php echo esc_url( $this->settings_url() ); ?>"><?php echo esc_html__( 'Close', 'yo-booking' ); ?></a></div><table class="form-table"><tbody>
		<tr><th><label for="yo-webhook-name"><?php echo esc_html__( 'Name', 'yo-booking' ); ?></label></th><td><input class="regular-text" required id="yo-webhook-name" name="name" value="<?php echo esc_attr( $editing ? $editing->name : '' ); ?>" /></td></tr>
		<tr><th><label for="yo-webhook-url"><?php echo esc_html__( 'Endpoint URL', 'yo-booking' ); ?></label></th><td><input class="large-text code" required type="url" id="yo-webhook-url" name="url" placeholder="https://example.com/webhooks/booking" value="<?php echo esc_attr( $editing ? $editing->url : '' ); ?>" /></td></tr>
		<tr><th><?php echo esc_html__( 'Events', 'yo-booking' ); ?></th><td class="yo-checkbox-list"><?php foreach ( Events::all() as $event => $label ) : ?><label><input type="checkbox" name="events[]" value="<?php echo esc_attr( $event ); ?>" <?php checked( in_array( $event, (array) $selected, true ) ); ?> /><?php echo esc_html( $label ); ?></label><?php endforeach; ?></td></tr>
		<tr><th><label for="yo-webhook-timeout"><?php echo esc_html__( 'Timeout', 'yo-booking' ); ?></label></th><td><input type="number" min="3" max="30" id="yo-webhook-timeout" name="timeout_seconds" value="<?php echo esc_attr( $editing ? (string) $editing->timeout_seconds : '10' ); ?>" /> <?php echo esc_html__( 'seconds', 'yo-booking' ); ?></td></tr>
		<tr><th><?php echo esc_html__( 'Status', 'yo-booking' ); ?></th><td><select name="status"><option value="active" <?php selected( $editing ? $editing->status : 'active', 'active' ); ?>><?php echo esc_html__( 'Active', 'yo-booking' ); ?></option><option value="inactive" <?php selected( $editing ? $editing->status : 'active', 'inactive' ); ?>><?php echo esc_html__( 'Inactive', 'yo-booking' ); ?></option></select></td></tr>
		<?php if ( $editing ) : ?><tr><th><?php echo esc_html__( 'Signing secret', 'yo-booking' ); ?></th><td><label><input type="checkbox" name="regenerate_secret" value="1" /><?php echo esc_html__( 'Generate a new secret and invalidate the previous signature.', 'yo-booking' ); ?></label></td></tr><?php endif; ?>
		</tbody></table><div class="yo-editor__footer"><?php submit_button( __( 'Save endpoint', 'yo-booking' ), 'primary', 'submit', false ); ?></div></form>
		<?php endif; ?>
		<div class="yo-table-scroll"><table class="widefat striped yo-table--endpoints"><thead><tr><th><?php echo esc_html__( 'Endpoint', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Events', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Status', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Actions', 'yo-booking' ); ?></th></tr></thead><tbody><?php $rows = $repository->all(); if ( ! $rows ) $this->render_empty_row( 4, __( 'No webhook endpoints', 'yo-booking' ), __( 'Add an endpoint to receive booking events.', 'yo-booking' ) ); foreach ( $rows as $endpoint ) : $events = json_decode( $endpoint->events, true ); ?><tr><td><strong><?php echo esc_html( $endpoint->name ); ?></strong><br /><code><?php echo esc_html( $endpoint->url ); ?></code></td><td><?php echo esc_html( implode( ', ', (array) $events ) ); ?></td><td><?php $this->render_status_badge( $endpoint->status ); ?></td><td><div class="yo-row-actions"><a class="button button-small" href="<?php echo esc_url( $this->settings_url( array( 'edit' => (int) $endpoint->id ) ) ); ?>"><?php echo esc_html__( 'Edit', 'yo-booking' ); ?></a><?php $this->post_button( 'yo_booking_test_webhook', 'yo_booking_test_webhook_' . $endpoint->id, $endpoint->id, __( 'Send test', 'yo-booking' ) ); ?><?php $this->post_button( 'yo_booking_delete_webhook', 'yo_booking_delete_webhook_' . $endpoint->id, $endpoint->id, __( 'Delete', 'yo-booking' ), 'button-link-delete' ); ?></div></td></tr><?php endforeach; ?></tbody></table></div>
		<?php
	}

	/** @return void */
	private function render_deliveries() {
		$rows = ( new WebhookDeliveryRepository() )->recent( 100 );
		?><div class="yo-table-scroll"><table class="widefat striped yo-table--deliveries"><thead><tr><th><?php echo esc_html__( 'Created', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Endpoint', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Event', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Status', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Attempts', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Response', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Actions', 'yo-booking' ); ?></th></tr></thead><tbody><?php if ( ! $rows ) $this->render_empty_row( 7, __( 'No webhook deliveries', 'yo-booking' ) ); foreach ( $rows as $row ) : ?><tr><td><?php echo esc_html( DateTimeFormatter::utc( $row->created_at ) ); ?></td><td><?php echo esc_html( $row->endpoint_name ? $row->endpoint_name : '#' . $row->endpoint_id ); ?></td><td><code><?php echo esc_html( $row->event ); ?></code><br /><?php echo esc_html( $row->object_type . ' #' . $row->object_id ); ?></td><td><?php $this->render_status_badge( $row->status ); ?></td><td><?php echo esc_html( (string) $row->attempts ); ?></td><td><?php echo esc_html( $row->response_code ? 'HTTP ' . $row->response_code : ( $row->error_message ? $row->error_message : '-' ) ); ?></td><td><?php if ( in_array( $row->status, array( 'failed', 'retrying' ), true ) ) $this->post_button( 'yo_booking_retry_webhook', 'yo_booking_retry_webhook_' . $row->id, $row->id, __( 'Retry now', 'yo-booking' ) ); else echo '-'; ?></td></tr><?php endforeach; ?></tbody></table></div><?php
	}

	/** @return void */
	private function render_api_keys() {
		$repository = new ApiKeyRepository();
		?><div class="yo-grid yo-grid--2 yo-section"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="yo-card"><?php wp_nonce_field( 'yo_booking_create_api_key' ); ?><input type="hidden" name="action" value="yo_booking_create_api_key" /><h2><?php echo esc_html__( 'Create API key', 'yo-booking' ); ?></h2><p><label><?php echo esc_html__( 'Name', 'yo-booking' ); ?><input class="widefat" required name="name" /></label></p><div class="yo-checkbox-list"><?php foreach ( ApiKeyRepository::capabilities() as $capability => $label ) : ?><label><input type="checkbox" name="capabilities[]" value="<?php echo esc_attr( $capability ); ?>" /><?php echo esc_html( $label ); ?></label><?php endforeach; ?></div><p><label><?php echo esc_html__( 'Expires', 'yo-booking' ); ?><input type="date" name="expires_at" /></label></p><?php submit_button( __( 'Create key', 'yo-booking' ), 'primary', 'submit', false ); ?></form><section class="yo-card"><h2><?php echo esc_html__( 'Authentication', 'yo-booking' ); ?></h2><p><code>Authorization: Bearer yb_live_...</code></p><p class="description"><?php echo esc_html__( 'Integration API base:', 'yo-booking' ); ?> <code><?php echo esc_html( rest_url( 'yo-booking/v1/integrations/' ) ); ?></code></p></section></div><div class="yo-table-scroll"><table class="widefat striped yo-table--api-keys"><thead><tr><th><?php echo esc_html__( 'Name', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Prefix', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Capabilities', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Last used', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Expires', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Status', 'yo-booking' ); ?></th><th><?php echo esc_html__( 'Actions', 'yo-booking' ); ?></th></tr></thead><tbody><?php $keys = $repository->all(); if ( ! $keys ) $this->render_empty_row( 7, __( 'No API keys', 'yo-booking' ) ); foreach ( $keys as $key ) : ?><tr><td><strong><?php echo esc_html( $key->name ); ?></strong></td><td><code><?php echo esc_html( $key->key_prefix . '...' ); ?></code></td><td><?php echo esc_html( implode( ', ', (array) json_decode( $key->capabilities, true ) ) ); ?></td><td><?php echo esc_html( $key->last_used_at ? DateTimeFormatter::utc( $key->last_used_at ) : '-' ); ?></td><td><?php echo esc_html( $key->expires_at ? DateTimeFormatter::utc( $key->expires_at, 'date' ) : __( 'Never', 'yo-booking' ) ); ?></td><td><?php $this->render_status_badge( $key->status ); ?></td><td><?php if ( 'active' === $key->status ) $this->post_button( 'yo_booking_revoke_api_key', 'yo_booking_revoke_api_key_' . $key->id, $key->id, __( 'Revoke', 'yo-booking' ), 'button-link-delete' ); else echo '-'; ?></td></tr><?php endforeach; ?></tbody></table></div><?php
	}

	/** @return void */
	public function save_webhook() {
		$this->ensure_capability(); check_admin_referer( 'yo_booking_save_webhook' );
		$data = wp_unslash( $_POST ); $id = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
		if ( ! $id || ! empty( $data['regenerate_secret'] ) ) { $data['secret'] = 'whsec_' . wp_generate_password( 40, false, false ); }
		$result = ( new WebhookEndpointRepository() )->save( $data );
		if ( ! is_wp_error( $result ) ) {
			if ( ! empty( $data['secret'] ) ) $this->reveal( __( 'Webhook signing secret:', 'yo-booking' ), $data['secret'] );
			// translators: %d: webhook endpoint database ID.
			( new AuditLogRepository() )->record( $id ? 'webhook.updated' : 'webhook.created', 'webhook_endpoint', $result, sprintf( __( 'Webhook endpoint #%d saved', 'yo-booking' ), $result ) );
		}
		$this->redirect_result( 'yo-booking-settings', $result, array( 'settings_tab' => 'integrations', 'integration_tab' => 'webhooks' ) );
	}

	/** @return void */
	public function delete_webhook() {
		$this->ensure_capability();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'yo_booking_delete_webhook_' . $id );
		( new WebhookEndpointRepository() )->delete( $id );
		// translators: %d: webhook endpoint database ID.
		$summary = sprintf( __( 'Webhook endpoint #%d deleted', 'yo-booking' ), $id );
		( new AuditLogRepository() )->record( 'webhook.deleted', 'webhook_endpoint', $id, $summary );
		$this->redirect( 'yo-booking-settings', 'deleted', array( 'settings_tab' => 'integrations' ) );
	}
	/** @return void */
	public function test_webhook() { $this->ensure_capability(); $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; check_admin_referer( 'yo_booking_test_webhook_' . $id ); $result = ( new WebhookDispatcher() )->queue_test( $id ); $this->redirect_result( 'yo-booking-settings', $result, array( 'settings_tab' => 'integrations', 'integration_tab' => 'deliveries' ) ); }
	/** @return void */
	public function retry_webhook() { $this->ensure_capability(); $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; check_admin_referer( 'yo_booking_retry_webhook_' . $id ); $repository = new WebhookDeliveryRepository(); $result = $repository->reset( $id ); if ( $result ) { wp_clear_scheduled_hook( WebhookDispatcher::PROCESS_HOOK, array( $id ) ); ( new WebhookDispatcher() )->schedule( $id, 1 ); } $this->redirect_result( 'yo-booking-settings', $result ? $id : new \WP_Error( 'yo_booking_webhook_retry_failed', __( 'Delivery could not be queued.', 'yo-booking' ) ), array( 'settings_tab' => 'integrations', 'integration_tab' => 'deliveries' ) ); }
	/** @return void */
	public function create_api_key() {
		$this->ensure_capability();
		check_admin_referer( 'yo_booking_create_api_key' );
		$result = ( new ApiKeyRepository() )->create_key( wp_unslash( $_POST ) );
		if ( ! is_wp_error( $result ) ) {
			$this->reveal( __( 'API key:', 'yo-booking' ), $result['key'] );
			// translators: %d: API key database ID.
			$summary = sprintf( __( 'API key #%d created', 'yo-booking' ), $result['id'] );
			( new AuditLogRepository() )->record( 'api_key.created', 'api_key', $result['id'], $summary );
		}
		$this->redirect_result( 'yo-booking-settings', is_wp_error( $result ) ? $result : $result['id'], array( 'settings_tab' => 'integrations', 'integration_tab' => 'api-keys' ) );
	}
	/** @return void */
	public function revoke_api_key() {
		$this->ensure_capability();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'yo_booking_revoke_api_key_' . $id );
		( new ApiKeyRepository() )->revoke( $id );
		// translators: %d: API key database ID.
		$summary = sprintf( __( 'API key #%d revoked', 'yo-booking' ), $id );
		( new AuditLogRepository() )->record( 'api_key.revoked', 'api_key', $id, $summary );
		$this->redirect( 'yo-booking-settings', 'saved', array( 'settings_tab' => 'integrations', 'integration_tab' => 'api-keys' ) );
	}

	/** @param array $args Extra query arguments. @return string */
	private function settings_url( array $args = array() ) { return add_query_arg( array_merge( array( 'page' => 'yo-booking-settings', 'settings_tab' => 'integrations' ), $args ), admin_url( 'admin.php' ) ); }

	/** @param string $label Label. @param string $value Secret value. @return void */
	private function reveal( $label, $value ) { set_transient( 'yo_booking_integration_reveal_' . get_current_user_id(), array( 'label' => $label, 'value' => SecretVault::encrypt( $value ) ), 5 * MINUTE_IN_SECONDS ); }
	/** @param string $action Action. @param string $nonce Nonce. @param int $id ID. @param string $label Label. @param string $class Extra class. @return void */
	private function post_button( $action, $nonce, $id, $label, $class = '' ) { ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"><input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" /><input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" /><?php wp_nonce_field( $nonce ); ?><button class="button button-small <?php echo esc_attr( $class ); ?>" type="submit"><?php echo esc_html( $label ); ?></button></form><?php }
}
