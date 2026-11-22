<?php

namespace App\Services\Marketing;

use App\Mail\MarketingCampaignEmail;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingSetting;
use App\Models\Setting;
use App\Services\WhatsApp\MetaCloudDriver;
use Carbon\Carbon;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Builds the recipient list for a campaign and delivers it across the
 * configured channel (sms / email / whatsapp). Designed to be safe by default:
 * SMS/WhatsApp are only transmitted when the relevant provider is enabled and
 * configured in Marketing Settings; otherwise recipients are marked "skipped".
 */
class MarketingDispatcher
{
    protected $resolver;

    /** Set by configureMail(): the tenant has its own mail server configured. */
    protected bool $mailReady = false;

    public function __construct(SegmentResolver $resolver = null)
    {
        $this->resolver = $resolver ?: new SegmentResolver;
    }

    /**
     * Resolve the campaign audience and (re)create its recipient rows.
     */
    public function buildRecipients(MarketingCampaign $campaign): int
    {
        // Clear any previous recipient rows (e.g. a re-send).
        MarketingCampaignRecipient::where('campaign_id', $campaign->id)->delete();

        $leads = false;
        if ($campaign->all_customers || ! $campaign->segment_id) {
            $clients = $this->resolver->resolve(true, []);
        } else {
            $segment = $campaign->segment;
            $clients = $segment
                ? $this->resolver->resolveSegment($segment)
                : $this->resolver->resolve(true, []);
            // A CRM lead segment: recipients are leads, not clients.
            $leads = $segment && $segment->audience === 'crm_leads';
        }

        $now = Carbon::now();
        $rows = [];
        foreach ($clients as $client) {
            $rows[] = ($leads ? ['crm_lead_id' => $client->id] : []) + [
                'campaign_id'   => $campaign->id,
                'client_id'     => $leads ? null : $client->id,
                'name'          => $this->clientName($client),
                'phone'         => $client->phone,
                'email'         => $client->email,
                'status'        => 'pending',
                'error_message' => null,
                'sent_at'       => null,
                'delivered_at'  => null,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            MarketingCampaignRecipient::insert($chunk);
        }

        $count = count($rows);
        $campaign->update([
            'total_recipients' => $count,
            'pending_count'    => $count,
            'sent_count'       => 0,
            'delivered_count'  => 0,
            'failed_count'     => 0,
        ]);

        return $count;
    }

    /**
     * Build the recipient list when there is nothing left to send: either a
     * first send (no recipients yet) or a manual re-send (all prior recipients
     * already processed). buildRecipients() resets the list, so a re-send
     * re-resolves the audience and retries from scratch, while a stuck campaign
     * with pending rows keeps them and resumes where it stopped.
     */
    public function prepareRecipients(MarketingCampaign $campaign): void
    {
        $hasPending = MarketingCampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'pending')->exists();

        if (! $hasPending) {
            $this->buildRecipients($campaign);
        }
    }

    /**
     * Deliver a campaign to all of its pending recipients.
     */
    public function dispatchCampaign(MarketingCampaign $campaign): void
    {
        $campaign->status = 'sending';
        $campaign->touch();

        $this->prepareRecipients($campaign);

        $settings = MarketingSetting::current();

        if ($campaign->type === 'email') {
            $this->mailReady = $this->configureMail($settings);
        }

        $sent = 0;
        $failed = 0;

        MarketingCampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(max(1, (int) $settings->batch_size), function ($recipients) use ($campaign, $settings, &$sent, &$failed) {
                foreach ($recipients as $recipient) {
                    $this->sendToRecipient($campaign, $recipient, $settings, $sent, $failed);
                }
                // Live progress, and the heartbeat MarketingCampaign::is_stuck reads.
                $campaign->fill($this->recipientCounts($campaign))->touch();
            });

        $campaign->refresh();
        $counts = $this->recipientCounts($campaign);

        $campaign->update($counts + [
            'status'  => $counts['failed_count'] > 0 && $counts['sent_count'] === 0 ? 'failed' : 'sent',
            'sent_at' => Carbon::now(),
        ]);
    }

