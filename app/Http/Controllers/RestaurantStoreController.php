<?php

namespace App\Http\Controllers;

use App\Services\Restaurant\FloorException;
use App\Services\Restaurant\MenuService;
use App\Services\Restaurant\OnlineBookingService;
use App\Services\Restaurant\OrderException;
use App\Services\Restaurant\QrOrderingService;
use App\Services\Restaurant\ReservationException;
use Illuminate\Http\Request;

/**
 * Restaurant pages of the online store (phase 8), all public:
 *   GET  /table/{token}              the table's QR menu (order from your phone)
 *   GET  /table/{token}/data         menu + what the table has ordered so far
 *   POST /table/{token}/order        send dishes to the table's order
 *   POST /table/{token}/bill         ask for the bill
 *   GET  /restaurant/booking-slots   free start times for a date and party size
 *   POST /restaurant/book            book a table
 * The Restaurant store theme's home page is home() (StoreFrontController::index).
 */
class RestaurantStoreController extends StoreFrontController
{
    public function __construct(private QrOrderingService $qr, private OnlineBookingService $booking, private MenuService $menu)
    {
    }

    /** Restaurant theme homepage: hero, the menu, online booking, about, visit us. */
    public function home(Request $request)
    {
        $s = \App\Models\StoreSetting::firstOrFail();
        $opts = \App\Support\RestaurantTheme::options($s);
        $menu = $this->menu->catalog(true);
        $dishes = $menu['dishes'];

        // Checkout always checks stock, so a dish with none left shows as sold
        // out (services, "always in stock", pre-order and fillable combos never do).
        if ($warehouseIds = $s->activeWarehouseIds()) {
            $inStock = array_flip(array_map('intval', $this->getInStockProductIds($warehouseIds)));
            $services = \App\Models\Product::whereIn('id', $dishes->pluck('id'))->where('type', 'is_service')->pluck('id')->flip();
            $dishes = $dishes->map(function ($d) use ($inStock, $services) {
                if (! isset($inStock[$d['id']]) && ! isset($services[$d['id']])) {
                    $d['sold_out'] = true;
                    $d['available'] = false;
                }

                return $d;
            });
        }
        $categories = $menu['categories']->filter(fn ($c) => $dishes->contains('category_id', $c->id))->values();
        $dietary = $dishes->pluck('dietary')->flatten()->unique()->values()->all();

        return view('store.restaurant.home', [
            's' => $s,
            'opts' => $opts,
            'sections' => $opts['sections'],
            'menuCategories' => $categories,
            'dishes' => $dishes->values(),
            'dietary' => $dietary,
            'booking' => $this->booking->enabled() ? $this->booking->rules() : null,
            'hours' => $this->hours($opts),
            'hidePrices' => ! \Illuminate\Support\Facades\Auth::guard('store')->check() && ($s->hide_prices_for_guests ?? false),
            'categories' => $this->storeCategories(),
            'showCategoryBar' => false,
        ]);
    }

    /** Opening hours, one line each, exactly as the owner typed them (nothing shown when blank). */
    private function hours(array $opts): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $opts['opening_hours']))));
    }

    public function table(Request $request, string $token)
    {
        try {
            $table = $this->qr->table($token);
        } catch (OrderException $e) {
            return response()->view('store.restaurant.table-closed', ['message' => $e->getMessage()], 404);
        }

        return view('store.restaurant.table', [
            'token' => $token,
            'tableName' => $table->name,
            'currency' => store_currency(),
            'decimals' => \App\utils\helpers::price_decimals(),
        ]);
    }

    public function tableData(Request $request, string $token)
    {
        return $this->run(function () use ($token) {
            $table = $this->qr->table($token);
            $menu = $this->menu->catalog(true);

            return $this->qr->state($table) + [
                'categories' => $menu['categories'],
                'dishes' => $menu['dishes']->map(fn ($d) => collect($d)->except(['code'])->all())->values(),
            ];
        });
    }

    public function tableOrder(Request $request, string $token)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:'.QrOrderingService::MAX_LINES,
            'items.*.product_id' => 'required|integer',
            'items.*.variant_id' => 'nullable|integer',
            'items.*.qty' => 'required|integer|min:1|max:'.QrOrderingService::MAX_QTY,
            'items.*.option_ids' => 'nullable|array|max:30',
            'items.*.option_ids.*' => 'integer',
            'items.*.note' => 'nullable|string|max:255',
            'guests' => 'nullable|integer|min:1|max:50',
        ]);

        return $this->run(function () use ($token, $data) {
            $table = $this->qr->table($token);
            $this->qr->order($table, $data['items'], $data['guests'] ?? null);

            return $this->qr->state($table->fresh());
        });
    }

    public function tableBill(Request $request, string $token)
    {
        return $this->run(function () use ($token) {
            $table = $this->qr->table($token);

            return $this->qr->state($this->qr->requestBill($table)->fresh());
        });
    }

    public function bookingSlots(Request $request)
    {
        $v = $request->validate(['date' => 'required|date_format:Y-m-d', 'party_size' => 'required|integer|min:1|max:500']);

        return $this->run(fn () => ['slots' => $this->booking->slots($v['date'], (int) $v['party_size']), 'rules' => $this->booking->rules()]);
    }

    public function book(Request $request)
    {
        // Honeypot: real visitors never fill the hidden "website" field.
        if (trim((string) $request->input('website', '')) !== '') {
            return response()->json(['ok' => true]);
        }
        $data = $request->validate([
            'customer_name' => 'required|string|max:120',
            'customer_phone' => 'required|string|max:40',
            'customer_email' => 'nullable|email|max:191',
            'party_size' => 'required|integer|min:1|max:500',
            'date' => 'required|date_format:Y-m-d',
            'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'note' => 'nullable|string|max:500',
        ]);

        // At most 10 online bookings per visitor a day, on top of the per-minute limit.
        $dayKey = 'rst-book-day:'.$request->ip();
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($dayKey, 10)) {
            return response()->json(['message' => __('Too many bookings from this connection today. Please call us.')], 429);
        }

        return $this->run(function () use ($data, $dayKey) {
            $r = $this->booking->book($data);
            \Illuminate\Support\Facades\RateLimiter::hit($dayKey, 86400);

            return ['ok' => true, 'booking' => [
                'id' => $r->id, 'name' => $r->customer_name, 'party_size' => $r->party_size,
                'date' => $r->starts_at->format('Y-m-d'), 'time' => $r->starts_at->format('H:i'),
            ]];
        });
    }

    private function run(callable $fn)
    {
        try {
            return response()->json($fn());
        } catch (OrderException|FloorException|ReservationException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        }
    }
}
