<?php

namespace App\Services\LicenseKeys;

use App\Models\LicenseKey;
use App\Models\LicenseKeyEvent;
use App\Models\LicensePolicy;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Ties license keys to sales.
 *
 * syncSale() is the single, idempotent entry point. For every Software License
 * line of a sale it computes how many keys the sale should hold:
 *
 *   held = units on the sale's lines (completed, not deleted sales only)
 *          − units sent back by received sale returns and approved online returns
 *
 * Returned units are read from the return documents themselves, never from key
 * rows, so restocking or writing off a returned key can't change the count.
 * The difference is then fixed:
 *   too many → release (uploaded → `returned` for the admin to restock / write
 *              off; generated → `revoked`), remembering the previous status;
 *   too few  → first put back this sale's own released keys (status restored),
 *              then claim available uploaded keys / issue generated ones.
 *
 * "Held" = sale_id set AND sale_detail_id set. Released = sale_id set,
 * sale_detail_id NULL, released_at set. An admin-revoked key stays held.
 *
 * Uploaded-key stock invariant (see LicenseStock): qte == available keys.
 * Sales / returns / deletes move qte as for any product; releasing a key
 * takes it back out of stock (−1), putting it back adds it (+1), so a returned
 * key is never sellable before the admin restocks it.
 *
 * One key ↔ one sale line: a key is claimed under a row lock with
 *   UPDATE … WHERE id = ? AND status = 'available'
 * inside the sale's own transaction.
 */
class LicenseFulfilment
{
    private static array $ready = [];

    /** @var array<int, Unit|null> */
    private array $units = [];

    /** Tables present for the current tenant (migration run)? */
    public static function ready(): bool
    {
        $key = function_exists('tenant') && tenant() ? (string) tenant()->getTenantKey() : 'central';

        return self::$ready[$key] ??= Schema::hasTable('license_keys') && Schema::hasColumn('products', 'license_mode');
    }

    /**
     * The hook every sale-writing path calls inside its transaction: sync the
     * sale's keys, then email the newly assigned ones after the commit.
     */
    public static function afterSaleWrite($sale, ?int $onlineOrderId = null, bool $lenient = false): void
    {
        if (! self::ready()) {
            return;
        }
        $newIds = app(self::class)->syncSale($sale, $onlineOrderId, \Illuminate\Support\Facades\Auth::id(), null, $lenient);
        app(LicenseDelivery::class)->afterCommit($newIds);
    }

    /**
     * Sales that can't be refused for lack of keys (shop imports, pre-orders,
     * subscription invoices): claim what is there, record the rest as a
     * backorder. Never throws for a shortage, and never breaks the caller.
     */
    public static function afterSaleWriteLenient($sale, ?int $onlineOrderId = null): void
    {
        try {
            self::afterSaleWrite($sale, $onlineOrderId, true);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('License keys: sync failed for sale '.(is_object($sale) ? $sale->id : $sale).': '.$e->getMessage());
        }
    }

    /**
     * Keys arrived for a product: give them to its backordered sales, oldest
     * first, each in its own transaction (and email them).
     *
     * @return int keys assigned
     */
    public function fillBackorders(int $productId, ?int $variantId = null): int
    {
        if (! self::ready() || ! Schema::hasTable('license_backorders')) {
            return 0;
        }
        $saleIds = DB::table('license_backorders')
            ->where('product_id', $productId)
            ->when($variantId, fn ($q) => $q->where('product_variant_id', $variantId), fn ($q) => $q->whereNull('product_variant_id'))
            ->orderBy('sale_id')->pluck('sale_id')->unique();

        $assigned = 0;
        foreach ($saleIds as $saleId) {
            if (! LicenseKey::where('product_id', $productId)->where('status', LicenseKey::STATUS_AVAILABLE)->exists()) {
                break;
            }
            try {
                $newIds = DB::transaction(fn () => $this->syncSale((int) $saleId, null, \Illuminate\Support\Facades\Auth::id(), null, true));
                $assigned += count($newIds);
                app(LicenseDelivery::class)->deliver($newIds);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("License keys: backorder fill failed for sale {$saleId}: ".$e->getMessage());
            }
        }

        return $assigned;
    }

