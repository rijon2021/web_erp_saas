<?php

namespace App\Services\Couriers;

/**
 * The one courier lifecycle every driver maps its own statuses onto. Stored
 * in shipments.courier_status and shipment_events.status; the courier's
 * original wording is kept alongside (courier_status_raw / raw_status).
 */
final class CourierStatus
{
    public const BOOKED = 'booked';
    public const PICKED_UP = 'picked_up';
    public const IN_TRANSIT = 'in_transit';
    public const OUT_FOR_DELIVERY = 'out_for_delivery';
    public const FAILED_ATTEMPT = 'failed_attempt';
    public const DELIVERED = 'delivered';
    public const RETURNING = 'returning';
    public const RETURNED = 'returned';
    public const CANCELLED = 'cancelled';

    public const ALL = [
        self::BOOKED, self::PICKED_UP, self::IN_TRANSIT, self::OUT_FOR_DELIVERY,
        self::FAILED_ATTEMPT, self::DELIVERED, self::RETURNING, self::RETURNED, self::CANCELLED,
    ];

    /** Nothing changes after these, so polling stops. */
    public const FINAL = [self::DELIVERED, self::RETURNED, self::CANCELLED];

    /**
     * Legacy shipping status (shipments.status / sales.shipping_status:
     * ordered|packed|shipped|delivered|cancelled) each courier status shows as.
     */
    public const LEGACY = [
        self::BOOKED => 'packed',
        self::PICKED_UP => 'shipped',
        self::IN_TRANSIT => 'shipped',
        self::OUT_FOR_DELIVERY => 'shipped',
        self::FAILED_ATTEMPT => 'shipped',
        self::RETURNING => 'shipped',
        self::DELIVERED => 'delivered',
        self::RETURNED => 'cancelled',
        self::CANCELLED => 'cancelled',
    ];

    public static function isValid(?string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function isFinal(?string $status): bool
    {
        return in_array($status, self::FINAL, true);
    }

    public static function legacy(string $status): string
    {
        return self::LEGACY[$status] ?? 'shipped';
    }
}
