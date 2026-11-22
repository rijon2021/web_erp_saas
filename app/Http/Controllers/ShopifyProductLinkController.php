<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\ShopifyLink;
use App\Models\ShopifyLog;
use App\Models\ShopifyStore;
use App\Services\ProductGalleryService;
use App\Services\Shopify\SyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pairing one ERP product with a listing that already exists in a shop.
 *
 * The sync creates a Shopify product for anything it cannot match, which is
 * right for a new catalogue and wrong for a shop that was selling long before
 * the ERP arrived: every product entered here would appear twice over there.
 * This controller is the "no, it is *that* one" path — search the shop from
 * the product form, pick the listing, and the link rows are written for the
 * product, its variants and its inventory item, so stock and orders flow
 * without a product push ever creating anything.
 */
class ShopifyProductLinkController extends BaseController
{
    /**
     * Every store with this product's current pairing in it, for the card on
     * the product form. A product that does not exist yet (the create form)
     * just gets the store list.
     */
    public function stores(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ShopifyStore::class);

        $productId = (int) $request->input('product_id', 0);
        $stores = ShopifyStore::whereNull('deleted_at')->orderBy('name')->get();

        $rows = $stores->map(function (ShopifyStore $store) use ($productId) {
            $link = $productId ? ShopifyLink::findLocal($store->id, 'product', $productId) : null;

            return [
                'id' => $store->id,
                'name' => $store->name,
                'shop_domain' => $store->shop_domain,
                'status' => $store->status,
                'sync_products' => (bool) $store->sync_products,
                'link' => $link ? [
                    'id' => $link->id,
                    'shopify_id' => $link->shopify_id,
                    'handle' => $link->shopify_handle,
                    'last_synced_at' => $link->last_synced_at ? $link->last_synced_at->toDateTimeString() : null,
                    'admin_url' => 'https://'.$store->shop_domain.'/admin/products/'.$link->shopify_id,
                    'variants' => $this->variantLinks($store, $productId),
                ] : null,
            ];
        });

