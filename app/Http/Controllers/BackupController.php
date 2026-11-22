<?php

namespace App\Http\Controllers;

use App\Models\Central\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Services\Backup\TenantBackupStore;
use App\Services\CloudBackupUploader;
use App\Services\TenantLimitsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * The tenant's Backup page: on-demand backups (database:backup), the
 * plan-scheduled ones (tenants:backup), and the plan's retention policy.
 *
 * Backups live in the tenant's private storage (TenantBackupStore) and are
 * only reachable through download_backup, behind the `backup` permission.
 */
class BackupController extends Controller
{
    // -------------------- List backups -------------\\

    public function Get_Backup(Request $request, TenantBackupStore $store)
    {
        $this->authorizeForUser($request->user('api'), 'backup', User::class);

        $data = [];
        foreach ($store->list() as $i => $row) {
            $data[] = [
                'id' => $i + 1,
                // `date` has always carried the file name; the page keys rows by it.
                'date' => $row['name'],
                'type' => $row['type'],
                'size' => $this->formatSizeUnits($row['size']),
                'created_at' => $row['created_at'],
            ];
        }

        return response()->json([
            'backups' => $data,
            'totalRows' => count($data),
            'policy' => $this->policy(),
        ]);
    }

    // -------------------- Generate backup -------------\\

    public function Generate_Backup(Request $request, TenantBackupStore $store)
    {
        $this->authorizeForUser($request->user('api'), 'backup', User::class);

        // A large tenant's dump outlasts the default 30 s web time limit.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $exitCode = Artisan::call('database:backup');
        $output = Artisan::output();

        if ($exitCode !== 0) {
            $errorMsg = trim($output);
            if (preg_match('/ERROR_DETAILS:\s*(.+)/s', $output, $matches)) {
                $errorMsg = trim($matches[1]);
            }
            if (empty($errorMsg) || strlen($errorMsg) < 10) {
                $errorMsg = 'Database backup command failed. Check that the database server is running and that mysqldump (DUMP_PATH) or PHP has access to it.';
            }

            return response()->json([
                'success' => false,
                'message' => 'Backup generation failed',
                'error' => $errorMsg,
                'cloud' => null,
            ], 500);
        }

        $file = preg_match('/BACKUP_FILE:\s*(\S+)/', $output, $m) ? $store->find($m[1]) : null;
        if (! $file || filesize($file) === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No backup file found after generation',
                'error' => 'Backup command completed but no backup file was written.',
                'cloud' => null,
            ], 500);
        }

        // Local backup is the primary copy; the cloud upload is optional and additive.
        $cloud = null;
        try {
            $setting = Setting::whereNull('deleted_at')->first();
            $cloud = (new CloudBackupUploader)->uploadIfConfigured($file, basename($file), $setting);
        } catch (\Throwable $e) {
            $cloud = ['success' => false, 'error' => $e->getMessage()];
        }

        $message = 'Backup generated successfully';
        if ($cloud && ! empty($cloud['success']) && empty($cloud['skipped'])) {
            $message .= ' and uploaded to '.ucfirst($cloud['provider']);
        } elseif ($cloud && isset($cloud['error'])) {
            $message .= ' (cloud upload failed: '.$cloud['error'].')';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'cloud' => $cloud,
        ]);
    }

    // -------------------- Download backup -------------\\

    public function Download_Backup(Request $request, TenantBackupStore $store, $name)
    {
        $this->authorizeForUser($request->user('api'), 'backup', User::class);

        $path = $store->find((string) $name);
        abort_if($path === null, 404);

        return response()->download($path, basename($path), [
            'Content-Type' => str_ends_with($path, '.gz') ? 'application/gzip' : 'application/sql',
        ]);
    }

    // -------------------- Delete backup -------------\\

    public function Delete_Backup(Request $request, TenantBackupStore $store, $name)
    {
        $this->authorizeForUser($request->user('api'), 'backup', User::class);

        $path = $store->find((string) $name);
        if ($path !== null) {
            @unlink($path);
        }

        return response()->json(['success' => true]);
    }

    /**
     * The tenant's plan policy, shown read-only on the Backup page (null when
     * no subscription grants access).
     */
    private function policy(): ?array
    {
        $plan = app(TenantLimitsService::class)->getActivePlan();
        if (! $plan) {
            return null;
        }

        $policy = [];
        foreach (array_keys(Plan::RETENTION_FIELDS) as $key) {
            $policy[$key] = $plan->retentionSetting($key);
        }

        return $policy;
    }

    // -------------------- Format units -------------\\

    public function formatSizeUnits($bytes)
    {
        if ($bytes >= 1073741824) {
            $bytes = number_format($bytes / 1073741824, 2).' GB';
        } elseif ($bytes >= 1048576) {
            $bytes = number_format($bytes / 1048576, 2).' MB';
        } elseif ($bytes >= 1024) {
            $bytes = number_format($bytes / 1024, 2).' KB';
        } elseif ($bytes > 1) {
            $bytes = $bytes.' bytes';
        } elseif ($bytes == 1) {
            $bytes = $bytes.' byte';
        } else {
            $bytes = '0 bytes';
        }

        return $bytes;
    }
}
