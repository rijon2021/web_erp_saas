<?php

namespace App\Http\Controllers;

use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmPipeline;
use App\Models\CrmTarget;
use App\Models\User;
use App\Services\Crm\CrmLeadService;
use App\Services\Crm\CrmReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CRM Reports (permission crm_reports) and sales targets (crm_targets to
 * set them; anyone with the dashboard sees their own progress there).
 */
class CrmReportController extends Controller
{
    private const SECTIONS = ['overview', 'winloss', 'forecast', 'reps', 'activities', 'sources'];

    public function __construct(private CrmReportService $reports)
    {
    }

    public function meta(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'reports', CrmLead::class);
        $viewAll = $user->can('viewAll', CrmLead::class);

        return response()->json([
            'view_all' => $viewAll,
            'owners' => $viewAll ? $this->salesUsers()->map(fn ($u) => ['id' => $u->id, 'name' => CrmLeadService::userName($u)])->values() : [],
            'pipelines' => CrmPipeline::orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'is_default']),
            'can_targets' => $user->can('targets', CrmLead::class),
        ]);
    }

    public function show(Request $request, string $section)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'reports', CrmLead::class);
        abort_unless(in_array($section, self::SECTIONS, true), 404);
        [$from, $to] = $this->range($request);
        $scope = $this->reports->scopeFor($user, $request->get('owner'));

        $data = match ($section) {
            'overview' => $this->reports->overview($scope, $from, $to, $request->integer('pipeline') ?: null),
            'winloss' => $this->reports->winLoss($scope, $from, $to),
            'forecast' => $this->reports->forecast($scope),
            'reps' => $this->reports->reps($scope, $from, $to),
            'activities' => $this->reports->activities($scope, $from, $to),
            'sources' => $this->reports->sources($scope, $from, $to),
        };

        return response()->json($data + ['range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()]]);
    }

    // ----- targets -----

    /** The grid for one period: reps × metrics, with live actuals. */
    public function targets(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'targets', CrmLead::class);
        $type = in_array($request->get('period_type'), CrmTarget::PERIODS, true) ? $request->get('period_type') : 'month';
        [$from, $to] = CrmTarget::bounds($type, $request->get('period_start') ?: now());

        $users = $this->salesUsers();
        $progress = $this->reports->targetProgress($type, $from)->groupBy(fn ($t) => $t['user_id'] ?? 'team');
        $actuals = [];
        foreach (array_merge(['team'], $users->pluck('id')->all()) as $uid) {
            foreach (array_keys(CrmTarget::METRICS) as $metric) {
                $actuals[$uid][$metric] = round($this->reports->actual($metric, $uid === 'team' ? null : (int) $uid, $from, $to), 2);
            }
        }

        return response()->json([
            'period_type' => $type,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'metrics' => collect(CrmTarget::METRICS)->map(fn ($money, $key) => ['key' => $key, 'money' => $money])->values(),
            'users' => $users->map(fn ($u) => ['id' => $u->id, 'name' => CrmLeadService::userName($u)])->values(),
            'targets' => $progress->map(fn ($g) => $g->keyBy('metric'))->all(),
            'actuals' => $actuals,
        ]);
    }

    /** Save the grid: rows of {user_id|null, metric, value}; an empty value removes the target. */
    public function saveTargets(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'targets', CrmLead::class);
        $request->validate([
            'period_type' => ['required', Rule::in(CrmTarget::PERIODS)],
            'period_start' => 'required|date',
            'rows' => 'present|array|max:2000',
            'rows.*.user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'rows.*.metric' => ['required', Rule::in(array_keys(CrmTarget::METRICS))],
            'rows.*.value' => 'nullable|numeric|min:0|max:999999999999',
        ]);
        [$from] = CrmTarget::bounds($request->period_type, $request->period_start);

        $saved = DB::transaction(function () use ($request, $from, $user) {
            $n = 0;
            foreach ($request->rows as $row) {
                $match = ['user_id' => $row['user_id'] ?? null, 'period_type' => $request->period_type, 'period_start' => $from->toDateString(), 'metric' => $row['metric']];
                $q = CrmTarget::where('period_type', $match['period_type'])->whereDate('period_start', $match['period_start'])->where('metric', $match['metric'])
                    ->when($match['user_id'], fn ($w) => $w->where('user_id', $match['user_id']), fn ($w) => $w->whereNull('user_id'));
                if (! isset($row['value']) || $row['value'] === '' || $row['value'] === null || (float) $row['value'] <= 0) {
                    $q->delete();
                    continue;
                }
                $existing = $q->first();
                if ($existing) {
                    $existing->update(['target_value' => round((float) $row['value'], 2)]);
                } else {
                    CrmTarget::create($match + ['target_value' => round((float) $row['value'], 2), 'created_by' => $user->id]);
                }
                $n++;
            }

            return $n;
        });

        return response()->json(['success' => true, 'saved' => $saved]);
    }

    /** Copy every target of the previous period into this one (existing ones are kept). */
    public function copyTargets(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'targets', CrmLead::class);
        $request->validate(['period_type' => ['required', Rule::in(CrmTarget::PERIODS)], 'period_start' => 'required|date']);
        $type = $request->period_type;
        [$to] = CrmTarget::bounds($type, $request->period_start);
        $prev = $type === 'quarter' ? $to->copy()->subMonthsNoOverflow(3) : $to->copy()->subMonthNoOverflow();

        $copied = 0;
        DB::transaction(function () use ($type, $prev, $to, $user, &$copied) {
            foreach (CrmTarget::where('period_type', $type)->whereDate('period_start', $prev->toDateString())->get() as $t) {
                $exists = CrmTarget::where('period_type', $type)->whereDate('period_start', $to->toDateString())->where('metric', $t->metric)
                    ->when($t->user_id, fn ($w) => $w->where('user_id', $t->user_id), fn ($w) => $w->whereNull('user_id'))->exists();
                if (! $exists) {
                    CrmTarget::create(['user_id' => $t->user_id, 'period_type' => $type, 'period_start' => $to->toDateString(),
                        'metric' => $t->metric, 'target_value' => $t->target_value, 'created_by' => $user->id]);
                    $copied++;
                }
            }
        });

        return response()->json(['success' => true, 'copied' => $copied]);
    }

    // ------------------------------------------------------------------

    /** [from, to] from the request (default: this month), at most three years. */
    private function range(Request $request): array
    {
        try {
            $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : Carbon::now()->startOfMonth();
            $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : Carbon::now()->endOfMonth();
        } catch (\Throwable $e) {
            abort(response()->json(['message' => 'Invalid dates.'], 422));
        }
        if ($to->lt($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }
        if ($from->diffInDays($to) > 1100) {
            abort(response()->json(['message' => 'Choose a range of three years at most.'], 422));
        }

        return [$from, $to];
    }

    /** Active users who work in the CRM (can see leads or deals). */
    private function salesUsers()
    {
        return User::whereNull('deleted_at')->where('statut', 1)->orderBy('firstname')->get()
            ->filter(fn ($u) => $u->can('view', CrmLead::class) || $u->can('view', CrmDeal::class))->values();
    }
}
