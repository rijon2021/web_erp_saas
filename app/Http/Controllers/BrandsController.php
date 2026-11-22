<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Intervention\Image\ImageManagerStatic as Image;

class BrandsController extends Controller
{
    // Not a BaseController subclass, so the warehouse helpers are mixed in here.
    use \App\Traits\ScopesWarehouseAccess;

    // ------------ GET ALL Brands -----------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Brand::class);
        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        // Whitelisted, and defaulted: an absent SortField previously reached
        // orderBy('') and the query died with "Unknown column ''".
        $order = in_array($request->SortField, ['id', 'name', 'description'], true) ? $request->SortField : 'id';
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        $helpers = new helpers;

        $brands = Brand::where('deleted_at', '=', null);

        // Settings -> Features: optionally show only brands that have a product
        // this user may see (owned by / stocked in one of their warehouses).
        // A brand with no product at all stays visible, so a new brand is never
        // hidden from its author.
        $this->scopeMasterDataToProducts($brands, fn ($sub) => $sub->whereColumn('products.brand_id', 'brands.id'));

        $brands
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('description', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $brands->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $brands = $brands->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        return response()->json([
            'brands' => $brands,
            'totalRows' => $totalRows,
        ]);

    }

    // ---------------- STORE NEW Brand -------------\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Brand::class);

        request()->validate([
            'name' => 'required',
            'image' => 'nullable|image|max:2048',
        ]);

        $createdBrand = \DB::transaction(function () use ($request) {

            $Brand = new Brand;

            $Brand->name = $request['name'];
            $Brand->description = $request['description'];
            $Brand->image = $this->storeImage($request) ?? 'no-image.png';
            $Brand->save();

            return $Brand; // Return the created brand
        }, 10);

        return response()->json([
            'success' => true,
            'brand' => $createdBrand
        ], 201);

    }

    // ------------ function show -----------\

    public function show($id)
    {
        //

    }

    // ---------------- UPDATE Brand -------------\

    public function update(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Brand::class);

        request()->validate([
            'name' => 'required',
            'image' => 'nullable|image|max:2048',
        ]);
        \DB::transaction(function () use ($request, $id) {
            $Brand = Brand::findOrFail($id);
            $currentImage = $Brand->image;

            // Only a newly uploaded file replaces the image; an edit that sends
            // no file keeps the current one.
            $filename = $this->storeImage($request);
            if ($filename) {
                $this->deleteImage($currentImage);
            } else {
                $filename = $currentImage ?: 'no-image.png';
            }

            Brand::whereId($id)->update([
                'name' => $request['name'],
                'description' => $request['description'],
                'image' => $filename,
            ]);

        }, 10);

        return response()->json(['success' => true]);
    }

    /**
     * Save the uploaded brand image (resized to 200x200) into the tenant's
     * upload folder — upload_public_path('brands') — which is where the SPA
     * reads it back from (uploadUrl('brands', …)). Returns the stored
     * filename, or null when the request carries no file.
     */
    private function storeImage(Request $request): ?string
    {
        if (! $request->hasFile('image') || ! $request->file('image')->isValid()) {
            return null;
        }

        $image = $request->file('image');
        $filename = \App\Support\SafeUpload::name(\App\Support\SafeUpload::imageExtension($image, 'image'));

        $dir = upload_public_path('brands');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        Image::make($image->getRealPath())
            ->resize(200, 200)
            ->save($dir.'/'.$filename);

        return $filename;
    }

    private function deleteImage($filename): void
    {
        if (! $filename || $filename === 'no-image.png') {
            return;
        }

        // Tenant folder first, then the legacy shared public/images/brands
        // location used before uploads moved into per-tenant folders.
        foreach ([upload_public_path('brands').'/'.$filename, public_path('images/brands/'.$filename)] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    // ------------ Delete Brand -----------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Brand::class);

        Brand::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Brand::class);

        $selectedIds = $request->selectedIds;
        foreach ($selectedIds as $brand_id) {
            Brand::whereId($brand_id)->update([
                'deleted_at' => Carbon::now(),
            ]);
        }

        return response()->json(['success' => true]);

    }
}
