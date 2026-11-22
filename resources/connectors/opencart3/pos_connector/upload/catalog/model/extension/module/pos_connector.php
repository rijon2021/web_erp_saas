<?php
/**
 * Reads and writes for the POS gateway on OpenCart 3.x.
 *
 * Writes use SQL because OpenCart's product model belongs to the admin
 * application and cannot be loaded from here. They follow what
 * admin/model/catalog/product.php stores, including text being kept
 * HTML-encoded: the admin form encodes every value and the storefront
 * decodes it, so a raw "&" here would print wrong in the shop.
 *
 * OpenCart 3 has no variants, only options, and keeps stock per option value
 * rather than per combination. So a product with exactly one select or radio
 * option is shown to the POS as variants: one per option value, whose
 * "product_id" is the product_option_value_id. A product with several
 * options cannot carry stock per combination; its options are created
 * without stock and the product's own quantity is what counts.
 *
 * What the POS owns on an update: model, SKU, name, price, status and stock.
 * Description, images and categories are only filled in when a product is
 * created, or when empty, so copy written in OpenCart is not overwritten.
 */
class ModelExtensionModulePosConnector extends Model {
	const MAX_LIMIT = 100;
	const MAX_IMAGE_BYTES = 5242880;

	// ---------------------------------------------------------------- info --

	public function info() {
		$stores = array(array('store_id' => 0, 'name' => $this->decode((string)$this->config->get('config_name'))));

		foreach ($this->db->query("SELECT store_id, name FROM `" . DB_PREFIX . "store` ORDER BY store_id")->rows as $row) {
			$stores[] = array('store_id' => (int)$row['store_id'], 'name' => $this->decode($row['name']));
		}

		$statuses = array();

		foreach ($this->db->query("SELECT order_status_id, name FROM `" . DB_PREFIX . "order_status` WHERE language_id = '" . $this->languageId() . "' ORDER BY order_status_id")->rows as $row) {
			$statuses[] = array('id' => (int)$row['order_status_id'], 'name' => $this->decode($row['name']));
		}

		return array(
			'store_url'           => defined('HTTP_SERVER') ? HTTP_SERVER : '',
			'stores'              => $stores,
			'currency'            => (string)$this->config->get('config_currency'),
			'order_statuses'      => $statuses,
			'processing_statuses' => array_map('intval', (array)$this->config->get('config_processing_status')),
			'complete_statuses'   => array_map('intval', (array)$this->config->get('config_complete_status')),
			'webhook_url'         => (string)$this->config->get('module_pos_connector_webhook_url'),
			'server_time'         => $this->now()
		);
	}

	public function setWebhookUrl($url) {
		if ($url !== '' && !preg_match('#^https?://#i', $url)) {
			throw new Exception('Webhook URL must be http or https');
		}

		$this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE store_id = '0' AND code = 'module_pos_connector' AND `key` = 'module_pos_connector_webhook_url'");
		$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET store_id = '0', code = 'module_pos_connector', `key` = 'module_pos_connector_webhook_url', value = '" . $this->db->escape($url) . "', serialized = '0'");

		return array('webhook_url' => $url);
	}

	// ------------------------------------------------------------ products --

	public function listProducts($page, $limit, $storeId) {
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$offset = (max(1, $page) - 1) * $limit;

		$from = "FROM `" . DB_PREFIX . "product` p INNER JOIN `" . DB_PREFIX . "product_to_store` s ON (s.product_id = p.product_id) WHERE s.store_id = '" . (int)$storeId . "'";

		$total = (int)$this->db->query("SELECT COUNT(*) AS total " . $from)->row['total'];
		$rows = $this->db->query("SELECT p.* " . $from . " ORDER BY p.product_id ASC LIMIT " . (int)$offset . ", " . (int)$limit)->rows;

		$products = array();

		foreach ($rows as $row) {
			$products[] = $this->productPayload($row);
		}

		return array('products' => $products, 'total' => $total, 'has_more' => ($offset + count($rows)) < $total);
	}

	public function productPayloadById($productId) {
		$row = $this->productRow($productId);

		return array('product' => $row ? $this->productPayload($row) : null);
	}

