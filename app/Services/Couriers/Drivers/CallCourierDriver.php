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

/**
 * Call Courier (Pakistan; owned by PostEx since 2022) — the legacy COD API
 * from the official "API Configuration" document, checked against the live
 * server. Base https://cod.callcourier.com.pk/api/CallCourier/, all GET with
 * query strings; the portal loginId is the only credential. Destinations and
 * the shipper city/area are IDs. Errors come back as Response "false, …".
 * No label, COD settlement or webhook endpoints: Stocky prints the label and
 * status is polled (GetTackingHistory — the API's own spelling).
 */
class CallCourierDriver extends AbstractCourierDriver
{
    private const BASE = 'https://cod.callcourier.com.pk/api/CallCourier/';

    public static function meta(): array
    {
        return [
            'code' => 'callcourier',
            'name' => 'Call Courier',
            'region' => 'Pakistan',
            'countries' => ['PK'],
            'website' => 'https://callcourier.com.pk',
            'tracking_url' => null,
            'description' => 'COD booking, tracking and cancellation through the Call Courier COD API.',
            'capabilities' => [
                'booking' => true,
                'cancel' => true,
                'tracking' => true,
                'cod' => true,
                'cities' => true,
                'pickup' => true,
            ],
            'credentials' => [
                ['key' => 'login_id', 'label' => 'Login ID', 'type' => 'password', 'required' => true,
                    'help' => 'Your Call Courier portal login ID.'],
                ['key' => 'account_code_id', 'label' => 'Account code ID', 'type' => 'text',
                    'help' => 'Optional, from your Call Courier account manager.'],
            ],
            'settings' => [
                ['key' => 'origin', 'label' => 'Origin branch', 'type' => 'text', 'required' => true,
                    'help' => 'Call Courier sale branch, e.g. Lahore. "Test connection" lists the branches allowed for your login.'],
                ['key' => 'shipper_city_id', 'label' => 'Shipper city ID', 'type' => 'number', 'required' => true],
                ['key' => 'shipper_area_id', 'label' => 'Shipper area ID', 'type' => 'number', 'required' => true],
                ['key' => 'shipper_email', 'label' => 'Shipper email', 'type' => 'text', 'required' => true],
                ['key' => 'shipper_landline', 'label' => 'Shipper other phone', 'type' => 'text'],
                ['key' => 'service_type_id', 'label' => 'Service type ID', 'type' => 'number',
                    'help' => 'Leave empty for 7 (COD). 26 = same-day COD.'],
            ],
        ];
    }

    public function testConnection(): ConnectionResult
    {
        if ($missing = $this->missingCredentials()) {
            return ConnectionResult::failed('Missing: ' . implode(', ', $missing));
        }

        $res = $this->http()->get(self::BASE . 'GetOriginListByShipper', ['LoginId' => $this->account->credential('login_id')]);
        $origins = $res->json();
        if (! $res->successful() || ! is_array($origins)) {
            return ConnectionResult::failed('Call Courier: login ID not recognised.');
        }

        $names = collect($origins)->map(fn ($o) => is_array($o) ? ($o['CityName'] ?? $o['BranchName'] ?? $o['OriginName'] ?? null) : $o)
            ->filter()->implode(', ');

        return ConnectionResult::ok('Connected to Call Courier.' . ($names ? " Origin branches: {$names}." : ''));
    }

    public function book(ShipmentRequest $request): BookingResult
    {
        $acc = $this->account;
        $destination = $request->courierCityCode ?: $this->cityIdByName($request->courierCityName ?: $request->city);
        if (! $destination) {
            throw new CourierException('Choose the Call Courier city for "' . $request->city . '".');
        }
        // The service booked: a picked one, else the account default (7 = COD, 26 = same-day COD).
        $serviceType = (int) ($request->service && is_numeric($request->service) ? $request->service : ($acc->setting('service_type_id') ?: 7));
        if ($request->codAmount <= 0 && in_array($serviceType, [7, 26], true)) {
            throw new CourierException('Call Courier COD service needs a COD amount above 0.');
        }

        $query = [
            'loginId' => $acc->credential('login_id'),
            'ConsigneeName' => $request->consigneeName,
            'ConsigneeRefNo' => $request->reference,
            'ConsigneeCellNo' => Phone::pk($request->consigneePhone),
            'Address' => $request->address,
            'Origin' => (string) $acc->setting('origin'),
            'DestCityId' => (string) $destination,
            'ServiceTypeId' => (string) $serviceType,
            'Pcs' => (string) $request->pieces,
            'Weight' => (string) round($request->weight, 2),
            'Description' => $request->itemsDescription,
            'SelOrigin' => 'Domestic',
            'CodAmount' => (string) round($request->codAmount),
            'SpecialHandling' => 'false',
            'MyBoxId' => '1',
            'Holiday' => 'false',
            'remarks' => $request->notes,
            'ShipperName' => $acc->pickup_contact ?: $acc->name,
            'ShipperCellNo' => Phone::pk($acc->pickup_phone),
            'ShipperArea' => (string) $acc->setting('shipper_area_id'),
            'ShipperCity' => (string) $acc->setting('shipper_city_id'),
            'ShipperAddress' => (string) $acc->pickup_address,
            'ShipperLandLineNo' => (string) ($acc->setting('shipper_landline') ?: Phone::pk($acc->pickup_phone)),
            'ShipperEmail' => (string) $acc->setting('shipper_email'),
        ];

        $res = $this->http()->get(self::BASE . 'SaveBooking', $query);
        $cn = $res->json('CNNO');
        $message = (string) $res->json('Response');
        if (! $res->successful() || ! $cn || str_starts_with(strtolower($message), 'false')) {
            throw new CourierException('Call Courier: ' . ($this->clean($message) ?: ('HTTP ' . $res->status())));
        }

        return new BookingResult(trackingNumber: (string) $cn, status: CourierStatus::BOOKED, rawStatus: 'Booked',
            charges: is_numeric($res->json('NetAmount')) ? (float) $res->json('NetAmount') : null);
    }

