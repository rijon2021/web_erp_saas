<?php

namespace App\Services\Rental;

use App\Models\Client;
use App\Models\EcommerceClient;
use App\Models\RentalCategory;
use App\Models\RentalCustomer;
use App\Models\RentalCustomerDocument;
use App\Models\RentalItem;
use App\Models\RentalMaintenance;
use App\Models\RentalReservation;
use App\Models\RentalReservationItem;
use App\Models\RentalSetting;
use App\Models\StoreSetting;
use App\Support\RentalTypes;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Rental store theme's business rules: which items and categories the
 * website lists, prices for a date range, the availability calendar and
 * turning a booking request into a reservation.
 *
 * A website booking is an ordinary rental reservation (status `held`,
 * source `website`) holding the items for Rental Settings ›
 * website_hold_hours. Staff then confirm it (the hold is stretched to the
 * end of the rental), convert it into a contract, or cancel it — all with
 * the usual Reservations screens. Availability uses the same rule as every
 * other booking (RentalContractService::conflicts), under the same item
 * locks, so the website can never double-book.
 */
class RentalWebsiteService
{
    private ?RentalSetting $settings = null;

    public function __construct(private RentalContractService $contracts)
    {
    }

    /** The plan allows the Rental module, its toggle is on and the website columns exist. */
    public static function available(): bool
    {
        try {
            return \App\Http\Controllers\Api\Portal\PortalRentalsController::moduleEnabled()
                && RentalSetting::websiteReady();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function settings(): RentalSetting
    {
        return $this->settings ??= RentalSetting::current();
    }

    public function bookingOpen(): bool
    {
        return self::available() && (bool) $this->settings()->website_booking;
    }

    public function types(): array
    {
        return RentalTypes::enabled($this->settings());
    }

    // ------------------------------------------------------------------
    // Catalogue
    // ------------------------------------------------------------------

    /** Items the website may show: published, not inactive, of an enabled type, in the store's branches. */
    public function itemsQuery(?string $type = null)
    {
        $types = $this->types();
        $q = RentalItem::query()
            ->whereNull('rental_items.deleted_at')
            ->where('rental_items.show_on_website', 1)
            ->where('rental_items.status', '!=', 'inactive')
            ->whereIn('rental_items.type', $types);
        if ($type !== null && in_array($type, $types, true)) {
            $q->where('rental_items.type', $type);
        }
        // A category hidden from the website hides its items too.
        $q->where(fn ($w) => $w->whereNull('rental_items.rental_category_id')
            ->orWhereIn('rental_items.rental_category_id', RentalCategory::whereNull('deleted_at')->where('show_on_website', 1)->select('id')));
        $warehouses = $this->storeWarehouseIds();
        if ($warehouses) {
            $q->where(fn ($w) => $w->whereNull('rental_items.warehouse_id')->orWhereIn('rental_items.warehouse_id', $warehouses));
        }

        return $q;
    }

    public function findPublic(int $id): ?RentalItem
    {
        return $this->itemsQuery()->with('category')->whereKey($id)->first();
    }

    /** Website categories with their number of listed items (empty ones left out). */
    public function categories(?string $type = null): Collection
    {
        $types = $this->types();
        $counts = $this->itemsQuery($type)->whereNotNull('rental_category_id')
            ->groupBy('rental_category_id')->selectRaw('rental_category_id, COUNT(*) AS c')->pluck('c', 'rental_category_id');

        return RentalCategory::whereNull('deleted_at')->where('show_on_website', 1)
            ->where(fn ($q) => $q->whereNull('type')->orWhereIn('type', $types))
            ->when($type, fn ($q) => $q->where(fn ($w) => $w->whereNull('type')->orWhere('type', $type)))
            ->orderBy('sort_order')->orderBy('name')->get()
            ->each(fn ($c) => $c->setAttribute('items_count', (int) ($counts[$c->id] ?? 0)))
            ->filter(fn ($c) => $c->items_count > 0)
            ->values();
    }

    private function storeWarehouseIds(): array
    {
        static $memo = [];
        $key = function_exists('tenant') && tenant() ? (string) tenant()->getTenantKey() : '_';
        if (! array_key_exists($key, $memo)) {
            $s = StoreSetting::query()->first();
            // An item with no branch belongs to the whole company: always listed.
            $memo[$key] = $s ? $s->activeWarehouseIds() : [];
        }

        return $memo[$key];
    }

    // ------------------------------------------------------------------
    // Dates, prices, availability
    // ------------------------------------------------------------------

    /** "2026-11-02" + "10:00" in the tenant's time zone, or null when malformed. */
    public function moment(?string $date, ?string $time = null): ?Carbon
    {
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $time = is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : '10:00';
        try {
            $m = Carbon::createFromFormat('Y-m-d H:i', $date.' '.$time, config('app.timezone'));
        } catch (\Throwable $e) {
            return null;
        }

        return $m && $m->format('Y-m-d') === $date ? $m->second(0) : null;
    }

    /** Booking window rules from Rental Settings; a visitor-facing message when broken. */
    public function windowProblem(Carbon $start, Carbon $end): ?string
    {
        $s = $this->settings();
        $now = Carbon::now();
        if ($end->lte($start)) {
            return __('messages.RntErrEndBeforeStart');
        }
        $notice = (int) ($s->website_min_notice_hours ?? 2);
        if ($start->lt($now->copy()->addHours($notice))) {
            return $notice > 0
                ? __('messages.RntErrNotice', ['hours' => $notice])
                : __('messages.RntErrPast');
        }
        $ahead = (int) ($s->website_max_days_ahead ?: 180);
        if ($start->gt($now->copy()->addDays($ahead))) {
            return __('messages.RntErrTooFar', ['days' => $ahead]);
        }
        if ($end->gt($start->copy()->addDays(366))) {
            return __('messages.RntErrTooLong');
        }

        return null;
    }

    /**
     * The cheapest single pricing period for [start, end]: each offered rate
     * × the periods it takes (rounded up). Ties keep the item's main period.
     *
     * @return array{period: string, periods: int, rate: float, amount: float}|null
     */
    public function pickPeriod(RentalItem $item, Carbon $start, Carbon $end): ?array
    {
        $best = null;
        $order = array_values(array_unique(array_merge([$item->default_period], RentalItem::PERIODS)));
        foreach ($order as $p) {
            $rate = $item->rateFor($p);
            if ($rate === null) {
                continue;
            }
            $n = $this->contracts->periodsBetween($start, $end, $p);
            $amount = round($rate * $n, 2);
            if ($best === null || $amount < $best['amount'] - 0.001) {
                $best = ['period' => $p, 'periods' => $n, 'rate' => (float) $rate, 'amount' => $amount];
            }
        }

        return $best;
    }

    /** Units of each item free over [start, end] under the usual booking rule. */
    public function freeUnits(array $itemIds, Carbon $start, Carbon $end, ?int $ignoreReservationId = null): array
    {
        $items = RentalItem::whereIn('id', $itemIds)->get(['id', 'quantity'])->keyBy('id');
        // Ask for one unit more than the item has: conflicts() then always
        // reports the item, with the number still free.
        $ask = [];
        foreach ($itemIds as $id) {
            $ask[(int) $id] = (int) optional($items->get($id))->quantity + 1;
        }
        $free = array_fill_keys(array_keys($ask), 0);
        foreach ($this->contracts->conflicts($ask, $start, $end, null, $ignoreReservationId) as $p) {
            $free[(int) $p['item_id']] = (int) $p['available'];
        }

        return $free;
    }

    /** The item's minimum rental (if any) fits in [start, end]. */
    public function meetsMinDuration(RentalItem $item, Carbon $start, Carbon $end): bool
    {
        if (! $item->min_duration) {
            return true;
        }
        $unit = $item->min_duration_unit ?: $item->default_period;

        return ! $end->lt($this->contracts->addPeriods($start, $unit, (int) $item->min_duration));
    }

    /** Price + availability for one item; `ok` false carries the reason in `message`. */
    public function quote(RentalItem $item, Carbon $start, Carbon $end, int $qty = 1): array
    {
        $qty = max(1, $qty);
        $base = ['ok' => false, 'available' => false, 'free' => 0, 'quantity' => $qty, 'message' => null];

        if ($problem = $this->windowProblem($start, $end)) {
            return array_merge($base, ['message' => $problem]);
        }
        if (! $this->meetsMinDuration($item, $start, $end)) {
            $unit = $item->min_duration_unit ?: $item->default_period;

            return array_merge($base, ['message' => __('messages.RntErrMinDuration', [
                'n' => $item->min_duration,
                'unit' => __('messages.RntUnit_'.$unit),
            ])]);
        }
        $pick = $this->pickPeriod($item, $start, $end);
        if (! $pick) {
            return array_merge($base, ['message' => __('messages.RntErrNoPrice')]);
        }

        $free = $this->freeUnits([$item->id], $start, $end)[$item->id] ?? 0;
        $totals = $this->contracts->calculate(
            [['rate' => $pick['rate'], 'quantity' => $qty]], $start, $end, $pick['period'], 'upfront', 0.0, 0.0
        );
        $deposit = round((float) $item->deposit_amount * $qty, 2);

        return [
            'ok' => $free >= $qty,
            'available' => $free >= $qty,
            'free' => $free,
            'quantity' => $qty,
            'period' => $pick['period'],
            'periods' => (int) $totals['periods'],
            'rate' => $pick['rate'],
            'rent' => (float) $totals['total_amount'],
            'deposit' => $deposit,
            'due_now' => 0.0,
            'start' => $start->format('Y-m-d H:i'),
            'end' => $end->format('Y-m-d H:i'),
            'message' => $free >= $qty ? null : ($free > 0
                ? __('messages.RntErrOnlyFree', ['n' => $free])
                : __('messages.RntErrBooked')),
        ];
    }

    /**
     * Day-by-day availability from $from for $days days:
     * [{date, state: free|partial|busy|closed|end, free}], where closed =
     * before the notice period and end = past the booking horizon (a rental
     * may end there but not start). Same overlap rule as
     * conflicts() (buffer, overdue contracts, maintenance), computed in memory.
     */
    public function calendar(RentalItem $item, Carbon $from, int $days = 42): array
    {
        $days = max(1, min(93, $days));
        $s = $this->settings();
        $buffer = (int) $s->buffer_hours;
        $now = Carbon::now();
        $qty = max(1, (int) $item->quantity);

        $rows = $this->contracts->holdingRows([$item->id])->concat($this->contracts->maintenanceRows([$item->id]));
        $spans = $rows->map(function ($row) use ($now, $buffer) {
            $st = Carbon::parse($row->start_date);
            if (! empty($row->checked_out_at)) {
                $st = $st->min(Carbon::parse($row->checked_out_at));
            }
            $en = $row->end_date ? Carbon::parse($row->end_date) : null;
            if ($en && $en->lt($now)) {
                $en = $now->copy();
            }

            return [$st->copy()->subHours($buffer), $en ? $en->copy()->addHours($buffer) : null, (int) $row->quantity];
        })->all();

        // In maintenance with no planned end = blocked until someone changes it.
        $blockedUntil = false;
        if ($item->status === 'maintenance') {
            $jobs = RentalMaintenance::whereNull('deleted_at')->where('rental_item_id', $item->id)->where('status', 'in_progress')->get();
            $blockedUntil = ($jobs->isEmpty() || $jobs->contains(fn ($j) => ! $j->scheduled_end))
                ? null : Carbon::parse($jobs->max('scheduled_end'));
        }

        $first = $now->copy()->addHours((int) ($s->website_min_notice_hours ?? 2))->startOfDay();
        $last = $now->copy()->addDays((int) ($s->website_max_days_ahead ?: 180))->endOfDay();
        $out = [];
        $day = $from->copy()->startOfDay();
        for ($i = 0; $i < $days; $i++, $day->addDay()) {
            $dayEnd = $day->copy()->addDay();
            if ($dayEnd->lte($first)) {
                $out[] = ['date' => $day->format('Y-m-d'), 'state' => 'closed', 'free' => 0];
                continue;
            }
            // Past the horizon a booking may not START, but may still end (windowProblem
            // limits the start only): such free days are "end" = return-only.
            $pastHorizon = $day->gt($last);
            if ($item->status === 'inactive' || ($item->status === 'maintenance' && ($blockedUntil === null || $day->lt($blockedUntil)))) {
                $out[] = ['date' => $day->format('Y-m-d'), 'state' => 'busy', 'free' => 0];
                continue;
            }
            $taken = 0;
            foreach ($spans as [$st, $en, $q]) {
                if ($st->lt($dayEnd) && ($en === null || $en->gt($day))) {
                    $taken += $q;
                }
            }
            $free = max(0, $qty - $taken);
            $state = $free <= 0 ? 'busy' : ($pastHorizon ? 'end' : ($free < $qty ? 'partial' : 'free'));
            $out[] = ['date' => $day->format('Y-m-d'), 'state' => $state, 'free' => $free];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Booking form
    // ------------------------------------------------------------------

    /**
     * The extra fields this item's booking form shows: the type's fields in
     * the owner's modes, minus those the item rules out (no delivery offered,
     * no operator available).
     */
    public function formFields(RentalItem $item): array
    {
        $fields = RentalTypes::bookingFields($item->type, $this->settings());
        $delivery = $item->spec('delivery_available') === true;
        $operator = $item->spec('operator_available') === true;

        return array_values(array_filter($fields, function ($f) use ($delivery, $operator) {
            if (in_array($f['id'], ['fulfilment', 'delivery_address'], true) && ! $delivery) {
                return false;
            }
            if ($f['id'] === 'operator_needed' && ! $operator) {
                return false;
            }

            return true;
        }));
    }

    /** Laravel rules for the form fields above (keys under `fields.`). */
    public function fieldRules(RentalItem $item, array $input): array
    {
        $rules = [];
        foreach ($this->formFields($item) as $f) {
            $req = $f['mode'] === 'required';
            // Delivery address only matters when delivery was chosen.
            if ($f['id'] === 'delivery_address') {
                $req = $req && (($input['fulfilment'] ?? null) === 'delivery');
            }
            $base = [$req ? 'required' : 'nullable'];
            switch ($f['input']) {
                case 'number':
                    $rules['fields.'.$f['id']] = array_merge($base, ['integer', 'min:'.($f['min'] ?? 0), 'max:'.($f['max'] ?? 100000)]);
                    break;
                case 'date':
                    $rules['fields.'.$f['id']] = array_merge($base, ['date_format:Y-m-d']);
                    break;
                case 'bool':
                    $rules['fields.'.$f['id']] = ['nullable', 'boolean'];
                    break;
                case 'choice':
                case 'location':
                    $rules['fields.'.$f['id']] = array_merge($base, ['string', \Illuminate\Validation\Rule::in($f['options'])]);
                    break;
                case 'file':
                    $rules['field_files.'.$f['id']] = array_merge($base, ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120']);
                    break;
                case 'textarea':
                    $rules['fields.'.$f['id']] = array_merge($base, ['string', 'max:1000']);
                    break;
                default:
                    $rules['fields.'.$f['id']] = array_merge($base, ['string', 'max:120']);
            }
        }

        return $rules;
    }

    /** Validation attribute names for those rules, so errors read "Driver age …". */
    public function fieldAttributes(RentalItem $item): array
    {
        $out = [];
        foreach ($this->formFields($item) as $f) {
            $out[($f['input'] === 'file' ? 'field_files.' : 'fields.').$f['id']] = mb_strtolower(RentalTypes::fieldLabel($f));
        }

        return $out;
    }

    /**
     * Book an item from the website.
     *
     * $data: item_id, quantity, start, end (Carbon), name, phone, email,
     * notes, fields (form answers). $files: field id => UploadedFile.
     */
    public function book(RentalItem $item, array $data, ?EcommerceClient $user = null, array $files = []): RentalReservation
    {
        if (! $this->bookingOpen()) {
            throw new RentalWebsiteException(__('messages.RntErrBookingClosed'), 409);
        }
        $s = $this->settings();
        if ($s->website_require_login && ! $user) {
            throw new RentalWebsiteException(__('messages.RntErrLoginRequired'), 401);
        }
        /** @var Carbon $start */
        $start = $data['start'];
        /** @var Carbon $end */
        $end = $data['end'];
        $qty = max(1, min((int) ($data['quantity'] ?? 1), max(1, (int) $item->quantity)));

        $quote = $this->quote($item, $start, $end, $qty);
        if (! $quote['ok']) {
            throw new RentalWebsiteException($quote['message'] ?: __('messages.RntErrBooked'), 422, 'dates');
        }

        $fields = $this->formFields($item);
        $answers = $this->answers($fields, (array) ($data['fields'] ?? []), $files);
        $this->checkAgainstItem($item, $answers);

        // A visitor may hold only a few unconfirmed requests at once.
        $email = mb_strtolower(trim((string) $data['email']));
        $phone = trim((string) $data['phone']);
        $pending = RentalReservation::whereNull('deleted_at')->where('source', 'website')->where('status', 'held')
            ->whereNull('confirmed_at')->where('hold_until', '>', Carbon::now())
            ->where(function ($q) use ($email, $user) {
                $q->whereIn('client_id', Client::where('email', $email)->pluck('id'));
                if ($user) {
                    $q->orWhere('ecommerce_client_id', $user->id);
                }
            })->count();
        if ($pending >= 3) {
            throw new RentalWebsiteException(__('messages.RntErrTooManyPending'), 429);
        }

        $reservation = $this->contracts->transact(function () use ($item, $data, $user, $start, $end, $qty, $quote, $answers, $files, $email, $phone, $s) {
            // Re-checked under the item lock: another booking may have just taken it.
            $free = $this->freeUnits([$item->id], $start, $end)[$item->id] ?? 0;
            if ($free < $qty) {
                throw new RentalWebsiteException(__('messages.RntErrJustBooked'), 409, 'dates');
            }

            $client = $this->client($data, $user, $email, $phone);
            $customer = RentalCustomer::whereNull('deleted_at')->where('client_id', $client->id)->first()
                ?: RentalCustomer::create(['client_id' => $client->id, 'notes' => 'Created from a website booking.']);
            if ($customer->is_blacklisted) {
                throw new RentalWebsiteException(__('messages.RntErrCannotBook'), 422);
            }

            $holdHours = max(1, (int) ($s->website_hold_hours ?: 24));
            $r = RentalReservation::create([
                'reservation_number' => $this->contracts->nextReservationNumber(),
                'rental_customer_id' => $customer->id,
                'client_id' => $client->id,
                'warehouse_id' => $item->warehouse_id,
                'status' => 'held',
                'start_date' => $start,
                'end_date' => $end,
                'hold_until' => Carbon::now()->addHours($holdHours),
                'rate_period' => $quote['period'],
                'billing_type' => 'upfront',
                'periods' => $quote['periods'],
                'rent_amount' => round($quote['rate'] * $qty, 2),
                'discount' => 0,
                'tax_rate' => 0,
                'tax_amount' => 0,
                'total_amount' => $quote['rent'],
                'contract_value' => $quote['rent'],
                'deposit_amount' => $quote['deposit'],
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'source' => 'website',
                'request_fields' => [
                    'contact' => ['name' => trim((string) $data['name']), 'phone' => $phone, 'email' => $email],
                    'answers' => $answers,
                    'item_type' => $item->type,
                    // What the customer wrote; `notes` starts as a copy but is staff's to edit
                    // (the public status page shows only this one).
                    'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                ],
                'public_token' => Str::random(40),
                'ecommerce_client_id' => $user ? $user->id : null,
                'created_by' => null,
            ]);
            RentalReservationItem::create([
                'rental_reservation_id' => $r->id,
                'rental_item_id' => $item->id,
                'item_name' => $item->name,
                'item_code' => $item->code,
                'quantity' => $qty,
                'rate' => $quote['rate'],
                'amount' => round($quote['rate'] * $qty, 2),
            ]);

            $this->saveLicence($customer, $r, $answers, $files);

            return $r;
        }, [$item->id]);

        return $reservation->fresh(['items', 'client']);
    }

    /** Customer cancels a request staff haven't turned into a contract yet. */
    public function cancelByCustomer(RentalReservation $r): void
    {
        if ($r->status !== 'held' || $r->contract_id) {
            throw new RentalWebsiteException(__('messages.RntErrCannotCancel'), 422);
        }
        $this->contracts->transact(function () use ($r) {
            $fresh = RentalReservation::whereKey($r->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->status !== 'held' || $fresh->contract_id) {
                throw new RentalWebsiteException(__('messages.RntErrCannotCancel'), 422);
            }
            $fresh->update(['status' => 'cancelled', 'cancelled_at' => Carbon::now(), 'cancel_reason' => 'Cancelled by the customer on the website.']);
        }, $r->items()->pluck('rental_item_id')->all());
    }

    /** Store user's client, an exact email + phone match, or a new client. */
    private function client(array $data, ?EcommerceClient $user, string $email, string $phone): Client
    {
        if ($user && $user->client_id && ($c = Client::whereNull('deleted_at')->find($user->client_id))) {
            return $c;
        }
        // Guests: only an exact email AND phone match re-uses a client, so a
        // stranger typing someone's email can't attach a booking to them.
        $match = Client::whereNull('deleted_at')->where('email', $email)->where('phone', $phone)->first();
        if ($match) {
            return $match;
        }
        if (app(\App\Services\TenantLimitsService::class)->hasReachedLimit('max_customers')) {
            throw new RentalWebsiteException(__('messages.RntErrCannotBookNow'), 503);
        }
        $last = DB::table('clients')->latest('id')->first();
        $client = Client::create([
            'name' => Str::limit(trim((string) $data['name']), 191, ''),
            'phone' => $phone,
            'email' => $email,
            'code' => $last ? $last->code + 1 : 1,
            'is_royalty_eligible' => 0,
            'opening_balance' => 0,
            'credit_limit' => 0,
        ]);
        if ($user && ! $user->client_id) {
            $user->update(['client_id' => $client->id]);
        }

        return $client;
    }

    /** Form answers in display order: [{id, input, label, value}] (files by original name). */
    private function answers(array $fields, array $input, array $files): array
    {
        $out = [];
        foreach ($fields as $f) {
            if ($f['input'] === 'file') {
                if (isset($files[$f['id']]) && $files[$f['id']] instanceof UploadedFile) {
                    $out[] = ['id' => $f['id'], 'input' => 'file', 'label' => $f['label'], 'value' => Str::limit($files[$f['id']]->getClientOriginalName(), 120, '')];
                }
                continue;
            }
            if ($f['input'] === 'location') {
                $v = in_array($input[$f['id']] ?? null, $f['options'], true) ? $input[$f['id']] : null;
            } else {
                $v = RentalTypes::coerce($f, $input[$f['id']] ?? null);
            }
            if ($f['id'] === 'delivery_address' && ($input['fulfilment'] ?? null) !== 'delivery') {
                $v = null;
            }
            if ($v === null || $v === '' || ($f['input'] === 'bool' && $v === false)) {
                continue;
            }
            $out[] = ['id' => $f['id'], 'input' => $f['input'], 'label' => $f['label'], 'value' => $v];
        }

        return $out;
    }

    /** Rules that depend on the item's own properties (driver age, guests). */
    private function checkAgainstItem(RentalItem $item, array $answers): void
    {
        $byId = collect($answers)->keyBy('id');
        $minAge = $item->spec('min_driver_age');
        if ($minAge && $byId->has('driver_age') && (int) $byId['driver_age']['value'] < (int) $minAge) {
            throw new RentalWebsiteException(__('messages.RntErrDriverAge', ['age' => (int) $minAge]), 422, 'fields.driver_age');
        }
        $maxGuests = $item->spec('max_guests');
        if ($maxGuests && $byId->has('guests') && (int) $byId['guests']['value'] > (int) $maxGuests) {
            throw new RentalWebsiteException(__('messages.RntErrGuests', ['n' => (int) $maxGuests]), 422, 'fields.guests');
        }
    }

    /**
     * Driving licence: the number/expiry fill the customer's ID only where it
     * is still empty (never overwriting what staff checked), and the photo is
     * filed under the customer's documents.
     */
    private function saveLicence(RentalCustomer $customer, RentalReservation $r, array $answers, array $files): void
    {
        // $answers only holds this item's active form fields: a file or licence
        // posted for a field the owner turned off (or another type) is ignored.
        $byId = collect($answers)->keyBy('id');
        $profile = [];
        if ($byId->has('license_number') && ! $customer->id_number) {
            $profile['id_type'] = 'driving_license';
            $profile['id_number'] = Str::limit((string) $byId['license_number']['value'], 100, '');
            if ($byId->has('license_expiry') && ! $customer->id_expiry) {
                $profile['id_expiry'] = $byId['license_expiry']['value'];
            }
        }
        if ($profile) {
            $customer->update($profile);
        }

        $file = $files['license_file'] ?? null;
        if (! $byId->has('license_file') || ! $file instanceof UploadedFile) {
            return;
        }
        $dir = upload_public_path(RentalCustomer::FILE_DIR);
        if (! file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        $ext = RentalUpload::extension($file, 'id');
        $name = Str::random(40).'.'.$ext;
        $size = (int) $file->getSize();
        $mime = (string) $file->getMimeType();
        $original = Str::limit($file->getClientOriginalName(), 191, '');
        $file->move($dir, $name);
        RentalCustomerDocument::create([
            'rental_customer_id' => $customer->id,
            'title' => 'Driving licence — website booking '.$r->reservation_number,
            'file_name' => $name,
            'original_name' => $original,
            'mime' => Str::limit($mime, 100, ''),
            'size' => $size,
            'uploaded_by' => null,
        ]);
    }
}
