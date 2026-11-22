<?php

namespace App\Services\Rental;

use App\Mail\CustomEmail;
use App\Models\Currency;
use App\Models\RentalContract;
use App\Models\RentalInvoice;
use App\Models\RentalReminder;
use App\Models\RentalSetting;
use App\Models\Setting;
use App\Services\SmsOtpSender;
use App\Services\WhatsApp\WhatsAppGateway as WhatsAppService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Customer reminders for the rental module, over email, SMS and WhatsApp:
 *
 *  rent_due           unpaid invoice due within `reminder_days_before` days — once per invoice
 *  rent_overdue       invoice past due + grace — repeated every `overdue_reminder_every_days`
 *  contract_expiring  active contract ending within `expiry_reminder_days`, not renewed — once
 *  return_due         active contract ending tomorrow with items still out — once
 *
 * Every attempt is logged in rental_reminders; the log is also what stops a
 * reminder from going out twice.
 */
class RentalReminderService
{
    private ?Setting $settings = null;
    private bool $mailConfigured = false;

    public function __construct(private RentalBillingService $billing)
    {
    }

    /** The scheduled run. Returns how many reminders were sent. */
    public function run(): int
    {
        $s = RentalSetting::current();
        $channels = $this->channels($s);
        if (! $s->reminders_enabled || ! $channels) {
            return 0;
        }

        $this->billing->run(); // today's invoices and late fees first
        $today = Carbon::today();
        $sent = 0;

        // Rent due soon.
        $until = $today->copy()->addDays(max(0, (int) $s->reminder_days_before));
        RentalInvoice::with('contract.client', 'contract.items')->whereIn('status', ['unpaid', 'partial'])
            ->whereBetween('due_date', [$today->toDateString(), $until->toDateString()])->get()
            ->each(function ($inv) use ($channels, &$sent) {
                foreach ($channels as $ch) {
                    if (! $this->already('rent_due', $ch, null, $inv->id)) {
                        $sent += (int) $this->send('rent_due', $ch, $inv->contract, $inv);
                    }
                }
            });

        // Overdue, repeated.
        $every = max(1, (int) $s->overdue_reminder_every_days);
        RentalInvoice::with('contract.client', 'contract.items')->whereIn('status', ['unpaid', 'partial'])
            ->where('due_date', '<', $today->toDateString())->get()
            ->filter(fn ($inv) => $inv->contract && $inv->isOverdue((int) $inv->contract->grace_days))
            ->each(function ($inv) use ($channels, $every, &$sent) {
                foreach ($channels as $ch) {
                    if (! $this->already('rent_overdue', $ch, null, $inv->id, Carbon::today()->subDays($every - 1))) {
                        $sent += (int) $this->send('rent_overdue', $ch, $inv->contract, $inv);
                    }
                }
            });

        // Contracts ending soon (not renewed).
        $soon = Carbon::now()->addDays(max(1, (int) $s->expiry_reminder_days));
        RentalContract::with('client', 'items')->whereNull('deleted_at')->where('status', 'active')
            ->whereNull('renewal_id')->whereNotNull('end_date')
            ->whereBetween('end_date', [Carbon::now(), $soon])->get()
            ->each(function ($c) use ($channels, &$sent) {
                foreach ($channels as $ch) {
                    if (! $this->already('contract_expiring', $ch, $c->id)) {
                        $sent += (int) $this->send('contract_expiring', $ch, $c);
                    }
                }
            });

        // Items to bring back tomorrow.
        RentalContract::with('client', 'items')->whereNull('deleted_at')->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [$today->copy()->addDay()->startOfDay(), $today->copy()->addDay()->endOfDay()])
            ->whereHas('items', fn ($q) => $q->whereNull('returned_at'))->get()
            ->each(function ($c) use ($channels, &$sent) {
                foreach ($channels as $ch) {
                    if (! $this->already('return_due', $ch, $c->id)) {
                        $sent += (int) $this->send('return_due', $ch, $c);
                    }
                }
            });

