<?php

namespace App\Services\Restaurant;

use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\RestaurantSetting;
use App\Models\RestaurantTable;
use App\Support\RestaurantModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ordering from the table (phase 8): each table has a secret QR link
 * (rst_tables.qr_token). Guests open the menu on their phone and their
 * dishes go on the table's open order — straight to the kitchen when
 * Restaurant Settings › "Send QR orders straight to the kitchen" is on,
 * otherwise as "new" lines the waiter checks and sends. Paying stays with
 * the staff (Bill & pay); guests can only ask for the bill.
 */
class QrOrderingService
{
    public const MAX_LINES = 30;

    public const MAX_QTY = 20;

    public function __construct(private OrderService $orders, private FloorService $floor)
    {
    }

    public function enabled(): bool
    {
        return RestaurantModule::menuReady() && RestaurantOrder::tablesReady()
            && \Illuminate\Support\Facades\Schema::hasColumn('rst_tables', 'qr_token')
            && (bool) (RestaurantSetting::current()->qr_ordering_enabled ?? false);
    }

    /** The table a QR link points at (the main table when it is joined to another). */
    public function table(string $token): RestaurantTable
    {
        if (! $this->enabled() || ! preg_match('/^[A-Za-z0-9]{20,40}$/', $token)) {
            throw new OrderException(__('This table link is not active. Please ask a member of staff.'), 404);
        }
        $t = RestaurantTable::where('qr_token', $token)->where('is_active', true)->first();
        if (! $t) {
            throw new OrderException(__('This table link is not active. Please ask a member of staff.'), 404);
        }

        return $t->merged_into_id ? (RestaurantTable::find($t->merged_into_id) ?? $t) : $t;
    }

    /** What the guest's phone shows: the table and the dishes on its open order. */
    public function state(RestaurantTable $t): array
    {
        $order = $this->orders->openOrderOf($t->id);
        $items = $order ? RestaurantOrderItem::where('order_id', $order->id)->where('status', '!=', 'void')->orderBy('id')->get() : collect();

        return [
            'table' => ['name' => $t->name, 'status' => $t->status],
            'needs_guests' => ! in_array($t->status, ['occupied', 'bill'], true) && (bool) RestaurantSetting::current()->require_guest_count,
            'can_order' => $t->status !== 'cleaning',
            'bill_requested' => $t->status === 'bill',
            'auto_send' => (bool) RestaurantSetting::current()->qr_auto_send,
            'order' => $order ? [
                'ref' => $order->ref,
                'subtotal' => (float) $order->subtotal,
                'items' => $items->map(fn ($i) => [
                    'name' => $i->name, 'qty' => (float) $i->qty, 'addons_label' => $i->addons_label, 'note' => $i->note,
                    'line_total' => (float) $i->line_total, 'status' => $i->status,
                ])->values(),
            ] : null,
        ];
    }

    /**
     * Put the guest's dishes on the table's order (seating the table first
     * when nobody is checked in there yet).
     *
     * @param  array  $lines  [{product_id, variant_id?, qty, option_ids?, note?}]
     */
    public function order(RestaurantTable $t, array $lines, ?int $guests): RestaurantOrder
    {
        if (! $lines) {
            throw new OrderException(__('Add at least one dish.'));
        }
        if (count($lines) > self::MAX_LINES) {
            throw new OrderException(__('Please send at most :n dishes at a time.', ['n' => self::MAX_LINES]));
        }
        foreach ($lines as $l) {
            $q = (float) ($l['qty'] ?? 1);
            if ($q < 1 || $q > self::MAX_QTY) {
                throw new OrderException(__('Enter a quantity between 1 and :n.', ['n' => self::MAX_QTY]));
            }
        }
        if ($t->status === 'cleaning') {
            throw new OrderException(__('This table is being prepared. Please ask a member of staff.'));
        }

        return DB::transaction(function () use ($t, $lines, $guests) {
            $order = $this->orders->openForTable($t->id, $guests ? max(1, min(50, $guests)) : null, null);
            if (($order->source ?? 'staff') === 'staff' && ! RestaurantOrderItem::where('order_id', $order->id)->exists()) {
                $order->update(['source' => 'qr']);
            }
            $clean = array_map(fn ($l) => [
                'product_id' => (int) ($l['product_id'] ?? 0),
                'variant_id' => ! empty($l['variant_id']) ? (int) $l['variant_id'] : null,
                'qty' => (float) ($l['qty'] ?? 1),
                'option_ids' => array_values(array_map('intval', (array) ($l['option_ids'] ?? []))),
                'note' => isset($l['note']) ? mb_substr(trim((string) $l['note']), 0, 255) : null,
            ], $lines);
            // Only what the online store sells can be ordered from a phone.
            $allowed = \App\Services\CheckoutService::sellableProducts()->whereIn('id', array_column($clean, 'product_id'))->pluck('id')->all();
            foreach ($clean as $l) {
                if (! in_array($l['product_id'], $allowed, true)) {
                    throw new OrderException(__('This dish is no longer on the menu.'));
                }
            }
            $order = $this->orders->addItems($order->id, $clean, null);
            if (RestaurantSetting::current()->qr_auto_send) {
                $this->orders->send($order->id, null);
            }

            return $order->fresh();
        });
    }

    /** Guests asked for the bill (the floor plan shows the table as "Bill requested"). */
    public function requestBill(RestaurantTable $t): RestaurantTable
    {
        if ($t->status === 'bill') {
            return $t;
        }
        if ($t->status !== 'occupied') {
            throw new OrderException(__('Nothing has been ordered at this table yet.'));
        }

        return $this->floor->setBillRequested($t->id, true);
    }

    /** The table's QR token, created on first use (or replaced, which retires printed codes). */
    public function token(RestaurantTable $t, bool $renew = false): string
    {
        if ($t->qr_token && ! $renew) {
            return $t->qr_token;
        }
        do {
            $token = Str::random(32);
        } while (RestaurantTable::withTrashed()->where('qr_token', $token)->exists());
        RestaurantTable::whereKey($t->id)->update(['qr_token' => $token]);
        $t->qr_token = $token;

        return $token;
    }

    public static function url(string $token): string
    {
        return store_base_url('table/'.$token);
    }
}
