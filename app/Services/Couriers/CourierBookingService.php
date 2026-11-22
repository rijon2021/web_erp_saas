<?php

namespace App\Services\Couriers;

use App\Models\CourierAccount;
use App\Models\CourierCityMapping;
use App\Models\OnlineOrder;
use App\Models\Sale;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Couriers\Data\ShipmentRequest;
use App\Services\Couriers\Data\TrackingEvent;
use App\Services\Shipping\ParcelEstimator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sending a sale to a courier: the pre-filled booking form, the courier's
 * city list, booking, and cancelling.
 */
class CourierBookingService
{
    public function __construct(private ShipmentUpdater $updater)
    {
    }

    /** Active courier shipment of a sale (a cancelled one does not count). */
    public function activeShipment(Sale $sale): ?Shipment
    {
        // Cancelled and returned parcels are over: the sale can be sent again
        // (a restocked return is refused separately in book()).
        return Shipment::query()
            ->where('sale_id', $sale->id)
            ->whereNull('deleted_at')
            ->whereNotNull('courier_account_id')
            ->where(fn ($q) => $q->whereNull('courier_status')->orWhereNotIn('courier_status', [CourierStatus::CANCELLED, CourierStatus::RETURNED]))
            ->latest('id')
            ->first();
    }

    /** The sale's latest returned courier parcel, if its last booking came back. */
    public function returnedShipment(Sale $sale): ?Shipment
    {
        $latest = Shipment::query()->where('sale_id', $sale->id)->whereNull('deleted_at')
            ->whereNotNull('courier_account_id')->latest('id')->first();

        return $latest && $latest->courier_status === CourierStatus::RETURNED ? $latest : null;
    }

    /** The account a sale goes to by default: its warehouse's, else the default one. */
    public function suggestedAccount(Sale $sale): ?CourierAccount
    {
        $active = CourierAccount::query()->where('active', true);

        // A web order priced with live carrier rates goes to the account that quoted it.
        $quotedBy = (int) ($this->checkoutQuote($sale)['courier_account_id'] ?? 0);
        if ($quotedBy && ($quoted = (clone $active)->find($quotedBy))) {
            return $quoted;
        }

        return (clone $active)->where('warehouse_id', $sale->warehouse_id)->orderByDesc('is_default')->first()
            ?: (clone $active)->where('is_default', true)->first()
            ?: (clone $active)->orderBy('id')->first();
    }

