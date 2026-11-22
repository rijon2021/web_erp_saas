<?php

namespace App\Services\LicenseKeys;

use App\Models\LicenseActivation;
use App\Models\LicenseKey;
use App\Models\LicensePolicy;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin-side key stock: upload (manual / CSV / Excel), restock, write off,
 * manual issue of generated keys, and the license actions (extend, suspend,
 * revoke, reset activations).
 *
 * Uploaded keys are stock: adding N keys posts a stock adjustment (+N) in the
 * chosen warehouse, writing one off posts (−1), so product_warehouse stays
 * equal to keys on hand and every existing stock check (POS, store, confirm)
 * keeps working unchanged.
 */
class LicenseKeyInventory
{
    public function __construct(private LicenseFulfilment $fulfilment)
    {
    }

    /**
     * Sort raw key rows into new / duplicate-in-file / already-stored.
     *
     * @param  array<int, array{key: string, cost?: mixed, note?: mixed}>  $rows
     * @return array{new: array, duplicates_in_file: array, existing: array, invalid: array}
     */
    public function preview(array $rows): array
    {
        $seen = [];
        $out = ['new' => [], 'duplicates_in_file' => [], 'existing' => [], 'invalid' => []];
        $candidates = [];

        foreach (array_values($rows) as $i => $row) {
            $line = $i + 1;
            $key = LicenseKey::normalize((string) ($row['key'] ?? ''));
            if ($key === '' || mb_strlen($key) > 500) {
                $out['invalid'][] = ['row' => $line, 'key' => mb_substr($key, 0, 60)];

                continue;
            }
            $hash = LicenseKey::hashKey($key);
            if (isset($seen[$hash])) {
                $out['duplicates_in_file'][] = ['row' => $line, 'key' => $this->mask($key), 'first_row' => $seen[$hash]];

                continue;
            }
            $seen[$hash] = $line;
            $candidates[$hash] = [
                'row' => $line,
                'key' => $key,
                'cost' => isset($row['cost']) && $row['cost'] !== '' && is_numeric($row['cost']) ? round((float) $row['cost'], 2) : null,
                'note' => isset($row['note']) && trim((string) $row['note']) !== '' ? mb_substr(trim((string) $row['note']), 0, 255) : null,
            ];
        }

        $existing = [];
        foreach (array_chunk(array_keys($candidates), 500) as $chunk) {
            foreach (LicenseKey::whereIn('key_hash', $chunk)->with('product:id,name')->get(['id', 'key_hash', 'product_id', 'status']) as $k) {
                $existing[$k->key_hash] = $k;
            }
        }

        foreach ($candidates as $hash => $c) {
            if (isset($existing[$hash])) {
                $out['existing'][] = [
                    'row' => $c['row'],
                    'key' => $this->mask($c['key']),
                    'product' => optional($existing[$hash]->product)->name,
                    'status' => $existing[$hash]->status,
                ];

                continue;
            }
            $out['new'][] = $c;
        }

        return $out;
    }

