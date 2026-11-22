<?php

namespace App\Console\Commands;

use App\Mail\LicenseExpiryReminderMail;
use App\Models\Client;
use App\Models\LicenseKey;
use App\Models\LicenseSetting;
use App\Models\OnlineOrder;
use App\Services\LicenseKeys\LicenseFulfilment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Daily: email customers whose generated licenses expire soon (License Keys ›
 * Settings › reminder days, default "30,7"). Each reminder window is sent
 * once: reminded_at is compared with the start of the current window, and an
 * extension clears it.
 */
class LicenseExpiryReminders extends Command
{
    protected $signature = 'licenses:send-reminders';

    protected $description = 'Email customers whose software licenses expire soon';

    public function handle(): int
    {
        foreach (\App\Tenant::all() as $tenant) {
            try {
                tenancy()->initialize($tenant);
                // Links in the email ("View my licenses") must use the tenant's host, not APP_URL.
                \App\Support\BookingModule::useTenantUrl();
                LicenseSetting::forgetMemo();
                if (! LicenseFulfilment::ready()) {
                    continue;
                }
                $settings = LicenseSetting::current();
                $offsets = $settings ? $settings->reminderOffsets() : [];
                if (! $offsets || ! \App\Services\LicenseKeys\TenantMailer::configure()) {
                    continue;
                }

                $sent = 0;
                LicenseKey::with(['product:id,name', 'variant:id,name'])
                    ->where('source', LicenseKey::SOURCE_GENERATED)
                    ->where('status', LicenseKey::STATUS_SOLD)
                    ->whereNotNull('expires_at')
                    ->whereBetween('expires_at', [now(), now()->addDays(max($offsets))->endOfDay()])
                    ->orderBy('id')
                    ->chunkById(200, function ($keys) use ($offsets, &$sent) {
                        foreach ($keys as $key) {
                            $daysLeft = (int) ceil(now()->diffInSeconds($key->expires_at, true) / 86400);
                            $window = collect($offsets)->filter(fn ($o) => $o >= $daysLeft)->min();
                            if ($window === null) {
                                continue;
                            }
                            $windowStart = $key->expires_at->copy()->subDays($window);
                            if ($key->reminded_at && $key->reminded_at->gte($windowStart)) {
                                continue;
                            }
                            $to = $this->recipient($key);
                            if (! $to) {
                                continue;
                            }
                            try {
                                Mail::to($to['email'])->send(new LicenseExpiryReminderMail($key, $to['name'], $daysLeft));
                                $key->forceFill(['reminded_at' => now()])->save();
                                app(LicenseFulfilment::class)->event($key->id, 'reminded', $to['email'], null, ['days_left' => $daysLeft]);
                                $sent++;
                            } catch (\Throwable $e) {
                                $this->warn("key {$key->id}: ".$e->getMessage());
                            }
                        }
                    });

                if ($sent) {
                    $this->line("tenant {$tenant->id}: {$sent} reminder(s)");
                }
            } catch (\Throwable $e) {
                $this->warn("tenant {$tenant->id}: ".$e->getMessage());
                report($e);
            } finally {
                try {
                    tenancy()->end();
                } catch (\Throwable $e) {
                    // Nothing to clean up.
                }
            }
        }

        return self::SUCCESS;
    }

    private function recipient(LicenseKey $key): ?array
    {
        $order = $key->online_order_id ? OnlineOrder::find($key->online_order_id) : null;
        $client = $key->client_id ? Client::find($key->client_id) : null;
        $email = $order?->customer_email ?: $client?->email;
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return ['email' => $email, 'name' => $order?->customer_name ?: ($client?->name ?: '')];
    }
}
