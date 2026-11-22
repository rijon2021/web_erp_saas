<?php

namespace App\Services\Booking;

use App\Events\BookingChanged;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\BookingSetting;
use App\Models\Setting;
use App\Models\StoreSetting;
use App\Services\WhatsApp\WhatsAppGateway;
use App\Support\BookingTypes;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails (and optional WhatsApp messages) about bookings, driven by
 * App\Events\BookingChanged and the reminder command. Booking Settings ›
 * Notifications decides what goes out:
 *
 *   customer (website bookings, "Email the customer"):
 *     received · confirmed (+ .ics) · declined · cancelled by staff ·
 *     time changed (+ .ics) · reminder before the visit (+ .ics)
 *     a suggested time always goes out (the customer has to answer it)
 *   owner ("Email me about every new website booking"):
 *     new website booking · cancelled by the customer · suggested time accepted / declined
 *
 * Only the tenant's own mail server is used (Settings › Mail, like every other
 * tenant email), never the platform mailer. WhatsApp goes through the tenant's
 * gateway when "Also send by WhatsApp" is on. Everything is best-effort and
 * logged in bk_messages (sent / failed / skipped). From a web request the
 * sending happens after the response, so a slow mail server never keeps the
 * visitor or staff waiting.
 */
class BookingNotifier
{
    private ?BookingSetting $settings = null;

    /** Listener for BookingChanged. */
    public function handle(BookingChanged $e): void
    {
        if (! Booking::notificationsReady()) {
            return;
        }
        $id = (int) $e->booking->id;
        $event = $e->event;
        if (app()->runningInConsole()) {
            $this->dispatch($event, $id);

            return;
        }
        $tenant = function_exists('tenant') ? tenant() : null;
        $run = fn () => (new self())->dispatch($event, $id);
        app()->terminating(fn () => $tenant ? $tenant->run($run) : $run());
    }

    /** Send what an event calls for. */
    public function dispatch(string $event, int $bookingId): void
    {
        try {
            $b = Booking::with(['customer', 'product', 'staff'])->find($bookingId);
            if (! $b) {
                return;
            }
            $s = $this->settings();
            $web = ($b->source ?? 'staff') === 'website';
            switch ($event) {
                case 'requested':
                    if ($web && $s->notify_owner) {
                        $this->toOwner('new_request', $b);
                    }
                    // Auto-confirmed bookings get the confirmation instead (its own event).
                    if ($web && $s->notify_customer && $b->status === 'pending') {
                        $this->toCustomer('received', $b);
                    }
                    break;
                case 'confirmed':
                case 'proposal_accepted':
                    if ($event === 'proposal_accepted' && $s->notify_owner) {
                        $this->toOwner('proposal_accepted', $b);
                    }
                    if ($web && $s->notify_customer) {
                        $this->toCustomer('confirmed', $b, true);
                    }
                    break;
                case 'declined':
                    if ($web && $s->notify_customer) {
                        $this->toCustomer('declined', $b);
                    }
                    break;
                case 'cancelled':
                    if ($b->cancelled_by === 'customer') {
                        // Portal customers can cancel staff-entered bookings too: tell the owner either way.
                        if ($s->notify_owner) {
                            $this->toOwner('cancelled_by_customer', $b);
                        }
                    } elseif ($web && $s->notify_customer) {
                        $this->toCustomer('cancelled', $b);
                    }
                    break;
                case 'rescheduled':
                    if ($web && $s->notify_customer && $b->status === 'confirmed') {
                        $this->toCustomer('rescheduled', $b, true);
                    }
                    break;
                case 'proposed':
                    if ($web) {
                        $this->toCustomer('proposal', $b);
                    }
                    break;
                case 'proposal_declined':
                    if ($web && $s->notify_owner) {
                        $this->toOwner('proposal_declined', $b);
                    }
                    break;
                case 'deposit_paid':
                    if ($web && $s->notify_owner) {
                        $this->toOwner('deposit_received', $b);
                    }
                    // Auto-confirmed on payment: the confirmation (its own event) says it.
                    if ($web && $s->notify_customer && $b->status !== 'confirmed') {
                        $this->toCustomer('deposit_paid', $b);
                    }
                    break;
                case 'deposit_paid_late':
                    // Money for a booking no longer waiting for it: the owner refunds it.
                    $this->toOwner('deposit_late', $b);
                    break;
                case 'deposit_requested':
                    if ($web) {
                        $this->toCustomer('deposit_requested', $b);
                    }
                    break;
            }
        } catch (\Throwable $e) {
            Log::warning("Booking notification ({$event}) failed for booking {$bookingId}: ".$e->getMessage());
        }
    }

