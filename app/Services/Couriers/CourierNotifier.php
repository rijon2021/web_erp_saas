<?php

namespace App\Services\Couriers;

use App\Http\Controllers\BaseController;
use App\Mail\CustomEmail;
use App\Models\Client;
use App\Models\CourierSetting;
use App\Models\Sale;
use App\Models\Shipment;
use App\Models\WhatsappSetting;
use App\Models\WhatsappTemplate;
use App\Services\SmsOtpSender;
use App\Services\TenantLimitsService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the customer when their parcel moves (Courier Settings → Customer
 * notifications): SMS through the tenant's SMS gateway, email through the
 * tenant's mail server, WhatsApp through an approved template. Each status
 * is announced at most once per shipment (shipments.notified_statuses).
 *
 * Called by ShipmentUpdater after the status change is committed. In a web
 * request (webhook, staff action) the messages go out after the response,
 * so a courier webhook is acknowledged at once; on the CLI (couriers:sync)
 * they are sent on the spot. A failing channel never affects the others.
 */
class CourierNotifier
{
    /** Short words for email subjects / {status}. */
    public const STATUS_WORDS = [
        CourierStatus::BOOKED => 'shipped',
        CourierStatus::PICKED_UP => 'picked up',
        CourierStatus::IN_TRANSIT => 'on its way',
        CourierStatus::OUT_FOR_DELIVERY => 'out for delivery',
        CourierStatus::FAILED_ATTEMPT => 'delivery attempt failed',
        CourierStatus::DELIVERED => 'delivered',
        CourierStatus::RETURNING => 'being returned',
    ];

    public function statusChanged(Shipment $shipment, string $status): void
    {
        if (! CourierSetting::hasPhase7Columns()) {
            return;
        }
        $settings = CourierSetting::current();
        if (! $settings->notifies() || ! in_array($status, $settings->notifyStatuses(), true)) {
            return;
        }

        // Claim the status first, so a concurrent webhook + poll send once.
        $claimed = DB::transaction(function () use ($shipment, $status) {
            $row = Shipment::query()->lockForUpdate()->find($shipment->id);
            $done = (array) ($row?->notified_statuses ?? []);
            if (! $row || in_array($status, $done, true)) {
                return false;
            }
            $row->forceFill(['notified_statuses' => array_values(array_merge($done, [$status]))])->save();

            return true;
        });
        if (! $claimed) {
            return;
        }

        $send = fn () => $this->send($shipment->fresh(), $status, $settings);
        if (app()->runningInConsole()) {
            $send();
        } else {
            app()->terminating($send);
        }
    }

    /**
     * Send on every enabled channel now.
     *
     * @return array<string,string> channel => sent | skipped reason | error
     */
    public function send(?Shipment $shipment, string $status, CourierSetting $settings): array
    {
        if (! $shipment) {
            return [];
        }

        $results = [];
        try {
            $ctx = $this->context($shipment, $status);
            $text = $this->render($settings->template($status), $ctx['vars']);

            if ($settings->notify_sms) {
                $results['sms'] = $this->attempt(fn () => $this->sms($ctx['phone'], $text));
            }
            if ($settings->notify_email) {
                $results['email'] = $this->attempt(fn () => $this->email($ctx, $status, $text));
            }
            if ($settings->notify_whatsapp) {
                $results['whatsapp'] = $this->attempt(fn () => $this->whatsapp($ctx, $status));
            }

            $this->recordOnTimeline($shipment, $results);
        } catch (\Throwable $e) {
            Log::warning('Courier customer notification failed: ' . $e->getMessage(), ['shipment_id' => $shipment->id]);
        }

        return $results;
    }

    /** {placeholder} replacement; unknown placeholders are left as typed. */
    public function render(string $template, array $vars): string
    {
        $text = strtr($template, $vars);

        // Tidy the gaps an empty {tracking_url} / {cod_note} leaves behind.
        return trim(preg_replace(['/[ \t]{2,}/', '/\s+([.,!?])(?=\s|$)/'], [' ', '$1'], $text));
    }

    /**
     * Link for the customer: the storefront tracking page when it is on,
     * else the courier's own tracking page, else ''.
     */
    public static function trackingUrl(Shipment $shipment): string
    {
        $settings = CourierSetting::current();
        if ($shipment->tracking_number && $settings->trackingPageEnabled() && self::storeOnline()) {
            return self::storeUrl('track') . '?t=' . rawurlencode((string) $shipment->tracking_number);
        }

        return (string) ($shipment->trackingLink() ?? '');
    }

    // ------------------------------------------------------------------

    /** Recipient, links and placeholder values for one shipment. */
    private function context(Shipment $shipment, string $status): array
    {
        $sale = Sale::query()->find($shipment->sale_id);
        $client = $sale ? Client::query()->find($sale->client_id) : null;
        $consignee = (array) ($shipment->courier_meta['consignee'] ?? []);
        $business = (string) (DB::table('settings')->whereNull('deleted_at')->value('CompanyName') ?? '');
        $courier = (string) ($shipment->courierAccount?->name ?: $shipment->courier ?: 'the courier');
        $cod = (float) $shipment->cod_amount;
        $codText = $cod > 0 ? number_format($cod, 2) : '';
        $name = trim((string) ($shipment->delivered_to ?: ($client->name ?? '')));

        return [
            'phone' => (string) (($consignee['phone'] ?? '') ?: ($client->phone ?? '')),
            'email' => (string) (($consignee['email'] ?? '') ?: ($client->email ?? '')),
            'business' => $business,
            'vars' => [
                '{contact_name}' => $name !== '' ? $name : 'there',
                '{business_name}' => $business,
                '{invoice_number}' => (string) ($sale->Ref ?? $shipment->Ref ?? ''),
                '{courier}' => $courier,
                '{tracking_number}' => (string) ($shipment->tracking_number ?? ''),
                '{tracking_url}' => self::trackingUrl($shipment),
                '{cod_amount}' => $codText,
                '{cod_note}' => $codText !== '' ? ' Please keep ' . $codText . ' ready.' : '',
                '{status}' => self::STATUS_WORDS[$status] ?? $status,
            ],
        ];
    }

