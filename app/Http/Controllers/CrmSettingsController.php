<?php

namespace App\Http\Controllers;

use App\Models\CrmLead;
use App\Models\CrmLeadSource;
use App\Models\CrmLeadStatus;
use App\Models\CrmLostReason;
use App\Models\CrmSetting;
use App\Models\CrmTag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CRM Settings: general options + the lookup lists (lead sources, lead
 * statuses, lost reasons, tags), each editable, re-orderable and removable.
 */
class CrmSettingsController extends Controller
{
    private const TYPES = [
        'sources' => CrmLeadSource::class,
        'statuses' => CrmLeadStatus::class,
        'lost-reasons' => CrmLostReason::class,
        'tags' => CrmTag::class,
    ];

    public function show(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);

        $count = fn ($column) => CrmLead::whereNull('deleted_at')->select($column, DB::raw('COUNT(*) as n'))
            ->groupBy($column)->pluck('n', $column);
        $bySource = $count('crm_lead_source_id');
        $byStatus = $count('crm_lead_status_id');
        $byReason = $count('crm_lost_reason_id');
        $byTag = DB::table('crm_taggables')->where('taggable_type', 'lead')
            ->select('crm_tag_id', DB::raw('COUNT(*) as n'))->groupBy('crm_tag_id')->pluck('n', 'crm_tag_id');

