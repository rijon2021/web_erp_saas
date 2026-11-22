<?php

namespace App\Services\Updater;

use App\Http\Middleware\CheckForMaintenanceMode;
use App\Http\Middleware\EnsureNotUpdating;
use App\Tenant;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The platform update state machine.
 *
 * The whole update is a sequence of short, resumable phases persisted in
 * storage/app/updater/state.json. The super-admin UI drives it by calling
 * step() repeatedly; each call performs at most ONE phase (or one chunk of
 * a chunked phase) within a small time budget and returns. This survives
 * PHP time limits, PHP-FPM restarts, closed browsers and server crashes —
 * the state always records exactly where the run stopped, and any failure
 * at or after the file swap triggers the automatic rollback phases.
 *
 * Multi-tenant: every run covers the central database and every tenant
 * database (UpdateTargets). All of them are dumped before anything
 * changes; migrations run central first, then tenant by tenant; ANY
 * failure — including a single tenant's migration — rolls the whole
 * platform back: files, central and every database already migrated.
 *
 * Each phase runs in its own HTTP request on purpose: once the file swap
 * has happened, the next request executes the NEW code, so migrate logic
 * always comes from the version being installed.
 */
class UpdateManager
{
    /** Per-request work budget (seconds) for chunked phases. */
    private const TIME_BUDGET = 18;

    /**
     * Downtime is kept to what needs it. The application-files backup and
     * the package extraction only read the live code (which does not change
     * until 'apply'), so they run while the platform is still up — the files
     * ZIP alone can take many minutes. Maintenance goes on right before the
     * database dumps, so every dump is a consistent snapshot that no tenant
     * can write to before the migrations run.
     */
    private const UPDATE_PHASES = [
        'init', 'backup_files', 'stage_extract', 'stage_validate',
        'maintenance_on', 'backup_db', 'backup_verify',
        'apply', 'migrate_central', 'migrate_tenants', 'cache', 'verify',
        'maintenance_off', 'cleanup',
    ];

    private const ROLLBACK_PHASES = ['rb_files', 'rb_db', 'rb_finalize'];

    private const RESTORE_PHASES = [
        'rst_init', 'rst_extract', 'maintenance_on', 'rst_backup_db', 'rst_swap',
        'rst_db', 'rst_cache', 'maintenance_off', 'rst_cleanup',
    ];

    /**
     * Phases whose failure means the live installation may have been
     * modified → automatic rollback. Post-verification phases
     * (maintenance_off, cleanup) are deliberately NOT here: at that point
     * the new version is verified healthy and rolling it back over a
     * cleanup hiccup would be worse than finishing.
     */
    private const MODIFYING_PHASES = [
        'apply', 'migrate_central', 'migrate_tenants', 'cache', 'verify',
        'rst_swap', 'rst_db', 'rst_cache',
    ];

    private UpdateState $state;
    private BackupManager $backups;
    private FileDeployer $files;
    private float $deadline = 0;

    public function __construct()
    {
        $this->state = new UpdateState;
        $this->backups = new BackupManager;
        $this->files = new FileDeployer;
    }

    // ------------------------------------------------------------- lifecycle

    /** Start an update run from a validated uploaded package. */
    public function startUpdate(array $packageMeta, ?array $user): array
    {
        $this->assertNoStepInFlight();
        $this->assertNoLegacyRun();
        $existing = $this->state->get();
        if ($existing && ($existing['status'] ?? '') === 'running') {
            if (! $this->state->isStalled($existing)) {
                throw new \RuntimeException(__('super.system_update.flash.in_progress'));
            }
            throw new \RuntimeException(__('super.system_update.flash.previous_unfinished'));
        }
        if ($existing && ($existing['status'] ?? '') === 'recovery_required') {
            // The state of that run is the only map back to a working
            // system — starting a new run would overwrite it.
            throw new \RuntimeException(__('super.system_update.flash.recovery_pending'));
        }

        $validator = new PackageValidator;
        $report = $validator->validate(UpdaterPaths::packageZip());
        if (! $report['ok']) {
            throw new \RuntimeException(__('super.system_update.flash.validation_failed'));
        }

        $runId = 'run-'.date('Ymd-His').'-'.Str::lower(Str::random(6));
        if (! $this->state->acquireLock($runId)) {
            throw new \RuntimeException(__('super.system_update.flash.in_progress'));
        }

        // Which databases get a dump: the validator's plan (central + the
        // tenants this release migrates). A tenant that appeared after the
        // validation is not in the plan — back it up to be safe.
        $planned = $report['package']['backup_keys'] ?? null;
        $validated = array_column((new UpdateTargets)->databases(), null, 'key');
        $databases = [];
        foreach ($validated as $key => $target) {
            $target['backup'] = $planned === null || in_array($key, $planned, true);
            $databases[] = $target;
        }
        $toVersion = $report['package']['version'] ?? ($packageMeta['version'] ?? 'unknown');
        $state = [
            'id' => $runId,
            'type' => 'update',
            'status' => 'running',
            'phase' => 'init',
            'from_version' => $validator->currentVersion(),
            'to_version' => $toVersion,
            'user' => $user,
            'started_at' => time(),
            'package' => [
                'path' => UpdaterPaths::packageZip(),
                'version' => $toVersion,
                'size' => $report['package']['size'] ?? 0,
                'files' => $report['package']['files'] ?? 0,
                'root_prefix' => $report['package']['root_prefix'] ?? null,
            ],
            'databases' => $databases,
            'modified' => [],
            'backup' => ['id' => null, 'status' => 'pending'],
            'staging' => ['dir' => UpdaterPaths::staging().DIRECTORY_SEPARATOR.$runId, 'cursor' => []],
            'maintenance' => ['secret' => Str::lower(Str::random(24)), 'on' => false],
            'swap_log' => [],
            'migrations' => ['started' => false, 'status' => 'pending', 'central' => 'pending', 'index' => 0, 'done' => 0],
            'rollback' => ['status' => null],
            'error' => null,
            'log' => [],
        ];
        $tenantTotal = count($databases) - 1;
        $tenantDumps = count($this->backupTargets($state)) - 1;
        $this->state->log($state, "=== Update started: v{$state['from_version']} → v{$toVersion} by ".($user['name'] ?? 'unknown')
            .' — central + '.$tenantTotal.' tenant database(s) ===');
        $this->state->log($state, 'Backing up the central database and '.$tenantDumps.' of '.$tenantTotal.' tenant database(s)'
            .($tenantDumps < $tenantTotal ? ' — the others have no pending migration in this release' : ''));
        $this->state->put($state);
        $this->state->recordHistory($this->state->historyFromState($state));

        return $state;
    }