	private function productPayload(array $row) {
		$productId = (int)$row['product_id'];
		$description = $this->description($productId);
		$options = $this->selectOptions($productId);
		$variants = array();

		// One option: its values are the variants, each with its own stock.
		if (count($options) == 1) {
			$option = $options[0];

			foreach ($this->optionValueRows((int)$option['product_option_id']) as $value) {
				$variants[] = array(
					'product_id' => (int)$value['product_option_value_id'],
					'model'      => $this->variantModel((string)$row['model'], (string)$value['name']),
					'sku'        => '',
					'codes'      => array(),
					'name'       => $this->decode($value['name']),
					'values'     => array($this->decode($option['name']) => $this->decode($value['name'])),
					'price'      => $this->optionPrice((float)$row['price'], $value),
					'quantity'   => (int)$value['quantity'],
					'status'     => (int)$row['status'],
					'image'      => ''
				);
			}
		}

		return array(
			'product_id'    => $productId,
			'model'         => $this->decode((string)$row['model']),
			'sku'           => $this->decode((string)$row['sku']),
			'codes'         => $this->rowCodes($row),
			'name'          => $this->decode(isset($description['name']) ? (string)$description['name'] : ''),
			'description'   => $this->decode(isset($description['description']) ? (string)$description['description'] : ''),
			'price'         => (float)$row['price'],
			'quantity'      => (int)$row['quantity'],
			'subtract'      => (int)$row['subtract'],
			'status'        => (int)$row['status'],
			'weight'        => (float)$row['weight'],
			'image'         => $this->imageUrl((string)$row['image']),
			'category'      => $this->categoryName($productId),
			'has_options'   => count($options) > 1 || (!$options && $this->hasOptions($productId)),
			'variants'      => $variants,
			'date_modified' => (string)$row['date_modified']
		);
	}

	public function saveProducts(array $items, $storeId) {
		if (count($items) > self::MAX_LIMIT) {
			throw new Exception('At most ' . self::MAX_LIMIT . ' items per call');
		}

		$results = array();

		foreach ($items as $item) {
			$item = (array)$item;
			$ref = isset($item['ref']) ? (string)$item['ref'] : '';

			try {
				$results[] = array('ref' => $ref, 'ok' => true) + $this->saveProduct($item, $storeId);
			} catch (Exception $e) {
				$results[] = array('ref' => $ref, 'ok' => false, 'error' => $e->getMessage());
			}
		}

		$this->cache->delete('product');

		return array('results' => $results);
	}

	private function saveProduct(array $item, $storeId) {
		$model = trim(isset($item['model']) ? (string)$item['model'] : '');
		$name = trim(isset($item['name']) ? (string)$item['name'] : '');

		if ($model === '' || $name === '') {
			throw new Exception('Model and name are required');
		}

		$warnings = array();
		$existing = $this->findProduct((int)$this->value($item, 'product_id', 0), $model, $this->itemCodes($item));

		if ($existing) {
			$productId = (int)$existing['product_id'];
			$action = (int)$this->value($item, 'product_id', 0) === $productId ? 'updated' : 'linked';

			$this->updateProduct($productId, $item, $model, $name);

			// A product found by its model may belong to another store only.
			$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "product_to_store` SET product_id = '" . (int)$productId . "', store_id = '" . (int)$storeId . "'");

			if (empty($existing['image']) && !empty($item['image_url'])) {
				$this->attachImage($productId, (string)$item['image_url'], $warnings);
			}
		} else {
			$productId = $this->createProduct($item, $model, $name, $storeId, $warnings);
			$action = 'created';
		}

		$variants = array();

		if (!empty($item['variants'])) {
			$variants = $this->saveVariants($productId, (array)$this->value($item, 'options', array()), (array)$item['variants'], $warnings);
		}

