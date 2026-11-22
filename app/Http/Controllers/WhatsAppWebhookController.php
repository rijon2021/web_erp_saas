<?php

namespace App\Http\Controllers;

use App\Models\WhatsappLog;
use App\Models\WhatsappSetting;
use App\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public WhatsApp Cloud API webhook endpoint. Tenant-scoped via the URL
 * ({tenant}) so a single platform domain can serve every tenant's own
 * Meta app. No auth — GET is the Meta verification handshake, POST carries
 * message-status callbacks and is verified with the tenant's app secret.
 */
class WhatsAppWebhookController extends Controller
{
    /**
     * GET handshake: Meta echoes hub.challenge when the verify token matches.
     */
    public function verify(Request $request, string $tenant)
    {
        $tenantModel = Tenant::find($tenant);

        if (! $tenantModel) {
            abort(404);
        }

        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $expected = null;
        $tenantModel->run(function () use (&$expected) {
            $expected = optional(WhatsappSetting::first())->webhook_verify_token;
        });

        if ($mode === 'subscribe' && $expected && hash_equals((string) $expected, (string) $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        abort(403);
    }

    /**
     * POST callback: update delivery status for known message IDs.
     */
    public function handle(Request $request, string $tenant)
    {
        $tenantModel = Tenant::find($tenant);

        if (! $tenantModel) {
            // Always 200 so Meta does not retry indefinitely against a dead tenant.
            return response()->json(['ok' => true]);
        }

        $rawBody   = $request->getContent();
        $signature = $request->header('X-Hub-Signature-256');

        $tenantModel->run(function () use ($request, $rawBody, $signature) {
            $settings = WhatsappSetting::first();

            if (! $settings) {
                return;
            }

            // Verify the payload signature when an app secret is configured.
            if (! empty($settings->app_secret) && ! $this->signatureValid($rawBody, $signature, $settings->app_secret)) {
                Log::warning('WhatsApp webhook signature mismatch.');
                return;
            }

            $this->applyStatuses($request->input('entry', []));
            $this->applyInbound($request->input('entry', []));
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Validate the X-Hub-Signature-256 header against the raw request body.
     */
    protected function signatureValid(string $rawBody, ?string $header, string $appSecret): bool
    {
        if (! $header || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);

        return hash_equals($expected, $header);
    }

    /**
     * Walk the webhook payload and update matching log rows.
     */
    protected function applyStatuses(array $entries): void
    {
        $map = [
            'sent'      => WhatsappLog::STATUS_SENT,
            'delivered' => WhatsappLog::STATUS_DELIVERED,
            'read'      => WhatsappLog::STATUS_READ,
            'failed'    => WhatsappLog::STATUS_FAILED,
        ];

        foreach ($entries as $entry) {
            foreach (data_get($entry, 'changes', []) as $change) {
                foreach (data_get($change, 'value.statuses', []) as $status) {
                    $messageId = $status['id'] ?? null;
                    $state     = $map[$status['status'] ?? ''] ?? null;

                    if (! $messageId || ! $state) {
                        continue;
                    }

                    $log = WhatsappLog::where('provider_message_id', $messageId)->first();

                    if (! $log) {
                        continue;
                    }

                    // Never downgrade a terminal/read state back to sent.
                    if ($log->status === WhatsappLog::STATUS_READ && $state !== WhatsappLog::STATUS_FAILED) {
                        continue;
                    }

                    $log->status = $state;

                    if ($state === WhatsappLog::STATUS_FAILED) {
                        $log->error = data_get($status, 'errors.0.title', $log->error);
                    }

                    $log->save();
                }
            }
        }
    }

    /**
     * Incoming customer messages. Only stored when the CRM can use them (its
     * tables exist and the tenant's plan / module toggle allow it): the reply
     * lands on the matching lead / customer conversation in the CRM inbox.
     * Logged as message_type 'inbound', status 'received', sender in
     * `recipient`; Meta retries are de-duplicated on the message id.
     */
    protected function applyInbound(array $entries): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('crm_messages') || ! \App\Services\Crm\CrmCaptureService::enabled()) {
            return;
        }

        foreach ($entries as $entry) {
            foreach (data_get($entry, 'changes', []) as $change) {
                $names = [];
                foreach (data_get($change, 'value.contacts', []) as $contact) {
                    if (! empty($contact['wa_id'])) {
                        $names[$contact['wa_id']] = data_get($contact, 'profile.name');
                    }
                }

                foreach (data_get($change, 'value.messages', []) as $msg) {
                    $id = $msg['id'] ?? null;
                    $from = preg_replace('/\D+/', '', (string) ($msg['from'] ?? ''));
                    if (! $id || $from === '' || WhatsappLog::where('provider_message_id', $id)->exists()) {
                        continue;
                    }

                    $type = (string) ($msg['type'] ?? 'text');
                    $body = match ($type) {
                        'text' => (string) data_get($msg, 'text.body', ''),
                        'button' => (string) data_get($msg, 'button.text', ''),
                        'interactive' => (string) (data_get($msg, 'interactive.button_reply.title') ?? data_get($msg, 'interactive.list_reply.title', '')),
                        default => (string) (data_get($msg, $type . '.caption') ?: '[' . $type . ']'),
                    };

                    try {
                        $log = WhatsappLog::create([
                            'recipient' => $from,
                            'message_type' => 'inbound',
                            'body' => $body,
                            'status' => 'received',
                            'provider_message_id' => $id,
                            'meta' => ['direction' => 'in', 'name' => $names[$from] ?? null, 'type' => $type],
                            'sent_at' => isset($msg['timestamp']) ? \Carbon\Carbon::createFromTimestamp((int) $msg['timestamp']) : now(),
                        ]);
                        app(\App\Services\Crm\CrmMessageService::class)->matchInbound($log);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }
        }
    }
}
