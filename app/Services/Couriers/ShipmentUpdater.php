<?php

namespace App\Services\Couriers;

use App\Models\CourierSetting;
use App\Models\Sale;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\Couriers\Data\TrackingEvent;
use App\Services\Couriers\Data\TrackingResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place a shipment's courier state changes — webhook, scheduled sync
 * and staff edits all go through apply(). It records new timeline events,
 * moves the status forward, mirrors it onto the legacy shipping status, and
 * fires the Stocky effects of each transition:
 *   delivered → sale delivered, COD counted as collected
 *   returned  → review queue, or automatic restock (Courier Settings)
 *   COD remitted → sale payment (CourierLedger)
 *   final status → courier charges as an expense (CourierLedger)
 *   any status → customer SMS / email / WhatsApp, if enabled (CourierNotifier)
 */
class ShipmentUpdater
{
    /** COD only moves forward through these. */
    private const COD_ORDER = ['none' => 0, 'pending' => 1, 'collected' => 2, 'remitted' => 3];

    public function __construct(
        private CourierLedger $ledger,
        private ReturnProcessor $returns,
    ) {
    }

    /**
     * @param  string  $source  booking | webhook | poll | manual
     * @return bool whether anything changed
     */
    public function apply(Shipment $shipment, TrackingResult $result, string $source, ?User $user = null): bool
    {
        [$changed, $from, $to, $codFrom, $codTo, $shipment] = DB::transaction(function () use ($shipment, $result, $source) {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            $changed = $this->recordEvents($shipment, $result->events, $source) > 0;

            $from = $shipment->courier_status;
            $to = $this->nextStatus($from, $result->status, $source === 'manual');
            $codFrom = $shipment->cod_status;

            if ($to !== $from) {
                $shipment->courier_status = $to;
                $shipment->status = CourierStatus::legacy($to);
                $changed = true;

                if ($to === CourierStatus::DELIVERED) {
                    $shipment->delivered_at = $this->local($result->deliveredAt) ?? now();
                    // The courier has the cash once the parcel is delivered.
                    if ((float) $shipment->cod_amount > 0 && $shipment->cod_status === 'pending') {
                        $shipment->cod_status = 'collected';
                    }
                } elseif ($to === CourierStatus::RETURNED) {
                    $shipment->returned_at = $this->local($result->returnedAt) ?? now();
                    $shipment->return_status = $shipment->return_status ?: 'pending';
                    if ($shipment->cod_status !== 'remitted') {
                        $shipment->cod_status = (float) $shipment->cod_amount > 0 ? 'none' : $shipment->cod_status;
                    }
                } elseif ($to === CourierStatus::CANCELLED && $shipment->cod_status !== 'remitted') {
                    $shipment->cod_status = 'none';
                }
            }

            // Couriers that issue the real tracking number after booking (EasyParcel AWB,
            // Biteship waybill) report it here; the old number is kept in courier_meta.
            $newTracking = trim((string) ($result->meta['tracking_number'] ?? ''));
            if ($newTracking !== '' && $newTracking !== (string) $shipment->tracking_number) {
                $meta = (array) ($shipment->courier_meta ?? []);
                $meta['previous_tracking_numbers'] = array_values(array_unique(array_filter(array_merge(
                    (array) ($meta['previous_tracking_numbers'] ?? []), [$shipment->tracking_number]))));
                $shipment->courier_meta = $meta;
                $shipment->tracking_number = mb_substr($newTracking, 0, 100);
                $changed = true;
            }

            if ($result->rawStatus !== null && $result->rawStatus !== $shipment->courier_status_raw) {
                $shipment->courier_status_raw = mb_substr($result->rawStatus, 0, 191);
                $changed = true;
            }

            if ($result->codStatus && (float) $shipment->cod_amount > 0
                && (self::COD_ORDER[$result->codStatus] ?? -1) > (self::COD_ORDER[$shipment->cod_status ?? 'none'] ?? 0)) {
                $shipment->cod_status = $result->codStatus;
                $changed = true;
            }
            if ($shipment->cod_status === 'remitted' && $codFrom !== 'remitted') {
                $shipment->cod_remitted_at = $this->local($result->codRemittedAt) ?? now();
                $shipment->cod_reference = $result->codReference ?? $shipment->cod_reference;
            }

            if ($result->charges !== null && (float) $result->charges > 0 && (float) $shipment->shipping_charges !== (float) $result->charges
                && ! $shipment->charges_expense_id) {
                $shipment->shipping_charges = (float) $result->charges;
                $changed = true;
            }

            if ($source === 'poll' || $source === 'webhook') {
                $shipment->last_synced_at = now();
                $shipment->sync_error = null;
                $shipment->sync_failures = 0;
            }

            $shipment->save();

            return [$changed, $from, $to, $codFrom, $shipment->cod_status, $shipment];
        });

        if ($to !== $from) {
            $this->onStatusChange($shipment, $to, $user);
        }
        // Not only on the transition: a payment / expense that failed once is retried on the
        // next update. The ledger re-checks cod_payment_id / charges_expense_id under a lock.
        if ($shipment->cod_status === 'remitted' && ! $shipment->cod_payment_id) {
            $this->safely('COD payment', $shipment, fn () => $this->ledger->recordCodPayment($shipment->fresh(), $user));
        }
        if (in_array($shipment->courier_status, [CourierStatus::DELIVERED, CourierStatus::RETURNED], true)
            && ! $shipment->charges_expense_id && (float) $shipment->shipping_charges > 0) {
            $this->safely('courier charges expense', $shipment, fn () => $this->ledger->recordChargesExpense($shipment->fresh(), $user));
        }

        return $changed;
    }

