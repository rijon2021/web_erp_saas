<?php

namespace App\Listeners;

use App\Support\PortalSettings;

/**
 * The translator memoizes every group it has loaded for the life of the
 * process, and the client portal's `portal` group is read from the TENANT
 * database (PortalTranslationLoader). A queue worker that renders a portal
 * mail for one tenant and then another would otherwise reuse the first
 * tenant's lines, so drop the memo whenever the tenant context changes.
 */
class ResetTranslatorOnTenancySwitch
{
    public function handle($event): void
    {
        try {
            $translator = app('translator');
            if (method_exists($translator, 'setLoaded')) {
                $translator->setLoaded([]);
            }
        } catch (\Throwable $e) {
            // translator not bound yet (early boot)
        }

        PortalSettings::forget();
    }
}
