<?php

namespace App\Http\Controllers;

use App\Models\RentalAgreementTemplate;
use App\Models\RentalContract;
use App\Models\RentalItem;
use App\Services\Rental\RentalAgreementRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Agreement templates: HTML with {placeholders}, merged per contract when
 * printing. Managing them sits behind `rnt_settings`; listing them (to pick
 * one when printing) only needs contract view rights.
 */
class RentalAgreementTemplateController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RentalContract::class);

        return response()->json([
            'templates' => RentalAgreementTemplate::whereNull('deleted_at')->orderByDesc('is_default')->orderBy('name')
                ->get(['id', 'name', 'content', 'is_default', 'updated_at']),
            'placeholders' => collect(RentalAgreementRenderer::placeholders())
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'default_content' => RentalAgreementRenderer::defaultTemplate(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'settings', RentalItem::class);

        $data = $this->validated($request);
        $template = DB::transaction(function () use ($data) {
            // The first template becomes the default automatically.
            $data['is_default'] = $data['is_default'] || ! RentalAgreementTemplate::whereNull('deleted_at')->exists();
            if ($data['is_default']) {
                RentalAgreementTemplate::where('is_default', true)->update(['is_default' => false]);
            }

            return RentalAgreementTemplate::create($data);
        });

        return response()->json(['success' => true, 'id' => $template->id]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'settings', RentalItem::class);

        $template = RentalAgreementTemplate::whereNull('deleted_at')->findOrFail($id);
        $data = $this->validated($request);

        DB::transaction(function () use ($template, $data) {
            if ($data['is_default']) {
                RentalAgreementTemplate::where('id', '!=', $template->id)->where('is_default', true)->update(['is_default' => false]);
            } elseif ($template->is_default) {
                // Never leave the module without a default.
                $data['is_default'] = true;
            }
            $template->update($data);
        });

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'settings', RentalItem::class);

        $template = RentalAgreementTemplate::whereNull('deleted_at')->findOrFail($id);
        $wasDefault = $template->is_default;
        $template->update(['is_default' => false]);
        $template->delete();

        if ($wasDefault) {
            optional(RentalAgreementTemplate::whereNull('deleted_at')->orderBy('id')->first())->update(['is_default' => true]);
        }

        return response()->json(['success' => true]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'content' => 'required|string|max:200000',
            'is_default' => 'nullable|boolean',
        ]);

        return [
            'name' => $data['name'],
            'content' => $data['content'],
            'is_default' => (bool) ($data['is_default'] ?? false),
        ];
    }
}