    /** Start a restore-from-backup run (files + every database in the backup). */
    public function startRestore(string $backupId, ?array $user): array
    {
        $this->assertNoStepInFlight();
        $this->assertNoLegacyRun();
        $backupId = basename($backupId);
        $existing = $this->state->get();
        if ($existing && ($existing['status'] ?? '') === 'running' && ! $this->state->isStalled($existing)) {
            throw new \RuntimeException(__('super.system_update.flash.in_progress'));
        }
        $dir = UpdaterPaths::backupDir($backupId);
        $index = $this->backups->databaseIndex($backupId);
        if (empty($this->backups->filesParts($backupId)) || empty($index)
            || ! File::exists(BackupManager::dumpPath($backupId, BackupManager::CENTRAL_DUMP))) {
            throw new \RuntimeException(__('super.system_update.flash.backup_incomplete'));
        }

        // Tenants deleted since the backup cannot be restored (no database
        // to restore into); every listed dump must exist.
        $databases = [];
        $skipped = [];
        foreach ($index as $target) {
            if (! File::exists(BackupManager::dumpPath($backupId, $target['file']))) {
                throw new \RuntimeException(__('super.system_update.flash.backup_incomplete'));
            }
            if ($target['tenant'] !== null && ! Tenant::find($target['tenant'])) {
                $skipped[] = $target['label'];
                continue;
            }
            $databases[] = $target;
        }

        // Tenants the backup left out (no pending migration back then) keep
        // their current database. That is only safe while their tables still
        // match the backup's code: if a later update migrated them, restoring
        // the old code over their newer schema would break them.
        $meta = json_decode((string) @file_get_contents($dir.DIRECTORY_SEPARATOR.BackupManager::META), true) ?: [];
        if (! empty($meta['skipped_tenants'])) {
            $codeMigrations = $this->backups->tenantMigrationsInBackup($backupId);
            $mismatch = [];
            foreach ($meta['skipped_tenants'] as $skippedTenant) {
                $tenant = Tenant::find($skippedTenant['tenant'] ?? '');
                if (! $tenant) {
                    continue;
                }
                $target = ['key' => 'tenant:'.$tenant->id, 'tenant' => (string) $tenant->id, 'label' => $skippedTenant['label'] ?? $tenant->id];
                $newer = UpdateTargets::run($target, fn () => array_diff(
                    \Illuminate\Support\Facades\DB::table('migrations')->pluck('migration')->all(),
                    $codeMigrations
                ));
                if ($newer) {
                    $mismatch[] = $target['label'];
                }
            }
            if ($mismatch) {
                throw new \RuntimeException(__('super.system_update.flash.restore_schema_mismatch', [
                    'tenants' => implode(', ', array_slice($mismatch, 0, 5)).(count($mismatch) > 5 ? ' (+'.(count($mismatch) - 5).')' : ''),
                ]));
            }
        }

        $runId = 'restore-'.date('Ymd-His').'-'.Str::lower(Str::random(6));
        if (! $this->state->acquireLock($runId)) {
            throw new \RuntimeException(__('super.system_update.flash.in_progress'));
        }

        $validator = new PackageValidator;
        $state = [
            'id' => $runId,
            'type' => 'restore',
            'status' => 'running',
            'phase' => 'rst_init',
            'from_version' => $validator->currentVersion(),
            'to_version' => $meta['version'] ?? null,
            'user' => $user,
            'started_at' => time(),
            'databases' => $databases,
            'modified' => [],
            'backup' => ['id' => $backupId, 'status' => 'available'],
            'staging' => ['dir' => UpdaterPaths::staging().DIRECTORY_SEPARATOR.$runId, 'cursor' => []],
            'maintenance' => ['secret' => Str::lower(Str::random(24)), 'on' => false],
            'swap_log' => [],
            'migrations' => ['started' => false, 'status' => 'n/a'],
            'pre_restore' => ['index' => 0, 'cursor' => [], 'tools' => []],
            'restore_db' => ['index' => 0, 'cursor' => []],
            'rollback' => ['status' => null],
            'error' => null,
            'log' => [],
        ];
        $this->state->log($state, "=== Restore of backup {$backupId} started by ".($user['name'] ?? 'unknown')
            .' — '.count($databases).' database(s) ===');
        if ($skipped) {
            $this->state->log($state, 'Skipped (tenant no longer exists): '.implode(', ', $skipped));
        }
        // Tenants the backup skipped on purpose keep their database (checked
        // above); only the rest were created after it.
        $newer = $this->tenantsMissingFrom(array_merge($index, $meta['skipped_tenants'] ?? []));
        if ($newer) {
            // The central restore brings back the tenants table as it was:
            // these tenants lose their platform records (domain, plan,
            // subscription); their own databases are left in place, unlinked.
            $this->state->log($state, 'Created after this backup — removed from the platform by the central restore (their databases are kept, unlinked): '.implode(', ', $newer));
        }
        $this->state->put($state);
        $this->state->recordHistory($this->state->historyFromState($state));

        return $state;
    }

    /**
     * Advance the state machine by one phase / chunk. Called repeatedly by
     * the UI (and by the recovery console after a crash).
     */
    public function step(): array
    {
        @ini_set('memory_limit', '1G');
        @set_time_limit(0);
        ignore_user_abort(true);
        $this->deadline = microtime(true) + self::TIME_BUDGET;

        // Serialize concurrent step calls (two tabs, double-click, poll
        // races): only one request may process a phase at a time; the
        // others just return the current state.
        UpdaterPaths::ensureDirectories();
        $mutex = fopen(UpdaterPaths::stepMutex(), 'c');
        if ($mutex && ! flock($mutex, LOCK_EX | LOCK_NB)) {
            fclose($mutex);

            return $this->state->get() ?? [];
        }

        try {
            return $this->stepLocked();
        } finally {
            if ($mutex) {
                flock($mutex, LOCK_UN);
                fclose($mutex);
            }
        }
    }

