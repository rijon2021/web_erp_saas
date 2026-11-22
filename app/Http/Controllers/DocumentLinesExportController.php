<?php

namespace App\Http\Controllers;

use App\Services\DocumentLines;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Line-item exports for the document lists: one row per product line, with
 * the product code and category, so a sheet can be matched back to a
 * catalogue by code (names are neither unique nor stable).
 *
 * The list page sends the ids of the documents its current filters return
 * (the same fetch its own Excel export makes), so every list filter applies
 * as-is; the ids are re-scoped here to the user's warehouses and, without
 * record_view, to their own documents. Lines come from App\Services\DocumentLines.
 *
 * POST document-lines/{type}  {ids: [..]}  → {lines: [..]}
 */
class DocumentLinesExportController extends BaseController
{
    public function lines(Request $request, string $type)
    {
        abort_unless(isset(DocumentLines::TYPES[$type]), 404);
        [$model, $docs] = DocumentLines::TYPES[$type];

        $this->authorizeForUser($request->user('api'), 'view', $model);

        $request->validate([
            'ids' => 'required|array|max:50000',
            'ids.*' => 'integer',
        ]);

        $user = Auth::user();
        $ids = DB::table($docs)
            ->whereIn('id', array_map('intval', $request->input('ids')))
            ->whereNull('deleted_at')
            ->whereIn('warehouse_id', $this->userWarehouseIds())
            ->when(! $user->hasRecordView(), fn ($q) => $q->where('user_id', $user->id))
            ->pluck('id')
            ->all();

        return response()->json(['lines' => DocumentLines::lines($type, $ids)]);
    }
}
