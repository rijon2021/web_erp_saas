<?php

namespace App\Services\LicenseKeys;

use App\Mail\LicenseKeysMail;
use App\Models\Client;
use App\Models\LicenseKey;
use App\Models\LicenseSetting;
use App\Models\OnlineOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends license keys to the customer by email (one message per sale / manual
 * issue) and stamps delivered_at. Keys are also always visible in the
 * storefront account (My Licenses) and the client portal, so a failed email
 * never loses a key — the admin can resend from License Keys.
 */
class LicenseDelivery
{
    /** Deliver once the surrounding transaction commits (no-op without keys). */
    public function afterCommit(array $keyIds): void
    {
        $keyIds = array_values(array_unique(array_filter(array_map('intval', $keyIds))));
        if (! $keyIds) {
            return;
        }
        $send = fn () => $this->deliver($keyIds);
        try {
            DB::connection()->afterCommit($send);
        } catch (\RuntimeException $e) {
            $send();
        }
    }

    /**
     * Email the given keys, grouped by sale. $to overrides the customer address
     * (admin resend); $force ignores the "email keys" switch.
     *
     * @return int number of emails sent
     */
    public function deliver(array $keyIds, ?string $to = null, bool $force = false): int
    {
        try {
            $settings = LicenseSetting::current();
            if (! $force && $settings && ! $settings->email_enabled) {
                return 0;
            }
            // Shop-sync jobs / billing run in the console: links must use the tenant's host.
            \App\Support\BookingModule::useTenantUrl();

            $keys = LicenseKey::with(['product:id,name', 'variant:id,name'])
                ->whereIn('id', $keyIds)
                ->whereIn('status', [LicenseKey::STATUS_SOLD])
                ->orderBy('id')
                ->get();

            // The tenant's own mail server; none set up = nothing sent (keys stay
            // visible in the account / portal, and the admin can resend later).
            if ($keys->isEmpty() || ! TenantMailer::configure()) {
                return 0;
            }

            // The POS default (walk-in) customer often carries a placeholder
            // address: never email keys there unless the admin typed one.
            $walkInId = (int) \App\Models\Setting::whereNull('deleted_at')->value('client_id');

            $sent = 0;
            foreach ($keys->groupBy(fn ($k) => $k->sale_id ? 's'.$k->sale_id : 'c'.(int) $k->client_id) as $group) {
                $first = $group->first();
                $order = $first->online_order_id ? OnlineOrder::find($first->online_order_id) : null;
                $client = $first->client_id ? Client::find($first->client_id) : null;
                if (! $to && ! $order && $client && $walkInId && (int) $client->id === $walkInId) {
                    continue;
                }
                $address = $to ?: ($order?->customer_email ?: $client?->email);
                if (! $address || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $name = $order?->customer_name ?: ($client?->name ?: '');
                $ref = $order?->ref ?: optional($first->sale)->Ref;

                Mail::to($address)->send(new LicenseKeysMail($group, $name, $ref));
                LicenseKey::whereIn('id', $group->pluck('id'))->update(['delivered_at' => now()]);
                foreach ($group as $k) {
                    app(LicenseFulfilment::class)->event($k->id, 'emailed', $address);
                }
                $sent++;
            }

            return $sent;
        } catch (\Throwable $e) {
            Log::warning('License key email failed: '.$e->getMessage(), ['keys' => $keyIds]);

            return 0;
        }
    }
}