    /**
     * A sale return / online return was written or removed: re-sync its sale.
     * $excludeReturnId = a sale return that is being deleted in this request
     * (its row may still look live).
     */
    public static function afterReturnWrite(?int $saleId, ?int $excludeReturnId = null): void
    {
        if (! self::ready() || ! $saleId) {
            return;
        }
        $newIds = app(self::class)->syncSale($saleId, null, \Illuminate\Support\Facades\Auth::id(), $excludeReturnId);
        app(LicenseDelivery::class)->afterCommit($newIds);
    }

    /**
     * Bring a sale's keys in line with its lines and returns. Returns the ids
     * of keys that were newly assigned (to deliver after commit).
     *
     * @throws ValidationException when an uploaded-key product is out of keys
     */
    public function syncSale($sale, ?int $onlineOrderId = null, ?int $userId = null, ?int $excludeReturnId = null, bool $lenient = false): array
    {
        if (! self::ready()) {
            return [];
        }
        $sale = $sale instanceof Sale ? $sale : Sale::find($sale);
        if (! $sale) {
            return [];
        }

        $mineAll = LicenseKey::where('sale_id', $sale->id)->orderBy('id')->lockForUpdate()->get();
        $held = $mineAll->whereNotNull('sale_detail_id')->values();
        $released = $mineAll->whereNull('sale_detail_id')->whereNotNull('released_at')->values();

        $heldGroups = $held->groupBy(fn ($k) => $this->groupKey($k->product_id, $k->product_variant_id));
        $lines = $this->licenseLines($sale->id, $heldGroups->keys()->all());
        if ($lines->isEmpty() && $held->isEmpty()) {
            $this->setBackorder($sale->id, null, 0);

            return [];
        }

        $active = $sale->statut === 'completed' && empty($sale->deleted_at);
        $lineGroups = $lines->groupBy(fn ($l) => $this->groupKey($l->product_id, $l->product_variant_id));
        $returned = $this->returnedUnits($sale, $lineGroups, $excludeReturnId);
        $onlineOrderId = $onlineOrderId ?: OnlineOrder::where('sale_id', $sale->id)->value('id');
        $clientId = $this->clientFor($sale);
        $warehouseId = (int) $sale->warehouse_id;

        $newIds = [];
        $this->setBackorder($sale->id, null, 0); // rebuilt below for what is still owed
        foreach ($lineGroups->keys()->merge($heldGroups->keys())->unique() as $gk) {
            $glines = $lineGroups->get($gk, collect());
            $mine = $heldGroups->get($gk, collect())->values();
            $units = $active ? (int) $glines->sum('units') : 0;
            $need = max(0, $units - (int) ($returned[$gk] ?? 0));
            $mode = $glines->first()->license_mode ?? 'none';

            // Product no longer a license product: keep what was delivered, issue nothing new.
            if (! in_array($mode, ['pool', 'generated'], true)) {
                $need = min($need, $mine->count());
            }

            if ($mine->count() > $need) {
                $out = $this->pickToRelease($mine, $mine->count() - $need);
                $reason = ! $active ? (empty($sale->deleted_at) ? 'Sale no longer completed' : 'Sale deleted')
                    : (($returned[$gk] ?? 0) > 0 ? 'Returned' : 'Sale edited');
                $this->release($out, $reason, $warehouseId, $userId);
                $mine = $mine->reject(fn ($k) => $out->contains('id', $k->id))->values();
            } elseif ($mine->count() < $need) {
                [$pid, $vid] = array_map('intval', explode('|', $gk));
                $vid = $vid ?: null;

                // 1) this sale's own released keys go back first (return deleted, qty raised again)
                $back = $released->filter(fn ($k) => $this->groupKey($k->product_id, $k->product_variant_id) === $gk
                        && in_array($k->status, [LicenseKey::STATUS_RETURNED, LicenseKey::STATUS_REVOKED], true))
                    ->sortByDesc('id')->take($need - $mine->count())->values();
                if ($back->isNotEmpty()) {
                    $this->putBack($back, $warehouseId, $userId);
                    $released = $released->reject(fn ($k) => $back->contains('id', $k->id))->values();
                    $mine = $mine->concat(LicenseKey::whereIn('id', $back->pluck('id'))->get())->values();
                }

                // 2) then new keys
                if ($mine->count() < $need) {
                    $first = $glines->first();
                    $new = $this->assign($sale, $pid, $vid, $need - $mine->count(), $mode, (string) $first->name, $onlineOrderId, $clientId, $userId, $lenient);
                    $newIds = array_merge($newIds, $new->pluck('id')->all());
                    $mine = $mine->concat($new)->values();
                }
            }

            // Whatever is still owed stays on record until keys arrive.
            [$bp, $bv] = array_map('intval', explode('|', $gk));
            $this->setBackorder($sale->id, [$bp, $bv ?: null], max(0, $need - $mine->count()));

            // Point each held key at a current line of its group.
            $i = 0;
            foreach ($glines as $line) {
                for ($c = 0; $c < (int) $line->units && $i < $mine->count(); $c++, $i++) {
                    $key = $mine[$i];
                    $patch = [];
                    if ((int) $key->sale_detail_id !== (int) $line->id) {
                        $patch['sale_detail_id'] = $line->id;
                    }
                    if ((int) $key->client_id !== (int) $clientId) {
                        $patch['client_id'] = $clientId;
                    }
                    if ($patch) {
                        LicenseKey::whereKey($key->id)->update($patch + ['updated_at' => now()]);
                    }
                }
            }
        }

        return $newIds;
    }