    private function stepLocked(): array
    {
        $state = $this->state->get();
        if (! $state) {
            throw new \RuntimeException(__('super.system_update.flash.no_run'));
        }
        if (($state['status'] ?? '') !== 'running') {
            return $state; // terminal — nothing to do
        }

        $phase = $state['phase'] ?? 'init';
        try {
            $completed = $this->runPhase($phase, $state);
            // A phase may set a terminal status itself (rb_finalize ends the
            // run as rolled_back) — never advance or overwrite it afterwards.
            if ($completed && ($state['status'] ?? '') === 'running') {
                $next = $this->nextPhase($state);
                if ($next === null) {
                    $this->finishSuccessfully($state);
                } else {
                    $state['phase'] = $next;
                    $this->state->log($state, "Phase completed: {$phase} → {$next}");
                }
            }
            $this->state->put($state);
        } catch (\Throwable $e) {
            // A tenant phase may have thrown while that tenant was still
            // initialized — never leave the rest of the request in it.
            if (function_exists('tenancy') && tenancy()->initialized) {
                tenancy()->end();
            }
            $this->handleFailure($state, $phase, $e);
        }

        $this->state->recordHistory($this->state->historyFromState($state));

        return $this->state->get() ?? $state;
    }

    /** Explicitly request a rollback for a failed / stalled / recovery run. */
    public function requestRollback(): array
    {
        $this->assertNoStepInFlight();
        $state = $this->state->get();
        if (! $state) {
            throw new \RuntimeException(__('super.system_update.flash.no_state'));
        }
        $status = $state['status'] ?? '';
        $stalled = $this->state->isStalled($state);
        $inRollback = in_array($state['phase'] ?? '', self::ROLLBACK_PHASES, true);
        if (! in_array($status, ['recovery_required', 'failed'], true) && ! ($status === 'running' && $stalled)) {
            throw new \RuntimeException(__('super.system_update.flash.rollback_not_allowed'));
        }
        if (! $inRollback) {
            $this->initiateRollback($state);
        }
        $state['status'] = 'running';
        $this->state->log($state, 'Rollback requested'.($stalled ? ' after interrupted update' : ''));
        $this->state->acquireLock($state['id'] ?? 'recovery');
        $this->state->put($state);

        return $state;
    }

    /** Resume a stalled run exactly where it stopped. */
    public function resume(): array
    {
        $this->assertNoStepInFlight();
        $state = $this->state->get();
        if ($state && ($state['status'] ?? '') === 'recovery_required') {
            // "Continue" on a run whose rollback failed means: retry the
            // rollback phase it stopped in.
            $this->requestRollback();

            return $this->step();
        }
        if (! $state || ($state['status'] ?? '') !== 'running') {
            throw new \RuntimeException(__('super.system_update.flash.nothing_to_resume'));
        }
        $this->state->log($state, 'Run resumed from phase '.($state['phase'] ?? '?'));
        $this->state->acquireLock($state['id'] ?? 'recovery');
        $this->state->put($state);

        return $this->step();
    }

    /** Clear a terminal state. */
    public function discard(): void
    {
        $this->assertNoStepInFlight();
        $state = $this->state->get();
        if ($state && ($state['status'] ?? '') === 'running' && ! $this->state->isStalled($state)) {
            throw new \RuntimeException(__('super.system_update.flash.cannot_discard_running'));
        }
        // A run that already modified files/databases may only be discarded
        // after its rollback finished — otherwise Resume/Rollback are the
        // only ways out (the state is the map back to a working system).
        if ($state && ! in_array($state['status'] ?? '', ['completed', 'failed', 'rolled_back'], true)) {
            $modified = ! empty($state['apply_done']) || ! empty($state['swap_log']) || ! empty($state['modified']);
            if ($modified) {
                throw new \RuntimeException(__('super.system_update.flash.discard_modified'));
            }
        }
        if ($state) {
            $staging = $state['staging']['dir'] ?? null;
            if ($staging && File::exists($staging)) {
                File::deleteDirectory($staging);
            }
            // Maintenance goes on as soon as a run starts, so a run abandoned
            // before it changed anything would otherwise leave the app down.
            if (! empty($state['maintenance']['on'])) {
                $this->maintenanceOff();
            }
        }
        $this->state->clear();
        $this->state->releaseLock();
    }

    // ---------------------------------------------------------------- phases

    /** Returns true when the phase is complete (advance), false to re-enter. */
    private function runPhase(string $phase, array &$state): bool
    {
        switch ($phase) {
            case 'init':
                return $this->phaseInit($state);
            case 'backup_db':
                return $this->phaseBackupDb($state);
            case 'backup_files':
                return $this->phaseBackupFiles($state);
            case 'backup_verify':
                return $this->phaseBackupVerify($state);
            case 'stage_extract':
            case 'rst_extract':
                return $this->phaseStageExtract($state);
            case 'stage_validate':
                return $this->phaseStageValidate($state);
            case 'maintenance_on':
                return $this->phaseMaintenanceOn($state);
            case 'apply':
            case 'rst_swap':
                return $this->phaseApply($state);
            case 'migrate_central':
                return $this->phaseMigrateCentral($state);
            case 'migrate_tenants':
                return $this->phaseMigrateTenants($state);
            case 'cache':
            case 'rst_cache':
                return $this->phaseCache($state);
            case 'verify':
                return $this->phaseVerify($state);
            case 'maintenance_off':
                return $this->phaseMaintenanceOff($state);
            case 'cleanup':
            case 'rst_cleanup':
                return $this->phaseCleanup($state);
            case 'rst_init':
                return $this->phaseRestoreInit($state);
            case 'rst_backup_db':
                return $this->phaseRestoreBackupDb($state);
            case 'rst_db':
                return $this->phaseRestoreDb($state);
            case 'rb_files':
                return $this->phaseRollbackFiles($state);
            case 'rb_db':
                return $this->phaseRollbackDb($state);
            case 'rb_finalize':
                return $this->phaseRollbackFinalize($state);
        }
        throw new \RuntimeException('Unknown update phase: '.$phase);
    }

