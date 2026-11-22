<?php

namespace App\Http\Controllers;

use App\Models\RentalItem;
use App\Models\RentalReservation;
use App\Models\StoreSetting;
use App\Services\Rental\RentalWebsiteException;
use App\Services\Rental\RentalWebsiteMailer;
use App\Services\Rental\RentalWebsiteService;
use App\Support\RentalTheme;
use App\Support\RentalTypes;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Rental storefront theme (StoreSetting->theme === 'rental'). Lists the
 * Rental module's items — only the business types the tenant runs — and
 * takes booking requests that become reservations (see RentalWebsiteService).
 *
 *   GET  /                          home (StoreFrontController::index)
 *   GET  /shop, /rentals            catalogue with the type's filters
 *   GET  /rentals/{slug-id}         item page + booking form
 *   GET  /rentals/{id}/calendar     day-by-day availability (JSON)
 *   POST /rentals/quote             price + availability for dates (JSON)
 *   POST /rentals/book              booking request (JSON)
 *   GET  /rentals/booking/{token}   the customer's booking status page
 *   POST /rentals/booking/{token}/cancel
 *   GET  /account/rentals           signed-in customer's bookings
 *
 * Cart, checkout and product pages are not used by this theme.
 */
class RentalStoreController extends StoreFrontController
{
    private const PER_PAGE = 12;

    public function __construct(private RentalWebsiteService $web)
    {
    }

    /** Variables every page of the theme passes to the layout. */
    private function base(array $extra = []): array
    {
        $s = StoreSetting::firstOrFail();

        return array_merge([
            's' => $s,
            'opts' => RentalTheme::options($s),
            'rnReady' => RentalWebsiteService::available(),
            'rnTypes' => RentalWebsiteService::available() ? $this->web->types() : [],
            'rnBookingOpen' => $this->web->bookingOpen(),
            'rnSettings' => RentalWebsiteService::available() ? $this->web->settings() : new \App\Models\RentalSetting(),
            // The layout's product category drawer stays empty: rentals have their own.
            'categories' => collect(),
            'showCategoryBar' => false,
        ], $extra);
    }

    public function home(Request $request)
    {
        $data = $this->base();
        if (! $data['rnReady']) {
            return view('store.rental.unavailable', $data);
        }
        $opts = $data['opts'];
        $featured = collect();
        if ($opts['sections']['featured']) {
            $limit = $opts['limits']['featured'];
            $featured = $this->web->itemsQuery()->with('category')->orderByDesc('featured')->orderByDesc('created_at')->limit($limit)->get();
        }

        return view('store.rental.home', $data + [
            'rnCategories' => $opts['sections']['categories'] ? $this->web->categories() : collect(),
            'featured' => $featured,
            'searchCategories' => $this->web->categories(),
            'hours' => $this->lines($opts['opening_hours']),
        ]);
    }

