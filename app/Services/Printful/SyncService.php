<?php

namespace App\Services\Printful;

use App\Models\Category;
use App\Models\PrintfulLog;
use App\Models\PrintfulMapping;
use App\Models\PrintfulOrder;
use App\Models\PrintfulSetting;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\product_warehouse;
use App\Services\StoreCurrencyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Intervention\Image\ImageManagerStatic as Image;

/**
 * Printful catalog side: connection / store picking, webhook registration,
 * and importing the store's sync products into Stocky.
 *
 * Products are designed in Printful (a "Manual order / API" store) and pulled
 * here: one Stocky product per sync product, one variant per synced sync
 * variant (a one-variant product becomes a simple product). Imported products
 * are "always in stock" — Printful prints to order — and their mockups become
 * the product / variant images. Re-importing updates names and mappings but
 * keeps the store's own prices and images unless told otherwise.
 */
class SyncService
{
    /** Webhook events Printful sends us (one URL per store). */
    public const WEBHOOK_EVENTS = [
        'package_shipped', 'package_returned',
        'order_updated', 'order_failed', 'order_canceled', 'order_put_hold', 'order_remove_hold',
        'product_synced', 'product_updated', 'product_deleted',
    ];

    private PrintfulSetting $settings;
    private Client $client;

    /** Mockups already saved during this run, by URL. */
    private array $imageCache = [];

    public function __construct(PrintfulSetting $settings, ?Client $client = null)
    {
        $this->settings = $settings;
        $this->client = $client ?: Client::make($settings);
    }

    public static function make(?PrintfulSetting $settings = null): self
    {
        return new self($settings ?: PrintfulSetting::current());
    }

    // -----------------------------------------------------------------
    // Connection
    // -----------------------------------------------------------------

    /**
     * Stores the token can reach (GET /stores: every store for an
     * account-level token, its own store for a store-level one).
     *
     * @return list<array{id: int, name: string, type: ?string}>
     */
    public function stores(): array
    {
        $result = $this->client->forStore(null)->get('stores')['result'] ?? [];

        $stores = [];
        foreach ((array) $result as $store) {
            if (! is_array($store) || empty($store['id'])) {
                continue;
            }
            $stores[] = [
                'id' => (int) $store['id'],
                'name' => (string) ($store['name'] ?? ('Store '.$store['id'])),
                'type' => isset($store['type']) ? (string) $store['type'] : null,
            ];
        }

        return $stores;
    }