    protected function recipientCounts(MarketingCampaign $campaign): array
    {
        $delivered = MarketingCampaignRecipient::where('campaign_id', $campaign->id)
            ->whereIn('status', ['sent', 'delivered'])->count();

        return [
            'sent_count'      => $delivered,
            'delivered_count' => $delivered,
            'failed_count'    => MarketingCampaignRecipient::where('campaign_id', $campaign->id)
                ->where('status', 'failed')->count(),
            'pending_count'   => MarketingCampaignRecipient::where('campaign_id', $campaign->id)
                ->whereIn('status', ['pending', 'skipped'])->count(),
        ];
    }

    protected function sendToRecipient(MarketingCampaign $campaign, MarketingCampaignRecipient $recipient, MarketingSetting $settings, &$sent, &$failed): void
    {
        $message = $this->personalize($campaign->message_content, $recipient);

        // A lead may have unsubscribed after the list was built.
        if ($recipient->crm_lead_id && \App\Models\CrmLead::whereKey($recipient->crm_lead_id)->where('marketing_opt_out', true)->exists()) {
            $this->markSkipped($recipient, 'Unsubscribed from marketing');
            return;
        }

        try {
            if ($campaign->type === 'email') {
                if (! $settings->email_enabled) {
                    $this->markSkipped($recipient, 'Email channel disabled');
                    return;
                }
                if (! $this->mailReady) {
                    // Never fall back to the platform's own mailer.
                    $this->markSkipped($recipient, 'No mail server configured (Settings > Mail Settings)');
                    return;
                }
                if (empty($recipient->email)) {
                    $this->markSkipped($recipient, 'No email address');
                    return;
                }
                $this->sendEmail($campaign, $recipient, $message, $settings);
            } elseif ($campaign->type === 'sms') {
                if (! $settings->sms_enabled || empty($settings->sms_api_url)) {
                    $this->markSkipped($recipient, 'SMS gateway not configured');
                    return;
                }
                if (empty($recipient->phone)) {
                    $this->markSkipped($recipient, 'No phone number');
                    return;
                }
                $this->sendHttp($settings, 'sms', $recipient->phone, $message);
            } elseif ($campaign->type === 'whatsapp') {
                if (! $settings->whatsapp_enabled || empty($settings->whatsapp_api_url)) {
                    $this->markSkipped($recipient, 'WhatsApp API not configured');
                    return;
                }
                if (empty($recipient->phone)) {
                    $this->markSkipped($recipient, 'No phone number');
                    return;
                }
                if (trim((string) $campaign->wa_template_name) !== '') {
                    // Meta-approved template: the only kind Meta delivers to
                    // customers outside their 24-hour messaging window.
                    if (! $this->isMetaCloud($settings)) {
                        throw new \RuntimeException('WhatsApp templates need the WhatsApp Cloud API (graph.facebook.com) in Marketing Settings.');
                    }
                    $this->sendMetaCloud($settings, $recipient->phone, $this->templateContent($campaign, $recipient));
                } else {
                    $this->sendHttp($settings, 'whatsapp', $recipient->phone, $message);
                }
            }

            $recipient->update([
                'status'  => 'sent',
                'sent_at' => Carbon::now(),
            ]);
            $sent++;
            if ($recipient->crm_lead_id) {
                $this->logOnLead($campaign, $recipient);
            }
        } catch (\Throwable $e) {
            Log::error('Marketing send failed (campaign '.$campaign->id.', recipient '.$recipient->id.'): '.$e->getMessage());
            $recipient->update([
                'status'        => 'failed',
                'error_message' => substr($e->getMessage(), 0, 500),
            ]);
            $failed++;
        }
    }