    /** A sale is being deleted: sync it as if it held nothing. */
    public function releaseSale(int $saleId, ?int $userId = null): void
    {
        if (! self::ready()) {
            return;
        }
        $held = LicenseKey::where('sale_id', $saleId)->whereNotNull('sale_detail_id')->lockForUpdate()->get();
        $warehouseId = (int) Sale::whereKey($saleId)->value('warehouse_id');
        $this->release($held, 'Sale deleted', $warehouseId, $userId);
    }

    // ------------------------------------------------------------------

    /**
     * Units sent back per (product, edition): received, non-deleted sale
     * returns of the sale + approved / refunded online returns of its order.
     *
     * @return array<string, int>
     */
    private function returnedUnits(Sale $sale, Collection $lineGroups, ?int $excludeReturnId): array
    {
        $out = [];
        $hasPack = Schema::hasColumn('sale_return_details', 'pack_multiplier');
        $rows = DB::table('sale_return_details as d')
            ->join('sale_returns as r', 'r.id', '=', 'd.sale_return_id')
            ->where('r.sale_id', $sale->id)
            ->whereNull('r.deleted_at')
            ->where('r.statut', 'received')
            ->when($excludeReturnId, fn ($q) => $q->where('r.id', '!=', $excludeReturnId))
            ->get(array_merge(['d.product_id', 'd.product_variant_id', 'd.quantity', 'd.sale_unit_id'], $hasPack ? ['d.pack_multiplier'] : []));
        foreach ($rows as $r) {
            $gk = $this->groupKey((int) $r->product_id, $r->product_variant_id);
            $out[$gk] = ($out[$gk] ?? 0) + $this->unitsFor((float) $r->quantity, (float) ($r->pack_multiplier ?? 1) ?: 1.0, $r->sale_unit_id ? (int) $r->sale_unit_id : null);
        }

        if (Schema::hasTable('online_order_returns')) {
            $orderIds = OnlineOrder::where('sale_id', $sale->id)->pluck('id');
            if ($orderIds->isNotEmpty()) {
                $items = DB::table('online_order_return_items as i')
                    ->join('online_order_returns as r', 'r.id', '=', 'i.return_id')
                    ->whereIn('r.order_id', $orderIds)
                    ->where('r.type', 'return')
                    ->whereIn('r.status', ['approved', 'refunded'])
                    ->get(['i.product_id', 'i.product_variant_id', 'i.qty']);
                foreach ($items as $it) {
                    $gk = $this->groupKey((int) $it->product_id, $it->product_variant_id);
                    // Online quantities are in the sale line's unit.
                    $unitId = optional($lineGroups->get($gk, collect())->first())->sale_unit_id;
                    $out[$gk] = ($out[$gk] ?? 0) + $this->unitsFor((float) $it->qty, 1.0, $unitId ? (int) $unitId : null);
                }
            }
        }

        return $out;
    }

