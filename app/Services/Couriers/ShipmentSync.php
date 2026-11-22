<?php

namespace App\Services\Couriers;

use App\Models\CourierAccount;
use App\Models\CourierSetting;
use App\Models\Shipment;
use Illuminate\Support\Collection;

/**
 * Polls couriers for shipments still on the way. Webhooks are the fast path;
 * this catches couriers without webhooks and any push that never arrived.
 */
class ShipmentSync
{
    /** Shipments older than this are no longer polled (courier history gone stale). */
    public const MAX_AGE_DAYS = 90;

    /** Couriers whose status check also reports the COD payout (checked after delivery too). */
    public const PAYOUT_COURIERS = ['postex', 'leopards', 'tcs', 'trax', 'mnp', 'shiprocket', 'pathao', 'redx', 'ecourier', 'shipbubble'];

    /** After this many failures in a row a shipment is left for a manual refresh. */
    public const MAX_FAILURES = 20;

    public function __construct(private ShipmentUpdater $updater)
    {
    }

    /** Courier codes whose driver can report status. */
    public static function trackableCouriers(): array
    {
        return array_values(array_filter(
            CourierRegistry::codes(),
            fn ($code) => CourierRegistry::meta($code)['capabilities']['tracking'] ?? false
        ));
    }

    /** Shipments due for a status check under the tenant's interval. */
    public function due(int $limit = 200): Collection
    {
        $couriers = self::trackableCouriers();
        if (! $couriers) {
            return collect();
        }

        $interval = max(15, (int) CourierSetting::current()->sync_interval_minutes);
        $accountIds = CourierAccount::query()->where('active', true)->whereIn('courier', $couriers)->pluck('id');
        $payoutAccountIds = CourierAccount::query()->where('active', true)->whereIn('courier', self::PAYOUT_COURIERS)->pluck('id');
        $payoutInterval = max($interval, 360);

        return Shipment::query()
            ->whereNull('deleted_at')
            ->whereIn('courier_account_id', $accountIds)
            ->whereNotNull('tracking_number')
            ->whereNotNull('courier_status')
            ->where(fn ($q) => $q
                ->where(fn ($open) => $open
                    ->whereNotIn('courier_status', CourierStatus::FINAL)
                    ->where(fn ($t) => $t->whereNull('last_synced_at')->orWhere('last_synced_at', '<=', now()->subMinutes($interval))))
                // Delivered COD the courier has not paid out yet: its payout report comes days later.
                ->orWhere(fn ($cod) => $cod
                    ->where('courier_status', CourierStatus::DELIVERED)
                    ->whereIn('courier_account_id', $payoutAccountIds)
                    ->where('cod_amount', '>', 0)
                    ->whereIn('cod_status', ['pending', 'collected'])
                    ->where(fn ($t) => $t->whereNull('last_synced_at')->orWhere('last_synced_at', '<=', now()->subMinutes($payoutInterval)))))
            ->where('booked_at', '>=', now()->subDays(self::MAX_AGE_DAYS))
            ->where('sync_failures', '<', self::MAX_FAILURES)
            ->orderByRaw('last_synced_at IS NOT NULL, last_synced_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  bool  $quiet  scheduled checks log only failures (Couriers → Logs)
     * @return array{ok: bool, changed: bool, error: ?string}
     */
    public function syncOne(Shipment $shipment, bool $quiet = true): array
    {
        $account = $shipment->courierAccount;
        if (! $account || $account->trashed()) {
            return ['ok' => false, 'changed' => false, 'error' => 'The courier account was deleted.'];
        }
        if (! (CourierRegistry::meta($account->courier)['capabilities']['tracking'] ?? false)) {
            return ['ok' => false, 'changed' => false, 'error' => 'This courier does not report status; update it by hand.'];
        }

        try {
            $result = CourierLogger::during($account, 'track', $shipment->id, fn () => CourierRegistry::make($account)->track($shipment), $quiet);
            $changed = $this->updater->apply($shipment, $result, 'poll');

            return ['ok' => true, 'changed' => $changed, 'error' => null];
        } catch (\Throwable $e) {
            $message = $e instanceof CourierException ? $e->getMessage() : 'Status check failed: ' . $e->getMessage();
            if (! $e instanceof CourierException) {
                report($e);
            }
            $this->updater->recordSyncFailure($shipment, $message);

            return ['ok' => false, 'changed' => false, 'error' => $message];
        }
    }
}