    private function sms(string $phone, string $text): string
    {
        if (trim($phone) === '') {
            return 'skipped: no phone';
        }
        app(SmsOtpSender::class)->send($phone, $text);

        return 'sent';
    }

    private function email(array $ctx, string $status, string $text): string
    {
        $to = trim($ctx['email']);
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return 'skipped: no email';
        }

        // The tenant's own mail server only — and a fresh mailer: on the CLI (couriers:sync
        // loops over tenants) the cached one would still hold the previous tenant's SMTP.
        if (! DB::table('servers')->whereNull('deleted_at')->exists()) {
            return 'skipped: no mail server configured';
        }
        (new BaseController)->Set_config_mail();
        Mail::purge();
        $subject = trim(($ctx['business'] !== '' ? $ctx['business'] . ': ' : '') . 'order ' . $ctx['vars']['{invoice_number}'] . ' ' . (self::STATUS_WORDS[$status] ?? $status));
        $body = nl2br(e($text));
        $url = $ctx['vars']['{tracking_url}'];
        if ($url !== '') {
            $body = str_replace(e($url), '<a href="' . e($url) . '">' . e($url) . '</a>', $body);
        }

        Mail::to($to)->send(new CustomEmail([
            'subject' => ucfirst($subject),
            'body' => '<p style="font-size:15px;line-height:1.6">' . $body . '</p>',
            'company_name' => $ctx['business'],
        ]));

        return 'sent';
    }

    /**
     * WhatsApp needs a template the tenant created (WhatsApp → Templates):
     * key "courier_<status>" (e.g. courier_out_for_delivery), else "courier_update".
     */
    private function whatsapp(array $ctx, string $status): string
    {
        $service = app(WhatsAppService::class);
        if (! $service->isAvailable()) {
            return 'skipped: WhatsApp not connected';
        }
        $key = WhatsappTemplate::query()->where('key', 'courier_' . $status)->where('is_active', true)->exists() ? 'courier_' . $status : 'courier_update';
        $to = $this->whatsappNumber($ctx['phone'], WhatsappSetting::instance()->default_country_code);
        if (! $to) {
            return 'skipped: no phone';
        }

        $v = $ctx['vars'];
        $ok = $service->sendTemplate($key, $to, [
            '{{customer_name}}' => $v['{contact_name}'],
            '{{invoice_number}}' => $v['{invoice_number}'],
            '{{courier}}' => $v['{courier}'],
            '{{tracking_number}}' => $v['{tracking_number}'],
            '{{tracking_url}}' => $v['{tracking_url}'],
            '{{cod_amount}}' => $v['{cod_amount}'],
            '{{status}}' => $v['{status}'],
        ]);

        return $ok ? 'sent' : 'failed: no active "' . $key . '" template or provider error';
    }

    /** Digits, local 0-prefixed numbers get the default country code (as WhatsAppNotifier). */
    private function whatsappNumber(string $phone, ?string $countryCode): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return null;
        }
        $cc = preg_replace('/\D+/', '', (string) $countryCode);

        return $cc !== '' && str_starts_with($digits, '0') ? $cc . ltrim($digits, '0') : $digits;
    }

    private function attempt(callable $fn): string
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return 'failed: ' . mb_substr($e->getMessage(), 0, 150);
        }
    }

    /** One timeline line ("Customer notified · SMS sent, Email skipped: no email"). */
    private function recordOnTimeline(Shipment $shipment, array $results): void
    {
        if (! $results) {
            return;
        }
        $summary = collect($results)->map(fn ($r, $ch) => strtoupper($ch === 'whatsapp' ? 'WhatsApp' : $ch) . ' ' . $r)->implode(' · ');
        app(ShipmentUpdater::class)->recordEvents($shipment, [
            new Data\TrackingEvent(null, 'Customer notified', now(), null, mb_substr($summary, 0, 500)),
        ], 'notification');
        foreach ($results as $channel => $r) {
            if (str_starts_with($r, 'failed')) {
                Log::warning("Courier customer {$channel} notification {$r}", ['shipment_id' => $shipment->id]);
            }
        }
    }

    private static function storeOnline(): bool
    {
        try {
            return (bool) DB::table('store_settings')->where('id', 1)->value('enabled')
                && app(TenantLimitsService::class)->hasFeature('online_orders');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Absolute storefront URL. Outside a request (couriers:sync) url() points
     * at APP_URL, so the tenant's own host is used instead.
     */
    private static function storeUrl(string $rel): string
    {
        if (! app()->runningInConsole() || ! function_exists('tenant') || ! tenant()) {
            return store_base_url($rel);
        }

        store_path(true); // per-tenant: the CLI loops over tenants
        $domain = strtolower((string) tenant()->domains()->value('domain'));
        if ($domain === '') {
            return store_base_url($rel);
        }
        if (! str_contains($domain, '.')) {
            $central = array_values(array_filter((array) config('tenancy.central_domains', [])))[0] ?? null;
            $domain = $central ? $domain . '.' . $central : $domain;
        }
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return $scheme . '://' . $domain . '/' . store_path_to($rel);
    }
}
