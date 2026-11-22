<?php

namespace App\Services\Booking;

use App\Events\BookingChanged;
use App\Http\Controllers\BookingController;
use App\Models\Booking;
use App\Models\BookingServiceDetail;
use App\Models\BookingSetting;
use App\Models\BookingStaff;
use App\Models\Client;
use App\Models\EcommerceClient;
use App\Support\BookingTheme;
use App\Support\BookingTypes;
use App\Support\BookingModule;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Booking store theme's data and booking logic.
 *
 * Services = service products with bk_services.show_on_website; the team =
 * active staff with show_on_website. Free times come from AvailabilityService
 * with the online rules (staff bookable online, notice, days ahead). A
 * booking for a free time is re-checked under a lock on the staff row, so
 * two visitors cannot take the same time; it waits as pending (held for
 * Booking Settings › hold time) unless auto-confirm is on. A request has a
 * preferred day and part of the day and waits for staff to give it a time.
 */
class BookingWebsiteService
{
    public const FILE_DIR = 'booking_files';

    private ?BookingSetting $settings = null;

    /** The last book() made a new customer record. */
    private bool $newClient = false;

    /** Plan + module toggle + every booking migration up to the workflow (phase 4). */
    public static function available(): bool
    {
        try {
            return BookingModule::enabled() && Booking::workflowReady();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function settings(): BookingSetting
    {
        return $this->settings ??= BookingSetting::current();
    }

    /** Which ways of booking the website offers: ['slot', 'request'] or fewer. */
    public function modes(): array
    {
        if (! self::available()) {
            return [];
        }
        $s = $this->settings();

        return array_values(array_filter([$s->slot_booking ? 'slot' : null, $s->request_booking ? 'request' : null]));
    }

    public function bookingOpen(): bool
    {
        return $this->modes() !== [];
    }

    // ------------------------------------------------------------------
    // Catalogue
    // ------------------------------------------------------------------

    /** Services the website lists, in website order, as plain arrays for the views and the scripts. */
    public function services(): Collection
    {
        $rows = DB::table('bk_services as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereNull('p.deleted_at')->where('p.type', 'is_service')->where('p.is_active', 1)
            ->where('s.show_on_website', 1)
            ->orderBy('s.sort_order')->orderByDesc('s.featured')->orderBy('p.name')
            ->get([
                'p.id', 'p.name', 'p.price', 'p.image', 'p.note', 'p.category_id', 'c.name as category_name',
                's.business_type', 's.duration_minutes', 's.seats', 's.price_display', 's.booking_modes', 's.featured', 's.short_description',
            ]);
        $links = DB::table('bk_service_staff')->whereIn('product_id', $rows->pluck('id'))->get()->groupBy('product_id');
        $online = BookingStaff::query()->active()->where('bookable_online', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
        $modes = $this->modes();
        $enabled = BookingTypes::enabled($this->settings());
        $deposits = BookingDepositService::ready() ? app(BookingDepositService::class) : null;

        return $rows->map(function ($r) use ($links, $online, $modes, $enabled, $deposits) {
            $linked = collect($links[$r->id] ?? [])->pluck('staff_id')->map(fn ($v) => (int) $v)->all();
            $staff = $linked ? $online->whereIn('id', $linked)->values() : $online;
            $own = $r->booking_modes === 'both' ? ['slot', 'request'] : [$r->booking_modes];
            $svcModes = array_values(array_intersect($modes, $own));
            // A free time needs somebody who can be booked online.
            if ($staff->isEmpty()) {
                $svcModes = array_values(array_diff($svcModes, ['slot']));
            }
            $type = $r->business_type ?: (count($enabled) === 1 ? $enabled[0] : null);

            return [
                'id' => (int) $r->id,
                'name' => $r->name,
                'description' => (string) ($r->short_description ?: ''),
                'category_id' => $r->category_id ? (int) $r->category_id : null,
                'category' => $r->category_name,
                'image' => $r->image && $r->image !== 'no-image.png' ? product_image_url_or_null($r->image) : null,
                'duration' => (int) $r->duration_minutes,
                'seats' => max(1, (int) $r->seats),
                'price' => (float) $r->price,
                'price_display' => $r->price_display,
                'price_label' => $r->price_display === 'hidden' ? '' : ($r->price_display === 'from'
                    ? __('messages.BkmFromPrice', ['price' => store_money((float) $r->price)])
                    : store_money((float) $r->price)),
                'featured' => (bool) $r->featured,
                'type' => $type,
                'modes' => $svcModes,
                'staff' => $staff->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values()->all(),
                // Deposit asked when picking a free time: the rule and the amount for one person.
                'deposit' => $deposits ? $this->depositInfo($deposits, (int) $r->id, (float) $r->price) : null,
            ];
        })->filter(fn ($svc) => $svc['modes'] !== [])->values();
    }

    public function service(int $productId): ?array
    {
        return $this->services()->firstWhere('id', $productId);
    }

    /** Categories of the listed services, in first-seen order. */
    public function categories(Collection $services): Collection
    {
        return $services->filter(fn ($s) => $s['category_id'])
            ->unique('category_id')->map(fn ($s) => ['id' => $s['category_id'], 'name' => $s['category']])->values();
    }

    /** The team section: active staff shown on the website. */
    public function team(): Collection
    {
        return BookingStaff::query()->active()->where('show_on_website', true)->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($m) => [
                'id' => $m->id, 'name' => $m->name, 'title' => (string) ($m->title ?? ''), 'photo' => $m->photoUrl(),
                'color' => $m->color, 'initial' => mb_strtoupper(mb_substr(trim($m->name), 0, 1)) ?: '?',
            ]);
    }

    /**
     * Opening hours from the staff working hours (earliest start → latest end
     * of anyone bookable online, per weekday), when the owner wrote none.
     *
     * @return array<int, array{day: string, hours: ?string}>
     */
    public function openingHours(): array
    {
        $ids = BookingStaff::query()->active()->pluck('id');
        $rows = DB::table('bk_staff_hours')->whereIn('staff_id', $ids)->get()->groupBy('weekday');
        $out = [];
        foreach (BookingStaff::WEEKDAYS as $wd) {
            $day = Carbon::create(2024, 1, $wd)->locale(app()->getLocale())->translatedFormat('l');
            $list = $rows[$wd] ?? collect();
            $out[] = ['day' => Str::ucfirst($day), 'hours' => $list->isEmpty() ? null : $list->min('start').' – '.$list->max('end')];
        }

        return $out;
    }

    /** Booking-form fields for a service (its business type's + the common ones), seats field left out. */
    public function formFields(array $service): array
    {
        $fields = BookingTypes::formFields((string) ($service['type'] ?? ''), $this->settings());

        return array_values(array_filter($fields, fn ($f) => empty($f['seats'])));
    }

    /** The fields with storefront labels, for the booking page script. */
    public function formFieldsForPage(array $service): array
    {
        return array_map(function ($f) {
            $f['label_text'] = BookingTypes::fieldLabel($f);
            if (! empty($f['options'])) {
                $f['option_labels'] = array_map(fn ($o) => ['value' => $o, 'label' => $f['input'] === 'area' ? $o : BookingTypes::optionLabel($o)], $f['options']);
            }

            return $f;
        }, $this->formFields($service));
    }

    /** Laravel rules for those fields (keys under `fields.` / `field_files.`). */
    public function fieldRules(array $service): array
    {
        $rules = [];
        foreach ($this->formFields($service) as $f) {
            $base = [$f['mode'] === 'required' ? 'required' : 'nullable'];
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
                    $rules['fields.'.$f['id']] = array_merge($base, ['string', \Illuminate\Validation\Rule::in($f['options'])]);
                    break;
                case 'area':
                    $rules['fields.'.$f['id']] = ! empty($f['options'])
                        ? array_merge($base, ['string', \Illuminate\Validation\Rule::in($f['options'])])
                        : array_merge($base, ['string', 'max:120']);
                    break;
                case 'file':
                    $rules['field_files.'.$f['id']] = array_merge($base, ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120']);
                    break;
                case 'textarea':
                    $rules['fields.'.$f['id']] = array_merge($base, ['string', 'max:2000']);
                    break;
                default:
                    $rules['fields.'.$f['id']] = array_merge($base, ['string', 'max:191']);
            }
        }

        return $rules;
    }

    /** Validation attribute names for those rules, so errors read "Date of birth …". */
    public function fieldAttributes(array $service): array
    {
        $out = [];
        foreach ($this->formFields($service) as $f) {
            $out[($f['input'] === 'file' ? 'field_files.' : 'fields.').$f['id']] = mb_strtolower(BookingTypes::fieldLabel($f));
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Free times
    // ------------------------------------------------------------------

    /** Free start times of a service on a day (website rules). */
    public function slots(array $service, string $date, ?int $staffId, int $seats): array
    {
        if (! in_array('slot', $service['modes'], true)) {
            return [];
        }

        return (new AvailabilityService($this->settings()))->slots($service['id'], $date, $staffId, $seats, ['online' => true]);
    }

    /** date → number of free start times, for the month calendar. */
    public function days(array $service, string $from, string $to, ?int $staffId, int $seats): array
    {
        if (! in_array('slot', $service['modes'], true)) {
            return [];
        }

        return (new AvailabilityService($this->settings()))->days($service['id'], $from, $to, $staffId, $seats, true);
    }

    // ------------------------------------------------------------------
    // Booking
    // ------------------------------------------------------------------

    /**
     * Book a service from the website.
     *
     * $data: mode (slot|request), date, time (slot), window (request), staff_id
     * (slot, optional), seats, name, phone, email, fields (form answers).
     * $files: field id => UploadedFile.
     */
    public function book(array $service, array $data, ?EcommerceClient $user = null, array $files = []): Booking
    {
        if (! $this->bookingOpen()) {
            throw new BookingWebsiteException(__('messages.BkmErrClosed'), 409);
        }
        $s = $this->settings();
        $mode = (string) ($data['mode'] ?? '');
        if (! in_array($mode, $service['modes'], true)) {
            throw new BookingWebsiteException(__('messages.BkmErrMode'), 422, 'mode');
        }
        $seats = $service['seats'] > 1 ? max(1, min((int) ($data['seats'] ?? 1), $service['seats'])) : 1;
        $date = (string) ($data['date'] ?? '');
        $today = Carbon::today()->toDateString();
        $last = Carbon::today()->addDays(max(1, (int) $s->max_days_ahead))->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $today || $date > $last) {
            throw new BookingWebsiteException(__('messages.BkmErrDate'), 422, 'date');
        }

        // A visitor may hold only a few unanswered requests at once.
        $email = mb_strtolower(trim((string) $data['email']));
        $phone = trim((string) $data['phone']);
        $open = Booking::whereNull('deleted_at')->where('source', 'website')->where('status', 'pending')
            ->where(function ($q) use ($email, $user) {
                $q->whereIn('customer_id', Client::whereNull('deleted_at')->where('email', $email)->pluck('id'));
                if ($user) {
                    $q->orWhere('ecommerce_client_id', $user->id);
                }
            })->count();
        if ($open >= max(1, (int) $s->max_open_per_customer)) {
            throw new BookingWebsiteException(__('messages.BkmErrTooManyOpen'), 429);
        }

        $fields = $this->formFields($service);
        $answers = $this->answers($fields, (array) ($data['fields'] ?? []), $files);
        try {

        $booking = BookingTx::run(function () use ($service, $data, $user, $mode, $seats, $date, $answers, $email, $phone, $s) {
            $staffId = null;
            $time = null;
            $end = null;
            if ($mode === 'slot') {
                $time = (string) ($data['time'] ?? '');
                $wanted = ! empty($data['staff_id']) ? (int) $data['staff_id'] : null;
                if ($wanted && ! collect($service['staff'])->contains('id', $wanted)) {
                    throw new BookingWebsiteException(__('messages.BkmErrStaff'), 422, 'staff_id');
                }
                $slot = collect($this->slots($service, $date, $wanted, $seats))->firstWhere('time', $time);
                if (! $slot) {
                    throw new BookingWebsiteException(__('messages.BkmErrTaken'), 409, 'time');
                }
                $staffId = $wanted ?: (new AvailabilityService($s))->pickStaff($slot['staff_ids'], $date);
                // Under the staff lock, look again: another visitor may have just taken it.
                BookingStaff::whereKey($staffId)->lockForUpdate()->first();
                $check = (new AvailabilityService($s))->check($service['id'], $staffId, $date, $time, $slot['end'], $seats);
                if (! $check['ok']) {
                    throw new BookingWebsiteException(__('messages.BkmErrTaken'), 409, 'time');
                }
                $end = $slot['end'] !== '24:00' ? $slot['end'] : null;
            } else {
                $windows = $s->enabledWindows();
                $window = (string) ($data['window'] ?? '');
                if (! isset($windows[$window])) {
                    throw new BookingWebsiteException(__('messages.BkmErrWindow'), 422, 'window');
                }
                // Requests have no time yet: the window start stands in until staff set one.
                $time = $windows[$window]['from'];
            }

            $client = $this->client($data, $user, $email, $phone);
            // Deposit: a free time waits for it (held for the payment time),
            // a request is asked for it once staff confirm it.
            $deposits = BookingDepositService::ready() ? app(BookingDepositService::class) : null;
            $deposit = $deposits ? $deposits->amountFor($service['id'], (float) $service['price'], $seats) : 0.0;
            $depositDue = $deposits && $deposits->wantedFor($mode, $deposit) && $deposits->gateways() !== [];
            $confirmNow = $mode === 'slot' && $s->auto_confirm && ! $depositDue;
            $holdMinutes = $mode === 'slot' && $depositDue ? $deposits->holdMinutes() : (int) $s->hold_minutes;

            $b = Booking::create([
                'Ref' => app(BookingController::class)->getNumberOrder(),
                'customer_id' => $client->id,
                'product_id' => $service['id'],
                'price' => $service['price'],
                'booking_date' => $date,
                'booking_time' => $time,
                'booking_end_time' => $end,
                'status' => $confirmNow ? 'confirmed' : 'pending',
                'notes' => null,
                'source' => 'website',
                'booking_mode' => $mode,
                'business_type' => $service['type'],
                'staff_id' => $staffId,
                'warehouse_id' => ($staffId ? BookingStaff::whereKey($staffId)->value('warehouse_id') : null) ?: ($s->default_warehouse_id ?: null),
                'duration_minutes' => $mode === 'slot' ? $service['duration'] : null,
                'seats' => $seats,
                'preferred_date' => $mode === 'request' ? $date : null,
                'preferred_window' => $mode === 'request' ? (string) $data['window'] : null,
                'request_fields' => $answers + ['_contact' => ['name' => trim((string) $data['name']), 'phone' => $phone, 'email' => $email]],
                'public_token' => Str::random(40),
                'hold_until' => ! $confirmNow && $mode === 'slot' && $holdMinutes > 0 ? now()->addMinutes($holdMinutes) : null,
                'confirmed_at' => $confirmNow ? now() : null,
                'ecommerce_client_id' => $user?->id,
            ] + ($deposits ? [
                'deposit_amount' => $depositDue ? $deposit : null,
                'deposit_status' => $depositDue ? 'due' : null,
            ] : []));
            $this->newClient = $client->wasRecentlyCreated;

            return $b;
        });
        } catch (\Throwable $e) {
            // Taken / closed / limit: the files uploaded with it go too.
            foreach ($answers as $a) {
                if (is_array($a) && ! empty($a['file'])) {
                    @unlink(upload_public_path(self::FILE_DIR).'/'.basename((string) $a['file']));
                }
            }
            throw $e;
        }
        app(BookingCrm::class)->capture($booking->fresh(['product', 'staff']), ! $this->newClient);

        event(new BookingChanged($booking, 'requested', null, null));
        if ($booking->status === 'confirmed') {
            event(new BookingChanged($booking, 'confirmed', 'pending', null));
        }

        return $booking->fresh();
    }

    /** Customer cancels from the status page (Booking Settings › customers can cancel online). */
    public function cancelByCustomer(Booking $b): Booking
    {
        if (! $this->canCancel($b)) {
            throw new BookingWebsiteException(__('messages.BkmErrCannotCancel'), 422);
        }
        try {
            return app(BookingWorkflow::class)->cancel($b, null, __('messages.BkmCancelledByCustomerReason'), 'customer');
        } catch (BookingWorkflowException $e) {
            throw new BookingWebsiteException(__('messages.BkmErrCannotCancel'), 422);
        }
    }

    public function canCancel(Booking $b): bool
    {
        $s = $this->settings();
        if (! $s->customer_cancel || ! in_array($b->status, ['pending', 'confirmed'], true)) {
            return false;
        }
        $starts = Carbon::createFromFormat('Y-m-d H:i', Carbon::parse($b->booking_date)->toDateString().' '.substr((string) $b->booking_time, 0, 5), config('app.timezone'));
        // A request without a time yet can always be withdrawn.
        if ($b->status === 'pending' && $b->booking_mode === 'request' && ! $b->confirmed_at) {
            return true;
        }
        if ($starts->isPast()) {
            return false;
        }
        // Waiting for an answer with nothing paid: free to withdraw until it starts.
        if ($b->status === 'pending' && ! (Booking::depositsReady() && $b->deposit_status === 'paid')) {
            return true;
        }

        return now()->addHours((int) $s->cancel_notice_hours)->lte($starts);
    }

    /** What the status page tells the customer. */
    public function stage(Booking $b): string
    {
        return match ($b->status) {
            'pending' => $b->booking_mode === 'request' && ! $b->confirmed_at ? 'requested' : 'pending',
            'confirmed', 'arrived' => 'confirmed',
            'completed' => 'completed',
            'declined' => 'declined',
            'no_show' => 'no_show',
            default => 'cancelled',
        };
    }

    /** "45 min", "1 h", "1 h 30 min" in the visitor's language. */
    public static function durationLabel(int $minutes): string
    {
        $h = intdiv(max(0, $minutes), 60);
        $m = max(0, $minutes) % 60;
        if (! $h) {
            return __('messages.BkmDurMin', ['n' => $m]);
        }

        return $m ? __('messages.BkmDurHoursMin', ['h' => $h, 'm' => $m]) : __('messages.BkmDurHours', ['h' => $h]);
    }

    /** Uploaded form files are kept here (public, random names). */
    public static function fileUrl(string $file): string
    {
        return url(upload_path(self::FILE_DIR).'/'.$file);
    }

    // ------------------------------------------------------------------

    /** ['type', 'value', 'one' => amount for one person] when picking a free time asks for a deposit. */
    private function depositInfo(BookingDepositService $deposits, int $productId, float $price): ?array
    {
        $one = $deposits->amountFor($productId, $price, 1);
        if (! $deposits->wantedFor('slot', $one) || $deposits->gateways() === []) {
            return null;
        }
        $rule = $deposits->ruleFor($productId);

        return ['type' => $rule['type'], 'value' => $rule['value'], 'one' => $one, 'label' => BookingDepositService::money($one)];
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
            throw new BookingWebsiteException(__('messages.BkmErrCannotBookNow'), 503);
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

    /** field id → value (files saved: {file, name}); blanks and unticked boxes left out. */
    private function answers(array $fields, array $input, array $files): array
    {
        $out = [];
        foreach ($fields as $f) {
            if ($f['input'] === 'file') {
                $file = $files[$f['id']] ?? null;
                if ($file instanceof UploadedFile && $file->isValid()) {
                    $ext = strtolower((string) $file->guessExtension());
                    $ext = ['jpeg' => 'jpg'][$ext] ?? $ext;
                    if (! in_array($ext, ['jpg', 'png', 'webp', 'pdf'], true)) {
                        throw new BookingWebsiteException(__('messages.BkmErrFileType'), 422, 'field_files.'.$f['id']);
                    }
                    $dir = upload_public_path(self::FILE_DIR);
                    if (! is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    $name = Str::random(32).'.'.$ext;
                    $file->move($dir, $name);
                    $out[$f['id']] = ['file' => $name, 'name' => Str::limit($file->getClientOriginalName(), 120, '')];
                }
                continue;
            }
            $v = BookingTypes::coerce($f, $input[$f['id']] ?? null);
            if ($v === null || $v === '' || ($f['input'] === 'bool' && $v === false)) {
                continue;
            }
            $out[$f['id']] = $v;
        }

        return $out;
    }

    /** Accent colour for the theme (business type, or the store's own primary colour). */
    public static function accent(?string $primary): string
    {
        $default = BookingTheme::accent();
        $p = strtolower((string) $primary);
        // Stock defaults of the other themes read as "unset" here.
        if ($p === '' || in_array($p, ['#6c5ce7', '#3b82f6', '#2563eb', '#7b6fd0', '#1a7f6b', '#16a34a', '#8a1c2b', '#c2410c', '#b4441f'], true) || ! preg_match('/^#[0-9a-f]{6}$/', $p)) {
            return $default;
        }

        return $p;
    }
}