    public function cancel(Shipment $shipment): void
    {
        $res = $this->http()->get(self::BASE . 'CancelBooking', array_filter([
            'LoginId' => $this->account->credential('login_id'),
            'CNNo' => (string) $shipment->tracking_number,
            'AccCodeId' => $this->account->credential('account_code_id') ?: null,
        ]));
        $body = $res->json();
        $message = is_array($body) ? (string) ($body['Response'] ?? $body['response'] ?? $body['Message'] ?? '') : (string) $body;
        if (! $res->successful() || str_starts_with(strtolower($message), 'false')) {
            throw new CourierException('Call Courier: ' . ($this->clean($message) ?: ('HTTP ' . $res->status())));
        }
    }

    public function track(Shipment $shipment): TrackingResult
    {
        $res = $this->http()->get(self::BASE . 'GetTackingHistory', ['cn' => (string) $shipment->tracking_number]);
        $rows = $res->json();
        if (! $res->successful() || ! is_array($rows) || ! array_is_list($rows) || ! $rows) {
            throw new CourierException('Call Courier: ' . ((is_array($rows) ? ($rows['message'] ?? null) : null) ?: 'no tracking information'));
        }

        $events = [];
        foreach ($rows as $row) {
            $portal = $row['ProcessDescForPortal'] ?? null;
            $operation = $row['OperationDesc'] ?? null;
            $reason = trim((string) ($row['ReasonDesc'] ?? ''));
            $status = StatusText::map($portal) ?? StatusText::map($operation);
            // A delivery phase with a reason other than OK is a failed attempt.
            if ($operation === 'Dlvry Phase II' && $reason !== '' && strtoupper($reason) !== 'OK'
                && ! in_array($status, [CourierStatus::RETURNED, CourierStatus::RETURNING], true)) {
                $status = CourierStatus::FAILED_ATTEMPT;
            }
            $events[] = new TrackingEvent(
                status: $status,
                rawStatus: $portal ?: $operation,
                at: $this->time($row['TransactionDate'] ?? null),
                location: $row['DestBranch'] ?? $row['HomeBranch'] ?? null,
                description: trim(($reason && strtoupper($reason) !== 'OK' ? $reason : '') . (! empty($row['ReceiverName']) ? ' · ' . $row['ReceiverName'] : ''), ' ·') ?: null,
            );
        }
        usort($events, fn ($a, $b) => ($a->at?->timestamp ?? 0) <=> ($b->at?->timestamp ?? 0));
        $latest = $events[count($events) - 1];

        return new TrackingResult(
            status: $latest->status,
            rawStatus: $latest->rawStatus,
            events: $events,
            deliveredAt: $latest->status === CourierStatus::DELIVERED ? $latest->at : null,
        );
    }

    public function cities(): array
    {
        $service = (int) ($this->account->setting('service_type_id') ?: 7);
        $res = $this->http()->get(self::BASE . 'GetCityListByService', ['serviceID' => $service]);
        if (! $res->successful() || ! is_array($res->json())) {
            throw new CourierException('Call Courier: could not load cities.');
        }

        return collect($res->json())
            ->filter(fn ($c) => isset($c['CityID'], $c['CityName']))
            ->map(fn ($c) => ['code' => (string) $c['CityID'], 'name' => trim($c['CityName'])])
            ->unique('code')->values()->all();
    }

    // ------------------------------------------------------------------

    private function cityIdByName(?string $name): ?string
    {
        $needle = mb_strtolower(trim((string) $name));
        if ($needle === '') {
            return null;
        }
        foreach ($this->cities() as $c) {
            if (mb_strtolower($c['name']) === $needle) {
                return $c['code'];
            }
        }

        return null;
    }

    /** "false, loginId is wrong" → "loginId is wrong". */
    private function clean(string $message): string
    {
        return trim(preg_replace('/^false\s*,?\s*/i', '', $message));
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
