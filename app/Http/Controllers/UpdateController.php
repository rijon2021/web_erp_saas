<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureNotUpdating;
use App\Services\Updater\PlatformUpgradeTasks;
use App\Services\Updater\UpdateState;
use App\Services\Updater\UpdateTargets;
use App\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class UpdateController extends Controller
{
    /** Statuses excluded from the migration target list. */
    private const SKIP_STATUSES = UpdateTargets::SKIP_STATUSES;

    /** GET /update — Render the progress UI with inventory of update targets. */
    public function show()
    {
        $this->assertSuperAdmin();
        $targets = $this->targets();

        return view('update.index', [
            'targets'     => $targets,
            'lockActive'  => $this->isLocked(),
        ]);
    }

    /** POST /update/start — Engage maintenance mode and return the target list. */
    public function start(Request $request): JsonResponse
    {
        $this->assertSuperAdmin();
        // The upload-based System Update owns the platform until its run is
        // finished — also while it is stalled or awaiting a rollback.
        if ((new UpdateState)->hasUnfinishedRun()) {
            return response()->json([
                'ok'    => false,
                'error' => __('super.system_update.flash.unfinished_run'),
            ], 409);
        }
        $this->engageMaintenance();

        return response()->json([
            'ok'      => true,
            'targets' => $this->targets(),
        ]);
    }

    /** POST /update/run-central — Run central migrations. */
    public function runCentral(): JsonResponse
    {
        $this->assertSuperAdmin();
        $this->raiseLimits();

        try {
            $output = (new PlatformUpgradeTasks)->central();
            Log::info('Platform update: central migrated.', ['output' => $output]);

            return response()->json([
                'ok'     => true,
                'output' => $output ?: 'Nothing to migrate.',
            ]);
        } catch (\Throwable $e) {
            Log::error('Platform update: central migration failed.', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /** POST /update/run-tenant/{tenantId} — Run migrations + seeders for one tenant. */
    public function runTenant(string $tenantId): JsonResponse
    {
        $this->assertSuperAdmin();
        $this->raiseLimits();

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return response()->json(['ok' => false, 'error' => 'Tenant not found.'], 404);
        }

        if (in_array($tenant->status, self::SKIP_STATUSES, true)) {
            return response()->json([
                'ok'      => false,
                'skipped' => true,
                'error'   => "Skipped (status: {$tenant->status}).",
            ]);
        }

        $output = '';

        try {
            $tenant->run(function () use (&$output) {
                $output = (new PlatformUpgradeTasks)->tenant();
            });

            Log::info("Platform update: tenant {$tenantId} migrated.");

            return response()->json([
                'ok'     => true,
                'output' => $output ?: 'Nothing to migrate.',
            ]);
        } catch (\Throwable $e) {
            Log::error("Platform update: tenant {$tenantId} failed.", [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /** POST /update/finish — Clear central caches and disengage maintenance. */
    public function finish(): JsonResponse
    {
        $this->assertSuperAdmin();
        try {
            Artisan::call('config:clear');
            Artisan::call('view:clear');
            Artisan::call('route:clear');
            // Application cache is shared by central + all tenants (tenant
            // entries are key-prefixed onto the same store, see
            // PrefixCacheTenancyBootstrapper), so one flush here clears
            // tenant-side caches on every driver — not just the file driver
            // handled by clearTenantCache() during runTenant().
            Artisan::call('cache:clear');
            // Supervisor queue workers still hold the old code in memory;
            // the restart signal lives in the cache, so after cache:clear.
            Artisan::call('queue:restart');
        } catch (\Throwable $e) {
            Log::warning('Platform update: cache clear failed.', ['error' => $e->getMessage()]);
        }

        $this->disengageMaintenance();

        return response()->json(['ok' => true]);
    }

    /** POST /update/abort — Emergency release of the maintenance lock. */
    public function abort(): JsonResponse
    {
        $this->assertSuperAdmin();
        $this->disengageMaintenance();
        Log::warning('Platform update: maintenance lock manually released.');
        return response()->json(['ok' => true]);
    }

    // ──────────────────────────────────────────────────────────────────────

    /** Build the ordered list of update targets (central + eligible tenants). */
    private function targets(): array
    {
        $targets = [[
            'key'    => 'central',
            'id'     => 'central',
            'label'  => 'Central database',
            'type'   => 'central',
            'status' => 'ready',
        ]];

        $tenants = Tenant::whereNotIn('status', self::SKIP_STATUSES)
            ->with('domains')
            ->orderBy('created_at')
            ->get();

        foreach ($tenants as $tenant) {
            $domain = optional($tenant->domains->first())->domain;
            $targets[] = [
                'key'    => 'tenant:' . $tenant->id,
                'id'     => $tenant->id,
                'label'  => $domain ?: $tenant->id,
                'type'   => 'tenant',
                'status' => 'ready',
                'meta'   => [
                    'id'     => $tenant->id,
                    'domain' => $domain,
                    'state'  => $tenant->status,
                ],
            ];
        }

        return $targets;
    }

    private function assertSuperAdmin(): void
    {
        $user = Auth::guard('central')->user();
        if (! $user || ! method_exists($user, 'isSuperAdmin') || ! $user->isSuperAdmin()) {
            abort(403, 'Only super admins can run platform updates.');
        }
    }

    private function engageMaintenance(): void
    {
        $path = storage_path('app/' . EnsureNotUpdating::LOCK_FILE);
        @file_put_contents($path, json_encode([
            'started_at' => now()->toIso8601String(),
            'by'         => optional(Auth::guard('central')->user())->email,
        ], JSON_UNESCAPED_SLASHES));
    }

    private function disengageMaintenance(): void
    {
        $path = storage_path('app/' . EnsureNotUpdating::LOCK_FILE);
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    private function isLocked(): bool
    {
        return file_exists(storage_path('app/' . EnsureNotUpdating::LOCK_FILE));
    }

    private function raiseLimits(): void
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '1G');
    }

}
