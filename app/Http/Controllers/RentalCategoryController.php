<?php

namespace App\Http\Controllers;

use App\Models\RentalCategory;
use App\Models\RentalItem;
use App\Models\RentalSetting;
use App\Support\RentalTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Rental categories (Apartment, Shop, Generator, Camera…). A category may
 * carry a default item type, which pre-selects the type on the item form and
 * decides which business type's website lists it.
 *
 * Website fields (photo, order, visibility) exist once migration
 * 2026_10_25_000001 ran; the photo arrives as multipart, so a save with a
 * photo POSTs to rental/categories/{id}/update.
 */
class RentalCategoryController extends Controller
{
    private const SORTABLE = ['id', 'name', 'type', 'created_at', 'sort_order'];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RentalCategory::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'id';
        if ($order === 'sort_order' && ! RentalSetting::websiteReady()) {
            $order = 'id';
        }
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = RentalCategory::whereNull('deleted_at')
            ->withCount(['items' => fn ($q) => $q->whereNull('deleted_at')]);

        if ($request->filled('search')) {
            $query->where('name', 'LIKE', '%' . $request->search . '%');
        }
        if ($request->filled('type')) {
            // A category with no type serves every type.
            $query->where(fn ($q) => $q->where('type', $request->type)->orWhereNull('type'));
        }

        $totalRows = $query->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }

        $columns = ['id', 'name', 'type', 'description', 'created_at'];
        if (RentalSetting::websiteReady()) {
            $columns = array_merge($columns, ['show_on_website', 'slug', 'image', 'sort_order']);
        }
        $categories = $query->orderBy($order, $dir)
            ->offset(($page * $perPage) - $perPage)
            ->limit($perPage)
            ->get($columns)
            ->each(fn ($c) => $c->setAttribute('image_url', $c->imageUrl()));

        return response()->json(['categories' => $categories, 'totalRows' => $totalRows]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', RentalCategory::class);

        $category = RentalCategory::create($this->validated($request));
        $this->syncImage($request, $category);

        return response()->json(['success' => true, 'id' => $category->id]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalCategory::class);

        $category = RentalCategory::whereNull('deleted_at')->findOrFail($id);
        $category->update($this->validated($request, $category));
        $this->syncImage($request, $category);

        return response()->json(['success' => true]);
    }

    /**
     * POST rental/categories/suggested {type} — adds the business type's
     * starter categories (RentalTypes) that don't exist yet, by name.
     */
    public function suggested(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', RentalCategory::class);
        $request->validate(['type' => ['required', Rule::in(RentalTypes::TYPES)]]);

        $existing = RentalCategory::whereNull('deleted_at')->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->all();
        $added = [];
        foreach (RentalTypes::suggestedCategories($request->type) as $i => $name) {
            if (in_array(mb_strtolower($name), $existing, true)) {
                continue;
            }
            $row = ['name' => $name, 'type' => $request->type, 'description' => null];
            if (RentalSetting::websiteReady()) {
                $row += ['show_on_website' => true, 'sort_order' => $i + 1, 'slug' => Str::slug($name) ?: null];
            }
            RentalCategory::create($row);
            $added[] = $name;
        }

        return response()->json(['success' => true, 'added' => $added]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RentalCategory::class);

        $category = RentalCategory::whereNull('deleted_at')->findOrFail($id);
        $this->guardUnused([$category->id]);
        $category->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RentalCategory::class);

        $ids = (array) $request->selectedIds;
        $this->guardUnused($ids);
        RentalCategory::whereIn('id', $ids)->delete();

        return response()->json(['success' => true]);
    }

    private function validated(Request $request, ?RentalCategory $existing = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'type' => ['nullable', Rule::in(RentalTypes::TYPES)],
            'description' => 'nullable|string|max:2000',
            'show_on_website' => 'sometimes|boolean',
            'sort_order' => 'sometimes|nullable|integer|min:-100000|max:100000',
            'slug' => 'sometimes|nullable|string|max:191',
            'image_file' => 'sometimes|nullable|image|max:5120',
        ]);

        $out = [
            'name' => $data['name'],
            'type' => $data['type'] ?? null,
            'description' => $data['description'] ?? null,
        ];
        if (RentalSetting::websiteReady()) {
            if ($request->has('show_on_website')) {
                $out['show_on_website'] = $request->boolean('show_on_website');
            }
            if ($request->has('sort_order')) {
                $out['sort_order'] = (int) ($data['sort_order'] ?? 0);
            }
            if ($request->has('slug') || ! $existing) {
                $out['slug'] = Str::slug(trim((string) ($data['slug'] ?? '')) ?: $data['name']) ?: null;
            }
        }

        return $out;
    }

    /** Replace or remove the category photo (multipart image_file / remove_image=1). */
    private function syncImage(Request $request, RentalCategory $category): void
    {
        if (! RentalSetting::websiteReady()) {
            return;
        }
        $dir = upload_public_path(RentalCategory::IMAGE_DIR);
        if ($request->hasFile('image_file')) {
            if (! file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            $file = $request->file('image_file');
            $name = time() . '_' . Str::random(10) . '.' . \App\Services\Rental\RentalUpload::extension($file, 'image');
            $file->move($dir, $name);
            $this->deleteImage($category->image);
            $category->update(['image' => $name]);
        } elseif ($request->boolean('remove_image') && $category->image) {
            $this->deleteImage($category->image);
            $category->update(['image' => null]);
        }
    }

    private function deleteImage($file): void
    {
        if (! $file || ! is_string($file) || str_contains($file, '/') || str_contains($file, '\\')) {
            return;
        }
        $path = upload_public_path(RentalCategory::IMAGE_DIR . '/' . $file);
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /** A category still holding items can't be removed — the items would lose it silently. */
    private function guardUnused(array $ids): void
    {
        $used = RentalItem::whereNull('deleted_at')->whereIn('rental_category_id', $ids)->exists();

        if ($used) {
            abort(response()->json([
                'message' => 'This category still has rental items. Move or delete them first.',
            ], 422));
        }
    }
}
