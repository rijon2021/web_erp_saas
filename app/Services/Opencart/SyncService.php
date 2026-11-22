<?php

namespace App\Services\Opencart;

use App\Models\Category;
use App\Models\Client as Customer;
use App\Models\OpencartLog;
use App\Models\OpencartMapping;
use App\Models\OpencartSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\product_warehouse;
use App\Services\ComboStockService;
use App\Services\ProductOptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Connection, catalogue and stock for the OpenCart module (one store per
 * tenant). Orders are in OrderSync. Batched methods follow the contract of the
 * other shop modules: a cursor in, has_more out, driven by the frontend loop.
 *
 * Product identity is Stocky's code against OpenCart's model, or against one
 * of its identifier fields (SKU, UPC, EAN, JAN, ISBN, MPN) when the settings
 * say so, for stores that type a category or product type into the required
 * Model box; see OpencartSetting::codeCandidates(). Stocky owns
 * stock, name, price and status: pushing overwrites those. Description, image
 * and category are only written when a product is created or has none (the
 * connector enforces this), so copy written in OpenCart survives.
 *
 * OpenCart variants are real product rows with their own model and quantity,
 * which is how Stocky variants are held too. Options that have no variant rows
 * cannot carry stock per combination: such a product comes in as one product.
 */
class SyncService
{
    /** Quantity sent for something that never runs out; subtract is off too. */
    private const UNLIMITED = 9999;

    private const STOCK_CHUNK = 100;

    private OpencartSetting $settings;
    private Client $client;

    /** @var string[] codes of the current pull page that another shop product already held */
    private array $suffixedCodes = [];

    /** @var string[] "old → new" codes repaired on the current pull page */
    private array $healedCodes = [];

    public function __construct(OpencartSetting $settings, ?Client $client = null)
    {
        $this->settings = $settings;
        $this->client = $client ?: Client::make($settings);
    }

    public static function make(?OpencartSetting $settings = null): self
    {
        return new self($settings ?: OpencartSetting::current());
    }

    public function client(): Client
    {
        return $this->client;
    }

    // -----------------------------------------------------------------
    // Connection
    // -----------------------------------------------------------------

    /**
     * Ping the store, and on success finish connecting it: remember the
     * versions, fill in the order status defaults, register the webhook, and
     * start the order cursor so history is not imported by surprise.
     */
    public function testConnection(string $webhookUrl): array
    {
        $info = $this->client->call('ping');

        // The first connection sets the status lists whatever the page saved
        // before it (it posts empty lists until it has seen the store's
        // statuses); after that the lists belong to the tenant.
        $firstConnection = empty($this->settings->connector_version);

        $this->settings->connector_version = (string) ($info['connector'] ?? '');
        $this->settings->opencart_version = (string) ($info['opencart'] ?? '');
        $this->fillStatusDefaults($info, $firstConnection);
        if (! $this->settings->orders_cursor && ! empty($info['server_time'])) {
            $this->settings->orders_cursor = $info['server_time'];
        }
        $this->settings->save();

        $this->client->call('webhook.set', ['url' => $webhookUrl]);

        return [
            'ok' => true,
            'connector' => $info['connector'] ?? null,
            'opencart' => $info['opencart'] ?? null,
            'currency' => $info['currency'] ?? null,
            'stores' => $info['stores'] ?? [],
            'order_statuses' => $info['order_statuses'] ?? [],
        ];
    }

    /**
     * What the shop's statuses mean, until the tenant says otherwise. OpenCart
     * reports which statuses take stock; the rest is judged by name, because
     * statuses can be renamed and added.
     */
    private function fillStatusDefaults(array $info, bool $force = false): void
    {
        $statuses = (array) ($info['order_statuses'] ?? []);
        if (! $statuses) {
            return;
        }

        if ($force) {
            $this->settings->cancel_status_ids = null;
            $this->settings->paid_status_ids = null;
            $this->settings->commit_status_ids = null;
        }

        $matching = function (string $pattern) use ($statuses): array {
            $ids = [];
            foreach ($statuses as $status) {
                if (preg_match($pattern, (string) ($status['name'] ?? ''))) {
                    $ids[] = (int) $status['id'];
                }
            }

            return $ids;
        };

        if ($this->settings->cancel_status_ids === null) {
            // "Canceled Reversal" means the cancellation was undone.
            $this->settings->cancel_status_ids = array_values(array_diff(
                $matching('/cancel|denied|fail|refund|revers|chargeback|expired|void/i'),
                $matching('/cancel.*revers/i')
            ));
        }

        if ($this->settings->paid_status_ids === null) {
            $this->settings->paid_status_ids = $matching('/^(processing|processed|shipped|complete)/i');
        }

        if ($this->settings->commit_status_ids === null) {
            $this->settings->commit_status_ids = array_values(array_diff(
                array_values(array_unique(array_merge(
                    array_map('intval', (array) ($info['processing_statuses'] ?? [])),
                    array_map('intval', (array) ($info['complete_statuses'] ?? []))
                ))),
                (array) $this->settings->cancel_status_ids
            ));
        }
    }

