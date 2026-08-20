<?php
/*
Superseded by ../cx-dhl-api.php (v1.1+). Kept here only for reference and
rollback - do not activate this file as a plugin. See ../CHANGELOG.md for
what changed and why. Original header preserved below for reference.

Original Plugin Name: CX DHL API
Description: Integrate DHL Parcel DE API with WordPress. Includes admin settings and frontend dashboard via shortcode.
Version: 1.0
Author: Creatricx
*/

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class CX_DHL_API {
    private $option_name = 'cx_dhl_api_settings';
	private $base_url = array(
		'sandbox'	=>	'https://api-sandbox.dhl.com', // account/auth/ropc/v1
		'live'		=>	'https://api-eu.dhl.com' // /shipping/v2/
	);
	
    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
		//add_action('template_redirect', [$this, 'process_form_submission']);
        add_shortcode('dhl_dashboard', [$this, 'render_dashboard']);
		
		add_action('rest_api_init', [$this, 'register_rest_api_routes']);
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
		foreach($shipper_fields as $s_field_key => $s_field_value){
			add_settings_field($s_field_key, $s_field_value, [$this, 'text_field_callback'], 'cx-dhl-api-settings', 'cx_dhl_api_shipper_section', ['label_for' => $s_field_key]);
		}
		
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

	public function select_field_callback($args) {
		$options = get_option($this->option_name);
		$value = isset($options[$args['label_for']]) ? $options[$args['label_for']] : 'sandbox';
		echo "<select id='{$args['label_for']}' name='{$this->option_name}[{$args['label_for']}]'>";
		echo "<option value='sandbox'" . selected($value, 'sandbox', false) . ">Sandbox</option>";
		echo "<option value='live'" . selected($value, 'live', false) . ">Live</option>";
		echo "</select>";
	}

    public function settings_page_html() {
		
		delete_transient( 'cx_dhl_api_token' );
		
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

    public function render_dashboard() {
		$options = get_option($this->option_name);
		$pin = isset($options['pin']) ? $options['pin'] : '';
		$url_pin = isset($_GET['pin']) ? sanitize_text_field($_GET['pin']) : '';

		if (empty($url_pin) || $url_pin !== $pin) {
			return ''; // Return blank if username not provided or doesn't match
		}
		
		$token = $this->get_token();
		if( !$token ){
			return "Fehler: Mit den API-Anmeldeinformationen stimmt etwas nicht.";
		}
		
		ob_start();
?>
<div id="cx-dhl-dashboard" class="cx-dhl-dashboard">
	<h2>
		DHL Dashboard
	</h2>
	
	<?php
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' ){
			echo $this->process_form_submission();
		}	
	?>
	
	<form class="cx-form" action="/dhl/?pin=<?= esc_attr($_GET['pin']); ?>" method="POST">
		<?php wp_nonce_field('cx_dhl_v01pak', 'cx_dhl_nonce'); ?>
		<select>
			<option>Parcel Shipment</option>
			<option>Internetmarke</option>
		</select>
		
		<h3>
			Bestelldetails
		</h3>
		
		<input type="text" name="cx_billingNumber" id="billingNumber" placeholder="Rechnungsnummer" <?= cx_default('billingNumber'); ?>>
		<input type="text" name="cx_refNo" id="refNo" placeholder="Ref.-Nr." <?= cx_default('refNo'); ?>>

		<h3>
			Empfängerinformationen
		</h3>

		<input type="text" name="cx_name" id="name" placeholder="Name" <?= cx_default('name1'); ?>>
		<input type="text" name="cx_addressStreet" id="addressStreet" placeholder="Straßenadresse" <?= cx_default('addressStreet'); ?>>
		<input type="text" name="cx_city" id="city" placeholder="Stadt" <?= cx_default('city'); ?>>
		<input type="text" name="cx_postalCode" id="postalCode" placeholder="Plz" <?= cx_default('postalCode'); ?>>
		<input type="email" name="cx_email" id="email" placeholder="E-Mail" <?= cx_default('email'); ?>>
		<input type="text" name="cx_phone" id="phone" placeholder="Telefon" <?= cx_default('phone'); ?>>
		
		<h3>
			Weitere Details
		</h3>
		
		<div class="cx-group">
			<input type="number" name="cx_height" id="height" placeholder="Pakethöhe (mm)" max="10000" min="1" <?= cx_default('height'); ?>>
			<input type="number" name="cx_length" id="length" placeholder="Paketlänge (mm)" max="10000" min="1" <?= cx_default('length'); ?>>
			<input type="number" name="cx_width" id="width" placeholder="Paketbreite (mm)" max="10000" min="1" <?= cx_default('width'); ?>>
			<input type="number" name="cx_weight" id="weight" placeholder="Paketgewicht (g)" max="10000" min="1" <?= cx_default('weight'); ?>>
		</div>
		
		<div class="cx-form-footer">
			<button type="submit" name="cx_submit" class="et_pb_button">
				Schicken
			</button>
		</div>
	</form>
</div>

<style>
	h1.entry-title{display:none}.cx-dhl-dashboard h2{text-align:center;padding-bottom:35px}.cx-dhl-dashboard h3{padding:15px 0;font-size:18px}#cx-dhl-dashboard{max-width:600px;width:80%;margin:10px auto 40px;background:#ecedf3;padding:35px;border-radius:15px}.cx-form input[type=email],.cx-form input[type=number],.cx-form input[type=password],.cx-form input[type=tel],.cx-form input[type=text],.cx-form select,.cx-form textarea{display:block;width:100%;padding:10px 17px;margin-bottom:15px;border:1px solid #bbb;color:#4e4e4e}.cx-group{display:flex;column-gap:15px;flex-wrap:wrap}.cx-group>*{min-width:calc(50% - 15px);max-width:calc(50% - 15px);width:calc(50% - 15px)}.cx-form-footer{text-align:center;margin-top:20px}
</style>
<?php
        return ob_get_clean();
    }
	
	
	/*
	 * Capture $_POST submission
	 * process it etc etc
	 * 
	 **/
	public function process_form_submission(){
		if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['cx_dhl_nonce'])) {
			return;
		}
		
		if (!wp_verify_nonce($_POST['cx_dhl_nonce'], 'cx_dhl_v01pak')) {
			die('Sicherheitsüberprüfung fehlgeschlagen! Nonce-Verifizierung fehlgeschlagen.');
		}

		$options = get_option($this->option_name);
		$pin = isset($options['pin']) ? $options['pin'] : '';
		$url_pin = isset($_GET['pin']) ? sanitize_text_field($_GET['pin']) : '';

		if (empty($url_pin) || $url_pin !== $pin) {
			return ''; // Return blank if pin not provided or doesn't match
		}

		$token = $this->get_token();
		if( !$token ){
			return '<div class="cx-floating cx-error">Fehler: Mit den API-Anmeldeinformationen stimmt etwas nicht.</div>';
		}
		
		// Preparing Data
		$billingNumber = isset($_POST['cx_billingNumber']) ? sanitize_text_field($_POST['cx_billingNumber']) : "";
		$refNo = isset($_POST['cx_refNo']) ? sanitize_text_field($_POST['cx_refNo']) : "";
		$name = isset($_POST['cx_name']) ? sanitize_text_field($_POST['cx_name']) : "";
		$addressStreet = isset($_POST['cx_addressStreet']) ? sanitize_text_field($_POST['cx_addressStreet']) : "";
		$city = isset($_POST['cx_city']) ? sanitize_text_field($_POST['cx_city']) : "";
		$postalCode = isset($_POST['cx_postalCode']) ? sanitize_text_field($_POST['cx_postalCode']) : "";
		$telefon = isset($_POST['cx_telefon']) ? sanitize_text_field($_POST['cx_telefon']) : "";
		$email = isset($_POST['cx_email']) ? sanitize_text_field($_POST['cx_email']) : "";
		$phone = isset($_POST['cx_phone']) ? sanitize_text_field($_POST['cx_phone']) : "";
		$height = isset($_POST['cx_height']) ? sanitize_text_field($_POST['cx_height']) : "";
		$length = isset($_POST['cx_length']) ? sanitize_text_field($_POST['cx_length']) : "";
		$width = isset($_POST['cx_width']) ? sanitize_text_field($_POST['cx_width']) : "";
		$weight = isset($_POST['cx_weight']) ? sanitize_text_field($_POST['cx_weight']) : "";
		
		// Auth request body
		$body = [
			'profile' => 'STANDARD_GRUPPENPROFIL',
			'shipments' => [
				[
					'product' => 'V01PAK',
					'billingNumber' => $billingNumber,
					'refNo' => $refNo,
					'shipper' => [
						'name1' => $options['shipper_name1'],
						'addressStreet' => $options['shipper_addressStreet'],
						'postalCode' => $options['shipper_postalCode'],
						'city' => $options['shipper_city'],
						'country' => 'DEU',
						'email' => $options['shipper_email'],
						'phone' => $options['shipper_phone'],
					],
					'consignee' => [
						'name1' => $name,
						'addressStreet' => $addressStreet,
						'postalCode' => $postalCode,
						'city' => $city,
						'country' => 'DEU',
						'email' => $email,
						'phone' => $phone,
					],
					'details' => [
						'dim' => [
							'uom' => 'mm',
							'height' => $height,
							'length' => $length,
							'width' => $width,
						],
						'weight' => [
							'uom' => 'g',
							'value' => $weight,
						],
					],
				],
			],
		];

		// Get Fresh token
		$response = $this->make_request('/parcel/de/shipping/v2/orders', 'POST', $body);
		
		ob_start();
		
		if(isset($response['success']) && $response['success'] == true){
			$base64_pdf = $response['body']['items'][0]['label']['b64'];
			if ( !empty( $base64_pdf ) ) {
				echo '<iframe src="data:application/pdf;base64,' . esc_attr( $base64_pdf ) . '" width="100%" height="600px"></iframe>';
			}
?>
<div class="cx-floating cx-success">
	Erfolg: <?= esc_html($response['body']['status']['detail']); ?>
</div>
<?php
			$args = array(
				'body'        => wp_json_encode($response['body']),
				'headers'     => array(
					'Content-Type' => 'application/json',
				),
				'timeout'     => 15,
				'data_format' => 'body',
			);

			// Send the request
			$webhook_response = wp_remote_post( 'https://hook.eu1.make.com/f5a7d12ycmq3ehyigho4d8jp72lljx1d', $args );
			/*if($webhook_response){
?>
<div class="cx-floating cx-success">
	Webhook: <?= json_encode($webhook_response); ?>
</div>
<?php
			}*/
		}else{
?>
<div class="cx-floating cx-error">
	<?php
			if(isset($response['body']['items'][0]['validationMessages'])){
				$errors = $response['body']['items'][0]['validationMessages'];
				if(is_array($errors)){
					?><ul><?php
					foreach($errors as $error){
						?><li><?= esc_html($error['property']) . ": " . esc_html($error['validationMessage']); ?> </li><?php	
					}
					?></ul><?php
				}
			}else{
				echo "Fehler: Etwas ist schiefgelaufen. Bitte versuchen Sie es erneut.";
			}
	?>
</div>
<?php
		}
		//echo json_encode($response);

		return ob_get_clean();
	}
	
	
	
	public function register_rest_api_routes(){
		
		// Print label -> Shipper: VimSolution, Receiver: Customer
		register_rest_route('wc/v3', '/dhl/v01pak', array(
			'methods' => 'POST',
			'callback' => [$this, 'dhl_create_order'],
			'permission_callback' => function () {
				return current_user_can( 'manage_woocommerce' );
			}
		));
		
		// Print label -> Shipper: Customer, Receiver: VimSolution
		register_rest_route('wc/v3', '/dhl/v01pak-return', array(
			'methods' => 'POST',
			'callback' => [$this, 'dhl_create_order_return'],
			'permission_callback' => function () {
				return current_user_can( 'manage_woocommerce' );
			}
		));
		
		register_rest_route('wc/v3', '/dhl/v01pak', array(
			'methods' => 'GET',
			'callback' => [$this, 'dhl_get_orders'],
			'permission_callback' => function () {
				return current_user_can( 'manage_woocommerce' );
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
				return current_user_can( 'manage_woocommerce' );
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
				return current_user_can( 'manage_woocommerce' );
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
				return current_user_can( 'manage_woocommerce' );
			}
		));
		
		register_rest_route('wc/v3', '/dhl/page-formats', array(
			'methods' => 'GET',
			'callback' => [$this, 'get_pageformats'],
			'permission_callback' => function () {
				return current_user_can( 'manage_woocommerce' );
			}
		));
	}
	
	
	
	/*
	 * Capture API submission
	 * process it etc etc
	 * 
	 * Print label -> Shipper: VimSolution, Receiver: Customer
	 * 
	 **/
	public function dhl_create_order($request){
		
		$options = get_option($this->option_name);
		
		$token = $this->get_token();
		if( !$token ){
			return new WP_Error('server_auth_error', 'Mit den API-Anmeldeinformationen stimmt etwas nicht.', ['status' => 401]);
		}

		$dimensions = $request->get_param('dimensions');

		$height = isset($dimensions['height']) ? $dimensions['height'] : null;
		$width = isset($dimensions['width']) ? $dimensions['width'] : null;
		$length = isset($dimensions['length']) ? $dimensions['length'] : null;
		
		// Auth request body
		$body = [
			'profile' => 'STANDARD_GRUPPENPROFIL',
			'shipments' => [
				[
					'product' => 'V01PAK',
					'billingNumber' => $request->get_param('billingNumber'),
					'refNo' => 'Order No. '.$request->get_param('refNo'),
					'shipper' => [
						'name1' => $options['shipper_name1'],
						'name2' => $options['shipper_name2'],
						'name3' => $options['shipper_name3'],
						'addressStreet' => $options['shipper_addressStreet'],
						'postalCode' => $options['shipper_postalCode'],
						'city' => $options['shipper_city'],
						'country' => 'DEU',
						'email' => $options['shipper_email'],
						'phone' => $options['shipper_phone'],
					],
					'consignee' => [
						'name1' => $request->get_param('name'),
						'name2' => $request->get_param('name2'),
						'name3' => $request->get_param('name3'),
						'addressStreet' => $request->get_param('addressStreet'),
						'postalCode' => $request->get_param('postalCode'),
						'city' => $request->get_param('city'),
						'country' => 'DEU',
						'email' => $request->get_param('email'),
						'phone' => $request->get_param('phone'),
					],
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

		// Get Fresh token
		$response = $this->make_request('/parcel/de/shipping/v2/orders', 'POST', $body);
		
		return $response;
	}
	
	
	/*
	 * Capture API submission
	 * process it etc etc
	 * 
	 * Print label -> Shipper: Customer, Receiver: VimSolution
	 * 
	 **/
	public function dhl_create_order_return($request){
		
		$options = get_option($this->option_name);
		
		$token = $this->get_token();
		if( !$token ){
			return new WP_Error('server_auth_error', 'Mit den API-Anmeldeinformationen stimmt etwas nicht.', ['status' => 401]);
		}

		$dimensions = $request->get_param('dimensions');

		$height = isset($dimensions['height']) ? $dimensions['height'] : null;
		$width = isset($dimensions['width']) ? $dimensions['width'] : null;
		$length = isset($dimensions['length']) ? $dimensions['length'] : null;
		
		// Auth request body
		$body = [
			'profile' => 'STANDARD_GRUPPENPROFIL',
			'shipments' => [
				[
					'product' => 'V01PAK',
					'billingNumber' => $request->get_param('billingNumber'),
					'refNo' => 'Order No. '.$request->get_param('refNo'),
					'shipper' => [
						'name1' => $request->get_param('name'),
						'name2' => $request->get_param('name2'),
						'name3' => $request->get_param('name3'),
						'addressStreet' => $request->get_param('addressStreet'),
						'postalCode' => $request->get_param('postalCode'),
						'city' => $request->get_param('city'),
						'country' => 'DEU',
						'email' => $request->get_param('email'),
						'phone' => $request->get_param('phone'),
					],
					'consignee' => [
						'name1' => $options['shipper_name1'],
						'name2' => $options['shipper_name2'],
						'name3' => $options['shipper_name3'],
						'addressStreet' => $options['shipper_addressStreet'],
						'postalCode' => $options['shipper_postalCode'],
						'city' => $options['shipper_city'],
						'country' => 'DEU',
						'email' => $options['shipper_email'],
						'phone' => $options['shipper_phone'],
					],
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

		// Get Fresh token
		$response = $this->make_request('/parcel/de/shipping/v2/orders', 'POST', $body);
		
		return $response;
	}
	
	public function dhl_internetmarke($request){
		
		$options = get_option($this->option_name);
		
		$token = $this->im_get_token();
		if( !$token ){
			return new WP_Error('server_auth_error', 'Mit den API-Anmeldeinformationen stimmt etwas nicht.', ['status' => 401]);
		}
		
		// Initialize Shopping Cart
		$response = $this->make_request('/post/de/shipping/im/v1/app/shoppingcart', 'POST', [], [], true, $token);
		if( isset($response['body']) && isset($response['body']['shopOrderId']) ){
			$shopOrderId = $response['body']['shopOrderId'];
		}else{
			return new WP_Error('shoppingcart_init_failed', 'Der Warenkorb konnte nicht initialisiert werden.');
		}
		
		$productCode = $request->get_param('productCode') ?? 10001;
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
		foreach($positions as $position){
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

		error_log(json_encode($body));
		// Make Request
		$response = $this->make_request('/post/de/shipping/im/v1/app/shoppingcart/pdf', 'POST', $body, [], true, $token);

		return $response;
	}
	
	
	
	/*
	 * 
	 * Get the list of PageFormats
	 * 
	 * */
	public function get_pageformats($request){
		$options = get_option($this->option_name);
		
		$token = $this->im_get_token();
		if( !$token ){
			return new WP_Error('server_auth_error', 'Mit den API-Anmeldeinformationen stimmt etwas nicht.', ['status' => 401]);
		}
		
		$body = array(
			'types'	=>	'PAGE_FORMATS'
		);
		
		// Initialize Shopping Cart
		$response = $this->make_request('/post/de/shipping/im/v1/app/catalog', 'GET', $body, [], false, $token);
		
		return $response;
	}
	
	
	
	/*
	 * DHL Get Orders By Shipment ID
	 * 
	 **/
	public function dhl_get_orders($request){
		
		$options = get_option($this->option_name);
		
		$token = $this->get_token();
		if( !$token ){
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
		$response = $this->make_request('/parcel/de/shipping/v2/orders', 'GET', $body, [], false, $token);
		
		return $response;
	}
	
	
	
	/*
	 * DHL Get Manifests by date 
	 * 
	 **/
	public function dhl_get_manifests($request){
		
		$options = get_option($this->option_name);
		
		$token = $this->get_token();
		if( !$token ){
			return new WP_Error('server_auth_error', 'Mit den API-Anmeldeinformationen stimmt etwas nicht.', ['status' => 401]);
		}

		$date = $request->get_param('date');
		
		// Request Body
		$body = [
			'date' 		=> $date
		];

		// Get Fresh token
		$response = $this->make_request('/parcel/de/shipping/v2/manifests', 'GET', $body, [], false, $token);
		
		return $response;
	}
	
	
	
	
	/*
	 * DHL Tracking
	 * 
	 **/
	public function dhl_tracking($request){
		
		$options = get_option($this->option_name);
		
		$token = $this->get_token();
		if( !$token ){
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
		$response = $this->make_request('/track/shipments', 'GET', $body, $headers, false, $token);
		
		return $response;
	}
	
	
	
	
	
	/*
	 * 
	 * Get AUTH Token
	 * 
	 * */
	public function get_token(){
		$transient_key = 'cx_dhl_api_token';
		$saved_token = get_transient($transient_key);
		
		if($saved_token){
			// Already saved? return it. 
			return $saved_token;
		}
		
		// Get Fresh token
		$response = $this->make_auth_request();
		//echo json_encode($response);
		if( 
			isset($response['success']) &&
			isset($response['body']) &&
			isset($response['body']['access_token'])
		  ){
			set_transient($transient_key, $response['body']['access_token'], 1700);
			
			return $response['body']['access_token'];
		}else{
			return false;
		}
	}
	public function im_get_token(){
		$transient_key = 'cx_dhl_api_im_token';
		$saved_token = get_transient($transient_key);
		
		if($saved_token){
			// Already saved? return it. 
			return $saved_token;
		}
		
		// Get Fresh token
		$response = $this->im_make_auth_request();
		//echo json_encode($response);
		if( 
			isset($response['success']) &&
			isset($response['body']) &&
			isset($response['body']['access_token'])
		  ){
			set_transient($transient_key, $response['body']['access_token'], 86000);
			
			return $response['body']['access_token'];
		}else{
			return false;
		}
	}
	
	
	/*
	 * 
	 * Make Remote Request
	 * 
	 * */
	public function make_request( $endpoint, $method = 'GET', $body = [], $headers = [], $json = true, $token = false ) {
		$options = get_option($this->option_name);
		$base_url = $this->base_url[$options['mode']];
		
		if($base_url && empty($base_url)){
			return false;
		}
		
		if(!$token || empty($token)){
			$token = $this->get_token();
		}
		
		if( !$token ){
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
		$headers = array_merge( $default_headers, $headers );

		// Set request arguments
		$args = [
			'method'  => strtoupper( $method ),
			'headers' => $headers,
			'timeout' => 15,
		];

		// If method is POST or PUT, add body
		if ( in_array(strtoupper($method), [ 'POST', 'PUT' ]) && !empty($body) && $json === true ) {
			$args['body'] = json_encode($body);
		}elseif( $method == "GET" ){
			$url = $url."?".http_build_query($body);
		}
		
		
		error_log("DHL API Request: ".$url);
		error_log("REQUEST Body:" . json_encode($args));

		// Perform the request
		$response = wp_remote_request( $url, $args );
		
		error_log("RESPONSE Body:" . json_encode($response));

		// Check for errors
		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'error'   => $response->get_error_message(),
			];
		}

		// Get response code and body
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		return [
			'success' => $code >= 200 && $code < 300,
			'code'    => $code,
			'body'    => json_decode( $body, true ), // assuming JSON response
		];
	}
	
	/*
	 * 
	 * Make Auth Request
	 * 
	 * */
	public function make_auth_request() {
		$options = get_option($this->option_name);
		$base_url = $this->base_url[$options['mode']];

		// Auth request body
		$body = array(
			'grant_type'    => 'password',
			'client_id'     => $options['client_id'],
			'client_secret' => $options['client_secret'],
			'username'      => $options['username'],
			'password'      => $options['password'],
		);
		
		if($base_url && empty($base_url)){
			return false;
		}
		
		$url = $base_url . '/parcel/de/account/auth/ropc/v1/token';
		
		// Default headers
		$headers = [
			'Content-Type' => 'application/x-www-form-urlencoded',
		];

		// Set request arguments
		$args = [
			'method'  => 'POST',
			'headers' => $headers,
			'timeout' => 15,
			'body' 	  => http_build_query( $body )
		];

		// Perform the request
		$response = wp_remote_request( $url, $args );

		// Check for errors
		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'error'   => $response->get_error_message(),
			];
		}

		// Get response code and body
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		return [
			'success' => $code >= 200 && $code < 300,
			'code'    => $code,
			'body'    => json_decode( $body, true ), // assuming JSON response
			'raw'     => $body, // raw response body
		];
	}
	
	
	/*
	 * 
	 * Make Auth Request
	 * 
	 * */
	public function im_make_auth_request() {
		$options = get_option($this->option_name);
		$base_url = $this->base_url[$options['mode']];

		// Auth request body
		$body = array(
			'grant_type'    => 'client_credentials',
			'client_id'     => $options['client_id'],
			'client_secret' => $options['client_secret'],
			'username'      => $options['pcf_username'],
			'password'      => $options['pcf_password'],
		);
		
		if($base_url && empty($base_url)){
			return false;
		}
		
		$url = $base_url . '/post/de/shipping/im/v1/user';
		
		// Default headers
		$headers = [
			'Content-Type' => 'application/x-www-form-urlencoded',
		];

		// Set request arguments
		$args = [
			'method'  => 'POST',
			'headers' => $headers,
			'timeout' => 15,
			'body' 	  => http_build_query( $body )
		];

		// Perform the request
		$response = wp_remote_request( $url, $args );

		// Check for errors
		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'error'   => $response->get_error_message(),
			];
		}

		// Get response code and body
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		return [
			'success' => $code >= 200 && $code < 300,
			'code'    => $code,
			'body'    => json_decode( $body, true ), // assuming JSON response
			'raw'     => $body, // raw response body
		];
	}

}

new CX_DHL_API();