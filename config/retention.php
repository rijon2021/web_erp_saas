<?php

/*
|--------------------------------------------------------------------------
| Backup & data retention — platform-level knobs
|--------------------------------------------------------------------------
|
| The per-tenant policy (backup frequency, how long backups / logs / message
| history / temporary data are kept) is set per subscription plan by the super
| admin — see Plan::RETENTION_FIELDS. These are the platform's own limits.
|
*/

return [

    // Skip scheduled tenant backups while the disk holding storage/ has less
    // free space than this, rather than fill it and take the app down.
    'min_free_disk_mb' => 1024,

    // Rows deleted per statement by tenants:prune-data. Small batches keep
    // each DELETE short so it never holds locks the app is waiting on.
    'prune_chunk' => 1000,

    // Central database housekeeping (run by tenants:prune-data).
    'central' => [
        // Resolved entries in the super-admin "Logs & Health" page.
        'resolved_system_logs_days' => 90,
        // Failed queue jobs (central failed_jobs table).
        'failed_jobs_days' => 30,
    ],

];