		return array('product_id' => $productId, 'action' => $action, 'variants' => $variants, 'warnings' => $warnings);
	}

	private function createProduct(array $item, $model, $name, $storeId, array &$warnings) {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "product` SET
			model = '" . $this->db->escape($this->encode($model)) . "',
			" . $this->codeAssignments($this->itemCodes($item), true) . ",
			location = '',
			quantity = '" . (int)$this->value($item, 'quantity', 0) . "',
			minimum = '1',
			subtract = '" . (int)$this->value($item, 'subtract', 1) . "',
			stock_status_id = '" . (int)$this->config->get('config_stock_status_id') . "',
			date_available = NOW(),
			manufacturer_id = '0',
			shipping = '1',
			price = '" . (float)$this->value($item, 'price', 0) . "',
			points = '0',
			weight = '" . (float)$this->value($item, 'weight', 0) . "',
			weight_class_id = '" . (int)$this->config->get('config_weight_class_id') . "',
			length = '0', width = '0', height = '0',
			length_class_id = '" . (int)$this->config->get('config_length_class_id') . "',
			status = '" . (int)!empty($item['status']) . "',
			tax_class_id = '0',
			sort_order = '0',
			image = '',
			viewed = '0',
			date_added = NOW(),
			date_modified = NOW()");

		$productId = (int)$this->db->getLastId();

		$this->writeDescriptions($productId, $name, (string)$this->value($item, 'description', ''));
		$this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_store` SET product_id = '" . $productId . "', store_id = '" . (int)$storeId . "'");
		$this->writeSeoUrl($productId, $name, $storeId);

		if (!empty($item['category'])) {
			$categoryId = $this->categoryId((string)$item['category'], $storeId);

			$this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_category` SET product_id = '" . $productId . "', category_id = '" . $categoryId . "'");
		}

		if (!empty($item['image_url'])) {
			$this->attachImage($productId, (string)$item['image_url'], $warnings);
		}

		return $productId;
	}

	private function updateProduct($productId, array $item, $model, $name) {
		$sql = "model = '" . $this->db->escape($this->encode($model)) . "', price = '" . (float)$this->value($item, 'price', 0) . "', status = '" . (int)!empty($item['status']) . "', date_modified = NOW()";

		if (isset($item['subtract'])) {
			$sql .= ", subtract = '" . (int)!empty($item['subtract']) . "'";
		}

		$codeSql = $this->codeAssignments($this->itemCodes($item), false);

		if ($codeSql !== '') {
			$sql .= ", " . $codeSql;
		}

		$this->db->query("UPDATE `" . DB_PREFIX . "product` SET " . $sql . " WHERE product_id = '" . (int)$productId . "'");

		$current = $this->description($productId);
		$fields = "name = '" . $this->db->escape($this->encode($name)) . "'";

		if (empty($current['meta_title'])) {
			$fields .= ", meta_title = '" . $this->db->escape($this->encode($name)) . "'";
		}

		if (empty($current['description']) && !empty($item['description'])) {
			$fields .= ", description = '" . $this->db->escape($this->encode((string)$item['description'])) . "'";
		}

		$this->db->query("UPDATE `" . DB_PREFIX . "product_description` SET " . $fields . " WHERE product_id = '" . (int)$productId . "' AND language_id = '" . $this->languageId() . "'");

		// No text in the store's language yet (catalogue made under another one).
		if (!$this->db->countAffected() && !$this->db->query("SELECT 1 FROM `" . DB_PREFIX . "product_description` WHERE product_id = '" . (int)$productId . "' AND language_id = '" . $this->languageId() . "'")->num_rows) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "product_description` SET product_id = '" . (int)$productId . "', language_id = '" . $this->languageId() . "', name = '" . $this->db->escape($this->encode($name)) . "', description = '" . $this->db->escape($this->encode((string)$this->value($item, 'description', ''))) . "', tag = '', meta_title = '" . $this->db->escape($this->encode($name)) . "', meta_description = '', meta_keyword = ''");
		}
	}

	/**
	 * POS variants onto OpenCart 3 options. With one option the values carry
	 * the stock (subtract 1) and each value is reported back as a variant. With
	 * more, the options are still created, so the shopper can choose, but no
	 * value takes stock: the product's quantity is the only figure, and the POS
	 * is told so.
	 */
	private function saveVariants($productId, array $options, array $variants, array &$warnings) {
		$single = count($options) == 1;
		$map = array();

		foreach ($options as $option) {
			$option = (array)$option;
			$optionName = trim(isset($option['name']) ? (string)$option['name'] : '');

			if ($optionName === '') {
				continue;
			}

			$optionId = $this->optionId($optionName);
			$productOptionId = $this->productOptionId($productId, $optionId);

			$map[$optionName] = array('product_option_id' => $productOptionId, 'values' => array());

			foreach ((array)$this->value($option, 'values', array()) as $value) {
				$value = trim((string)$value);

				if ($value !== '') {
					$map[$optionName]['values'][$value] = $this->productOptionValueId($productId, $productOptionId, $optionId, $this->optionValueId($optionId, $value), $single);
				}
			}
		}

		if (!$single) {
			$warnings[] = 'OpenCart 3 keeps stock per option value, not per combination: the options were created but stock stays on the product as a whole';

			return array();
		}

		$product = $this->productRow($productId);
		$results = array();

		foreach ($variants as $variant) {
			$variant = (array)$variant;
			$ref = isset($variant['ref']) ? (string)$variant['ref'] : '';

			try {
				$results[] = array('ref' => $ref, 'ok' => true) + $this->saveVariant($product, $map, $variant);
			} catch (Exception $e) {
				$results[] = array('ref' => $ref, 'ok' => false, 'error' => $e->getMessage());
			}
		}

		return $results;
	}

	private function saveVariant(array $product, array $map, array $variant) {
		$valueId = 0;

		foreach ((array)$this->value($variant, 'values', array()) as $optionName => $value) {
			if (isset($map[$optionName]['values'][$value])) {
				$valueId = (int)$map[$optionName]['values'][$value];
			}
		}

		if (!$valueId) {
			throw new Exception('Variant ' . (isset($variant['model']) ? $variant['model'] : '') . ' names no option value');
		}

		// The value's price is the difference from the product's price.
		$difference = round((float)$this->value($variant, 'price', $product['price']) - (float)$product['price'], 4);

		$this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET quantity = '" . (int)$this->value($variant, 'quantity', 0) . "', subtract = '1', price = '" . abs($difference) . "', price_prefix = '" . ($difference < 0 ? '-' : '+') . "' WHERE product_option_value_id = '" . $valueId . "'");

		return array('product_id' => $valueId, 'action' => 'updated');
	}

	// --------------------------------------------------------------- stock --

	/** Items carry type "variant" when the id is a product_option_value_id. */
	public function setStock(array $items) {
		if (count($items) > self::MAX_LIMIT) {
			throw new Exception('At most ' . self::MAX_LIMIT . ' items per call');
		}

		$updated = 0;
		$missing = array();

		foreach ($items as $item) {
			$item = (array)$item;
			$id = (int)$this->value($item, 'product_id', 0);
			$quantity = (int)$this->value($item, 'quantity', 0);

			if ($this->value($item, 'type', 'product') === 'variant') {
				if (!$id || !$this->db->query("SELECT product_option_value_id FROM `" . DB_PREFIX . "product_option_value` WHERE product_option_value_id = '" . $id . "'")->num_rows) {
					$missing[] = $id;

					continue;
				}

				$this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET quantity = '" . $quantity . "', subtract = '1' WHERE product_option_value_id = '" . $id . "'");
			} else {
				if (!$id || !$this->productRow($id)) {
					$missing[] = $id;

					continue;
				}

				$sql = "quantity = '" . $quantity . "'";

				if (isset($item['subtract'])) {
					$sql .= ", subtract = '" . (int)!empty($item['subtract']) . "'";
				}

				$this->db->query("UPDATE `" . DB_PREFIX . "product` SET " . $sql . " WHERE product_id = '" . $id . "'");
			}

			$updated++;
		}

		$this->cache->delete('product');

		return array('updated' => $updated, 'missing' => $missing);
	}

	// -------------------------------------------------------------- orders --

	/**
	 * Orders by id, a stable order for paging. Status 0 is a checkout that was
	 * never confirmed: not an order yet. "Changed since" looks at the history
	 * as well as date_modified.
	 */
	public function listOrders(array $filter) {
		$limit = max(1, min(self::MAX_LIMIT, (int)$this->value($filter, 'limit', 25)));
		$offset = (max(1, (int)$this->value($filter, 'page', 1)) - 1) * $limit;

		$where = "order_status_id > '0' AND store_id = '" . (int)$this->value($filter, 'store_id', 0) . "'";

		if (!empty($filter['modified_since'])) {
			$since = $this->db->escape((string)$filter['modified_since']);

			$where .= " AND (date_modified >= '" . $since . "' OR order_id IN (SELECT order_id FROM `" . DB_PREFIX . "order_history` WHERE date_added >= '" . $since . "'))";
		}

		if (!empty($filter['created_since'])) {
			$where .= " AND date_added >= '" . $this->db->escape((string)$filter['created_since']) . "'";
		}

		$total = (int)$this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "order` WHERE " . $where)->row['total'];
		$rows = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE " . $where . " ORDER BY order_id ASC LIMIT " . (int)$offset . ", " . (int)$limit)->rows;

		$orders = array();

		foreach ($rows as $row) {
			$orders[] = $this->orderPayload($row);
		}

		return array('orders' => $orders, 'total' => $total, 'has_more' => ($offset + count($rows)) < $total, 'server_time' => $this->now());
	}

	public function orderPayloadById($orderId) {
		$row = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE order_id = '" . (int)$orderId . "'")->row;

		return array('order' => $row ? $this->orderPayload($row) : null);
	}

	private function orderPayload(array $row) {
		$orderId = (int)$row['order_id'];
		$lines = array();

		foreach ($this->db->query("SELECT * FROM `" . DB_PREFIX . "order_product` WHERE order_id = '" . $orderId . "'")->rows as $line) {
			$options = array();
			$variantId = 0;

			foreach ($this->db->query("SELECT oo.*, pov.subtract FROM `" . DB_PREFIX . "order_option` oo LEFT JOIN `" . DB_PREFIX . "product_option_value` pov ON (pov.product_option_value_id = oo.product_option_value_id) WHERE oo.order_id = '" . $orderId . "' AND oo.order_product_id = '" . (int)$line['order_product_id'] . "'")->rows as $option) {
				$options[] = array(
					'product_option_id'       => (int)$option['product_option_id'],
					'product_option_value_id' => (int)$option['product_option_value_id'],
					'name'                    => $this->decode((string)$option['name']),
					'value'                   => $this->decode((string)$option['value'])
				);

				// The value that carries stock names the variant.
				if (!$variantId && !empty($option['subtract']) && (int)$option['product_option_value_id']) {
					$variantId = (int)$option['product_option_value_id'];
				}
			}

			$productId = (int)$line['product_id'];
			$product = $this->productRow($productId);

			$lines[] = array(
				'product_id'         => $productId,
				'master_id'          => 0,
				'variant_product_id' => $variantId,
				'model'              => $this->decode((string)$line['model']),
				'sku'                => $product ? $this->decode((string)$product['sku']) : '',
				'codes'              => $product ? $this->rowCodes($product) : array(),
				'name'               => $this->decode((string)$line['name']),
				'quantity'           => (float)$line['quantity'],
				'price'              => (float)$line['price'],
				'tax'                => (float)$line['tax'],
				'total'              => (float)$line['total'],
				'options'            => $options
			);
		}

		$totals = array();

		foreach ($this->db->query("SELECT code, title, value FROM `" . DB_PREFIX . "order_total` WHERE order_id = '" . $orderId . "' ORDER BY sort_order ASC")->rows as $total) {
			$totals[] = array('code' => (string)$total['code'], 'title' => $this->decode((string)$total['title']), 'value' => (float)$total['value']);
		}

		return array(
			'order_id'        => $orderId,
			'store_id'        => (int)$row['store_id'],
			'status_id'       => (int)$row['order_status_id'],
			'reference'       => $row['invoice_no'] ? $this->decode((string)$row['invoice_prefix']) . $row['invoice_no'] : (string)$orderId,
			'customer_id'     => (int)$row['customer_id'],
			'firstname'       => $this->decode((string)$row['firstname']),
			'lastname'        => $this->decode((string)$row['lastname']),
			'email'           => (string)$row['email'],
			'telephone'       => (string)$row['telephone'],
			'address'         => $this->address($row),
			'payment_method'  => $this->decode((string)$row['payment_method']),
			'shipping_method' => $this->decode((string)$row['shipping_method']),
			'comment'         => $this->decode((string)$row['comment']),
			'total'           => (float)$row['total'],
			'currency'        => (string)$row['currency_code'],
			'currency_value'  => (float)$row['currency_value'],
			'date_added'      => (string)$row['date_added'],
			'date_modified'   => (string)$row['date_modified'],
			'lines'           => $lines,
			'totals'          => $totals
		);
	}

	/** The shipping address when there is one, otherwise the billing address. */
	private function address(array $row) {
		$prefix = !empty($row['shipping_address_1']) ? 'shipping_' : 'payment_';

		return array(
			'address_1' => $this->decode((string)$row[$prefix . 'address_1']),
			'address_2' => $this->decode((string)$row[$prefix . 'address_2']),
			'city'      => $this->decode((string)$row[$prefix . 'city']),
			'zone'      => $this->decode((string)$row[$prefix . 'zone']),
			'postcode'  => (string)$row[$prefix . 'postcode'],
			'country'   => $this->decode((string)$row[$prefix . 'country'])
		);
	}

	// ------------------------------------------------------------- helpers --

	private function productRow($productId) {
		if (!$productId) {
			return array();
		}

		return $this->db->query("SELECT * FROM `" . DB_PREFIX . "product` WHERE product_id = '" . (int)$productId . "'")->row;
	}

	private function findProduct($productId, $model, array $codes) {
		$row = $this->productRow($productId);

		if ($row) {
			return $row;
		}

		$row = $this->db->query("SELECT * FROM `" . DB_PREFIX . "product` WHERE model = '" . $this->db->escape($this->encode($model)) . "' ORDER BY product_id ASC LIMIT 1")->row;

		foreach ($this->cleanCodes($codes) as $field => $value) {
			if ($row) {
				break;
			}

			$row = $this->db->query("SELECT * FROM `" . DB_PREFIX . "product` WHERE " . $field . " = '" . $this->db->escape($this->encode($value)) . "' ORDER BY product_id ASC LIMIT 1")->row;
		}

		return $row;
	}

	/** OpenCart's identifier columns as the POS names them. */
	private static $codeFields = array('sku', 'upc', 'ean', 'jan', 'isbn', 'mpn');

	/** The identifiers on a product row: every field present, blank when unset. */
	private function rowCodes(array $row) {
		$codes = array();

		foreach (self::$codeFields as $field) {
			$codes[$field] = $this->decode(isset($row[$field]) ? (string)$row[$field] : '');
		}

		return $codes;
	}

	/** The identifiers an item from the POS carries: its codes map, or the older sku key alone. */
	private function itemCodes(array $item) {
		$codes = isset($item['codes']) ? (array)$item['codes'] : array();

		if (!isset($codes['sku']) && isset($item['sku'])) {
			$codes['sku'] = (string)$item['sku'];
		}

		return $this->cleanCodes($codes);
	}

	/** Known fields with a non-blank value only. */
	private function cleanCodes(array $codes) {
		$clean = array();

		foreach (self::$codeFields as $field) {
			$value = isset($codes[$field]) ? trim((string)$codes[$field]) : '';

			if ($value !== '') {
				$clean[$field] = $value;
			}
		}

		return $clean;
	}

	/** "sku = 'x', isbn = 'y'" for the fields given; every field, blank when not given, with $withBlanks (an INSERT). */
	private function codeAssignments(array $codes, $withBlanks) {
		$codes = $this->cleanCodes($codes);
		$parts = array();

		foreach (self::$codeFields as $field) {
			if (isset($codes[$field])) {
				$parts[] = $field . " = '" . $this->db->escape($this->encode($codes[$field])) . "'";
			} elseif ($withBlanks) {
				$parts[] = $field . " = ''";
			}
		}

		return implode(', ', $parts);
	}

	/** The product's select and radio options, with their names. */
	private function selectOptions($productId) {
		return $this->db->query("SELECT po.product_option_id, po.option_id, od.name FROM `" . DB_PREFIX . "product_option` po INNER JOIN `" . DB_PREFIX . "option` o ON (o.option_id = po.option_id) INNER JOIN `" . DB_PREFIX . "option_description` od ON (od.option_id = o.option_id AND od.language_id = '" . $this->languageId() . "') WHERE po.product_id = '" . (int)$productId . "' AND o.type IN ('select', 'radio') ORDER BY po.product_option_id")->rows;
	}

	private function optionValueRows($productOptionId) {
		return $this->db->query("SELECT pov.*, ovd.name FROM `" . DB_PREFIX . "product_option_value` pov INNER JOIN `" . DB_PREFIX . "option_value_description` ovd ON (ovd.option_value_id = pov.option_value_id AND ovd.language_id = '" . $this->languageId() . "') WHERE pov.product_option_id = '" . (int)$productOptionId . "' ORDER BY pov.product_option_value_id")->rows;
	}

	private function hasOptions($productId) {
		return (bool)$this->db->query("SELECT 1 FROM `" . DB_PREFIX . "product_option` WHERE product_id = '" . (int)$productId . "' LIMIT 1")->num_rows;
	}

	/** "MODEL-red-l": a code the POS can keep as the variant's, the way 4.x variants have their own model. */
	private function variantModel($model, $valueName) {
		$slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($this->decode($valueName))), '-');

		return $this->decode($model) . '-' . ($slug !== '' ? $slug : 'value');
	}

	private function optionPrice($base, array $value) {
		$difference = (float)$value['price'];

		return round($value['price_prefix'] === '-' ? $base - $difference : $base + $difference, 4);
	}

	private function description($productId) {
		return $this->db->query("SELECT * FROM `" . DB_PREFIX . "product_description` WHERE product_id = '" . (int)$productId . "' ORDER BY (language_id = '" . $this->languageId() . "') DESC LIMIT 1")->row;
	}

	private function writeDescriptions($productId, $name, $description) {
		$name = $this->db->escape($this->encode($name));
		$description = $this->db->escape($this->encode($description));

		foreach ($this->languageIds() as $languageId) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "product_description` SET product_id = '" . (int)$productId . "', language_id = '" . $languageId . "', name = '" . $name . "', description = '" . $description . "', tag = '', meta_title = '" . $name . "', meta_description = '', meta_keyword = ''");
		}
	}

	/** The product id keeps the keyword unique within a store and language. */
	private function writeSeoUrl($productId, $name, $storeId) {
		$slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($this->decode($name))), '-');
		$keyword = ($slug !== '' ? substr($slug, 0, 80) : 'product') . '-' . (int)$productId;

		foreach ($this->languageIds() as $languageId) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "seo_url` SET store_id = '" . (int)$storeId . "', language_id = '" . $languageId . "', query = 'product_id=" . (int)$productId . "', keyword = '" . $this->db->escape($keyword) . "'");
		}
	}

	private function categoryName($productId) {
		$row = $this->db->query("SELECT cd.name FROM `" . DB_PREFIX . "product_to_category` pc INNER JOIN `" . DB_PREFIX . "category_description` cd ON (cd.category_id = pc.category_id) WHERE pc.product_id = '" . (int)$productId . "' AND cd.language_id = '" . $this->languageId() . "' LIMIT 1")->row;

		return $this->decode(isset($row['name']) ? (string)$row['name'] : '');
	}

	private function categoryId($name, $storeId) {
		$encoded = $this->db->escape($this->encode($name));

		$row = $this->db->query("SELECT category_id FROM `" . DB_PREFIX . "category_description` WHERE name = '" . $encoded . "' AND language_id = '" . $this->languageId() . "' LIMIT 1")->row;

		if ($row) {
			return (int)$row['category_id'];
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "category` SET image = '', parent_id = '0', top = '0', `column` = '1', sort_order = '0', status = '1', date_added = NOW(), date_modified = NOW()");

		$categoryId = (int)$this->db->getLastId();

		foreach ($this->languageIds() as $languageId) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "category_description` SET category_id = '" . $categoryId . "', language_id = '" . $languageId . "', name = '" . $encoded . "', description = '', meta_title = '" . $encoded . "', meta_description = '', meta_keyword = ''");
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "category_path` SET category_id = '" . $categoryId . "', path_id = '" . $categoryId . "', level = '0'");
		$this->db->query("INSERT INTO `" . DB_PREFIX . "category_to_store` SET category_id = '" . $categoryId . "', store_id = '" . (int)$storeId . "'");

		$this->cache->delete('category');

		return $categoryId;
	}

	private function optionId($name) {
		$encoded = $this->db->escape($this->encode($name));

		$row = $this->db->query("SELECT od.option_id FROM `" . DB_PREFIX . "option_description` od INNER JOIN `" . DB_PREFIX . "option` o ON (o.option_id = od.option_id) WHERE od.name = '" . $encoded . "' AND od.language_id = '" . $this->languageId() . "' AND o.type IN ('select', 'radio') LIMIT 1")->row;

		if ($row) {
			return (int)$row['option_id'];
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "option` SET type = 'select', sort_order = '0'");

		$optionId = (int)$this->db->getLastId();

		foreach ($this->languageIds() as $languageId) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "option_description` SET option_id = '" . $optionId . "', language_id = '" . $languageId . "', name = '" . $encoded . "'");
		}

		return $optionId;
	}

	private function optionValueId($optionId, $name) {
		$encoded = $this->db->escape($this->encode($name));

		$row = $this->db->query("SELECT option_value_id FROM `" . DB_PREFIX . "option_value_description` WHERE option_id = '" . (int)$optionId . "' AND name = '" . $encoded . "' AND language_id = '" . $this->languageId() . "' LIMIT 1")->row;

		if ($row) {
			return (int)$row['option_value_id'];
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "option_value` SET option_id = '" . (int)$optionId . "', image = '', sort_order = '0'");

		$optionValueId = (int)$this->db->getLastId();

		foreach ($this->languageIds() as $languageId) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "option_value_description` SET option_value_id = '" . $optionValueId . "', language_id = '" . $languageId . "', option_id = '" . (int)$optionId . "', name = '" . $encoded . "'");
		}

		return $optionValueId;
	}

	private function productOptionId($productId, $optionId) {
		$row = $this->db->query("SELECT product_option_id FROM `" . DB_PREFIX . "product_option` WHERE product_id = '" . (int)$productId . "' AND option_id = '" . (int)$optionId . "' LIMIT 1")->row;

		if ($row) {
			return (int)$row['product_option_id'];
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "product_option` SET product_id = '" . (int)$productId . "', option_id = '" . (int)$optionId . "', value = '', required = '1'");

		return (int)$this->db->getLastId();
	}

	private function productOptionValueId($productId, $productOptionId, $optionId, $optionValueId, $tracked) {
		$row = $this->db->query("SELECT product_option_value_id FROM `" . DB_PREFIX . "product_option_value` WHERE product_option_id = '" . (int)$productOptionId . "' AND option_value_id = '" . (int)$optionValueId . "' LIMIT 1")->row;

		if ($row) {
			return (int)$row['product_option_value_id'];
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "product_option_value` SET product_option_id = '" . (int)$productOptionId . "', product_id = '" . (int)$productId . "', option_id = '" . (int)$optionId . "', option_value_id = '" . (int)$optionValueId . "', quantity = '0', subtract = '" . ($tracked ? 1 : 0) . "', price = '0', price_prefix = '+', points = '0', points_prefix = '+', weight = '0', weight_prefix = '+'");

		return (int)$this->db->getLastId();
	}

	private function attachImage($productId, $url, array &$warnings) {
		try {
			$path = $this->downloadImage($url);

			$this->db->query("UPDATE `" . DB_PREFIX . "product` SET image = '" . $this->db->escape($path) . "' WHERE product_id = '" . (int)$productId . "'");
		} catch (Exception $e) {
			// A picture that cannot be fetched is no reason to lose the product.
			$warnings[] = 'Image not saved: ' . $e->getMessage();
		}
	}

	private function downloadImage($url) {
		if (!preg_match('#^https?://#i', $url) || !function_exists('curl_init')) {
			throw new Exception('Unsupported image URL');
		}

		$curl = curl_init($url);

		curl_setopt_array($curl, array(
			CURLOPT_RETURNTRANSFER  => true,
			CURLOPT_FOLLOWLOCATION  => true,
			CURLOPT_MAXREDIRS       => 3,
			CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_CONNECTTIMEOUT  => 5,
			CURLOPT_TIMEOUT         => 15
		));

		$bytes = curl_exec($curl);
		$status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		curl_close($curl);

		if (!is_string($bytes) || $bytes === '' || $status !== 200) {
			throw new Exception('Download failed (HTTP ' . $status . ')');
		}

		if (strlen($bytes) > self::MAX_IMAGE_BYTES) {
			throw new Exception('File is larger than 5 MB');
		}

		$info = @getimagesizefromstring($bytes);
		$types = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp');
		$extension = isset($info[2]) && isset($types[$info[2]]) ? $types[$info[2]] : '';

		if ($extension === '') {
			throw new Exception('Not a JPG, PNG, GIF or WEBP image');
		}

		$relative = 'catalog/connector/' . sha1($bytes) . '.' . $extension;
		$target = DIR_IMAGE . $relative;

		if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0777, true) && !is_dir(dirname($target))) {
			throw new Exception('Cannot create the image folder');
		}

		if (@file_put_contents($target, $bytes) === false) {
			throw new Exception('Cannot write the image file');
		}

		return $relative;
	}

	private function imageUrl($image) {
		if ($image === '') {
			return '';
		}

		return (defined('HTTP_SERVER') ? HTTP_SERVER : '') . 'image/' . str_replace(' ', '%20', html_entity_decode($image, ENT_QUOTES, 'UTF-8'));
	}

	private function now() {
		return (string)$this->db->query("SELECT NOW() AS now")->row['now'];
	}

	private function languageId() {
		return (int)$this->config->get('config_language_id');
	}

	private function languageIds() {
		$ids = array();

		foreach ($this->db->query("SELECT language_id FROM `" . DB_PREFIX . "language`")->rows as $row) {
			$ids[] = (int)$row['language_id'];
		}

		return $ids;
	}

	private function value(array $input, $key, $default) {
		return isset($input[$key]) ? $input[$key] : $default;
	}

	private function encode($value) {
		return htmlspecialchars((string)$value, ENT_COMPAT, 'UTF-8');
	}

	private function decode($value) {
		return html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8');
	}
}
