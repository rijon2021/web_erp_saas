<?php

namespace App\Services\Webhooks;

use App\Models\WebhookIncomingLog;
use Illuminate\Http\Request;
use Throwable;

class IncomingWebhookService
{
    /**
     * Log every incoming webhook attempt, verify signature when a secret is
     * configured for the source, and return the persisted log record.
     */
    /** Stored payload cap — the endpoint is public, the log must not become a dump. */
    private const MAX_STORED_PAYLOAD = 262144;

    public function receive(Request $request, string $source): WebhookIncomingLog
    {
        $rawBody = $request->getContent();
        $headers = $this->sanitizeHeaders($request->headers->all());
        $signatureValid = $this->verifySignature($source, $request, $rawBody);

        $error = null;
        if (! $signatureValid) {
            $error = $this->getSourceSecret($source)
                ? 'Signature verification failed or missing.'
                : 'No secret configured for source "'.$source.'" — set one in config/webhooks.php (or WEBHOOKS_ALLOW_UNSIGNED=true while integrating).';
        }

        $log = WebhookIncomingLog::create([
            'source'          => mb_substr($source, 0, 100),
            'event'           => mb_substr((string) ($request->header('X-Webhook-Event') ?? $request->input('event')), 0, 191) ?: null,
            'ip'              => $request->ip(),
            'headers'         => $headers,
            // Refused requests keep only a short sample for debugging.
            'payload'         => mb_strcut($rawBody, 0, $signatureValid ? self::MAX_STORED_PAYLOAD : 8192, 'UTF-8'),
            'signature_valid' => $signatureValid,
            'status'          => $signatureValid
                ? WebhookIncomingLog::STATUS_RECEIVED
                : WebhookIncomingLog::STATUS_FAILED,
            'error_message'   => $error,
        ]);

        if ($signatureValid) {
            $this->process($log);
        }

        return $log;
    }

    protected function process(WebhookIncomingLog $log): void
    {
        try {
            $log->status = WebhookIncomingLog::STATUS_PROCESSED;
            $log->processed_at = now();
            $log->save();
        } catch (Throwable $e) {
            $log->status = WebhookIncomingLog::STATUS_FAILED;
            $log->error_message = $e->getMessage();
            $log->save();
            report($e);
        }
    }

    protected function verifySignature(string $source, Request $request, string $rawBody): bool
    {
        $secret = $this->getSourceSecret($source);
        if (!$secret) {
            // Unsigned requests are only trusted when explicitly allowed
            // (initial integration); otherwise anyone could post "events".
            return (bool) config('webhooks.allow_unsigned', false);
        }

        $header = $request->header('X-Webhook-Signature');
        if (!$header) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $header);
    }

    protected function getSourceSecret(string $source): ?string
    {
        $secrets = config('webhooks.incoming_secrets', []);
        return $secrets[$source] ?? null;
    }

    protected function sanitizeHeaders(array $headers): array
    {
        $blocked = ['authorization', 'cookie', 'x-api-key'];
        return collect($headers)
            ->reject(fn ($v, $k) => in_array(strtolower($k), $blocked, true))
            ->toArray();
    }
}
