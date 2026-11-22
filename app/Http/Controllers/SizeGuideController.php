<?php

namespace App\Http\Controllers;

use App\Models\SizeGuide;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Intervention\Image\ImageManagerStatic as Image;

/**
 * Size guides (size charts) — ported from stocky. A guide is a typed table
 * (columns + rows) and/or a chart image; products link to one through
 * products.size_guide_id and the storefront product page shows it.
 * Images live in the tenant upload folder upload_public_path('size_guides').
 */
class SizeGuideController extends Controller
{
    // ------------ GET ALL Size Guides (datatable) -----------\\
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', SizeGuide::class);
        if (! SizeGuide::tablesReady()) {
            return response()->json(['size_guides' => [], 'totalRows' => 0]);
        }

        $perPage = $request->limit ?: 10;
        $pageStart = (int) $request->get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = in_array($request->SortField, ['id', 'name', 'status', 'created_at'], true) ? $request->SortField : 'id';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = SizeGuide::whereNull('deleted_at')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'LIKE', "%{$request->search}%"));

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
            $offSet = 0;
        }

        $guides = $query->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get()
            ->map(fn (SizeGuide $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'image' => $g->image,
                'image_url' => $g->imageUrl(),
                'columns' => $g->chart('columns'),
                'rows' => $g->chart('rows'),
                'rows_count' => count($g->chart('rows')),
                'products_count' => $g->products()->whereNull('deleted_at')->count(),
                'status' => (bool) $g->status,
                'created_at' => optional($g->created_at)->toDateTimeString(),
            ]);

        return response()->json([
            'size_guides' => $guides,
            'totalRows' => $totalRows,
        ]);
    }

    // ---------------- STORE -------------\\
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', SizeGuide::class);
        $this->abortIfNotMigrated();

        $request->validate([
            'name' => 'required|string|max:191',
            'image' => 'nullable|image|max:2048',
        ]);

        $guide = DB::transaction(function () use ($request) {
            $guide = new SizeGuide;
            $guide->name = trim($request['name']);
            [$guide->columns, $guide->rows] = $this->normalizeChart($request->input('columns'), $request->input('rows'));
            $guide->status = filter_var($request->input('status', true), FILTER_VALIDATE_BOOLEAN);
            $guide->image = $this->saveImage($request);
            $guide->save();

            return $guide;
        }, 10);

        return response()->json(['success' => true, 'id' => $guide->id], 201);
    }

    // ---------------- UPDATE -------------\\
    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', SizeGuide::class);
        $this->abortIfNotMigrated();

        $request->validate([
            'name' => 'required|string|max:191',
            'image' => 'nullable|image|max:2048',
        ]);

        DB::transaction(function () use ($request, $id) {
            $guide = SizeGuide::whereNull('deleted_at')->findOrFail($id);
            $guide->name = trim($request['name']);
            [$guide->columns, $guide->rows] = $this->normalizeChart($request->input('columns'), $request->input('rows'));
            $guide->status = filter_var($request->input('status', true), FILTER_VALIDATE_BOOLEAN);
            if (filter_var($request->input('remove_image', false), FILTER_VALIDATE_BOOLEAN) && ! $request->hasFile('image')) {
                $this->deleteImage($guide->image);
                $guide->image = null;
            } else {
                $guide->image = $this->saveImage($request, $guide->image);
            }
            $guide->save();
        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ DELETE -----------\\
    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', SizeGuide::class);
        $this->abortIfNotMigrated();

        $this->softDelete([(int) $id]);

        return response()->json(['success' => true]);
    }

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', SizeGuide::class);
        $this->abortIfNotMigrated();

        $this->softDelete(array_map('intval', (array) $request->selectedIds));

        return response()->json(['success' => true]);
    }

    /** Active guides for the product form dropdown. */
    public function list(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', SizeGuide::class);

        return response()->json([
            'size_guides' => self::activeOptions(),
        ]);
    }

    /** [{id, name}] of active guides; [] before the migration has run. */
    public static function activeOptions()
    {
        if (! SizeGuide::tablesReady()) {
            return collect();
        }

        return SizeGuide::whereNull('deleted_at')->where('status', 1)->orderBy('name')->get(['id', 'name']);
    }

    // ---------------------------------------------------------------------

    /** Soft-delete guides and unlink them from their products. */
    private function softDelete(array $ids): void
    {
        DB::transaction(function () use ($ids) {
            SizeGuide::whereIn('id', $ids)->update(['deleted_at' => Carbon::now()]);
            DB::table('products')->whereIn('size_guide_id', $ids)->update(['size_guide_id' => null]);
        });
    }

    private function abortIfNotMigrated(): void
    {
        if (! SizeGuide::tablesReady()) {
            abort(response()->json(['success' => false, 'message' => 'Size guides are not set up yet: run the tenant database migrations.'], 422));
        }
    }

    private function saveImage(Request $request, ?string $currentImage = null): ?string
    {
        if (! $request->hasFile('image') || ! $request->file('image')->isValid()) {
            // No new file: keep the existing one on update, null on create.
            return $currentImage;
        }

        $dir = upload_public_path('size_guides');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $image = $request->file('image');
        $ext = \App\Support\SafeUpload::imageExtension($image, 'image');
        $filename = rand(11111111, 99999999).'_'.time().'.'.$ext;
        Image::make($image->getRealPath())->resize(1200, 1200, function ($c) {
            $c->aspectRatio();
            $c->upsize();
        })->save($dir.'/'.$filename);

        $this->deleteImage($currentImage);

        return $filename;
    }

    private function deleteImage(?string $filename): void
    {
        if (! $filename || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return;
        }
        $path = upload_public_path('size_guides').'/'.$filename;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Columns + rows → [headers, rows]. A column with a blank header is
     * dropped together with its cells, so every row stays aligned to the
     * headers; fully-empty rows are dropped.
     */
    private function normalizeChart($columns, $rows): array
    {
        $cell = fn ($c) => mb_substr(trim((string) (is_scalar($c) ? $c : '')), 0, 60);
        $columns = $this->decodeJson($columns);
        $rows = $this->decodeJson($rows);
        if (! is_array($columns)) {
            return [[], []];
        }

        $keep = [];
        $headers = [];
        foreach (array_values($columns) as $i => $c) {
            $h = $cell($c);
            if ($h !== '') {
                $keep[] = $i;
                $headers[] = $h;
            }
        }
        if (! $headers || ! is_array($rows)) {
            return [$headers, []];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $row = array_values($row);
            $cells = array_map(fn ($i) => $cell($row[$i] ?? ''), $keep);
            if (count(array_filter($cells, fn ($c) => $c !== '')) > 0) {
                $out[] = $cells;
            }
        }

        return [$headers, array_slice($out, 0, 100)];
    }

    private function decodeJson($value)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        return $value;
    }
}
