<?php
/**
 * Connector for OpenCart 3.x.
 *
 * JSON gateway the POS talks to:
 *   POST index.php?route=extension/module/pos_connector/api&action=<name>
 *
 * OpenCart's own API only serves cart and order calls and limits callers by
 * IP, so it cannot reach the catalogue or stock. Every call is signed:
 * X-Connector-Timestamp plus X-Connector-Signature, the HMAC-SHA256 of
 * "POST\n<action>\n<timestamp>\n<sha256 of the raw body>" with the secret
 * shown on the module page. Same contract as the OpenCart 4 connector.
 */
class ControllerExtensionModulePosConnector extends Controller {
	const VERSION = '1.1.0';

	/** How far the caller's clock may drift, in seconds. */
	const MAX_SKEW = 300;

	public function api() {
		// OpenCart echoes PHP warnings when error display is on; anything
		// printed before the JSON would make the whole answer unreadable.
		ob_start();

		$action = isset($this->request->get['action']) ? (string)$this->request->get['action'] : '';
		$body = (string)file_get_contents('php://input');

		if (strtoupper(isset($this->request->server['REQUEST_METHOD']) ? $this->request->server['REQUEST_METHOD'] : '') != 'POST') {
			return $this->respond(405, array('ok' => false, 'error' => 'POST required'));
		}

		$denied = $this->denied($action, $body);

		if ($denied) {
			return $this->respond($denied[0], array('ok' => false, 'code' => $denied[1], 'error' => $denied[2]));
		}

		$input = $body === '' ? array() : json_decode($body, true);

		if (!is_array($input)) {
			return $this->respond(400, array('ok' => false, 'error' => 'Body must be JSON'));
		}

		$this->load->model('extension/module/pos_connector');
		$model = $this->model_extension_module_pos_connector;

		try {
			switch ($action) {
				case 'ping':
					$result = $model->info() + array('connector' => self::VERSION, 'opencart' => VERSION, 'variants' => 'option_values');
					break;
				case 'webhook.set':
					$result = $model->setWebhookUrl(isset($input['url']) ? (string)$input['url'] : '');
					break;
				case 'products.list':
					$result = $model->listProducts((int)$this->value($input, 'page', 1), (int)$this->value($input, 'limit', 50), (int)$this->value($input, 'store_id', 0));
					break;
				case 'products.get':
					$result = $model->productPayloadById((int)$this->value($input, 'product_id', 0));
					break;
				case 'products.save':
					$result = $model->saveProducts((array)$this->value($input, 'items', array()), (int)$this->value($input, 'store_id', 0));
					break;
				case 'stock.set':
					$result = $model->setStock((array)$this->value($input, 'items', array()));
					break;
				case 'orders.list':
					$result = $model->listOrders($input);
					break;
				case 'orders.get':
					$result = $model->orderPayloadById((int)$this->value($input, 'order_id', 0));
					break;
				default:
					return $this->respond(404, array('ok' => false, 'error' => 'Unknown action: ' . $action));
			}
		} catch (Exception $e) {
			$this->log->write('__COMPANY_NAME__ connector ' . $action . ': ' . $e->getMessage());

			return $this->respond(500, array('ok' => false, 'error' => $e->getMessage()));
		}

		return $this->respond(200, array('ok' => true) + $result);
	}

	/**
	 * catalog/model/checkout/order/addOrderHistory/after
	 *
	 * Tells the POS an order changed. Checkout and the order screen run through
	 * here, so this must stay quick and must never fail: a short timeout and
	 * nothing thrown. A notice that does not arrive costs little, because
	 * The POS's scheduled pull picks the order up anyway. The notice carries
	 * ids only; the POS reads the order back through the signed API.
	 */
	public function orderHistory(&$route, &$args, &$output) {
		try {
			$url = (string)$this->config->get('module_pos_connector_webhook_url');
			$secret = (string)$this->config->get('module_pos_connector_secret');

			if (!$this->config->get('module_pos_connector_status') || $url === '' || $secret === '' || !preg_match('#^https?://#i', $url) || !function_exists('curl_init')) {
				return;
			}

			$body = (string)json_encode(array(
				'event'     => 'order.updated',
				'order_id'  => (int)(isset($args[0]) ? $args[0] : 0),
				'status_id' => (int)(isset($args[1]) ? $args[1] : 0)
			));

			$timestamp = (string)time();
			$signature = hash_hmac('sha256', "POST\nwebhook\n" . $timestamp . "\n" . hash('sha256', $body), $secret);

			$curl = curl_init($url);

			curl_setopt_array($curl, array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 2,
				CURLOPT_TIMEOUT        => 3,
				CURLOPT_HTTPHEADER     => array(
					'Content-Type: application/json',
					'X-Connector-Timestamp: ' . $timestamp,
					'X-Connector-Signature: ' . $signature
				)
			));

			curl_exec($curl);
			curl_close($curl);
		} catch (Exception $e) {
			$this->log->write('__COMPANY_NAME__ connector webhook: ' . $e->getMessage());
		}
	}

	/** @return array|null [http status, code, message] when refused */
	private function denied($action, $body) {
		$secret = (string)$this->config->get('module_pos_connector_secret');

		if (!$this->config->get('module_pos_connector_status') || $secret === '') {
			return array(503, 'disabled', 'The __COMPANY_NAME__ Connector module is disabled');
		}

		$timestamp = isset($this->request->server['HTTP_X_CONNECTOR_TIMESTAMP']) ? (string)$this->request->server['HTTP_X_CONNECTOR_TIMESTAMP'] : '';
		$signature = isset($this->request->server['HTTP_X_CONNECTOR_SIGNATURE']) ? (string)$this->request->server['HTTP_X_CONNECTOR_SIGNATURE'] : '';

		if ($action === '' || $timestamp === '' || $signature === '') {
			return array(401, 'unsigned', 'Missing signature');
		}

		if (abs(time() - (int)$timestamp) > self::MAX_SKEW) {
			return array(401, 'clock', 'Request time is too far from the store clock');
		}

		$expected = hash_hmac('sha256', "POST\n" . $action . "\n" . $timestamp . "\n" . hash('sha256', $body), $secret);

		if (!hash_equals($expected, $signature)) {
			return array(401, 'signature', 'Bad signature');
		}

		return null;
	}

	private function value(array $input, $key, $default) {
		return isset($input[$key]) ? $input[$key] : $default;
	}

	private function respond($status, array $data) {
		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		http_response_code($status);

		// Older stores hold text that is not valid UTF-8; it must not empty the answer.
		$flags = JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : JSON_PARTIAL_OUTPUT_ON_ERROR);

		$this->response->addHeader('Content-Type: application/json');
		$this->response->addHeader('Cache-Control: no-store');
		$this->response->setOutput((string)json_encode($data, $flags));
	}
}