    protected function sendEmail(MarketingCampaign $campaign, MarketingCampaignRecipient $recipient, string $message, MarketingSetting $settings): void
    {
        $appSettings = Setting::where('deleted_at', '=', null)->first();
        $businessName = $settings->default_sender_name
            ?: ($appSettings->CompanyName ?? config('app.name'));

        $attachment = null;
        if (! empty($campaign->attachment)) {
            $path = public_path($campaign->attachment);
            if (is_file($path)) {
                $attachment = $path;
            }
        }

        // Personalised again with HTML-escaped values (the template itself is staff-written HTML).
        $body = nl2br($this->personalize($campaign->message_content, $recipient, true));
        // Lead emails always carry an unsubscribe link (customers are handled by the shop's own rules).
        if ($recipient->crm_lead_id && ! str_contains((string) $campaign->message_content, '{unsubscribe_url}')) {
            $body .= '<br><br><small style="color:#94a3b8">'.e(__('Don\'t want these emails?')).' <a href="'
                .e(\App\Services\Crm\CrmLeadSegmentResolver::unsubscribeUrl((int) $recipient->crm_lead_id)).'">'.e(__('Unsubscribe')).'</a></small>';
        }

        Mail::to($recipient->email)->send(new MarketingCampaignEmail([
            'subject'      => $this->personalize($campaign->subject ?: $campaign->title, $recipient),
            'body'         => $body,
            'company_name' => $businessName,
            'attachment'   => $attachment,
        ]));
    }

    /**
     * Generic configurable HTTP send for SMS / WhatsApp gateways.
     */
    protected function sendHttp(MarketingSetting $settings, string $channel, string $to, string $message): void
    {
        if ($channel === 'whatsapp' && $this->isMetaCloud($settings)) {
            $this->sendMetaCloud($settings, $to, [
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => $message],
            ]);

            return;
        }

        $url       = $channel === 'sms' ? $settings->sms_api_url : $settings->whatsapp_api_url;
        $method    = strtoupper($channel === 'sms' ? ($settings->sms_http_method ?: 'POST') : ($settings->whatsapp_http_method ?: 'POST'));
        $apiKey    = $this->cleanToken($channel === 'sms' ? $settings->sms_api_key : $settings->whatsapp_api_key);
        $toField   = ($channel === 'sms' ? $settings->sms_to_field : $settings->whatsapp_to_field) ?: 'to';
        $msgField  = ($channel === 'sms' ? $settings->sms_message_field : $settings->whatsapp_message_field) ?: 'message';
        $extra     = ($channel === 'sms' ? $settings->sms_extra_params : $settings->whatsapp_extra_params) ?: [];

        $payload = array_merge(is_array($extra) ? $extra : [], [
            $toField  => $to,
            $msgField => $message,
        ]);

        if ($channel === 'sms' && ! empty($settings->sms_sender_id)) {
            $payload['sender'] = $settings->sms_sender_id;
        }
        if ($channel === 'whatsapp' && ! empty($settings->whatsapp_phone_id)) {
            $payload['phone_id'] = $settings->whatsapp_phone_id;
        }

        $headers = ['Accept' => 'application/json'];
        if (! empty($apiKey)) {
            $headers['Authorization'] = 'Bearer '.$apiKey;
        }

        $options = ['headers' => $headers, 'http_errors' => false];
        if ($method === 'GET') {
            $options['query'] = $payload;
        } else {
            $options['json'] = $payload;
        }

