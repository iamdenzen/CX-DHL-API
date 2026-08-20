<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * REST routes consumed by WooCommerce order flows and external services:
 * label creation (+return variant), Internetmarke, order/manifest lookup,
 * and tracking. Routes, methods, args, and permission callbacks are kept
 * identical to the legacy plugin so existing callers see no change.
 */
class CX_DHL_REST_Controller {

	private $option_name;
	private $client;
	private $records;

	public function __construct($option_name, CX_DHL_Client $client, CX_DHL_Records $records) {
		$this->option_name = $option_name;
		$this->client = $client;
		$this->records = $records;
	}

	public function register_rest_api_routes() {

		// Print label -> Shipper: VimSolution, Receiver: Customer
		register_rest_route('wc/v3', '/dhl/v01pak', array(
			'methods' => 'POST',
			'callback' => [$this, 'dhl_create_order'],
			'permission_callback' => function () {
				return current_user_can('manage_woocommerce');
			}
		));

		// Print label -> Shipper: Customer, Receiver: VimSolution
		register_rest_route('wc/v3', '/dhl/v01pak-return', array(
			'methods' => 'POST',
			'callback' => [$this, 'dhl_create_order_return'],
			'permission_callback' => function () {
				return current_user_can('manage_woocommerce');
			}
		));

		register_rest_route('wc/v3', '/dhl/v01pak', array(
			'methods' => 'GET',
			'callback' => [$this, 'dhl_get_orders'],
			'permission_callback' => function () {
				return current_user_can('manage_woocommerce');
			},
			'args' => array(
				'trackingNumber' => array(
					'required' => true,
					'type' => 'string',
				)
			),
		));
		register_rest_route('wc/v3', '/dhl/tracking', array(
			'methods' => 'GET',
			'callback' => [$this, 'dhl_tracking'],
			'permission_callback' => function () {
				return current_user_can('manage_woocommerce');
			},
			'args' => array(
				'trackingNumber' => array(
					'required' => true,
					'type' => 'string',
				)
			),
		));
		register_rest_route('wc/v3', '/dhl/manifests', array(
			'methods' => 'GET',
			'callback' => [$this, 'dhl_get_manifests'],
			'permission_callback' => function () {
				return current_user_can('manage_woocommerce');
			},
			'args' => array(
				'date' => array(
					'required' => true,
					'type' => 'string',
				)
			),
		));


		register_rest_route('wc/v3', '/dhl/internetmarke', array(
			'methods' => 'POST',
			'callback' => [$this, 'dhl_internetmarke'],
			'permission_callback' => function () {
				return current_user_can('manage_woocommerce');
			}
		));

		register_rest_route('wc/v3', '/dhl/page-formats', array(
			'methods' => 'GET',
			'callback' => [$this, 'get_pageformats'],
			'permission_callback' => function () {
				return current_user_can('manage_woocommerce');
			}
		));
	}

	private function build_order_body($request, $reverse = false) {
		$options = get_option($this->option_name);

		$dimensions = $request->get_param('dimensions');

		$height = isset($dimensions['height']) ? $dimensions['height'] : null;
		$width = isset($dimensions['width']) ? $dimensions['width'] : null;
		$length = isset($dimensions['length']) ? $dimensions['length'] : null;

		$shipper_party = [
			'name1' => $options['shipper_name1'],
			'name2' => $options['shipper_name2'],
			'name3' => $options['shipper_name3'],
			'addressStreet' => $options['shipper_addressStreet'],
			'postalCode' => $options['shipper_postalCode'],
			'city' => $options['shipper_city'],
			'country' => 'DEU',
			'email' => $options['shipper_email'],
			'phone' => $options['shipper_phone'],
		];

		$request_party = [
			'name1' => $request->get_param('name'),
			'name2' => $request->get_param('name2'),
			'name3' => $request->get_param('name3'),
			'addressStreet' => $request->get_param('addressStreet'),
			'postalCode' => $request->get_param('postalCode'),
			'city' => $request->get_param('city'),
			'country' => 'DEU',
			'email' => $request->get_param('email'),
			'phone' => $request->get_param('phone'),
		];

		// Print label -> Shipper: VimSolution, Receiver: Customer
		// Return   -> Shipper: Customer, Receiver: VimSolution
		$shipper = $reverse ? $request_party : $shipper_party;
		$consignee = $reverse ? $shipper_party : $request_party;

		return [
			'profile' => 'STANDARD_GRUPPENPROFIL',
			'shipments' => [
				[
					'product' => 'V01PAK',
					'billingNumber' => $request->get_param('billingNumber'),
					'refNo' => 'Order No. ' . $request->get_param('refNo'),
					'shipper' => $shipper,
					'consignee' => $consignee,
					'details' => [
						'dim' => [
							'uom' => 'mm',
							'height' => $height,
							'length' => $length,
							'width' => $width,
						],
						'weight' => [
							'uom' => 'g',
							'value' => $request->get_param('weight'),
						],
					],
				],
			],
		];
	}