    /**
     * Pre-filled booking form. Address priority: the sale's existing shipment
     * row (staff may have typed it), the online order's shipping address, then
     * the customer profile.
     */
    public function defaults(Sale $sale, ?CourierAccount $account = null): array
    {
        $sale->loadMissing(['client', 'details.product']);
        $client = $sale->client;
        $order = OnlineOrder::query()->where('sale_id', $sale->id)->first();
        $legacy = Shipment::query()->where('sale_id', $sale->id)->whereNull('deleted_at')->latest('id')->first();

        $name = $order?->customer_name ?: ($client?->name ?: trim(($client?->firstname ?? '') . ' ' . ($client?->lastname ?? '')));
        $address = $order?->shipping_address ?: ($client?->adresse ?? '');
        $city = $order?->shipping_city ?: ($client?->city ?? '');
        if ($legacy && $legacy->shipping_address && ! $legacy->courier_account_id) {
            $address = $legacy->shipping_address;
        }

        $units = [];
        $items = [];
        $quantity = 0.0;
        foreach ($sale->details as $d) {
            $quantity += (float) $d->quantity;
            $units[] = [
                'product' => $d->product,
                'units' => (float) $d->quantity * ((float) ($d->pack_multiplier ?? 0) > 0 ? (float) $d->pack_multiplier : 1),
            ];
            $items[] = trim(($d->product?->name ?? 'Item') . ' x' . rtrim(rtrim(number_format((float) $d->quantity, 2, '.', ''), '0'), '.'));
        }
        // The same parcel checkout quoted live rates for (weight, stacked size).
        $parcel = ParcelEstimator::estimate($units, $account);

        // The carrier service the customer paid for at checkout, when this account quoted it.
        $quote = $order ? $this->checkoutQuote($sale, $order) : null;
        $service = $quote && $account && (int) ($quote['courier_account_id'] ?? 0) === (int) $account->id && ! empty($quote['service_token'])
            ? (string) $quote['service_token']
            : $account?->default_service;

        $due = max(0, round((float) $sale->GrandTotal - (float) $sale->paid_amount, 2));
        $mapping = $account ? CourierCityMapping::lookup($account->courier, $city) : null;

        return [
            'consignee_name' => $legacy?->delivered_to && ! $legacy->courier_account_id ? $legacy->delivered_to : $name,
            'consignee_phone' => $order?->customer_phone ?: ($client?->phone ?? ''),
            'consignee_email' => $order?->customer_email ?: ($client?->email ?? null),
            'address' => $address,
            'city' => $city,
            'courier_city_code' => $mapping?->courier_city_code,
            'courier_city_name' => $mapping?->courier_city_name,
            'state' => $order?->shipping_state ?: ($client?->state ?? null),
            'country' => $order?->shipping_country ?: ($client?->country ?? null),
            'zip' => $order?->shipping_zip ?: ($client?->zip ?? null),
            // Couriers without COD ship prepaid; start the form at 0 for them.
            'cod_amount' => $account && ! (CourierRegistry::meta($account->courier)['capabilities']['cod'] ?? false) ? 0 : $due,
            'declared_value' => round((float) $sale->GrandTotal, 2),
            'weight' => $parcel['weight'],
            'parcel_length' => $parcel['sized'] ? $parcel['length'] : null,
            'parcel_width' => $parcel['sized'] ? $parcel['width'] : null,
            'parcel_height' => $parcel['sized'] ? $parcel['height'] : null,
            // Couriers whose "pieces" is the number of items (PostEx "Items") start at the
            // sale's total quantity; for the rest it is the parcel count, so 1.
            'pieces' => $account && (CourierRegistry::meta($account->courier)['capabilities']['pieces_are_items'] ?? false)
                ? max(1, (int) ceil(round($quantity, 3)))
                : 1,
            'items_description' => mb_substr(implode(', ', $items), 0, 250),
            'notes' => '',
            'service' => $service,
            'allow_open_parcel' => (bool) ($account?->allow_open_parcel ?? false),
            'tracking_number' => null,
        ];
    }

    /** The live carrier rate a web order was sold with (online_orders.shipping_quote), if any. */
    private function checkoutQuote(Sale $sale, ?OnlineOrder $order = null): ?array
    {
        $order ??= OnlineOrder::query()->where('sale_id', $sale->id)->first();
        $quote = $order?->shipping_quote;

        return is_array($quote) ? $quote : null;
    }

    /** Courier cities matching $search (cached per account for 12 hours). */
    public function cities(CourierAccount $account, ?string $search = null, int $limit = 50): array
    {
        $driver = CourierRegistry::make($account);
        if (! (CourierRegistry::meta($account->courier)['capabilities']['cities'] ?? false)) {
            return [];
        }

        $key = 'courier_cities:' . (tenant('id') ?? 'central') . ':' . $account->id . ':' . $account->mode;
        $all = Cache::get($key);
        if (! is_array($all) || ! $all) {
            $all = CourierLogger::during($account, 'cities', null, fn () => $driver->cities());
            // An empty list is never cached: a courier hiccup must not blank the picker for 12 hours.
            if ($all) {
                Cache::put($key, $all, now()->addHours(12));
            }
        }

        $needle = CourierCityMapping::normalize($search);
        if ($needle === '') {
            return array_slice($all, 0, $limit);
        }

        $exact = $starts = $contains = [];
        foreach ($all as $c) {
            $n = CourierCityMapping::normalize($c['name'] ?? '');
            if ($n === $needle) {
                $exact[] = $c;
            } elseif (str_starts_with($n, $needle)) {
                $starts[] = $c;
            } elseif (str_contains($n, $needle)) {
                $contains[] = $c;
            }
        }

        return array_slice(array_merge($exact, $starts, $contains), 0, $limit);
    }

