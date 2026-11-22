<?php

namespace App\Observers;

use App\Services\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Model;

/**
 * Attached to every App\Services\Audit\AuditRegistry::MODELS class in
 * AppServiceProvider. Restores are logged from `updated` (deleted_at → null),
 * which fires for both the manual and the SoftDeletes restore paths.
 */
class AuditObserver
{
    public function created(Model $model): void
    {
        app(AuditRecorder::class)->created($model);
    }

    public function updated(Model $model): void
    {
        app(AuditRecorder::class)->updated($model);
    }

    public function deleted(Model $model): void
    {
        app(AuditRecorder::class)->deleted($model);
    }
}