	/*
	 * Capture API submission
	 * process it etc etc
	 *
	 * Print label -> Shipper: VimSolution, Receiver: Customer
	 *
	 **/
	public function dhl_create_order($request) {
		$token = $this->client->get_token();
		if (!$token) {
			return new WP_Error('server_auth_error', 'Mit den API-Anmeldeinformationen stimmt etwas nicht.', ['status' => 401]);
		}

		$request_id = wp_generate_uuid4();
		$body = $this->build_order_body($request, false);

		// Get Fresh token
		$response = $this->client->make_request('/parcel/de/shipping/v2/orders', 'POST', $body, [], true, false, ['request_id' => $request_id]);

		$this->record_order_result('order', 'rest', $request_id, $body, $response);

		return $this->to_rest_response($response);
	}


	/*
	 * Capture API submission
	 * process it etc etc
	 *
	 * Print label -> Shipper: Customer, Receiver: VimSolution
	 *
	 **/
	public function dhl_create_order_return($request) {
		$token = $this->client->get_token();
		if (!$token) {
			return new WP_Error('server_auth_error', 'Mit den API-Anmeldeinformationen stimmt etwas nicht.', ['status' => 401]);
		}

		$request_id = wp_generate_uuid4();
		$body = $this->build_order_body($request, true);

		// Get Fresh token
		$response = $this->client->make_request('/parcel/de/shipping/v2/orders', 'POST', $body, [], true, false, ['request_id' => $request_id]);

		$this->record_order_result('order_return', 'rest', $request_id, $body, $response);

		return $this->to_rest_response($response);
	}

	private function record_order_result($type, $source, $request_id, $body, $response) {
		list($actor_id, $actor_label) = CX_DHL_Records::current_actor();
		$shipment = $body['shipments'][0];
		$summary = CX_DHL_Records::summarize_order_response($response);

		$this->records->insert([
			'request_id'      => $request_id,
			'record_type'     => $type,
			'status'          => $summary['success'] ? 'success' : 'failed',
			'http_code'       => $response['code'] ?? null,
			'source'          => $source,
			'actor_id'        => $actor_id,
			'actor_label'     => $actor_label,
			'reference'       => trim($shipment['billingNumber'] . ($shipment['refNo'] ? ' / ' . $shipment['refNo'] : '')),
			'tracking_number' => $summary['tracking_number'],
			'recipient_name'  => $shipment['consignee']['name1'] ?? '',
			'recipient_city'  => $shipment['consignee']['city'] ?? '',
			'summary'         => $summary['message'],
			'error_message'   => $summary['success'] ? '' : $summary['message'],
		]);
	}