    /**
     * Claim uploaded keys or issue generated ones.
     *
     * @return Collection<int, LicenseKey>
     */
    private function assign(Sale $sale, int $productId, ?int $variantId, int $n, string $mode, string $name,
        ?int $onlineOrderId, ?int $clientId, ?int $userId, bool $lenient = false): Collection
    {
        $now = now();
        $base = [
            'sale_id' => $sale->id,
            'client_id' => $clientId,
            'online_order_id' => $onlineOrderId,
            'sold_at' => $now,
            'released_at' => null,
            'prev_status' => null,
            'status_reason' => null,
            'updated_at' => $now,
        ];

        if ($mode === 'generated') {
            $policy = LicensePolicy::forLine($productId, $variantId);
            $generator = app(LicenseKeyGenerator::class);
            $out = collect();
            for ($i = 0; $i < $n; $i++) {
                $key = new LicenseKey([
                    'product_id' => $productId,
                    'product_variant_id' => $variantId,
                    'warehouse_id' => $sale->warehouse_id,
                    'source' => LicenseKey::SOURCE_GENERATED,
                    'status' => LicenseKey::STATUS_SOLD,
                    'starts_at' => $now,
                    'expires_at' => $policy ? $policy->expiryFrom($now) : null,
                    'max_activations' => $policy?->max_activations,
                    'restriction' => $policy->restriction ?? 'none',
                    'created_by' => $userId,
                    'sale_detail_id' => 0, // re-pointed by syncSale
                ] + $base);
                $key->setPlainKey($generator->unique($policy?->key_format));
                $key->save();
                $this->event($key->id, 'issued', 'Sale '.$sale->Ref, $userId);
                $out->push($key);
            }

            return $out;
        }

        $warehouseId = (int) $sale->warehouse_id;
        $scope = fn ($q) => $q->where('product_id', $productId)
            ->when($variantId, fn ($w) => $w->where('product_variant_id', $variantId), fn ($w) => $w->whereNull('product_variant_id'))
            ->where('status', LicenseKey::STATUS_AVAILABLE);

        // Keys of the sale's warehouse first; others only if it runs short (moved over below).
        $candidates = LicenseKey::query()->tap($scope)->where('warehouse_id', $warehouseId)
            ->orderBy('id')->limit($n)->lockForUpdate()->get();
        if ($candidates->count() < $n) {
            $candidates = $candidates->concat(
                LicenseKey::query()->tap($scope)->where(fn ($w) => $w->where('warehouse_id', '!=', $warehouseId)->orWhereNull('warehouse_id'))
                    ->orderBy('id')->limit($n - $candidates->count())->lockForUpdate()->get()
            );
        }

        if ($candidates->count() < $n && ! $lenient) {
            throw ValidationException::withMessages([
                'license_keys' => [__('Not enough license keys for :name: need :need, :have available.', [
                    'name' => $name, 'need' => $n, 'have' => $candidates->count(),
                ])],
            ]);
        }

        foreach ($candidates as $key) {
            $claimed = LicenseKey::whereKey($key->id)
                ->where('status', LicenseKey::STATUS_AVAILABLE)
                ->update(['status' => LicenseKey::STATUS_SOLD, 'sale_detail_id' => 0, 'warehouse_id' => $warehouseId ?: $key->warehouse_id] + $base);
            if ($claimed !== 1) {
                throw ValidationException::withMessages([
                    'license_keys' => [__('A license key for :name was taken by another sale — please retry.', ['name' => $name])],
                ]);
            }
            $this->event($key->id, 'sold', 'Sale '.$sale->Ref, $userId);
        }

        // Keys taken from another warehouse: move their stock unit to the sale's
        // warehouse (the sale already took one there).
        if ($warehouseId) {
            foreach ($candidates->filter(fn ($k) => (int) $k->warehouse_id !== $warehouseId)->groupBy('warehouse_id') as $fromWh => $moved) {
                if ($fromWh) {
                    LicenseStock::post((int) $fromWh, LicenseStock::linesFor($moved, 'sub'), 'License keys moved to sale '.$sale->Ref, $userId);
                }
                LicenseStock::post($warehouseId, LicenseStock::linesFor($moved, 'add'), 'License keys moved for sale '.$sale->Ref, $userId);
            }
        }

        return LicenseKey::whereIn('id', $candidates->pluck('id'))->orderBy('id')->get();
    }

