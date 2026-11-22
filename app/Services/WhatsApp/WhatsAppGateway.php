<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappLog;
use App\Models\WhatsappSetting;
use App\Services\WhatsAppService;
use Throwable;

/**
 * Static entry points over the tenant WhatsAppService for the modules
 * ported from the single-tenant app (Rental Management, CRM), whose code
 * calls isActive() / normalizePhone() / sendNow() / queue().
 *
 * Every send goes through WhatsAppService::send(), so the super-admin
 * toggle, the connection check, the plan's monthly quota and the delivery
 * log all apply exactly as for the rest of the app.
 */
class WhatsAppGateway
{
    public static function isActive(): bool
    {
        try {
            return app(WhatsAppService::class)->isAvailable();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Digits only, international format. "00" prefixes are dropped and local
     * numbers (leading 0) get the configured default country code. Null when
     * nothing usable is left.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $raw = trim((string) $phone);
        if ($raw === '' || $raw === 'null') {
            return null;
        }
        $hasPlus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            return null;
        }
        if (! $hasPlus && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (! $hasPlus && str_starts_with($digits, '0')) {
            try {
                $cc = preg_replace('/\D+/', '', (string) (WhatsappSetting::instance()->default_country_code ?? ''));
            } catch (Throwable $e) {
                $cc = '';
            }
            if ($cc !== '') {
                $digits = $cc . ltrim($digits, '0');
            }
        }

        return strlen($digits) >= 7 ? $digits : null;
    }

    /**
     * Send right away and return the log row (status sent | failed, with
     * the provider error in `error`).
     */
    public static function sendNow(string $phone, string $body, array $meta = []): WhatsappLog
    {
        $service = app(WhatsAppService::class);
        $service->send($phone, $body, ['message_type' => 'text', 'meta' => $meta ?: null]);

        return $service->lastLog;
    }

    /**
     * Fire-and-forget send for automations (reminders). SaaS has no WhatsApp
     * queue, so this sends synchronously; null when the message could not be
     * sent (not connected, quota reached, provider error).
     */
    public static function queue(string $phone, string $body, array $meta = []): ?WhatsappLog
    {
        try {
            $log = self::sendNow($phone, $body, $meta);

            return $log->status === WhatsappLog::STATUS_FAILED ? null : $log;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
