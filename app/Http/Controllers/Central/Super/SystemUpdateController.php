<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Services\Updater\BackupManager;
use App\Services\Updater\PackageUnwrapper;
use App\Services\Updater\PackageValidator;
use App\Services\Updater\UpdateManager;
use App\Services\Updater\UpdaterPaths;
use App\Services\Updater\UpdateState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

/**
 * Upload-based System Update for the whole platform (code + central
 * database + every tenant database).
 *
 * Super admins only. Every endpoint is whitelisted in
 * CheckForMaintenanceMode so the update UI keeps working while the
 * platform is down for updating.
 */
class SystemUpdateController extends Controller
{
    /** Max accepted size for one upload chunk (must stay under post_max_size). */
    private const MAX_CHUNK = 8 * 1024 * 1024;

    private function assertSuperAdmin(): void
    {
        $user = Auth::guard('central')->user();
        if (! $user || ! method_exists($user, 'hasPermission') || ! $user->hasPermission('system_update')) {
            abort(403, __('super.system_update.flash.super_admin_only'));
        }
    }

    private function currentUser(): ?array
    {
        $user = Auth::guard('central')->user();

        return $user ? ['id' => $user->id, 'name' => $user->name ?: $user->email] : null;
    }

    // ------------------------------------------------------------------ pages

    public function index()
    {
        $this->assertSuperAdmin();

        return view('central.super.system-update.index', [
            'status' => (new UpdateManager)->status(),
        ]);
    }

    /**
     * Standalone recovery console. Kept outside the super layout so it
     * still works when the layout's own dependencies are broken mid-update.
     */
    public function recoveryConsole()
    {
        $this->assertSuperAdmin();

        return view('update.system-recovery');
    }

    /** Unauthenticated liveness probe used by the post-update health check. */
    public function ping()
    {
        return response('pong', 200)->header('Content-Type', 'text/plain');
    }

    // ---------------------------------------------------------------- status

    public function status()
    {
        $this->assertSuperAdmin();

        return response()->json((new UpdateManager)->status());
    }

    // ---------------------------------------------------------------- upload

    public function uploadChunk(Request $request)
    {
        $this->assertSuperAdmin();

        if ((new UpdateState)->hasUnfinishedRun()) {
            return response()->json(['message' => __('super.system_update.flash.unfinished_run')], 409);
        }

        $request->validate([
            'chunk' => 'required|file',
            'index' => 'required|integer|min:0',
            'total' => 'required|integer|min:1|max:20000',
            'upload_id' => 'required|string|max:64',
            'filename' => 'nullable|string|max:255',
            'sha256' => 'nullable|string|size:64',
        ]);

        $index = (int) $request->input('index');
        $total = (int) $request->input('total');
        $uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $request->input('upload_id'));
        $chunk = $request->file('chunk');
        if ($chunk->getSize() > self::MAX_CHUNK) {
            return response()->json(['message' => __('super.system_update.flash.chunk_too_large')], 413);
        }

        UpdaterPaths::ensureDirectories();
        $part = UpdaterPaths::packagePart();
        $metaFile = $part.'.meta.json';

        if ($index === 0) {
            @File::delete($part);
            @File::delete($metaFile);
            @File::delete(UpdaterPaths::packageZip());
            @File::delete(UpdaterPaths::packageMeta());
            PackageUnwrapper::cleanup();
            File::put($metaFile, json_encode(['upload_id' => $uploadId, 'next' => 0, 'total' => $total]));
        }

        $meta = json_decode((string) @file_get_contents($metaFile), true);
        if (! is_array($meta) || ($meta['upload_id'] ?? null) !== $uploadId) {
            return response()->json(['message' => __('super.system_update.flash.upload_superseded')], 409);
        }
        if ((int) $meta['next'] !== $index) {
            return response()->json(['message' => __('super.system_update.flash.upload_out_of_order'), 'expected' => $meta['next']], 409);
        }

        // Append the chunk
        $in = fopen($chunk->getRealPath(), 'rb');
        $out = fopen($part, $index === 0 ? 'wb' : 'ab');
        if (! $in || ! $out) {
            return response()->json(['message' => __('super.system_update.flash.upload_write_failed')], 500);
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        $meta['next'] = $index + 1;
        File::put($metaFile, json_encode($meta));

        if ($index + 1 < $total) {
            return response()->json(['received' => $index + 1, 'total' => $total]);
        }

        // ------- last chunk: finalize --------------------------------------
        $sha256 = strtolower((string) $request->input('sha256', ''));
        if ($sha256 !== '') {
            $actual = hash_file('sha256', $part);
            if (! hash_equals($sha256, $actual)) {
                @File::delete($part);
                @File::delete($metaFile);

                return response()->json(['message' => __('super.system_update.flash.upload_checksum')], 422);
            }
        }

        $zip = new \ZipArchive;
        if ($zip->open($part) !== true) {
            @File::delete($part);
            @File::delete($metaFile);

            return response()->json(['message' => __('super.system_update.flash.not_a_zip')], 422);
        }
        $zip->close();

        // The CodeCanyon download wraps the application ZIP inside another
        // ZIP (next to the documentation) — use the inner one in that case.
        @set_time_limit(0);
        try {
            $unwrapped = (new PackageUnwrapper)->unwrap($part);
        } catch (\RuntimeException $e) {
            @File::delete($part);
            @File::delete($metaFile);

            return response()->json(['message' => $e->getMessage()], 422);
        }
        if ($unwrapped['path'] !== $part) {
            @File::delete($part);
        }

        @File::delete(UpdaterPaths::packageZip());
        if (! @rename($unwrapped['path'], UpdaterPaths::packageZip())) {
            return response()->json(['message' => __('super.system_update.flash.upload_finalize_failed')], 500);
        }
        @File::delete($metaFile);

        $filename = (string) $request->input('filename', 'package.zip');
        foreach ($unwrapped['inner'] as $innerName) {
            $filename .= ' › '.basename($innerName);
        }

        File::put(UpdaterPaths::packageMeta(), json_encode([
            'filename' => mb_substr($filename, 0, 255),
            'size' => File::size(UpdaterPaths::packageZip()),
            'sha256' => $sha256 ?: null,
            'uploaded_at' => date('Y-m-d H:i:s'),
            'validated' => false,
            'report' => null,
        ], JSON_PRETTY_PRINT));

        return response()->json(['received' => $total, 'total' => $total, 'complete' => true]);
    }