	public function dhl_internetmarke($request) {
		$options = get_option($this->option_name);

		$token = $this->client->im_get_token();
		if (!$token) {
			return new WP_Error('server_auth_error', 'Mit den API-Anmeldeinformationen stimmt etwas nicht.', ['status' => 401]);
		}

		$request_id = wp_generate_uuid4();
		$context = ['request_id' => $request_id];

		// Initialize Shopping Cart
		$response = $this->client->make_request('/post/de/shipping/im/v1/app/shoppingcart', 'POST', [], [], true, $token, $context);
		if (isset($response['body']) && isset($response['body']['shopOrderId'])) {
			$shopOrderId = $response['body']['shopOrderId'];
		} else {
			list($actor_id, $actor_label) = CX_DHL_Records::current_actor();
			$this->records->insert([
				'request_id'    => $request_id,
				'record_type'   => 'internetmarke',
				'status'        => 'failed',
				'http_code'     => $response['code'] ?? null,
				'source'        => 'rest',
				'actor_id'      => $actor_id,
				'actor_label'   => $actor_label,
				'summary'       => 'Warenkorb-Initialisierung fehlgeschlagen',
				'error_message' => 'Warenkorb-Initialisierung fehlgeschlagen',
			]);

			return new WP_Error('shoppingcart_init_failed', 'Der Warenkorb konnte nicht initialisiert werden.');
		}

		$pageFormatId = $request->get_param('pageFormatId') ?? 1;
		$total = $request->get_param('total') ?? 0;

		$sender = [
			'name' 			=>	$request->get_param('shipper_name') ?? $options['shipper_name1'],
			'additionalName'=>	$request->get_param('shipper_name2') ?? $options['shipper_name2'],
			'addressLine1'	=>	$options['shipper_addressStreet'],
			'postalCode'	=>	$options['shipper_postalCode'],
			'city'			=>	$options['shipper_city'],
			'country'		=>	'DEU'
		];

		// Auth request body
		$body = [
			'type' 				=>	'AppShoppingCartPDFRequest',
			'shopOrderId'		=>	$shopOrderId,
			'pageFormatId'		=>	$pageFormatId,
			'positions' 		=>	[],
			"total"				=>	$total,
			"createManifest"	=>	true,
			"createShippingList"=>	"2",
			"dpi"				=>	"DPI300"
		];

		$positions = $request->get_param('positions') ?? [];
		foreach ($positions as $position) {
			$body['positions'][] = array(
				'productCode'	=>	$position['productCode'],
				'address'		=>	[
					'sender'	=>	$sender,
					'receiver'	=>	[
						'name'			=>	$position['address']['receiver']['name'] ?? "",
						'additionalName'=>	$position['address']['receiver']['name2'] ?? "",
						'addressLine1'	=>	$position['address']['receiver']['addressStreet'] ?? "",
						'postalCode'	=>	$position['address']['receiver']['postalCode'] ?? "",
						'city'			=>	$position['address']['receiver']['city'] ?? "",
						'country'		=>	'DEU'
					]
				],
				'voucherLayout'		=>	$position['voucherLayout'],
				'position'			=>	$position['position'],
				'positionType'		=>	$position['positionType']
			);
		}

		// Make Request
		$response = $this->client->make_request('/post/de/shipping/im/v1/app/shoppingcart/pdf', 'POST', $body, [], true, $token, $context);

		$success = !empty($response['success']);
		list($actor_id, $actor_label) = CX_DHL_Records::current_actor();

		$recipient_name = '';
		$recipient_city = '';
		if (!empty($body['positions'][0]['address']['receiver'])) {
			$recipient_name = $body['positions'][0]['address']['receiver']['name'] ?? '';
			$recipient_city = $body['positions'][0]['address']['receiver']['city'] ?? '';
		}

		$error_message = '';
		if (!$success) {
			$error_message = $response['body']['detail'] ?? $response['body']['title'] ?? ($response['error'] ?? 'Fehler bei der Anfrage - siehe Log');
		}

		$this->records->insert([
			'request_id'      => $request_id,
			'record_type'     => 'internetmarke',
			'status'          => $success ? 'success' : 'failed',
			'http_code'       => $response['code'] ?? null,
			'source'          => 'rest',
			'actor_id'        => $actor_id,
			'actor_label'     => $actor_label,
			'reference'       => (string) $shopOrderId,
			'recipient_name'  => $recipient_name,
			'recipient_city'  => $recipient_city,
			'summary'         => $success ? 'PDF erstellt' : 'PDF-Erstellung fehlgeschlagen',
			'error_message'   => $error_message,
		]);

		return $this->to_rest_response($response);
	}



