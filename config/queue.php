<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue API supports an assortment of back-ends via a single
    | API, giving you convenient access to each back-end using the same
    | syntax for every one. Here you may define a default connection.
    |
    */

    // Use "database" by default so long-running sync can be cancelled.
    'default' => env('QUEUE_CONNECTION', 'database'),

    // --timeout (seconds) for the queue:work runs the app starts itself (the
    // scheduler and the no-cron sync ticks). Must exceed the slowest single
    // sync batch. Read with config('queue.worker_timeout'), never env().
    'worker_timeout' => (int) env('QUEUE_WORKER_TIMEOUT', 1200),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection information for each server that
    | is used by your application. A default configuration has been added
    | for each back-end shipped with Laravel. You are free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        // Tenant-aware: do not add 'central' => true. Stancl tenancy injects tenant_id into
        // job payload when jobs are dispatched from tenant context so the worker runs in the correct tenant DB.
        // The jobs TABLE must always live in the central DB: without pinning the connection,
        // tenant-context dispatches write to the tenant's own jobs table where the central
        // worker never looks, and the worker itself crashes if the central DB lacks the table.
        'database' => [
            'driver' => 'database',
            'connection' => 'central',
            'table' => 'jobs',
            'queue' => 'default',
            // Must be greater than the longest-running job, otherwise a slow job can be re-queued and run twice.
            // Longest job $timeout is 2000s (ProvisionTenantWorkspace; WooCommerce/Opencart/SendMarketingCampaign
            // are 1200s), so the value is floored at worker timeout + 60s and at 2060s even when an older .env
            // still says QUEUE_RETRY_AFTER=1200.
            'retry_after' => max(
                (int) env('QUEUE_RETRY_AFTER', 2100),
                (int) env('QUEUE_WORKER_TIMEOUT', 1200) + 60,
                2060
            ),
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => 'localhost',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => 0,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'your-queue-name'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => 90,
            'block_for' => null,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control which database and table are used to store the jobs that
    | have failed. You may change them to any database / table you wish.
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],

];
