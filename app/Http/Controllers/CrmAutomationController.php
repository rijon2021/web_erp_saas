<?php

namespace App\Http\Controllers;

use App\Models\CrmAssignmentRule;
use App\Models\CrmLead;
use App\Models\CrmLeadSource;
use App\Models\CrmScoringRule;
use App\Models\CrmSetting;
use App\Models\CrmTag;
use App\Models\User;
use App\Services\Crm\CrmLeadService;
use App\Services\Crm\CrmScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CRM Settings → Lead capture / Assignment rules / Lead scoring. All behind
 * the crm_settings permission.
 */
class CrmAutomationController extends Controller
{
    public function show(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        $s = CrmSetting::current();

        return response()->json([
            'capture' => $s->only(['capture_store_messages', 'capture_quote_requests', 'capture_property_inquiries', 'capture_portal_requests',
                'capture_whatsapp_unknown', 'capture_followup_hours', 'capture_notify', 'scoring_enabled', 'stale_alerts', 'stale_lead_days', 'weekly_report']),
            'captured' => CrmLead::whereNull('deleted_at')->whereNotNull('captured_via')
                ->select('captured_via', DB::raw('COUNT(*) as n'))->groupBy('captured_via')->pluck('n', 'captured_via'),
            'assignment_rules' => CrmAssignmentRule::orderBy('sort_order')->orderBy('id')->get()->map(fn ($r) => $this->presentRule($r))->values(),
            'scoring_rules' => CrmScoringRule::orderBy('sort_order')->orderBy('id')->get()
                ->map(fn ($r) => $r->only(['id', 'criterion', 'value', 'points', 'is_active']))->values(),
            'criteria' => CrmScoringRule::CRITERIA,
            'channels' => CrmAssignmentRule::CHANNELS,
            'users' => User::whereNull('deleted_at')->where('statut', 1)->orderBy('firstname')->get()
                ->map(fn ($u) => ['id' => $u->id, 'name' => CrmLeadService::userName($u)])->values(),
            'sources' => CrmLeadSource::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'color']),
            'tags' => CrmTag::orderBy('name')->get(['id', 'name', 'color']),
            'score_spread' => [
                'hot' => CrmLead::whereNull('deleted_at')->whereNull('client_id')->where('score', '>=', 70)->count(),
                'warm' => CrmLead::whereNull('deleted_at')->whereNull('client_id')->whereBetween('score', [40, 69])->count(),
                'cold' => CrmLead::whereNull('deleted_at')->whereNull('client_id')->where('score', '<', 40)->count(),
            ],
        ]);
    }

    // ----- assignment rules -----

    public function storeRule(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        $data = $this->validatedRule($request);
        $data['sort_order'] = (int) CrmAssignmentRule::max('sort_order') + 1;
        $rule = CrmAssignmentRule::create($data);

        return response()->json(['success' => true, 'id' => $rule->id]);
    }

    public function updateRule(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        $rule = CrmAssignmentRule::findOrFail($id);
        $rule->update($this->validatedRule($request));

        return response()->json(['success' => true]);
    }

    public function destroyRule(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        CrmAssignmentRule::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    public function reorderRules(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        DB::transaction(function () use ($request) {
            foreach (array_values($request->ids) as $i => $id) {
                CrmAssignmentRule::whereKey($id)->update(['sort_order' => $i + 1]);
            }
        });

        return response()->json(['success' => true]);
    }

    // ----- scoring rules -----

    public function storeScoring(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        $data = $this->validatedScoring($request);
        $data['sort_order'] = (int) CrmScoringRule::max('sort_order') + 1;
        $rule = CrmScoringRule::create($data);

        return response()->json(['success' => true, 'id' => $rule->id]);
    }

    public function updateScoring(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        CrmScoringRule::findOrFail($id)->update($this->validatedScoring($request));

        return response()->json(['success' => true]);
    }

    public function destroyScoring(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        CrmScoringRule::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    /** Re-score every open lead now (after editing the rules). */
    public function recalculate(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        if (! CrmSetting::current()->scoring_enabled) {
            return response()->json(['message' => 'Lead scoring is switched off.'], 422);
        }
        $changed = app(CrmScoringService::class)->refreshAll();

        return response()->json(['success' => true, 'changed' => $changed]);
    }

    // ------------------------------------------------------------------

    private function presentRule(CrmAssignmentRule $r): array
    {
        $names = User::whereIn('id', (array) $r->user_ids)->get()->keyBy('id');

        return $r->only(['id', 'name', 'is_active', 'method', 'assigned_count']) + [
            'conditions' => array_merge(['channels' => [], 'source_ids' => [], 'countries' => [], 'min_value' => null], (array) $r->conditions),
            'user_ids' => array_values(array_map('intval', (array) $r->user_ids)),
            'users' => collect((array) $r->user_ids)->map(fn ($id) => [
                'id' => (int) $id,
                'name' => CrmLeadService::userName($names[$id] ?? null) ?? '#' . $id,
                'active' => isset($names[$id]) && ! $names[$id]->deleted_at && (int) $names[$id]->statut === 1,
                'next' => $r->method === 'round_robin' && $this->nextInTurn($r) === (int) $id,
            ])->values(),
        ];
    }

    private function nextInTurn(CrmAssignmentRule $r): ?int
    {
        $ids = array_values(array_map('intval', (array) $r->user_ids));
        if (! $ids) {
            return null;
        }
        $pos = array_search((int) $r->last_user_id, $ids, true);

        return $ids[$pos === false ? 0 : ($pos + 1) % count($ids)];
    }

    private function validatedRule(Request $request): array
    {
        $request->validate([
            'name' => 'required|string|max:160',
            'is_active' => 'nullable|boolean',
            'method' => ['required', Rule::in(CrmAssignmentRule::METHODS)],
            'user_ids' => 'required|array|min:1|max:50',
            'user_ids.*' => ['integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'conditions' => 'nullable|array',
            'conditions.channels' => 'nullable|array',
            'conditions.channels.*' => [Rule::in(CrmAssignmentRule::CHANNELS)],
            'conditions.source_ids' => 'nullable|array',
            'conditions.source_ids.*' => 'integer',
            'conditions.countries' => 'nullable|array|max:50',
            'conditions.countries.*' => 'string|max:120',
            'conditions.min_value' => 'nullable|numeric|min:0',
        ], ['user_ids.required' => 'Pick at least one sales rep.', 'user_ids.min' => 'Pick at least one sales rep.']);

        $c = (array) $request->input('conditions', []);

        return [
            'name' => trim($request->name),
            'is_active' => $request->boolean('is_active', true),
            'method' => $request->input('method') === 'user' ? 'user' : 'round_robin',
            'user_ids' => array_values(array_unique(array_map('intval', $request->user_ids))),
            'conditions' => [
                'channels' => array_values(array_unique((array) ($c['channels'] ?? []))),
                'source_ids' => array_values(array_unique(array_map('intval', (array) ($c['source_ids'] ?? [])))),
                'countries' => array_values(array_filter(array_map('trim', (array) ($c['countries'] ?? [])))),
                'min_value' => isset($c['min_value']) && $c['min_value'] !== '' ? (float) $c['min_value'] : null,
            ],
        ];
    }

    private function validatedScoring(Request $request): array
    {
        $request->validate([
            'criterion' => ['required', Rule::in(array_keys(CrmScoringRule::CRITERIA))],
            'value' => 'nullable|string|max:191',
            'points' => 'required|integer|min:-100|max:100',
            'is_active' => 'nullable|boolean',
        ]);
        $kind = CrmScoringRule::CRITERIA[$request->criterion];
        $value = trim((string) $request->value);
        $bad = match ($kind) {
            null => false,
            'rating' => ! in_array($value, CrmLead::RATINGS, true),
            'channel' => ! in_array($value, CrmAssignmentRule::CHANNELS, true),
            'source' => ! CrmLeadSource::whereKey((int) $value)->exists(),
            'tag' => ! CrmTag::whereKey((int) $value)->exists(),
            'number', 'days' => ! is_numeric($value) || (float) $value < ($kind === 'days' ? 1 : 0),
            default => $value === '',
        };
        if ($bad) {
            abort(response()->json(['message' => 'Choose a value for this rule.', 'errors' => ['value' => ['Required.']]], 422));
        }

        return [
            'criterion' => $request->criterion,
            'value' => $kind === null ? null : $value,
            'points' => (int) $request->points,
            'is_active' => $request->boolean('is_active', true),
        ];
    }
}
