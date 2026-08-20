<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * A hidden admin page (no menu entry) reached only via the "View log"
 * link on the Records screen. Shows just the log lines for one
 * request_id, from that day's log file.
 */
class CX_DHL_Log_Viewer {

	private $logger;

	public function __construct(CX_DHL_Logger $logger) {
		$this->logger = $logger;
		add_action('admin_menu', [$this, 'register_page']);
	}

	public function register_page() {
		add_submenu_page(
			null,
			'DHL API Log',
			'DHL API Log',
			'manage_options',
			'cx-dhl-api-log-viewer',
			[$this, 'render']
		);
	}

	public function render() {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}

		$date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : '';
		$request_id = isset($_GET['request_id']) ? sanitize_text_field($_GET['request_id']) : '';

		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $request_id === '') {
			wp_die('Invalid request.');
		}

		check_admin_referer('cx_dhl_view_log_' . $request_id);

		echo '<div class="wrap">';
		echo '<h1>DHL API Log &mdash; ' . esc_html($date) . '</h1>';
		echo '<p>Request ID: <code>' . esc_html($request_id) . '</code></p>';

		$file = $this->logger->get_log_file_for_date($date);

		if (!file_exists($file)) {
			echo '<p>No log file found for this date.</p></div>';
			return;
		}

		$lines = file($file, FILE_IGNORE_NEW_LINES);
		$needle = '[' . $request_id . ']';
		$matched = array_filter($lines, function ($line) use ($needle) {
			return strpos($line, $needle) !== false;
		});

		if (empty($matched)) {
			echo '<p>No log lines found for this request.</p></div>';
			return;
		}

		echo '<pre style="background:#fff;padding:15px;border:1px solid #ccd0d4;overflow:auto;">';
		foreach ($matched as $line) {
			echo esc_html($line) . "\n";
		}
		echo '</pre></div>';
	}
}
