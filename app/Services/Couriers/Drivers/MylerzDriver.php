<?php

namespace App\Services\Couriers\Drivers;

use App\Models\Shipment;
use App\Services\Couriers\AbstractCourierDriver;
use App\Services\Couriers\CourierException;
use App\Services\Couriers\CourierStatus;
use App\Services\Couriers\Data\BookingResult;
use App\Services\Couriers\Data\ConnectionResult;
use App\Services\Couriers\Data\ShipmentRequest;
use App\Services\Couriers\Data\TrackingEvent;
use App\Services\Couriers\Data\TrackingResult;
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Mylerz (Egypt, Tunisia, Morocco, Algeria, Jordan), from the live public
 * Swagger ("MYLERZ.Integration.Api" v1) and Mylerz's WooCommerce plugin.
 * One host per country; OAuth2 password grant → bearer token (cached).
 * Envelope {Value, IsErrorState, ErrorDescription}. The drop-off is a zone
 * code from GetCityZoneList (sent as Neighborhood); the AWB is a base64 PDF.
 * The webhook payload is undocumented, so status is polled (TrackPackages).
 * There is no public sandbox.
 */
class MylerzDriver extends AbstractCourierDriver
{
    private const HOSTS = [
        'EG' => 'https://integration.mylerz.net/',
        'TN' => 'https://integration.tunisia.mylerz.net/',
        'MA' => 'https://integration.morocco.mylerz.net/',
        'DZ' => 'https://integration.algeria.mylerz.net/',
        'JO' => 'https://integration.jordan.mylerz.net/',
    ];

    private const COUNTRY_NAMES = ['EG' => 'Egypt', 'TN' => 'Tunisia', 'MA' => 'Morocco', 'DZ' => 'Algeria', 'JO' => 'Jordan'];
    private const CURRENCIES = ['EG' => 'EGP', 'TN' => 'TND', 'MA' => 'MAD', 'DZ' => 'DZD', 'JO' => 'JOD'];

