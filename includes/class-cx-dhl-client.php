<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * HTTP client for the DHL Parcel DE and Internetmarke APIs: base URL
 * resolution, both OAuth flows (ROPC + client_credentials), token caching,
 * and the generic authenticated request wrapper.
 */
class CX_DHL_Client {

	private $option_name;
	private $logger;

	private $base_url = array(
		'sandbox'	=>	'https://api-sandbox.dhl.com', // account/auth/ropc/v1
		'live'		=>	'https://api-eu.dhl.com' // /shipping/v2/
	);

	public function __construct($option_name, CX_DHL_Logger $logger) {
		$this->option_name = $option_name;
		$this->logger = $logger;
	}

	private function get_options() {
		return get_option($this->option_name);
	}

	private function get_base_url() {
		$options = $this->get_options();
		$mode = (isset($options['mode']) && isset($this->base_url[$options['mode']]))
			? $options['mode']
			: 'sandbox';

		return $this->base_url[$mode];
	}

	/*
	 *
	 * Get AUTH Token
	 *
	 * */
	public function get_token() {
		return $this->get_cached_token('cx_dhl_api_token', 1700, [$this, 'make_auth_request']);
	}

	public function im_get_token() {
		return $this->get_cached_token('cx_dhl_api_im_token', 86000, [$this, 'im_make_auth_request']);
	}

	private function get_cached_token($transient_key, $ttl, callable $requester) {
		$saved_token = get_transient($transient_key);

		if ($saved_token) {
			// Already saved? return it.
			return $saved_token;
		}

		// Get Fresh token
		$response = call_user_func($requester);
		if (
			isset($response['success']) &&
			isset($response['body']) &&
			isset($response['body']['access_token'])
		) {
			set_transient($transient_key, $response['body']['access_token'], $ttl);

			return $response['body']['access_token'];
		}

		return false;
	}

	/*
	 *
	 * Make Remote Request
	 *
	 * */
	public function make_request($endpoint, $method = 'GET', $body = [], $headers = [], $json = true, $token = false, $context = []) {
		$base_url = $this->get_base_url();

		if (empty($base_url)) {
			return false;
		}

		if (!$token || empty($token)) {
			$token = $this->get_token();
		}

		if (!$token) {
			return false;
		}

		$url = $base_url . $endpoint;

		// Content Type
		$content_type = $json === true ? 'application/json' : 'application/x-www-form-urlencoded';

		// Default headers
		$default_headers = [
			'Content-Type' => $content_type,
			'Authorization' => 'Bearer ' . $token // OAuth
		];

		// Merge custom headers with default
		$headers = array_merge($default_headers, $headers);

		// Set request arguments
		$args = [
			'method'  => strtoupper($method),
			'headers' => $headers,
			'timeout' => 15,
		];

		// If method is POST or PUT, add body
		if (in_array(strtoupper($method), ['POST', 'PUT']) && !empty($body) && $json === true) {
			$args['body'] = json_encode($body);
		} elseif ($method == "GET") {
			$url = $url . "?" . http_build_query($body);
		}

		$this->logger->log('info', 'DHL API request: ' . strtoupper($method) . ' ' . $url, array_merge($context, [
			'headers' => $headers,
		]));

		// Perform the request
		$response = wp_remote_request($url, $args);

		// Check for errors
		if (is_wp_error($response)) {
			$this->logger->log('error', 'DHL API request failed: ' . $response->get_error_message(), $context);

			return [
				'success' => false,
				'error'   => $response->get_error_message(),
			];
		}

		// Get response code and body
		$code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);

		$level = ($code >= 200 && $code < 300) ? 'info' : 'warning';
		$this->logger->log($level, 'DHL API response ' . $code . ' for ' . $url, $context);

		return [
			'success' => $code >= 200 && $code < 300,
			'code'    => $code,
			'body'    => json_decode($response_body, true), // assuming JSON response
		];
	}

	/*
	 *
	 * Make Auth Request
	 *
	 * */
	public function make_auth_request() {
		$options = $this->get_options();

		// Auth request body
		$body = array(
			'grant_type'    => 'password',
			'client_id'     => $options['client_id'],
			'client_secret' => $options['client_secret'],
			'username'      => $options['username'],
			'password'      => $options['password'],
		);

		return $this->perform_auth_request($this->get_base_url(), '/parcel/de/account/auth/ropc/v1/token', $body);
	}

	/*
	 *
	 * Make Auth Request (Internetmarke)
	 *
	 * */
	public function im_make_auth_request() {
		$options = $this->get_options();

		// Auth request body
		$body = array(
			'grant_type'    => 'client_credentials',
			'client_id'     => $options['client_id'],
			'client_secret' => $options['client_secret'],
			'username'      => $options['pcf_username'],
			'password'      => $options['pcf_password'],
		);

		return $this->perform_auth_request($this->get_base_url(), '/post/de/shipping/im/v1/user', $body);
	}

	private function perform_auth_request($base_url, $endpoint, $body) {
		if (empty($base_url)) {
			return false;
		}

		$url = $base_url . $endpoint;

		// Default headers
		$headers = [
			'Content-Type' => 'application/x-www-form-urlencoded',
		];

		// Set request arguments
		$args = [
			'method'  => 'POST',
			'headers' => $headers,
			'timeout' => 15,
			'body'    => http_build_query($body)
		];

		$this->logger->log('info', 'DHL auth token request: ' . $url, []);

		// Perform the request
		$response = wp_remote_request($url, $args);

		// Check for errors
		if (is_wp_error($response)) {
			$this->logger->log('error', 'DHL auth token request failed: ' . $response->get_error_message(), []);

			return [
				'success' => false,
				'error'   => $response->get_error_message(),
			];
		}

		// Get response code and body
		$code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);

		$level = ($code >= 200 && $code < 300) ? 'info' : 'warning';
		$this->logger->log($level, 'DHL auth token response ' . $code . ' for ' . $url, []);

		return [
			'success' => $code >= 200 && $code < 300,
			'code'    => $code,
			'body'    => json_decode($response_body, true), // assuming JSON response
			'raw'     => $response_body, // raw response body
		];
	}
}