        return $sent;
    }

    /** Channels switched on in settings, minus WhatsApp when the integration is off. */
    public function channels(?RentalSetting $s = null): array
    {
        $s = $s ?? RentalSetting::current();
        $list = array_values(array_intersect((array) ($s->reminder_channels ?? []), RentalReminder::CHANNELS));

        return array_values(array_filter($list, fn ($c) => $c !== 'whatsapp' || WhatsAppService::isActive()));
    }

    /** Was this reminder already sent (optionally: since a moment)? Failed attempts don't count. */
    private function already(string $type, string $channel, ?int $contractId, ?int $invoiceId = null, ?Carbon $since = null): bool
    {
        return RentalReminder::where('type', $type)->where('channel', $channel)->where('status', 'sent')
            ->when($invoiceId, fn ($q) => $q->where('rental_invoice_id', $invoiceId))
            ->when(! $invoiceId && $contractId, fn ($q) => $q->where('rental_contract_id', $contractId))
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->exists();
    }

    /**
     * Render and send one reminder; logs the outcome. Returns true when sent.
     */
    public function send(string $type, string $channel, ?RentalContract $c, ?RentalInvoice $inv = null, bool $manual = false): bool
    {
        if (! $c) {
            return false;
        }
        $c->loadMissing('client', 'items');
        $client = $c->client;
        $recipient = $channel === 'email' ? optional($client)->email : optional($client)->phone;

        $template = RentalSetting::current()->reminderTemplate($type);
        $vars = $this->vars($c, $inv);
        $subject = $this->render($template['subject'], $vars);
        $body = $this->render($template['body'], $vars);

        $log = [
            'type' => $type,
            'channel' => $channel,
            'rental_contract_id' => $c->id,
            'rental_invoice_id' => optional($inv)->id,
            'rental_customer_id' => $c->rental_customer_id,
            'recipient' => $recipient,
            'message' => $channel === 'email' ? $subject . "\n\n" . $body : $body,
            'manual' => $manual,
            'user_id' => optional(auth()->user())->id,
        ];

        if (! $recipient) {
            $loggedToday = RentalReminder::where('type', $type)->where('channel', $channel)->where('status', 'skipped')
                ->where('rental_contract_id', $log['rental_contract_id'] ?? null)
                ->where('rental_invoice_id', $log['rental_invoice_id'] ?? null)
                ->where('created_at', '>=', Carbon::today())->exists();
            if (! $loggedToday) {
                RentalReminder::create($log + ['status' => 'skipped', 'error' => $channel === 'email' ? 'No email address' : 'No phone number']);
            }

            return false;
        }

        try {
            switch ($channel) {
                case 'email':
                    $this->configureMail();
                    Mail::to($recipient)->send(new CustomEmail([
                        'subject' => $subject,
                        'body' => nl2br(e($body)),
                        'company_name' => optional($this->settings())->CompanyName,
                    ]));
                    break;
                case 'sms':
                    app(SmsOtpSender::class)->send($recipient, $body);
                    break;
                case 'whatsapp':
                    $phone = WhatsAppService::normalizePhone($recipient);
                    if (! $phone) {
                        throw new \RuntimeException('This phone number cannot receive WhatsApp messages.');
                    }
                    $wa = WhatsAppService::sendNow($phone, $body, ['source' => 'rental_' . $type, 'rental_contract_id' => $c->id]);
                    if ($wa->status === \App\Models\WhatsappLog::STATUS_FAILED) {
                        throw new \RuntimeException($wa->error ?: 'WhatsApp send failed');
                    }
                    break;
            }
            RentalReminder::create($log + ['status' => 'sent']);

            return true;
        } catch (\Throwable $e) {
            RentalReminder::create($log + ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);

            return false;
        }
    }

    private function vars(RentalContract $c, ?RentalInvoice $inv): array
    {
        $s = $this->settings();
        $symbol = optional(Currency::find(optional($s)->currency_id))->symbol ?? '';
        $money = fn ($v) => trim($symbol . ' ' . number_format((float) $v, 2));
        $grace = (int) $c->grace_days;

        return [
            '{customer_name}' => optional($c->client)->name,
            '{contract_number}' => $c->contract_number,
            '{invoice_number}' => optional($inv)->invoice_number ?? '',
            '{amount}' => $inv ? $money($inv->balance()) : $money($c->total_amount),
            '{due_date}' => $inv ? $inv->due_date->format('M j, Y') : '',
            '{days_late}' => $inv && $inv->isOverdue($grace) ? (string) (int) $inv->due_date->copy()->addDays($grace)->diffInDays(Carbon::today()) : '0',
            '{end_date}' => $c->end_date ? $c->end_date->format('M j, Y H:i') : '',
            '{items}' => $c->items->pluck('item_name')->implode(', '),
            '{company_name}' => optional($s)->CompanyName,
            '{company_phone}' => optional($s)->CompanyPhone,
        ];
    }

    private function render(string $text, array $vars): string
    {
        return str_replace(array_keys($vars), array_map(fn ($v) => (string) $v, array_values($vars)), $text);
    }

    private function settings(): ?Setting
    {
        return $this->settings ??= Setting::whereNull('deleted_at')->first();
    }

    /** Same mail setup as BaseController::Set_config_mail (servers table). */
    private function configureMail(): void
    {
        if ($this->mailConfigured) {
            return;
        }
        $server = DB::table('servers')->whereNull('deleted_at')->first();
        $settings = $this->settings();
        if (! $server || ! $settings) {
            // SaaS: a tenant only mails through its own server, never the platform .env mailer.
            throw new \RuntimeException('No mail server is configured (Settings > Mail Settings).');
        }
        Config::set('mail', [
            'driver' => $server->mail_mailer,
            'host' => $server->host,
            'port' => $server->port,
            'from' => ['address' => ($server->sender_email ?? null) ?: $settings->email, 'name' => $server->sender_name],
            'encryption' => $server->encryption,
            'username' => $server->username,
            'password' => $server->password,
            'timeout' => (int) (config('mail.mailers.smtp.timeout') ?: 15),
            'sendmail' => '/usr/sbin/sendmail -bs',
            'pretend' => false,
            'stream' => ['ssl' => ['allow_self_signed' => true, 'verify_peer' => false, 'verify_peer_name' => false]],
        ]);
        // Fresh mailer: on the CLI the cached one would still hold the previous tenant's SMTP.
        Mail::purge();
        $this->mailConfigured = true;
    }
}