    /**
     * Reminders for confirmed website bookings starting within the next
     * reminder_hours. A booking confirmed when it was already that close gets
     * none (the confirmation was the reminder). Returns how many were sent.
     */
    public function sendDueReminders(): int
    {
        $s = $this->settings();
        $hours = (int) $s->reminder_hours;
        if ($hours <= 0 || ! $s->notify_customer) {
            return 0;
        }
        $now = now();
        $until = $now->copy()->addHours($hours);
        $sent = 0;
        $candidates = Booking::with(['customer', 'product', 'staff'])->whereNull('deleted_at')
            ->where('status', 'confirmed')->where('source', 'website')->whereNull('reminder_sent_at')
            ->whereBetween('booking_date', [$now->toDateString(), $until->toDateString()])
            ->limit(500)->get();
        foreach ($candidates as $b) {
            $starts = $this->startsAt($b);
            if ($starts->lte($now) || $starts->gt($until)) {
                continue;
            }
            // Claim it first so two overlapping runs never send twice.
            $claimed = DB::table('bookings')->where('id', $b->id)->whereNull('reminder_sent_at')->update(['reminder_sent_at' => $now]);
            if (! $claimed) {
                continue;
            }
            if ($b->confirmed_at && Carbon::parse($b->confirmed_at)->gte($starts->copy()->subHours($hours))) {
                continue;
            }
            if ($this->toCustomer('reminder', $b, true)) {
                $sent++;
            }
        }

        return $sent;
    }

    // ------------------------------------------------------------------
    // Delivery
    // ------------------------------------------------------------------

    /** @return bool at least one channel delivered */
    private function toCustomer(string $kind, Booking $b, bool $withIcs = false): bool
    {
        $contact = (array) (($b->request_fields ?? [])['_contact'] ?? []);
        $email = ($contact['email'] ?? null) ?: optional($b->customer)->email;
        $phone = ($contact['phone'] ?? null) ?: optional($b->customer)->phone;
        $content = $this->content($kind, $b);
        $ok = false;

        if ($email) {
            $ok = $this->email($b, $kind, 'customer', $email, $content, $withIcs) || $ok;
        } else {
            $this->log($b, $kind, 'email', 'customer', null, 'skipped', 'No customer email.');
        }
        if ($this->settings()->whatsapp_notify) {
            $ok = $this->whatsapp($b, $kind, $phone, $content) || $ok;
        }

        return $ok;
    }

    private function toOwner(string $kind, Booking $b): void
    {
        $to = $this->ownerEmail();
        if (! $to) {
            $this->log($b, $kind, 'email', 'owner', null, 'skipped', 'No owner email (Booking Settings › Notifications, Store or company email).');

            return;
        }
        $this->email($b, $kind, 'owner', $to, $this->content($kind, $b), false);
    }

