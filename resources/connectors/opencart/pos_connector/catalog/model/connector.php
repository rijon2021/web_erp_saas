<?php
namespace Opencart\Catalog\Model\Extension\PosConnector;

/**
 * Reads and writes for the POS gateway.
 *
 * Writes use SQL because OpenCart's product model belongs to the admin
 * application and cannot be loaded from here. They follow what
 * admin/model/catalog/product.php stores, including text being kept
 * HTML-encoded: the admin form encodes every value and the storefront
 * decodes it, so a raw "&" here would print wrong in the shop.
 *
 * What the POS owns on an update: model, the identifiers it sends (SKU, UPC,
 * EAN, JAN, ISBN, MPN), name, price, status and stock.
 * Description, images and categories are only filled in when a product is
 * created, or when empty, so copy written in OpenCart is not overwritten.
 */
class Connector extends \Opencart\System\Engine\Model {
	private const MAX_LIMIT = 100;
	private const MAX_IMAGE_BYTES = 5242880;

	private ?int $languageId = null;

	// ---------------------------------------------------------------- info --

	public function info(): array {
		$stores = [['store_id' => 0, 'name' => $this->decode((string)$this->config->get('config_name'))]];

		foreach ($this->db->query("SELECT `store_id`, `name` FROM `" . DB_PREFIX . "store` ORDER BY `store_id`")->rows as $row) {
			$stores[] = ['store_id' => (int)$row['store_id'], 'name' => $this->decode($row['name'])];
		}

		$statuses = [];

		foreach ($this->db->query("SELECT `order_status_id`, `name` FROM `" . DB_PREFIX . "order_status` WHERE `language_id` = '" . $this->languageId() . "' ORDER BY `order_status_id`")->rows as $row) {
			$statuses[] = ['id' => (int)$row['order_status_id'], 'name' => $this->decode($row['name'])];
		}

		return [
			'store_url'           => defined('HTTP_SERVER') ? HTTP_SERVER : '',
			'stores'              => $stores,
			'currency'            => (string)$this->config->get('config_currency'),
			'order_statuses'      => $statuses,
			'processing_statuses' => array_map('intval', (array)$this->config->get('config_processing_status')),
			'complete_statuses'   => array_map('intval', (array)$this->config->get('config_complete_status')),
			'webhook_url'         => (string)$this->config->get('module_pos_connector_webhook_url'),
			'server_time'         => $this->now()
		];
	}

	public function setWebhookUrl(string $url): array {
		if ($url !== '' && !preg_match('#^https?://#i', $url)) {
			throw new \InvalidArgumentException('Webhook URL must be http or https');
		}

		$this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `code` = 'module_pos_connector' AND `key` = 'module_pos_connector_webhook_url'");
		$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0', `code` = 'module_pos_connector', `key` = 'module_pos_connector_webhook_url', `value` = '" . $this->db->escape($url) . "', `serialized` = '0'");

		return ['webhook_url' => $url];
	}

	// ------------------------------------------------------------ products --

	public function listProducts(int $page, int $limit, int $storeId): array {
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$offset = (max(1, $page) - 1) * $limit;

		$where = "`p`.`master_id` = '0' AND `s`.`store_id` = '" . (int)$storeId . "'";
		$from = "FROM `" . DB_PREFIX . "product` `p` INNER JOIN `" . DB_PREFIX . "product_to_store` `s` ON (`s`.`product_id` = `p`.`product_id`)";

		$total = (int)$this->db->query("SELECT COUNT(*) AS `total` " . $from . " WHERE " . $where)->row['total'];
		$rows = $this->db->query("SELECT `p`.* " . $from . " WHERE " . $where . " ORDER BY `p`.`product_id` ASC LIMIT " . (int)$offset . ", " . (int)$limit)->rows;

		$products = [];

		foreach ($rows as $row) {
			$products[] = $this->productPayload($row);
		}

		return ['products' => $products, 'total' => $total, 'has_more' => ($offset + count($rows)) < $total];
	}

	public function productPayloadById(int $productId): array {
		$row = $this->productRow($productId);

		if (!$row) {
			return ['product' => null];
		}

		// A variant is delivered inside its master, like everywhere else.
		if ((int)$row['master_id']) {
			$master = $this->productRow((int)$row['master_id']);

			if ($master) {
				$row = $master;
			}
		}

		return ['product' => $this->productPayload($row)];
	}

	private function productPayload(array $row): array {
		$productId = (int)$row['product_id'];
		$variants = [];

		foreach ($this->db->query("SELECT * FROM `" . DB_PREFIX . "product` WHERE `master_id` = '" . $productId . "' ORDER BY `product_id` ASC")->rows as $child) {
			$childCodes = $this->codes((int)$child['product_id']);

			$variants[] = [
				'product_id' => (int)$child['product_id'],
				'model'      => $this->decode((string)$child['model']),
				'sku'        => $childCodes['sku'],
				'codes'      => $childCodes,
				'name'       => $this->variantLabel($child),
				'values'     => $this->variantValues($child),
				'price'      => (float)$child['price'],
				'quantity'   => (int)$child['quantity'],
				'status'     => (int)$child['status'],
				'image'      => $this->imageUrl((string)$child['image'])
			];
		}

		$description = $this->description($productId);
		$codes = $this->codes($productId);

		return [
			'product_id'    => $productId,
			'model'         => $this->decode((string)$row['model']),
			'sku'           => $codes['sku'],
			'codes'         => $codes,
			'name'          => $this->decode((string)($description['name'] ?? '')),
			'description'   => $this->decode((string)($description['description'] ?? '')),
			'price'         => (float)$row['price'],
			'quantity'      => (int)$row['quantity'],
			'subtract'      => (int)$row['subtract'],
			'status'        => (int)$row['status'],
			'weight'        => (float)$row['weight'],
			'image'         => $this->imageUrl((string)$row['image']),
			'category'      => $this->categoryName($productId),
			'has_options'   => $this->hasOptions($productId),
			'variants'      => $variants,
			'date_modified' => (string)$row['date_modified']
		];
	}

