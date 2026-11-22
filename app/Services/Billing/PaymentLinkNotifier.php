<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Central\CentralLanguage;
use App\Models\Central\EmailTemplate;
use App\Models\Central\GeneralSetting;
use App\Models\Central\PaymentLink;
use Database\Seeders\Central\EmailTemplatesSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers a payment link to its tenant.
 *
 * Email uses the editable "payment_link" template (Super > Email Templates).
 * It is sent even when that template is switched off, because the admin asked
 * for this specific email. WhatsApp is click-to-chat (wa.me): the platform has
 * no central WhatsApp sender — Cloud API credentials belong to each tenant.
 */
class PaymentLinkNotifier
{
    public static function formatAmount(PaymentLink $link): string
    {
        $amount = number_format((float) $link->amount, 2);

        return strtoupper($link->currency) === strtoupper(GeneralSetting::currencyCode())
            ? GeneralSetting::currencySymbol() . $amount
            : $amount . ' ' . strtoupper($link->currency);
    }

    /** The tenant's own locale, or null for the platform default. */
    public static function tenantLocale(PaymentLink $link): ?string
    {
        $locale = $link->tenant?->locale ?? null;

        return ($locale && $locale !== CentralLanguage::defaultLocale()) ? $locale : null;
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    public function sendEmail(PaymentLink $link, string $to): array
    {
        try {
            $template = EmailTemplate::with('translations')
                ->where('trigger_key', EmailTemplate::TRIGGER_PAYMENT_LINK)
                ->first();

            // Self-heal an install whose template row is missing (migration not run yet).
            if (! $template) {
                $template = EmailTemplate::create(array_merge(
                    (new EmailTemplatesSeeder())->paymentLinkTemplateRow(),
                    ['is_active' => true]
                ));
                $template->setRelation('translations', collect());
            }

            $tenant   = $link->tenant;
            $settings = GeneralSetting::instance();
            $locale   = self::tenantLocale($link);

            $variables = [
                '{{user_name}}'           => e($tenant?->company_name ?? $link->tenant_id),
                '{{user_email}}'          => $to,
                '{{amount}}'              => self::formatAmount($link),
                '{{payment_title}}'       => e($link->title),
                '{{payment_description}}' => nl2br(e((string) $link->description)),
                '{{payment_link}}'        => $link->url(),
                '{{link_expires}}'        => $link->expires_at
                    ? $link->expires_at->format('M d, Y H:i')
                    : trans('super.payment_links.no_expiry', [], $locale),
                '{{app_name}}'            => $settings->app_name ?: config('app.name', 'Stocky'),
                '{{app_url}}'             => config('app.url', 'http://localhost'),
            ];

            // The subject is plain text — HTML-escaping there would show "&amp;".
            $subject = $template->renderSubject(
                array_merge($variables, ['{{payment_title}}' => $link->title, '{{user_name}}' => $tenant?->company_name ?? $link->tenant_id]),
                $locale
            );
            $html    = $template->render($variables, $locale);

            Mail::send([], [], function ($message) use ($to, $subject, $html) {
                $message->to($to)->subject($subject)->html($html);
            });

            Log::info("PaymentLink {$link->id}: email sent to {$to}.");

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            Log::error("PaymentLink {$link->id}: email to {$to} failed — {$e->getMessage()}");

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Default WhatsApp text, in the tenant's language. */
    public function whatsappMessage(PaymentLink $link): string
    {
        $locale  = self::tenantLocale($link);
        $expires = $link->expires_at
            ? trans('super.payment_links.wa_expires_line', ['date' => $link->expires_at->format('M d, Y')], $locale)
            : '';

        return trim(trans('super.payment_links.wa_message', [
            'company' => $link->tenant?->company_name ?? $link->tenant_id,
            'title'   => $link->title,
            'amount'  => self::formatAmount($link),
            'url'     => $link->url(),
            'expires' => $expires,
        ], $locale));
    }

    /**
     * Digits-only international number for wa.me ("+44 20…", "0044…" → "4420…").
     * Returns null when what is left cannot be a phone number.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // A leading 0 is a national trunk prefix ("0300 1234567"): wa.me needs
        // the country code, and guessing it would message a stranger.
        if ($digits === '' || $digits[0] === '0') {
            return null;
        }

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
    }

    public function whatsappUrl(string $phoneDigits, string $message): string
    {
        return 'https://wa.me/' . $phoneDigits . '?text=' . rawurlencode($message);
    }
}