    public static function meta(): array
    {
        return [
            'code' => 'mylerz',
            'name' => 'Mylerz',
            'region' => 'Egypt / North Africa / Jordan',
            'countries' => ['EG', 'TN', 'MA', 'DZ', 'JO'],
            'website' => 'https://mylerz.com',
            'tracking_url' => null,
            'description' => 'COD deliveries with Mylerz: booking by zone, airway bills, tracking and cancellation.',
            'capabilities' => [
                'booking' => true,
                'labels' => true,
                'cancel' => true,
                'tracking' => true,
                'cod' => true,
                'cities' => true,
                'pickup' => true,
            ],
            'credentials' => [
                ['key' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true,
                    'help' => 'Your Mylerz integration login.'],
                ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true],
            ],
            'settings' => [
                ['key' => 'country', 'label' => 'Country', 'type' => 'select', 'options' => [
                    ['value' => 'EG', 'label' => 'Egypt'], ['value' => 'TN', 'label' => 'Tunisia'], ['value' => 'MA', 'label' => 'Morocco'],
                    ['value' => 'DZ', 'label' => 'Algeria'], ['value' => 'JO', 'label' => 'Jordan'],
                ], 'help' => 'Leave empty for Egypt.'],
                ['key' => 'warehouse_name', 'label' => 'Warehouse name', 'type' => 'text', 'required' => true,
                    'help' => '"Test connection" lists your Mylerz warehouses.'],
                ['key' => 'service_type', 'label' => 'Service type', 'type' => 'select', 'options' => [
                    ['value' => 'DTD', 'label' => 'Door to door'], ['value' => 'DTC', 'label' => 'Door to counter'],
                    ['value' => 'CTD', 'label' => 'Counter to door'], ['value' => 'CTC', 'label' => 'Counter to counter'],
                ], 'help' => 'Leave empty for door to door.'],
                ['key' => 'service', 'label' => 'Speed', 'type' => 'select', 'options' => [
                    ['value' => 'ND', 'label' => 'Next day'], ['value' => 'SD', 'label' => 'Same day'],
                ], 'help' => 'Leave empty for next day.'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        try {
            $res = $this->client()->get($this->base() . 'api/Orders/GetWarehouses');
        } catch (CourierException $e) {
            return ConnectionResult::failed($e->getMessage());
        }
        if (! $this->ok($res)) {
            return ConnectionResult::failed('Mylerz: ' . $this->error($res));
        }

        $names = collect($res->json('Value') ?? [])->pluck('Name')->filter()->implode(', ');

        return ConnectionResult::ok('Connected to Mylerz.' . ($names ? " Warehouses: {$names}." : ''));
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $zone = $this->zone($request);
        $country = $this->country();
        $cod = $request->codAmount > 0;

        $pieces = [];
        for ($i = 1; $i <= $request->pieces; $i++) {
            $pieces[] = ['PieceNo' => $i, 'SpecialNotes' => ''];
        }

        $order = array_filter([
            'WarehouseName' => (string) $this->account->setting('warehouse_name'),
            'PickupDueDate' => now()->addDay()->format('Y-m-d H:i:s'),
            'Package_Serial' => 1,
            'Reference' => mb_substr($request->reference, 0, 100),
            'Description' => mb_substr($request->itemsDescription ?: 'Goods', 0, 250),
            'Service_Type' => $this->account->setting('service_type') ?: 'DTD',
            'Service' => $request->service && in_array($request->service, ['ND', 'SD'], true) ? $request->service : ($this->account->setting('service') ?: 'ND'),
            'Service_Category' => 'DELIVERY',
            'Payment_Type' => $cod ? 'COD' : 'PP',
            'COD_Value' => $cod ? round($request->codAmount, 2) : 0,
            'Pieces' => $pieces,
            'Total_Weight' => round($request->weight, 2),
            'Customer_Name' => $request->consigneeName,
            'Mobile_No' => preg_replace('/\s+/', '', $request->consigneePhone),
            'Customer_Email' => $request->consigneeEmail,
            'Street' => $request->address,
            'City' => $zone['city_code'] ?? null,
            'Neighborhood' => $zone['code'],
            'Country' => self::COUNTRY_NAMES[$country] ?? 'Egypt',
            'Address_Category' => 'H',
            'Currency' => self::CURRENCIES[$country] ?? 'EGP',
            'Special_Notes' => $request->notes ?: null,
            'ValueOfGoods' => $request->declaredValue > 0 ? round($request->declaredValue, 2) : null,
        ], fn ($v) => $v !== null && $v !== '');

        $res = $this->client()->post($this->base() . 'api/Orders/AddOrders', [$order]);
        $package = $res->json('Value.Packages.0') ?? [];
        if (! $this->ok($res) || empty($package['BarCode'])) {
            throw new CourierException('Mylerz: ' . (($package['ErrorMessage'] ?? null) ?: $this->error($res)));
        }

        return new BookingResult(
            trackingNumber: (string) $package['BarCode'],
            courierReference: (string) ($res->json('Value.PickupOrderCode') ?? ''),
            status: CourierStatus::BOOKED,
            rawStatus: $package['Status'] ?? 'Created',
        );
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->client()->post($this->base() . 'api/packages/CancelPackage', [['Barcode' => (string) $shipment->tracking_number]]);
        $row = $res->json('Value.0') ?? [];
        if (! $this->ok($res) || empty($row['IsChanged'])) {
            throw new CourierException('Mylerz: ' . (($row['ErrorMessage'] ?? null) ?: $this->error($res)));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->client()->post($this->base() . 'api/packages/TrackPackages', [['Barcode' => (string) $shipment->tracking_number]]);
        $p = $res->json('Value.0');
        if (! $this->ok($res) || ! is_array($p)) {
            throw new CourierException('Mylerz: ' . $this->error($res));
        }

        $events = [];
        foreach ($p['TrackLog'] ?? [] as $log) {
            $text = $log['StatusEnName'] ?? null;
            $events[] = new TrackingEvent(
                status: $this->map($text),
                rawStatus: $text,
                at: $this->time($log['ChangedDate'] ?? null),
                description: $log['StatusArName'] ?? null,
            );
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));
        $latest = $events ? $events[count($events) - 1] : null;

        return new TrackingResult(
            status: $latest?->status,
            rawStatus: $latest?->rawStatus,
            events: $events,
            deliveredAt: $latest?->status === CourierStatus::DELIVERED ? $latest->at : null,
        );
    }

    /** Base64 PDF airway bill. */
    public function label(Shipment $shipment): array
    {
        $res = $this->client()->post($this->base() . 'api/packages/GetAWB', ['Barcode' => (string) $shipment->tracking_number]);
        $data = $res->json('Value');
        if (! $this->ok($res) || ! is_string($data) || $data === '') {
            throw new CourierException('Mylerz: ' . $this->error($res));
        }

        return ['pdf' => base64_decode($data)];
    }

    /** Zones (what Mylerz delivers to): {code: zone Code, name: "Zone (City)"}. */
    public function cities(): array
    {
        $res = $this->client()->get($this->base() . 'api/packages/GetCityZoneList');
        if (! $this->ok($res)) {
            throw new CourierException('Mylerz: ' . $this->error($res));
        }

        $out = [];
        foreach ($res->json('Value') ?? [] as $city) {
            foreach ($city['Zones'] ?? [] as $zone) {
                if (! empty($zone['Code'])) {
                    $out[] = [
                        'code' => (string) $zone['Code'],
                        'name' => trim(($zone['EnName'] ?? $zone['Code']) . ' (' . ($city['EnName'] ?? '') . ')'),
                        'city_code' => (string) ($city['Code'] ?? ''),
                    ];
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------

    /** Mylerz wording first ("Mylerz on the way", "Delivered, enjoy it :-)"), then the shared keywords. */
    private function map(?string $text): ?string
    {
        $t = mb_strtolower((string) $text);
        if ($t === '') {
            return null;
        }
        if (str_contains($t, 'on the way')) {
            return CourierStatus::OUT_FOR_DELIVERY;
        }
        if (str_contains($t, 'reject') || str_contains($t, 'postpon') || str_contains($t, 'not answer')) {
            return CourierStatus::FAILED_ATTEMPT;
        }

        return StatusText::map($text);
    }

    private function zone(ShipmentRequest $request): array
    {
        $all = $this->cities();
        if ($request->courierCityCode) {
            foreach ($all as $z) {
                if ($z['code'] === (string) $request->courierCityCode) {
                    return $z;
                }
            }
        }
        foreach ([$request->state, $request->city] as $guess) {
            $needle = mb_strtolower(trim((string) $guess));
            if ($needle === '') {
                continue;
            }
            foreach ($all as $z) {
                if (mb_strtolower(trim(explode(' (', $z['name'])[0])) === $needle) {
                    return $z;
                }
            }
        }
        throw new CourierException('Choose the Mylerz zone for "' . $request->city . '".');
    }

    private function token(): string
    {
        $key = 'courier_mylerz_token:' . (tenant('id') ?? 'central') . ':' . md5($this->country() . '|' . $this->account->credential('username') . '|' . $this->account->credential('password'));

        return Cache::get($key) ?? (function () use ($key) {
            $res = $this->http()->asForm()->post($this->base() . 'token', [
                'username' => $this->account->credential('username'),
                'password' => $this->account->credential('password'),
                'grant_type' => 'password',
            ]);
            $token = $res->json('access_token');
            if (! $res->successful() || ! $token) {
                throw new CourierException('Mylerz: ' . ($res->json('error_description') ?: 'login failed') . '.');
            }
            // Lifetime is undocumented; honour expires_in when present.
            $ttl = is_numeric($res->json('expires_in')) ? max(60, (int) $res->json('expires_in') - 300) : 6 * 3600;
            Cache::put($key, $token, now()->addSeconds($ttl));

            return $token;
        })();
    }

    private function client()
    {
        return $this->http()->withToken($this->token());
    }

    private function country(): string
    {
        $c = strtoupper((string) ($this->account->setting('country') ?: 'EG'));

        return isset(self::HOSTS[$c]) ? $c : 'EG';
    }

    private function base(): string
    {
        return self::HOSTS[$this->country()];
    }

    private function ok(Response $res): bool
    {
        return $res->successful() && $res->json('IsErrorState') !== true;
    }

    private function time(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function error(Response $res): string
    {
        return $res->json('ErrorDescription') ?: $res->json('Message') ?: ('HTTP ' . $res->status());
    }
}
