<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nwidart\Modules\Facades\Module;

class ModuleSettingsController extends Controller
{
    /** A module is PHP code that runs on boot, so the package is checked before anything is written. */
    private const MAX_ENTRIES = 5000;

    private const MAX_UNCOMPRESSED_BYTES = 200 * 1024 * 1024;

    /** Folder name under Modules/ — never a path. */
    private const MODULE_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/';

    public function get_modules_info(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'module_settings', Setting::class);

        $modules = Module::all();
        $result = [];

        foreach ($modules as $name => $module) {
            $moduleJson = json_decode(File::get($module->getPath() . '/module.json'), true);
            $result[] = [
                'module_name'     => $name,
                'current_version' => $moduleJson['version'] ?? '1.0.0',
                'status'          => $module->isEnabled(),
            ];
        }

        return response()->json($result);
    }

    public function update_status_module(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'module_settings', Setting::class);

        if ($refused = $this->refuseInTenantContext()) {
            return $refused;
        }

        $request->validate([
            'name'   => 'required|string',
            'status' => 'required',
        ]);

        $module = Module::find($request->name);

        if (!$module) {
            return response()->json(['message' => 'Module not found'], 404);
        }

        if ($request->status) {
            $module->enable();
        } else {
            $module->disable();
        }

        return response()->json(['message' => 'Module status updated']);
    }

    public function upload_module(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'module_settings', Setting::class);

        if ($refused = $this->refuseInTenantContext()) {
            return $refused;
        }

        $request->validate([
            'module_zip' => 'required|file|mimes:zip',
        ]);

        $file = $request->file('module_zip');
        $modulesPath = base_path('Modules');

        if (!File::isDirectory($modulesPath)) {
            File::makeDirectory($modulesPath, 0755, true);
        }

        $zip = new \ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            return response()->json(['message' => 'Failed to extract zip'], 422);
        }

        // Check every entry BEFORE extracting anything.
        $problem = $this->unsafeZipReason($zip);
        if ($problem !== null) {
            $zip->close();
            return response()->json(['message' => 'Invalid module package: ' . $problem], 422);
        }

        $tmpPath = storage_path('app/tmp_module_' . Str::random(16));
        File::makeDirectory($tmpPath, 0755, true);

        $extracted = $zip->extractTo($tmpPath);
        $zip->close();

        if (!$extracted) {
            File::deleteDirectory($tmpPath);
            return response()->json(['message' => 'Failed to extract zip'], 422);
        }

        // Find the module.json to identify the module
        $directories = File::directories($tmpPath);
        $sourceDir = count($directories) === 1 ? $directories[0] : $tmpPath;

        if (!File::exists($sourceDir . '/module.json')) {
            // Maybe files are directly in tmp without subfolder
            if (File::exists($tmpPath . '/module.json')) {
                $sourceDir = $tmpPath;
            } else {
                File::deleteDirectory($tmpPath);
                return response()->json(['message' => 'Invalid module: module.json not found'], 422);
            }
        }

        $moduleJson = json_decode(File::get($sourceDir . '/module.json'), true);
        $moduleName = is_array($moduleJson) ? ($moduleJson['name'] ?? null) : null;

        // The name becomes a folder under Modules/ that is deleted and replaced,
        // so it must be a plain name ("../app" would otherwise replace app/).
        if (!is_string($moduleName) || !preg_match(self::MODULE_NAME_PATTERN, $moduleName)) {
            File::deleteDirectory($tmpPath);
            return response()->json(['message' => 'Invalid module.json: the module name may only contain letters, numbers, "-" and "_"'], 422);
        }

        $targetPath = $modulesPath . DIRECTORY_SEPARATOR . $moduleName;

        if (dirname($targetPath) !== $modulesPath) {
            File::deleteDirectory($tmpPath);
            return response()->json(['message' => 'Invalid module name'], 422);
        }

        // Remove old version if exists
        if (File::isDirectory($targetPath)) {
            File::deleteDirectory($targetPath);
        }

        File::moveDirectory($sourceDir, $targetPath);
        File::deleteDirectory($tmpPath);

        // Enable the module
        $module = Module::find($moduleName);
        if ($module) {
            $module->enable();
        }

        return response()->json(['message' => 'Module uploaded successfully']);
    }

    /**
     * Modules are shared PHP code under base_path('Modules') and their
     * enabled/disabled state is one global file: installing or toggling one
     * from a tenant would run code / change behaviour for EVERY tenant on the
     * platform. Tenants may list modules; changing them is platform-admin only.
     */
    private function refuseInTenantContext()
    {
        if (function_exists('tenancy') && tenancy()->initialized) {
            return response()->json([
                'message' => 'Modules are managed by the platform administrator.',
            ], 403);
        }

        return null;
    }

    /**
     * Why the archive must be refused, or null when every entry is a plain
     * relative path (no "..", no absolute/drive paths, no symlinks) and the
     * package is not unreasonably large.
     */
    private function unsafeZipReason(\ZipArchive $zip): ?string
    {
        if ($zip->numFiles === 0) {
            return 'the archive is empty';
        }

        if ($zip->numFiles > self::MAX_ENTRIES) {
            return 'too many files';
        }

        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                return 'unreadable entry';
            }

            $name = str_replace('\\', '/', (string) $stat['name']);

            if ($name === ''
                || str_contains($name, "\0")
                || str_starts_with($name, '/')
                || preg_match('/^[A-Za-z]:/', $name)
                || in_array('..', explode('/', $name), true)) {
                return 'unsafe file path "' . $stat['name'] . '"';
            }

            // Unix symlink entries (mode 0120000) could point outside the module.
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr)
                && $opsys === \ZipArchive::OPSYS_UNIX
                && (($attr >> 16) & 0170000) === 0120000) {
                return 'symbolic links are not allowed';
            }

            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                return 'the package is too large';
            }
        }

        return null;
    }
}