        $client = new HttpClient(['timeout' => 30]);
        $response = $client->request($method, $url, $options);
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(ucfirst($channel).' gateway returned HTTP '.$status.': '.substr((string) $response->getBody(), 0, 300));
        }
    }

    /**
     * WhatsApp settings that point at Meta's Cloud API (graph.facebook.com),
     * by URL or by provider name. Those need Meta's own message shape; the
     * generic {to, message} body is rejected by the Graph API.
     */
    protected function isMetaCloud(MarketingSetting $settings): bool
    {
        $host = strtolower((string) parse_url((string) $settings->whatsapp_api_url, PHP_URL_HOST));
        if ($host === 'graph.facebook.com') {
            return true;
        }

        $provider = strtolower(trim((string) $settings->whatsapp_provider));

        return in_array($provider, ['meta', 'meta_cloud', 'meta cloud', 'whatsapp cloud', 'cloud api', 'facebook'], true);
    }

    /**
     * The `template` part of a Cloud API message for one recipient. Each stored
     * parameter fills the template's next body placeholder ({{1}}, {{2}}...)
     * after personalization; Meta rejects empty parameter text, so a blank
     * value (e.g. a customer with no name) is sent as "-".
     */
    protected function templateContent(MarketingCampaign $campaign, MarketingCampaignRecipient $recipient): array
    {
        $template = [
            'name' => trim((string) $campaign->wa_template_name),
            'language' => ['code' => trim((string) $campaign->wa_template_language) ?: 'en_US'],
        ];

        $params = is_array($campaign->wa_template_params) ? $campaign->wa_template_params : [];
        if ($params) {
            $template['components'] = [[
                'type' => 'body',
                'parameters' => array_map(function ($param) use ($recipient) {
                    $text = trim($this->personalize((string) $param, $recipient));

                    return ['type' => 'text', 'text' => $text !== '' ? $text : '-'];
                }, array_values($params)),
            ]];
        }

        return ['type' => 'template', 'template' => $template];
    }

    /**
     * Send one message through the WhatsApp Cloud API. $content is the
     * type-specific part: ['type' => 'text', 'text' => [...]] or
     * ['type' => 'template', 'template' => [...]].
     *
     * The endpoint is /{phone_number_id}/messages. Admins paste the URL in
     * different forms, so: a URL already ending in /messages is used as is;
     * otherwise the Phone ID field (or the URL's own last segment) completes it.
     */
    protected function sendMetaCloud(MarketingSetting $settings, string $to, array $content): void
    {
        $token = $this->cleanToken($settings->whatsapp_api_key);
        if ($token === '') {
            throw new \RuntimeException('WhatsApp Cloud API: access token is empty (Marketing Settings > WhatsApp API key).');
        }

        $url = rtrim(trim((string) $settings->whatsapp_api_url), '/');
        if (! preg_match('#/messages$#', $url)) {
            $phoneId = trim((string) $settings->whatsapp_phone_id);
            $url = $phoneId !== ''
                ? 'https://graph.facebook.com/'.MetaCloudDriver::GRAPH_VERSION.'/'.$phoneId.'/messages'
                : $url.'/messages';
        }

        $client = new HttpClient(['timeout' => 30]);
        $response = $client->request('POST', $url, [
            'http_errors' => false,
            'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token],
            'json' => [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                // E.164 digits only: Meta rejects "+", spaces and dashes.
                'to' => preg_replace('/\D+/', '', $to),
            ] + $content,
        ]);

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        $body = json_decode((string) $response->getBody(), true);
        $code = data_get($body, 'error.code');
        $detail = data_get($body, 'error.error_data.details') ?: data_get($body, 'error.message');
        $hint = match ((int) $code) {
            // The usual cause: the 24-hour token from Meta's "API Setup" page.
            190 => ' The access token is invalid or expired: use a permanent System User token (Business Settings > System users) with whatsapp_business_messaging permission.',
            132001 => ' Check the template name and language code match an APPROVED template in WhatsApp Manager exactly.',
            132000 => ' The number of template parameters must equal the template\'s {{n}} placeholders.',
            131047 => ' Free-text messages only reach customers who wrote in the last 24 hours; use an approved template.',
            default => '',
        };

        throw new \RuntimeException(
            'WhatsApp Cloud API returned HTTP '.$status
            .($code ? ' [code '.$code.']' : '')
            .': '.rtrim((string) ($detail ?: substr((string) $response->getBody(), 0, 300)), '. ')
            .($hint !== '' ? '.'.$hint : '')
        );
    }

    /**
     * API keys are pasted from provider dashboards: drop surrounding
     * whitespace/newlines and a copied "Bearer " prefix, either of which makes
     * an otherwise valid token fail authentication.
     */
    protected function cleanToken($value): string
    {
        $token = trim((string) $value);

        return trim(preg_replace('/^Bearer\s+/i', '', $token));
    }

    /**
     * Replace personalization variables in a message body.
     */
    public function personalize(?string $text, MarketingCampaignRecipient $recipient, bool $html = false): string
    {
        if ($text === null) {
            return '';
        }

        $client = $recipient->client;
        $lastPurchase = '';
        $totalSpent = '';
        if ($client) {
            // last_purchase / total_spent are not stored on the recipient; pull live stats lazily.
            $stats = DB::table('sales')
                ->where('client_id', $client->id)
                ->whereNull('deleted_at')
                ->where('statut', 'completed')
                ->selectRaw('MAX(date) as last_purchase, COALESCE(SUM(GrandTotal),0) as total_spent')
                ->first();
            $lastPurchase = $stats->last_purchase ?? '';
            $totalSpent = $stats->total_spent ?? '';
        }

        $lead = $recipient->crm_lead_id ? $recipient->lead : null;

        $vars = [
            '{customer_name}' => $recipient->name ?: '',
            '{first_name}'    => $lead ? ($lead->first_name ?: explode(' ', (string) $lead->name)[0]) : (optional($client)->firstname ?: explode(' ', (string) $recipient->name)[0]),
            '{company}'       => $lead ? (string) $lead->company_name : '',
            '{phone}'         => $recipient->phone ?: '',
            '{email}'         => $recipient->email ?: '',
            '{last_purchase}' => (string) $lastPurchase,
            '{total_spent}'   => (string) $totalSpent,
            '{unsubscribe_url}' => $lead ? \App\Services\Crm\CrmLeadSegmentResolver::unsubscribeUrl($lead->id) : '',
        ];
        // In an HTML email the values (names typed into public forms…) must not become markup.
        if ($html) {
            $vars = array_map(fn ($v) => e((string) $v), $vars);
        }

        return strtr($text, $vars);
    }

    /** "Received campaign …" on the lead's history (best effort). */
    protected function logOnLead(MarketingCampaign $campaign, MarketingCampaignRecipient $recipient): void
    {
        try {
            \App\Models\CrmLeadEvent::create([
                'crm_lead_id' => $recipient->crm_lead_id,
                'type'        => 'campaign',
                'meta'        => ['campaign' => $campaign->title, 'campaign_id' => $campaign->id, 'channel' => $campaign->type],
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function markSkipped(MarketingCampaignRecipient $recipient, string $reason): void
    {
        $recipient->update([
            'status'        => 'skipped',
            'error_message' => $reason,
        ]);
    }

    protected function clientName($client): string
    {
        $full = trim(($client->firstname ?? '').' '.($client->lastname ?? ''));

        return $full !== '' ? $full : ($client->name ?: '');
    }

    /**
     * Apply the SMTP credentials stored in the servers table (same source the
     * rest of Stocky uses for outgoing mail).
     */
    protected function configureMail(MarketingSetting $settings): bool
    {
        $server = DB::table('servers')->where('deleted_at', '=', null)->first();
        $appSettings = DB::table('settings')->where('deleted_at', '=', null)->first();

        if (! $server || ! $appSettings) {
            // No tenant mail server: callers must not send (no .env fallback).
            return false;
        }

        $fromEmail = $settings->email_from_address
            ?: (($server->sender_email ?? null) ?: $appSettings->email);
        $fromName = $settings->email_from_name
            ?: ($server->sender_name ?? config('app.name'));

        Config::set('mail', [
            'driver'     => $server->mail_mailer,
            'host'       => $server->host,
            'port'       => $server->port,
            'from'       => ['address' => $fromEmail, 'name' => $fromName],
            'encryption' => $server->encryption,
            'username'   => $server->username,
            'password'   => $server->password,
            'sendmail'   => '/usr/sbin/sendmail -bs',
            'pretend'    => false,
            'stream'     => [
                'ssl' => [
                    'allow_self_signed' => true,
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                ],
            ],
        ]);
        // Fresh mailer: marketing:process-scheduled sends for several tenants in
        // one process, and the cached transport would keep the previous SMTP.
        Mail::purge();

        return true;
    }
}
