<?php

namespace App\Listeners;

use App\Services\Opencart\AutoSync;

/**
 * Keeps OpenCart automatic sync honest across a tenant switch, like
 * ResetWooCommerceAutoSync: buffered ids are flushed against the tenant that
 * filled them, and the incoming tenant starts from nothing.
 */
class ResetOpencartAutoSync
{
    public function handle($event = null): void
    {
        try {
            AutoSync::flush();
        } catch (\Throwable $e) {
            // Must never break the tenancy switch; the scheduled reconcile
            // catches up with anything lost here.
            report($e);
        }

        AutoSync::reset();
    }
}