    private function email(Booking $b, string $kind, string $recipient, string $to, array $content, bool $withIcs): bool
    {
        if (! $this->configureMail()) {
            $this->log($b, $kind, 'email', $recipient, $to, 'skipped', 'No mail server set up (Settings › Mail).');

            return false;
        }
        try {
            $ics = $withIcs && in_array($b->status, ['confirmed', 'arrived'], true) ? BookingIcs::make($b) : null;
            Mail::to($to)->send(new BookingMail($content, $ics, BookingIcs::filename($b)));
            $this->log($b, $kind, 'email', $recipient, $to, 'sent');

            return true;
        } catch (\Throwable $e) {
            $this->log($b, $kind, 'email', $recipient, $to, 'failed', $e->getMessage());

            return false;
        }
    }

    private function whatsapp(Booking $b, string $kind, ?string $phone, array $content): bool
    {
        try {
            if (! WhatsAppGateway::isActive()) {
                $this->log($b, $kind, 'whatsapp', 'customer', $phone, 'skipped', 'WhatsApp is not connected.');

                return false;
            }
            $to = WhatsAppGateway::normalizePhone($phone);
            if (! $to) {
                $this->log($b, $kind, 'whatsapp', 'customer', $phone, 'skipped', 'No phone number that can receive WhatsApp.');

                return false;
            }
            $lines = ['*'.$content['title'].'*'];
            if (! empty($content['intro'])) {
                $lines[] = $content['intro'];
            }
            $lines[] = '';
            foreach ($content['lines'] as [$label, $value]) {
                $lines[] = $label.': '.$value;
            }
            if (! empty($content['button'])) {
                $lines[] = '';
                $lines[] = $content['button'][1];
            }
            $log = WhatsAppGateway::sendNow($to, implode("\n", $lines), ['source' => 'booking_'.$kind]);
            $failed = $log->status === \App\Models\WhatsappLog::STATUS_FAILED;
            $this->log($b, $kind, 'whatsapp', 'customer', $to, $failed ? 'failed' : 'sent', $failed ? ($log->error ?: 'WhatsApp send failed') : null);

            return ! $failed;
        } catch (\Throwable $e) {
            $this->log($b, $kind, 'whatsapp', 'customer', $phone, 'failed', $e->getMessage());

            return false;
        }
    }

