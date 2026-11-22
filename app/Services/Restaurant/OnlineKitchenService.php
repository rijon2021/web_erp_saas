<?php

namespace App\Services\Restaurant;

use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RestaurantDishDetail;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\RestaurantSetting;
use App\Support\RestaurantModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Online-store orders on the kitchen display (Restaurant Settings › "Online
 * orders to the kitchen"):
 *   on_place   — as soon as the order is placed, when it is already paid or is
 *                paid on delivery / at pickup (an unfinished card redirect waits);
 *   on_accept  — when the order is accepted (Online Orders › Confirm);
 *   off        — never.
 * Every online order that reaches the kitchen becomes a takeaway (pickup) or
 * delivery restaurant order, already paid through the store, with one ticket.
 * Cancelling the online order voids what the kitchen has not served.
 *
 * Never breaks the store: failures are logged and skipped.
 */
class OnlineKitchenService
{
    public const PAY_LATER = ['cod', 'cash_on_pickup'];

    public function __construct(private OrderService $orders)
    {
    }

    public function placed(OnlineOrder $o): ?RestaurantOrder
    {
        if (! $this->ready() || RestaurantSetting::current()->kitchen_online_orders !== 'on_place') {
            return null;
        }
        // Not paid yet: only a signed-in customer's cash-on-delivery / pickup
        // order goes straight in; a guest's waits for staff to accept it (no
        // anonymous orders cooked unseen).
        if (($o->payment_status ?? '') !== 'paid' && (! in_array($o->payment_method, self::PAY_LATER, true) || ! empty($o->is_guest))) {
            return null;
        }

        return $this->push($o);
    }

    /** A payment gateway just marked the order paid (query-builder update, no model event). */
    public function placedById(int $id): ?RestaurantOrder
    {
        $o = OnlineOrder::find($id);

        return $o && $o->status === 'pending' ? $this->placed($o) : null;
    }

    public function confirmed(OnlineOrder $o): ?RestaurantOrder
    {
        if (! $this->ready() || RestaurantSetting::current()->kitchen_online_orders === 'off') {
            return null;
        }

        return $this->push($o);
    }

    public function cancelled(OnlineOrder $o): void
    {
        if (! $this->ready()) {
            return;
        }
        try {
            DB::transaction(function () use ($o) {
                $r = RestaurantOrder::where('online_order_id', $o->id)->lockForUpdate()->first();
                if (! $r || $r->status === 'cancelled') {
                    return;
                }
                $tickets = RestaurantOrderItem::where('order_id', $r->id)->whereNotIn('status', ['served', 'void'])->pluck('ticket_id')->filter()->unique();
                RestaurantOrderItem::where('order_id', $r->id)->whereNotIn('status', ['served', 'void'])->update([
                    'status' => 'void', 'voided_at' => now(), 'void_reason' => 'Online order cancelled', 'updated_at' => now(),
                ]);
                $r->update(['status' => 'cancelled', 'cancel_reason' => 'Online order '.$o->ref.' cancelled', 'closed_at' => now()]);
                // A ticket with nothing left to cook is void (or served, if part of it went out).
                foreach ($tickets as $tid) {
                    $left = RestaurantOrderItem::where('ticket_id', $tid)->where('status', '!=', 'void')->count();
                    \App\Models\RestaurantKitchenTicket::whereKey($tid)->update(['status' => $left ? 'served' : 'void', 'updated_at' => now()]);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Restaurant: could not cancel the kitchen order of online order '.$o->id.': '.$e->getMessage());
        }
    }

    /** The restaurant order (with its kitchen ticket) for an online order; created once. */
    private function push(OnlineOrder $o): ?RestaurantOrder
    {
        try {
            return DB::transaction(function () use ($o) {
                // Lock the online order so a webhook and an admin "Confirm"
                // arriving together cannot both create a kitchen order.
                OnlineOrder::whereKey($o->id)->lockForUpdate()->first();
                if ($existing = RestaurantOrder::where('online_order_id', $o->id)->first()) {
                    return $existing;
                }

                return $this->create($o);
            });
        } catch (\Throwable $e) {
            Log::warning('Restaurant: online order '.$o->id.' could not reach the kitchen: '.$e->getMessage());

            return null;
        }
    }

    private function create(OnlineOrder $o): ?RestaurantOrder
    {
        $o->loadMissing('items');
        if ($o->items->isEmpty()) {
            return null;
        }
        $products = Product::whereIn('id', $o->items->pluck('product_id'))->get(['id', 'name'])->keyBy('id');
        $variants = ProductVariant::whereIn('id', $o->items->pluck('product_variant_id')->filter())->get(['id', 'name'])->keyBy('id');
        $stations = RestaurantDishDetail::whereIn('product_id', $o->items->pluck('product_id'))->pluck('station_id', 'product_id');

        $lines = $o->items->map(function ($it) use ($products, $variants, $stations) {
            $options = is_array($it->options ?? null) ? $it->options : [];
            $extra = round(collect($options)->sum(fn ($x) => (float) ($x['price'] ?? 0)), 2);
            $name = optional($products[$it->product_id] ?? null)->name ?? ('#'.$it->product_id);
            if ($it->product_variant_id && isset($variants[$it->product_variant_id])) {
                $name .= ' ('.$variants[$it->product_variant_id]->name.')';
            }

            return [
                'product_id' => (int) $it->product_id,
                'product_variant_id' => $it->product_variant_id ? (int) $it->product_variant_id : null,
                'name' => $name,
                'qty' => (float) $it->qty,
                'unit_price' => round(max(0, (float) $it->price - $extra), 2),
                'addons_price' => $extra,
                'addons' => $options,
                'addons_label' => $it->options_label ?? null,
                'station_id' => $stations[$it->product_id] ?? null,
            ];
        })->values()->all();

        $pickup = ($o->delivery_method ?? 'ship') === 'pickup';
        $address = trim(implode(', ', array_filter([$o->shipping_address, $o->shipping_city, $o->shipping_state, $o->shipping_zip])));

        return $this->orders->createSent([
            'type' => $pickup ? 'takeaway' : 'delivery',
            'source' => 'online',
            'online_order_id' => $o->id,
            'client_id' => $o->client_id,
            'customer_name' => $o->customer_name ? mb_substr($o->customer_name, 0, 120) : null,
            'customer_phone' => $o->customer_phone ? mb_substr($o->customer_phone, 0, 40) : null,
            'delivery_address' => $pickup ? null : ($address !== '' ? mb_substr($address, 0, 1000) : null),
            'note' => mb_substr('Online order '.$o->ref.(($o->payment_status ?? '') === 'paid' ? ' · paid' : ' · pay on '.($pickup ? 'pickup' : 'delivery')), 0, 500),
            'warehouse_id' => $o->warehouse_id ?: null,
        ], $lines);
    }

    private function ready(): bool
    {
        return RestaurantModule::menuReady() && RestaurantOrder::tablesReady() && Schema::hasColumn('rst_orders', 'online_order_id');
    }
}