    // -----------------------------------------------------------------
    // Products: Stocky to OpenCart
    // -----------------------------------------------------------------

    /**
     * Create or update products in the store, then send their stock.
     *
     * @param  int[]|null  $productIds  push exactly these, regardless of the cursor (auto-sync)
     */
    public function pushProducts(int $startAfterId = 0, int $batch = 15, ?array $productIds = null): array
    {
        $query = Product::whereNull('deleted_at')->orderBy('id');
        $productIds === null
            ? $query->where('id', '>', $startAfterId)->limit($batch)
            : $query->whereIn('id', $productIds);
        $products = $query->get();

        $lastId = $products->isEmpty() ? $startAfterId : (int) $products->last()->id;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];
        $warnings = [];

        $items = [];
        foreach ($products as $product) {
            $mapped = OpencartMapping::opencartId(OpencartMapping::TYPE_PRODUCT, (int) $product->id);
            if (! $mapped && ! $this->isListable($product)) {
                $skipped++;
                continue;
            }
            $items[] = $this->productPayload($product, $mapped);
        }

        if ($items) {
            $response = $this->client->call('products.save', [
                'store_id' => (int) $this->settings->store_id,
                'items' => $items,
            ], 120);

            foreach ((array) ($response['results'] ?? []) as $result) {
                $productId = (int) substr((string) ($result['ref'] ?? ''), 1);

                if (empty($result['ok'])) {
                    $failed++;
                    $errors[] = ['product_id' => $productId, 'error' => (string) ($result['error'] ?? 'Failed')];
                    continue;
                }

                OpencartMapping::put(OpencartMapping::TYPE_PRODUCT, $productId, (int) $result['product_id']);

                foreach ((array) ($result['variants'] ?? []) as $variant) {
                    $variantId = (int) substr((string) ($variant['ref'] ?? ''), 1);
                    if (empty($variant['ok'])) {
                        $errors[] = ['product_id' => $productId, 'variant_id' => $variantId, 'error' => (string) ($variant['error'] ?? 'Failed')];
                        continue;
                    }
                    OpencartMapping::put(OpencartMapping::TYPE_VARIANT, $variantId, (int) $variant['product_id'], [
                        'product_id' => $productId,
                    ]);
                }

                ($result['action'] ?? '') === 'created' ? $created++ : $updated++;

                foreach ((array) ($result['warnings'] ?? []) as $warning) {
                    $warnings[] = ['product_id' => $productId, 'warning' => (string) $warning];
                }
            }

            $this->pushStockFor($products->pluck('id')->all());
        }

        $this->settings->forceFill(['last_sync_at' => now()])->save();
        $this->log('products.push', ($failed || $errors || $warnings) ? 'warning' : 'info', "Products push batch: {$created} created, {$updated} updated, {$skipped} skipped, {$failed} failed", [
            'errors' => array_slice($errors, 0, 10),
            'warnings' => array_slice($warnings, 0, 10),
        ]);

