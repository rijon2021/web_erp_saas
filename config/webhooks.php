<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Per-source HMAC Secrets for Incoming Webhooks
    |--------------------------------------------------------------------------
    | Map from source name (used in /api/webhooks/incoming/{source} on a
    | tenant domain) to the shared secret. Requests must include an
    | X-Webhook-Signature header (sha256=<hmac of the raw body>).
    |
    | If a source is omitted here, incoming requests for it are logged but
    | REFUSED (401) unless allow_unsigned is true (initial integration only).
    |
    | Note: this file is platform-wide — the same secret applies to that
    | source on every tenant.
    */
    'allow_unsigned' => env('WEBHOOKS_ALLOW_UNSIGNED', false),

    'incoming_secrets' => [
        // 'generic'     => env('WEBHOOKS_INCOMING_SECRET_GENERIC'),
        // 'woocommerce' => env('WEBHOOKS_INCOMING_SECRET_WOOCOMMERCE'),
        // 'quickbooks'  => env('WEBHOOKS_INCOMING_SECRET_QUICKBOOKS'),
    ],
];
