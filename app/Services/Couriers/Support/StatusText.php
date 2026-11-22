<?php

namespace App\Services\Couriers\Support;

use App\Services\Couriers\CourierStatus;

/**
 * Courier status wording → Stocky status. Drivers map the codes their API
 * documents first; this keyword fallback catches wording that varies by
 * branch or changes over time ("Delivered to consignee", "Return to origin"…).
 * Order matters: return/cancel phrases are checked before delivery ones,
 * because "Returned to shipper — delivered" means the parcel came back.
 */
final class StatusText
{
    private const RULES = [
        CourierStatus::RETURNED => ['returned to shipper', 'returned to origin', 'return delivered', 'returned to vendor',
            'returned to merchant', 'return received', 'rts delivered', 'delivered to shipper', 'returned'],
        CourierStatus::RETURNING => ['return in process', 'return in transit', 'return to shipper', 'return to origin',
            'being returned', 'returning', 'rts', 'return initiated', 'ready for return', 'return'],
        CourierStatus::CANCELLED => ['cancelled', 'canceled', 'cancel'],
        CourierStatus::FAILED_ATTEMPT => ['refused', 'not delivered', 'undelivered', 'attempt failed', 'delivery failed',
            'customer not available', 'consignee not available', 'not responding', 'incomplete address', 'on hold',
            'hold', 'unable to deliver', 'attempted', 'unsuccessful', 'shipment refused', 're-attempt', 'reattempt'],
        CourierStatus::OUT_FOR_DELIVERY => ['out for delivery', 'out-for-delivery', 'on route', 'en route to consignee',
            'with rider', 'assigned to rider', 'dispatched for delivery', 'delivery in progress'],
        CourierStatus::DELIVERED => ['delivered', 'successfully delivered', 'shipment delivered'],
        CourierStatus::IN_TRANSIT => ['in transit', 'transit', 'arrived at', 'arrival', 'departed', 'dispatched', 'forwarded',
            'received at', 'at hub', 'at facility', 'sorting', 'manifested', 'en route', 'in process'],
        CourierStatus::PICKED_UP => ['picked up', 'picked', 'pickup done', 'collected from shipper', 'received from shipper',
            'shipment received'],
        CourierStatus::BOOKED => ['booked', 'unbooked', 'pickup requested', 'order placed', 'created', 'pending pickup',
            'ready for pickup', 'awaiting pickup', 'pickup pending', 'new order'],
    ];

    public static function map(?string $text): ?string
    {
        // Hyphens count as spaces: "En-Route" must match "en route".
        $t = mb_strtolower(trim(preg_replace('/[\s\-]+/u', ' ', (string) $text)));
        if ($t === '') {
            return null;
        }

        foreach (self::RULES as $status => $phrases) {
            foreach ($phrases as $phrase) {
                $phrase = str_replace('-', ' ', $phrase);
                if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/u', $t)) {
                    return $status;
                }
            }
        }

        return null;
    }
}
