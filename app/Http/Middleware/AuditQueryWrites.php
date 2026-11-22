<?php

namespace App\Http\Middleware;

use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditRegistry;
use Closure;
use Illuminate\Http\Request;

/**
 * Audit log safety net for writes done with a query. Many controllers update
 * or soft-delete with `Model::whereId($id)->update([...])`, which fires no
 * model event, so AuditObserver never sees it. For update / destroy /
 * delete_by_selection on a controller in AuditRegistry::CONTROLLERS, the
 * row(s) are read before and after the request, and whatever the observer did
 * not already log is logged from that before/after pair.
 */
class AuditQueryWrites
{
    public function handle(Request $request, Closure $next)
    {
        $watch = null;

        try {
            $watch = $this->watch($request);
            if ($watch) {
                $watch['before'] = app(AuditRecorder::class)->snapshot($watch['type'], $watch['ids']);
            }
        } catch (\Throwable $e) {
            $watch = null; // auditing must never break the request
        }

        $response = $next($request);

        try {
            if ($watch && $response->getStatusCode() < 300) {
                app(AuditRecorder::class)->reconcile($watch['type'], $watch['ids'], $watch['before']);
            }
        } catch (\Throwable $e) {
            // auditing must never break the request
        }

        return $response;
    }

    /** @return array{type: string, ids: array}|null */
    private function watch(Request $request): ?array
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return null;
        }

        $route = $request->route();
        $action = $route ? $route->getActionMethod() : null;
        if (! $action || ! in_array($action, AuditRegistry::WRITE_ACTIONS, true)) {
            return null;
        }

        $type = AuditRegistry::typeForController($route->getControllerClass());
        if (! $type || ! app(AuditRecorder::class)->enabled()) {
            return null;
        }

        $ids = in_array($action, ['delete_by_selection', 'deleteBySelection'], true)
            ? (array) $request->input('selectedIds', [])
            : [last($route->parameters())];

        return ['type' => $type, 'ids' => $ids];
    }
}
