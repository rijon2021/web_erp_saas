<?php

/*
|--------------------------------------------------------------------------
| WooCommerce sync — platform limits
|--------------------------------------------------------------------------
|
| Server-side limits shared by every tenant: HTTP timeouts and retries, stuck
| detection, polling. They depend on the host (PHP time limits, queue worker
| timeout), so they belong to whoever runs the server, not to a tenant.
|
| Always read these with config('woocommerce.…'), never env(): once
| `php artisan config:cache` has run, env() returns null outside config files
| and every call site would silently fall back to its own default.
|
| Store-specific tuning (batch sizes, lookup page caps, the alternate-SKU rule)
| is set per tenant on the WooCommerce settings tab — see
| WooCommerceSetting::tuning(). `tenant_limits` below caps what a tenant may set.
|
*/

return [

    // Developer use only (local tunnel / proxy): send every Woo API call to this
    // address, keeping the store's Host header. It applies to EVERY tenant, so
    // never set it on a production server.
    'api_base_url' => env('WOO_API_BASE_URL'),

    // Woo REST API
    'timeout_ms_batch' => (int) env('WOO_TIMEOUT_MS_BATCH', 30000),
    'http_retries' => (int) env('WOO_HTTP_RETRIES', 2),
    'http_retry_base_ms' => (int) env('WOO_HTTP_RETRY_BASE_MS', 300),
    'max_consecutive_failures' => (int) env('WOO_MAX_CONSECUTIVE_FAILURES', 7),

    // Sync run supervision
    'sync_stuck_seconds' => (int) env('WOO_SYNC_STUCK_SECONDS', 600),
    'sync_queue_wait_seconds' => (int) env('WOO_SYNC_QUEUE_WAIT_SECONDS', 1800),
    'poll_tick_max_seconds' => (int) env('WOO_POLL_TICK_MAX_SECONDS', 300),

    // WordPress media (large images on shared hosting)
    'wp_media' => [
        'search_timeout' => (int) env('WOO_WP_MEDIA_SEARCH_TIMEOUT', 15),
        'upload_timeout' => (int) env('WOO_WP_MEDIA_UPLOAD_TIMEOUT', 240),
        'retries' => (int) env('WOO_WP_MEDIA_RETRIES', 2),
        'retry_sleep_ms' => (int) env('WOO_WP_MEDIA_RETRY_SLEEP_MS', 500),
    ],

    // Remote lookups
    'remote_index_ttl' => (int) env('WOO_REMOTE_INDEX_TTL', 900),
    'pull_order_notes_max' => (int) env('WOO_PULL_ORDER_NOTES_MAX', 25),
    'pull_stats_page_cap' => (int) env('WOO_PULL_STATS_PAGE_CAP', 200),
    'autolink_page_cap' => (int) env('WOO_AUTOLINK_PAGE_CAP', 200),
    'pull_variations_page_cap' => (int) env('WOO_PULL_VARIATIONS_PAGE_CAP', 50),
    'delete_variations_page_cap' => (int) env('WOO_DELETE_VARIATIONS_PAGE_CAP', 50),
    'variations_list_page_cap' => (int) env('WOO_VARIATIONS_LIST_PAGE_CAP', 100),

    // Upper bounds for the per-tenant tuning, so one tenant cannot set batches
    // large enough to hold the shared queue workers for minutes at a time.
    'tenant_limits' => [
        'products_per_job' => (int) env('WOO_MAX_PRODUCTS_PER_JOB', 50),
        'stock_products_per_job' => (int) env('WOO_MAX_STOCK_PRODUCTS_PER_JOB', 100),
        'remote_index_page_cap' => (int) env('WOO_MAX_REMOTE_INDEX_PAGE_CAP', 2000),
        'sku_search_page_cap' => (int) env('WOO_MAX_SKU_SEARCH_PAGE_CAP', 50),
    ],

];
