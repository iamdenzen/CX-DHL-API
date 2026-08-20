<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * Admin settings screen (Settings > DHL API): credential fields, shipper
 * defaults, and the API mode (sandbox/live) selector.
 */
class CX_DHL_Settings {

	private $option_name;

	public function __construct($option_name) {
		$this->option_name = $option_name;

		// Legacy behavior invalidated the cached token on every Settings
		// page *view*. Only do it when the option is actually saved.
		add_action('update_option_' . $option_name, [$this, 'on_settings_updated']);
	}

	public function on_settings_updated() {
		delete_transient('cx_dhl_api_token');
	}

	public function add_settings_page() {
		add_options_page(
			'DHL API Settings',
			'DHL API',
			'manage_options',
			'cx-dhl-api-settings',
			[$this, 'settings_page_html']
		);
	}

	public function register_settings() {
		register_setting('cx_dhl_api_settings_group', $this->option_name);

		add_settings_section(
			'cx_dhl_api_main_section',
			'DHL API Credentials',
			null,
			'cx-dhl-api-settings'
		);

		add_settings_field('client_id', 'Client ID', [$this, 'text_field_callback'], 'cx-dhl-api-settings', 'cx_dhl_api_main_section', ['label_for' => 'client_id']);

		add_settings_field('client_secret', 'Client Secret', [$this, 'password_field_callback'], 'cx-dhl-api-settings', 'cx_dhl_api_main_section', ['label_for' => 'client_secret']);

		add_settings_field('username', 'Username', [$this, 'text_field_callback'], 'cx-dhl-api-settings', 'cx_dhl_api_main_section', ['label_for' => 'username']);

		add_settings_field('pass', 'Password', [$this, 'password_field_callback'], 'cx-dhl-api-settings', 'cx_dhl_api_main_section', ['label_for' => 'password']);

		add_settings_field('pcf_username', 'PCF Username', [$this, 'text_field_callback'], 'cx-dhl-api-settings', 'cx_dhl_api_main_section', ['label_for' => 'pcf_username']);

		add_settings_field('pcf_pass', 'PCF Password', [$this, 'password_field_callback'], 'cx-dhl-api-settings', 'cx_dhl_api_main_section', ['label_for' => 'pcf_password']);

		add_settings_field('pin', 'Dashboard PIN', [$this, 'text_field_callback'], 'cx-dhl-api-settings', 'cx_dhl_api_main_section', ['label_for' => 'pin']);

		add_settings_field('mode', 'API Mode', [$this, 'select_field_callback'], 'cx-dhl-api-settings', 'cx_dhl_api_main_section', ['label_for' => 'mode']);


		add_settings_section(
			'cx_dhl_api_shipper_section',
			'Shipper Details',
			null,
			'cx-dhl-api-settings'
		);

		$shipper_fields = [
			'shipper_name1'			=>	'Name',
			'shipper_addressStreet'	=>	'Street Address',
			'shipper_postalCode'	=>	'Postal Code',
			'shipper_city'			=>	'City',
			'shipper_email'			=>	'Email',
			'shipper_phone'			=>	'Phone'
		];
		foreach ($shipper_fields as $s_field_key => $s_field_value) {
			add_settings_field($s_field_key, $s_field_value, [$this, 'text_field_callback'], 'cx-dhl-api-settings', 'cx_dhl_api_shipper_section', ['label_for' => $s_field_key]);
		}


		add_settings_section(
			'cx_dhl_api_logging_section',
			'Logging',
			null,
			'cx-dhl-api-settings'
		);

		add_settings_field('enable_logging', 'Enable Request Logging', [$this, 'checkbox_field_callback'], 'cx-dhl-api-settings', 'cx_dhl_api_logging_section', ['label_for' => 'enable_logging']);

		add_settings_field('log_retention_days', 'Log Retention (days)', [$this, 'number_field_callback'], 'cx-dhl-api-settings', 'cx_dhl_api_logging_section', ['label_for' => 'log_retention_days', 'default' => 30, 'min' => 1]);
	}

	public function text_field_callback($args) {
		$options = get_option($this->option_name);
		$value = isset($options[$args['label_for']]) ? esc_attr($options[$args['label_for']]) : '';
		echo "<input type='text' id='{$args['label_for']}' name='{$this->option_name}[{$args['label_for']}]' value='{$value}' class='regular-text'>";
	}

	public function password_field_callback($args) {
		$options = get_option($this->option_name);
		$value = isset($options[$args['label_for']]) ? esc_attr($options[$args['label_for']]) : '';
		echo "<input type='password' id='{$args['label_for']}' name='{$this->option_name}[{$args['label_for']}]' value='{$value}' class='regular-text'>";
	}

	public function checkbox_field_callback($args) {
		$options = get_option($this->option_name);
		// Default on before the settings form has ever been saved.
		$checked = !isset($options[$args['label_for']]) || $options[$args['label_for']] == '1';
		echo "<input type='hidden' name='{$this->option_name}[{$args['label_for']}]' value='0'>";
		echo "<input type='checkbox' id='{$args['label_for']}' name='{$this->option_name}[{$args['label_for']}]' value='1'" . checked($checked, true, false) . ">";
	}

	public function number_field_callback($args) {
		$options = get_option($this->option_name);
		$default = isset($args['default']) ? $args['default'] : '';
		$value = (isset($options[$args['label_for']]) && $options[$args['label_for']] !== '')
			? esc_attr($options[$args['label_for']])
			: esc_attr($default);
		$min = isset($args['min']) ? " min='" . esc_attr($args['min']) . "'" : '';
		echo "<input type='number' id='{$args['label_for']}' name='{$this->option_name}[{$args['label_for']}]' value='{$value}'{$min} class='small-text'>";
	}

	public function select_field_callback($args) {
		$options = get_option($this->option_name);
		$value = isset($options[$args['label_for']]) ? $options[$args['label_for']] : 'sandbox';
		echo "<select id='{$args['label_for']}' name='{$this->option_name}[{$args['label_for']}]'>";
		echo "<option value='sandbox'" . selected($value, 'sandbox', false) . ">Sandbox</option>";
		echo "<option value='live'" . selected($value, 'live', false) . ">Live</option>";
		echo "</select>";
	}

	public function settings_page_html() {
		echo '<div class="wrap">';
		echo '<h1>DHL API Settings</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields('cx_dhl_api_settings_group');
		do_settings_sections('cx-dhl-api-settings');
		submit_button();
		echo '</form>';
		echo '<table class="form-table"><tr><th scope="row"><label for="shortcode">Dashboard Shortcode:</label></th><td><input type="text" readonly value="[dhl_dashboard]" class="regular-text" id="shortcode"></td></tr></table>';
		echo '</div>';
	}
}
