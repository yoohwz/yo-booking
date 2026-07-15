<?php
/**
 * Audit log repository.
 *
 * @package YoBooking
 */

namespace YoBooking\Repositories;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned storage requires direct SQL; dynamic table names are generated internally.

/**
 * Stores immutable operational audit entries.
 */
final class AuditLogRepository extends BaseRepository {
	/** @var string */
	protected $table_suffix = 'audit_logs';

	/**
	 * Record an audit event.
	 *
	 * @param string $action Action key.
	 * @param string $object_type Object type.
	 * @param int    $object_id Object ID.
	 * @param string $summary Human-readable summary.
	 * @param array  $context Non-sensitive structured context.
	 * @return int|false
	 */
	public function record( $action, $object_type, $object_id, $summary, array $context = array() ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'actor_user_id' => get_current_user_id(),
				'action'        => $this->action_key( $action ),
				'object_type'   => sanitize_key( $object_type ),
				'object_id'     => absint( $object_id ),
				'summary'       => sanitize_text_field( $summary ),
				'context'       => $context ? wp_json_encode( $context ) : null,
				'created_at'    => $this->now(),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return false === $inserted ? false : (int) $wpdb->insert_id;
	}

	/**
	 * List audit entries with optional filters.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function all( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args( $args, array( 'action' => '', 'object_type' => '', 'search' => '', 'limit' => 50, 'offset' => 0 ) );
		list( $where, $values ) = $this->where_clause( $args );
		$table = $this->table();
		$users = $wpdb->users;
		$values[] = max( 1, min( 200, absint( $args['limit'] ) ) );
		$values[] = absint( $args['offset'] );
		$sql = "SELECT l.*, u.display_name AS actor_name FROM {$table} l LEFT JOIN {$users} u ON u.ID = l.actor_user_id {$where} ORDER BY l.created_at DESC, l.id DESC LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	}

	/** @param array $args Query arguments. @return int */
	public function count_matching( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args( $args, array( 'action' => '', 'object_type' => '', 'search' => '' ) );
		list( $where, $values ) = $this->where_clause( $args );
		$table = $this->table();
		$sql = "SELECT COUNT(*) FROM {$table} l {$where}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) ( $values ? $wpdb->get_var( $wpdb->prepare( $sql, $values ) ) : $wpdb->get_var( $sql ) );
	}

	/** @param array $args Query arguments. @return array */
	private function where_clause( array $args ) {
		global $wpdb;

		$clauses = array( '1=1' );
		$values  = array();
		if ( $args['action'] ) {
			$clauses[] = 'l.action = %s';
			$values[]  = $this->action_key( $args['action'] );
		}
		if ( $args['object_type'] ) {
			$clauses[] = 'l.object_type = %s';
			$values[]  = sanitize_key( $args['object_type'] );
		}
		if ( $args['search'] ) {
			$like      = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$clauses[] = '(l.summary LIKE %s OR l.action LIKE %s)';
			$values[]  = $like;
			$values[]  = $like;
		}

		return array( 'WHERE ' . implode( ' AND ', $clauses ), $values );
	}

	/** @param string $action Raw action. @return string */
	private function action_key( $action ) {
		return substr( preg_replace( '/[^a-z0-9._-]/', '', strtolower( (string) $action ) ), 0, 64 );
	}
}
