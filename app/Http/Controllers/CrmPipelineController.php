<?php

namespace App\Http\Controllers;

use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRM Settings → Pipelines: pipelines and their stages (name, colour, win
 * probability, rot days), ordering, the default pipeline. Anything still
 * holding deals cannot be deleted.
 */
class CrmPipelineController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);

        $byStage = CrmDeal::whereNull('deleted_at')->select('crm_pipeline_stage_id', DB::raw('COUNT(*) as n'))
            ->groupBy('crm_pipeline_stage_id')->pluck('n', 'crm_pipeline_stage_id');
        $byPipeline = CrmDeal::whereNull('deleted_at')->select('crm_pipeline_id', DB::raw('COUNT(*) as n'))
            ->groupBy('crm_pipeline_id')->pluck('n', 'crm_pipeline_id');

        return response()->json(['pipelines' => CrmPipeline::with('stages')->orderBy('sort_order')->orderBy('id')->get()->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'is_default' => (bool) $p->is_default,
            'deals' => (int) ($byPipeline[$p->id] ?? 0),
            'stages' => $p->stages->map(fn ($s) => $s->only(['id', 'name', 'color', 'probability', 'rot_days']) + ['deals' => (int) ($byStage[$s->id] ?? 0)])->values(),
        ])->values()]);
    }

    /** A new pipeline comes with the stages sent (or a starter set). */
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'is_default' => 'nullable|boolean',
        ]);
        $this->assertUniqueName($data['name']);

        $p = DB::transaction(function () use ($data, $request) {
            if ($request->boolean('is_default')) {
                CrmPipeline::query()->update(['is_default' => false]);
            }
            $p = CrmPipeline::create([
                'name' => trim($data['name']),
                'is_default' => $request->boolean('is_default') || ! CrmPipeline::exists(),
                'sort_order' => (int) CrmPipeline::max('sort_order') + 1,
            ]);
            foreach ([['New', '#3b82f6', 10], ['In progress', '#8b5cf6', 40], ['Proposal', '#f59e0b', 60], ['Negotiation', '#f97316', 80]] as $i => [$n, $c, $prob]) {
                CrmPipelineStage::create(['crm_pipeline_id' => $p->id, 'name' => $n, 'color' => $c, 'probability' => $prob, 'sort_order' => $i + 1]);
            }

            return $p;
        });

        return response()->json(['success' => true, 'id' => $p->id]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        $p = CrmPipeline::findOrFail($id);
        $data = $request->validate(['name' => 'required|string|max:120', 'is_default' => 'nullable|boolean']);
        $this->assertUniqueName($data['name'], $p->id);

        DB::transaction(function () use ($p, $data, $request) {
            if ($request->boolean('is_default')) {
                CrmPipeline::where('id', '!=', $p->id)->update(['is_default' => false]);
            }
            $p->update([
                'name' => trim($data['name']),
                // There is always one default.
                'is_default' => $request->boolean('is_default') || ($p->is_default && ! CrmPipeline::where('id', '!=', $p->id)->where('is_default', true)->exists()),
            ]);
        });

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        $p = CrmPipeline::findOrFail($id);
        if (CrmDeal::whereNull('deleted_at')->where('crm_pipeline_id', $p->id)->exists()) {
            return $this->refuse('This pipeline has deals. Move or delete them first.');
        }
        if (CrmPipeline::count() <= 1) {
            return $this->refuse('Keep at least one pipeline.');
        }
        DB::transaction(function () use ($p) {
            CrmPipelineStage::where('crm_pipeline_id', $p->id)->delete();
            $p->delete();
            if ($p->is_default) {
                optional(CrmPipeline::orderBy('sort_order')->first())->update(['is_default' => true]);
            }
        });

        return response()->json(['success' => true]);
    }

    public function storeStage(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        $p = CrmPipeline::findOrFail($id);
        $data = $this->validatedStage($request);

        $stage = CrmPipelineStage::create($data + [
            'crm_pipeline_id' => $p->id,
            'sort_order' => (int) CrmPipelineStage::where('crm_pipeline_id', $p->id)->max('sort_order') + 1,
        ]);

        return response()->json(['success' => true, 'id' => $stage->id]);
    }

    public function updateStage(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        $stage = CrmPipelineStage::findOrFail($id);
        $stage->update($this->validatedStage($request));

        return response()->json(['success' => true]);
    }

    public function destroyStage(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        $stage = CrmPipelineStage::findOrFail($id);
        if (CrmDeal::whereNull('deleted_at')->where('status', 'open')->where('crm_pipeline_stage_id', $stage->id)->exists()) {
            return $this->refuse('Open deals are in this stage. Move them to another stage first.');
        }
        if (CrmPipelineStage::where('crm_pipeline_id', $stage->crm_pipeline_id)->count() <= 1) {
            return $this->refuse('A pipeline needs at least one stage.');
        }
        // Soft delete: won / lost deals keep showing the stage they closed in.
        $stage->delete();

        return response()->json(['success' => true]);
    }

    public function reorderStages(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'settings', CrmLead::class);
        $p = CrmPipeline::findOrFail($id);
        $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        DB::transaction(function () use ($p, $request) {
            foreach (array_values($request->ids) as $i => $sid) {
                CrmPipelineStage::where('crm_pipeline_id', $p->id)->whereKey($sid)->update(['sort_order' => $i + 1]);
            }
        });

        return response()->json(['success' => true]);
    }

    private function validatedStage(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'probability' => 'required|integer|min:0|max:100',
            'rot_days' => 'nullable|integer|min:1|max:365',
        ], ['color.regex' => 'Pick a colour.']);
        $data['name'] = trim($data['name']);
        $data['rot_days'] = $data['rot_days'] ?? null;
        if (empty($data['color'])) {
            unset($data['color']);
        }

        return $data;
    }

    private function assertUniqueName(string $name, ?int $except = null): void
    {
        $clash = CrmPipeline::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($except, fn ($q) => $q->where('id', '!=', $except))->exists();
        if ($clash) {
            abort(response()->json(['message' => 'A pipeline with this name already exists.', 'errors' => ['name' => ['Already exists.']]], 422));
        }
    }

    private function refuse(string $message)
    {
        return response()->json(['message' => $message], 422);
    }
}