    private function phaseInit(array &$state): bool
    {
        UpdaterPaths::ensureDirectories();
        // Sweep staging leftovers from previous (discarded/rolled-back) runs.
        foreach (File::directories(UpdaterPaths::staging()) as $dir) {
            if (basename($dir) !== ($state['id'] ?? '')) {
                try {
                    File::deleteDirectory($dir);
                } catch (\Throwable $e) {
                }
            }
        }
        $backupId = $this->backups->newBackupId($state['from_version'] ?? 'unknown');
        $this->backups->createBackupDir($backupId);
        $state['backup']['id'] = $backupId;
        $state['backup']['status'] = 'running';
        $count = $this->backups->buildManifest($backupId);
        $state['backup']['manifest_count'] = $count;
        $state['backup']['files_cursor'] = ['offset' => 0, 'position' => 0];
        $state['backup']['db'] = ['index' => 0, 'cursor' => [], 'tools' => []];
        // The index lists only the databases this backup holds; the tenants
        // left out (no pending migration) are recorded so a later restore
        // can tell whether their tables changed since.
        $this->backups->writeDatabaseIndex($backupId, $this->backupTargets($state));
        $skipped = array_values(array_filter($state['databases'], fn ($t) => ($t['backup'] ?? true) === false));
        $this->backups->backupEnvAndMeta($backupId, [
            'version' => $state['from_version'],
            'reason' => 'pre-update',
            'target_version' => $state['to_version'],
            'databases' => count($this->backupTargets($state)),
            'skipped_tenants' => array_map(fn ($t) => ['tenant' => $t['tenant'], 'label' => $t['label']], $skipped),
        ]);
        $this->state->log($state, "Backup {$backupId}: manifest built ({$count} files), .env and metadata copied");

        return true;
    }

    private function phaseBackupDb(array &$state): bool
    {
        $backupId = $state['backup']['id'];

        return $this->dumpDatabases(
            $state,
            $this->backupTargets($state),
            fn (array $target) => BackupManager::dumpPath($backupId, $target['file']),
            $state['backup']['db']
        );
    }

    private function phaseBackupFiles(array &$state): bool
    {
        $cursor = &$state['backup']['files_cursor'];
        $this->backups->zipChunk($state['backup']['id'], $cursor, fn () => $this->outOfBudget());
        if (! empty($cursor['done'])) {
            $this->state->log($state, 'Application backup archive finished');

            return true;
        }

        return false;
    }

    private function phaseBackupVerify(array &$state): bool
    {
        $backupId = $state['backup']['id'];
        $tools = $state['backup']['db']['tools'] ?? [];
        $verifier = new DatabaseBackupService;

        foreach ($this->backupTargets($state) as $target) {
            $path = BackupManager::dumpPath($backupId, $target['file']);
            if (! $verifier->verifyDump($path, ($tools[$target['file']] ?? '') === 'php')) {
                throw new \RuntimeException('Database backup of '.$target['label'].' could not be verified — update aborted before any change was made.');
            }
        }
        if (! $this->backups->verifyFilesZip($backupId)) {
            throw new \RuntimeException('Application file backup could not be verified — update aborted before any change was made.');
        }
        $this->backups->markComplete($backupId);
        $state['backup']['status'] = 'verified';
        $this->state->log($state, 'Backup verified (files + '.count($this->backupTargets($state)).' database dump(s))');

        return true;
    }

    private function phaseStageExtract(array &$state): bool
    {
        $cursor = &$state['staging']['cursor'];
        if ($state['type'] === 'restore') {
            // A backup is a series of part archives — extract them in order.
            $parts = $this->backups->filesParts($state['backup']['id']);
            $cursor += ['part' => 0, 'parts' => count($parts), 'sub' => [], 'entries' => 0];
            while ($cursor['part'] < count($parts)) {
                $sub = &$cursor['sub'];
                $this->files->extractChunk($parts[$cursor['part']], $state['staging']['dir'], null, $sub, fn () => $this->outOfBudget());
                unset($sub);
                if (empty($cursor['sub']['done'])) {
                    return false;
                }
                $cursor['entries'] += (int) ($cursor['sub']['total'] ?? 0);
                $cursor['part']++;
                $cursor['sub'] = [];
                if ($cursor['part'] < count($parts) && $this->outOfBudget()) {
                    return false;
                }
            }
            $this->state->log($state, 'Backup extraction complete ('.$cursor['entries'].' entries from '.count($parts).' part(s))');

            return true;
        }

        $zipPath = $state['package']['path'];
        $prefix = $state['package']['root_prefix'] ?? null;
        $this->files->extractChunk($zipPath, $state['staging']['dir'], $prefix, $cursor, fn () => $this->outOfBudget());
        if (! empty($cursor['done'])) {
            $this->state->log($state, 'Extraction complete ('.($cursor['total'] ?? '?').' entries)');

            return true;
        }

        return false;
    }

    private function phaseStageValidate(array &$state): bool
    {
        $problems = $this->files->validateStagedTree($state['staging']['dir'], $state['to_version'] ?? null);
        if (! empty($problems)) {
            throw new \RuntimeException('Staged package failed validation: '.implode(' | ', $problems));
        }
        $this->state->log($state, 'Staged tree validated');

        return true;
    }

    private function phaseMaintenanceOn(array &$state): bool
    {
        $secret = $state['maintenance']['secret'] ?? null;
        $params = ['--retry' => 30];
        if ($secret) {
            $params['--secret'] = $secret;
        }
        if (view()->exists('update.maintenance')) {
            $params['--render'] = 'update.maintenance';
        }
        // DownCommand builds the "except" list stored in the down file from
        // the BASE middleware class, so the $except of our subclass is not
        // seen there. Register the paths statically so the down file carries
        // them too — the updater's own step/status calls must keep working.
        $except = (new CheckForMaintenanceMode(app()))->getExcludedPaths();
        PreventRequestsDuringMaintenance::except($except);
        Artisan::call('down', $params);
        $this->ensureDownFileExcept($except);
        $state['maintenance']['on'] = true;
        $this->state->log($state, 'Maintenance mode enabled (central and every tenant domain)');

        return true;
    }

    private function phaseApply(array &$state): bool
    {
        if (empty($state['maintenance']['on'])) {
            $this->phaseMaintenanceOn($state);
        }
        // The swap is the point of no return for plain failure handling —
        // from here on, any error triggers the rollback phases.
        $replacedDir = $this->replacedDir($state);
        $stateService = $this->state;
        $this->files->swap($state['staging']['dir'], $replacedDir, function (array $entry) use (&$state, $stateService) {
            $state['swap_log'][] = $entry;
            $stateService->put($state);
        });
        $state['apply_done'] = true;
        $this->state->log($state, 'File swap completed ('.count($state['swap_log']).' items)');

        return true;
    }

    private function phaseMigrateCentral(array &$state): bool
    {
        $state['migrations']['started'] = true;
        $state['migrations']['status'] = 'running';
        $state['migrations']['central'] = 'running';
        $state['modified']['central'] = true;
        $this->state->put($state); // persist BEFORE running — rollback must know

        $output = (new PlatformUpgradeTasks)->central();

        $state['migrations']['central'] = 'completed';
        $this->state->log($state, 'Central database migrated'.$this->summarize($output));

        return true;
    }