        return [
            'ok' => true,
            'processed' => $products->count(),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
            'last_id' => $lastId,
            'has_more' => $productIds === null && $products->count() === $batch,
        ];
    }

    /** Whether a product not yet in the shop belongs there. */
    private function isListable(Product $product): bool
    {
        return (bool) $product->is_active && ! $product->hide_from_online_store && ! $product->not_selling;
    }

    private function productPayload(Product $product, ?int $remoteId): array
    {
        [$quantity, $subtract] = $this->stockOf($product);

        $payload = [
            'ref' => 'p'.$product->id,
            'model' => $this->modelFor(OpencartMapping::TYPE_PRODUCT, (int) $product->id, (string) $product->code),
            'sku' => (string) ($this->settings->pushCodes((string) $product->code)['sku'] ?? ''),
            'codes' => $this->settings->pushCodes((string) $product->code),
            'name' => trim((string) $product->name) ?: 'Product '.$product->id,
            'description' => (string) $product->note,
            'price' => (float) $product->price,
            'status' => $this->isListable($product) ? 1 : 0,
            'subtract' => $subtract,
            'quantity' => $quantity,
            'weight' => (float) $product->weight,
            'category' => (string) optional(Category::find($product->category_id))->name,
            'image_url' => $this->imageUrl($product),
        ];
        if ($remoteId) {
            $payload['product_id'] = $remoteId;
        }

        if ($product->type === 'is_variant') {
            $payload += $this->variantsPayload($product);
        }

        return $payload;
    }

    /**
     * Options and variants for the connector. A product with Color / Size
     * options sends those; an older product only has variant names, which
     * become the values of one "Variant" option.
     */
    private function variantsPayload(Product $product): array
    {
        $variants = ProductVariant::whereNull('deleted_at')->where('product_id', $product->id)->orderBy('id')->get();
        $service = app(ProductOptionService::class);
        $options = $service->optionNamesAndValues((int) $product->id);
        $valuesMap = $options ? $service->variantValuesMap((int) $product->id) : [];

        if (! $options) {
            $options = [['name' => 'Variant', 'values' => $variants->pluck('name')->map(fn ($n) => (string) $n)->all()]];
        }

        $rows = [];
        foreach ($variants as $variant) {
            $values = $valuesMap[$variant->id] ?? ['Variant' => (string) $variant->name];
            [$quantity] = $this->stockOf($product, (int) $variant->id);

            $row = [
                'ref' => 'v'.$variant->id,
                'model' => $this->modelFor(OpencartMapping::TYPE_VARIANT, (int) $variant->id, (string) ($variant->code ?: $product->code.'-'.$variant->id)),
                'sku' => (string) ($this->settings->pushCodes((string) $variant->code)['sku'] ?? ''),
                'codes' => $this->settings->pushCodes((string) $variant->code),
                'price' => (float) ($variant->price ?: $product->price),
                'status' => 1,
                'quantity' => $quantity,
                'values' => $values,
            ];
            $remoteId = OpencartMapping::opencartId(OpencartMapping::TYPE_VARIANT, (int) $variant->id);
            if ($remoteId) {
                $row['product_id'] = $remoteId;
            }
            $rows[] = $row;
        }

        return ['options' => $options, 'variants' => $rows];
    }

    private function imageUrl(Product $product): string
    {
        $image = trim((string) $product->image);
        if ($image === '' || strtolower($image) === 'no-image.png') {
            return '';
        }

        if (preg_match('#^https?://#i', $image)) {
            return $image;
        }

        return is_file(upload_public_path('products').'/'.$image) ? product_image_url($image) : '';
    }

    // -----------------------------------------------------------------
    // Products: OpenCart to Stocky
    // -----------------------------------------------------------------

    public function pullProducts(int $page = 1, int $perPage = 50, bool $importStock = true): array
    {
        $page = max(1, $page);
        $response = $this->client->call('products.list', [
            'page' => $page,
            'limit' => $perPage,
            'store_id' => (int) $this->settings->store_id,
        ], 120);

        $created = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];
        $singled = [];
        $this->suffixedCodes = [];
        $this->healedCodes = [];

        // What the store sent for the code: products where the chosen field
        // was blank (their model became the code), and how many products on
        // the page carried each identifier field at all.
        $source = $this->settings->codeSource();
        $fallback = [];
        $codesSeen = array_fill_keys(OpencartSetting::CODE_FIELDS, 0);
        $connectorCodes = false;
        foreach ((array) ($response['products'] ?? []) as $remote) {
            $remote = (array) $remote;
            $connectorCodes = $connectorCodes || array_key_exists('codes', $remote);
            $codes = OpencartSetting::remoteCodes($remote);
            foreach ($codes as $field => $value) {
                $codesSeen[$field] += $value !== '' ? 1 : 0;
            }
            if ($source !== OpencartSetting::CODE_SOURCE_MODEL && $codes[$source] === '') {
                $fallback[] = '#'.(int) ($remote['product_id'] ?? 0).' '.trim((string) ($remote['model'] ?? ''));
            }
        }

        // Pulled data must not bounce straight back to the store.
        AutoSync::muted(function () use ($response, $importStock, &$created, &$updated, &$failed, &$errors, &$singled) {
            foreach ((array) ($response['products'] ?? []) as $remote) {
                $remote = (array) $remote;
                try {
                    $this->importRemoteProduct($remote, $importStock) === 'created' ? $created++ : $updated++;

                    if (! empty($remote['has_options']) && empty($remote['variants'])) {
                        $singled[] = (string) ($remote['model'] ?? $remote['product_id'] ?? '');
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = ['opencart_product_id' => $remote['product_id'] ?? null, 'error' => $e->getMessage()];
                }
            }
        });

        $hasMore = ! empty($response['has_more']);

        $this->settings->forceFill(['last_sync_at' => now()])->save();
        $summary = "Products pull page {$page}: {$created} created, {$updated} updated, {$failed} failed";
        if ($this->healedCodes) {
            $summary .= ', '.count($this->healedCodes).' codes corrected';
        }
        if ($fallback) {
            $summary .= ', '.count($fallback).' without '.strtoupper($source).' (model used as code)';
        }

        $this->log('products.pull', ($failed || $singled || $this->suffixedCodes || $fallback) ? 'warning' : 'info', $summary, [
            'errors' => array_slice($errors, 0, 10),
            'code_source' => $source,
            // Shop products whose chosen field was blank: "#<shop id> <model>".
            'code_fallback' => array_slice($fallback, 0, 20),
            'code_fallback_count' => count($fallback),
            // Products on the page carrying each identifier; all zero for the
            // chosen field means the store keeps it somewhere the connector does not read.
            'codes_seen' => $codesSeen,
            // false: the store runs a connector older than 1.1.0 (Model and SKU only).
            'connector_codes' => $connectorCodes,
            // Linked products whose fallback code (the model) became the chosen field's value.
            'codes_fixed' => array_slice($this->healedCodes, 0, 20),
            'codes_fixed_count' => count($this->healedCodes),
            // Stock is tracked per product for these, not per option.
            'options_without_variants' => array_slice($singled, 0, 20),
            // The store's own count for this store id, to compare with what arrives.
            'store_total' => (int) ($response['total'] ?? 0),
            'per_page' => $perPage,
            // Codes another shop product already holds here: created as code-<shop id>.
            'codes_suffixed' => array_slice($this->suffixedCodes, 0, 20),
        ]);

        return [
            'ok' => true,
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'errors' => $errors,
            'next_page_info' => $hasMore ? $page + 1 : null,
            'has_more' => $hasMore,
            'store_total' => (int) ($response['total'] ?? 0),
        ];
    }

    /**
     * Bring in the product behind an order line that matched nothing. The
     * shop has already taken the order's units from its quantity, and the
     * sale about to be created takes them again here, so the opening stock
     * is the shop's figure plus what this order holds.
     *
     * @param  array<int, float>  $heldByRemoteId  units on the order, by product or variant id
     */
    public function importProductById(int $remoteProductId, array $heldByRemoteId = []): bool
    {
        $response = $this->client->call('products.get', ['product_id' => $remoteProductId]);
        if (empty($response['product'])) {
            return false;
        }

        $this->importRemoteProduct((array) $response['product'], true, $heldByRemoteId);

        return true;
    }

    /**
     * @param  array<int, float>  $extra  units to add to the opening stock, by remote id
     * @return string 'created' | 'updated'
     */
    private function importRemoteProduct(array $remote, bool $importStock, array $extra = []): string
    {
        $remoteId = (int) ($remote['product_id'] ?? 0);
        $model = trim((string) ($remote['model'] ?? ''));
        $codes = OpencartSetting::remoteCodes($remote);
        $name = trim((string) ($remote['name'] ?? '')) ?: 'OpenCart product';
        $price = (float) ($remote['price'] ?? 0);
        $variants = (array) ($remote['variants'] ?? []);

        $localId = OpencartMapping::localId(OpencartMapping::TYPE_PRODUCT, $remoteId);
        $product = $localId ? Product::whereNull('deleted_at')->find($localId) : null;

        foreach ($this->settings->codeCandidates($model, $codes) as $code) {
            if (! $product) {
                $product = $this->unlinkedProductByCode($code, $remoteId);
            }
        }

        // A variant already held locally points at its product.
        foreach ($variants as $variant) {
            $variant = (array) $variant;
            foreach ($this->settings->codeCandidates(trim((string) ($variant['model'] ?? '')), OpencartSetting::remoteCodes($variant)) as $code) {
                if (! $product) {
                    $local = ProductVariant::whereNull('deleted_at')->where('code', $code)->first();
                    $product = $local ? $this->unlinkedProductByCode('', $remoteId, (int) $local->product_id) : null;
                }
            }
        }

        $isNew = ! $product;

        DB::transaction(function () use (&$product, $isNew, $remote, $remoteId, $model, $codes, $name, $price, $variants, $importStock, $extra) {
            if ($isNew) {
                $product = $this->createLocalProduct($remote, $remoteId, $this->settings->codeOf($model, $codes), $name, $price, $variants, $importStock, $extra);
            } else {
                $product->name = $name;
                if ($price > 0 && $product->type !== 'is_variant') {
                    $product->price = $price;
                }
                $this->healCode($product, $model, $codes, $remoteId);
                $product->save();
            }

            OpencartMapping::put(OpencartMapping::TYPE_PRODUCT, (int) $product->id, $remoteId, ['model' => $model, 'codes' => $codes]);

            // Variants can only be added to a product that is a variant
            // product here too; a local single product stays one.
            if ($product->type === 'is_variant') {
                $this->importVariants($product, $variants, $isNew && $importStock, $extra);
            }
        }, 3);

        return $isNew ? 'created' : 'updated';
    }

    /**
     * The local product holding this code (or the given id), unless it is
     * already linked to a different shop product. Several shop products can
     * share a code here (a category typed into the model, a blank ISBN
     * falling back to it): matching them all to one product would collapse
     * the catalogue into one Stocky product per distinct value.
     */
    private function unlinkedProductByCode(string $code, int $remoteId, ?int $productId = null): ?Product
    {
        $product = $productId
            ? Product::whereNull('deleted_at')->find($productId)
            : ($code !== '' ? Product::whereNull('deleted_at')->where('code', $code)->first() : null);
        if (! $product) {
            return null;
        }

        $linked = OpencartMapping::opencartId(OpencartMapping::TYPE_PRODUCT, (int) $product->id);

        return $linked && $linked !== $remoteId ? null : $product;
    }

    /**
     * A linked product created while the chosen code field was blank carries
     * a fallback code: the model (often a category name), the model with the
     * shop id appended, or OC-<shop id>. Once the field arrives it takes that
     * value, unless another product or variant already holds it. A code that
     * is not one of those fallbacks (typed here by hand) is never replaced.
     */
    private function healCode(Product $product, string $model, array $codes, int $remoteId): void
    {
        $source = $this->settings->codeSource();
        if ($source === OpencartSetting::CODE_SOURCE_MODEL) {
            return;
        }

        $wanted = trim((string) ($codes[$source] ?? ''));
        $current = (string) $product->code;
        if ($wanted === '' || $current === $wanted) {
            return;
        }

        $model = trim($model);
        $fallbacks = ['OC-'.$remoteId];
        if ($model !== '') {
            $fallbacks[] = $model;
            $fallbacks[] = $model.'-'.$remoteId;
        }
        if (! in_array($current, $fallbacks, true)) {
            return;
        }

        $taken = Product::whereNull('deleted_at')->where('code', $wanted)->where('id', '!=', $product->id)->exists()
            || ProductVariant::whereNull('deleted_at')->where('code', $wanted)->exists();
        if ($taken) {
            return;
        }

        $product->code = $wanted;
        $this->healedCodes[] = $current.' → '.$wanted;
    }

    /**
     * A code no product or variant holds yet: the given one, or it with the
     * shop id appended when taken, or OC-<shop id> when blank.
     */
    private function freeCode(string $code, int $remoteId): string
    {
        if ($code === '') {
            return 'OC-'.$remoteId;
        }

        $taken = Product::whereNull('deleted_at')->where('code', $code)->exists()
            || ProductVariant::whereNull('deleted_at')->where('code', $code)->exists();
        if (! $taken) {
            return $code;
        }

        $this->suffixedCodes[] = $code;

        return $code.'-'.$remoteId;
    }

    /**
     * The model to push. Under the model setting it is the code. Under any
     * other setting the store's own model is kept (the connector overwrites
     * model on every update), falling back to the code for a product the
     * store lacks.
     */
    private function modelFor(string $type, int $localId, string $code): string
    {
        if ($this->settings->codeFromModel()) {
            return $code;
        }

        $model = trim((string) (OpencartMapping::extraOf($type, $localId)['model'] ?? ''));

        return $model !== '' ? $model : $code;
    }

    private function createLocalProduct(array $remote, int $remoteId, string $code, string $name, float $price, array $variants, bool $importStock, array $extra = []): Product
    {
        $hasVariants = (bool) $variants;

        $product = new Product();
        $product->type = $hasVariants ? 'is_variant' : 'is_single';
        $product->name = $name;
        $product->code = $this->freeCode($code, $remoteId);
        $product->Type_barcode = 'CODE128';
        $product->price = $price;
        $product->cost = 0;
        $product->wholesale_price = 0;
        $product->min_price = 0;
        $product->category_id = $this->resolveCategoryId((string) ($remote['category'] ?? ''));
        $product->unit_id = $this->defaultUnitId();
        $product->unit_sale_id = $product->unit_id;
        $product->unit_purchase_id = $product->unit_id;
        $product->tax_method = '1';
        $product->stock_alert = 0;
        $product->weight = (float) ($remote['weight'] ?? 0);
        $product->is_variant = $hasVariants ? 1 : 0;
        $product->is_active = ! empty($remote['status']) ? 1 : 0;
        $product->note = trim(strip_tags((string) ($remote['description'] ?? ''))) ?: null;
        $product->image = $this->downloadImage((string) ($remote['image'] ?? ''), $remoteId) ?? 'no-image.png';
        $product->save();

        if (! $hasVariants) {
            $openingWarehouse = $importStock ? $this->openingWarehouseId() : null;
            foreach (Warehouse::whereNull('deleted_at')->pluck('id') as $warehouseId) {
                product_warehouse::firstOrCreate([
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'product_variant_id' => null,
                ], [
                    'qte' => (int) $warehouseId === $openingWarehouse ? max(0, (float) ($remote['quantity'] ?? 0)) + (float) ($extra[$remoteId] ?? 0) : 0,
                    'manage_stock' => 1,
                ]);
            }
        }

        return $product;
    }

    private function importVariants(Product $product, array $variants, bool $openingStock, array $extra = []): void
    {
        $openingWarehouse = $openingStock ? $this->openingWarehouseId() : null;
        $namedValues = [];

        foreach ($variants as $remote) {
            $remote = (array) $remote;
            $remoteId = (int) ($remote['product_id'] ?? 0);
            $model = trim((string) ($remote['model'] ?? ''));
            $codes = OpencartSetting::remoteCodes($remote);

            $localId = OpencartMapping::localId(OpencartMapping::TYPE_VARIANT, $remoteId);
            $variant = $localId ? ProductVariant::whereNull('deleted_at')->find($localId) : null;
            foreach ($this->settings->codeCandidates($model, $codes) as $code) {
                if (! $variant) {
                    $variant = ProductVariant::whereNull('deleted_at')
                        ->where('product_id', $product->id)
                        ->where('code', $code)
                        ->first();
                }
            }

            $isNew = ! $variant;
            if ($isNew) {
                $variant = new ProductVariant();
                $variant->product_id = $product->id;
                $variant->code = $this->freeCode($this->settings->codeOf($model, $codes), $remoteId);
                $variant->qty = 0;
                $variant->cost = 0;
            }
            $variant->name = trim((string) ($remote['name'] ?? '')) ?: $variant->code;
            if ((float) ($remote['price'] ?? 0) > 0) {
                $variant->price = (float) $remote['price'];
            }
            $variant->save();

            foreach (Warehouse::whereNull('deleted_at')->pluck('id') as $warehouseId) {
                product_warehouse::firstOrCreate([
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'product_variant_id' => $variant->id,
                ], [
                    'qte' => $isNew && (int) $warehouseId === $openingWarehouse ? max(0, (float) ($remote['quantity'] ?? 0)) + (float) ($extra[$remoteId] ?? 0) : 0,
                    'manage_stock' => 1,
                ]);
            }

            OpencartMapping::put(OpencartMapping::TYPE_VARIANT, (int) $variant->id, $remoteId, [
                'product_id' => (int) $product->id,
                'model' => $model,
            ]);

            if (! empty($remote['values'])) {
                $namedValues[(int) $variant->id] = (array) $remote['values'];
            }
        }

        if ($namedValues && ! app(ProductOptionService::class)->hasOptions((int) $product->id)) {
            app(ProductOptionService::class)->importNamedValues($product, $namedValues);
        }
    }

    /** Stored under the tenant's product images. A failure is not worth losing the product. */
    private function downloadImage(string $url, int $remoteId): ?string
    {
        if ($url === '' || Client::urlProblem($url)) {
            return null;
        }

        try {
            $response = Http::timeout(15)->get($url);
            $bytes = (string) $response->body();
            $type = $response->successful() ? (@getimagesizefromstring($bytes)[2] ?? 0) : 0;
            $extension = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$type] ?? null;
            if (! $extension) {
                return null;
            }

            $directory = upload_public_path('products');
            if (! is_dir($directory)) {
                @mkdir($directory, 0755, true);
            }

            $filename = 'opencart_'.$remoteId.'_'.uniqid().'.'.$extension;

            return file_put_contents($directory.DIRECTORY_SEPARATOR.$filename, $bytes) === false ? null : $filename;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // -----------------------------------------------------------------
    // Stock
    // -----------------------------------------------------------------

    public function pushInventory(int $startAfterId = 0, int $batch = 25): array
    {
        $mappings = OpencartMapping::where('entity_type', OpencartMapping::TYPE_PRODUCT)
            ->where('id', '>', $startAfterId)
            ->orderBy('id')
            ->limit($batch)
            ->get();

        $result = $this->pushStockFor($mappings->pluck('local_id')->all());

        $this->log('inventory.push', $result['missing'] ? 'warning' : 'info', "Stock push batch: {$result['updated']} updated, {$result['missing']} no longer in the store", []);

        return [
            'ok' => true,
            'processed' => $mappings->count(),
            'updated' => $result['updated'],
            'skipped' => $result['missing'],
            'failed' => 0,
            'errors' => [],
            'last_id' => $mappings->isEmpty() ? $startAfterId : (int) $mappings->last()->id,
            'has_more' => $mappings->count() === $batch,
        ];
    }

    /** One full pass over every linked product: the scheduled reconcile. */
    public function reconcileStock(): int
    {
        $updated = 0;
        $cursor = 0;
        do {
            $result = $this->pushInventory($cursor, 50);
            $updated += (int) $result['updated'];
            $cursor = (int) $result['last_id'];
        } while ($result['has_more']);

        return $updated;
    }

    /**
     * Send the current stock of these products and their variants.
     *
     * Always the absolute Stocky figure. That is also what repairs OpenCart
     * after an order: bought from a master page, it takes the units from the
     * master and leaves the variant's quantity untouched.
     *
     * @param  int[]  $productIds
     * @return array{updated:int, missing:int}
     */
    public function pushStockFor(array $productIds): array
    {
        $items = [];
        $byRemote = [];

        $mappings = OpencartMapping::where('entity_type', OpencartMapping::TYPE_PRODUCT)
            ->whereIn('local_id', $productIds)
            ->get();
        $products = Product::whereIn('id', $mappings->pluck('local_id'))->get()->keyBy('id');

        foreach ($mappings as $mapping) {
            $product = $products->get($mapping->local_id);
            if (! $product) {
                continue;
            }

            foreach ($this->stockItems($product, (int) $mapping->opencart_id) as $item) {
                $items[] = $item;
                $byRemote[$item['product_id']] = $item['type'];
            }
        }

        $updated = 0;
        $missing = 0;
        foreach (array_chunk($items, self::STOCK_CHUNK) as $chunk) {
            $response = $this->client->call('stock.set', [
                // `type` tells the OpenCart 3 connector a variant is an option value.
                'items' => $chunk,
            ]);
            $updated += (int) ($response['updated'] ?? 0);

            // Deleted in OpenCart: unlink, and the next product push recreates it.
            foreach ((array) ($response['missing'] ?? []) as $remoteId) {
                $missing++;
                OpencartMapping::where('entity_type', $byRemote[$remoteId] ?? OpencartMapping::TYPE_PRODUCT)
                    ->where('opencart_id', (int) $remoteId)
                    ->delete();
            }
        }

        return ['updated' => $updated, 'missing' => $missing];
    }

    /** The master row plus one row per linked variant. */
    private function stockItems(Product $product, int $remoteId): array
    {
        if ($product->type !== 'is_variant') {
            [$quantity, $subtract] = $this->stockOf($product);

            return [['product_id' => $remoteId, 'quantity' => $quantity, 'subtract' => $subtract, 'type' => OpencartMapping::TYPE_PRODUCT]];
        }

        $variantIds = ProductVariant::whereNull('deleted_at')->where('product_id', $product->id)->pluck('id');
        $links = OpencartMapping::where('entity_type', OpencartMapping::TYPE_VARIANT)
            ->whereIn('local_id', $variantIds)
            ->pluck('opencart_id', 'local_id');

        // The master shows the total over every variant, linked or not: on
        // OpenCart 3 a multi-option product has no linked variants at all,
        // and the master's own figure is the only stock it has.
        $items = [];
        $total = 0;
        $subtract = 1;
        foreach ($variantIds as $variantId) {
            [$quantity, $variantSubtract] = $this->stockOf($product, (int) $variantId);
            $total += $quantity;
            $subtract = min($subtract, $variantSubtract);
            if (isset($links[$variantId])) {
                $items[] = ['product_id' => (int) $links[$variantId], 'quantity' => $quantity, 'subtract' => $variantSubtract, 'type' => OpencartMapping::TYPE_VARIANT];
            }
        }

        $items[] = ['product_id' => $remoteId, 'quantity' => $total, 'subtract' => $subtract, 'type' => OpencartMapping::TYPE_PRODUCT];

        return $items;
    }

    /**
     * [quantity, subtract] the shop should show, in the unit the product is
     * sold in. Services, "always in stock" products and unlimited combos never
     * run out, so OpenCart is told not to count them.
     *
     * @return array{0:int, 1:int}
     */
    private function stockOf(Product $product, ?int $variantId = null): array
    {
        if ($product->type === 'is_service' || $product->always_in_stock) {
            return [self::UNLIMITED, 0];
        }

        if ($product->type === 'is_combo' && ComboStockService::enabled()) {
            $available = app(ComboStockService::class)->availability([(int) $product->id], $this->stockWarehouseIds())[(int) $product->id] ?? 0;

            return $available === null ? [self::UNLIMITED, 0] : [$this->toSaleUnits($product, (float) $available), 1];
        }

        $query = product_warehouse::where('product_id', $product->id)
            ->whereNull('deleted_at')
            ->whereIn('warehouse_id', $this->stockWarehouseIds());
        $variantId ? $query->where('product_variant_id', $variantId) : $query->whereNull('product_variant_id');

        $rows = $query->get(['qte', 'manage_stock']);
        if ($rows->isNotEmpty() && $rows->every(fn ($row) => ! $row->manage_stock)) {
            return [self::UNLIMITED, 0];
        }

        return [$this->toSaleUnits($product, (float) $rows->sum('qte')), 1];
    }

    private function toSaleUnits(Product $product, float $baseQuantity): int
    {
        $unit = $product->unit_sale_id ? Unit::find($product->unit_sale_id) : null;
        $factor = (float) ($unit->operator_value ?? 1) ?: 1;
        $quantity = ($unit->operator ?? '*') === '/' ? $baseQuantity * $factor : $baseQuantity / $factor;

        return max(0, (int) floor($quantity + 1e-9));
    }

    /** @return int[] */
    private function stockWarehouseIds(): array
    {
        return $this->settings->warehouse_id
            ? [(int) $this->settings->warehouse_id]
            : Warehouse::whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function openingWarehouseId(): ?int
    {
        $id = $this->settings->warehouse_id ?: Warehouse::whereNull('deleted_at')->orderBy('id')->value('id');

        return $id ? (int) $id : null;
    }

    // -----------------------------------------------------------------
    // Stats / reset / logging / helpers
    // -----------------------------------------------------------------

    public function stats(): array
    {
        $count = fn (string $type) => OpencartMapping::where('entity_type', $type)->count();

        return [
            'products_total' => Product::whereNull('deleted_at')->count(),
            'products_mapped' => $count(OpencartMapping::TYPE_PRODUCT),
            'variants_mapped' => $count(OpencartMapping::TYPE_VARIANT),
            'customers_total' => Customer::whereNull('deleted_at')->count(),
            'customers_mapped' => $count(OpencartMapping::TYPE_CUSTOMER),
            'orders_imported' => $count(OpencartMapping::TYPE_ORDER),
        ];
    }

    public function resetMappings(?string $entityType = null): int
    {
        $query = OpencartMapping::query();
        if ($entityType) {
            $query->whereIn('entity_type', $entityType === OpencartMapping::TYPE_PRODUCT
                ? [OpencartMapping::TYPE_PRODUCT, OpencartMapping::TYPE_VARIANT]
                : [$entityType]);
        } else {
            // Order links stay by default: they guard against duplicate sales.
            $query->where('entity_type', '!=', OpencartMapping::TYPE_ORDER);
        }

        return $query->delete();
    }

    public function log(string $action, string $level, string $message, array $context = []): void
    {
        try {
            OpencartLog::create([
                'action' => $action,
                'level' => $level,
                'message' => $message,
                'context' => $context,
            ]);
        } catch (\Throwable $e) {
            // Logging must never break a sync
        }
    }

    private function resolveCategoryId(string $name): int
    {
        $name = trim($name);
        if ($name !== '') {
            $category = Category::whereNull('deleted_at')->where('name', $name)->first();

            return (int) ($category ?: Category::create(['code' => 'OC'.substr(md5($name), 0, 8), 'name' => $name]))->id;
        }

        $first = Category::whereNull('deleted_at')->first();

        return (int) ($first ?: Category::create(['code' => 'OPENCART', 'name' => 'OpenCart']))->id;
    }

    private function defaultUnitId(): ?int
    {
        $unit = DB::table('units')->whereNull('deleted_at')->orderBy('id')->first();

        return $unit ? (int) $unit->id : null;
    }
}
