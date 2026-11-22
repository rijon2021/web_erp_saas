<?php

namespace App\Translation;

use App\Services\PortalLanguageInstaller;
use Illuminate\Contracts\Translation\Loader;

/**
 * Wraps the translation loader so the client portal's `portal` group is
 * served from the tenant's portal_translations rows (Client Portal → Portal
 * Languages / Translations) on top of resources/lang/{locale}/portal.php.
 * Every other group — and any key missing from the database — still comes
 * from the inner loader, and the translator's fallback locale (en) covers
 * keys a pack does not have yet.
 *
 * Rows live in the TENANT database; the line cache is tenant-prefixed by
 * PrefixCacheTenancyBootstrapper, and ResetTranslatorOnTenancySwitch drops
 * the translator's in-process copy when a worker changes tenant.
 */
class PortalTranslationLoader implements Loader
{
    public function __construct(protected Loader $inner)
    {
    }

    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->inner->load($locale, $group, $namespace);

        if ($group === PortalLanguageInstaller::group() && ($namespace === null || $namespace === '*')
            && function_exists('tenancy') && tenancy()->initialized) {
            $db = PortalLanguageInstaller::lines((string) $locale);
            if ($db) {
                $lines = array_replace(is_array($lines) ? $lines : [], $db);
            }
        }

        return $lines;
    }

    public function addNamespace($namespace, $hint)
    {
        $this->inner->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->inner->addJsonPath($path);
    }

    public function namespaces()
    {
        return $this->inner->namespaces();
    }

    /** Forward anything else (addPath, forgetVersions, …) to the wrapped loader. */
    public function __call($method, $args)
    {
        return $this->inner->{$method}(...$args);
    }
}