    /** One tenant after another, as many as fit in the request budget (at least one). */
    private function phaseMigrateTenants(array &$state): bool
    {
        $tenants = $this->tenantTargets($state['databases']);
        $index = &$state['migrations']['index'];
        $tasks = new PlatformUpgradeTasks;

        while ($index < count($tenants)) {
            $target = $tenants[$index];
            $backedUp = ($target['backup'] ?? true) !== false;
            $state['migrations']['current'] = $target['label'];
            if ($backedUp) {
                // Only a dumped database can be rolled back; the others keep
                // their tables (no pending migration) and are left alone.
                $state['modified'][$target['key']] = true;
            }
            $this->state->put($state); // persist BEFORE running — rollback must know

            try {
                $output = UpdateTargets::run($target, function () use ($tasks, $backedUp, $target) {
                    if (! $backedUp && ($pending = $this->pendingTenantMigrations()) > 0) {
                        // Planned as unchanged, yet the new code would alter
                        // its tables — never migrate a database without a dump.
                        throw new \RuntimeException("{$pending} pending migration(s) on a database that was not backed up — stopping before touching it");
                    }

                    return $tasks->tenant();
                });
            } catch (\Throwable $e) {
                throw new \RuntimeException('Tenant '.$target['label'].': '.$e->getMessage(), 0, $e);
            }

            $index++;
            $state['migrations']['done'] = $index;
            $this->state->log($state, 'Tenant '.$target['label'].' migrated ('.$index.'/'.count($tenants).')'.$this->summarize($output));
            $this->state->put($state);

            if ($index < count($tenants) && $this->outOfBudget()) {
                return false;
            }
        }

        unset($state['migrations']['current']);
        $state['migrations']['status'] = 'completed';
        $this->state->log($state, 'All '.count($tenants).' tenant database(s) migrated');

        return true;
    }

    private function phaseCache(array &$state): bool
    {
        // The application cache is shared by central + every tenant (tenant
        // keys are prefixed onto the same store), so one flush covers all;
        // tenants' file caches were cleared tenant by tenant while migrating.
        foreach (['optimize:clear', 'cache:clear', 'config:clear', 'view:clear', 'route:clear'] as $cmd) {
            try {
                Artisan::call($cmd);
            } catch (\Throwable $e) {
                $this->state->log($state, "Cache command {$cmd} reported: ".$e->getMessage());
            }
        }
        $this->restartQueueWorkers($state);
        $this->state->log($state, 'Caches cleared');

        return true;
    }

    private function phaseVerify(array &$state): bool
    {
        $report = (new HealthChecker)->verify($state['to_version'] ?? null, $state['databases']);
        $state['health'] = $report;
        if (! $report['ok']) {
            $failed = array_values(array_filter($report['checks'], fn ($c) => ! $c['ok'] && $c['level'] === 'critical'));
            $labels = implode(' | ', array_map(fn ($c) => $c['label'].($c['detail'] ? ' — '.$c['detail'] : ''), $failed));
            throw new \RuntimeException('Post-update verification failed: '.$labels);
        }
        $this->state->log($state, 'Post-update health checks passed');

        return true;
    }

    private function phaseMaintenanceOff(array &$state): bool
    {
        $this->maintenanceOff();
        if (File::exists(UpdaterPaths::storage('framework/down'))) {
            throw new \RuntimeException('Could not disable maintenance mode (storage/framework/down is not deletable).');
        }
        $state['maintenance']['on'] = false;
        $this->state->log($state, 'Maintenance mode disabled');

        return true;
    }

    private function phaseCleanup(array &$state): bool
    {
        // The update is verified and live at this point — cleanup problems
        // must never fail (let alone roll back) the run.
        try {
            $staging = $state['staging']['dir'] ?? null;
            if ($staging && File::exists($staging)) {
                File::deleteDirectory($staging);
            }
            // The "replaced" dir holds the old tree moved out by the swap; the
            // durable backup ZIP + DB dumps remain per the retention policy.
            $replacedDir = $this->replacedDir($state);
            if (File::exists($replacedDir)) {
                File::deleteDirectory($replacedDir);
            }
            if (($state['type'] ?? '') === 'update') {
                @File::delete(UpdaterPaths::packageZip());
                @File::delete(UpdaterPaths::packageMeta());
                $config = $this->state->config();
                $this->backups->applyRetention((int) ($config['retention'] ?? 3));
            } else {
                $dir = $this->preRestoreDir($state);
                if (File::exists($dir)) {
                    File::deleteDirectory($dir);
                }
            }
            $this->state->log($state, 'Cleanup finished');
        } catch (\Throwable $e) {
            $this->state->log($state, 'Cleanup reported a non-fatal problem: '.$e->getMessage());
        }

        return true;
    }

    private function phaseRestoreInit(array &$state): bool
    {
        $backupId = $state['backup']['id'];
        if (! $this->backups->verifyFilesZip($backupId)) {
            throw new \RuntimeException('Backup archive failed verification and cannot be restored.');
        }
        $state['staging']['cursor'] = [];
        $this->state->log($state, "Backup {$backupId} verified — starting restore");

        return true;
    }