    /**
     * Checks the token, picks the store when there is only one, and confirms
     * the store's products can be read.
     */
    public function testConnection(): array
    {
        try {
            $stores = $this->stores();
            if (! $stores) {
                return ['ok' => false, 'error' => 'This token does not reach any Printful store.', 'stores' => []];
            }

            $chosen = collect($stores)->firstWhere('id', (int) $this->settings->store_id);
            if (! $chosen && count($stores) === 1) {
                $chosen = $stores[0];
                $this->settings->store_id = $chosen['id'];
            }
            if (! $chosen) {
                // A store this token can't reach (or none yet): ask again.
                if ($this->settings->store_id || $this->settings->store_name) {
                    $this->settings->forceFill(['store_id' => null, 'store_name' => null, 'webhook_registered_at' => null])->save();
                }

                return ['ok' => false, 'error' => 'Choose which Printful store to use.', 'stores' => $stores, 'needs_store' => true];
            }

            $this->settings->store_name = $chosen['name'];
            $this->settings->save();

            $this->client->get('store/products', ['limit' => 1]);

            return ['ok' => true, 'stores' => $stores, 'store' => $chosen];
        } catch (PrintfulException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'stores' => []];
        }
    }

    // -----------------------------------------------------------------
    // Webhooks
    // -----------------------------------------------------------------

    /** Points the store's webhook at this tenant (replaces any other URL). */
    public function registerWebhooks(): array
    {
        try {
            $this->client->post('webhooks', [
                'url' => $this->settings->webhookUrl(),
                'types' => self::WEBHOOK_EVENTS,
            ]);
            $this->settings->forceFill(['webhook_registered_at' => now()])->save();
            $this->log('webhooks.register', 'info', 'Webhooks registered: '.$this->settings->webhookUrl());

            return ['ok' => true];
        } catch (PrintfulException $e) {
            $this->log('webhooks.register', 'error', $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** The webhook Printful currently has for the store, if any. */
    public function webhookStatus(): array
    {
        try {
            $result = $this->client->get('webhooks')['result'] ?? [];
            $url = (string) ($result['url'] ?? '');

            return [
                'ok' => true,
                'url' => $url ?: null,
                'ours' => $url !== '' && hash_equals($this->settings->webhookUrl(), $url),
                'types' => array_values((array) ($result['types'] ?? [])),
            ];
        } catch (PrintfulException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // -----------------------------------------------------------------
    // Product import
    // -----------------------------------------------------------------

    /**
     * One page of the store's sync products, each fetched in full and
     * imported. Cursor = list offset (surfaced as next_page_info).
     */
    public function pullProducts(int $offset = 0, int $limit = 10): array
    {
        $offset = max(0, $offset);
        $limit = max(1, min(20, $limit));

        try {
            $page = $this->client->get('store/products', ['offset' => $offset, 'limit' => $limit]);
        } catch (PrintfulException $e) {
            $this->log('products.pull', 'error', $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ((array) ($page['result'] ?? []) as $summary) {
            $id = (int) ($summary['id'] ?? 0);
            if (! $id || ! empty($summary['is_ignored'])) {
                $skipped++;
                continue;
            }
            try {
                $result = $this->importSyncProduct($id);
                if ($result === 'created') {
                    $created++;
                } elseif ($result === 'updated') {
                    $updated++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['printful_product_id' => $id, 'name' => $summary['name'] ?? null, 'error' => $e->getMessage()];
            }
        }

        $total = (int) ($page['paging']['total'] ?? 0);
        $next = $offset + $limit;
        $hasMore = $next < $total && count((array) ($page['result'] ?? [])) > 0;

        $this->settings->forceFill(['last_sync_at' => now()])->save();
        $this->log('products.pull', $failed ? 'warning' : 'info', "Products {$offset}–".min($next, $total)." of {$total}: {$created} created, {$updated} updated, {$skipped} skipped, {$failed} failed", [
            'errors' => array_slice($errors, 0, 10),
        ]);

        return [
            'ok' => true,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
            'next_page_info' => $hasMore ? $next : null,
            'has_more' => $hasMore,
        ];
    }

    /** @return string created|updated|skipped */
    public function importSyncProduct(int $syncProductId): string
    {
        $detail = $this->client->get('store/products/'.$syncProductId)['result'] ?? [];
        $remote = (array) ($detail['sync_product'] ?? []);
        $variants = array_values(array_filter((array) ($detail['sync_variants'] ?? []), function ($v) {
            // Only variants Printful can actually print (catalog variant + files).
            return is_array($v) && ! empty($v['id']) && empty($v['is_ignored']) && ($v['synced'] ?? true) !== false;
        }));

        if (! $remote || ! $variants) {
            $this->log('products.import', 'warning', 'Skipped "'.($remote['name'] ?? $syncProductId).'": no printable variants (add print files in Printful first).', [
                'printful_product_id' => $syncProductId,
            ]);

            return 'skipped';
        }

        // Mockups are fetched first: no HTTP while the transaction holds locks.
        $this->downloadImage($remote['thumbnail_url'] ?? null);
        foreach ($variants as $rv) {
            $this->downloadImage($this->variantPreview($rv));
        }

        return DB::transaction(fn () => $this->importRemote($remote, $variants));
    }

    private function importRemote(array $remote, array $variants): string
    {
        $syncProductId = (int) $remote['id'];
        $name = trim((string) ($remote['name'] ?? '')) ?: 'Printful product '.$syncProductId;

        $product = $this->findLocalProduct($syncProductId, $variants);
        // A variant product stays one even when Printful is down to one
        // variant, so the links of the variants that went away are dropped.
        $isVariant = count($variants) > 1 || ($product && (int) $product->is_variant === 1);

        // Printful's retail currency (what the exchange rate converts from).
        $currency = strtoupper((string) ($variants[0]['currency'] ?? ''));
        if ($currency !== '' && $currency !== $this->settings->currency) {
            $this->settings->forceFill(['currency' => $currency])->save();
        }
        $prices = array_map(fn ($v) => $this->toStorePrice($v['retail_price'] ?? null, $v['currency'] ?? null), $variants);
        $known = array_filter($prices, fn ($p) => $p !== null);
        $basePrice = $known ? min($known) : null;

        $isNew = ! $product;
        if ($isNew) {
            $product = new Product();
            $product->type = $isVariant ? 'is_variant' : 'is_single';
            $product->is_variant = $isVariant ? 1 : 0;
            $product->name = $name;
            $product->code = $this->uniqueProductCode(
                ! $isVariant && trim((string) ($variants[0]['sku'] ?? '')) !== '' ? trim((string) $variants[0]['sku']) : 'PF-'.$syncProductId
            );
            $product->Type_barcode = 'CODE128';
            $product->price = $basePrice ?? 0;
            $product->cost = 0;
            $product->wholesale_price = 0;
            $product->min_price = 0;
            $product->category_id = $this->categoryId();
            $product->unit_id = $this->defaultUnitId();
            $product->unit_sale_id = $product->unit_id;
            $product->unit_purchase_id = $product->unit_id;
            $product->tax_method = '1';
            $product->stock_alert = 0;
            // No price we can trust (Printful currency with no exchange rate):
            // keep it off sale until the store prices it.
            $product->is_active = $basePrice === null ? 0 : 1;
            if ($this->hasColumn('always_in_stock')) {
                $product->always_in_stock = 1;
            }
            $product->image = $this->downloadImage($remote['thumbnail_url'] ?? null) ?? 'no-image.png';
            $product->save();

            if ($basePrice === null) {
                $this->log('products.import', 'warning', "\"{$name}\" was imported inactive: its Printful prices are in {$currency}, the store sells in ".$this->storeCurrency().'. Set an exchange rate on the Connection tab, or price it by hand.', [
                    'product_id' => $product->id,
                ]);
            }
        } else {
            $product->name = $name;
            if ($isVariant && (int) $product->is_variant !== 1) {
                $product->type = 'is_variant';
                $product->is_variant = 1;
            }
            if (! $isVariant && $this->settings->update_prices && $basePrice !== null) {
                $product->price = $basePrice;
            }
            if ($this->hasColumn('always_in_stock')) {
                $product->always_in_stock = 1;
            }
            if (in_array((string) $product->image, ['', 'no-image.png'], true)) {
                $product->image = $this->downloadImage($remote['thumbnail_url'] ?? null) ?? 'no-image.png';
            }
            $product->save();
        }

        PrintfulMapping::put(PrintfulMapping::TYPE_PRODUCT, (int) $product->id, $syncProductId, ['name' => $name]);

        if ($isVariant) {
            $this->importVariants($product, $syncProductId, $variants, $isNew);
        } else {
            $this->ensureStockRows($product->id, null);
            PrintfulMapping::put(PrintfulMapping::TYPE_SINGLE, (int) $product->id, (int) $variants[0]['id'], $this->variantExtra($variants[0], $syncProductId));
        }

        $this->addToGallery($product, $isNew);

        return $isNew ? 'created' : 'updated';
    }

    private function importVariants(Product $product, int $syncProductId, array $variants, bool $isNew): void
    {
        $seen = [];
        foreach ($variants as $rv) {
            $syncVariantId = (int) $rv['id'];
            $seen[] = $syncVariantId;
            $sku = trim((string) ($rv['sku'] ?? ''));

            $localId = PrintfulMapping::localId(PrintfulMapping::TYPE_VARIANT, $syncVariantId);
            $variant = $localId ? ProductVariant::whereNull('deleted_at')->where('product_id', $product->id)->find($localId) : null;
            if (! $variant && $sku !== '') {
                $variant = ProductVariant::whereNull('deleted_at')->where('product_id', $product->id)->where('code', $sku)->first();
            }

            $price = $this->toStorePrice($rv['retail_price'] ?? null, $rv['currency'] ?? null);
            if (! $variant) {
                $variant = new ProductVariant();
                $variant->product_id = $product->id;
                $variant->code = $this->uniqueProductCode($sku !== '' ? $sku : 'PF-'.$syncVariantId);
                $variant->qty = 0;
                $variant->cost = 0;
                $variant->price = $price ?? 0;
                $variant->image = $this->downloadImage($this->variantPreview($rv)) ?? 'no-image.png';
            } else {
                if ($this->settings->update_prices && $price !== null) {
                    $variant->price = $price;
                }
                if (in_array((string) $variant->image, ['', 'no-image.png'], true)) {
                    $variant->image = $this->downloadImage($this->variantPreview($rv)) ?? 'no-image.png';
                }
            }
            $variant->name = $this->variantName($rv);
            $variant->save();

            $this->ensureStockRows($product->id, (int) $variant->id);
            PrintfulMapping::put(PrintfulMapping::TYPE_VARIANT, (int) $variant->id, $syncVariantId, $this->variantExtra($rv, $syncProductId));
        }

        // Variants removed in Printful can no longer be ordered: drop their links.
        $localVariantIds = ProductVariant::where('product_id', $product->id)->pluck('id');
        PrintfulMapping::where('entity_type', PrintfulMapping::TYPE_VARIANT)
            ->whereIn('local_id', $localVariantIds)
            ->whereNotIn('printful_id', $seen)
            ->delete();
        // A product that used to be simple has no single link any more.
        PrintfulMapping::where('entity_type', PrintfulMapping::TYPE_SINGLE)->where('local_id', $product->id)->delete();
    }

    /**
     * The product this Printful product was imported as. Existing products
     * are never adopted by SKU: that would switch a self-shipped product to
     * "always in stock" and let its orders go to Printful.
     */
    private function findLocalProduct(int $syncProductId, array $variants): ?Product
    {
        $localId = PrintfulMapping::localId(PrintfulMapping::TYPE_PRODUCT, $syncProductId);

        return $localId ? Product::whereNull('deleted_at')->find($localId) : null;
    }

    private function variantExtra(array $rv, int $syncProductId): array
    {
        return [
            'sync_product_id' => $syncProductId,
            'catalog_variant_id' => (int) ($rv['variant_id'] ?? ($rv['product']['variant_id'] ?? 0)) ?: null,
            'external_id' => $rv['external_id'] ?? null,
            'sku' => $rv['sku'] ?? null,
            'name' => $rv['name'] ?? null,
            'retail_price' => isset($rv['retail_price']) ? (float) $rv['retail_price'] : null,
            'currency' => $rv['currency'] ?? null,
        ];
    }

    /** "Black / M" from the colour + size, else from "Shirt (Black / M)". */
    private function variantName(array $rv): string
    {
        $parts = array_filter([trim((string) ($rv['color'] ?? '')), trim((string) ($rv['size'] ?? ''))]);
        if ($parts) {
            return implode(' / ', $parts);
        }
        $name = trim((string) ($rv['name'] ?? ''));
        if (preg_match('/\(([^()]+)\)\s*$/', $name, $m)) {
            return trim($m[1]);
        }

        return $name !== '' ? mb_substr($name, 0, 190) : 'Variant '.$rv['id'];
    }

    /** The variant's mockup: its preview file, else the catalog image. */
    private function variantPreview(array $rv): ?string
    {
        foreach ((array) ($rv['files'] ?? []) as $file) {
            if (($file['type'] ?? '') === 'preview' && ! empty($file['preview_url'])) {
                return (string) $file['preview_url'];
            }
        }

        return $rv['product']['image'] ?? null;
    }

    private function ensureStockRows(int $productId, ?int $variantId): void
    {
        foreach (Warehouse::whereNull('deleted_at')->pluck('id') as $warehouseId) {
            product_warehouse::firstOrCreate([
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'product_variant_id' => $variantId,
            ], ['qte' => 0, 'manage_stock' => 1]);
        }
    }

    /** Main image + each distinct variant mockup in the product gallery. */
    private function addToGallery(Product $product, bool $isNew): void
    {
        if (! Schema::hasTable('product_images')) {
            return;
        }

        $files = [];
        if (! in_array((string) $product->image, ['', 'no-image.png'], true)) {
            $files[] = (string) $product->image;
        }
        $variantImages = ProductVariant::whereNull('deleted_at')->where('product_id', $product->id)->pluck('image')->all();
        foreach ($variantImages as $image) {
            if ($image && $image !== 'no-image.png' && str_starts_with((string) $image, 'pf_')) {
                $files[] = (string) $image;
            }
        }

        $sort = (int) ProductImage::where('product_id', $product->id)->max('sort_order');
        $hasMain = ProductImage::where('product_id', $product->id)->where('is_main', true)->exists();
        foreach (array_values(array_unique($files)) as $file) {
            $image = ProductImage::firstOrCreate(
                ['product_id' => $product->id, 'image_path' => $file],
                ['is_main' => ! $hasMain && $isNew, 'sort_order' => ++$sort]
            );
            $hasMain = $hasMain || $image->is_main;
        }
    }

    // -----------------------------------------------------------------
    // Currency
    // -----------------------------------------------------------------

    public function storeCurrency(): string
    {
        return strtoupper((string) (StoreCurrencyService::base()['code'] ?? 'USD'));
    }

    /**
     * A Printful amount in the store's base currency, null when it can't be
     * converted. The exchange rate is for Printful's retail currency only;
     * any other currency (e.g. a different billing currency) is not guessed.
     */
    public function toStorePrice($amount, ?string $currency): ?float
    {
        if ($amount === null || $amount === '' || ! is_numeric($amount)) {
            return null;
        }
        $printfulCurrency = strtoupper((string) ($this->settings->currency ?: 'USD'));
        $currency = strtoupper((string) ($currency ?: $printfulCurrency));
        if ($currency === $this->storeCurrency()) {
            return round((float) $amount, 2);
        }
        $rate = (float) $this->settings->exchange_rate;

        return $rate > 0 && $currency === $printfulCurrency ? round((float) $amount * $rate, 2) : null;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Saves a Printful mockup as a product image (800px max, like uploads).
     * Named after the URL, so importing again reuses the file.
     */
    private function downloadImage(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || ! str_starts_with($url, 'https://')) {
            return null;
        }
        if (array_key_exists($url, $this->imageCache)) {
            return $this->imageCache[$url];
        }

        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'jpg';
        }
        $filename = 'pf_'.substr(md5($url), 0, 20).'.'.$ext;
        $dir = upload_public_path('products');

        try {
            if (! File::exists($dir.'/'.$filename)) {
                $response = Http::timeout(25)->get($url);
                if (! $response->successful() || strlen($response->body()) > 15 * 1024 * 1024) {
                    return $this->imageCache[$url] = null;
                }
                if (! File::isDirectory($dir)) {
                    File::makeDirectory($dir, 0755, true);
                }
                \App\utils\helpers::apply_product_image_resize(Image::make($response->body()))->save($dir.'/'.$filename);
            }

            return $this->imageCache[$url] = $filename;
        } catch (\Throwable $e) {
            return $this->imageCache[$url] = null; // a missing mockup never fails the import
        }
    }

    private function uniqueProductCode(string $code): string
    {
        $code = mb_substr($code, 0, 180);
        $candidate = $code;
        for ($i = 2; Product::where('code', $candidate)->exists() || ProductVariant::where('code', $candidate)->exists(); $i++) {
            $candidate = $code.'-'.$i;
        }

        return $candidate;
    }

    private function categoryId(): int
    {
        if ($this->settings->category_id && Category::whereNull('deleted_at')->find($this->settings->category_id)) {
            return (int) $this->settings->category_id;
        }

        $category = Category::whereNull('deleted_at')->where('code', 'PRINTFUL')->first()
            ?: Category::create(['code' => 'PRINTFUL', 'name' => 'Printful']);

        return (int) $category->id;
    }

    private function defaultUnitId(): ?int
    {
        $unit = DB::table('units')->whereNull('deleted_at')->orderBy('id')->first();

        return $unit ? (int) $unit->id : null;
    }

    private function hasColumn(string $column): bool
    {
        static $columns = [];

        return $columns[(tenant('id') ?? 'central').':'.$column] ??= Schema::hasColumn('products', $column);
    }

    // -----------------------------------------------------------------
    // Stats / logging
    // -----------------------------------------------------------------

    public function stats(): array
    {
        $count = fn (string $type) => PrintfulMapping::where('entity_type', $type)->count();

        return [
            'products_mapped' => $count(PrintfulMapping::TYPE_PRODUCT),
            'variants_mapped' => $count(PrintfulMapping::TYPE_VARIANT) + $count(PrintfulMapping::TYPE_SINGLE),
            'orders_sent' => PrintfulOrder::whereNotNull('printful_order_id')->count(),
            'orders_draft' => PrintfulOrder::where('status', 'draft')->count(),
            'orders_error' => PrintfulOrder::whereIn('status', [PrintfulOrder::STATUS_ERROR, 'failed', 'onhold'])->count(),
            'orders_shipped' => PrintfulOrder::whereNotNull('shipped_at')->count(),
        ];
    }

    public function log(string $action, string $level, string $message, array $context = []): void
    {
        try {
            PrintfulLog::create([
                'action' => $action,
                'level' => $level,
                'message' => $message,
                'context' => $context,
            ]);
        } catch (\Throwable $e) {
            // Logging must never break a sync
        }
    }
}