    /** Staff moved the status by hand (manual courier, or a correction). */
    public function setStatusManually(Shipment $shipment, string $status, ?string $note, User $user): bool
    {
        if (! CourierStatus::isValid($status)) {
            throw new CourierException('Unknown status.');
        }
        // Say so instead of "succeeding" without moving the status.
        $current = $shipment->courier_status;
        if ($status !== $current && $this->nextStatus($current, $status, true) !== $status) {
            throw new CourierException($current === CourierStatus::DELIVERED
                ? 'A delivered parcel can only be marked as returning or returned.'
                : 'A returned or cancelled shipment can no longer change status.');
        }

        return $this->apply($shipment, new TrackingResult(
            status: $status,
            events: [new TrackingEvent($status, null, now(), null, $note ?: null, ['by' => $user->id])],
        ), 'manual', $user);
    }

    /** Staff marked the COD as collected / remitted by hand. */
    public function setCodManually(Shipment $shipment, string $codStatus, ?string $reference, User $user): bool
    {
        if (! in_array($codStatus, ['collected', 'remitted'], true)) {
            throw new CourierException('Unknown COD status.');
        }
        if ((float) $shipment->cod_amount <= 0) {
            throw new CourierException('This shipment has no COD amount.');
        }
        // A returned / cancelled parcel collected nothing: never create a payment for it.
        if (in_array($shipment->courier_status, [CourierStatus::RETURNED, CourierStatus::CANCELLED], true)) {
            throw new CourierException('This parcel was returned or cancelled, so there is no COD to record.');
        }
        if ($shipment->cod_status === 'remitted') {
            throw new CourierException('This COD was already paid out.');
        }

        $label = $codStatus === 'remitted' ? 'COD remitted' : 'COD collected';

        return $this->apply($shipment, new TrackingResult(
            status: null,
            events: [new TrackingEvent(null, $label, now(), null, $reference ?: null, ['by' => $user->id])],
            codStatus: $codStatus,
            codReference: $reference ?: null,
        ), 'manual', $user);
    }

    /** A status sync failed: keep the reason for the shipments page. */
    public function recordSyncFailure(Shipment $shipment, string $error): void
    {
        $shipment->forceFill([
            'last_synced_at' => now(),
            'sync_error' => mb_substr($error, 0, 500),
            'sync_failures' => (int) $shipment->sync_failures + 1,
        ])->save();
    }