    private function log(Booking $b, string $kind, string $channel, string $recipient, ?string $to, string $status, ?string $error = null): void
    {
        try {
            BookingMessage::create([
                'booking_id' => $b->id, 'kind' => $kind, 'channel' => $channel, 'recipient' => $recipient,
                'to' => $to ? mb_substr($to, 0, 191) : null, 'status' => $status,
                'error' => $error ? mb_substr($error, 0, 1000) : null, 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Booking message log failed: '.$e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Content
    // ------------------------------------------------------------------

    /** subject, title, intro, lines [[label, value]], button [label, url]?, note?, store, accent */
    public function content(string $kind, Booking $b): array
    {
        $store = $this->storeName();
        $service = optional($b->product)->name ?: ($b->product_name ?: __('messages.BkmBooking'));
        $vars = ['store' => $store, 'ref' => $b->Ref, 'service' => $service, 'amount' => BookingDepositService::money(
            Booking::depositsReady() && $b->depositDue() ? app(BookingDepositService::class)->remaining($b) : (float) ($b->deposit_paid ?: $b->deposit_amount)
        )];
        $link = $b->public_token ? store_base_url('appointment/'.$b->public_token) : null;
        $owner = in_array($kind, ['new_request', 'cancelled_by_customer', 'proposal_accepted', 'proposal_declined', 'deposit_received', 'deposit_late'], true);
        $deposits = Booking::depositsReady();
        $payDeposit = $deposits && ! $owner && $b->depositDue() && app(BookingDepositService::class)->payableNow($b)
            && in_array($kind, ['received', 'confirmed', 'deposit_requested', 'rescheduled', 'reminder'], true);

        $lines = [[__('messages.BkmMailRef'), $b->Ref], [__('messages.BkmService'), $service]];
        $lines[] = [$this->isOpenRequest($b) ? __('messages.BkmPreferred') : __('messages.BkmDateTime'), $this->when($b)];
        if ($b->staff && ! $this->isOpenRequest($b)) {
            $lines[] = [__('messages.BkmWith'), $b->staff->name];
        }
        if (($b->seats ?? 1) > 1) {
            $lines[] = [__('messages.BkmPeople'), (string) $b->seats];
        }
        if ($kind === 'proposal' && $b->proposed_date) {
            $lines[] = [__('messages.BkmMailSuggested'), $this->proposedWhen($b)];
        }
        if ($deposits && $b->deposit_status && (float) $b->deposit_amount > 0) {
            $lines[] = [__('messages.BkmDeposit'), match ($b->deposit_status) {
                'paid' => __('messages.BkmDepPaidFact', ['amount' => BookingDepositService::money((float) $b->deposit_paid)]),
                'refunded' => __('messages.BkmDepRefundedFact'),
                'waived' => __('messages.BkmDepWaivedFact'),
                default => __('messages.BkmDepDueFact', ['amount' => BookingDepositService::money((float) $b->deposit_amount)]),
            }];
        }
        if (in_array($kind, ['declined', 'cancelled'], true) && $b->cancel_reason) {
            $lines[] = [__('messages.BkmReason'), $b->cancel_reason];
        }
        if ($kind === 'deposit_late' && $b->deposit_note) {
            $lines[] = [__('messages.BkmMailMessage'), $b->deposit_note];
        }
        if ($kind === 'proposal' && $b->proposal_note) {
            $lines[] = [__('messages.BkmMailMessage'), $b->proposal_note];
        }
        if ($owner) {
            $contact = (array) (($b->request_fields ?? [])['_contact'] ?? []);
            $who = trim(implode(' · ', array_filter([
                ($contact['name'] ?? null) ?: optional($b->customer)->name,
                ($contact['phone'] ?? null) ?: optional($b->customer)->phone,
                ($contact['email'] ?? null) ?: optional($b->customer)->email,
            ])));
            $lines[] = [__('messages.BkmMailCustomer'), $who ?: '—'];
            if ($kind === 'new_request') {
                $defs = BookingTypes::allFields();
                foreach ((array) ($b->request_fields ?? []) as $key => $value) {
                    if (str_starts_with((string) $key, '_') || $value === null || $value === '') {
                        continue;
                    }
                    $f = $defs[$key] ?? ['id' => $key, 'label' => (string) $key, 'input' => 'text'];
                    $lines[] = [BookingTypes::fieldLabel($f), $this->answer($f, $value)];
                }
            }
        } else {
            $address = (string) (optional(StoreSetting::query()->first())->contact_address ?? '');
            if ($address !== '' && in_array($kind, ['confirmed', 'rescheduled', 'reminder'], true)) {
                $lines[] = [__('messages.BkmWhere'), $address];
            }
        }

        $content = [
            'subject' => __('messages.BkmMail_'.$kind.'_Subject', $vars),
            'title' => __('messages.BkmMail_'.$kind.'_Title', $vars),
            'intro' => __('messages.BkmMail_'.$kind.'_Intro', $vars),
            'lines' => $lines,
            'store' => $store,
            'accent' => BookingWebsiteService::accent(optional(StoreSetting::query()->first())->primary_color),
            'note' => null,
            'button' => null,
        ];
        if ($owner) {
            $content['button'] = [__('messages.BkmMailOpenAdmin'), url('/bookings/requests?id='.$b->id)];
        } elseif ($link) {
            $content['button'] = [$kind === 'proposal' ? __('messages.BkmMailAnswer') : ($payDeposit ? __('messages.BkmMailPayDeposit') : __('messages.BkmMailView')), $link];
            $content['note'] = __('messages.BkmMailFooterNote');
            if ($payDeposit && $kind !== 'deposit_requested') {
                $content['intro'] = trim($content['intro'].' '.__('messages.BkmMailDepositIntro', ['amount' => BookingDepositService::money((float) $b->deposit_amount)]));
            }
        }

        return $content;
    }

    private function answer(array $f, $value): string
    {
        return match (true) {
            $f['input'] === 'bool' => $value ? __('messages.BkmYes') : __('messages.BkmNo'),
            $f['input'] === 'choice' => BookingTypes::optionLabel((string) $value),
            $f['input'] === 'file' && is_array($value) && ! empty($value['file']) => ($value['name'] ?? $value['file']).' — '.BookingWebsiteService::fileUrl($value['file']),
            is_array($value) => implode(', ', array_map('strval', $value)),
            default => (string) $value,
        };
    }

    private function isOpenRequest(Booking $b): bool
    {
        return $b->booking_mode === 'request' && $b->status === 'pending' && ! $b->confirmed_at;
    }

    private function when(Booking $b): string
    {
        $d = Carbon::parse($b->booking_date)->locale(app()->getLocale());
        if ($this->isOpenRequest($b)) {
            return $d->translatedFormat('l j F Y').' · '.__('messages.BkmWindow_'.$b->preferred_window);
        }

        return $d->translatedFormat('l j F Y').', '.substr((string) $b->booking_time, 0, 5)
            .($b->booking_end_time ? ' – '.substr((string) $b->booking_end_time, 0, 5) : '');
    }

    private function proposedWhen(Booking $b): string
    {
        $d = Carbon::parse($b->proposed_date)->locale(app()->getLocale());
        $staff = $b->proposed_staff_id ? \App\Models\BookingStaff::withTrashed()->whereKey($b->proposed_staff_id)->value('name') : null;

        return $d->translatedFormat('l j F Y').', '.$b->proposed_time.($b->proposed_end_time ? ' – '.$b->proposed_end_time : '').($staff ? ' · '.$staff : '');
    }

    private function startsAt(Booking $b): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i', Carbon::parse($b->booking_date)->toDateString().' '.substr((string) $b->booking_time, 0, 5), config('app.timezone'));
    }

    private function settings(): BookingSetting
    {
        return $this->settings ??= BookingSetting::current();
    }

    private function storeName(): string
    {
        $store = StoreSetting::query()->first();

        return ($store->store_name ?? null) ?: (optional(Setting::whereNull('deleted_at')->first())->CompanyName ?: config('app.name'));
    }

    private function ownerEmail(): ?string
    {
        $s = $this->settings();
        if ($s->notify_owner_email) {
            return $s->notify_owner_email;
        }
        $store = StoreSetting::query()->first();

        return ($store->contact_email ?? null) ?: optional(Setting::whereNull('deleted_at')->first())->email;
    }

    /** Same mail setup as BaseController::Set_config_mail; false when the tenant has no mail server. */
    private function configureMail(): bool
    {
        $server = DB::table('servers')->whereNull('deleted_at')->first();
        $settings = Setting::whereNull('deleted_at')->first();
        if (! $server || ! $settings) {
            return false;
        }
        Config::set('mail', [
            'driver' => $server->mail_mailer,
            'host' => $server->host,
            'port' => $server->port,
            'from' => ['address' => ($server->sender_email ?? null) ?: $settings->email, 'name' => $server->sender_name ?: $this->storeName()],
            'encryption' => $server->encryption,
            'username' => $server->username,
            'password' => $server->password,
            'timeout' => (int) (config('mail.mailers.smtp.timeout') ?: 15),
            'sendmail' => '/usr/sbin/sendmail -bs',
            'pretend' => false,
            'stream' => ['ssl' => ['allow_self_signed' => true, 'verify_peer' => false, 'verify_peer_name' => false]],
        ]);
        $mailer = Mail::getFacadeRoot();
        if ($mailer && method_exists($mailer, 'purge')) {
            Mail::purge();
        }

        return true;
    }
}
