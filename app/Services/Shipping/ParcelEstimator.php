<?php

namespace App\Services\Shipping;

use App\Models\CourierAccount;
use App\Services\Couriers\Support\Shipper;

/**
 * One parcel for a list of products: what checkout quotes live carrier rates
 * for and what the courier label is later booked with, so both see the same
 * package. Weights are kg and sizes cm (the product Weight/Length/Width/Height
 * fields).
 *
 * Weight is the sum of product weight × units, else the courier account's
 * default weight. Size stacks the items: the longest length, the widest
 * width and the summed heights — used only when every product has all three
 * sides, otherwise the account's default box.
 */
final class ParcelEstimator
{
    /**
     * @param  iterable<array{product: ?object, units: float}>  $lines
     * @return array{weight: float, length: float, width: float, height: float, sized: bool}
     */
    public static function estimate(iterable $lines, ?CourierAccount $account = null): array
    {
        $weight = 0.0;
        $length = $width = $height = 0.0;
        $sized = true;
        $any = false;

        foreach ($lines as $line) {
            $product = $line['product'] ?? null;
            $units = max(0, (float) ($line['units'] ?? 0));
            if ($units <= 0) {
                continue;
            }
            $any = true;
            $weight += (float) ($product->weight ?? 0) * $units;

            $l = (float) ($product->length ?? 0);
            $w = (float) ($product->width ?? 0);
            $h = (float) ($product->height ?? 0);
            if ($l <= 0 || $w <= 0 || $h <= 0) {
                $sized = false;
                continue;
            }
            $length = max($length, $l);
            $width = max($width, $w);
            $height += $h * $units;
        }

        $sized = $sized && $any;
        $box = $account ? Shipper::dimensions($account) : ['length' => 20.0, 'width' => 15.0, 'height' => 10.0];

        return [
            'weight' => $weight > 0 ? round($weight, 3) : (float) ($account?->default_weight ?: 0.5),
            'length' => $sized ? round($length, 2) : (float) $box['length'],
            'width' => $sized ? round($width, 2) : (float) $box['width'],
            'height' => $sized ? round($height, 2) : (float) $box['height'],
            'sized' => $sized,
        ];
    }
}