	/*
	 *
	 * Get the list of PageFormats
	 *
	 * */
	public function get_pageformats($request) {
		$token = $this->client->im_get_token();
		if (!$token) {
			return new WP_Error('server_auth_error', 'Mit den API-Anmeldeinformationen stimmt etwas nicht.', ['status' => 401]);
		}

		$body = array(
			'types'	=>	'PAGE_FORMATS'
		);

		// Initialize Shopping Cart
		$response = $this->client->make_request('/post/de/shipping/im/v1/app/catalog', 'GET', $body, [], false, $token);

		return $this->to_rest_response($response);
	}



	/*
	 * DHL Get Orders By Shipment ID
	 *
	 **/
	public function dhl_get_orders($request) {
		$token = $this->client->get_token();
		if (!$token) {
			return new WP_Error('server_auth_error', 'Mit den API-Anmeldeinformationen stimmt etwas nicht.', ['status' => 401]);
		}

		$trackingNumber = $request->get_param('trackingNumber');

		// Request Body
		$body = [
			'shipment' 		=> $trackingNumber,
			'docFormat'		=> 'PDF',
			'includeDocs'	=> 'URL'
		];

		// Get Fresh token
		$response = $this->client->make_request('/parcel/de/shipping/v2/orders', 'GET', $body, [], false, $token);

		return $this->to_rest_response($response);
	}



	/*
	 * DHL Get Manifests by date
	 *
	 **/
	public function dhl_get_manifests($request) {
		$token = $this->client->get_token();
		if (!$token) {
			return new WP_Error('server_auth_error', 'Mit den API-Anmeldeinformationen stimmt etwas nicht.', ['status' => 401]);
		}

		$date = $request->get_param('date');

		// Request Body
		$body = [
			'date' 		=> $date
		];

		// Get Fresh token
		$response = $this->client->make_request('/parcel/de/shipping/v2/manifests', 'GET', $body, [], false, $token);

		return $this->to_rest_response($response);
	}




	/*
	 * DHL Tracking
	 *
	 **/
	public function dhl_tracking($request) {
		$options = get_option($this->option_name);

		$token = $this->client->get_token();
		if (!$token) {
			return new WP_Error('server_auth_error', 'Mit den API-Anmeldeinformationen stimmt etwas nicht.', ['status' => 401]);
		}

		$trackingNumber = $request->get_param('trackingNumber');

		// Auth request header
		$headers = [
			'DHL-API-Key'	=>	$options['client_id']
		];

		// Request Body
		$body = [
			'trackingNumber' => $trackingNumber,
		];

		// Get Fresh token
		$response = $this->client->make_request('/track/shipments', 'GET', $body, $headers, false, $token);

		return $this->to_rest_response($response);
	}

	/**
	 * Legacy behavior returned the raw make_request() array as-is, which
	 * WordPress always wraps as HTTP 200 - so a rejected DHL request
	 * still came back "successful" at the transport level. Body shape
	 * (success/code/body keys) is unchanged; only the HTTP status now
	 * reflects the actual outcome.
	 */
	private function to_rest_response($response) {
		if (!is_array($response)) {
			return new WP_Error('dhl_request_failed', 'Die Anfrage an die DHL API ist fehlgeschlagen.', ['status' => 502]);
		}

		if (!empty($response['success'])) {
			$status = !empty($response['code']) ? (int) $response['code'] : 200;
		} else {
			$status = (!empty($response['code']) && (int) $response['code'] >= 400) ? (int) $response['code'] : 502;
		}

		return new WP_REST_Response($response, $status);
	}
}
