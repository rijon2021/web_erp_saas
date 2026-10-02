<?php

/**
 * Get the base image upload path for the current context.
 *
 * Tenant context:  images/tenants/{tenant_id}/{subfolder}
 * Super admin:     images/super/{subfolder}
 */
if (!function_exists('upload_path')) {
    function upload_path(string $subfolder = ''): string
    {
        if (function_exists('tenancy') && tenancy()->initialized && tenancy()->tenant) {
            $base = 'images/tenants/' . tenancy()->tenant->id;
        } else {
            $base = 'images/super';
        }

        return $subfolder ? $base . '/' . trim($subfolder, '/') : $base;
    }
}

/**
 * Get the absolute public path for uploads.
 */
if (!function_exists('upload_public_path')) {
    function upload_public_path(string $subfolder = ''): string
    {
        return public_path(upload_path($subfolder));
    }
}

/**
 * Root-relative URL of a company / branch logo that is guaranteed to exist.
 *
 * Logos are stored by filename and served from upload_path('settings'), but
 * older tenants (and branch logos saved before they moved next to the company
 * logo) may still hold the file in the tenant root or the shared public/images
 * folder — and a tenant provisioned without a default logo has no file at all.
 * A receipt <img> pointing at a missing file prints as a broken-image box, so
 * the receipt APIs pass the stored filename through here and ship a URL to
 * the first location that really has the file, falling back to the platform
 * default logo. The SPA's uploadUrl() passes root-relative URLs through.
 */
if (!function_exists('receipt_logo_url')) {
    function receipt_logo_url(?string $file, string $default = 'logo-default.png'): ?string
    {
        $file = trim((string) $file);
        if ($file !== '' && preg_match('#^(https?:)?//|^data:|^/#i', $file)) {
            return $file; // already a URL
        }

        $names = array_values(array_unique(array_filter([$file, $default])));
        foreach ($names as $name) {
            $candidates = [
                upload_path('settings') . '/' . $name,
                upload_path() . '/' . $name,
                'images/settings/' . $name,
                'images/' . $name,
            ];
            foreach ($candidates as $relative) {
                if (is_file(public_path($relative))) {
                    return '/' . ltrim(str_replace('\\', '/', $relative), '/');
                }
            }
        }

        return null;
    }
}

/**
 * Resolve the URL of a tenant's PWA icon (192 or 512).
 *
 * Tenants get their own icons under images/tenants/{id}/pwa/ generated at
 * provisioning from the super admin's Tenant-Settings logo. When a tenant has
 * no custom icon yet (older tenants, or no logo configured) we fall back to the
 * shared defaults in /pwa_images so the manifest is always valid.
 */
if (!function_exists('pwa_icon_url')) {
    function pwa_icon_url(int $size): string
    {
        $relative = upload_path('pwa') . '/pwa-icon-' . $size . '.png';
        $absolute = public_path($relative);

        if (is_file($absolute)) {
            return global_asset($relative) . '?v=' . filemtime($absolute);
        }

        return '/pwa_images/pwa-icon-' . $size . '.png';
    }
}

/**
 * Favicon for tenant pages that have no icon of their own (the storefront when
 * Store Settings has no favicon). Order: the tenant's app favicon under
 * images/tenants/{id}/settings (provisioning seeds it from the Super Admin
 * branding), then the Super Admin tenant default, then the platform file.
 */
if (!function_exists('tenant_favicon_url')) {
    function tenant_favicon_url(): ?string
    {
        try {
            $file = trim((string) \App\Models\Setting::query()->value('favicon')) ?: 'favicon.ico';
            $relative = upload_path('settings') . '/' . $file;
            if (is_file(public_path($relative))) {
                return global_asset($relative) . '?v=' . filemtime(public_path($relative));
            }
        } catch (\Throwable) {
            // No tenant settings table (central context) — fall through.
        }

        try {
            if ($url = \App\Models\Central\GeneralSetting::instance()->getTenantFaviconUrl()) {
                return $url;
            }
        } catch (\Throwable) {
        }

        return is_file(public_path('images/favicon.ico')) ? '/images/favicon.ico' : null;
    }
}
