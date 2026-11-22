<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Api\ScopedLanguagesController;
use App\Models\Sale;
use App\Services\PortalLanguageInstaller;
use App\Support\PortalSettings;
use Illuminate\Http\Request;

/**
 * Client Portal → Portal Languages and Portal Translations (admin side).
 *
 * Portal languages are independent from the back-office and storefront ones:
 * rows live in portal_languages / portal_translations and are served to
 * __('portal.*') and GET /api/portal/translations/{locale} by the translation
 * loader. The default portal language stays in Portal Settings. Gated by the
 * same `portal_settings` ability as Portal Settings.
 */
class PortalLanguagesController extends ScopedLanguagesController
{
    protected function installerClass(): string
    {
        return PortalLanguageInstaller::class;
    }

    protected function authorizeScope(Request $request): void
    {
        $this->authorizeForUser($request->user('api'), 'portal_settings', Sale::class);
    }

    protected function label(): string
    {
        return 'portal';
    }

    /** Portal Settings default, or English when none is set. */
    protected function defaultLocale(): string
    {
        return (string) (PortalSettings::defaultLocale() ?: 'en');
    }

    protected function applyDefault(string $locale): void
    {
        // save() normalizes against defaults — pass the full current settings.
        PortalSettings::save(array_merge(PortalSettings::all(), ['default_locale' => $locale]));
    }

    protected function extraIndex(): array
    {
        return ['visit_url' => url('/portal')];
    }
}
