<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the DHL API Records admin screen: one row per generated
 * shipment/label/PDF, with a link to the exact log lines for that request.
 */
class CX_DHL_Records_List_Table extends WP_List_Table {

	private $records;

	public function __construct(CX_DHL_Records $records) {
		parent::__construct([
			'singular' => 'dhl_record',
			'plural'   => 'dhl_records',
			'ajax'     => false,
		]);
		$this->records = $records;
	}

	public function get_columns() {
		return [
			'created_at'      => 'Date',
			'record_type'     => 'Type',
			'status'          => 'Status',
			'reference'       => 'Reference',
			'tracking_number' => 'Tracking #',
			'recipient'       => 'Recipient',
			'actor_label'     => 'Actor',
			'summary'         => 'Summary',
			'log'             => 'Log',
		];
	}

	protected function get_sortable_columns() {
		return [
			'created_at'  => ['created_at', true],
			'record_type' => ['record_type', false],
			'status'      => ['status', false],
		];
	}

	public function prepare_items() {
		$per_page = 20;
		$paged = $this->get_pagenum();

		$record_type = isset($_GET['record_type']) ? sanitize_text_field($_GET['record_type']) : '';
		$status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
		$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
		$orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'created_at';
		$order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'desc';

		$result = $this->records->query([
			'record_type' => $record_type,
			'status'      => $status,
			'search'      => $search,
			'per_page'    => $per_page,
			'paged'       => $paged,
			'orderby'     => $orderby,
			'order'       => $order,
		]);

		$this->items = $result['items'];

		$this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];

		$this->set_pagination_args([
			'total_items' => $result['total'],
			'per_page'    => $per_page,
			'total_pages' => (int) ceil($result['total'] / $per_page),
		]);
	}

	public function column_default($item, $column_name) {
		switch ($column_name) {
			case 'created_at':
				return esc_html($item->created_at);
			case 'record_type':
				return esc_html($item->record_type);
			case 'status':
				$class = $item->status === 'success' ? 'cx-dhl-status-success' : 'cx-dhl-status-failed';
				$code = !empty($item->http_code) ? ' (' . (int) $item->http_code . ')' : '';
				return '<span class="' . esc_attr($class) . '">' . esc_html(ucfirst($item->status)) . esc_html($code) . '</span>';
			case 'reference':
				return esc_html((string) $item->reference);
			case 'tracking_number':
				return esc_html((string) $item->tracking_number);
			case 'recipient':
				$name = (string) ($item->recipient_name ?? '');
				$city = (string) ($item->recipient_city ?? '');
				return esc_html(trim($name . ($city !== '' ? ' (' . $city . ')' : '')));
			case 'actor_label':
				return esc_html((string) $item->actor_label);
			case 'summary':
				$text = $item->status === 'success' ? $item->summary : $item->error_message;
				return esc_html((string) $text);
			case 'log':
				return $this->column_log($item);
			default:
				return '';
		}
	}

	private function column_log($item) {
		if (empty($item->request_id)) {
			return '&mdash;';
		}

		$date = substr($item->created_at, 0, 10);
		$url = wp_nonce_url(
			add_query_arg(
				[
					'page'       => 'cx-dhl-api-log-viewer',
					'date'       => $date,
					'request_id' => $item->request_id,
				],
				admin_url('admin.php')
			),
			'cx_dhl_view_log_' . $item->request_id
		);

		return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">View log</a>';
	}

	protected function extra_tablenav($which) {
		if ($which !== 'top') {
			return;
		}

		$record_type = isset($_GET['record_type']) ? sanitize_text_field($_GET['record_type']) : '';
		$status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
		$types = ['order' => 'Order', 'order_return' => 'Order (Return)', 'internetmarke' => 'Internetmarke', 'dashboard_order' => 'Dashboard'];
		?>
		<div class="alignleft actions">
			<select name="record_type">
				<option value="">All types</option>
				<?php foreach ($types as $value => $label) : ?>
					<option value="<?php echo esc_attr($value); ?>" <?php selected($record_type, $value); ?>><?php echo esc_html($label); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="status">
				<option value="">All statuses</option>
				<option value="success" <?php selected($status, 'success'); ?>>Success</option>
				<option value="failed" <?php selected($status, 'failed'); ?>>Failed</option>
			</select>
			<?php submit_button('Filter', '', 'filter_action', false); ?>
		</div>
		<?php
	}
}
