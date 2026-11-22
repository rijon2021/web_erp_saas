<?php
/**
 * Connector module page for OpenCart 3.x: on/off switch, and the two
 * values the POS needs (store URL and secret). The webhook address is written
 * here by the POS when it connects.
 */
class ControllerExtensionModulePosConnector extends Controller {
	const EVENT_CODE = 'pos_connector_order_history';

	private $error = array();

	public function index() {
		$this->load->language('extension/module/pos_connector');

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('setting/setting');

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->store(array('module_pos_connector_status' => (int)!empty($this->request->post['module_pos_connector_status'])));

			$this->session->data['success'] = $this->language->get('text_success');

			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true));
		}

		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';

		$token = 'user_token=' . $this->session->data['user_token'];

		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token, true)),
			array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=module', true)),
			array('text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/module/pos_connector', $token, true))
		);

		$data['action'] = $this->url->link('extension/module/pos_connector', $token, true);
		$data['regenerate'] = $this->url->link('extension/module/pos_connector/regenerate', $token, true);
		$data['cancel'] = $this->url->link('marketplace/extension', $token . '&type=module', true);

		$data['module_pos_connector_status'] = (int)$this->config->get('module_pos_connector_status');
		$data['secret'] = (string)$this->config->get('module_pos_connector_secret');
		$data['webhook_url'] = (string)$this->config->get('module_pos_connector_webhook_url');
		$data['store_url'] = HTTP_CATALOG;

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/pos_connector', $data));
	}

	/** A new secret disconnects the POS until it is pasted there too. */
	public function regenerate() {
		$this->load->language('extension/module/pos_connector');

		$json = array();

		if (!$this->validate()) {
			$json['error'] = $this->error['warning'];
		} else {
			$secret = bin2hex(openssl_random_pseudo_bytes(32));

			$this->store(array('module_pos_connector_secret' => $secret));

			$json['secret'] = $secret;
			$json['success'] = $this->language->get('text_regenerated');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function install() {
		$this->load->model('setting/event');

		$this->model_setting_event->deleteEventByCode(self::EVENT_CODE);
		$this->model_setting_event->addEvent(self::EVENT_CODE, 'catalog/model/checkout/order/addOrderHistory/after', 'extension/module/pos_connector/orderHistory');

		// Uninstalling clears these settings, so a module removed and added back
		// gets a new secret, which then has to be pasted into the POS again.
		$secret = (string)$this->config->get('module_pos_connector_secret');

		$this->store(array(
			'module_pos_connector_status' => 1,
			'module_pos_connector_secret' => $secret !== '' ? $secret : bin2hex(openssl_random_pseudo_bytes(32))
		));
	}

	public function uninstall() {
		$this->load->model('setting/event');

		$this->model_setting_event->deleteEventByCode(self::EVENT_CODE);
	}

	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/module/pos_connector')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		return !$this->error;
	}

	/** editSetting replaces the whole group, so the other keys are carried over. */
	private function store(array $changes) {
		$this->load->model('setting/setting');

		$current = $this->model_setting_setting->getSetting('module_pos_connector');

		$this->model_setting_setting->editSetting('module_pos_connector', $changes + $current);
	}
}
