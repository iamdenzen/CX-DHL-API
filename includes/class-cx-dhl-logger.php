<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * Writes the plugin's own per-day log files under
 * wp-content/uploads/cx-dhl-logs/, separate from WordPress's shared
 * debug.log. Every line carries a request_id so it can be correlated back
 * to a row in the cx_dhl_records table.
 */
class CX_DHL_Logger {

	private $option_name;

	public function __construct($option_name) {
		$this->option_name = $option_name;
		add_action('cx_dhl_log_cleanup', [$this, 'cleanup_old_logs']);
	}

	public function info($message, $context = []) {
		$this->log('info', $message, $context);
	}

	public function warning($message, $context = []) {
		$this->log('warning', $message, $context);
	}

	public function error($message, $context = []) {
		$this->log('error', $message, $context);
	}

	public function log($level, $message, $context = []) {
		if (!$this->is_enabled()) {
			return;
		}

		$dir = $this->get_log_dir();
		$this->ensure_protected_dir($dir);

		$request_id = isset($context['request_id']) ? $context['request_id'] : '-';
		unset($context['request_id']);

		$context = $this->redact($context);

		$line = sprintf(
			'[%s] [%s] [%s] %s',
			current_time('mysql'),
			strtoupper($level),
			$request_id,
			$message
		);

		if (!empty($context)) {
			$line .= ' ' . wp_json_encode($context);
		}

		$file = $dir . '/' . current_time('Y-m-d') . '.log';
		file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
	}

	public function get_log_file_for_date($date) {
		return $this->get_log_dir() . '/' . $date . '.log';
	}

	/**
	 * Deletes log files older than the configured retention window.
	 * Hooked to the daily cx_dhl_log_cleanup cron event.
	 */
	public function cleanup_old_logs($days = null) {
		$days = $days !== null ? (int) $days : $this->get_retention_days();
		$dir = $this->get_log_dir();

		if (!is_dir($dir)) {
			return;
		}

		$cutoff = strtotime('-' . $days . ' days', current_time('timestamp'));

		foreach (glob($dir . '/*.log') as $file) {
			$file_time = strtotime(basename($file, '.log'));
			if ($file_time !== false && $file_time < $cutoff) {
				@unlink($file);
			}
		}
	}

	private function is_enabled() {
		$options = get_option($this->option_name);
		if (!isset($options['enable_logging'])) {
			return true; // default on, before the settings form has ever been saved
		}
		return $options['enable_logging'] == '1';
	}

	private function get_retention_days() {
		$options = get_option($this->option_name);
		$days = (isset($options['log_retention_days']) && $options['log_retention_days'] !== '')
			? (int) $options['log_retention_days']
			: 30;
		return $days > 0 ? $days : 30;
	}

	private function get_log_dir() {
		$upload_dir = wp_upload_dir();
		return trailingslashit($upload_dir['basedir']) . 'cx-dhl-logs';
	}

	private function ensure_protected_dir($dir) {
		if (!file_exists($dir)) {
			wp_mkdir_p($dir);
		}

		$htaccess = $dir . '/.htaccess';
		if (!file_exists($htaccess)) {
			file_put_contents($htaccess, "Require all denied\nDeny from all\n");
		}

		$index = $dir . '/index.php';
		if (!file_exists($index)) {
			file_put_contents($index, "<?php\n// Silence is golden.\n");
		}
	}

	private function redact($context) {
		if (isset($context['headers']['Authorization'])) {
			$context['headers']['Authorization'] = 'Bearer [redacted]';
		}
		return $context;
	}
}
