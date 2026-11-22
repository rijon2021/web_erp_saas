<?php
namespace Opencart\Catalog\Controller\Extension\PosConnector;

/**
 * JSON gateway the POS talks to:
 *   POST index.php?route=extension/pos_connector/api&action=<name>
 *
 * OpenCart's own API only serves order and subscription calls and limits
 * callers by IP, so it cannot reach the catalogue or stock. This gateway
 * lives on the catalog side because the admin folder is usually renamed.
 *
 * Every call is signed: X-Connector-Timestamp plus X-Connector-Signature, the
 * HMAC-SHA256 of "POST\n<action>\n<timestamp>\n<sha256 of the raw body>" with
 * the secret shown on the module page.
 */
class Api extends \Opencart\System\Engine\Controller {
	public const VERSION = '1.2.0';

	/** How far the caller's clock may drift, in seconds. */
	private const MAX_SKEW = 300;

	public function index(): void {
		// OpenCart echoes PHP warnings when error display is on; anything
		// printed before the JSON would make the whole answer unreadable.
		ob_start();

		$action = (string)($this->request->get['action'] ?? '');
		$body = (string)file_get_contents('php://input');

		if (strtoupper((string)($this->request->server['REQUEST_METHOD'] ?? '')) !== 'POST') {
			$this->respond(405, ['ok' => false, 'error' => 'POST required']);

			return;
		}

		$denied = $this->denied($action, $body);

		if ($denied) {
			$this->respond($denied[0], ['ok' => false, 'code' => $denied[1], 'error' => $denied[2]]);

			return;
		}

		$input = $body === '' ? [] : json_decode($body, true);

		if (!is_array($input)) {
			$this->respond(400, ['ok' => false, 'error' => 'Body must be JSON']);

			return;
		}

		$this->load->model('extension/pos_connector/connector');
		$model = $this->model_extension_pos_connector_connector;

		try {
			switch ($action) {
				case 'ping':
					$result = $model->info() + ['connector' => self::VERSION, 'opencart' => VERSION];
					break;
				case 'webhook.set':
					$result = $model->setWebhookUrl((string)($input['url'] ?? ''));
					break;
				case 'products.list':
					$result = $model->listProducts((int)($input['page'] ?? 1), (int)($input['limit'] ?? 50), (int)($input['store_id'] ?? 0));
					break;
				case 'products.get':
					$result = $model->productPayloadById((int)($input['product_id'] ?? 0));
					break;
				case 'products.save':
					$result = $model->saveProducts((array)($input['items'] ?? []), (int)($input['store_id'] ?? 0));
					break;
				case 'stock.set':
					$result = $model->setStock((array)($input['items'] ?? []));
					break;
				case 'orders.list':
					$result = $model->listOrders($input);
					break;
				case 'orders.get':
					$result = $model->orderPayloadById((int)($input['order_id'] ?? 0));
					break;
				default:
					$this->respond(404, ['ok' => false, 'error' => 'Unknown action: ' . $action]);

					return;
			}
		} catch (\Throwable $e) {
			$this->log->write('__COMPANY_NAME__ connector ' . $action . ': ' . $e->getMessage());
			$this->respond(500, ['ok' => false, 'error' => $e->getMessage()]);

			return;
		}

		$this->respond(200, ['ok' => true] + $result);
	}

	/** @return array{0:int,1:string,2:string}|null [http status, code, message] when refused */
	private function denied(string $action, string $body): ?array {
		$secret = (string)$this->config->get('module_pos_connector_secret');

		if (!$this->config->get('module_pos_connector_status') || $secret === '') {
			return [503, 'disabled', 'The __COMPANY_NAME__ Connector module is disabled'];
		}

		$timestamp = (string)($this->request->server['HTTP_X_CONNECTOR_TIMESTAMP'] ?? '');
		$signature = (string)($this->request->server['HTTP_X_CONNECTOR_SIGNATURE'] ?? '');

		if ($action === '' || $timestamp === '' || $signature === '') {
			return [401, 'unsigned', 'Missing signature'];
		}

		if (abs(time() - (int)$timestamp) > self::MAX_SKEW) {
			return [401, 'clock', 'Request time is too far from the store clock'];
		}

		$expected = hash_hmac('sha256', "POST\n" . $action . "\n" . $timestamp . "\n" . hash('sha256', $body), $secret);

		if (!hash_equals($expected, $signature)) {
			return [401, 'signature', 'Bad signature'];
		}

		return null;
	}

	private function respond(int $status, array $data): void {
		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		http_response_code($status);

		$this->response->addHeader('Content-Type: application/json');
		$this->response->addHeader('Cache-Control: no-store');
		$this->response->setOutput((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
	}
}