    // -------------------------------------------------------------- validate

    public function validatePackage()
    {
        $this->assertSuperAdmin();

        if (! File::exists(UpdaterPaths::packageZip())) {
            return response()->json(['message' => __('super.system_update.flash.no_package')], 404);
        }

        $report = (new PackageValidator)->validate(UpdaterPaths::packageZip());

        $meta = json_decode((string) @file_get_contents(UpdaterPaths::packageMeta()), true) ?: [];
        $meta['validated'] = true;
        $meta['validated_at'] = date('Y-m-d H:i:s');
        $meta['ok'] = $report['ok'];
        $meta['report'] = $report;
        $meta['version'] = $report['package']['version'] ?? null;
        File::put(UpdaterPaths::packageMeta(), json_encode($meta, JSON_PRETTY_PRINT));

        return response()->json($report);
    }

    // ----------------------------------------------------------------- runs

    public function start()
    {
        $this->assertSuperAdmin();

        $meta = json_decode((string) @file_get_contents(UpdaterPaths::packageMeta()), true) ?: [];
        if (empty($meta['validated']) || empty($meta['ok'])) {
            return response()->json(['message' => __('super.system_update.flash.validate_first')], 422);
        }

        return $this->run(fn (UpdateManager $m) => $m->startUpdate($meta, $this->currentUser()));
    }

    public function step()
    {
        $this->assertSuperAdmin();

        return $this->run(fn (UpdateManager $m) => $m->step());
    }

    public function resume()
    {
        $this->assertSuperAdmin();

        return $this->run(fn (UpdateManager $m) => $m->resume());
    }

    public function rollback()
    {
        $this->assertSuperAdmin();

        return $this->run(fn (UpdateManager $m) => $m->requestRollback());
    }

    public function discard()
    {
        $this->assertSuperAdmin();

        return $this->run(fn (UpdateManager $m) => $m->discard());
    }

    /** Remove an uploaded (not yet applied) package. */
    public function deletePackage()
    {
        $this->assertSuperAdmin();

        if ((new UpdateState)->hasUnfinishedRun()) {
            return response()->json(['message' => __('super.system_update.flash.unfinished_run')], 409);
        }
        @File::delete(UpdaterPaths::packageZip());
        @File::delete(UpdaterPaths::packagePart());
        @File::delete(UpdaterPaths::packageMeta());
        PackageUnwrapper::cleanup();

        return response()->json((new UpdateManager)->status());
    }

    // --------------------------------------------------------------- backups

    public function restoreBackup(Request $request)
    {
        $this->assertSuperAdmin();
        $request->validate(['id' => 'required|string|max:100']);

        return $this->run(fn (UpdateManager $m) => $m->startRestore((string) $request->input('id'), $this->currentUser()));
    }

    public function deleteBackup(string $id)
    {
        $this->assertSuperAdmin();

        if ((new UpdateState)->hasUnfinishedRun()) {
            return response()->json(['message' => __('super.system_update.flash.unfinished_run')], 409);
        }
        try {
            (new BackupManager)->deleteBackup($id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json((new UpdateManager)->status());
    }

    public function saveRetention(Request $request)
    {
        $this->assertSuperAdmin();
        $request->validate(['retention' => 'required|integer|min:1|max:20']);

        $stateService = new UpdateState;
        $config = $stateService->config();
        $config['retention'] = (int) $request->input('retention');
        $stateService->saveConfig($config);
        // Pruning now could delete the backup an unfinished run would roll
        // back to; the new value is applied by that run's cleanup instead.
        if (! $stateService->hasUnfinishedRun()) {
            (new BackupManager)->applyRetention($config['retention']);
        }

        return response()->json((new UpdateManager)->status());
    }

    /** Run one state-machine action and answer with the full status (409 on refusal). */
    private function run(callable $action)
    {
        $manager = new UpdateManager;
        try {
            $action($manager);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json((new UpdateManager)->status());
    }
}
