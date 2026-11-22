<?php

namespace App\Http\Controllers;

use App\Models\CrmLead;
use App\Models\CrmLeadSource;
use App\Models\CrmLeadStatus;
use App\Models\CrmTag;
use App\Models\CrmWebForm;
use App\Models\User;
use App\Services\Crm\CrmLeadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Admin side of CRM web forms (permission crm_web_forms). The public side is CrmWebFormPublicController. */
class CrmWebFormController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'webForms', CrmLead::class);
        $leads = CrmLead::whereNull('deleted_at')->whereNotNull('crm_web_form_id')
            ->select('crm_web_form_id', DB::raw('COUNT(*) as n'), DB::raw('SUM(CASE WHEN client_id IS NOT NULL THEN 1 ELSE 0 END) as won'))
            ->groupBy('crm_web_form_id')->get()->keyBy('crm_web_form_id');

        return response()->json([
            'forms' => CrmWebForm::with('source', 'owner')->orderByDesc('id')->get()->map(fn ($f) => $this->present($f) + [
                'leads' => (int) optional($leads->get($f->id))->n,
                'converted' => (int) optional($leads->get($f->id))->won,
            ])->values(),
            'palette' => collect(CrmWebForm::FIELDS)->map(fn ($v, $k) => ['key' => $k, 'label' => $v[0], 'type' => $v[1]])->values(),
            'default_fields' => CrmWebForm::DEFAULT_FIELDS,
            'sources' => CrmLeadSource::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'color']),
            'statuses' => CrmLeadStatus::where('kind', 'open')->orderBy('sort_order')->get(['id', 'name', 'color', 'is_default']),
            'owners' => User::whereNull('deleted_at')->where('statut', 1)->orderBy('firstname')->get()
                ->map(fn ($u) => ['id' => $u->id, 'name' => CrmLeadService::userName($u)])->values(),
            'tags' => CrmTag::orderBy('name')->get(['id', 'name', 'color']),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'webForms', CrmLead::class);
        $form = CrmWebForm::create($this->validated($request) + ['token' => CrmWebForm::newToken(), 'created_by' => $user->id]);

        return response()->json(['success' => true, 'id' => $form->id, 'form' => $this->present($form->fresh(['source', 'owner']))]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'webForms', CrmLead::class);
        $form = CrmWebForm::findOrFail($id);
        $form->update($this->validated($request));

        return response()->json(['success' => true, 'form' => $this->present($form->fresh(['source', 'owner']))]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'webForms', CrmLead::class);
        CrmWebForm::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    /** New link: the old link / embeds stop working (e.g. after spam). */
    public function regenerate(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'webForms', CrmLead::class);
        $form = CrmWebForm::findOrFail($id);
        $form->update(['token' => CrmWebForm::newToken()]);

        return response()->json(['success' => true, 'form' => $this->present($form->fresh(['source', 'owner']))]);
    }

    private function present(CrmWebForm $f): array
    {
        return $f->only(['id', 'name', 'title', 'intro', 'submit_label', 'success_message', 'redirect_url', 'consent_text',
            'accent_color', 'crm_lead_source_id', 'crm_lead_status_id', 'owner_id', 'is_active', 'submissions']) + [
                'fields' => $f->fieldList(),
                'tag_ids' => array_values(array_map('intval', (array) $f->tag_ids)),
                'token' => $f->token,
                'url' => $f->url(),
                'source' => $f->source ? ['id' => $f->source->id, 'name' => $f->source->name, 'color' => $f->source->color] : null,
                'owner' => $f->owner ? ['id' => $f->owner->id, 'name' => CrmLeadService::userName($f->owner)] : null,
                'last_submission_at' => optional($f->last_submission_at)->toIso8601String(),
                'created_at' => optional($f->created_at)->toIso8601String(),
            ];
    }

    private function validated(Request $request): array
    {
        $request->validate([
            'name' => 'required|string|max:160',
            'title' => 'nullable|string|max:191',
            'intro' => 'nullable|string|max:2000',
            'fields' => 'required|array|min:1|max:' . count(CrmWebForm::FIELDS),
            'fields.*.key' => ['required', Rule::in(array_keys(CrmWebForm::FIELDS))],
            'fields.*.label' => 'nullable|string|max:120',
            'fields.*.required' => 'nullable|boolean',
            'submit_label' => 'nullable|string|max:80',
            'success_message' => 'nullable|string|max:500',
            'redirect_url' => ['nullable', 'string', 'max:500', 'regex:#^https?://#i'],
            'consent_text' => 'nullable|string|max:500',
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'crm_lead_source_id' => 'nullable|integer|exists:crm_lead_sources,id',
            'crm_lead_status_id' => ['nullable', 'integer', Rule::exists('crm_lead_statuses', 'id')->where('kind', 'open')],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'tag_ids' => 'nullable|array|max:20',
            'tag_ids.*' => 'integer|exists:crm_tags,id',
            'is_active' => 'nullable|boolean',
        ], ['redirect_url.regex' => 'The redirect address must start with http:// or https://']);

        $fields = [];
        foreach ($request->fields as $f) {
            if (isset($fields[$f['key']])) {
                continue;
            }
            $fields[$f['key']] = ['key' => $f['key'], 'label' => trim((string) ($f['label'] ?? '')) ?: CrmWebForm::FIELDS[$f['key']][0], 'required' => ! empty($f['required'])];
        }
        $keys = array_keys($fields);
        if (! array_intersect($keys, ['name', 'first_name', 'last_name', 'company_name'])) {
            abort(response()->json(['message' => 'Add a name field (full name, first / last name or company).', 'errors' => ['fields' => ['Name missing.']]], 422));
        }
        if (! array_intersect($keys, ['email', 'phone'])) {
            abort(response()->json(['message' => 'Add an email or phone field, so you can reach the lead.', 'errors' => ['fields' => ['Contact missing.']]], 422));
        }
        $blank = fn ($k) => ($v = trim((string) $request->input($k))) === '' ? null : $v;

        return [
            'name' => trim($request->name),
            'title' => $blank('title'),
            'intro' => $blank('intro'),
            'fields' => array_values($fields),
            'submit_label' => $blank('submit_label'),
            'success_message' => $blank('success_message'),
            'redirect_url' => $blank('redirect_url'),
            'consent_text' => $blank('consent_text'),
            'accent_color' => $blank('accent_color'),
            'crm_lead_source_id' => $request->crm_lead_source_id ?: null,
            'crm_lead_status_id' => $request->crm_lead_status_id ?: null,
            'owner_id' => $request->owner_id ?: null,
            'tag_ids' => array_values(array_unique(array_map('intval', (array) $request->tag_ids))),
            'is_active' => $request->boolean('is_active', true),
        ];
    }
}
