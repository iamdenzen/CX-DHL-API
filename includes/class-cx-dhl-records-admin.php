<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * Registers and renders the "DHL API Records" admin screen (sibling to
 * the existing Settings page).
 */
class CX_DHL_Records_Admin {

	private $records;

	public function __construct(CX_DHL_Records $records) {
		$this->records = $records;
		add_action('admin_menu', [$this, 'register_page']);
	}

	public function register_page() {
		add_options_page(
			'DHL API Records',
			'DHL API Records',
			'manage_options',
			'cx-dhl-api-records',
			[$this, 'render']
		);
	}

	public function render() {
		if (!current_user_can('manage_options')) {
			wp_die('Forbidden');
		}

		$list_table = new CX_DHL_Records_List_Table($this->records);
		$list_table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1>DHL API Records</h1>';
		echo '<style>.cx-dhl-status-success{color:#1a7f37;font-weight:600}.cx-dhl-status-failed{color:#b32d2e;font-weight:600}</style>';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="cx-dhl-api-records">';
		$list_table->search_box('Search', 'cx-dhl-records-search');
		$list_table->display();
		echo '</form>';
		echo '</div>';
	}
}