	public function saveProducts(array $items, int $storeId): array {
		if (count($items) > self::MAX_LIMIT) {
			throw new \InvalidArgumentException('At most ' . self::MAX_LIMIT . ' items per call');
		}

		$results = [];

		foreach ($items as $item) {
			$item = (array)$item;
			$ref = (string)($item['ref'] ?? '');

			try {
				$results[] = ['ref' => $ref, 'ok' => true] + $this->saveProduct($item, $storeId);
			} catch (\Throwable $e) {
				$results[] = ['ref' => $ref, 'ok' => false, 'error' => $e->getMessage()];
			}
		}

		$this->cache->delete('product');

		return ['results' => $results];
	}

	private function saveProduct(array $item, int $storeId): array {
		$model = trim((string)($item['model'] ?? ''));
		$name = trim((string)($item['name'] ?? ''));

		if ($model === '' || $name === '') {
			throw new \InvalidArgumentException('Model and name are required');
		}

		$warnings = [];
		$existing = $this->findProduct((int)($item['product_id'] ?? 0), $model, $this->itemCodes($item));

		if ($existing && (int)$existing['master_id']) {
			throw new \RuntimeException('Model ' . $model . ' is already used by a variant of product ' . (int)$existing['master_id']);
		}

		if ($existing) {
			$productId = (int)$existing['product_id'];
			$action = (int)($item['product_id'] ?? 0) === $productId ? 'updated' : 'linked';

			$this->updateProduct($productId, $item, $model, $name);

			// A product found by its model may belong to another store only.
			$this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "product_to_store` SET `product_id` = '" . $productId . "', `store_id` = '" . (int)$storeId . "'");

			if (empty($existing['image']) && !empty($item['image_url'])) {
				$this->attachImage($productId, (string)$item['image_url'], $warnings);
			}
		} else {
			$productId = $this->createProduct($item, $model, $name, $storeId, $warnings);
			$action = 'created';
		}

		$variants = [];

		if (!empty($item['variants'])) {
			$variants = $this->saveVariants($productId, (array)($item['options'] ?? []), (array)$item['variants'], $storeId, $warnings);
		}

