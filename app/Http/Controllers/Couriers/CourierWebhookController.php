<?php

namespace App\Http\Controllers\Couriers;

use App\Http\Controllers\Controller;
use App\Models\CourierAccount;
use App\Models\Shipment;
use App\Services\Couriers\CourierException;
use App\Services\Couriers\CourierLogger;
use App\Services\Couriers\CourierRegistry;
use App\Services\Couriers\Data\WebhookUpdate;
use App\Services\Couriers\ShipmentUpdater;
use App\Services\TenantLimitsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Public status push from a courier: POST /api/couriers/webhook/{token}.
 * The token (48 random chars, one per account) identifies the account on
 * the tenant host; the driver then verifies the courier's own signature.
 * Unknown tracking numbers are acknowledged with 200 so couriers stop retrying.
 * Every delivery — accepted, ignored or rejected — is written to Couriers → Logs.
 */
class CourierWebhookController extends Controller
{
    public function handle(Request $request, string $token, ShipmentUpdater $updater)
    {
        if (strlen($token) < 32 || ! Schema::hasTable('courier_accounts')) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $account = CourierAccount::query()->where('webhook_token', $token)->first();
        if (! $account || ! CourierRegistry::has($account->courier)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! app(TenantLimitsService::class)->hasFeature('couriers')) {
            CourierLogger::incoming($account, $request, 403, 'Rejected: Courier Integration is not in the current plan.', null, 'error');

            return response()->json(['message' => 'Courier integration is not available on this plan.'], 403);
        }

        try {
            return $this->process($request, $account, $updater);
        } catch (\Throwable $e) {
            // Anything unexpected still shows up on Couriers → Logs; 500 makes the courier retry.
            report($e);
            CourierLogger::incoming($account, $request, 500, 'Failed: ' . CourierLogger::mask($e->getMessage(), $account), null, 'error');

            return response()->json(['message' => 'Could not process the update.'], 500);
        }
    }

    private function process(Request $request, CourierAccount $account, ShipmentUpdater $updater)
    {
        try {
            $driver = CourierRegistry::make($account);
            $update = $driver->parseWebhook($request);
        } catch (CourierException $e) {
            Log::warning('Courier webhook rejected: ' . $e->getMessage(), ['account_id' => $account->id]);
            CourierLogger::incoming($account, $request, 401, 'Rejected: ' . $e->getMessage(), null, 'error');

            return response()->json(['message' => $e->getMessage()], 401);
        }

        $applied = 0;
        $matched = [];
        $updates = $update instanceof WebhookUpdate ? [$update] : (array) $update;
        foreach ($updates as $one) {
            // Couriers identify a parcel by its tracking number or by their own
            // order/shipment id (stored as courier_reference).
            $shipment = Shipment::query()
                ->where('courier_account_id', $account->id)
                ->where(fn ($q) => $q->where('tracking_number', $one->trackingNumber)->orWhere('courier_reference', $one->trackingNumber))
                ->whereNull('deleted_at')
                ->latest('id')
                ->first();
            if ($shipment) {
                $updater->apply($shipment, $one->result, 'webhook');
                $applied++;
                $matched[] = $shipment->id;
            }
        }

        [$status, $body, $headers] = array_pad($driver->webhookAck(), 3, []);
        if ($body === ['ok' => true] && ! $applied) {
            $body['ignored'] = true;
        }

        $first = $updates[0] ?? null;
        CourierLogger::incoming($account, $request, (int) $status, match (true) {
            ! $updates => 'Received: nothing to apply (test ping or unsupported event).',
            ! $applied => 'Received: ' . count($updates) . ' update(s), no matching shipment' . ($first ? ' (' . $first->trackingNumber . ')' : '') . '.',
            default => 'Applied ' . $applied . ' of ' . count($updates) . ' update(s)' . ($first?->result->status ? ' — ' . $first->result->status : '') . '.',
        }, count($matched) === 1 ? $matched[0] : null);

        return response()->json($body, $status, (array) $headers);
    }
}