    /**
     * Take keys back from their sale line. Uploaded → `returned` (out of stock
     * until restocked: −1 in the sale's warehouse, which the return / delete /
     * edit just credited), generated → `revoked`. The previous status is kept.
     */
    private function release(Collection $keys, string $reason, int $warehouseId, ?int $userId): void
    {
        if ($keys->isEmpty()) {
            return;
        }
        $pool = collect();
        foreach ($keys as $key) {
            $generated = $key->isGenerated();
            LicenseKey::whereKey($key->id)->update([
                'sale_detail_id' => null,
                'released_at' => now(),
                'prev_status' => $key->status,
                'status' => $generated ? LicenseKey::STATUS_REVOKED : LicenseKey::STATUS_RETURNED,
                'status_reason' => $reason,
                'warehouse_id' => $generated ? $key->warehouse_id : ($warehouseId ?: $key->warehouse_id),
                'updated_at' => now(),
            ]);
            $this->event($key->id, $generated ? 'revoked' : 'returned', $reason, $userId);
            if (! $generated) {
                $pool->push($key);
            }
        }
        if ($pool->isNotEmpty() && $warehouseId) {
            LicenseStock::post($warehouseId, LicenseStock::linesFor($pool, 'sub'), 'License keys returned — awaiting review', $userId);
        }
    }

    /** Released keys go back on their sale with the status they had (uploaded: +1 stock, then sold again). */
    private function putBack(Collection $keys, int $warehouseId, ?int $userId): void
    {
        $pool = collect();
        foreach ($keys as $key) {
            LicenseKey::whereKey($key->id)->update([
                'status' => $key->prev_status ?: LicenseKey::STATUS_SOLD,
                'prev_status' => null,
                'released_at' => null,
                'status_reason' => null,
                'sale_detail_id' => 0, // re-pointed by syncSale
                'updated_at' => now(),
            ]);
            $this->event($key->id, 'return_reversed', null, $userId);
            if (! $key->isGenerated()) {
                $pool->push($key);
            }
        }
        // The released key had been taken out of stock; the sale takes it again,
        // and the undone return / raised quantity already took its unit.
        $wh = $warehouseId ?: (int) $keys->first()->warehouse_id;
        if ($pool->isNotEmpty() && $wh) {
            LicenseStock::post($wh, LicenseStock::linesFor($pool, 'add'), 'License keys back on their sale', $userId);
        }
    }

    /** Which held keys go back first: admin-blocked ones, then fewest activations, then newest. */
    private function pickToRelease(Collection $keys, int $n): Collection
    {
        if ($n <= 0) {
            return collect();
        }
        $active = DB::table('license_activations')
            ->whereIn('license_key_id', $keys->pluck('id')->all())
            ->whereNull('deactivated_at')
            ->selectRaw('license_key_id, COUNT(*) as n')
            ->groupBy('license_key_id')
            ->pluck('n', 'license_key_id');

        // Admin-blocked keys (revoked / suspended) go back first, so a working key stays with the customer.
        $blocked = fn ($k) => in_array($k->status, [LicenseKey::STATUS_REVOKED, LicenseKey::STATUS_SUSPENDED], true) ? 0 : 1;

        return $keys->sortBy([
            fn ($a, $b) => $blocked($a) <=> $blocked($b),
            fn ($a, $b) => ((int) ($active[$a->id] ?? 0)) <=> ((int) ($active[$b->id] ?? 0)),
            fn ($a, $b) => $b->id <=> $a->id,
        ])->take($n)->values();
    }

