<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Intervention\Image\Facades\Image;

class WarehouseController extends Controller
{
    // Not a BaseController subclass, so the warehouse helpers are mixed in here.
    use \App\Traits\ScopesWarehouseAccess;

    // ----------- GET ALL  Warehouse --------------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Warehouse::class);

        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        // Whitelisted, and defaulted: an absent SortField previously reached
        // orderBy('') and the query died with "Unknown column ''".
        $order = in_array($request->SortField, ['id', 'name', 'mobile', 'country', 'city', 'zip', 'email'], true)
            ? $request->SortField
            : 'id';
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';

        $warehouses = Warehouse::where('deleted_at', '=', null);

        // Every warehouse dropdown in the app already lists only the ones the
        // user is assigned to; this management list was the last place that
        // showed all of them. Gated on the same Settings -> Features switch as
        // the other master data, so the default stays as it was.
        if ($this->restrictsMasterDataByWarehouse()) {
            $warehouses->whereIn('id', $this->userWarehouseIds());
        }

        $warehouses
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('mobile', 'LIKE', "%{$request->search}%")
                        ->orWhere('country', 'LIKE', "%{$request->search}%")
                        ->orWhere('city', 'LIKE', "%{$request->search}%")
                        ->orWhere('zip', 'LIKE', "%{$request->search}%")
                        ->orWhere('address', 'LIKE', "%{$request->search}%")
                        ->orWhere('email', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $warehouses->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $warehouses = $warehouses->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        return response()->json([
            'warehouses' => $warehouses,
            'totalRows' => $totalRows,
        ]);
    }

    // ----------- Store new Warehouse --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Warehouse::class);

        request()->validate([
            'name' => 'required',
        ]);

        $logo = $this->storeLogo($request);

        \DB::transaction(function () use ($request, $logo) {

            $Warehouse = new Warehouse;
            $Warehouse->name = $request['name'];
            $Warehouse->address = $request['address'];
            $Warehouse->mobile = $request['mobile'];
            $Warehouse->country = $request['country'];
            $Warehouse->city = $request['city'];
            $Warehouse->zip = $request['zip'];
            $Warehouse->email = $request['email'];
            $Warehouse->invoice_footer = $request['invoice_footer'];
            $Warehouse->logo = $logo;
            $Warehouse->save();

            $products = Product::where('deleted_at', '=', null)->get(['id', 'type']);

            if ($products) {
                foreach ($products as $product) {
                    $product_warehouse = [];
                    $Product_Variants = ProductVariant::where('product_id', $product->id)
                        ->where('deleted_at', null)
                        ->get();

                    if ($Product_Variants->isNotEmpty()) {
                        foreach ($Product_Variants as $product_variant) {

                            $product_warehouse[] = [
                                'product_id' => $product->id,
                                'warehouse_id' => $Warehouse->id,
                                'product_variant_id' => $product_variant->id,
                                'manage_stock' => $product->type == 'is_service' ? 0 : 1,
                            ];
                        }
                    } else {
                        $product_warehouse[] = [
                            'product_id' => $product->id,
                            'warehouse_id' => $Warehouse->id,
                            'product_variant_id' => null,
                            'manage_stock' => $product->type == 'is_service' ? 0 : 1,
                        ];
                    }

                    product_warehouse::insert($product_warehouse);
                }
            }

        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // -----------Update Warehouse --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Warehouse::class);

        request()->validate([
            'name' => 'required',
        ]);

        $Warehouse = Warehouse::where('deleted_at', '=', null)->findOrFail($id);

        $Warehouse->update([
            'name' => $request['name'],
            'address' => $request['address'],
            'mobile' => $request['mobile'],
            'country' => $request['country'],
            'city' => $request['city'],
            'zip' => $request['zip'],
            'email' => $request['email'],
            'invoice_footer' => $request['invoice_footer'],
            'logo' => $this->storeLogo($request, $Warehouse->logo),
        ]);

        return response()->json(['success' => true]);
    }

    // ----------- Delete  Warehouse --------------\\

    /**
     * Save an uploaded branch logo next to the company logo (the tenant's
     * images/tenants/{id}/settings folder) and return its filename.
     *
     * Receipts and invoices resolve every issuer logo — company or branch —
     * through the same "settings" upload folder ($imgUrl('settings', …) on the
     * POS, upload_public_path('settings/…') in the PDF views), so a branch
     * logo saved anywhere else never shows on the printed receipt.
     *
     * Keeps the current logo when no new file is sent, and clears it when the
     * form explicitly asks for it (remove_logo). Branch logos are optional: an
     * empty one makes the invoice header fall back to the company logo.
     */
    private function storeLogo(Request $request, $current = null)
    {
        if ($request->boolean('remove_logo')) {
            $this->deleteLogo($current);

            return null;
        }

        if (! $request->hasFile('logo') || ! $request->file('logo')->isValid()) {
            return $current;
        }

        $image = $request->file('logo');
        $filename = \App\Support\SafeUpload::name(\App\Support\SafeUpload::imageExtension($image, 'logo'));

        $dir = upload_public_path('settings');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        Image::make($image->getRealPath())
            ->resize(800, 800, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            })->save($dir.'/'.$filename);

        $this->deleteLogo($current);

        return $filename;
    }

    private function deleteLogo($filename)
    {
        if (! $filename || $filename === 'logo-default.png') {
            return;
        }

        // Current location first, then the legacy shared public/images root
        // where branch logos were saved before they moved next to the company logo.
        foreach ([upload_public_path('settings/'.$filename), public_path('/images/'.$filename)] as $path) {
            if (file_exists($path) && is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Warehouse::class);

        \DB::transaction(function () use ($id) {

            Warehouse::whereId($id)->update([
                'deleted_at' => Carbon::now(),
            ]);

            product_warehouse::where('warehouse_id', $id)->update([
                'deleted_at' => Carbon::now(),
            ]);

        }, 10);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Warehouse::class);

        \DB::transaction(function () use ($request) {
            $selectedIds = $request->selectedIds;
            foreach ($selectedIds as $warehouse_id) {
                Warehouse::whereId($warehouse_id)->update([
                    'deleted_at' => Carbon::now(),
                ]);

                product_warehouse::where('warehouse_id', $warehouse_id)->update([
                    'deleted_at' => Carbon::now(),
                ]);
            }

        }, 10);

        return response()->json(['success' => true]);
    }

    // ----------- GET ALL  Warehouse --------------\\

    public function Get_Warehouses()
    {
        $Warehouses = Warehouse::where('deleted_at', '=', null)->get();

        return response()->json($Warehouses);
    }
}
