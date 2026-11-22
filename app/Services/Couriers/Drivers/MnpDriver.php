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
use App\Services\Couriers\Support\Phone;
use App\Services\Couriers\Support\StatusText;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;

/**
 * M&P — Muller & Phipps (Pakistan, COD). Request models from M&P's own
 * Swagger spec (https://mnpcourier.com/mycodapi/swagger/docs/v1); responses
 * are untyped there, so they are read defensively. Portal username/password
 * travel in every request (body for POST, query for GET) with endpoint-
 * specific casing; failures come back as HTTP 200 with isSuccess "false".
 * Cities are names; the pickup is a locationID. No webhooks; Stocky prints
 * the label (M&P's label response format is undocumented).
 */
class MnpDriver extends AbstractCourierDriver
{
    private const BASE = 'https://mnpcourier.com/mycodapi/api/';

    public static function meta(): array
    {
        return [
            'code' => 'mnp',
            'name' => 'M&P (Muller & Phipps)',
            'region' => 'Pakistan',
            'countries' => ['PK'],
            'website' => 'https://www.mulphilog.com',
            'tracking_url' => 'https://www.mulphilog.com/tracking/{tracking}',
            'description' => 'COD deliveries across Pakistan through the M&P COD API: booking, tracking, cancellation and COD payment status.',
            'capabilities' => [
                'booking' => true,
                'cancel' => true,
                'tracking' => true,
                'cod' => true,
                'cities' => true,
                'pickup' => true,
            ],
            'credentials' => [
                ['key' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true,
                    'help' => 'Your M&P COD portal login.'],
                ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true],
                ['key' => 'account_no', 'label' => 'Account number', 'type' => 'text', 'required' => true,
                    'help' => 'Issued by M&P, e.g. 4T154.'],
            ],
            'settings' => [
                ['key' => 'location_id', 'label' => 'Pickup location ID', 'type' => 'text', 'required' => true,
                    'help' => '"Test connection" lists your location IDs.'],
                ['key' => 'service', 'label' => 'Service', 'type' => 'text',
                    'help' => 'e.g. Overnight or Second Day. Leave empty for Overnight.'],
                ['key' => 'insert_type', 'label' => 'Insert type', 'type' => 'number', 'help' => 'Account value issued by M&P.'],
                ['key' => 'return_location', 'label' => 'Return location', 'type' => 'number', 'help' => 'Account value issued by M&P.'],
                ['key' => 'sub_account_id', 'label' => 'Sub-account ID', 'type' => 'number', 'help' => 'Account value issued by M&P.'],
                ['key' => 'fragile', 'label' => 'Mark parcels fragile', 'type' => 'switch'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->http()->get(self::BASE . 'Locations/Get_locations', $this->query('username', 'password') + ['AccountNo' => $this->account->credential('account_no')]);
        // Responses come wrapped in a 1-element list and isSuccess may be the string "false".
        $body = $this->first($res);
        if (! $res->successful() || ! $this->truthy($body['isSucces'] ?? $body['isSuccess'] ?? false)) {
            return ConnectionResult::failed('M&P: ' . ($body['message'] ?? ('HTTP ' . $res->status())));
        }

        $locations = collect($body['locationList'] ?? [])
            ->map(fn ($l) => ($l['locationID'] ?? '?') . ' (' . ($l['locationName'] ?? '') . ')')->implode(', ');

        return ConnectionResult::ok('Connected to M&P.' . ($locations ? " Location IDs: {$locations}." : ''));
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $acc = $this->account;
        $body = [
            'username' => $acc->credential('username'),
            'password' => $acc->credential('password'),
            'consigneeName' => $request->consigneeName,
            'consigneeAddress' => $request->address,
            'consigneeMobNo' => Phone::pk($request->consigneePhone),
            'consigneeEmail' => (string) $request->consigneeEmail,
            'destinationCityName' => $request->courierCityName ?: $request->city,
            'pieces' => $request->pieces,
            'weight' => round($request->weight, 3),
            'codAmount' => round($request->codAmount, 2),
            'custRefNo' => mb_substr($request->reference, 0, 50),
            'productDetails' => mb_substr($request->itemsDescription ?: 'Goods', 0, 50),
            'fragile' => $acc->setting('fragile') ? 'YES' : 'NO',
            'service' => $request->service ?: ($acc->setting('service') ?: 'Overnight'),
            'remarks' => mb_substr($request->notes, 0, 400),
            'insuranceValue' => '0',
            'locationID' => (string) $acc->setting('location_id'),
            'AccountNo' => $acc->credential('account_no'),
            'InsertType' => (int) ($acc->setting('insert_type') ?: 0),
            'ReturnLocation' => (int) ($acc->setting('return_location') ?: 0),
            'subAccountId' => (int) ($acc->setting('sub_account_id') ?: 0),
        ];

        $res = $this->http()->asJson()->post(self::BASE . 'Booking/InsertBookingData', $body);
        $row = $this->first($res);
        if (! $res->successful() || ! $this->truthy($row['isSuccess'] ?? false)) {
            throw new CourierException('M&P: ' . ($row['message'] ?? ('HTTP ' . $res->status())));
        }

        $cn = $row['orderReferenceId'] ?? $row['consignmentNumber'] ?? $row['ConsignmentNumber'] ?? $row['cn'] ?? null;
        if (! $cn) {
            throw new CourierException('M&P accepted the booking but sent no consignment number ("' . ($row['message'] ?? '') . '"). Check the M&P portal before booking again.');
        }

        return new BookingResult(trackingNumber: (string) $cn, status: CourierStatus::BOOKED, rawStatus: 'Booked', meta: ['message' => $row['message'] ?? null]);
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->http()->asJson()->post(self::BASE . 'Booking/VoidConsignment', [
            'Username' => $this->account->credential('username'),
            'password' => $this->account->credential('password'),
            'locationID' => (string) $this->account->setting('location_id'),
            'consignmentNumberList' => [(string) $shipment->tracking_number],
        ]);
        $row = $this->first($res);
        if (! $res->successful() || ! $this->truthy($row['isSuccess'] ?? false)) {
            throw new CourierException('M&P: ' . ($row['message'] ?? ('HTTP ' . $res->status())));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->http()->get(self::BASE . 'Tracking/Consignment_Tracking',
            $this->query('Username', 'password') + ['consignment' => (string) $shipment->tracking_number]);
        $row = $this->first($res);
        $detail = $row['tracking_Details'][0] ?? null;
        if (! $res->successful() || ! $this->truthy($row['isSuccess'] ?? false) || ! is_array($detail)) {
            throw new CourierException('M&P: ' . ($row['message'] ?? ('HTTP ' . $res->status())));
        }

        $events = [];
        foreach ($detail['CNTrackingDetail'] ?? [] as $h) {
            $text = $h['TrackingStatus'] ?? $h['Event'] ?? null;
            $events[] = new TrackingEvent(
                status: StatusText::map($text) ?? StatusText::map($h['TrackingNarration'] ?? null),
                rawStatus: $text,
                at: $this->time($h['TransactionTime'] ?? $h['TransDate'] ?? null),
                location: $h['Location'] ?? null,
                description: $h['TrackingNarration'] ?? null,
            );
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));
        $latest = $events ? $events[count($events) - 1] : null;

        $status = $latest?->status;
        $paid = $status === CourierStatus::DELIVERED && ! empty($detail['PaymentID']);

        return new TrackingResult(
            status: $status,
            rawStatus: $latest?->rawStatus,
            events: $events,
            deliveredAt: $status === CourierStatus::DELIVERED ? $latest->at : null,
            codStatus: $paid ? 'remitted' : null,
            codReference: $paid ? (string) (($detail['InstrumentNumber'] ?? null) ?: $detail['PaymentID']) : null,
            codRemittedAt: $paid ? $this->time($detail['PaymentDate'] ?? null) : null,
        );
    }

    /** Names only; the name is the code. */
    public function cities(): array
    {
        $res = $this->http()->get(self::BASE . 'Branches/Get_Cities', $this->query('username', 'password') + ['AccountNo' => $this->account->credential('account_no')]);
        $names = $this->first($res)['City'] ?? [];
        if (! $res->successful() || ! is_array($names) || (count($names) === 1 && str_contains((string) $names[0], 'Object reference'))) {
            throw new CourierException('M&P: could not load cities (check the credentials).');
        }

        return collect($names)->filter(fn ($n) => is_string($n) && trim($n) !== '')
            ->map(fn ($n) => ['code' => trim($n), 'name' => trim($n)])->values()->all();
    }

    // ------------------------------------------------------------------

    /** M&P wraps most answers in a one-element array. */
    private function first(Response $res): array
    {
        $json = $res->json();
        if (is_array($json) && array_is_list($json)) {
            return is_array($json[0] ?? null) ? $json[0] : [];
        }

        return is_array($json) ? $json : [];
    }

    /** isSuccess arrives as "true"/"false" or a boolean. */
    private function truthy($v): bool
    {
        return $v === true || (is_string($v) && strtolower($v) === 'true');
    }

    private function query(string $userKey, string $passKey): array
    {
        return [$userKey => $this->account->credential('username'), $passKey => $this->account->credential('password')];
    }

    private function time(?string $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
