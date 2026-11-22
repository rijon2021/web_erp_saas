<?php

namespace App\Services\Couriers\Support;

use App\Services\Couriers\Data\Rate;

/**
 * Chooses one rate for a booking. The preference comes from the booking form
 * (a rate id picked in "Compare rates") or the account's default service:
 *   - a rate id                → that rate
 *   - a service-level token    → that service (live checkout rates)
 *   - "fastest"                → fewest days, then cheapest
 *   - "cheapest" / empty       → cheapest
 *   - any other text, e.g. "ups" or "dhl:express"
 *                              → cheapest rate whose carrier/service matches
 */
final class RatePicker
{
    /** @param Rate[] $rates */
    public static function pick(array $rates, ?string $preference): ?Rate
    {
        if (! $rates) {
            return null;
        }

        $pref = trim((string) $preference);
        foreach ($rates as $r) {
            if ($pref !== '' && $r->id === $pref) {
                return $r;
            }
        }
        // A service-level token (e.g. "usps_priority") the customer chose at checkout.
        foreach ($rates as $r) {
            if ($pref !== '' && ($r->meta['servicelevel'] ?? null) === $pref) {
                return $r;
            }
        }

        $byPrice = fn (Rate $a, Rate $b) => $a->amount <=> $b->amount;
        $lower = mb_strtolower($pref);

        if ($lower === 'fastest') {
            usort($rates, fn (Rate $a, Rate $b) => [($a->days ?? PHP_INT_MAX), $a->amount] <=> [($b->days ?? PHP_INT_MAX), $b->amount]);

            return $rates[0];
        }

        if ($lower !== '' && $lower !== 'cheapest') {
            [$carrier, $service] = array_pad(explode(':', $lower, 2), 2, null);
            $matches = array_values(array_filter($rates, function (Rate $r) use ($carrier, $service) {
                $c = mb_strtolower($r->carrier);
                $s = mb_strtolower($r->service);
                if ($service !== null) {
                    return str_contains($c, trim($carrier)) && str_contains($s, trim($service));
                }

                return str_contains($c, $carrier) || str_contains($s, $carrier);
            }));
            if (! $matches) {
                return null;
            }
            usort($matches, $byPrice);

            return $matches[0];
        }

        usort($rates, $byPrice);

        return $rates[0];
    }
}
