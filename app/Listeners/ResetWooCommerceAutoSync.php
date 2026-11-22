<?php

namespace App\Listeners;

use App\Services\WooCommerce\AutoSync;

/**
 * Keeps WooCommerce automatic sync honest across a tenant switch — the Woo
 * twin of ResetShopifyStockWatcher.
 *
 * AutoSync keeps the tenant's settings and the product ids waiting to be
 * pushed in static memory for the life of the process. A scheduled sweep
 * (shopify:sync writes stock tenant by tenant), a queue worker or an
 * impersonation session can visit several tenants in a row, and tenant B must
 * neither inherit tenant A's switches nor push ids buffered under A to B's
 * shop.
 *
 * Registered on InitializingTenancy and EndingTenancy, which fire while the
 * OUTGOING tenant is still current: the buffer is flushed against the
 * database (and queued with the tenant) that filled it, and the incoming
 * tenant starts from nothing.
 */
class ResetWooCommerceAutoSync
{
    public function handle($event = null): void
    {
        try {
            AutoSync::flush();
        } catch (\Throwable $e) {
            // A flush that cannot reach the queue must never break the tenancy
            // switch it hangs off; the next manual stock push reconciles it.
            report($e);
        }

        AutoSync::reset();
    }
}