    /**
     * @param  TrackingEvent[]  $events
     */
    public function recordEvents(Shipment $shipment, array $events, string $source): int
    {
        $added = 0;
        foreach ($events as $event) {
            // Couriers send UTC / offset times: store them in the workspace timezone.
            $eventAt = $this->local($event->at);
            $at = $eventAt?->toDateTimeString();
            $hash = ShipmentEvent::hashFor($event->rawStatus ?? $event->status, $at, $event->location, $event->description);

            $row = ShipmentEvent::query()->firstOrCreate(
                ['shipment_id' => $shipment->id, 'hash' => $hash],
                [
                    'status' => CourierStatus::isValid($event->status) ? $event->status : null,
                    'raw_status' => $event->rawStatus ? mb_substr($event->rawStatus, 0, 191) : null,
                    'description' => $event->description,
                    'location' => $event->location ? mb_substr($event->location, 0, 191) : null,
                    'event_at' => $eventAt ?? now(),
                    'source' => $source,
                    'payload' => $event->payload ?: null,
                ]
            );
            if ($row->wasRecentlyCreated) {
                $added++;
            }
        }

        return $added;
    }

    /** Lifecycle order; equal ranks may alternate (out for delivery ↔ attempt failed). */
    private const RANK = [
        CourierStatus::BOOKED => 1, CourierStatus::PICKED_UP => 2, CourierStatus::IN_TRANSIT => 3,
        CourierStatus::OUT_FOR_DELIVERY => 4, CourierStatus::FAILED_ATTEMPT => 4, CourierStatus::DELIVERED => 5,
        CourierStatus::RETURNING => 6, CourierStatus::RETURNED => 7, CourierStatus::CANCELLED => 7,
    ];

    /**
     * Statuses move forward: a late or out-of-order courier update never drags a
     * parcel back, and a final one sticks — except that a "delivered" parcel can
     * still be reported as coming back (refused after handover). Staff ($manual)
     * may correct a non-final status in either direction.
     */
    private function nextStatus(?string $current, ?string $reported, bool $manual = false): ?string
    {
        if (! CourierStatus::isValid($reported) || $reported === $current) {
            return $current;
        }
        if ($current === null || ! isset(self::RANK[$current])) {
            return $reported;
        }
        if ($current === CourierStatus::DELIVERED) {
            return in_array($reported, [CourierStatus::RETURNING, CourierStatus::RETURNED], true) ? $reported : $current;
        }
        if (CourierStatus::isFinal($current)) {
            return $current;
        }
        if ($manual) {
            return $reported;
        }
        // A parcel on its way back to the merchant is not "delivered" to the customer.
        if ($current === CourierStatus::RETURNING && $reported === CourierStatus::DELIVERED) {
            return $current;
        }

        return self::RANK[$reported] >= self::RANK[$current] ? $reported : $current;
    }

    private function onStatusChange(Shipment $shipment, string $to, ?User $user): void
    {
        // Mirror on the sale like the Shipments page does.
        $this->safely('sale shipping status', $shipment, function () use ($shipment, $to) {
            $sale = Sale::query()->whereNull('deleted_at')->find($shipment->sale_id);
            $sale?->update(['shipping_status' => CourierStatus::legacy($to)]);
        });

        if ($to === CourierStatus::RETURNED && CourierSetting::current()->return_action === 'restock') {
            $this->safely('automatic restock', $shipment, function () use ($shipment, $user) {
                // System-initiated: act as the owner, who may always create sale returns.
                $actor = $user ?: ActingUser::resolve(null);
                if ($actor) {
                    $this->returns->restock($shipment->fresh(), $actor, 'Restocked automatically (courier return)');
                }
            }, keepInQueue: true);
        }

        // Courier Settings → Customer notifications (after the response in web requests).
        $this->safely('customer notification', $shipment, fn () => app(CourierNotifier::class)->statusChanged($shipment, $to));
    }

    /**
     * Effects never undo the status update; a failure is logged (and for an
     * automatic restock, noted on the shipment so the queue shows why).
     */
    private function safely(string $what, Shipment $shipment, callable $fn, bool $keepInQueue = false): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning("Courier {$what} failed: " . $e->getMessage(), ['shipment_id' => $shipment->id]);
            if ($keepInQueue) {
                $shipment->fresh()?->forceFill(['return_note' => mb_substr('Automatic restock failed: ' . $e->getMessage(), 0, 500)])->save();
            }
        }
    }

    /** A courier time in the workspace timezone (stored wall-clock times are local). */
    private function local(?\Carbon\CarbonInterface $at): ?\Carbon\CarbonInterface
    {
        return $at?->copy()->setTimezone(config('app.timezone'));
    }
}