        return response()->json([
            'settings' => CrmSetting::current()->only(['lead_prefix', 'deal_prefix', 'default_owner', 'stale_lead_days', 'duplicate_check',
                'default_reminder_minutes', 'reminder_email', 'reminder_push',
                'capture_store_messages', 'capture_quote_requests', 'capture_property_inquiries', 'capture_portal_requests',
                'capture_whatsapp_unknown', 'capture_followup_hours', 'capture_notify', 'scoring_enabled', 'stale_alerts']),
            'sources' => CrmLeadSource::orderBy('sort_order')->orderBy('name')->get()
                ->map(fn ($s) => $s->only(['id', 'name', 'color', 'is_active', 'sort_order']) + ['used' => (int) ($bySource[$s->id] ?? 0)]),
            'statuses' => CrmLeadStatus::orderBy('sort_order')->get()
                ->map(fn ($s) => $s->only(['id', 'name', 'color', 'kind', 'is_default', 'sort_order']) + ['used' => (int) ($byStatus[$s->id] ?? 0)]),
            'lost_reasons' => CrmLostReason::orderBy('sort_order')->orderBy('name')->get()
                ->map(fn ($s) => $s->only(['id', 'name', 'sort_order']) + ['used' => (int) ($byReason[$s->id] ?? 0)]),
            'tags' => CrmTag::orderBy('name')->get()
                ->map(fn ($s) => $s->only(['id', 'name', 'color']) + ['used' => (int) ($byTag[$s->id] ?? 0)]),
        ]);
    }

    public function update(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        $data = $request->validate([
            'lead_prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'deal_prefix' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'default_owner' => ['required', Rule::in(['creator', 'pool', 'rules'])],
            'stale_lead_days' => 'required|integer|min:1|max:365',
            'duplicate_check' => 'required|boolean',
            'default_reminder_minutes' => 'nullable|integer|min:0|max:10080',
            'reminder_email' => 'nullable|boolean',
            'reminder_push' => 'nullable|boolean',
        ], ['lead_prefix.regex' => 'Use letters and numbers only.', 'deal_prefix.regex' => 'Use letters and numbers only.']);

        $data['lead_prefix'] = strtoupper($data['lead_prefix']);
        $data['deal_prefix'] = strtoupper($data['deal_prefix']);
        $data['reminder_email'] = $request->boolean('reminder_email', true);
        $data['reminder_push'] = $request->boolean('reminder_push', true);
        $data['default_reminder_minutes'] = $request->filled('default_reminder_minutes') ? (int) $request->default_reminder_minutes : null;
        CrmSetting::current()->update($data);

        return response()->json(['success' => true]);
    }

    /** Lead capture switches, scoring on/off and the daily stale alerts. */
    public function updateAutomation(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        $request->validate(['capture_followup_hours' => 'nullable|integer|min:0|max:720']);
        // Partial saves are fine (the scoring switch sends only its own flag).
        $data = $request->has('capture_followup_hours')
            ? ['capture_followup_hours' => $request->filled('capture_followup_hours') ? (int) $request->capture_followup_hours : null]
            : [];
        foreach (['capture_store_messages', 'capture_quote_requests', 'capture_property_inquiries', 'capture_portal_requests',
            'capture_whatsapp_unknown', 'capture_notify', 'scoring_enabled', 'stale_alerts', 'weekly_report'] as $flag) {
            if ($request->has($flag)) {
                $data[$flag] = $request->boolean($flag);
            }
        }
        $before = (bool) CrmSetting::current()->scoring_enabled;
        CrmSetting::current()->update($data);
        if (! $before && ! empty($data['scoring_enabled'])) {
            app(\App\Services\Crm\CrmScoringService::class)->refreshAll();
        }

        return response()->json(['success' => true]);
    }

    public function storeLookup(Request $request, string $type)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        $class = $this->model($type);
        $data = $this->validatedLookup($request, $type);
        // Tags have no sort_order column (they list alphabetically).
        if ($type !== 'tags') {
            $data['sort_order'] = (int) $class::max('sort_order') + 1;
        }

        $row = DB::transaction(function () use ($class, $data, $type) {
            if ($type === 'statuses' && ! empty($data['is_default'])) {
                CrmLeadStatus::query()->update(['is_default' => false]);
            }

            return $class::create($data);
        });

        return response()->json(['success' => true, 'id' => $row->id]);
    }

    public function updateLookup(Request $request, string $type, $id)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        $class = $this->model($type);
        $row = $class::findOrFail($id);
        $data = $this->validatedLookup($request, $type, $row);

        if ($type === 'statuses' && $row->kind !== $data['kind']) {
            $used = CrmLead::whereNull('deleted_at')->where('crm_lead_status_id', $row->id)->exists();
            if ($used) {
                return $this->refuse('Leads use this status, so its kind can no longer change. Create a new status instead.');
            }
            if ($row->kind === 'open' && CrmLeadStatus::where('kind', 'open')->count() <= 1) {
                return $this->refuse('Keep at least one open status.');
            }
        }

        DB::transaction(function () use ($row, $data, $type) {
            if ($type === 'statuses' && ! empty($data['is_default'])) {
                CrmLeadStatus::where('id', '!=', $row->id)->update(['is_default' => false]);
            }
            $row->update($data);
        });

        return response()->json(['success' => true]);
    }

    public function destroyLookup(Request $request, string $type, $id)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        $class = $this->model($type);
        $row = $class::findOrFail($id);

        switch ($type) {
            case 'statuses':
                if (CrmLead::whereNull('deleted_at')->where('crm_lead_status_id', $row->id)->exists()) {
                    return $this->refuse('Leads are in this status. Move them to another status first.');
                }
                if (CrmLeadStatus::where('kind', $row->kind)->count() <= 1) {
                    return $this->refuse('Keep at least one status of this kind.');
                }
                break;
            case 'tags':
                DB::table('crm_taggables')->where('crm_tag_id', $row->id)->delete();
                break;
        }
        // Sources and lost reasons are soft-deleted: leads keep showing the old name.
        $row->delete();

        return response()->json(['success' => true]);
    }

    /** ids in their new order. */
    public function reorder(Request $request, string $type)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        $class = $this->model($type);
        $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        if ($type === 'tags') {
            return response()->json(['success' => true]); // alphabetical, no sort_order column
        }
        DB::transaction(function () use ($class, $request) {
            foreach (array_values($request->ids) as $i => $id) {
                $class::whereKey($id)->update(['sort_order' => $i + 1]);
            }
        });

        return response()->json(['success' => true]);
    }

    private function model(string $type): string
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return self::TYPES[$type];
    }

    private function validatedLookup(Request $request, string $type, $row = null): array
    {
        $color = ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
        $rules = ['name' => 'required|string|max:' . ($type === 'tags' ? 80 : 160)];
        if (in_array($type, ['sources', 'statuses', 'tags'], true)) {
            $rules['color'] = $color;
        }
        if ($type === 'sources') {
            $rules['is_active'] = 'nullable|boolean';
        }
        if ($type === 'statuses') {
            $rules['kind'] = ['required', Rule::in(CrmLeadStatus::KINDS)];
            $rules['is_default'] = 'nullable|boolean';
        }
        $data = $request->validate($rules, ['color.regex' => 'Pick a colour.']);

        $class = self::TYPES[$type];
        $clash = $class::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])
            ->when($row, fn ($q) => $q->where('id', '!=', $row->id))->exists();
        if ($clash) {
            abort(response()->json(['message' => 'This name is already in the list.', 'errors' => ['name' => ['Already exists.']]], 422));
        }

        $data['name'] = trim($data['name']);
        if (array_key_exists('color', $data) && ! $data['color']) {
            unset($data['color']);
        }
        if ($type === 'sources') {
            $data['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : ($row ? $row->is_active : true);
        }
        if ($type === 'statuses') {
            // Only an open status can be the starting status.
            $data['is_default'] = $request->boolean('is_default') && $data['kind'] === 'open';
            if ($row && $row->is_default && ! $data['is_default'] && ! CrmLeadStatus::where('id', '!=', $row->id)->where('is_default', true)->exists()) {
                $data['is_default'] = $data['kind'] === 'open'; // keep one default
            }
        }

        return $data;
    }

    private function refuse(string $message)
    {
        return response()->json(['message' => $message], 422);
    }
}