    public function catalog(Request $request)
    {
        $data = $this->base();
        if (! $data['rnReady']) {
            return view('store.rental.unavailable', $data);
        }
        $types = $data['rnTypes'];
        $type = in_array($request->query('type'), $types, true) ? $request->query('type') : (count($types) === 1 ? $types[0] : null);
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $categoryId = $request->filled('category') ? (int) $request->query('category') : null;
        $sort = in_array($request->query('sort'), ['recommended', 'price_asc', 'price_desc', 'newest'], true) ? $request->query('sort') : 'recommended';

        $query = $this->web->itemsQuery($type)->with('category');
        if ($categoryId) {
            $query->where('rental_items.rental_category_id', $categoryId);
        }
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                foreach (['name', 'brand', 'model', 'city', 'description'] as $col) {
                    $w->orWhere('rental_items.'.$col, 'LIKE', '%'.$q.'%');
                }
            });
        }

        // The type's spec filters (only when one type is being browsed).
        $specFilters = [];
        $active = [];
        if ($type) {
            foreach (RentalTypes::specFields($type) as $f) {
                if (empty($f['filter'])) {
                    continue;
                }
                $specFilters[] = $f;
                $raw = $request->query('f_'.$f['id']);
                if ($raw === null || $raw === '' || $raw === []) {
                    continue;
                }
                $path = 'rental_items.specs->'.$f['id'];
                if ($f['input'] === 'select') {
                    $vals = array_values(array_intersect((array) $raw, $f['options']));
                    if ($vals) {
                        $query->whereIn($path, $vals);
                        $active[$f['id']] = $vals;
                    }
                } elseif ($f['input'] === 'bool') {
                    if (filter_var($raw, FILTER_VALIDATE_BOOLEAN)) {
                        $query->where($path, true);
                        $active[$f['id']] = true;
                    }
                } elseif ($f['input'] === 'number' && is_numeric($raw)) {
                    $query->where($path, '>=', (int) $raw);
                    $active[$f['id']] = (int) $raw;
                }
            }
            if ($type === 'property' && is_numeric($request->query('bedrooms'))) {
                $query->where('rental_items.bedrooms', '>=', (int) $request->query('bedrooms'));
                $active['bedrooms'] = (int) $request->query('bedrooms');
            }
        }

        // Dates: only items with a unit free over the whole range.
        [$start, $end, $dateProblem] = $this->window($request);
        $ids = (clone $query)->pluck('rental_items.id')->all();
        $free = [];
        if ($start && $end && ! $dateProblem && $ids) {
            $free = $this->web->freeUnits($ids, $start, $end);
            $ids = array_values(array_filter($ids, fn ($id) => ($free[$id] ?? 0) > 0));
        }

        $items = RentalItem::with('category')->whereIn('id', $ids ?: [0])->get();
        if ($start && $end && ! $dateProblem) {
            // Free is not enough: the item's minimum rental must fit too (the quote enforces it).
            $items = $items->filter(fn ($i) => $this->web->meetsMinDuration($i, $start, $end))->values();
        }
        $items = $this->sortItems($items, $sort, $start, $end);
        $page = max(1, (int) $request->query('page', 1));
        $total = $items->count();
        $pageItems = $items->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values();
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator($pageItems, $total, self::PER_PAGE, $page, [
            'path' => store_route_or('store.shop'),
            'query' => $request->except('page'),
        ]);

        return view('store.rental.catalog', $data + [
            'items' => $paginator,
            'type' => $type,
            'q' => $q,
            'categoryId' => $categoryId,
            'sort' => $sort,
            'specFilters' => $specFilters,
            'activeFilters' => $active,
            'catalogCategories' => $this->web->categories($type),
            'start' => $start,
            'end' => $end,
            'dateProblem' => $dateProblem,
            'quotes' => ($start && $end && ! $dateProblem) ? $pageItems->mapWithKeys(fn ($i) => [$i->id => $this->web->pickPeriod($i, $start, $end)])->all() : [],
        ]);
    }

    public function show(Request $request, string $key)
    {
        if (! self::themeActive()) {
            abort(404);
        }
        $data = $this->base();
        if (! $data['rnReady']) {
            return view('store.rental.unavailable', $data);
        }
        $id = RentalItem::idFromPublicKey($key);
        $item = $id ? $this->web->findPublic($id) : null;
        if (! $item) {
            abort(404);
        }
        // Old or hand-typed slugs land on the canonical address.
        if ($key !== $item->publicKey()) {
            return redirect(store_base_url('rentals/'.$item->publicKey()), 301);
        }
        [$start, $end, $dateProblem] = $this->window($request);

        $similar = $this->web->itemsQuery($item->type)->with('category')->where('rental_items.id', '!=', $item->id)
            ->when($item->rental_category_id, fn ($q) => $q->orderByRaw('rental_items.rental_category_id = ? DESC', [$item->rental_category_id]))
            ->orderByDesc('featured')->limit(4)->get();

        $opts = $data['opts'];
        $seo = [
            'title' => $item->name.' — '.($data['s']->store_name ?: config('app.name')),
            'description' => \Illuminate\Support\Str::limit(strip_tags((string) $item->description), 160),
            'type' => 'product',
            'canonical' => store_base_url('rentals/'.$item->publicKey()),
        ];

        return view('store.rental.show', $data + [
            'item' => $item,
            'specs' => RentalTypes::specFields($item->type),
            'formFields' => $this->web->formFields($item),
            'similar' => $similar,
            'start' => $start && ! $dateProblem ? $start : null,
            'end' => $end && ! $dateProblem ? $end : null,
            'customer' => Auth::guard('store')->user(),
            'requireLogin' => (bool) $this->web->settings()->website_require_login,
            'terms' => trim((string) $this->web->settings()->website_terms),
            'seo' => $seo,
            'showDeposit' => $opts['show_deposit'],
        ]);
    }

    /** GET /rentals/{id}/calendar?from=Y-m-d&days=42 */
    public function calendar(Request $request, $id)
    {
        if (! RentalWebsiteService::available() || ! self::themeActive()) {
            return response()->json(['message' => __('messages.RntErrBookingClosed')], 404);
        }
        $item = $this->web->findPublic((int) $id);
        if (! $item) {
            return response()->json(['message' => __('messages.RntErrNotFound')], 404);
        }
        $from = $this->web->moment((string) $request->query('from', ''), '00:00') ?: Carbon::today();

        return response()->json(['days' => $this->web->calendar($item, $from, (int) $request->query('days', 42))]);
    }

    /** POST /rentals/quote {item_id, start_date, start_time, end_date, end_time, quantity} */
    public function quote(Request $request)
    {
        if (! RentalWebsiteService::available() || ! self::themeActive()) {
            return response()->json(['ok' => false, 'message' => __('messages.RntErrBookingClosed')], 404);
        }
        $v = $request->validate([
            'item_id' => 'required|integer',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d',
            'start_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'end_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'quantity' => 'nullable|integer|min:1|max:10000',
        ]);
        $item = $this->web->findPublic((int) $v['item_id']);
        if (! $item) {
            return response()->json(['ok' => false, 'message' => __('messages.RntErrNotFound')], 404);
        }
        $start = $this->web->moment($v['start_date'], $v['start_time'] ?? null);
        $end = $this->web->moment($v['end_date'], $v['end_time'] ?? null);
        if (! $start || ! $end) {
            return response()->json(['ok' => false, 'message' => __('messages.RntErrDates')], 422);
        }

        return response()->json($this->presentQuote($this->web->quote($item, $start, $end, (int) ($v['quantity'] ?? 1))));
    }

    /** POST /rentals/book — multipart (a licence photo may ride along). */
    public function book(Request $request)
    {
        // Honeypot: real visitors never fill the hidden "website" field.
        if (trim((string) $request->input('website', '')) !== '') {
            return response()->json(['ok' => true]);
        }
        if (! $this->web->bookingOpen() || ! self::themeActive()) {
            return response()->json(['message' => __('messages.RntErrBookingClosed')], 409);
        }
        $item = $this->web->findPublic((int) $request->input('item_id'));
        if (! $item) {
            return response()->json(['message' => __('messages.RntErrNotFound')], 404);
        }
        $terms = trim((string) $this->web->settings()->website_terms) !== '';
        $rules = [
            'item_id' => 'required|integer',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d',
            'start_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'end_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'quantity' => 'nullable|integer|min:1|max:10000',
            'name' => 'required|string|max:150',
            'phone' => 'required|string|max:40',
            'email' => 'required|email|max:190',
            'notes' => 'nullable|string|max:2000',
            'fields' => 'nullable|array',
            'field_files' => 'nullable|array',
            'accept_terms' => $terms ? 'accepted' : 'nullable',
        ] + $this->web->fieldRules($item, (array) $request->input('fields', []));
        $request->validate($rules, [], $this->web->fieldAttributes($item) + [
            'name' => mb_strtolower(__('messages.RntYourName')),
            'phone' => mb_strtolower(__('messages.Phone')),
            'email' => mb_strtolower(__('messages.Email')),
            'accept_terms' => mb_strtolower(__('messages.RntTerms')),
        ]);

        // At most 10 website bookings per connection a day, on top of the per-minute limit.
        $dayKey = 'rnt-book-day:'.$request->ip();
        if (RateLimiter::tooManyAttempts($dayKey, 10)) {
            return response()->json(['message' => __('messages.RntErrTooManyToday')], 429);
        }

        $start = $this->web->moment($request->input('start_date'), $request->input('start_time'));
        $end = $this->web->moment($request->input('end_date'), $request->input('end_time'));
        if (! $start || ! $end) {
            return response()->json(['message' => __('messages.RntErrDates'), 'errors' => ['dates' => [__('messages.RntErrDates')]]], 422);
        }

        try {
            $r = $this->web->book($item, [
                'start' => $start,
                'end' => $end,
                'quantity' => (int) $request->input('quantity', 1),
                'name' => $request->input('name'),
                'phone' => $request->input('phone'),
                'email' => $request->input('email'),
                'notes' => $request->input('notes'),
                'fields' => (array) $request->input('fields', []),
            ], Auth::guard('store')->user(), array_filter((array) $request->file('field_files', [])));
        } catch (RentalWebsiteException $e) {
            $body = ['message' => $e->getMessage()];
            if ($e->field()) {
                $body['errors'] = [$e->field() => [$e->getMessage()]];
            }

            return response()->json($body, $e->status());
        }
        RateLimiter::hit($dayKey, 86400);
        RentalWebsiteMailer::later('received', $r);

        $response = response()->json([
            'ok' => true,
            'number' => $r->reservation_number,
            'redirect' => store_base_url('rentals/booking/'.$r->public_token),
        ], 201);
        // The emails go out after the response: let the browser finish reading it now.
        $response->headers->set('Content-Length', (string) strlen($response->getContent()));
        $response->headers->set('Connection', 'close');

        return $response;
    }

    /** GET /rentals/booking/{token} — the customer's status page (the token is the secret). */
    public function booking(Request $request, string $token)
    {
        $data = $this->base();
        $r = $this->findByToken($token);
        if (! $r) {
            abort(404);
        }
        app(\App\Services\Rental\RentalContractService::class)->sync($r->items->pluck('rental_item_id')->all());
        $r->refresh()->load('items.item', 'client', 'contract');

        return view('store.rental.booking', $data + [
            'r' => $r,
            'stage' => $r->customerStage(),
            'answers' => collect((array) (($r->request_fields ?? [])['answers'] ?? [])),
            'itemType' => (($r->request_fields ?? [])['item_type'] ?? optional(optional($r->items->first())->item)->type) ?: 'other',
            'seo' => ['title' => __('messages.RntBookingTitle', ['number' => $r->reservation_number]), 'canonical' => store_base_url('rentals/booking/'.$token)],
        ]);
    }

    /** POST /rentals/booking/{token}/cancel */
    public function cancelBooking(Request $request, string $token)
    {
        $r = $this->findByToken($token);
        if (! $r) {
            abort(404);
        }
        try {
            $this->web->cancelByCustomer($r);
        } catch (RentalWebsiteException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], $e->status())
                : redirect(store_base_url('rentals/booking/'.$token))->with('rn_error', $e->getMessage());
        }
        RentalWebsiteMailer::later('cancelled_by_customer', $r);

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : redirect(store_base_url('rentals/booking/'.$token))->with('rn_notice', __('messages.RntCancelledNotice'));
    }

    /** GET /account/rentals — website bookings and rental contracts of the signed-in customer. */
    public function myRentals(Request $request)
    {
        $data = $this->base();
        $user = Auth::guard('store')->user();
        $bookings = collect();
        $contracts = collect();
        if ($data['rnReady'] && $user) {
            $clientId = (int) $user->client_id;
            $bookings = RentalReservation::with('items')->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('ecommerce_client_id', $user->id)->when($clientId, fn ($w) => $w->orWhere('client_id', $clientId)))
                ->orderByDesc('start_date')->limit(50)->get();
            if ($clientId) {
                $contracts = \App\Models\RentalContract::with('items')->whereNull('deleted_at')->where('client_id', $clientId)
                    ->whereIn('status', ['active', 'ended', 'terminated', 'renewed'])->orderByDesc('start_date')->limit(50)->get();
            }
        }

        return view('store.rental.account', $data + ['bookings' => $bookings, 'contracts' => $contracts]);
    }

    /** GET /rentals — the catalogue lives at /shop; keep the filters. */
    public function catalogRedirect(Request $request)
    {
        $url = store_route_or('store.shop');
        $query = $request->query();

        return redirect($url.($query ? '?'.http_build_query($query) : ''), 301);
    }

    /** The Rental theme is the store's theme right now (item pages, quotes and bookings need it). */
    public static function themeActive(): bool
    {
        return (StoreSetting::query()->value('theme') ?? 'default') === RentalTheme::KEY;
    }

    // ------------------------------------------------------------------

    private function findByToken(string $token): ?RentalReservation
    {
        if (! RentalWebsiteService::available() || ! preg_match('/^[A-Za-z0-9]{20,64}$/', $token)) {
            return null;
        }

        return RentalReservation::with('items.item', 'client', 'contract')->whereNull('deleted_at')
            ->where('source', 'website')->where('public_token', $token)->first();
    }

    /** [start, end, problem] from ?start=&start_time=&end=&end_time= (all null when no dates asked). */
    private function window(Request $request): array
    {
        if (! $request->filled('start') || ! $request->filled('end')) {
            return [null, null, null];
        }
        $start = $this->web->moment((string) $request->query('start'), (string) $request->query('start_time', ''));
        $end = $this->web->moment((string) $request->query('end'), (string) $request->query('end_time', ''));
        if (! $start || ! $end) {
            return [null, null, __('messages.RntErrDates')];
        }

        return [$start, $end, $this->web->windowProblem($start, $end)];
    }

    private function sortItems($items, string $sort, ?Carbon $start, ?Carbon $end)
    {
        $price = function (RentalItem $i) use ($start, $end) {
            if ($start && $end) {
                return optional($this->web->pickPeriod($i, $start, $end))['amount'] ?? PHP_FLOAT_MAX;
            }
            $h = $i->headline();

            return $h ? $h['rate'] : PHP_FLOAT_MAX;
        };
        switch ($sort) {
            case 'price_asc':
                return $items->sortBy($price)->values();
            case 'price_desc':
                return $items->sortByDesc(fn ($i) => $price($i) === PHP_FLOAT_MAX ? -1 : $price($i))->values();
            case 'newest':
                return $items->sortByDesc('created_at')->values();
            default:
                return $items->sortBy([['featured', 'desc'], ['created_at', 'desc']])->values();
        }
    }

    /** The quote with money already formatted in the visitor's currency. */
    private function presentQuote(array $q): array
    {
        if (isset($q['rent'])) {
            $q['rent_label'] = store_money($q['rent']);
            $q['rate_label'] = store_money($q['rate']);
            $q['deposit_label'] = store_money($q['deposit']);
            $q['period_label'] = __('messages.RntPer_'.$q['period']);
            $q['periods_label'] = trans_choice('messages.RntPeriods_'.$q['period'], $q['periods'], ['n' => $q['periods']]);
        }

        return $q;
    }

    private function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text))));
    }
}
