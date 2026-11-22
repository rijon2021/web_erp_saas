<?php
namespace Opencart\Admin\Controller\Extension\PosConnector\Module;

/**
 * Module page: on/off switch, and the two values the POS needs (store URL and
 * secret). The webhook address is written here by the POS when it connects.
 */
class PosConnector extends \Opencart\System\Engine\Controller {
	private const EVENT_CODE = 'pos_connector_order_history';

	public function index(): void {
		$this->load->language('extension/pos_connector/module/pos_connector');

		$this->document->setTitle($this->language->get('heading_title'));

		$token = 'user_token=' . $this->session->data['user_token'];

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token)],
			['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=module')],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/pos_connector/module/pos_connector', $token)]
		];

		$data['save'] = $this->url->link('extension/pos_connector/module/pos_connector.save', $token);
		$data['regenerate'] = $this->url->link('extension/pos_connector/module/pos_connector.regenerate', $token);
		$data['back'] = $this->url->link('marketplace/extension', $token . '&type=module');

		$data['module_pos_connector_status'] = (int)$this->config->get('module_pos_connector_status');
		$data['secret'] = (string)$this->config->get('module_pos_connector_secret');
		$data['webhook_url'] = (string)$this->config->get('module_pos_connector_webhook_url');
		$data['store_url'] = HTTP_CATALOG;

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/pos_connector/module/pos_connector', $data));
	}

	public function save(): void {
		$this->load->language('extension/pos_connector/module/pos_connector');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/pos_connector/module/pos_connector')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$this->store(['module_pos_connector_status' => (int)!empty($this->request->post['module_pos_connector_status'])]);

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput((string)json_encode($json));
	}

	/** A new secret disconnects the POS until it is pasted there too. */
	public function regenerate(): void {
		$this->load->language('extension/pos_connector/module/pos_connector');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/pos_connector/module/pos_connector')) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			$secret = bin2hex(random_bytes(32));

			$this->store(['module_pos_connector_secret' => $secret]);

			$json['secret'] = $secret;
			$json['success'] = $this->language->get('text_regenerated');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput((string)json_encode($json));
	}

	public function install(): void {
		$this->load->model('setting/event');

		$this->model_setting_event->deleteEventByCode(self::EVENT_CODE);
		$this->model_setting_event->addEvent([
			'code'        => self::EVENT_CODE,
			'description' => '__COMPANY_NAME__ Connector: notify __COMPANY_NAME__ when an order changes',
			'trigger'     => 'catalog/model/checkout/order.addHistory/after',
			'action'      => 'extension/pos_connector/event.orderHistory',
			'status'      => true,
			'sort_order'  => 0
		]);

		// Uninstalling clears these settings, so a module removed and added back
		// gets a new secret, which then has to be pasted into the POS again.
		$this->store([
			'module_pos_connector_status' => 1,
			'module_pos_connector_secret' => (string)$this->config->get('module_pos_connector_secret') ?: bin2hex(random_bytes(32))
		]);
	}

	public function uninstall(): void {
		$this->load->model('setting/event');

		$this->model_setting_event->deleteEventByCode(self::EVENT_CODE);
	}

	/** editSetting replaces the whole group, so the other keys are carried over. */
	private function store(array $changes): void {
		$this->load->model('setting/setting');

		$current = $this->model_setting_setting->getSetting('module_pos_connector');

		$this->model_setting_setting->editSetting('module_pos_connector', $changes + $current);
	}
}