		return ['product_id' => $productId, 'action' => $action, 'variants' => $variants, 'warnings' => $warnings];
	}

	private function createProduct(array $item, string $model, string $name, int $storeId, array &$warnings): int {
		$productId = $this->insertProductRow($item, $model, 0, '', '', '');

		$this->writeDescriptions($productId, $name, (string)($item['description'] ?? ''));
		$this->writeCodes($productId, $this->itemCodes($item));
		$this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_store` SET `product_id` = '" . $productId . "', `store_id` = '" . (int)$storeId . "'");
		$this->writeSeoUrl($productId, $name, $storeId);

		if (!empty($item['category'])) {
			$categoryId = $this->categoryId((string)$item['category'], $storeId);

			$this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_category` SET `product_id` = '" . $productId . "', `category_id` = '" . $categoryId . "'");
		}

		if (!empty($item['image_url'])) {
			$this->attachImage($productId, (string)$item['image_url'], $warnings);
		}

		return $productId;
	}

	private function updateProduct(int $productId, array $item, string $model, string $name): void {
		$sql = "`model` = '" . $this->db->escape($this->encode($model)) . "', `price` = '" . (float)($item['price'] ?? 0) . "', `status` = '" . (int)!empty($item['status']) . "', `date_modified` = NOW()";

		if (isset($item['subtract'])) {
			$sql .= ", `subtract` = '" . (int)!empty($item['subtract']) . "'";
		}

		$this->db->query("UPDATE `" . DB_PREFIX . "product` SET " . $sql . " WHERE `product_id` = '" . $productId . "'");

		$current = $this->description($productId);
		$fields = "`name` = '" . $this->db->escape($this->encode($name)) . "'";

		if (empty($current['meta_title'])) {
			$fields .= ", `meta_title` = '" . $this->db->escape($this->encode($name)) . "'";
		}

		if (empty($current['description']) && !empty($item['description'])) {
			$fields .= ", `description` = '" . $this->db->escape($this->encode((string)$item['description'])) . "'";
		}

		$this->db->query("UPDATE `" . DB_PREFIX . "product_description` SET " . $fields . " WHERE `product_id` = '" . $productId . "' AND `language_id` = '" . $this->languageId() . "'");

		// No text in the store's language yet (catalogue made under another one).
		if (!$this->db->countAffected() && !$this->db->query("SELECT 1 FROM `" . DB_PREFIX . "product_description` WHERE `product_id` = '" . $productId . "' AND `language_id` = '" . $this->languageId() . "'")->num_rows) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "product_description` SET `product_id` = '" . $productId . "', `language_id` = '" . $this->languageId() . "', `name` = '" . $this->db->escape($this->encode($name)) . "', `description` = '" . $this->db->escape($this->encode((string)($item['description'] ?? ''))) . "', `tag` = '', `meta_title` = '" . $this->db->escape($this->encode($name)) . "', `meta_description` = '', `meta_keyword` = ''");
		}

		$this->writeCodes($productId, $this->itemCodes($item));
	}

	/** Inserts the product row itself; every other table is the caller's. */
	private function insertProductRow(array $item, string $model, int $masterId, string $variant, string $override, string $image): int {
		// 4.0.x still has the identifier columns on the product row, without defaults.
		$identifiers = $this->skuInCodes() ? '' : "`sku` = '', `upc` = '', `ean` = '', `jan` = '', `isbn` = '', `mpn` = '',";

		$this->db->query("INSERT INTO `" . DB_PREFIX . "product` SET
			`master_id` = '" . $masterId . "',
			`model` = '" . $this->db->escape($this->encode($model)) . "',
			" . $identifiers . "
			`location` = '',
			`rating` = '0',
			`variant` = '" . $this->db->escape($variant) . "',
			`override` = '" . $this->db->escape($override) . "',
			`quantity` = '" . (int)($item['quantity'] ?? 0) . "',
			`minimum` = '1',
			`subtract` = '" . (int)($item['subtract'] ?? 1) . "',
			`stock_status_id` = '" . (int)$this->config->get('config_stock_status_id') . "',
			`date_available` = CURDATE(),
			`manufacturer_id` = '0',
			`shipping` = '1',
			`price` = '" . (float)($item['price'] ?? 0) . "',
			`points` = '0',
			`weight` = '" . (float)($item['weight'] ?? 0) . "',
			`weight_class_id` = '" . (int)$this->config->get('config_weight_class_id') . "',
			`length` = '0',
			`width` = '0',
			`height` = '0',
			`length_class_id` = '" . (int)$this->config->get('config_length_class_id') . "',
			`status` = '" . (int)!empty($item['status']) . "',
			`tax_class_id` = '0',
			`sort_order` = '0',
			`image` = '" . $this->db->escape($image) . "',
			`date_added` = NOW(),
			`date_modified` = NOW()");

		return (int)$this->db->getLastId();
	}

	/**
	 * A variant in OpenCart is a product row with master_id set, its own model
	 * and quantity, and a `variant` map of product_option_id to
	 * product_option_value_id. The master carries the options that map points
	 * at, so those are created first. Option values do not track stock here
	 * (subtract 0): the variant row does, one figure per combination.
	 */
	private function saveVariants(int $masterId, array $options, array $variants, int $storeId, array &$warnings): array {
		$map = [];

		foreach ($options as $option) {
			$option = (array)$option;
			$optionName = trim((string)($option['name'] ?? ''));

			if ($optionName === '') {
				continue;
			}

			$optionId = $this->optionId($optionName);
			$productOptionId = $this->productOptionId($masterId, $optionId);

			$map[$optionName] = ['product_option_id' => $productOptionId, 'values' => []];

			foreach ((array)($option['values'] ?? []) as $value) {
				$value = trim((string)$value);

				if ($value !== '') {
					$map[$optionName]['values'][$value] = $this->productOptionValueId($masterId, $productOptionId, $optionId, $this->optionValueId($optionId, $value));
				}
			}
		}

		$masterRow = $this->productRow($masterId);
		$results = [];

		foreach ($variants as $variant) {
			$variant = (array)$variant;
			$ref = (string)($variant['ref'] ?? '');

			try {
				$results[] = ['ref' => $ref, 'ok' => true] + $this->saveVariant($masterRow, $map, $variant, $storeId, $warnings);
			} catch (\Throwable $e) {
				$results[] = ['ref' => $ref, 'ok' => false, 'error' => $e->getMessage()];
			}
		}

		return $results;
	}

	private function saveVariant(array $master, array $map, array $variant, int $storeId, array &$warnings): array {
		$masterId = (int)$master['product_id'];
		$model = trim((string)($variant['model'] ?? ''));

		if ($model === '') {
			throw new \InvalidArgumentException('Variant model is required');
		}

		$choice = [];

		foreach ((array)($variant['values'] ?? []) as $optionName => $value) {
			$productOptionId = $map[$optionName]['product_option_id'] ?? 0;
			$productOptionValueId = $map[$optionName]['values'][$value] ?? 0;

			if ($productOptionId && $productOptionValueId) {
				$choice[$productOptionId] = (string)$productOptionValueId;
			}
		}

		if (!$choice) {
			throw new \RuntimeException('Variant ' . $model . ' has no option values');
		}

		// The cart reads the fixed options from override[<id>], the product
		// page from override[variant][<id>]. Both have to be there.
		$override = ['model' => '1', 'price' => '1', 'product_code' => '1', 'variant' => array_fill_keys(array_map('strval', array_keys($choice)), '1')];

		foreach (array_keys($choice) as $productOptionId) {
			$override[(string)$productOptionId] = '1';
		}

		$variantJson = (string)json_encode($choice);
		$overrideJson = (string)json_encode($override);

		$existing = $this->findProduct((int)($variant['product_id'] ?? 0), $model, $this->itemCodes($variant));

		if ($existing && (int)$existing['master_id'] !== $masterId) {
			throw new \RuntimeException('Model ' . $model . ' is already used by another product');
		}

		if ($existing) {
			$childId = (int)$existing['product_id'];

			$this->db->query("UPDATE `" . DB_PREFIX . "product` SET `model` = '" . $this->db->escape($this->encode($model)) . "', `price` = '" . (float)($variant['price'] ?? 0) . "', `status` = '" . (int)!empty($variant['status']) . "', `variant` = '" . $this->db->escape($variantJson) . "', `override` = '" . $this->db->escape($overrideJson) . "', `date_modified` = NOW() WHERE `product_id` = '" . $childId . "'");

			$this->writeCodes($childId, $this->itemCodes($variant));

			return ['product_id' => $childId, 'action' => 'updated'];
		}

		$childId = $this->insertProductRow($variant + ['subtract' => (int)$master['subtract'], 'weight' => (float)$master['weight']], $model, $masterId, $variantJson, $overrideJson, (string)$master['image']);

		// A variant inherits the master's text, stores and categories.
		foreach ($this->db->query("SELECT * FROM `" . DB_PREFIX . "product_description` WHERE `product_id` = '" . $masterId . "'")->rows as $row) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "product_description` SET `product_id` = '" . $childId . "', `language_id` = '" . (int)$row['language_id'] . "', `name` = '" . $this->db->escape((string)$row['name']) . "', `description` = '" . $this->db->escape((string)$row['description']) . "', `tag` = '" . $this->db->escape((string)$row['tag']) . "', `meta_title` = '" . $this->db->escape((string)$row['meta_title']) . "', `meta_description` = '" . $this->db->escape((string)$row['meta_description']) . "', `meta_keyword` = '" . $this->db->escape((string)$row['meta_keyword']) . "'");
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_store` (`product_id`, `store_id`) SELECT '" . $childId . "', `store_id` FROM `" . DB_PREFIX . "product_to_store` WHERE `product_id` = '" . $masterId . "'");
		$this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_category` (`product_id`, `category_id`) SELECT '" . $childId . "', `category_id` FROM `" . DB_PREFIX . "product_to_category` WHERE `product_id` = '" . $masterId . "'");

		$this->writeCodes($childId, $this->itemCodes($variant));
		$this->writeSeoUrl($childId, (string)($this->description($masterId)['name'] ?? $model), $storeId);

		return ['product_id' => $childId, 'action' => 'created'];
	}

	// --------------------------------------------------------------- stock --

	public function setStock(array $items): array {
		if (count($items) > self::MAX_LIMIT) {
			throw new \InvalidArgumentException('At most ' . self::MAX_LIMIT . ' items per call');
		}

		$updated = 0;
		$missing = [];

		foreach ($items as $item) {
			$item = (array)$item;
			$productId = (int)($item['product_id'] ?? 0);

			if (!$productId || !$this->productRow($productId)) {
				$missing[] = $productId;

				continue;
			}

			$sql = "`quantity` = '" . (int)($item['quantity'] ?? 0) . "'";

			if (isset($item['subtract'])) {
				$sql .= ", `subtract` = '" . (int)!empty($item['subtract']) . "'";
			}

			$this->db->query("UPDATE `" . DB_PREFIX . "product` SET " . $sql . " WHERE `product_id` = '" . $productId . "'");

			$updated++;
		}

		$this->cache->delete('product');

		return ['updated' => $updated, 'missing' => $missing];
	}

	// -------------------------------------------------------------- orders --

	/**
	 * Orders by id, a stable order for paging. Status 0 is a checkout that was
	 * never confirmed: not an order yet.
	 *
	 * A status change does not touch date_modified, only an edit of the order
	 * does, so "changed since" has to look at the history as well.
	 */
	public function listOrders(array $filter): array {
		$limit = max(1, min(self::MAX_LIMIT, (int)($filter['limit'] ?? 25)));
		$offset = (max(1, (int)($filter['page'] ?? 1)) - 1) * $limit;

		$where = "`order_status_id` > '0' AND `store_id` = '" . (int)($filter['store_id'] ?? 0) . "'";

		if (!empty($filter['modified_since'])) {
			$since = $this->db->escape((string)$filter['modified_since']);

			$where .= " AND (`date_modified` >= '" . $since . "' OR `order_id` IN (SELECT `order_id` FROM `" . DB_PREFIX . "order_history` WHERE `date_added` >= '" . $since . "'))";
		}

		if (!empty($filter['created_since'])) {
			$where .= " AND `date_added` >= '" . $this->db->escape((string)$filter['created_since']) . "'";
		}

		$total = (int)$this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "order` WHERE " . $where)->row['total'];
		$rows = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE " . $where . " ORDER BY `order_id` ASC LIMIT " . (int)$offset . ", " . (int)$limit)->rows;

		$orders = [];

		foreach ($rows as $row) {
			$orders[] = $this->orderPayload($row);
		}

		return ['orders' => $orders, 'total' => $total, 'has_more' => ($offset + count($rows)) < $total, 'server_time' => $this->now()];
	}

	public function orderPayloadById(int $orderId): array {
		$row = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . $orderId . "'")->row;

		return ['order' => $row ? $this->orderPayload($row) : null];
	}

	private function orderPayload(array $row): array {
		$orderId = (int)$row['order_id'];
		$lines = [];

		foreach ($this->db->query("SELECT * FROM `" . DB_PREFIX . "order_product` WHERE `order_id` = '" . $orderId . "'")->rows as $line) {
			$options = [];

			foreach ($this->db->query("SELECT * FROM `" . DB_PREFIX . "order_option` WHERE `order_id` = '" . $orderId . "' AND `order_product_id` = '" . (int)$line['order_product_id'] . "'")->rows as $option) {
				$options[] = [
					'product_option_id'       => (int)$option['product_option_id'],
					'product_option_value_id' => (int)$option['product_option_value_id'],
					'name'                    => $this->decode((string)$option['name']),
					'value'                   => $this->decode((string)$option['value'])
				];
			}

			$productId = (int)$line['product_id'];

			$lines[] = [
				'product_id' => $productId,
				'master_id'  => (int)$line['master_id'],
				// Bought from the master page: the chosen options name the
				// variant, though the line itself points at the master.
				'variant_product_id' => (int)$line['master_id'] ? $productId : $this->matchVariant($productId, $options),
				'model'      => $this->decode((string)$line['model']),
				'sku'        => $this->codes($productId)['sku'],
				'codes'      => $this->codes($productId),
				'name'       => $this->decode((string)$line['name']),
				'quantity'   => (float)$line['quantity'],
				'price'      => (float)$line['price'],
				'tax'        => (float)$line['tax'],
				'total'      => (float)$line['total'],
				'options'    => $options
			];
		}

		$totals = [];

		foreach ($this->db->query("SELECT `code`, `title`, `value` FROM `" . DB_PREFIX . "order_total` WHERE `order_id` = '" . $orderId . "' ORDER BY `sort_order` ASC")->rows as $total) {
			$totals[] = ['code' => (string)$total['code'], 'title' => $this->decode((string)$total['title']), 'value' => (float)$total['value']];
		}

		$payment = json_decode((string)$row['payment_method'], true);
		$shipping = json_decode((string)$row['shipping_method'], true);

		return [
			'order_id'      => $orderId,
			'store_id'      => (int)$row['store_id'],
			'status_id'     => (int)$row['order_status_id'],
			'reference'     => $row['invoice_no'] ? $this->decode((string)$row['invoice_prefix']) . $row['invoice_no'] : (string)$orderId,
			'customer_id'   => (int)$row['customer_id'],
			'firstname'     => $this->decode((string)$row['firstname']),
			'lastname'      => $this->decode((string)$row['lastname']),
			'email'         => (string)$row['email'],
			'telephone'     => (string)$row['telephone'],
			'address'       => $this->address($row),
			'payment_method'  => is_array($payment) ? $this->decode((string)($payment['name'] ?? '')) : '',
			'shipping_method' => is_array($shipping) ? $this->decode((string)($shipping['name'] ?? '')) : '',
			'comment'       => $this->decode((string)$row['comment']),
			'total'         => (float)$row['total'],
			'currency'      => (string)$row['currency_code'],
			'currency_value' => (float)$row['currency_value'],
			'date_added'    => (string)$row['date_added'],
			'date_modified' => (string)$row['date_modified'],
			'lines'         => $lines,
			'totals'        => $totals
		];
	}

	/** The shipping address when there is one, otherwise the billing address. */
	private function address(array $row): array {
		$prefix = !empty($row['shipping_address_1']) ? 'shipping_' : 'payment_';

		return [
			'address_1' => $this->decode((string)$row[$prefix . 'address_1']),
			'address_2' => $this->decode((string)$row[$prefix . 'address_2']),
			'city'      => $this->decode((string)$row[$prefix . 'city']),
			'zone'      => $this->decode((string)$row[$prefix . 'zone']),
			'postcode'  => (string)$row[$prefix . 'postcode'],
			'country'   => $this->decode((string)$row[$prefix . 'country'])
		];
	}

	/** The variant of a master whose fixed options are all among the chosen ones. */
	private function matchVariant(int $masterId, array $options): int {
		if (!$options) {
			return 0;
		}

		$chosen = [];

		foreach ($options as $option) {
			$chosen[(int)$option['product_option_id']] = (int)$option['product_option_value_id'];
		}

		foreach ($this->db->query("SELECT `product_id`, `variant` FROM `" . DB_PREFIX . "product` WHERE `master_id` = '" . $masterId . "'")->rows as $child) {
			$variant = json_decode((string)$child['variant'], true);

			if (!is_array($variant) || !$variant) {
				continue;
			}

			foreach ($variant as $productOptionId => $productOptionValueId) {
				if (($chosen[(int)$productOptionId] ?? 0) !== (int)$productOptionValueId) {
					continue 2;
				}
			}

			return (int)$child['product_id'];
		}

		return 0;
	}

	// ------------------------------------------------------------- helpers --

	private function productRow(int $productId): array {
		if (!$productId) {
			return [];
		}

		return $this->db->query("SELECT * FROM `" . DB_PREFIX . "product` WHERE `product_id` = '" . $productId . "'")->row;
	}

	/**
	 * OpenCart's identifier fields as the POS names them. On 4.1+ product_code
	 * rows hold them under any case: the admin form writes 'ISBN', the 4.1
	 * upgrade wrote 'isbn' (MySQL compares them equal).
	 */
	private const CODE_FIELDS = ['sku', 'upc', 'ean', 'jan', 'isbn', 'mpn'];

	/** @var string[]|null identifier columns still on the product table, read once */
	private ?array $legacyColumns = null;

	/**
	 * 4.1+ moved identifiers into product_code, but the product table can
	 * still carry the old columns with values: the 4.1 upgrade never moves
	 * mpn, and an upgrade cut short leaves the rest behind.
	 */
	private function legacyCodeColumns(): array {
		if ($this->legacyColumns === null) {
			$this->legacyColumns = [];

			foreach ($this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "product`")->rows as $column) {
				if (in_array((string)$column['Field'], self::CODE_FIELDS, true)) {
					$this->legacyColumns[] = (string)$column['Field'];
				}
			}
		}

		return $this->legacyColumns;
	}

	private function findProduct(int $productId, string $model, array $codes): array {
		$row = $this->productRow($productId);

		if ($row) {
			return $row;
		}

		$row = $this->db->query("SELECT * FROM `" . DB_PREFIX . "product` WHERE `model` = '" . $this->db->escape($this->encode($model)) . "' ORDER BY `master_id` ASC, `product_id` ASC LIMIT 1")->row;

		foreach ($this->cleanCodes($codes) as $field => $value) {
			if ($row) {
				break;
			}

			$value = $this->db->escape($this->encode($value));

			if (!$this->skuInCodes()) {
				$row = $this->db->query("SELECT * FROM `" . DB_PREFIX . "product` WHERE `" . $field . "` = '" . $value . "' ORDER BY `master_id` ASC, `product_id` ASC LIMIT 1")->row;
			} else {
				$row = $this->db->query("SELECT `p`.* FROM `" . DB_PREFIX . "product` `p` INNER JOIN `" . DB_PREFIX . "product_code` `c` ON (`c`.`product_id` = `p`.`product_id`) WHERE `c`.`code` = '" . strtoupper($field) . "' AND `c`.`value` = '" . $value . "' ORDER BY `p`.`master_id` ASC, `p`.`product_id` ASC LIMIT 1")->row;

				if (!$row && in_array($field, $this->legacyCodeColumns(), true)) {
					$row = $this->db->query("SELECT * FROM `" . DB_PREFIX . "product` WHERE `" . $field . "` = '" . $value . "' ORDER BY `master_id` ASC, `product_id` ASC LIMIT 1")->row;
				}
			}
		}

		return $row;
	}

	/** 4.1 moved the SKU and the other identifiers from product columns into the product_code table. */
	private function skuInCodes(): bool {
		return version_compare(VERSION, '4.1.0.0', '>=');
	}

	/** The identifiers a product carries: every field present, blank when unset. */
	private function codes(int $productId): array {
		$codes = array_fill_keys(self::CODE_FIELDS, '');

		if (!$this->skuInCodes()) {
			$row = $this->db->query("SELECT `" . implode('`, `', self::CODE_FIELDS) . "` FROM `" . DB_PREFIX . "product` WHERE `product_id` = '" . $productId . "'")->row;

			foreach (self::CODE_FIELDS as $field) {
				$codes[$field] = $this->decode((string)($row[$field] ?? ''));
			}

			return $codes;
		}

		foreach ($this->db->query("SELECT `code`, `value` FROM `" . DB_PREFIX . "product_code` WHERE `product_id` = '" . $productId . "'")->rows as $row) {
			$field = strtolower(trim((string)$row['code']));

			if (isset($codes[$field]) && $codes[$field] === '') {
				$codes[$field] = $this->decode(trim((string)$row['value']));
			}
		}

		// Blanks left: the old product columns may still hold them.
		$legacy = array_values(array_intersect(array_keys(array_filter($codes, fn ($value) => $value === '')), $this->legacyCodeColumns()));

		if ($legacy) {
			$row = $this->db->query("SELECT `" . implode('`, `', $legacy) . "` FROM `" . DB_PREFIX . "product` WHERE `product_id` = '" . $productId . "'")->row;

			foreach ($legacy as $field) {
				$codes[$field] = $this->decode(trim((string)($row[$field] ?? '')));
			}
		}

		return $codes;
	}

	/** The identifiers an item from the POS carries: its codes map, or the older sku key alone. */
	private function itemCodes(array $item): array {
		$codes = (array)($item['codes'] ?? []);

		if (!isset($codes['sku']) && isset($item['sku'])) {
			$codes['sku'] = (string)$item['sku'];
		}

		return $this->cleanCodes($codes);
	}

	/** Known fields with a non-blank value only. */
	private function cleanCodes(array $codes): array {
		$clean = [];

		foreach (self::CODE_FIELDS as $field) {
			$value = trim((string)($codes[$field] ?? ''));

			if ($value !== '') {
				$clean[$field] = $value;
			}
		}

		return $clean;
	}

	/** Writes the identifiers given; fields not given keep their value. */
	private function writeCodes(int $productId, array $codes): void {
		foreach ($this->cleanCodes($codes) as $field => $value) {
			$value = $this->db->escape($this->encode($value));

			if (!$this->skuInCodes()) {
				$this->db->query("UPDATE `" . DB_PREFIX . "product` SET `" . $field . "` = '" . $value . "' WHERE `product_id` = '" . $productId . "'");

				continue;
			}

			$this->db->query("DELETE FROM `" . DB_PREFIX . "product_code` WHERE `product_id` = '" . $productId . "' AND `code` = '" . strtoupper($field) . "'");
			$this->db->query("INSERT INTO `" . DB_PREFIX . "product_code` SET `product_id` = '" . $productId . "', `code` = '" . strtoupper($field) . "', `value` = '" . $value . "'");
		}
	}

	private function writeDescriptions(int $productId, string $name, string $description): void {
		$name = $this->db->escape($this->encode($name));
		$description = $this->db->escape($this->encode($description));

		foreach ($this->languageIds() as $languageId) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "product_description` SET `product_id` = '" . $productId . "', `language_id` = '" . $languageId . "', `name` = '" . $name . "', `description` = '" . $description . "', `tag` = '', `meta_title` = '" . $name . "', `meta_description` = '', `meta_keyword` = ''");
		}
	}

	/** The product id keeps the keyword unique within a store and language. */
	private function writeSeoUrl(int $productId, string $name, int $storeId): void {
		$slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($this->decode($name))), '-');
		$keyword = ($slug !== '' ? substr($slug, 0, 80) : 'product') . '-' . $productId;

		foreach ($this->languageIds() as $languageId) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "seo_url` SET `store_id` = '" . (int)$storeId . "', `language_id` = '" . $languageId . "', `key` = 'product_id', `value` = '" . $productId . "', `keyword` = '" . $this->db->escape($keyword) . "', `sort_order` = '0'");
		}
	}

	private function description(int $productId): array {
		return $this->db->query("SELECT * FROM `" . DB_PREFIX . "product_description` WHERE `product_id` = '" . $productId . "' ORDER BY (`language_id` = '" . $this->languageId() . "') DESC LIMIT 1")->row;
	}

	private function categoryName(int $productId): string {
		$row = $this->db->query("SELECT `cd`.`name` FROM `" . DB_PREFIX . "product_to_category` `pc` INNER JOIN `" . DB_PREFIX . "category_description` `cd` ON (`cd`.`category_id` = `pc`.`category_id`) WHERE `pc`.`product_id` = '" . $productId . "' AND `cd`.`language_id` = '" . $this->languageId() . "' LIMIT 1")->row;

		return $this->decode((string)($row['name'] ?? ''));
	}

	private function categoryId(string $name, int $storeId): int {
		$encoded = $this->db->escape($this->encode($name));

		$row = $this->db->query("SELECT `category_id` FROM `" . DB_PREFIX . "category_description` WHERE `name` = '" . $encoded . "' AND `language_id` = '" . $this->languageId() . "' LIMIT 1")->row;

		if ($row) {
			return (int)$row['category_id'];
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "category` SET `image` = '', `parent_id` = '0', `sort_order` = '0', `status` = '1'");

		$categoryId = (int)$this->db->getLastId();

		foreach ($this->languageIds() as $languageId) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "category_description` SET `category_id` = '" . $categoryId . "', `language_id` = '" . $languageId . "', `name` = '" . $encoded . "', `description` = '', `meta_title` = '" . $encoded . "', `meta_description` = '', `meta_keyword` = ''");
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "category_path` SET `category_id` = '" . $categoryId . "', `path_id` = '" . $categoryId . "', `level` = '0'");
		$this->db->query("INSERT INTO `" . DB_PREFIX . "category_to_store` SET `category_id` = '" . $categoryId . "', `store_id` = '" . (int)$storeId . "'");

		$this->cache->delete('category');

		return $categoryId;
	}

	private function optionId(string $name): int {
		$encoded = $this->db->escape($this->encode($name));

		$row = $this->db->query("SELECT `od`.`option_id` FROM `" . DB_PREFIX . "option_description` `od` INNER JOIN `" . DB_PREFIX . "option` `o` ON (`o`.`option_id` = `od`.`option_id`) WHERE `od`.`name` = '" . $encoded . "' AND `od`.`language_id` = '" . $this->languageId() . "' AND `o`.`type` IN ('select', 'radio') LIMIT 1")->row;

		if ($row) {
			return (int)$row['option_id'];
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "option` SET `type` = 'select', `validation` = '', `sort_order` = '0'");

		$optionId = (int)$this->db->getLastId();

		foreach ($this->languageIds() as $languageId) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "option_description` SET `option_id` = '" . $optionId . "', `language_id` = '" . $languageId . "', `name` = '" . $encoded . "'");
		}

		return $optionId;
	}

	private function optionValueId(int $optionId, string $name): int {
		$encoded = $this->db->escape($this->encode($name));

		$row = $this->db->query("SELECT `option_value_id` FROM `" . DB_PREFIX . "option_value_description` WHERE `option_id` = '" . $optionId . "' AND `name` = '" . $encoded . "' AND `language_id` = '" . $this->languageId() . "' LIMIT 1")->row;

		if ($row) {
			return (int)$row['option_value_id'];
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "option_value` SET `option_id` = '" . $optionId . "', `image` = '', `sort_order` = '0'");

		$optionValueId = (int)$this->db->getLastId();

		foreach ($this->languageIds() as $languageId) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "option_value_description` SET `option_value_id` = '" . $optionValueId . "', `language_id` = '" . $languageId . "', `option_id` = '" . $optionId . "', `name` = '" . $encoded . "'");
		}

		return $optionValueId;
	}

	private function productOptionId(int $productId, int $optionId): int {
		$row = $this->db->query("SELECT `product_option_id` FROM `" . DB_PREFIX . "product_option` WHERE `product_id` = '" . $productId . "' AND `option_id` = '" . $optionId . "' LIMIT 1")->row;

		if ($row) {
			return (int)$row['product_option_id'];
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "product_option` SET `product_id` = '" . $productId . "', `option_id` = '" . $optionId . "', `value` = '', `required` = '1'");

		return (int)$this->db->getLastId();
	}

	private function productOptionValueId(int $productId, int $productOptionId, int $optionId, int $optionValueId): int {
		$row = $this->db->query("SELECT `product_option_value_id` FROM `" . DB_PREFIX . "product_option_value` WHERE `product_option_id` = '" . $productOptionId . "' AND `option_value_id` = '" . $optionValueId . "' LIMIT 1")->row;

		if ($row) {
			return (int)$row['product_option_value_id'];
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "product_option_value` SET `product_option_id` = '" . $productOptionId . "', `product_id` = '" . $productId . "', `option_id` = '" . $optionId . "', `option_value_id` = '" . $optionValueId . "', `quantity` = '0', `subtract` = '0', `price` = '0', `price_prefix` = '+', `points` = '0', `points_prefix` = '+', `weight` = '0', `weight_prefix` = '+'");

		return (int)$this->db->getLastId();
	}

	/** Options that exist on the product without variants tracking them. */
	private function hasOptions(int $productId): bool {
		return (bool)$this->db->query("SELECT 1 FROM `" . DB_PREFIX . "product_option` WHERE `product_id` = '" . $productId . "' LIMIT 1")->num_rows;
	}

	/** "Red / Large", in option order. */
	private function variantLabel(array $child): string {
		$values = $this->variantValues($child);

		return $values ? implode(' / ', $values) : $this->decode((string)$child['model']);
	}

	/** @return array<string, string> option name => value name */
	private function variantValues(array $child): array {
		$variant = json_decode((string)$child['variant'], true);
		$values = [];

		if (!is_array($variant)) {
			return [];
		}

		foreach ($variant as $productOptionId => $productOptionValueId) {
			$row = $this->db->query("SELECT `od`.`name` AS `option_name`, `ovd`.`name` AS `value_name` FROM `" . DB_PREFIX . "product_option_value` `pov` INNER JOIN `" . DB_PREFIX . "option_description` `od` ON (`od`.`option_id` = `pov`.`option_id` AND `od`.`language_id` = '" . $this->languageId() . "') INNER JOIN `" . DB_PREFIX . "option_value_description` `ovd` ON (`ovd`.`option_value_id` = `pov`.`option_value_id` AND `ovd`.`language_id` = '" . $this->languageId() . "') WHERE `pov`.`product_option_value_id` = '" . (int)$productOptionValueId . "' AND `pov`.`product_option_id` = '" . (int)$productOptionId . "'")->row;

			if ($row) {
				$values[$this->decode($row['option_name'])] = $this->decode($row['value_name']);
			}
		}

		return $values;
	}

	private function attachImage(int $productId, string $url, array &$warnings): void {
		try {
			$path = $this->downloadImage($url);

			$this->db->query("UPDATE `" . DB_PREFIX . "product` SET `image` = '" . $this->db->escape($path) . "' WHERE `product_id` = '" . $productId . "'");
		} catch (\Throwable $e) {
			// A picture that cannot be fetched is no reason to lose the product.
			$warnings[] = 'Image not saved: ' . $e->getMessage();
		}
	}

	private function downloadImage(string $url): string {
		if (!preg_match('#^https?://#i', $url) || !function_exists('curl_init')) {
			throw new \RuntimeException('Unsupported image URL');
		}

		$curl = curl_init($url);

		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER  => true,
			CURLOPT_FOLLOWLOCATION  => true,
			CURLOPT_MAXREDIRS       => 3,
			CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_CONNECTTIMEOUT  => 5,
			CURLOPT_TIMEOUT         => 15
		]);

		$bytes = curl_exec($curl);
		$status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		curl_close($curl);

		if (!is_string($bytes) || $bytes === '' || $status !== 200) {
			throw new \RuntimeException('Download failed (HTTP ' . $status . ')');
		}

		if (strlen($bytes) > self::MAX_IMAGE_BYTES) {
			throw new \RuntimeException('File is larger than 5 MB');
		}

		$info = @getimagesizefromstring($bytes);
		$extension = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? '';

		if ($extension === '') {
			throw new \RuntimeException('Not a JPG, PNG, GIF or WEBP image');
		}

		$relative = 'catalog/connector/' . sha1($bytes) . '.' . $extension;
		$target = DIR_IMAGE . $relative;

		if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0777, true) && !is_dir(dirname($target))) {
			throw new \RuntimeException('Cannot create the image folder');
		}

		if (@file_put_contents($target, $bytes) === false) {
			throw new \RuntimeException('Cannot write the image file');
		}

		return $relative;
	}

	private function imageUrl(string $image): string {
		if ($image === '') {
			return '';
		}

		return (defined('HTTP_SERVER') ? HTTP_SERVER : '') . 'image/' . str_replace(' ', '%20', html_entity_decode($image, ENT_QUOTES, 'UTF-8'));
	}

	private function now(): string {
		return (string)$this->db->query("SELECT NOW() AS `now`")->row['now'];
	}

	private function languageId(): int {
		if ($this->languageId === null) {
			$this->languageId = (int)$this->config->get('config_language_id');
		}

		return $this->languageId;
	}

	/** @return int[] */
	private function languageIds(): array {
		return array_map(static fn ($row) => (int)$row['language_id'], $this->db->query("SELECT `language_id` FROM `" . DB_PREFIX . "language`")->rows);
	}

	private function encode(string $value): string {
		return htmlspecialchars($value, ENT_COMPAT, 'UTF-8');
	}

	private function decode(string $value): string {
		return html_entity_decode($value, ENT_QUOTES, 'UTF-8');
	}
}