    /**
     * Store new uploaded keys for a product/edition and put them in stock.
     * Duplicates are skipped (never an error). Returns the counts.
     */
    public function addKeys(Product $product, ?int $variantId, int $warehouseId, array $rows, ?float $defaultCost, ?string $note, ?int $userId): array
    {
        if (LicenseFulfilment::modeOf($product) !== 'pool') {
            throw ValidationException::withMessages(['product_id' => [__('This product does not use uploaded license keys.')]]);
        }
        $this->assertVariant($product, $variantId);

        $preview = $this->preview($rows);
        $new = $preview['new'];
        $batch = (string) Str::uuid();

        try {
            DB::transaction(function () use ($product, $variantId, $warehouseId, $new, $defaultCost, $note, $userId, $batch) {
                if (! $new) {
                    return;
                }
                $now = now();

                // Keys first, in chunks; the unique key_hash index rejects a key
                // another import stored meanwhile (caught below).
                foreach (array_chunk($new, 500) as $chunk) {
                    $rows = [];
                    foreach ($chunk as $c) {
                        $key = (new LicenseKey(['source' => LicenseKey::SOURCE_POOL]))->setPlainKey($c['key']);
                        $rows[] = [
                            'product_id' => $product->id,
                            'product_variant_id' => $variantId,
                            'warehouse_id' => $warehouseId,
                            'source' => LicenseKey::SOURCE_POOL,
                            'key_encrypted' => $key->key_encrypted,
                            'key_hash' => $key->key_hash,
                            'key_last4' => $key->key_last4,
                            'status' => LicenseKey::STATUS_AVAILABLE,
                            'cost' => $c['cost'] ?? $defaultCost,
                            'note' => $c['note'] ?? ($note ? mb_substr($note, 0, 255) : null),
                            'batch' => $batch,
                            'created_by' => $userId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    DB::table('license_keys')->insert($rows);
                }

                // Then stock (+N) — the stock row is locked only for this last step.
                $adjustment = LicenseStock::post($warehouseId, [[
                    'product_id' => (int) $product->id, 'variant_id' => $variantId, 'qty' => count($new), 'type' => 'add',
                ]], 'License keys imported ('.count($new).')', $userId);

                DB::table('license_keys')->where('batch', $batch)->update(['adjustment_id' => $adjustment?->id]);
                DB::table('license_keys')->where('batch', $batch)->orderBy('id')->select('id')
                    ->chunkById(1000, function ($ids) use ($adjustment, $userId, $now) {
                        DB::table('license_key_events')->insert($ids->map(fn ($r) => [
                            'license_key_id' => $r->id, 'event' => 'imported', 'note' => $adjustment?->Ref,
                            'user_id' => $userId, 'meta' => null, 'created_at' => $now,
                        ])->all());
                    });
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['keys' => [__('Another import added some of these keys meanwhile — please preview again.')]]);
        }

        // Sales waiting for this product's keys get them now (and by email).
        $filled = $new ? $this->fulfilment->fillBackorders((int) $product->id, $variantId) : 0;

        return [
            'backorders_filled' => $filled,
            'added' => count($new),
            'duplicates_in_file' => count($preview['duplicates_in_file']),
            'existing' => count($preview['existing']),
            'invalid' => count($preview['invalid']),
            'batch' => $new ? $batch : null,
        ];
    }

    /**
     * Returned key → available again. Releasing it had taken it out of stock,
     * so it comes back in (+1) in its warehouse.
     */
    public function restock(LicenseKey $key, ?int $userId): LicenseKey
    {
        return DB::transaction(function () use ($key, $userId) {
            $key = LicenseKey::whereKey($key->id)->lockForUpdate()->firstOrFail();
            if ($key->isGenerated() || $key->status !== LicenseKey::STATUS_RETURNED) {
                throw ValidationException::withMessages(['status' => [__('Only returned uploaded keys can be restocked.')]]);
            }
            $key->fill([
                'status' => LicenseKey::STATUS_AVAILABLE,
                'sale_id' => null, 'sale_detail_id' => null, 'released_at' => null, 'prev_status' => null,
                'online_order_id' => null, 'client_id' => null, 'sold_at' => null, 'delivered_at' => null,
                'status_reason' => null,
            ])->save();
            if ($key->warehouse_id) {
                LicenseStock::post((int) $key->warehouse_id, LicenseStock::linesFor([$key], 'add'), 'License key restocked', $userId);
            }
            $this->fulfilment->event($key->id, 'restocked', null, $userId);

            return $key;
        });
    }

    /**
     * Write off an uploaded key. An available key leaves stock (−1); a
     * returned one is already out of stock.
     */
    public function void(LicenseKey $key, ?string $reason, ?int $userId): LicenseKey
    {
        return DB::transaction(function () use ($key, $reason, $userId) {
            $key = LicenseKey::whereKey($key->id)->lockForUpdate()->firstOrFail();
            if ($key->isGenerated() || ! in_array($key->status, [LicenseKey::STATUS_AVAILABLE, LicenseKey::STATUS_RETURNED], true)) {
                throw ValidationException::withMessages(['status' => [__('Only available or returned uploaded keys can be written off.')]]);
            }
            if ($key->status === LicenseKey::STATUS_AVAILABLE && $key->warehouse_id) {
                LicenseStock::post((int) $key->warehouse_id, LicenseStock::linesFor([$key], 'sub'), 'License key written off', $userId);
            }
            $key->fill([
                'status' => LicenseKey::STATUS_VOID,
                'status_reason' => $reason ? mb_substr($reason, 0, 255) : null,
                'released_at' => null, 'prev_status' => null,
            ])->save();
            $this->fulfilment->event($key->id, 'voided', $reason, $userId);

            return $key;
        });
    }

    /** Delete an available uploaded key typed by mistake (stock −1, no history kept). */
    public function delete(LicenseKey $key, ?int $userId): void
    {
        DB::transaction(function () use ($key, $userId) {
            $key = LicenseKey::whereKey($key->id)->lockForUpdate()->firstOrFail();
            if ($key->isGenerated() || $key->status !== LicenseKey::STATUS_AVAILABLE) {
                throw ValidationException::withMessages(['status' => [__('Only available uploaded keys can be deleted.')]]);
            }
            if ($key->warehouse_id) {
                LicenseStock::post((int) $key->warehouse_id, LicenseStock::linesFor([$key], 'sub'), 'License key deleted', $userId);
            }
            DB::table('license_key_events')->where('license_key_id', $key->id)->delete();
            $key->delete();
        });
    }

    /**
     * Issue generated keys by hand (reseller batch, free licence, replacement):
     * they go out already "sold" to the chosen customer, outside any sale.
     *
     * @return \Illuminate\Support\Collection<int, LicenseKey>
     */
    public function issue(Product $product, ?int $variantId, int $count, ?int $clientId, ?string $note, ?string $expiresAt, ?int $userId)
    {
        if (LicenseFulfilment::modeOf($product) !== 'generated') {
            throw ValidationException::withMessages(['product_id' => [__('This product does not use generated license keys.')]]);
        }
        $this->assertVariant($product, $variantId);
        $count = max(1, min(500, $count));
        $policy = LicensePolicy::forLine($product->id, $variantId);
        $generator = app(LicenseKeyGenerator::class);
        $batch = (string) Str::uuid();

        return DB::transaction(function () use ($product, $variantId, $count, $clientId, $note, $expiresAt, $userId, $policy, $generator, $batch) {
            $now = now();
            $out = collect();
            for ($i = 0; $i < $count; $i++) {
                $key = new LicenseKey([
                    'product_id' => $product->id,
                    'product_variant_id' => $variantId,
                    'source' => LicenseKey::SOURCE_GENERATED,
                    'status' => LicenseKey::STATUS_SOLD,
                    'client_id' => $clientId,
                    'sold_at' => $now,
                    'starts_at' => $now,
                    'expires_at' => $expiresAt ? \Carbon\Carbon::parse($expiresAt)->endOfDay() : ($policy ? $policy->expiryFrom($now) : null),
                    'max_activations' => $policy?->max_activations,
                    'restriction' => $policy->restriction ?? 'none',
                    'note' => $note ? mb_substr($note, 0, 255) : null,
                    'batch' => $batch,
                    'created_by' => $userId,
                ]);
                $key->setPlainKey($generator->unique($policy?->key_format));
                $key->save();
                $this->fulfilment->event($key->id, 'issued', 'Issued manually', $userId);
                $out->push($key);
            }

            return $out;
        });
    }

    /** Set a new expiry (renewal / manual extend). NULL = lifetime. */
    public function extend(LicenseKey $key, ?string $expiresAt, ?int $userId, string $note = 'Extended'): LicenseKey
    {
        $this->assertGenerated($key);
        $old = $key->expires_at?->toDateString();
        $key->expires_at = $expiresAt ? \Carbon\Carbon::parse($expiresAt)->endOfDay() : null;
        $key->reminded_at = null;
        $key->save();
        $this->fulfilment->event($key->id, 'extended', $note, $userId, ['from' => $old, 'to' => $key->expires_at?->toDateString()]);

        return $key;
    }

    /** suspend | revoke | reactivate a generated key. */
    public function setState(LicenseKey $key, string $action, ?string $reason, ?int $userId): LicenseKey
    {
        $this->assertGenerated($key);
        $map = [
            'suspend' => [[LicenseKey::STATUS_SOLD], LicenseKey::STATUS_SUSPENDED, 'suspended'],
            'revoke' => [[LicenseKey::STATUS_SOLD, LicenseKey::STATUS_SUSPENDED], LicenseKey::STATUS_REVOKED, 'revoked'],
            'reactivate' => [[LicenseKey::STATUS_SUSPENDED, LicenseKey::STATUS_REVOKED], LicenseKey::STATUS_SOLD, 'reactivated'],
        ];
        [$from, $to, $event] = $map[$action] ?? throw ValidationException::withMessages(['action' => ['Unknown action.']]);
        if (! in_array($key->status, $from, true)) {
            throw ValidationException::withMessages(['status' => [__('This license cannot be changed from its current status.')]]);
        }
        // A key revoked by a return stays with the return; reverse the return instead.
        if ($action === 'reactivate' && $key->released_at !== null) {
            throw ValidationException::withMessages(['status' => [__('This license was returned — delete the return to give it back.')]]);
        }
        $key->status = $to;
        $key->status_reason = $action === 'reactivate' ? null : ($reason ? mb_substr($reason, 0, 255) : null);
        $key->save();
        $this->fulfilment->event($key->id, $event, $reason, $userId);

        return $key;
    }

    /** Free every activation slot (or one). */
    public function resetActivations(LicenseKey $key, ?int $activationId, ?int $userId): int
    {
        $this->assertGenerated($key);
        $n = LicenseActivation::where('license_key_id', $key->id)
            ->whereNull('deactivated_at')
            ->when($activationId, fn ($q) => $q->whereKey($activationId))
            ->update(['deactivated_at' => now(), 'updated_at' => now()]);
        $this->fulfilment->event($key->id, $activationId ? 'deactivated' : 'activations_reset', null, $userId, ['count' => $n]);

        return $n;
    }

    // ------------------------------------------------------------------

    private function assertVariant(Product $product, ?int $variantId): void
    {
        if ((int) $product->is_variant === 1 || $product->type === 'is_variant') {
            if (! $variantId || ! DB::table('product_variants')->where('id', $variantId)->where('product_id', $product->id)->whereNull('deleted_at')->exists()) {
                throw ValidationException::withMessages(['product_variant_id' => [__('Choose the edition (variant) these keys are for.')]]);
            }
        } elseif ($variantId) {
            throw ValidationException::withMessages(['product_variant_id' => [__('This product has no editions.')]]);
        }
    }

    private function assertGenerated(LicenseKey $key): void
    {
        if (! $key->isGenerated()) {
            throw ValidationException::withMessages(['status' => [__('Only generated licenses have expiry, activations and revocation.')]]);
        }
    }

    private function mask(string $key): string
    {
        $tail = mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $key) ?: $key, -4);

        return '••••-'.$tail;
    }
}