    /** Safety net for restores: dump every CURRENT database before overwriting it. */
    private function phaseRestoreBackupDb(array &$state): bool
    {
        $dir = $this->preRestoreDir($state);
        if (! File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
        $done = $this->dumpDatabases(
            $state,
            $state['databases'],
            fn (array $target) => $dir.DIRECTORY_SEPARATOR.$target['file'],
            $state['pre_restore']
        );
        if (! $done) {
            return false;
        }

        $verifier = new DatabaseBackupService;
        foreach ($state['databases'] as $target) {
            $php = ($state['pre_restore']['tools'][$target['file']] ?? '') === 'php';
            if (! $verifier->verifyDump($dir.DIRECTORY_SEPARATOR.$target['file'], $php)) {
                throw new \RuntimeException('Could not create a safety dump of '.$target['label'].' — restore aborted before any change.');
            }
        }
        $this->state->log($state, 'Safety dumps of the current databases created');

        return true;
    }

    private function phaseRestoreDb(array &$state): bool
    {
        $backupId = $state['backup']['id'];

        return $this->restoreDatabases(
            $state,
            $this->restoreOrder($state['databases']),
            fn (array $target) => BackupManager::dumpPath($backupId, $target['file']),
            $state['restore_db'],
            true
        );
    }

    // ------------------------------------------------------------- rollback

    private function phaseRollbackFiles(array &$state): bool
    {
        $replacedDir = $this->replacedDir($state);
        if (! empty($state['swap_log'])) {
            $this->files->reverseSwap($state['swap_log'], $replacedDir);
            $state['swap_log'] = [];
            $this->state->log($state, 'File rollback completed (swap reversed)');
        } else {
            $this->state->log($state, 'File rollback: nothing to reverse');
        }

        return true;
    }

    /** Restore every database the run touched from the backup it took first. */
    private function phaseRollbackDb(array &$state): bool
    {
        $keys = $state['rollback']['keys'] ?? [];
        if (empty($keys)) {
            $this->state->log($state, 'Database rollback not needed (no database was modified)');

            return true;
        }
        $targets = array_values(array_filter(
            $this->restoreOrder($state['databases']),
            fn ($t) => in_array($t['key'], $keys, true) && ($t['backup'] ?? true) !== false
        ));
        $source = $state['rollback']['source'] ?? 'backup';
        $backupId = $state['backup']['id'] ?? '';
        $preDir = $this->preRestoreDir($state);
        $pathFor = $source === 'pre_restore'
            ? fn (array $t) => $preDir.DIRECTORY_SEPARATOR.$t['file']
            : fn (array $t) => BackupManager::dumpPath($backupId, $t['file']);

        foreach ($targets as $target) {
            if (! File::exists($pathFor($target))) {
                throw new \RuntimeException('Database backup of '.$target['label'].' is missing — cannot roll it back automatically.');
            }
        }

        $done = $this->restoreDatabases($state, $targets, $pathFor, $state['rollback']['db'], false);
        if ($done) {
            $state['migrations']['status'] = 'rolled_back';
        }

        return $done;
    }

    private function phaseRollbackFinalize(array &$state): bool
    {
        foreach (['optimize:clear', 'cache:clear', 'config:clear', 'view:clear', 'route:clear'] as $cmd) {
            try {
                Artisan::call($cmd);
            } catch (\Throwable $e) {
            }
        }
        $this->restartQueueWorkers($state);
        try {
            $this->maintenanceOff();
            $state['maintenance']['on'] = false;
        } catch (\Throwable $e) {
        }
        $staging = $state['staging']['dir'] ?? null;
        if ($staging && File::exists($staging)) {
            try {
                File::deleteDirectory($staging);
            } catch (\Throwable $e) {
            }
        }
        $state['rollback']['status'] = 'completed';
        $state['status'] = 'rolled_back';
        $state['finished_at'] = time();
        $this->state->log($state, '=== Rollback completed — the previous version and every database were restored ===');
        $this->state->releaseLock();

        return true;
    }

    // ------------------------------------------------- multi-database helpers

    /**
     * Dump $targets one after another into $pathFor($target). $progress
     * (['index','cursor','tools']) lives in the state so the loop resumes at
     * the right database — and the right table / row — on the next request.
     */
    private function dumpDatabases(array &$state, array $targets, callable $pathFor, array &$progress): bool
    {
        $progress += ['index' => 0, 'cursor' => [], 'tools' => []];

        while ($progress['index'] < count($targets)) {
            $target = $targets[$progress['index']];
            $path = $pathFor($target);
            if (! File::exists(dirname($path))) {
                File::makeDirectory(dirname($path), 0755, true);
            }
            $cursor = &$progress['cursor'];

            $done = UpdateTargets::run($target, function (string $connection) use ($path, &$cursor, &$state, $target) {
                $service = new DatabaseBackupService($connection);
                if (empty($cursor['tool'])) {
                    if ($service->dumpWithBinary($path)) {
                        $cursor = ['tool' => 'mysqldump', 'done' => true];

                        return true;
                    }
                    $cursor['tool'] = 'php';
                    $this->state->log($state, 'mysqldump unavailable for '.$target['label'].' ('.($service->lastError ?? '?').') — using chunked PHP dumper');
                }
                $service->dumpChunkPhp($path, $cursor, fn () => $this->outOfBudget());

                return ! empty($cursor['done']);
            });
            unset($cursor);

            if (! $done) {
                return false; // budget spent mid-database; resumes at the cursor
            }

            $progress['tools'][$target['file']] = $progress['cursor']['tool'] ?? 'php';
            $progress['index']++;
            $progress['cursor'] = [];
            $this->state->log($state, 'Database '.$target['label'].' dumped ('.$this->fmtSize($path).') — '
                .$progress['index'].'/'.count($targets));
            $this->state->put($state);

            if ($progress['index'] < count($targets) && $this->outOfBudget()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Replay a dump into each of $targets. With $markModified (restore runs)
     * each database is flagged before it is touched, so a failure rolls it
     * back from the pre-restore safety dump.
     */
    private function restoreDatabases(array &$state, array $targets, callable $pathFor, array &$progress, bool $markModified): bool
    {
        $progress += ['index' => 0, 'cursor' => []];
        if ($markModified) {
            $state['db_modified'] = true;
        }

        while ($progress['index'] < count($targets)) {
            $target = $targets[$progress['index']];
            if ($markModified && empty($state['modified'][$target['key']])) {
                $state['modified'][$target['key']] = true;
                $this->state->put($state);
            }
            $sqlPath = $pathFor($target);
            $cursor = &$progress['cursor'];

            $done = UpdateTargets::run($target, function (string $connection) use ($sqlPath, &$cursor, &$state, $target) {
                $service = new DatabaseBackupService($connection);
                // The tables are NOT dropped up front. Every step request has
                // to authenticate the super admin against the central
                // database, so a restore that wipes it and then spans several
                // requests could never be continued. Instead each table is
                // dropped and recreated by the dump's own DROP TABLE IF
                // EXISTS / CREATE TABLE right before its rows, the PHP
                // executor only yields between tables, and tables the dump
                // does not contain are removed at the end.
                if (empty($cursor['tried_binary'])) {
                    $cursor['tried_binary'] = true;
                    $this->state->put($state);
                    if ($service->restoreWithBinary($sqlPath)) {
                        $service->dropTablesNotInDump($sqlPath);
                        $cursor['done'] = true;

                        return true;
                    }
                    $this->state->log($state, 'mysql client unavailable for '.$target['label'].' ('.($service->lastError ?? '?').') — restoring via chunked PHP executor');
                    $cursor['offset'] = 0;
                }
                $service->restoreChunkPhp($sqlPath, $cursor, fn () => $this->outOfBudget());

                return ! empty($cursor['done']);
            });
            unset($cursor);

            if (! $done) {
                return false;
            }

            $progress['index']++;
            $progress['cursor'] = [];
            $this->state->log($state, 'Database '.$target['label'].' restored — '.$progress['index'].'/'.count($targets));
            $this->state->put($state);

            if ($progress['index'] < count($targets) && $this->outOfBudget()) {
                return false;
            }
        }

        return true;
    }

    /** The databases this run dumps (all of them for restores and older states). */
    private function backupTargets(array $state): array
    {
        return array_values(array_filter($state['databases'] ?? [], fn ($t) => ($t['backup'] ?? true) !== false));
    }

    /** Tenant migrations on disk (the code now installed) the current tenant has not run. */
    private function pendingTenantMigrations(): int
    {
        $files = array_map(
            fn ($f) => PackageValidator::migrationName($f),
            File::glob(database_path('migrations'.DIRECTORY_SEPARATOR.'tenant').DIRECTORY_SEPARATOR.'*.php') ?: []
        );
        $ran = \Illuminate\Support\Facades\DB::table('migrations')->pluck('migration')->all();

        return count(array_diff($files, $ran));
    }

    /** Tenant targets only, in run order. */
    private function tenantTargets(array $databases): array
    {
        return array_values(array_filter($databases, fn ($t) => ($t['tenant'] ?? null) !== null));
    }

    /**
     * Tenants first, central last: the central database holds the super
     * admin's account, so it stays untouched for as long as possible.
     */
    private function restoreOrder(array $databases): array
    {
        $central = array_values(array_filter($databases, fn ($t) => ($t['tenant'] ?? null) === null));

        return array_merge($this->tenantTargets($databases), $central);
    }

    /** Live tenants (with a database) that a backup index does not cover. */
    private function tenantsMissingFrom(array $index): array
    {
        $covered = array_filter(array_map(fn ($t) => $t['tenant'] ?? null, $index));
        $missing = [];
        foreach ((new UpdateTargets)->databases() as $target) {
            if ($target['tenant'] !== null && ! in_array($target['tenant'], $covered, true)) {
                $missing[] = $target['label'];
            }
        }

        return $missing;
    }

    // -------------------------------------------------------------- helpers

    /** Mutating entry points must not interleave with a phase being executed. */
    private function assertNoStepInFlight(): void
    {
        if ($this->state->stepInProgress()) {
            throw new \RuntimeException(__('super.system_update.flash.step_in_flight'));
        }
    }

    /** The migration-only /update page has its own lock — never run both. */
    private function assertNoLegacyRun(): void
    {
        if (File::exists(UpdaterPaths::storage('app/'.EnsureNotUpdating::LOCK_FILE))) {
            throw new \RuntimeException(__('super.system_update.flash.legacy_running'));
        }
    }

    /**
     * Long-running queue workers (Supervisor, see deploy/) keep the code they
     * booted with in memory: after a swap — either way — they must exit
     * after their current job so Supervisor restarts them on the code now on
     * disk. The signal lives in the cache, so this runs after cache:clear.
     */
    private function restartQueueWorkers(array &$state): void
    {
        try {
            Artisan::call('queue:restart');
            $this->state->log($state, 'Queue workers signalled to restart on the code now on disk');
        } catch (\Throwable $e) {
            $this->state->log($state, 'queue:restart reported: '.$e->getMessage());
        }
    }

    private function maintenanceOff(): void
    {
        try {
            Artisan::call('up');
        } catch (\Throwable $e) {
            // Last resort: maintenance mode is just a marker file.
            @File::delete(UpdaterPaths::storage('framework/down'));
        }
    }

    /**
     * Belt and braces for phaseMaintenanceOn(): make sure the down file
     * really carries the excluded paths, whatever the framework did.
     */
    private function ensureDownFileExcept(array $except): void
    {
        $file = UpdaterPaths::storage('framework/down');
        if (! File::exists($file)) {
            return;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (! is_array($data)) {
            return;
        }
        $current = (array) ($data['except'] ?? []);
        $merged = array_values(array_unique(array_merge($current, $except)));
        if ($merged !== $current) {
            $data['except'] = $merged;
            File::put($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    private function nextPhase(array $state): ?string
    {
        $phase = $state['phase'];
        $list = match (true) {
            in_array($phase, self::ROLLBACK_PHASES, true) => self::ROLLBACK_PHASES,
            ($state['type'] ?? 'update') === 'restore' => self::RESTORE_PHASES,
            default => self::UPDATE_PHASES,
        };
        $idx = array_search($phase, $list, true);
        if ($idx === false || $idx + 1 >= count($list)) {
            return null;
        }

        return $list[$idx + 1];
    }

    private function finishSuccessfully(array &$state): void
    {
        $state['status'] = 'completed';
        $state['finished_at'] = time();
        $label = ($state['type'] ?? 'update') === 'restore'
            ? 'Restore of backup '.$state['backup']['id']
            : 'Update to v'.($state['to_version'] ?? '?');
        $this->state->log($state, "=== {$label} completed successfully ===");
        $this->state->releaseLock();
    }

    private function handleFailure(array &$state, string $phase, \Throwable $e): void
    {
        $this->state->log($state, "FAILURE in phase {$phase}: ".$e->getMessage());
        $state['error'] = ['phase' => $phase, 'message' => $e->getMessage(), 'time' => date('Y-m-d H:i:s')];

        if (in_array($phase, self::ROLLBACK_PHASES, true)) {
            // The rollback itself failed — recoverable state, manual action.
            $state['status'] = 'recovery_required';
            $state['rollback']['status'] = 'failed';
            $this->state->log($state, 'ROLLBACK FAILED — entering recovery mode. A verified backup is available at: '
                .UpdaterPaths::backupDir($state['backup']['id'] ?? ''));
            $this->state->put($state);

            return;
        }

        $modified = ! empty($state['apply_done'])
            || in_array($phase, self::MODIFYING_PHASES, true)
            || ! empty($state['swap_log'])
            || ! empty($state['modified']);

        if ($modified) {
            // Automatic rollback via the state machine.
            $this->initiateRollback($state);
            $this->state->log($state, 'Starting automatic rollback of the whole platform');
            $this->state->put($state);

            return;
        }

        // Nothing was modified — fail cleanly.
        if (! empty($state['maintenance']['on'])) {
            try {
                $this->maintenanceOff();
                $state['maintenance']['on'] = false;
            } catch (\Throwable $up) {
            }
        }
        $state['status'] = 'failed';
        $state['finished_at'] = time();
        $this->state->log($state, 'Update failed before any change was made — the platform is untouched.');
        $this->state->put($state);
        $this->state->releaseLock();
    }

    /** Put the run into rollback mode with the right DB restore sources. */
    private function initiateRollback(array &$state): void
    {
        $state['rollback'] = [
            'status' => 'running',
            'keys' => array_keys(array_filter($state['modified'] ?? [])),
            'source' => ($state['type'] ?? 'update') === 'restore' ? 'pre_restore' : 'backup',
            'db' => ['index' => 0, 'cursor' => []],
        ];
        $state['phase'] = 'rb_files';
    }

    private function replacedDir(array $state): string
    {
        $backupId = $state['backup']['id'] ?? 'unknown';

        return UpdaterPaths::backupDir($backupId).DIRECTORY_SEPARATOR.'replaced';
    }

    private function preRestoreDir(array $state): string
    {
        return UpdaterPaths::work().DIRECTORY_SEPARATOR.'pre-restore-'.($state['id'] ?? 'run');
    }

    private function outOfBudget(): bool
    {
        return microtime(true) > $this->deadline;
    }

    /** Last line of an artisan output, for the run log. */
    private function summarize(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $output))));

        return $lines ? ' — '.mb_substr(end($lines), 0, 160) : '';
    }

    private function fmtSize(string $path): string
    {
        $bytes = File::exists($path) ? File::size($path) : 0;
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }

    // --------------------------------------------------------------- status

    /** Full status payload for the super-admin UI. */
    public function status(): array
    {
        $state = $this->state->get();
        $stalled = $this->state->isStalled($state);
        $config = $this->state->config();

        $packageMeta = null;
        if (File::exists(UpdaterPaths::packageMeta())) {
            $packageMeta = json_decode((string) @file_get_contents(UpdaterPaths::packageMeta()), true);
        }

        return [
            'current_version' => (new PackageValidator)->currentVersion(),
            'state' => $state ? $this->publicState($state, $stalled) : null,
            'package' => $packageMeta,
            'history' => array_reverse($this->state->history()),
            'backups' => $this->backups->listBackups(),
            'retention' => (int) ($config['retention'] ?? 3),
        ];
    }

    private function publicState(array $state, bool $stalled): array
    {
        [$percent, $stepLabel] = $this->progressOf($state);

        return [
            'id' => $state['id'] ?? null,
            'type' => $state['type'] ?? 'update',
            'status' => $state['status'] ?? null,
            'phase' => $state['phase'] ?? null,
            'stalled' => $stalled,
            'percent' => $percent,
            'step_label' => $stepLabel,
            'from_version' => $state['from_version'] ?? null,
            'to_version' => $state['to_version'] ?? null,
            'started_at' => isset($state['started_at']) ? date('Y-m-d H:i:s', $state['started_at']) : null,
            'error' => $state['error'] ?? null,
            'rollback' => isset($state['rollback']) ? array_diff_key($state['rollback'], ['db' => true]) : null,
            'backup_id' => $state['backup']['id'] ?? null,
            'databases' => count($state['databases'] ?? []),
            'db_progress' => $this->dbProgressOf($state),
            'maintenance_secret' => (! empty($state['maintenance']['on'])) ? ($state['maintenance']['secret'] ?? null) : null,
            'health' => $state['health'] ?? null,
            'log_tail' => array_slice($state['log'] ?? [], -40),
        ];
    }

    /** [done, total] of the per-database loop the current phase is in, or null. */
    private function dbProgressOf(array $state): ?array
    {
        $total = count($state['databases'] ?? []);
        switch ($state['phase'] ?? '') {
            case 'backup_db':
                return ['done' => (int) ($state['backup']['db']['index'] ?? 0), 'total' => count($this->backupTargets($state))];
            case 'rst_backup_db':
                return ['done' => (int) ($state['pre_restore']['index'] ?? 0), 'total' => $total];
            case 'rst_db':
                return ['done' => (int) ($state['restore_db']['index'] ?? 0), 'total' => $total];
            case 'migrate_tenants':
                return [
                    'done' => (int) ($state['migrations']['index'] ?? 0),
                    'total' => count($this->tenantTargets($state['databases'] ?? [])),
                    'current' => $state['migrations']['current'] ?? null,
                ];
            case 'rb_db':
                return ['done' => (int) ($state['rollback']['db']['index'] ?? 0), 'total' => count($state['rollback']['keys'] ?? [])];
        }

        return null;
    }

    private function progressOf(array $state): array
    {
        $phase = $state['phase'] ?? 'init';
        $map = [
            // phase => [start%, end%]
            'init' => [0, 2], 'backup_files' => [2, 25], 'stage_extract' => [25, 33], 'stage_validate' => [33, 35],
            'maintenance_on' => [35, 36], 'backup_db' => [36, 52], 'backup_verify' => [52, 55],
            'apply' => [55, 62], 'migrate_central' => [62, 66], 'migrate_tenants' => [66, 88],
            'cache' => [88, 90], 'verify' => [90, 95],
            'maintenance_off' => [95, 97], 'cleanup' => [97, 100],
            'rst_init' => [0, 3], 'rst_extract' => [3, 22], 'rst_backup_db' => [22, 40], 'rst_swap' => [40, 50],
            'rst_db' => [50, 88], 'rst_cache' => [88, 92], 'rst_cleanup' => [97, 100],
            'rb_files' => [0, 30], 'rb_db' => [30, 90], 'rb_finalize' => [90, 100],
        ];
        [$from, $to] = $map[$phase] ?? [0, 100];

        $fraction = 0.0;
        if ($phase === 'backup_files') {
            $total = max(1, (int) ($state['backup']['manifest_count'] ?? 1));
            $fraction = min(1, ($state['backup']['files_cursor']['position'] ?? 0) / $total);
        } elseif ($phase === 'rst_extract') {
            $total = max(1, (int) ($state['staging']['cursor']['parts'] ?? 1));
            $fraction = min(1, ($state['staging']['cursor']['part'] ?? 0) / $total);
        } elseif ($phase === 'stage_extract') {
            $total = max(1, (int) ($state['staging']['cursor']['total'] ?? 1));
            $fraction = min(1, ($state['staging']['cursor']['index'] ?? 0) / $total);
        } elseif ($db = $this->dbProgressOf($state)) {
            $fraction = $db['total'] > 0 ? min(1, $db['done'] / $db['total']) : 1;
        }

        if (($state['status'] ?? '') === 'completed') {
            return [100, 'completed'];
        }

        return [(int) round($from + ($to - $from) * $fraction), $phase];
    }
}
