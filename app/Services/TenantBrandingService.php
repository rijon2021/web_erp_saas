<?php

namespace App\Services;

use App\Models\Central\GeneralSetting;
use App\Models\Setting;
use App\Tenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Pushes the Super Admin's Tenant Settings branding (logo, favicon, app name)
 * to workspaces that already exist. Provisioning applies the same defaults to
 * new workspaces only (ProvisionTenantWorkspace::applyConfiguredTenantBranding),
 * so without this an existing tenant never picks up a later change.
 */
class TenantBrandingService
{
    public const PARTS = ['logo', 'favicon', 'app_name', 'company_name', 'footer', 'developed_by', 'page_title_suffix'];

    /** Text parts → [tenant settings column, getTenantDefaults() key]. */
    protected const TEXT_PARTS = [
        'app_name'          => ['app_name', 'app_name'],
        'footer'            => ['footer', 'footer_text'],
        'developed_by'      => ['developed_by', 'developed_by'],
        'page_title_suffix' => ['page_title_suffix', 'page_title_suffix'],
    ];

    /**
     * Company names that are still the seeded placeholder. The company name is
     * the tenant's own business, so only these are replaced.
     */
    protected const PLACEHOLDER_COMPANY_NAMES = ['', 'stocky'];

    /** Tenants whose database and image folder exist and are in use. */
    protected const STATUSES = [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED];

    /**
     * Apply the chosen parts to every active / suspended tenant.
     *
     * @param  string[]  $parts  subset of self::PARTS
     * @return array{updated: int, failed: array<string, string>}
     */
    public function applyToAll(array $parts): array
    {
        $parts = array_values(array_intersect($parts, self::PARTS));
        $settings = GeneralSetting::instance();

        $logo = in_array('logo', $parts, true) ? $this->source($settings->tenant_logo_path) : null;
        $favicon = in_array('favicon', $parts, true) ? $this->source($settings->tenant_favicon_path) : null;
        $defaults = $settings->getTenantDefaults();
        $texts = [];
        foreach (self::TEXT_PARTS as $part => [$column, $key]) {
            if (in_array($part, $parts, true)) {
                $texts[$column] = (string) ($defaults[$key] ?? '');
            }
        }
        $companyName = in_array('company_name', $parts, true) ? (string) $defaults['company_name'] : null;

        $updated = 0;
        $failed = [];

        foreach (Tenant::whereIn('status', self::STATUSES)->cursor() as $tenant) {
            try {
                $this->applyToTenant($tenant, $logo, $favicon, $texts, $companyName);
                $updated++;
            } catch (\Throwable $e) {
                $failed[$tenant->id] = $e->getMessage();
                Log::warning("TenantBrandingService: tenant {$tenant->id} failed: {$e->getMessage()}");
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    /**
     * @param  array<string, string>  $texts  tenant settings column => value
     * @param  string|null  $companyName  replaces a placeholder company name only
     */
    protected function applyToTenant(Tenant $tenant, ?string $logo, ?string $favicon, array $texts, ?string $companyName): void
    {
        $tenantDir = public_path('images/tenants/' . $tenant->id);
        $settingsDir = $tenantDir . DIRECTORY_SEPARATOR . 'settings';
        if ($logo || $favicon) {
            File::ensureDirectoryExists($settingsDir);
        }

        $columns = [];
        $stamp = time();

        // .htaccess caches images for a year, so the settings row gets a fresh
        // filename each time — an in-place overwrite would keep showing the old
        // logo to anyone who already loaded it. The fixed default names
        // provisioning uses are refreshed too, for code that falls back to them
        // (receipts, branch logos); skipped for SVG, which a .png / .ico name
        // would break.
        if ($logo) {
            $name = 'brand-logo-' . $stamp . '.' . $this->extension($logo);
            $this->copy($logo, $settingsDir . DIRECTORY_SEPARATOR . $name);
            if (! $this->isSvg($logo)) {
                $this->copy($logo, $settingsDir . DIRECTORY_SEPARATOR . 'logo-default.png');
            }
            $columns['logo'] = $name;
        }

        if ($favicon) {
            $name = 'brand-favicon-' . $stamp . '.' . $this->extension($favicon);
            $this->copy($favicon, $settingsDir . DIRECTORY_SEPARATOR . $name);
            if (! $this->isSvg($favicon)) {
                $this->copy($favicon, $settingsDir . DIRECTORY_SEPARATOR . 'favicon.ico');
            }
            $columns['favicon'] = $name;
        }

        $columns += $texts;

        if ($companyName !== null && $companyName !== '') {
            $this->replacePlaceholderCompanyName($tenant, $companyName);
        }

        if ($columns) {
            $previous = $tenant->run(function () use ($columns) {
                $row = Setting::whereNull('deleted_at')->first();
                Setting::whereNull('deleted_at')->update($columns);

                return $row ? ['logo' => $row->logo, 'favicon' => $row->favicon] : [];
            });

            // Remove the copy a previous apply wrote, now that nothing points
            // at it. Files the tenant uploaded itself are left alone.
            foreach (['logo', 'favicon'] as $column) {
                $old = (string) ($previous[$column] ?? '');
                if (isset($columns[$column]) && $old !== $columns[$column]
                    && preg_match('/^brand-(logo|favicon)-\d+\.[a-z0-9]+$/i', $old)) {
                    @unlink($settingsDir . DIRECTORY_SEPARATOR . $old);
                }
            }
        }

        // The installed app's icons are derived from the logo; SVG can't be
        // rasterized by the icon generator, so those tenants keep their icons.
        if ($logo && ! $this->isSvg($logo)) {
            try {
                PwaIconService::generate($logo, $tenantDir);
            } catch (\Throwable $e) {
                Log::warning("TenantBrandingService: PWA icons for tenant {$tenant->id} failed: {$e->getMessage()}");
            }
        }
    }

    /**
     * Settings CompanyName and companies.name, only where they still hold the
     * seeded placeholder (a tenant registered without a company name).
     */
    protected function replacePlaceholderCompanyName(Tenant $tenant, string $companyName): void
    {
        $tenant->run(function () use ($companyName) {
            $placeholders = self::PLACEHOLDER_COMPANY_NAMES;

            Setting::whereNull('deleted_at')
                ->where(function ($q) use ($placeholders) {
                    $q->whereNull('CompanyName')->orWhereIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(CompanyName))'), $placeholders);
                })
                ->update(['CompanyName' => $companyName]);

            if (\Illuminate\Support\Facades\Schema::hasTable('companies')) {
                \Illuminate\Support\Facades\DB::table('companies')
                    ->where(function ($q) use ($placeholders) {
                        $q->whereNull('name')->orWhereIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(name))'), $placeholders);
                    })
                    ->update(['name' => $companyName]);
            }
        });
    }

    /** Absolute path of a configured public/ file, or null when unset / missing. */
    protected function source(?string $relative): ?string
    {
        if (! $relative) {
            return null;
        }

        $path = public_path($relative);

        return is_file($path) ? $path : null;
    }

    protected function copy(string $from, string $to): void
    {
        if (! @copy($from, $to)) {
            throw new \RuntimeException('Could not write ' . $to);
        }
    }

    protected function isSvg(string $path): bool
    {
        return $this->extension($path) === 'svg';
    }

    protected function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'png';
    }
}
