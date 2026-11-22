<?php

namespace App\Services\Shipping;

use App\Models\CourierAccount;
use App\Models\PrintfulSetting;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Services\CountryService;
use App\Services\Couriers\CourierLogger;
use App\Services\Couriers\CourierRegistry;
use App\Services\Couriers\Data\Rate;
use App\Services\Couriers\Data\ShipmentRequest;
use App\Services\Printful\OrderService as PrintfulOrderService;
use App\Services\StoreCurrencyService;
use App\Services\TenantLimitsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Checkout options of a live shipping method: the cart's parcel and the
 * customer's address are quoted by the method's courier account — Shippo or
 * Shipbubble, whichever the store picked (a Printful
 * method: by Printful, for the cart's print-on-demand items), and every
 * carrier service comes back with the store's handling fee, free-shipping
 * threshold and backup rate applied.
 *
 * Quotes are cached per parcel + address, so the price the customer picked is
 * the price the order is placed at (the payment session and the order both
 * re-read the same cache). A failed lookup is cached briefly and falls back to
 * the backup rate, if the store set one.
 */
class LiveShippingRates
{
    /** Courier drivers a live method can quote with. */
    public const COURIERS = ['shippo', 'shipbubble', 'topship'];

    /** Couriers that verify the street address before quoting (no quote from a city alone). */
    private const NEEDS_STREET = ['shipbubble'];

    private const CACHE_MINUTES = 60;

    private const FAILURE_MINUTES = 2;

    /** Uncached carrier lookups one visitor may cause per 10 minutes. */
    private const LOOKUPS_PER_IP = 40;

    /**
     * @param  array  $lines  checkout line items (product_id, qty)
     * @param  array  $destination  name/phone/email/address/city/state/zip/country
     * @return array{pending: bool, options: list<array{key: string, name: string, price: float, days: ?int, quote: array}>}
     */
    public function options(ShippingMethod $method, array $lines, array $destination, float $netSubtotal): array
    {
        if (! $this->hasDestination($destination)) {
            return ['pending' => true, 'options' => []];
        }

        $printful = $method->isPrintful();
        $account = $printful ? null : $this->account($method);
        if ($account && in_array($account->courier, self::NEEDS_STREET, true) && trim((string) ($destination['address'] ?? '')) === '') {
            return ['pending' => true, 'options' => []];
        }
        $parcel = $printful ? null : $this->parcel($lines, $account);
        $printfulSettings = $printful ? $this->printfulSettings() : null;
        $rates = $printful
            ? ($printfulSettings ? $this->printfulRates($printfulSettings, $lines, $destination) : null)
            : ($account ? $this->rates($account, $parcel, $destination, $netSubtotal) : null);

        $handlingFixed = max(0, (float) $method->liveSetting('handling_fee', 0));
        $handlingPercent = max(0, (float) $method->liveSetting('handling_percent', 0));
        $factor = $this->currencyFactor($method, (float) ($printfulSettings->exchange_rate ?? 0));
        $keywords = $this->serviceKeywords($method);

        $options = [];
        foreach ($rates ?? [] as $rate) {
            $toStore = $factor($rate['currency']);
            if ($toStore === null) {
                continue; // a currency the store has no exchange rate for
            }
            if ($keywords && ! $this->matches($rate, $keywords)) {
                continue;
            }
            $cost = $rate['amount'] * $toStore;
            $options[] = [
                'key' => $rate['key'],
                'name' => trim($rate['carrier'] . ' ' . $rate['service']),
                'price' => round($cost * (1 + $handlingPercent / 100) + $handlingFixed, 2),
                'days' => $rate['days'],
                'quote' => [
                    'courier_account_id' => $account?->id,
                    'carrier' => $rate['carrier'],
                    'service' => $rate['service'],
                    'service_token' => $rate['token'],
                    'carrier_amount' => $rate['amount'],
                    'currency' => $rate['currency'],
                    'days' => $rate['days'],
                    'parcel' => $parcel,
                    'fallback' => false,
                ] + ($printful ? ['source' => 'printful'] : []),
            ];
        }

        usort($options, fn ($a, $b) => [$a['price'], $a['days'] ?? PHP_INT_MAX] <=> [$b['price'], $b['days'] ?? PHP_INT_MAX]);
        $max = (int) $method->liveSetting('max_options', 0);
        if ($max > 0) {
            $options = array_slice($options, 0, $max);
        }

        // Carrier unreachable, nothing quoted, or nothing in a usable currency.
        $fallback = $method->liveSetting('fallback_price');
        if (! $options && is_numeric($fallback)) {
            $options[] = [
                'key' => 'fallback',
                'name' => $method->name,
                'price' => round(max(0, (float) $fallback), 2),
                'days' => null,
                'quote' => [
                    'courier_account_id' => $account?->id,
                    'service_token' => null,
                    'parcel' => $parcel,
                    'fallback' => true,
                ] + ($printful ? ['source' => 'printful'] : []),
            ];
        }

        // Free shipping over the threshold: the cheapest option costs nothing.
        $freeOver = $method->liveSetting('free_over');
        if ($options && is_numeric($freeOver) && (float) $freeOver > 0 && $netSubtotal >= (float) $freeOver) {
            $options[0]['price'] = 0.0;
            $options[0]['quote']['free_shipping'] = true;
        }

        return ['pending' => false, 'options' => array_values($options)];
    }

    /** Enough of an address to quote: a country plus a postal code or city. */
    public function hasDestination(array $destination): bool
    {
        return trim((string) ($destination['country'] ?? '')) !== ''
            && (trim((string) ($destination['zip'] ?? '')) !== '' || trim((string) ($destination['city'] ?? '')) !== '');
    }

    /** The method's active rate courier account, when the plan includes couriers. */
    public function account(ShippingMethod $method): ?CourierAccount
    {
        $id = (int) $method->liveSetting('courier_account_id', 0);
        if (! $id) {
            return null;
        }

        try {
            if (! app(TenantLimitsService::class)->hasFeature('couriers')) {
                return null;
            }

            return CourierAccount::query()->where('active', true)->whereIn('courier', self::COURIERS)->find($id);
        } catch (\Throwable $e) {
            return null; // courier tables not migrated on this tenant
        }
    }

    private function parcel(array $lines, ?CourierAccount $account): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($l) => (int) ($l['product_id'] ?? 0), $lines))));
        $products = $ids
            ? Product::query()->whereIn('id', $ids)->get(['id', 'weight', 'length', 'width', 'height'])->keyBy('id')
            : collect();

        return ParcelEstimator::estimate(array_map(fn ($l) => [
            'product' => $products->get((int) ($l['product_id'] ?? 0)),
            'units' => (float) ($l['qty'] ?? 0),
        ], $lines), $account);
    }

    /**
     * Carrier rates for the parcel + address in the carrier's currency, from
     * the cache when this exact parcel and address were quoted recently.
     * Null when the lookup failed.
     *
     * @return ?list<array{key: string, token: ?string, carrier: string, service: string, amount: float, currency: string, days: ?int}>
     */
    private function rates(CourierAccount $account, array $parcel, array $destination, float $declaredValue): ?array
    {
        $key = 'live_ship_rates:' . (tenant('id') ?? 'central') . ':' . $account->id . ':'
            . md5(json_encode([optional($account->updated_at)->timestamp, $account->mode, $parcel, $this->place($destination)]));

        return $this->remember($key, fn () => $this->fetchCarrier($account, $parcel, $destination, $declaredValue));
    }

    /**
     * Printful's rates for the cart's Printful items, cached like carrier
     * quotes. Null when the cart has none (the backup rate applies) or the
     * lookup failed.
     */
    private function printfulRates(PrintfulSetting $settings, array $lines, array $destination): ?array
    {
        $service = PrintfulOrderService::make($settings);
        $items = $service->rateItems($lines);
        if (! $items) {
            return null;
        }

        $key = 'live_ship_rates:' . (tenant('id') ?? 'central') . ':printful:'
            . md5(json_encode([optional($settings->updated_at)->timestamp, $settings->store_id, $items, $this->place($destination)]));

        return $this->remember($key, function () use ($service, $lines, $destination) {
            try {
                $rates = $service->rates($lines, $destination);
            } catch (\Throwable $e) {
                $service->log('rates', 'warning', 'Checkout rates failed: ' . $e->getMessage());
                throw $e;
            }
            usort($rates, fn ($a, $b) => $a['amount'] <=> $b['amount']);

            return array_values(collect($rates)->unique('key')->all());
        });
    }

    /** Printful settings when the plan has it and the store is connected. */
    private function printfulSettings(): ?PrintfulSetting
    {
        try {
            if (! PrintfulOrderService::enabled()) {
                return null;
            }
            $settings = PrintfulSetting::query()->first();

            return $settings && $settings->isReady() ? $settings : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The destination as a cache key part. */
    private function place(array $destination): array
    {
        return [
            'country' => CountryService::toCode($destination['country'] ?? null) ?? mb_strtolower(trim((string) ($destination['country'] ?? ''))),
            'state' => mb_strtolower(trim((string) ($destination['state'] ?? ''))),
            'city' => mb_strtolower(trim((string) ($destination['city'] ?? ''))),
            'zip' => mb_strtolower(preg_replace('/\s+/', '', (string) ($destination['zip'] ?? ''))),
            'address' => mb_strtolower(trim((string) ($destination['address'] ?? ''))),
        ];
    }

    /**
     * Cached quote lookup: a hit is returned as is, a recent failure as null,
     * otherwise $fetch runs (rate-limited per visitor) and its rates — or
     * its failure — are cached.
     */
    private function remember(string $key, \Closure $fetch): ?array
    {
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        if ($cached === false) {
            return null; // failed a moment ago; don't hammer the carrier
        }

        $limiter = 'live-ship-rates:' . request()->ip();
        if (RateLimiter::tooManyAttempts($limiter, self::LOOKUPS_PER_IP)) {
            return null;
        }
        RateLimiter::hit($limiter, 600);

        try {
            $rates = $fetch();
        } catch (\Throwable $e) {
            Log::info('Live shipping rates failed: ' . $e->getMessage());
            Cache::put($key, false, now()->addMinutes(self::FAILURE_MINUTES));

            return null;
        }

        Cache::put($key, $rates, now()->addMinutes(self::CACHE_MINUTES));

        return $rates;
    }

    /** @return list<array{key: string, token: ?string, carrier: string, service: string, amount: float, currency: string, days: ?int}> */
    private function fetchCarrier(CourierAccount $account, array $parcel, array $destination, float $declaredValue): array
    {
        $request = new ShipmentRequest(
            reference: 'checkout-quote',
            consigneeName: trim((string) ($destination['name'] ?? '')) ?: 'Customer',
            consigneePhone: trim((string) ($destination['phone'] ?? '')),
            address: trim((string) ($destination['address'] ?? '')),
            city: trim((string) ($destination['city'] ?? '')),
            consigneeEmail: trim((string) ($destination['email'] ?? '')) ?: null,
            country: trim((string) ($destination['country'] ?? '')) ?: null,
            zip: trim((string) ($destination['zip'] ?? '')) ?: null,
            declaredValue: $declaredValue,
            weight: $parcel['weight'],
            itemsDescription: 'Merchandise',
            state: trim((string) ($destination['state'] ?? '')) ?: null,
            length: $parcel['length'],
            width: $parcel['width'],
            height: $parcel['height'],
        );

        $found = CourierLogger::during($account, 'rates', null, fn () => CourierRegistry::make($account)->rates($request));

        $rates = [];
        foreach ($found as $rate) {
            /** @var Rate $rate */
            // The price meant for customers when the courier gives one (Shipbubble's rate card).
            $amount = (float) ($rate->meta['checkout_amount'] ?? $rate->amount);
            if ($amount < 0) {
                continue;
            }
            // Shippo's servicelevel token / Shipbubble's courier:service — booking picks the same service by it.
            $token = $rate->meta['servicelevel'] ?? null;
            $rates[] = [
                'key' => $token ?: substr(md5($rate->carrier . '|' . $rate->service), 0, 16),
                'token' => $token ?: null,
                'carrier' => $rate->carrier,
                'service' => $rate->service,
                'amount' => round($amount, 2),
                'currency' => strtoupper($rate->currency ?: 'USD'),
                'days' => $rate->days,
            ];
        }
        // One option per service: a carrier listed twice keeps its cheaper rate.
        usort($rates, fn ($a, $b) => $a['amount'] <=> $b['amount']);
        return array_values(collect($rates)->unique('key')->all());
    }

    /**
     * Multiplier from a quote currency to the store's base currency: 1 for the
     * same currency, the method's exchange rate otherwise, null when unknown.
     */
    private function currencyFactor(ShippingMethod $method, float $defaultRate = 0): \Closure
    {
        $base = strtoupper((string) (StoreCurrencyService::base()['code'] ?? 'USD'));
        // A Printful method falls back to the Printful settings' exchange rate.
        $rate = (float) $method->liveSetting('exchange_rate', 0) ?: $defaultRate;

        return fn (string $currency) => strtoupper($currency) === $base ? 1.0 : ($rate > 0 ? $rate : null);
    }

    /** @return string[] lower-cased "only show" keywords, e.g. ["usps", "ups ground"] */
    private function serviceKeywords(ShippingMethod $method): array
    {
        $raw = $method->liveSetting('services', []);
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map(fn ($k) => mb_strtolower(trim((string) $k)), $list)));
    }

    private function matches(array $rate, array $keywords): bool
    {
        $haystack = mb_strtolower($rate['carrier'] . ' ' . $rate['service'] . ' ' . ($rate['token'] ?? ''));
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
