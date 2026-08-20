<?php
/*
Plugin Name: CX DHL API
Description: Integrate DHL Parcel DE API with WordPress. Includes admin settings and frontend dashboard via shortcode.
Version: 1.2
Author: Creatricx
*/

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

define('CX_DHL_API_DIR', plugin_dir_path(__FILE__));

require_once CX_DHL_API_DIR . 'includes/class-cx-dhl-logger.php';
require_once CX_DHL_API_DIR . 'includes/class-cx-dhl-records.php';
require_once CX_DHL_API_DIR . 'includes/class-cx-dhl-client.php';
require_once CX_DHL_API_DIR . 'includes/class-cx-dhl-settings.php';
require_once CX_DHL_API_DIR . 'includes/class-cx-dhl-dashboard.php';
require_once CX_DHL_API_DIR . 'includes/class-cx-dhl-rest-controller.php';
require_once CX_DHL_API_DIR . 'includes/class-cx-dhl-records-list-table.php';
require_once CX_DHL_API_DIR . 'includes/class-cx-dhl-records-admin.php';
require_once CX_DHL_API_DIR . 'includes/class-cx-dhl-log-viewer.php';
require_once CX_DHL_API_DIR . 'includes/class-cx-dhl-plugin.php';

register_activation_hook(__FILE__, 'cx_dhl_api_activate');
register_deactivation_hook(__FILE__, 'cx_dhl_api_deactivate');

function cx_dhl_api_activate() {
	$logger = new CX_DHL_Logger('cx_dhl_api_settings');
	$records = new CX_DHL_Records($logger);
	$records->maybe_create_table();

	if (!wp_next_scheduled('cx_dhl_log_cleanup')) {
		wp_schedule_event(time(), 'daily', 'cx_dhl_log_cleanup');
	}
}

function cx_dhl_api_deactivate() {
	wp_clear_scheduled_hook('cx_dhl_log_cleanup');
}

new CX_DHL_API();