    /**
     * Book $sale with $account. $input = the booking form (see defaults()).
     * The courier call happens first; the shipment row is written after it
     * succeeds, so a rejected booking leaves Stocky untouched.
     */
    public function book(Sale $sale, CourierAccount $account, array $input, User $user): Shipment
    {
        // A double click or two staff on the same sale must not book the parcel twice at the courier.
        $lock = Cache::lock('courier_book:' . (tenant('id') ?? 'central') . ':' . $sale->id, 120);
        if (! $lock->get()) {
            throw new CourierException("{$sale->Ref} is being sent to a courier right now. Wait a moment, then refresh.");
        }
        try {
            return $this->bookLocked($sale->fresh() ?? $sale, $account, $input, $user);
        } finally {
            $lock->release();
        }
    }

    private function bookLocked(Sale $sale, CourierAccount $account, array $input, User $user): Shipment
    {
        if (! $account->active) {
            throw new CourierException("The courier account \"{$account->name}\" is inactive.");
        }
        if ($existing = $this->activeShipment($sale)) {
            throw new CourierException("{$sale->Ref} is already booked" . ($existing->tracking_number ? " (tracking {$existing->tracking_number})" : '') . '. Cancel that booking first.');
        }
        // Re-sending a parcel that came back is fine — unless its goods were restocked
        // with a sale return: then they are back in stock and belong to a new sale.
        $returned = $this->returnedShipment($sale);
        if ($returned && $returned->return_status === 'restocked') {
            throw new CourierException("The returned parcel of {$sale->Ref} was restocked with a sale return. Create a new sale to ship the goods again.");
        }

        $meta = CourierRegistry::meta($account->courier);
        if (! ($meta['capabilities']['booking'] ?? false)) {
            throw CourierException::unsupported($meta['name'] ?? $account->courier, 'booking');
        }

        $driver = CourierRegistry::make($account);
        // The merchant's own order number (e.g. CS-Cart) when entered on the sale, else the Stocky Ref.
        $request = ShipmentRequest::fromArray(['reference' => trim((string) $sale->order_reference) ?: $sale->Ref] + $input);
        // Couriers without COD would ship it prepaid while Stocky waits for cash that never comes.
        if ($request->codAmount > 0 && ! ($meta['capabilities']['cod'] ?? false)) {
            throw new CourierException(($meta['name'] ?? $account->courier) . ' does not collect cash on delivery: set the COD amount to 0 (prepaid).');
        }
        $result = CourierLogger::during($account, 'book', null, fn () => $driver->book($request));

        // Remember a city match the user picked, for the next order to the same city.
        // Never let this convenience lose a booking the courier already has.
        if ($request->courierCityName && $request->city !== '') {
            try {
                CourierCityMapping::remember($account->courier, $request->city, $request->courierCityCode, $request->courierCityName);
            } catch (\Throwable $e) {
                Log::info('Courier city mapping not saved: ' . $e->getMessage());
            }
        }

        try {
            $shipment = DB::transaction(function () use ($sale, $account, $request, $result, $user, $returned) {
                // Reuse the sale's hand-made shipment (keeps its Ref) or a cancelled booking;
                // a returned parcel keeps its own row (return history), so a re-send is new.
                $latest = Shipment::query()->where('sale_id', $sale->id)->whereNull('deleted_at')->latest('id')->first();
                $reusable = $latest && (! $latest->courier_account_id || $latest->courier_status === CourierStatus::CANCELLED);
                $shipment = $reusable ? $latest
                    : new Shipment(['Ref' => app(\App\Http\Controllers\ShipmentController::class)->getNumberOrder(), 'date' => now()]);

                $shipment->fill([
                    'user_id' => $user->id,
                    'sale_id' => $sale->id,
                    'delivered_to' => $request->consigneeName,
                    'shipping_address' => trim($request->address . ($request->city !== '' ? ', ' . $request->city : '')),
                    'shipping_details' => $request->notes ?: $shipment->shipping_details,
                    'status' => CourierStatus::legacy($result->status),
                    'courier_account_id' => $account->id,
                    'courier' => $account->courier,
                    'tracking_number' => $result->trackingNumber,
                    'courier_reference' => $result->courierReference,
                    'courier_status' => $result->status,
                    'courier_status_raw' => $result->rawStatus,
                    'destination_city' => $request->courierCityName ?: $request->city,
                    'weight' => $request->weight,
                    'pieces' => $request->pieces,
                    'cod_amount' => $request->codAmount,
                    'cod_status' => $request->codAmount > 0 ? 'pending' : 'none',
                    'cod_remitted_at' => null,
                    'cod_reference' => null,
                    'shipping_charges' => $result->charges,
                    'label_url' => $result->labelUrl,
                    'booked_at' => now(),
                    'delivered_at' => null,
                    'returned_at' => null,
                    'last_synced_at' => null,
                    // Booking response + what the label prints that has no column.
                    'courier_meta' => [
                        'booking' => $result->meta,
                        'service' => $request->service,
                        'consignee' => [
                            'phone' => $request->consigneePhone,
                            'email' => $request->consigneeEmail,
                            'zip' => $request->zip,
                            'country' => $request->country,
                        ],
                        'items' => $request->itemsDescription,
                        'notes' => $request->notes,
                        'allow_open_parcel' => $request->allowOpenParcel,
                    ],
                    'return_status' => null,
                    'return_note' => null,
                    'return_handled_at' => null,
                    'return_handled_by' => null,
                    'sale_return_id' => null,
                    'cod_payment_id' => null,
                    'charges_expense_id' => null,
                    'sync_error' => null,
                    'sync_failures' => 0,
                ]);
                if (\App\Models\CourierSetting::hasPhase7Columns()) {
                    $shipment->notified_statuses = null; // a re-booking notifies afresh
                }
                $shipment->save();

                $this->updater->recordEvents($shipment, [
                    new TrackingEvent($result->status, $result->rawStatus ?? 'Booked', now(), null,
                        'Booked with ' . $account->name, ['by' => $user->id]),
                ], 'booking');

                $sale->update(['shipping_status' => CourierStatus::legacy($result->status)]);

                // The parcel that came back is on its way again: it leaves the returns queue.
                if ($returned && $returned->id !== $shipment->id && $returned->return_status === 'pending') {
                    $returned->forceFill([
                        'return_status' => 'resent',
                        'return_note' => mb_substr('Sent again: ' . $result->trackingNumber, 0, 500),
                        'return_handled_at' => now(),
                        'return_handled_by' => $user->id,
                    ])->save();
                }

                return $shipment;
            });
        } catch (\Throwable $e) {
            // The courier has the booking but Stocky could not save it: say so
            // loudly, with the tracking number, so nobody books it twice.
            Log::critical('Courier booking saved at courier but not in Stocky', [
                'sale_id' => $sale->id, 'account_id' => $account->id, 'tracking' => $result->trackingNumber, 'error' => $e->getMessage(),
            ]);
            throw new CourierException("Booked at the courier (tracking {$result->trackingNumber}) but saving it failed: {$e->getMessage()}");
        }

        // A new booking starts as "booked" without a status change, so tell the customer here.
        try {
            if ($result->status) {
                app(CourierNotifier::class)->statusChanged($shipment, $result->status);
            }
        } catch (\Throwable $e) {
            Log::warning('Courier customer notification failed: ' . $e->getMessage(), ['shipment_id' => $shipment->id]);
        }

        return $shipment;
    }

    /** Cancel at the courier (when supported), then in Stocky. */
    public function cancel(Shipment $shipment, User $user, ?string $note = null): Shipment
    {
        if (CourierStatus::isFinal($shipment->courier_status)) {
            throw new CourierException('A delivered, returned or cancelled shipment cannot be cancelled.');
        }
        if (! in_array($shipment->courier_status, [null, CourierStatus::BOOKED], true)) {
            throw new CourierException('The courier already has this parcel; it can only be returned now.');
        }

        $account = $shipment->courierAccount;
        if ($account && ! $account->trashed()) {
            $meta = CourierRegistry::meta($account->courier);
            if ($meta['capabilities']['cancel'] ?? false) {
                CourierLogger::during($account, 'cancel', $shipment->id, fn () => CourierRegistry::make($account)->cancel($shipment));
            }
        }

        $this->updater->setStatusManually($shipment, CourierStatus::CANCELLED, $note ?: 'Booking cancelled', $user);

        return $shipment->fresh();
    }
}
