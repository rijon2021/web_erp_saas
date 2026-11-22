<?php

namespace App\Services\Couriers;

use App\Models\CourierAccount;
use App\Services\Couriers\Contracts\CourierDriver;
use App\Services\Couriers\Drivers\AfterShipDriver;
use App\Services\Couriers\Drivers\AramexDriver;
use App\Services\Couriers\Drivers\BostaDriver;
use App\Services\Couriers\Drivers\JtExpressDriver;
use App\Services\Couriers\Drivers\MylerzDriver;
use App\Services\Couriers\Drivers\NaqelDriver;
use App\Services\Couriers\Drivers\SmsaDriver;
use App\Services\Couriers\Drivers\BlueExDriver;
use App\Services\Couriers\Drivers\CallCourierDriver;
use App\Services\Couriers\Drivers\DaewooDriver;
use App\Services\Couriers\Drivers\EasyPostDriver;
use App\Services\Couriers\Drivers\LeopardsDriver;
use App\Services\Couriers\Drivers\BiteshipDriver;
use App\Services\Couriers\Drivers\EasyParcelDriver;
use App\Services\Couriers\Drivers\ManualDriver;
use App\Services\Couriers\Drivers\MnpDriver;
use App\Services\Couriers\Drivers\OtoDriver;
use App\Services\Couriers\Drivers\PostExDriver;
use App\Services\Couriers\Drivers\QuiqupDriver;
use App\Services\Couriers\Drivers\SendboxDriver;
use App\Services\Couriers\Drivers\ShiipDriver;
use App\Services\Couriers\Drivers\ShipbubbleDriver;
use App\Services\Couriers\Drivers\TopshipDriver;
use App\Services\Couriers\Drivers\SendcloudDriver;
use App\Services\Couriers\Drivers\SeventeenTrackDriver;
use App\Services\Couriers\Drivers\ShiplogicDriver;
use App\Services\Couriers\Drivers\ShippoDriver;
use App\Services\Couriers\Drivers\ShiprocketDriver;
use App\Services\Couriers\Drivers\TcsDriver;
use App\Services\Couriers\Drivers\TorodDriver;
use App\Services\Couriers\Drivers\TraxDriver;
use App\Services\Couriers\Drivers\DelhiveryDriver;
use App\Services\Couriers\Drivers\DhlExpressDriver;
use App\Services\Couriers\Drivers\FedExDriver;
use App\Services\Couriers\Drivers\UpsDriver;
use App\Services\Couriers\Drivers\ECourierDriver;
use App\Services\Couriers\Drivers\FlashExpressDriver;
use App\Services\Couriers\Drivers\LalamoveDriver;
use App\Services\Couriers\Drivers\NinjaVanDriver;
use App\Services\Couriers\Drivers\PathaoDriver;
use App\Services\Couriers\Drivers\RedxDriver;
use App\Services\Couriers\Drivers\ShadowfaxDriver;
use App\Services\Couriers\Drivers\SteadfastDriver;

/**
 * Every courier Stocky can talk to. Adding a courier = one driver class in
 * Drivers/ plus one line in DRIVERS (and removing it from PLANNED).
 */
final class CourierRegistry
{
    /** code => driver class */
    public const DRIVERS = [
        'manual' => ManualDriver::class,
        'aftership' => AfterShipDriver::class,
        '17track' => SeventeenTrackDriver::class,
        // Phase 3 — Pakistan
        'postex' => PostExDriver::class,
        'leopards' => LeopardsDriver::class,
        'tcs' => TcsDriver::class,
        'trax' => TraxDriver::class,
        'daewoo' => DaewooDriver::class,
        'mnp' => MnpDriver::class,
        'blueex' => BlueExDriver::class,
        'callcourier' => CallCourierDriver::class,
        // Phase 4 — aggregators
        'easypost' => EasyPostDriver::class,
        'shippo' => ShippoDriver::class,
        'shiprocket' => ShiprocketDriver::class,
        'sendcloud' => SendcloudDriver::class,
        'oto' => OtoDriver::class,
        'torod' => TorodDriver::class,
        'easyparcel' => EasyParcelDriver::class,
        'biteship' => BiteshipDriver::class,
        'shiplogic' => ShiplogicDriver::class,
        'sendbox' => SendboxDriver::class,
        'shiip' => ShiipDriver::class,
        'shipbubble' => ShipbubbleDriver::class,
        'topship' => TopshipDriver::class,
        // Phase 5 — Gulf & Egypt
        'quiqup' => QuiqupDriver::class,
        'aramex' => AramexDriver::class,
        'smsa' => SmsaDriver::class,
        'jt_express' => JtExpressDriver::class,
        'naqel' => NaqelDriver::class,
        'bosta' => BostaDriver::class,
        'mylerz' => MylerzDriver::class,
        // Phase 6 — Bangladesh, India, Southeast Asia
        'steadfast' => SteadfastDriver::class,
        'pathao' => PathaoDriver::class,
        'redx' => RedxDriver::class,
        'ecourier' => ECourierDriver::class,
        'delhivery' => DelhiveryDriver::class,
        'shadowfax' => ShadowfaxDriver::class,
        'ninjavan' => NinjaVanDriver::class,
        'flash_express' => FlashExpressDriver::class,
        'lalamove' => LalamoveDriver::class,
        // Phase 7 — global express
        'dhl_express' => DhlExpressDriver::class,
        'fedex' => FedExDriver::class,
        'ups' => UpsDriver::class,
    ];

    /**
     * Announced but not built yet — shown greyed out in the courier picker
     * so tenants can see what is coming. phase = rollout batch.
     * Shape: ['code' => …, 'name' => …, 'region' => …, 'phase' => n]. Empty: every planned courier is built.
     */
    public const PLANNED = [];

    public static function has(?string $code): bool
    {
        return $code !== null && isset(self::DRIVERS[$code]);
    }

    public static function codes(): array
    {
        return array_keys(self::DRIVERS);
    }

    public static function meta(string $code): ?array
    {
        return self::has($code) ? self::normalizeMeta((self::DRIVERS[$code])::meta()) : null;
    }

    /** Available drivers (full meta) + the planned list, for the account form. */
    public static function catalog(): array
    {
        $available = array_map(fn ($code) => self::meta($code), self::codes());
        $built = array_flip(self::codes());

        return [
            'available' => array_values($available),
            'planned' => array_values(array_filter(self::PLANNED, fn ($p) => ! isset($built[$p['code']]))),
        ];
    }

    public static function make(CourierAccount $account): CourierDriver
    {
        if (! self::has($account->courier)) {
            throw new CourierException("Unknown courier '{$account->courier}'.");
        }

        $class = self::DRIVERS[$account->courier];

        return new $class($account);
    }

    /** Fill the optional meta keys so the SPA can rely on them. */
    private static function normalizeMeta(array $meta): array
    {
        $field = fn (array $f) => $f + ['type' => 'text', 'required' => false, 'help' => null, 'options' => []];

        $meta['capabilities'] = ($meta['capabilities'] ?? []) + [
            'booking' => false, 'manual_tracking' => false, 'rates' => false, 'coordinates' => false, 'labels' => false, 'cancel' => false, 'tracking' => false,
            'webhooks' => false, 'cod' => false, 'cities' => false, 'pickup' => false, 'test_mode' => false,
        ];
        $meta['credentials'] = array_map($field, $meta['credentials'] ?? []);
        $meta['settings'] = array_map($field, $meta['settings'] ?? []);

        return $meta + [
            'region' => null, 'countries' => [], 'website' => null,
            'tracking_url' => null, 'description' => null, 'logo' => null,
        ];
    }
}
