<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * Persistent audit trail of everything the plugin generates (shipment
 * labels, return labels, Internetmarke PDFs, dashboard submissions).
 * Deliberately does not cover read-only lookups (tracking/manifests/
 * order lookup) - those are visible in the log files only.
 */
class CX_DHL_Records {

	const DB_VERSION = '1.0';
	const DB_VERSION_OPTION = 'cx_dhl_db_version';

	private $logger;

	public function __construct(CX_DHL_Logger $logger) {
		$this->logger = $logger;
		add_action('admin_init', [$this, 'maybe_create_table']);
	}

	private function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'cx_dhl_records';
	}

	/**
	 * The plugin file this replaces was already active, so the
	 * register_activation_hook path alone won't run for existing
	 * installs - this admin_init check is what actually creates the
	 * table for a site that's already running the plugin.
	 */
	public function maybe_create_table() {
		if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
			return;
		}
		$this->create_table();
		update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
	}

	public function create_table() {
		global $wpdb;
		$table = $this->table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			request_id VARCHAR(40) NOT NULL,
			record_type VARCHAR(32) NOT NULL,
			status VARCHAR(16) NOT NULL,
			http_code SMALLINT UNSIGNED NULL,
			source VARCHAR(16) NOT NULL,
			actor_id BIGINT UNSIGNED NULL,
			actor_label VARCHAR(191) NULL,
			reference VARCHAR(191) NULL,
			tracking_number VARCHAR(64) NULL,
			recipient_name VARCHAR(191) NULL,
			recipient_city VARCHAR(191) NULL,
			summary VARCHAR(255) NULL,
			error_message TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY record_type (record_type),
			KEY status (status),
			KEY tracking_number (tracking_number),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);
	}

	/**
	 * Writes one record row. Failures are caught and logged, never
	 * thrown back into the REST/dashboard flow returning a response to
	 * an external caller.
	 */
	public function insert($fields) {
		global $wpdb;

		$defaults = [
			'request_id'      => '',
			'record_type'     => '',
			'status'          => 'failed',
			'http_code'       => null,
			'source'          => '',
			'actor_id'        => null,
			'actor_label'     => '',
			'reference'       => '',
			'tracking_number' => '',
			'recipient_name'  => '',
			'recipient_city'  => '',
			'summary'         => '',
			'error_message'   => '',
			'created_at'      => current_time('mysql'),
		];

		$data = array_merge($defaults, $fields);

		$result = $wpdb->insert($this->table_name(), $data);

		if ($result === false) {
			$this->logger->error('Failed to insert DHL record', [
				'request_id' => $data['request_id'],
				'db_error'   => $wpdb->last_error,
			]);
		}

		return $result !== false;
	}

	/**
	 * Returns [actor_id, actor_label]. REST calls are authenticated WP
	 * users; the dashboard is PIN-gated with no WP session, so it falls
	 * back to the visitor's IP.
	 */
	public static function current_actor() {
		$user = wp_get_current_user();
		if ($user && $user->ID > 0) {
			return [$user->ID, $user->display_name ?: $user->user_login];
		}

		$ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
		return [0, 'Guest (' . $ip . ')'];
	}

	/**
	 * Extracts a success flag, tracking number, and human-readable
	 * message out of a make_request() response for a V01PAK order/order
	 * return call. Shared between the REST controller and the dashboard
	 * so both record the same way.
	 */
	public static function summarize_order_response($response) {
		$success = !empty($response['success']);
		$item = $response['body']['items'][0] ?? [];
		$tracking_number = $item['shipmentNo'] ?? $item['trackingNumber'] ?? '';

		if ($success) {
			$message = $response['body']['status']['detail'] ?? 'Label erstellt';
		} elseif (!empty($item['validationMessages']) && is_array($item['validationMessages'])) {
			$parts = [];
			foreach ($item['validationMessages'] as $vm) {
				$parts[] = ($vm['property'] ?? '') . ': ' . ($vm['validationMessage'] ?? '');
			}
			$message = implode('; ', $parts);
		} elseif (!empty($response['error'])) {
			$message = $response['error'];
		} else {
			$message = 'Unbekannter Fehler';
		}

		return [
			'success'         => $success,
			'tracking_number' => $tracking_number,
			'message'         => $message,
		];
	}

	public function query($args = []) {
		global $wpdb;
		$table = $this->table_name();

		$defaults = [
			'record_type' => '',
			'status'      => '',
			'search'      => '',
			'per_page'    => 20,
			'paged'       => 1,
			'orderby'     => 'created_at',
			'order'       => 'DESC',
		];
		$args = array_merge($defaults, $args);

		$where = ['1=1'];
		$params = [];

		if ($args['record_type'] !== '') {
			$where[] = 'record_type = %s';
			$params[] = $args['record_type'];
		}
		if ($args['status'] !== '') {
			$where[] = 'status = %s';
			$params[] = $args['status'];
		}
		if ($args['search'] !== '') {
			$where[] = '(reference LIKE %s OR tracking_number LIKE %s OR recipient_name LIKE %s)';
			$like = '%' . $wpdb->esc_like($args['search']) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode(' AND ', $where);

		$allowed_orderby = ['created_at', 'record_type', 'status'];
		$orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'created_at';
		$order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

		$per_page = max(1, (int) $args['per_page']);
		$paged = max(1, (int) $args['paged']);
		$offset = ($paged - 1) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));

		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$query_params = array_merge($params, [$per_page, $offset]);
		$items = $wpdb->get_results($wpdb->prepare($sql, $query_params));

		return [
			'items' => $items,
			'total' => $total,
		];
	}
}
