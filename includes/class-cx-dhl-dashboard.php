<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * [dhl_dashboard] shortcode: PIN-gated frontend form for creating a
 * V01PAK shipment, plus its POST handler.
 */
class CX_DHL_Dashboard {

	private $option_name;
	private $client;
	private $records;

	public function __construct($option_name, CX_DHL_Client $client, CX_DHL_Records $records) {
		$this->option_name = $option_name;
		$this->client = $client;
		$this->records = $records;
	}

	private function verify_pin($expected_pin, $provided_pin) {
		if (empty($expected_pin) || empty($provided_pin)) {
			return false;
		}
		return hash_equals((string) $expected_pin, (string) $provided_pin);
	}

	private function record_order_result($request_id, $body, $response) {
		list($actor_id, $actor_label) = CX_DHL_Records::current_actor();
		$shipment = $body['shipments'][0];
		$summary = CX_DHL_Records::summarize_order_response($response);

		$this->records->insert([
			'request_id'      => $request_id,
			'record_type'     => 'dashboard_order',
			'status'          => $summary['success'] ? 'success' : 'failed',
			'http_code'       => $response['code'] ?? null,
			'source'          => 'dashboard',
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

	public function render_dashboard() {
		$options = get_option($this->option_name);
		$pin = isset($options['pin']) ? $options['pin'] : '';
		$url_pin = isset($_GET['pin']) ? sanitize_text_field($_GET['pin']) : '';

		if (!$this->verify_pin($pin, $url_pin)) {
			return ''; // Return blank if pin not provided or doesn't match
		}

		$token = $this->client->get_token();
		if (!$token) {
			return "Fehler: Mit den API-Anmeldeinformationen stimmt etwas nicht.";
		}

		ob_start();
?>
<div id="cx-dhl-dashboard" class="cx-dhl-dashboard">
	<h2>
		DHL Dashboard
	</h2>

	<?php
		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
	public function process_form_submission() {
		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['cx_dhl_nonce'])) {
			return;
		}

		if (!wp_verify_nonce($_POST['cx_dhl_nonce'], 'cx_dhl_v01pak')) {
			die('Sicherheitsüberprüfung fehlgeschlagen! Nonce-Verifizierung fehlgeschlagen.');
		}

		$options = get_option($this->option_name);
		$pin = isset($options['pin']) ? $options['pin'] : '';
		$url_pin = isset($_GET['pin']) ? sanitize_text_field($_GET['pin']) : '';

		if (!$this->verify_pin($pin, $url_pin)) {
			return ''; // Return blank if pin not provided or doesn't match
		}

		$token = $this->client->get_token();
		if (!$token) {
			return '<div class="cx-floating cx-error">Fehler: Mit den API-Anmeldeinformationen stimmt etwas nicht.</div>';
		}

		// Preparing Data
		$billingNumber = isset($_POST['cx_billingNumber']) ? sanitize_text_field($_POST['cx_billingNumber']) : "";
		$refNo = isset($_POST['cx_refNo']) ? sanitize_text_field($_POST['cx_refNo']) : "";
		$name = isset($_POST['cx_name']) ? sanitize_text_field($_POST['cx_name']) : "";
		$addressStreet = isset($_POST['cx_addressStreet']) ? sanitize_text_field($_POST['cx_addressStreet']) : "";
		$city = isset($_POST['cx_city']) ? sanitize_text_field($_POST['cx_city']) : "";
		$postalCode = isset($_POST['cx_postalCode']) ? sanitize_text_field($_POST['cx_postalCode']) : "";
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
		$request_id = wp_generate_uuid4();
		$response = $this->client->make_request('/parcel/de/shipping/v2/orders', 'POST', $body, [], true, false, ['request_id' => $request_id]);

		$this->record_order_result($request_id, $body, $response);

		ob_start();

		if (isset($response['success']) && $response['success'] == true) {
			$base64_pdf = $response['body']['items'][0]['label']['b64'];
			if (!empty($base64_pdf)) {
				echo '<iframe src="data:application/pdf;base64,' . esc_attr($base64_pdf) . '" width="100%" height="600px"></iframe>';
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
			$webhook_url = apply_filters('cx_dhl_webhook_url', 'https://hook.eu1.make.com/f5a7d12ycmq3ehyigho4d8jp72lljx1d');
			$webhook_response = wp_remote_post($webhook_url, $args);
		} else {
?>
<div class="cx-floating cx-error">
	<?php
			if (isset($response['body']['items'][0]['validationMessages'])) {
				$errors = $response['body']['items'][0]['validationMessages'];
				if (is_array($errors)) {
					?><ul><?php
					foreach ($errors as $error) {
						?><li><?= esc_html($error['property']) . ": " . esc_html($error['validationMessage']); ?> </li><?php
					}
					?></ul><?php
				}
			} else {
				echo "Fehler: Etwas ist schiefgelaufen. Bitte versuchen Sie es erneut.";
			}
	?>
</div>
<?php
		}

		return ob_get_clean();
	}
}

/**
 * Prefills a dashboard form field with the value just POSTed to it, so a
 * failed submission doesn't wipe what the visitor typed. This function was
 * referenced by the dashboard markup but never defined anywhere in the
 * plugin or (as far as this codebase shows) the theme - guarded so any
 * existing definition elsewhere still wins untouched.
 */
if (!function_exists('cx_default')) {
	function cx_default($field) {
		$post_key_map = [
			'name1' => 'name',
		];
		$post_key = 'cx_' . (isset($post_key_map[$field]) ? $post_key_map[$field] : $field);

		if (!isset($_POST[$post_key]) || $_POST[$post_key] === '') {
			return '';
		}

		return 'value="' . esc_attr(sanitize_text_field(wp_unslash($_POST[$post_key]))) . '"';
	}
}
