<?php

namespace App\Services\EInvoicing;

use App\Models\AgtDocument;
use App\Models\AgtSetting;
use App\Models\Central\Plan;
use App\Models\DgiDocument;
use App\Models\DgiSetting;
use App\Models\FbrDocument;
use App\Models\FbrSetting;
use App\Models\ZatcaDocument;
use App\Models\ZatcaSetting;
use App\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet-wide e-invoicing picture for Super Admin > Settings > E-Invoicing >
 * Overview: per provider, how many plans offer it, how many active tenants
 * switched it on / finished onboarding, and the document backlog. Walks
 * every active tenant database, so the result is cached and refreshed on
 * demand.
 */
class ProviderOverview
{
    public const CACHE_KEY = 'super.einvoicing.overview';

    public const TTL_MINUTES = 15;

    public const PROVIDERS = ['zatca', 'fbr', 'agt', 'dgi'];

    public static function cached(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(self::TTL_MINUTES), fn () => self::collect());
    }

    /** The cached scan, or null when none exists yet (no scan on page load). */
    public static function cachedIfAny(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        return is_array($cached) ? $cached : null;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function collect(): array
    {
        $blank = fn () => [
            'plans' => 0,
            'tenants_enabled' => 0,
            'tenants_ready' => 0,
            'docs_pending' => 0,
            'docs_failed' => 0,
            'docs_accepted' => 0,
            'last_document_at' => null,
            'tenants' => [], // top tenants by document volume
        ];

        $stats = array_combine(self::PROVIDERS, array_map($blank, self::PROVIDERS));
        $tenantsScanned = 0;
        $tenantsSkipped = 0;

        try {
            foreach (Plan::all() as $plan) {
                foreach (self::PROVIDERS as $provider) {
                    if ($plan->hasFeature($provider)) {
                        $stats[$provider]['plans']++;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('E-invoicing overview: plan scan failed: '.$e->getMessage());
        }

        try {
            foreach (Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->get() as $tenant) {
                $row = null;
                try {
                    $tenant->run(function () use (&$row) {
                        $row = self::tenantSnapshot();
                    });
                } catch (\Throwable $e) {
                    $tenantsSkipped++;
                    continue; // tenant DB unreachable — skip
                } finally {
                    // Tenant::run() has no finally of its own: a throwing
                    // callback leaves the tenant context (DB, cache prefix)
                    // initialised, which would poison the central cache write.
                    self::endTenancy();
                }
                $tenantsScanned++;

                foreach (self::PROVIDERS as $provider) {
                    $p = $row[$provider] ?? null;
                    if (! $p) {
                        continue;
                    }
                    $stats[$provider]['tenants_enabled'] += $p['enabled'] ? 1 : 0;
                    $stats[$provider]['tenants_ready'] += $p['ready'] ? 1 : 0;
                    $stats[$provider]['docs_pending'] += $p['pending'];
                    $stats[$provider]['docs_failed'] += $p['failed'];
                    $stats[$provider]['docs_accepted'] += $p['accepted'];
                    if ($p['last_at'] && ($stats[$provider]['last_document_at'] === null || $p['last_at'] > $stats[$provider]['last_document_at'])) {
                        $stats[$provider]['last_document_at'] = $p['last_at'];
                    }
                    $total = $p['pending'] + $p['failed'] + $p['accepted'];
                    if ($p['enabled'] || $total > 0) {
                        $stats[$provider]['tenants'][] = [
                            'id' => $tenant->id,
                            'name' => $tenant->company_name ?: $tenant->id,
                            'enabled' => $p['enabled'],
                            'ready' => $p['ready'],
                            'environment' => $p['environment'],
                            'pending' => $p['pending'],
                            'failed' => $p['failed'],
                            'accepted' => $p['accepted'],
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('E-invoicing overview: tenant scan failed: '.$e->getMessage());
        }

        foreach ($stats as &$s) {
            usort($s['tenants'], fn ($a, $b) => ($b['failed'] <=> $a['failed']) ?: (($b['pending'] + $b['accepted']) <=> ($a['pending'] + $a['accepted'])));
            $s['tenants'] = array_slice($s['tenants'], 0, 8);
        }
        unset($s);

        return [
            'providers' => $stats,
            'tenants_scanned' => $tenantsScanned,
            'tenants_skipped' => $tenantsSkipped,
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    protected static function endTenancy(): void
    {
        try {
            if (function_exists('tenancy') && tenancy()->initialized) {
                tenancy()->end();
            }
        } catch (\Throwable $e) {
            // nothing more to do
        }
    }

    /** Runs inside a tenant context. */
    protected static function tenantSnapshot(): array
    {
        // Force the connection now so an unreachable database surfaces as a
        // skipped tenant instead of four silent "not migrated" providers.
        DB::connection()->getPdo();

        $out = [];

        // ZATCA Phase 2: pending | reported | cleared | failed
        $out['zatca'] = self::snapshot(
            'zatca_settings',
            function () {
                $z = ZatcaSetting::query()->first();

                return [(bool) ($z?->enabled), (bool) ($z && $z->isReady()), $z?->environment];
            },
            'zatca_documents',
            fn () => ZatcaDocument::query(),
            ['pending'],
            ['failed'],
            ['reported', 'cleared']
        );

        // FBR: pending | valid | invalid | error
        $out['fbr'] = self::snapshot(
            'fbr_settings',
            function () {
                $f = FbrSetting::query()->first();

                return [(bool) ($f?->enabled), (bool) ($f && $f->isReady()), $f?->environment];
            },
            'fbr_documents',
            fn () => FbrDocument::query(),
            ['pending'],
            ['invalid', 'error'],
            ['valid']
        );

        // AGT: pending | submitted | valid | invalid | error | superseded
        $out['agt'] = self::snapshot(
            'agt_settings',
            function () {
                $a = AgtSetting::query()->first();

                return [AgtSetting::moduleEnabled(), (bool) ($a && $a->isReady()), $a?->environment];
            },
            'agt_documents',
            fn () => AgtDocument::query()->where('status', '!=', 'superseded'),
            ['pending', 'submitted'],
            ['invalid', 'error'],
            ['valid']
        );

        // DGI: identity only until the e-MCF client exists
        $out['dgi'] = self::snapshot(
            'dgi_settings',
            function () {
                $d = DgiSetting::query()->first();

                return [DgiSetting::moduleEnabled(), (bool) ($d && ! empty($d->nif)), $d?->environment];
            },
            'dgi_documents',
            fn () => DgiDocument::query(),
            ['pending'],
            ['invalid', 'error', 'failed'],
            ['valid', 'accepted']
        );

        return $out;
    }

    protected static function snapshot(
        string $settingsTable,
        callable $settings,
        string $documentsTable,
        callable $query,
        array $pending,
        array $failed,
        array $accepted
    ): ?array {
        try {
            if (! Schema::hasTable($settingsTable)) {
                return null;
            }
            [$enabled, $ready, $environment] = $settings();
        } catch (\Throwable $e) {
            return null;
        }

        $row = [
            'enabled' => $enabled,
            'ready' => $ready,
            'environment' => $environment,
            'pending' => 0,
            'failed' => 0,
            'accepted' => 0,
            'last_at' => null,
        ];

        try {
            if (Schema::hasTable($documentsTable)) {
                $row['pending'] = (clone $query())->whereIn('status', $pending)->count();
                $row['failed'] = (clone $query())->whereIn('status', $failed)->count();
                $row['accepted'] = (clone $query())->whereIn('status', $accepted)->count();
                $last = (clone $query())->max('created_at');
                $row['last_at'] = $last ? (string) $last : null;
            }
        } catch (\Throwable $e) {
            // documents table missing or unreadable — counts stay at zero
        }

        return $row;
    }
}
