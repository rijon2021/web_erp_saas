<?php

namespace App\Services\Updater;

use App\Tenant;

/**
 * The databases a platform update touches: the central database first,
 * then every tenant that has a provisioned database.
 *
 * A target is ['key' => 'central'|'tenant:<id>', 'tenant' => null|<id>,
 * 'label' => <domain or id>, 'file' => <dump file name>]. Targets are
 * resolved once when a run starts and stored in the run state, so a tenant
 * created mid-update is simply not part of that run.
 */
class UpdateTargets
{
    /**
     * Tenant statuses without a usable database (shared with UpdateController).
     * A rejected tenant is a pending registration the super admin declined
     * (TenantController::reject only accepts pending ones) — it was never
     * provisioned, so probing it would block every update.
     */
    public const SKIP_STATUSES = [
        Tenant::STATUS_PENDING,
        Tenant::STATUS_PROVISIONING,
        Tenant::STATUS_FAILED,
        Tenant::STATUS_REJECTED,
    ];

    public function databases(): array
    {
        $targets = [[
            'key' => 'central',
            'tenant' => null,
            'label' => 'central',
            'file' => BackupManager::dumpFileFor(null),
        ]];

        $tenants = Tenant::whereNotIn('status', self::SKIP_STATUSES)
            ->with('domains')
            ->orderBy('created_at')
            ->get();

        foreach ($tenants as $tenant) {
            $targets[] = [
                'key' => 'tenant:'.$tenant->id,
                'tenant' => (string) $tenant->id,
                'label' => optional($tenant->domains->first())->domain ?: (string) $tenant->id,
                'file' => BackupManager::dumpFileFor((string) $tenant->id),
            ];
        }

        return $targets;
    }

    /**
     * Run $callback against a target's database. The callback receives the
     * connection name to use: 'central', or 'tenant' while the tenant is
     * initialized (its own credentials, its own database).
     */
    public static function run(array $target, callable $callback)
    {
        if (($target['tenant'] ?? null) === null) {
            return $callback('central');
        }

        $tenant = Tenant::find($target['tenant']);
        if (! $tenant) {
            throw new \RuntimeException('Tenant '.$target['tenant'].' no longer exists.');
        }

        try {
            return $tenant->run(fn () => $callback('tenant'));
        } catch (\Throwable $e) {
            // Tenant::run() has no finally: a throwing callback would leave
            // the tenant initialized (tenant connection, storage_path()) for
            // the rest of the request.
            if (tenancy()->initialized) {
                tenancy()->end();
            }
            throw $e;
        }
    }
}
