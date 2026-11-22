<?php

namespace App\Services;

/**
 * Client portal languages (Client Portal → Portal Languages): portal_languages
 * / portal_translations, serving the `portal` group that the portal SPA
 * fetches from /api/portal/translations/{locale}. All logic lives in
 * ScopedLanguageInstaller.
 */
class PortalLanguageInstaller extends ScopedLanguageInstaller
{
    public static function scope(): string
    {
        return 'portal';
    }

    public static function group(): string
    {
        return 'portal';
    }

    public static function packDir(): string
    {
        return 'portal_translations';
    }
}
