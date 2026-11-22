<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\TenantSubscription;
use App\Services\SuperDashboard\WidgetData;
use App\Support\SuperDashboard\DashboardLayout;
use App\Support\SuperDashboard\WidgetRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    /** Period chips in the dashboard header: key => days back (mtd = month to date). */
    public const PERIODS = ['7d' => 7, '30d' => 30, '90d' => 90, 'mtd' => null];

    /**
     * The dashboard is a grid of widgets laid out in Settings > General >
     * Dashboard. Only the enabled widgets this admin may see are built.
     */
    public function index(Request $request, WidgetData $data)
    {
        // Flip lapsed trials/periods to expired first so the counts below are
        // truthful without the cron.
        TenantSubscription::expireLapsed();

        $user = Auth::guard('central')->user();
        $layout = DashboardLayout::current();
        $data->forUser($user);

        // Period chip: a backward-looking window applied to every widget that
        // has a "days" or "range" option. Remembered for the session; absent
        // (the default) each widget keeps its own configured period.
        $period = $this->resolvePeriod($request);
        $periodDays = $period === null ? null : ($period === 'mtd' ? max(1, (int) now()->startOfMonth()->diffInDays(now()) + 1) : self::PERIODS[$period]);

        $widgets = [];
        $pendingTenants = null;
        foreach (DashboardLayout::visibleWidgets($layout, $user) as $key => $widget) {
            $definition = WidgetRegistry::get($key);
            $options = $widget['options'];
            if ($periodDays !== null) {
                foreach (['days', 'range'] as $name) {
                    if (array_key_exists($name, $options)) {
                        $options[$name] = $periodDays;
                    }
                }
            }
            try {
                $payload = $data->build($key, $options, (int) $layout['settings']['cache']);
            } catch (\Throwable $e) {
                // One broken widget (a missing table on an old install, say)
                // must not take the whole dashboard down.
                Log::warning("Super dashboard widget {$key} failed: {$e->getMessage()}");
                continue;
            }
            if ($key === 'pending_tenants') {
                // The topbar notifications list the same tenants; hand them
                // to the layout so it does not run the query again.
                $pendingTenants = $payload['tenants'] ?? collect();
            }
            if ($payload === null) {
                continue;
            }
            $widgets[] = $widget + [
                'group'  => $definition['group'],
                'icon'   => $definition['icon'],
                'column' => WidgetRegistry::columnClass($widget['width']),
                'data'   => $payload,
            ];
        }

        return view('central.super.dashboard', [
            'widgets'  => $widgets,
            'settings' => $layout['settings'],
            'period'   => $period,
            'periods'  => array_keys(self::PERIODS),
        ] + ($pendingTenants !== null ? ['pendingTenants' => $pendingTenants] : []));
    }

    /** The chosen period key, or null for "each widget's own setting". */
    private function resolvePeriod(Request $request): ?string
    {
        if ($request->has('period')) {
            $chosen = (string) $request->query('period');
            if ($chosen === '' || ! array_key_exists($chosen, self::PERIODS)) {
                $request->session()->forget('super_dashboard_period');

                return null;
            }
            $request->session()->put('super_dashboard_period', $chosen);

            return $chosen;
        }

        $stored = $request->session()->get('super_dashboard_period');

        return is_string($stored) && array_key_exists($stored, self::PERIODS) ? $stored : null;
    }
}
