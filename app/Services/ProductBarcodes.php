<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additional barcodes per product / variant (product_barcodes).
 *
 * One namespace for scanning: a barcode may not equal any live product code,
 * variant code or another extra barcode, so a scan always finds exactly one
 * product. Everything is a no-op until the product_barcodes migration has run.
 */
class ProductBarcodes
{
    /** Table check per tenant database — a queue worker switches tenants. */
    private static array $enabled = [];

    public static function enabled(): bool
    {
        $db = DB::connection()->getDatabaseName();

        return self::$enabled[$db] ??= Schema::hasTable('product_barcodes');
    }

    /** Test helper: forget the memoised table check. */
    public static function flush(): void
    {
        self::$enabled = [];
    }

    /**
     * Barcodes for many products at once, keyed "productId|variantId" (variant
     * part empty for the product itself) → list of barcodes.
     */
    public static function map(array $productIds): array
    {
        if (! self::enabled() || ! $productIds) {
            return [];
        }

        $map = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $productIds))), 1000) as $chunk) {
            $rows = DB::table('product_barcodes')->whereIn('product_id', $chunk)->orderBy('id')
                ->get(['product_id', 'product_variant_id', 'barcode']);
            foreach ($rows as $r) {
                $map[$r->product_id.'|'.($r->product_variant_id ?? '')][] = $r->barcode;
            }
        }

        return $map;
    }

    /** The list for one product (variant null) or one variant. */
    public static function for(int $productId, ?int $variantId = null): array
    {
        return self::map([$productId])[$productId.'|'.($variantId ?? '')] ?? [];
    }

    /**
     * Rows that still count: their product is live and, for a variant
     * barcode, so is the variant. Deleting a variant or product soft-deletes
     * it, which must not keep its barcodes reserved.
     */
    private static function live()
    {
        return DB::table('product_barcodes as pb')
            ->join('products as p', 'p.id', '=', 'pb.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'pb.product_variant_id')
            ->whereNull('p.deleted_at')
            ->where(fn ($q) => $q->whereNull('pb.product_variant_id')->orWhere(fn ($v) => $v->whereNotNull('pv.id')->whereNull('pv.deleted_at')));
    }

    /**
     * Delete barcodes of deleted products / variants, so the unique index
     * frees them for reuse. Called before barcodes are checked or written.
     */
    public static function purgeDead(): void
    {
        if (! self::enabled()) {
            return;
        }
        // Ids first: MySQL refuses a DELETE that selects from its own table.
        $dead = DB::table('product_barcodes as pb')
            ->leftJoin('products as p', 'p.id', '=', 'pb.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'pb.product_variant_id')
            ->where(fn ($q) => $q->whereNull('p.id')->orWhereNotNull('p.deleted_at')
                ->orWhere(fn ($v) => $v->whereNotNull('pb.product_variant_id')->where(fn ($d) => $d->whereNull('pv.id')->orWhereNotNull('pv.deleted_at'))))
            ->pluck('pb.id')->all();
        foreach (array_chunk($dead, 1000) as $chunk) {
            DB::table('product_barcodes')->whereIn('id', $chunk)->delete();
        }
    }

    /** [product_id, product_variant_id] owning this barcode, or null. */
    public static function owner(string $barcode): ?array
    {
        if (! self::enabled() || $barcode === '') {
            return null;
        }
        $row = self::live()->where('pb.barcode', $barcode)->first(['pb.product_id', 'pb.product_variant_id']);

        return $row ? [(int) $row->product_id, $row->product_variant_id !== null ? (int) $row->product_variant_id : null] : null;
    }

    /** Every additional barcode (imports check new codes against them). */
    public static function all(): array
    {
        return self::enabled() ? self::live()->pluck('pb.barcode')->all() : [];
    }

    /** Product ids having a barcode that contains $term (for searches). */
    public static function productIdsLike(string $term): array
    {
        if (! self::enabled() || trim($term) === '') {
            return [];
        }

        return DB::table('product_barcodes')->where('barcode', 'LIKE', '%'.$term.'%')->limit(500)->pluck('product_id')->all();
    }

    /**
     * Clean a submitted list: trimmed, non-empty, unique, in order. Accepts an
     * array or a JSON / comma / newline separated string.
     */
    public static function normalize($raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : preg_split('/[\r\n,;]+/', $raw);
        }
        // Case-insensitive dedupe: the unique index compares with the
        // table's (case-insensitive) collation, so "abc" and "ABC" collide.
        $list = [];
        foreach ((array) $raw as $b) {
            $b = trim((string) $b);
            if ($b !== '' && ! isset($list[mb_strtolower($b)])) {
                $list[mb_strtolower($b)] = $b;
            }
        }

        return array_values($list);
    }

    /**
     * Why each barcode cannot be used for this product / variant, as
     * "barcode → reason" messages. Empty when all are free.
     */
    public static function conflicts(array $barcodes, ?int $productId, ?int $variantId = null, ?string $ownCode = null): array
    {
        self::purgeDead(); // barcodes of deleted variants / products are free

        $errors = [];
        foreach ($barcodes as $b) {
            if (mb_strlen($b) > 64) {
                $errors[] = "Barcode \"{$b}\" is longer than 64 characters.";

                continue;
            }
            if ($ownCode !== null && mb_strtolower($b) === mb_strtolower($ownCode)) {
                $errors[] = "Barcode \"{$b}\" is the product's own code.";

                continue;
            }
            $product = DB::table('products')->whereNull('deleted_at')->where('code', $b)->first(['id', 'name']);
            if ($product) {
                $errors[] = "Barcode \"{$b}\" is already the code of \"{$product->name}\".";

                continue;
            }
            $variant = DB::table('product_variants')->whereNull('deleted_at')->where('code', $b)->first(['id', 'product_id']);
            if ($variant) {
                $own = $productId && (int) $variant->product_id === $productId && (int) $variant->id === (int) $variantId;
                $errors[] = $own ? "Barcode \"{$b}\" is the variant's own code." : "Barcode \"{$b}\" is already a variant code.";

                continue;
            }
            $owner = self::owner($b);
            if ($owner && ! ($productId && $owner[0] === $productId && $owner[1] === $variantId)) {
                $name = DB::table('products')->where('id', $owner[0])->value('name');
                $errors[] = "Barcode \"{$b}\" already belongs to \"{$name}\".";
            }
        }

        return $errors;
    }

    /** Replace the barcodes of one product / variant with $barcodes. */
    public static function sync(int $productId, ?int $variantId, array $barcodes): void
    {
        if (! self::enabled()) {
            return;
        }

        DB::table('product_barcodes')->where('product_id', $productId)->where('product_variant_id', $variantId)
            ->whereNotIn('barcode', $barcodes ?: [''])->delete();
        $existing = DB::table('product_barcodes')->where('product_id', $productId)->where('product_variant_id', $variantId)->pluck('barcode')->all();
        $now = now();
        // Case-insensitive, like the unique index: "ABC" is already "abc".
        $have = array_map('mb_strtolower', $existing);
        $new = array_values(array_filter($barcodes, fn ($b) => ! in_array(mb_strtolower($b), $have, true)));
        if ($new) {
            DB::table('product_barcodes')->insert(array_map(fn ($b) => [
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'barcode' => $b,
                'created_at' => $now,
                'updated_at' => $now,
            ], $new));
        }
    }

    /** Append barcodes (import), skipping ones the product already has. */
    public static function add(int $productId, ?int $variantId, array $barcodes): int
    {
        $existing = self::for($productId, $variantId);

        $merged = array_values(array_unique(array_merge($existing, $barcodes)));
        self::sync($productId, $variantId, $merged);

        return count($merged) - count($existing);
    }

    /** Drop all barcodes of a deleted product. */
    public static function forget(int $productId): void
    {
        if (self::enabled()) {
            DB::table('product_barcodes')->where('product_id', $productId)->delete();
        }
    }
}
