<?php
namespace Opencart\Catalog\Controller\Extension\PosConnector;

/**
 * Tells the POS an order changed. Checkout and the order screen run through
 * here, so this must stay quick and must never fail: a short timeout, nothing
 * thrown. A notice that does not arrive costs little, because the POS's
 * scheduled pull picks the order up anyway.
 *
 * The notice carries ids only. The POS reads the order back through the signed
 * API before acting on it.
 */
class Event extends \Opencart\System\Engine\Controller {
	/**
	 * catalog/model/checkout/order.addHistory/after
	 *
	 * @param array<int, mixed> $args [order_id, order_status_id, comment, notify, override]
	 */
	public function orderHistory(string &$route, array &$args, mixed &$output): void {
		try {
			$url = (string)$this->config->get('module_pos_connector_webhook_url');
			$secret = (string)$this->config->get('module_pos_connector_secret');

			if (!$this->config->get('module_pos_connector_status') || $url === '' || $secret === '' || !preg_match('#^https?://#i', $url) || !function_exists('curl_init')) {
				return;
			}

			$body = (string)json_encode([
				'event'    => 'order.updated',
				'order_id' => (int)($args[0] ?? 0),
				'status_id' => (int)($args[1] ?? 0)
			]);

			$timestamp = (string)time();
			$signature = hash_hmac('sha256', "POST\nwebhook\n" . $timestamp . "\n" . hash('sha256', $body), $secret);

			$curl = curl_init($url);

			curl_setopt_array($curl, [
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 2,
				CURLOPT_TIMEOUT        => 3,
				CURLOPT_HTTPHEADER     => [
					'Content-Type: application/json',
					'X-Connector-Timestamp: ' . $timestamp,
					'X-Connector-Signature: ' . $signature
				]
			]);

			curl_exec($curl);
			curl_close($curl);
		} catch (\Throwable $e) {
			$this->log->write('__COMPANY_NAME__ connector webhook: ' . $e->getMessage());
		}
	}
}