        return response()->json(['stores' => $rows]);
    }

    /** Live search of one shop's catalogue. */
    public function search(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ShopifyStore::class);

        $request->validate([
            'store_id' => 'required|exists:shopify_stores,id',
            'q' => 'required|string|max:120',
        ]);

        $store = ShopifyStore::whereNull('deleted_at')->findOrFail($request->store_id);

        try {
            $rows = SyncService::for($store)->searchRemoteProducts((string) $request->q, 12);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        // Which of these listings are already paired with another ERP product,
        // so the picker can say so instead of silently moving the pairing.
        $taken = ShopifyLink::where('store_id', $store->id)
            ->where('entity_type', 'product')
            ->whereIn('shopify_id', array_column($rows, 'id'))
            ->pluck('local_id', 'shopify_id');
        $names = $taken->isEmpty() ? collect() : Product::whereIn('id', $taken->values())->pluck('name', 'id');

        foreach ($rows as &$row) {
            $localId = $taken[$row['id']] ?? null;
            $row['linked_to'] = $localId ? ['id' => (int) $localId, 'name' => $names[$localId] ?? ('#'.$localId)] : null;
        }

        return response()->json(['products' => $rows]);
    }

    /**
     * Pair the product with the chosen listing.
     *
     * mode "keep" (the default) leaves the shop's listing alone; "replace"
     * lets the next product push overwrite it with the ERP's data.
     * copy_remote pulls the listing's price, barcode and pictures into the
     * ERP product once, so a product entered in a hurry ends up as complete
     * as the listing it now stands for.
     */
    public function link(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', ShopifyStore::class);

        $request->validate([
            'store_id' => 'required|exists:shopify_stores,id',
            'product_id' => 'required|integer|exists:products,id',
            'shopify_id' => 'required|string|max:64',
            'mode' => 'nullable|in:keep,replace',
            'copy_remote' => 'nullable|boolean',
        ]);

        $store = ShopifyStore::whereNull('deleted_at')->findOrFail($request->store_id);
        $product = Product::whereNull('deleted_at')->findOrFail($request->product_id);
        $keep = ($request->input('mode') ?: 'keep') === 'keep';
        $copy = $request->boolean('copy_remote');

        $sync = SyncService::for($store);

        try {
            $remote = $sync->fetchRemoteProduct($request->shopify_id);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if (! $remote) {
            return response()->json(['success' => false, 'message' => 'Shopify has no product with that id.'], 422);
        }

        $claimedBy = ShopifyLink::findRemote($store->id, 'product', $remote['id']);
        if ($claimedBy && (int) $claimedBy->local_id !== (int) $product->id && ! $request->boolean('force')) {
            $other = Product::find($claimedBy->local_id);

            return response()->json([
                'success' => false,
                'code' => 'claimed',
                'message' => 'That Shopify product is already linked to "'.($other->name ?? ('#'.$claimedBy->local_id)).'". Unlink it there first, or confirm moving the link.',
            ], 409);
        }

        $result = DB::transaction(function () use ($sync, $product, $remote, $keep, $copy) {
            if ($copy) {
                $this->copyRemoteInto($product, $remote);
            }

            return $sync->linkRemoteProduct($product->fresh(), $remote, $keep);
        });

        return response()->json(['success' => true] + $result);
    }

    /** Forget the pairing with one shop. Nothing is deleted on either side. */
    public function unlink(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', ShopifyStore::class);

        $request->validate([
            'store_id' => 'required|exists:shopify_stores,id',
            'product_id' => 'required|integer',
        ]);

        $store = ShopifyStore::whereNull('deleted_at')->findOrFail($request->store_id);
        $product = Product::findOrFail($request->product_id);

        $removed = SyncService::for($store)->unlinkProduct($product);

        ShopifyLog::write($store->id, 'product.unlink',
            'Product #'.$product->id.' unlinked from the shop ('.$removed.' link rows removed).',
            'warning', ['product_id' => $product->id], null, 'products');

        return response()->json(['success' => true, 'removed' => $removed]);
    }

    // ---------------------------------------------------------------- helpers --

    private function variantLinks(ShopifyStore $store, int $productId): array
    {
        $variants = ProductVariant::where('product_id', $productId)->whereNull('deleted_at')->get(['id', 'name', 'code']);
        if ($variants->isEmpty()) {
            return [];
        }

        $links = ShopifyLink::where('store_id', $store->id)
            ->where('entity_type', 'variant')
            ->whereIn('local_id', $variants->pluck('id'))
            ->get()
            ->keyBy('local_id');

        return $variants->map(fn ($v) => [
            'local_id' => $v->id,
            'name' => $v->name,
            'sku' => $v->code,
            'shopify_id' => optional($links->get($v->id))->shopify_id,
        ])->values()->all();
    }

    /**
     * Fill the ERP product from the listing: price and barcode from the
     * variants (matched by SKU, then by the variant's name), and the shop's
     * pictures as remote URLs. The ERP already accepts an absolute URL
     * wherever an image filename is expected, so nothing is downloaded.
     */
    private function copyRemoteInto(Product $product, array $remote): void
    {
        $remoteVariants = $remote['variants'] ?? [];
        $first = $remoteVariants[0] ?? [];

        if (isset($first['price'])) {
            $product->price = (float) $first['price'];
        }
        if (! empty($first['barcode']) && ! $product->is_variant) {
            $product->gtin = $first['barcode'];
        }

        if ($product->is_variant) {
            $local = ProductVariant::where('product_id', $product->id)->whereNull('deleted_at')->get();
            foreach ($remoteVariants as $rv) {
                $match = null;
                if (! empty($rv['sku'])) {
                    $match = $local->first(fn ($v) => mb_strtolower(trim((string) $v->code)) === mb_strtolower(trim((string) $rv['sku'])));
                }
                if (! $match && ! empty($rv['title'])) {
                    $match = $local->firstWhere('name', $rv['title']);
                }
                if (! $match) {
                    continue;
                }
                if (isset($rv['price'])) {
                    $match->price = (float) $rv['price'];
                }
                if (! empty($rv['barcode']) && Schema::hasColumn('product_variants', 'gtin')) {
                    $match->gtin = $rv['barcode'];
                }
                $match->save();
            }
        }

        $urls = [];
        foreach ($remote['images'] ?? [] as $img) {
            $url = ProductGalleryService::sanitizeRemoteImageUrl($img['src'] ?? null);
            if ($url && ! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        if ($urls) {
            $current = trim((string) $product->image);
            if ($current === '' || $current === 'no-image.png') {
                $product->image = $urls[0];
            }

            if (Schema::hasTable('product_images')) {
                $existing = ProductImage::where('product_id', $product->id)->pluck('image_path')->all();
                $hasMain = ProductImage::where('product_id', $product->id)->where('is_main', 1)->exists();
                $sort = (int) ProductImage::where('product_id', $product->id)->max('sort_order');

                foreach ($urls as $i => $url) {
                    if (in_array($url, $existing, true)) {
                        continue;
                    }
                    ProductImage::create([
                        'product_id' => $product->id,
                        'image_path' => $url,
                        'is_main' => ! $hasMain && $i === 0,
                        'sort_order' => ++$sort,
                    ]);
                }
            }
        }

        $product->save();
    }
}
