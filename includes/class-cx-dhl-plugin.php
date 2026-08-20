<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * Orchestrator/facade. Keeps the original class name and every original
 * public method so any external code that instantiates CX_DHL_API directly
 * or calls its methods keeps working unchanged, while the actual logic now
 * lives in the focused classes below.
 */
class CX_DHL_API {
	private $option_name = 'cx_dhl_api_settings';

	private $logger;
	private $records;
	private $client;
	private $settings;
	private $dashboard;
	private $rest_controller;
	private $records_admin;
	private $log_viewer;

	public function __construct() {
		$this->logger = new CX_DHL_Logger($this->option_name);
		$this->records = new CX_DHL_Records($this->logger);
		$this->client = new CX_DHL_Client($this->option_name, $this->logger);
		$this->settings = new CX_DHL_Settings($this->option_name);
		$this->dashboard = new CX_DHL_Dashboard($this->option_name, $this->client, $this->records);
		$this->rest_controller = new CX_DHL_REST_Controller($this->option_name, $this->client, $this->records);
		// Self-register their own admin_menu hooks.
		$this->records_admin = new CX_DHL_Records_Admin($this->records);
		$this->log_viewer = new CX_DHL_Log_Viewer($this->logger);

		add_action('admin_menu', [$this->settings, 'add_settings_page']);
		add_action('admin_init', [$this->settings, 'register_settings']);
		add_shortcode('dhl_dashboard', [$this->dashboard, 'render_dashboard']);

		add_action('rest_api_init', [$this->rest_controller, 'register_rest_api_routes']);
	}

	// --- Backward-compatible delegating wrappers ---

	public function add_settings_page() {
		return $this->settings->add_settings_page();
	}

	public function register_settings() {
		return $this->settings->register_settings();
	}

	public function text_field_callback($args) {
		return $this->settings->text_field_callback($args);
	}

	public function password_field_callback($args) {
		return $this->settings->password_field_callback($args);
	}

	public function select_field_callback($args) {
		return $this->settings->select_field_callback($args);
	}

	public function settings_page_html() {
		return $this->settings->settings_page_html();
	}

	public function render_dashboard() {
		return $this->dashboard->render_dashboard();
	}

	public function process_form_submission() {
		return $this->dashboard->process_form_submission();
	}

	public function register_rest_api_routes() {
		return $this->rest_controller->register_rest_api_routes();
	}

	public function dhl_create_order($request) {
		return $this->rest_controller->dhl_create_order($request);
	}

	public function dhl_create_order_return($request) {
		return $this->rest_controller->dhl_create_order_return($request);
	}

	public function dhl_internetmarke($request) {
		return $this->rest_controller->dhl_internetmarke($request);
	}

	public function get_pageformats($request) {
		return $this->rest_controller->get_pageformats($request);
	}

	public function dhl_get_orders($request) {
		return $this->rest_controller->dhl_get_orders($request);
	}

	public function dhl_get_manifests($request) {
		return $this->rest_controller->dhl_get_manifests($request);
	}

	public function dhl_tracking($request) {
		return $this->rest_controller->dhl_tracking($request);
	}

	public function get_token() {
		return $this->client->get_token();
	}

	public function im_get_token() {
		return $this->client->im_get_token();
	}

	public function make_request($endpoint, $method = 'GET', $body = [], $headers = [], $json = true, $token = false) {
		return $this->client->make_request($endpoint, $method, $body, $headers, $json, $token);
	}

	public function make_auth_request() {
		return $this->client->make_auth_request();
	}

	public function im_make_auth_request() {
		return $this->client->im_make_auth_request();
	}
}