    /**
     * Sale lines of license products (plus lines of products that already
     * hold keys on this sale), with their unit count.
     */
    private function licenseLines(int $saleId, array $heldGroupKeys): Collection
    {
        $hasPack = Schema::hasColumn('sale_details', 'pack_multiplier');
        $rows = DB::table('sale_details as d')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->where('d.sale_id', $saleId)
            ->orderBy('d.id')
            ->get(array_merge(
                ['d.id', 'd.product_id', 'd.product_variant_id', 'd.quantity', 'd.sale_unit_id', 'p.license_mode', 'p.name'],
                $hasPack ? ['d.pack_multiplier'] : []
            ));

        // Lines that renewed an existing license (subscription invoices) need no key.
        $renewalLines = Schema::hasTable('license_renewals')
            ? DB::table('license_renewals')->where('sale_id', $saleId)->pluck('sale_detail_id')->filter()->map(fn ($v) => (int) $v)->all()
            : [];

        return $rows->filter(function ($r) use ($heldGroupKeys) {
            return in_array($r->license_mode, ['pool', 'generated'], true)
                || in_array($this->groupKey((int) $r->product_id, $r->product_variant_id ? (int) $r->product_variant_id : null), $heldGroupKeys, true);
        })->map(function ($r) use ($renewalLines) {
            $r->units = in_array((int) $r->id, $renewalLines, true)
                ? 0
                : $this->unitsFor((float) $r->quantity, (float) ($r->pack_multiplier ?? 1) ?: 1.0, $r->sale_unit_id ? (int) $r->sale_unit_id : null);

            return $r;
        })->values();
    }

    /**
     * Record what a sale is still owed for one product/edition ($line =
     * [productId, variantId]); missing 0 removes it. $line NULL clears the
     * whole sale.
     */
    private function setBackorder(int $saleId, ?array $line, int $missing): void
    {
        static $has = [];
        $tk = function_exists('tenant') && tenant() ? (string) tenant()->getTenantKey() : 'central';
        if (! ($has[$tk] ??= Schema::hasTable('license_backorders'))) {
            return;
        }
        $q = DB::table('license_backorders')->where('sale_id', $saleId);
        if ($line === null) {
            $q->delete();

            return;
        }
        [$pid, $vid] = $line;
        $q->where('product_id', $pid)->when($vid, fn ($w) => $w->where('product_variant_id', $vid), fn ($w) => $w->whereNull('product_variant_id'));
        if ($missing <= 0) {
            $q->delete();

            return;
        }
        if ((clone $q)->exists()) {
            $q->update(['missing' => $missing, 'updated_at' => now()]);
        } else {
            DB::table('license_backorders')->insert([
                'sale_id' => $saleId, 'product_id' => $pid, 'product_variant_id' => $vid,
                'missing' => $missing, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** The POS default (walk-in) customer is "no customer" for keys. */
    private function clientFor(Sale $sale): ?int
    {
        if (! $sale->client_id) {
            return null;
        }
        static $walkIn = [];
        $tk = function_exists('tenant') && tenant() ? (string) tenant()->getTenantKey() : 'central';
        $walkIn[$tk] ??= (int) DB::table('settings')->whereNull('deleted_at')->value('client_id');

        return (int) $sale->client_id === $walkIn[$tk] ? null : (int) $sale->client_id;
    }

    /** Number of keys a line quantity stands for (base units, whole keys). */
    private function unitsFor(float $qty, float $pack, ?int $unitId): int
    {
        $base = $qty * ($pack > 0 ? $pack : 1);
        if ($unitId) {
            $unit = $this->units[$unitId] ??= Unit::find($unitId);
            if ($unit && (float) $unit->operator_value > 0) {
                $base = $unit->operator === '/' ? $base / (float) $unit->operator_value : $base * (float) $unit->operator_value;
            }
        }

        return max(0, (int) round($base));
    }

    private function groupKey(int $productId, $variantId): string
    {
        return $productId.'|'.($variantId ? (int) $variantId : 0);
    }

    public function event(int $keyId, string $event, ?string $note = null, ?int $userId = null, ?array $meta = null): void
    {
        LicenseKeyEvent::create([
            'license_key_id' => $keyId,
            'event' => $event,
            'note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'user_id' => $userId,
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }

    /** Is this product a Software License product? */
    public static function modeOf(?Product $product): string
    {
        return $product && in_array($product->license_mode ?? 'none', ['pool', 'generated'], true) ? $product->license_mode : 'none';
    }
}
