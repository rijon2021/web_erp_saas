<?php

namespace App\Services\Crm;

use App\Mail\CustomEmail;
use App\Models\CrmActivity;
use App\Models\CrmSetting;
use App\Models\Setting;
use App\Services\FcmService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Task reminders: an open activity with reminder_minutes is announced to its
 * owner (email and / or mobile push, per CRM Settings) that long before
 * due_at — once (reminded_at). Run by `crm:send-reminders` every few minutes.
 */
class CrmReminderService
{
    private bool $mailConfigured = false;

    /** @return int reminders sent */
    public function run(): int
    {
        $s = CrmSetting::current();
        if (! $s->reminder_email && ! $s->reminder_push) {
            return 0;
        }
        $now = Carbon::now();
        $sent = 0;

        CrmActivity::with('owner', 'lead', 'deal')->whereNull('deleted_at')
            ->where('status', 'open')->whereNotNull('due_at')->whereNotNull('reminder_minutes')->whereNull('reminded_at')
            ->whereNotNull('owner_id')
            // Not for things that were already more than a day late when the reminder came due.
            ->where('due_at', '>=', $now->copy()->subDay())
            ->get()
            ->filter(fn ($a) => $a->due_at->copy()->subMinutes((int) $a->reminder_minutes)->lte($now))
            ->each(function (CrmActivity $a) use ($s, &$sent) {
                // Claim it first, so two overlapping runs can't both send.
                $claimed = CrmActivity::whereKey($a->id)->whereNull('reminded_at')->update(['reminded_at' => now()]);
                if (! $claimed) {
                    return;
                }
                $title = $a->subject;
                $about = $a->deal ? $a->deal->title : optional($a->lead)->name;
                $body = trim(($about ? $about . ' — ' : '') . 'due ' . $a->due_at->format('Y-m-d H:i'));

                if ($s->reminder_email && $a->owner && $a->owner->email) {
                    try {
                        $this->configureMail();
                        Mail::to($a->owner->email)->send(new CustomEmail([
                            'subject' => 'Reminder: ' . $title,
                            'body' => nl2br(e($title . "\n" . $body . ($a->description ? "\n\n" . $a->description : ''))),
                            'company_name' => optional($this->settings())->CompanyName,
                        ]));
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
                if ($s->reminder_push && class_exists(FcmService::class)) { // no push service in SaaS yet
                    try {
                        app(FcmService::class)->sendToUsers([$a->owner_id], $title, $body, [
                            'type' => 'crm_activity', 'id' => (string) $a->id,
                        ]);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
                $sent++;
            });

        return $sent;
    }

    /** One-off email and / or push to a user (new captured lead, stale digest). Never throws. */
    public function notifyUser(?int $userId, string $title, string $body, array $data = [], bool $email = true, bool $push = true, ?string $htmlBody = null): void
    {
        $user = $userId ? \App\Models\User::whereNull('deleted_at')->find($userId) : null;
        if (! $user) {
            return;
        }
        if ($email && $user->email) {
            try {
                $this->configureMail();
                Mail::to($user->email)->send(new CustomEmail([
                    'subject' => $title,
                    'body' => $htmlBody ?? nl2br(e($body)),
                    'company_name' => optional($this->settings())->CompanyName,
                ]));
            } catch (\Throwable $e) {
                report($e);
            }
        }
        if ($push && class_exists(FcmService::class)) { // no push service in SaaS yet
            try {
                app(FcmService::class)->sendToUsers([$user->id], $title, mb_substr($body, 0, 240), array_map('strval', $data));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private ?Setting $settings = null;

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
