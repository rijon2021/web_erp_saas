<?php

namespace App\Services\Crm;

use App\Mail\CustomEmail;
use App\Models\MarketingSetting;
use App\Models\Setting;
use App\Services\Marketing\MarketingDispatcher;
use App\Services\SmsOtpSender;
use App\Services\WhatsApp\WhatsAppGateway as WhatsAppService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Sends CRM email / SMS through the same plumbing as Marketing (its SMS
 * gateway and mail setup), falling back to Stocky's own SMS gateway. A thin
 * subclass, so the marketing code itself stays untouched.
 */
class CrmMessageTransport extends MarketingDispatcher
{
    /** Which channels can send right now. */
    public function availability(): array
    {
        $m = $this->marketing();
        $settings = Setting::whereNull('deleted_at')->first();

        return [
            'email' => DB::table('servers')->whereNull('deleted_at')->exists(),
            'sms' => ($m && $m->sms_enabled && $m->sms_api_url) || ($settings && $settings->sms_gateway),
            'whatsapp' => WhatsAppService::isActive(),
            // Per deal: the customer also needs an active portal account (checked on send).
            'portal' => \Illuminate\Support\Facades\Schema::hasTable('portal_clients'),
        ];
    }

    public function email(string $to, string $subject, string $body): void
    {
        $m = $this->marketing() ?? new MarketingSetting;
        if (! $this->configureMail($m)) {
            throw new \RuntimeException('No mail server is configured (Settings > Mail Settings).');
        }
        $company = optional(Setting::whereNull('deleted_at')->first())->CompanyName;
        Mail::to($to)->send(new CustomEmail([
            'subject' => $subject,
            'body' => nl2br(e($body)),
            'company_name' => $m->default_sender_name ?: $company,
        ]));
    }

    public function sms(string $to, string $body): void
    {
        $m = $this->marketing();
        if ($m && $m->sms_enabled && $m->sms_api_url) {
            $this->sendHttp($m, 'sms', $to, $body);

            return;
        }
        app(SmsOtpSender::class)->send($to, $body);
    }

    private function marketing(): ?MarketingSetting
    {
        try {
            return MarketingSetting::query()->first();
        } catch (\Throwable $e) {
            return null; // marketing tables not migrated
        }
    }
}
