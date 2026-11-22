<?php

namespace App\Http\Controllers;

use App\Models\RestaurantArea;
use App\Models\RestaurantSetting;
use App\Models\RestaurantTable;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Restaurant Management › Floor setup (permission rst_setup): dining areas and
 * their tables. Areas tied to a warehouse are only visible to users who may
 * see that warehouse; areas without one belong to every branch.
 */
class RestaurantFloorSetupController extends BaseController
{
    /** GET restaurant/setup/floor → {ready, areas: [... tables], warehouses} */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_setup', RestaurantSetting::class);
        if (! RestaurantArea::tablesReady()) {
            return response()->json(['ready' => false, 'areas' => [], 'warehouses' => []]);
        }

        $areas = $this->scopeToWarehouses(RestaurantArea::query(), 'warehouse_id', true)
            ->with(['tables' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'), 'warehouse:id,name'])
            ->orderBy('sort_order')->orderBy('id')->get();

        return response()->json([
            'ready' => true,
            'areas' => $areas->map(fn (RestaurantArea $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'warehouse_id' => $a->warehouse_id,
                'warehouse_name' => $a->warehouse->name ?? null,
                'is_active' => $a->is_active,
                'tables' => $a->tables->map(fn (RestaurantTable $t) => [
                    'id' => $t->id, 'name' => $t->name, 'seats' => $t->seats, 'shape' => $t->shape,
                    'is_active' => $t->is_active, 'status' => $t->status, 'in_use' => $t->status !== 'free' || (bool) $t->merged_into_id,
                ])->values(),
            ])->values(),
            'warehouses' => $this->scopeToWarehouses(Warehouse::whereNull('deleted_at'), 'id')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * GET restaurant/setup/qr-codes[?table_id=] → the tables' QR ordering links
     * and printable QR images (a table gets its secret link on first use).
     */
    public function qrCodes(Request $request, \App\Services\Restaurant\QrOrderingService $qr)
    {
        $this->authorizeForUser($request->user('api'), 'rst_setup', RestaurantSetting::class);
        if (! RestaurantArea::tablesReady() || ! \Illuminate\Support\Facades\Schema::hasColumn('rst_tables', 'qr_token')) {
            return response()->json(['message' => 'Run the latest database update first.'], 503);
        }
        $areaIds = $this->scopeToWarehouses(RestaurantArea::query(), 'warehouse_id', true)->pluck('id');
        $tables = RestaurantTable::whereIn('area_id', $areaIds)->where('is_active', true)
            ->when($request->filled('table_id'), fn ($q) => $q->whereKey((int) $request->input('table_id')))
            ->with('area:id,name')->orderBy('area_id')->orderBy('sort_order')->orderBy('id')->get();
        $s = RestaurantSetting::current();

        return response()->json([
            'enabled' => (bool) ($s->qr_ordering_enabled ?? false),
            // The QR pages are part of the online store: off store / plan without online orders = dead links.
            'store_live' => (bool) (\DB::table('store_settings')->value('enabled') ?? false) && \App\Services\Crm\CrmCaptureService::planAllows('online_orders'),
            'store_name' => (string) (\DB::table('store_settings')->value('store_name') ?: config('app.name')),
            'tables' => $tables->map(function (RestaurantTable $t) use ($qr) {
                $url = $qr::url($qr->token($t));

                return ['id' => $t->id, 'name' => $t->name, 'area' => $t->area->name ?? null, 'url' => $url, 'qr' => \App\Support\FiscalQr::dataUri($url, 360)];
            })->values(),
        ]);
    }

    /** POST restaurant/setup/tables/{id}/qr/renew — a new link (printed codes for the table stop working). */
    public function renewQr(Request $request, \App\Services\Restaurant\QrOrderingService $qr, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_setup', RestaurantSetting::class);
        if (! \Illuminate\Support\Facades\Schema::hasColumn('rst_tables', 'qr_token')) {
            return response()->json(['message' => 'Run the latest database update first.'], 503);
        }
        $t = $this->findTable($id);
        $url = $qr::url($qr->token($t, true));

        return response()->json(['id' => $t->id, 'url' => $url, 'qr' => \App\Support\FiscalQr::dataUri($url, 360)]);
    }

    public function storeArea(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_setup', RestaurantSetting::class);
        $this->ready();
        $data = $this->validateArea($request);
        $data['sort_order'] = (int) RestaurantArea::max('sort_order') + 1;
        $area = RestaurantArea::create($data);

        return response()->json(['id' => $area->id], 201);
    }

    public function updateArea(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_setup', RestaurantSetting::class);
        $this->ready();
        $area = $this->findArea($id, true);
        $data = $this->validateArea($request);
        if (! $data['is_active'] && $area->tables()->where(fn ($q) => $q->where('status', '!=', 'free')->orWhereNotNull('merged_into_id'))->exists()) {
            return response()->json(['message' => __('Some tables in this area have guests. Clear them first.')], 422);
        }
        $movedBranch = (int) $area->warehouse_id !== (int) $data['warehouse_id'];
        DB::transaction(function () use ($area, $data, $movedBranch) {
            $area->update($data);
            // Bookings and waiting parties of the area follow it to its new branch.
            if ($movedBranch) {
                $this->moveBookings(fn ($q) => $q->where('area_id', $area->id), $area->id, $data['warehouse_id']);
            }
        });

        return response()->json(['success' => true]);
    }

    public function destroyArea(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_setup', RestaurantSetting::class);
        $this->ready();
        $area = $this->findArea($id, true);
        if ($area->tables()->where(fn ($q) => $q->where('status', '!=', 'free')->orWhereNotNull('merged_into_id'))->exists()) {
            return response()->json(['message' => __('Some tables in this area have guests. Clear them first.')], 422);
        }
        DB::transaction(function () use ($area) {
            $area->tables()->delete();
            $area->delete();
        });

        return response()->json(['success' => true]);
    }

    /** POST restaurant/setup/areas/reorder {ids: [...]} */
    public function reorderAreas(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_setup', RestaurantSetting::class);
        $this->ready();
        $ids = $request->validate(['ids' => 'required|array|max:200', 'ids.*' => 'integer'])['ids'];
        $visible = $this->scopeToWarehouses(RestaurantArea::query(), 'warehouse_id', true)->whereIn('id', $ids)->pluck('id')->all();
        foreach (array_values(array_intersect($ids, $visible)) as $i => $id) {
            RestaurantArea::whereKey($id)->update(['sort_order' => $i]);
        }

        return response()->json(['success' => true]);
    }

    public function storeTable(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_setup', RestaurantSetting::class);
        $this->ready();
        $data = $this->validateTable($request);
        $this->findArea($data['area_id'], true);
        $data['sort_order'] = (int) RestaurantTable::where('area_id', $data['area_id'])->max('sort_order') + 1;
        $table = RestaurantTable::create($data + ['status' => 'free']);

        return response()->json(['id' => $table->id], 201);
    }

    public function updateTable(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_setup', RestaurantSetting::class);
        $this->ready();
        $table = $this->findTable($id, true);
        $data = $this->validateTable($request, $table);
        $newArea = $this->findArea($data['area_id'], true);

        return DB::transaction(function () use ($table, $data, $newArea) {
            // Locked: a waiter seating the table meanwhile is seen.
            $table = RestaurantTable::whereKey($table->id)->lockForUpdate()->first();
            $inUse = $table->status !== 'free' || $table->merged_into_id || $table->mergedTables()->exists();
            if ($inUse && (! $data['is_active'] || $data['area_id'] !== $table->area_id)) {
                return response()->json(['message' => __('Table :table has guests. Clear it first.', ['table' => $table->name])], 422);
            }
            $movedArea = $data['area_id'] !== $table->area_id;
            $table->update($data);
            // Its coming bookings go with it to the new area / branch.
            if ($movedArea) {
                $this->moveBookings(fn ($q) => $q->where('table_id', $table->id), $newArea->id, $newArea->warehouse_id, false);
            }

            return response()->json(['success' => true]);
        });
    }

    public function destroyTable(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_setup', RestaurantSetting::class);
        $this->ready();
        $table = $this->findTable($id, true);

        return DB::transaction(function () use ($table) {
            $table = RestaurantTable::whereKey($table->id)->lockForUpdate()->first();
            if ($table->status !== 'free' || $table->merged_into_id || $table->mergedTables()->exists()) {
                return response()->json(['message' => __('Table :table has guests. Clear it first.', ['table' => $table->name])], 422);
            }
            $table->delete();

            return response()->json(['success' => true]);
        });
    }

    /**
     * POST restaurant/setup/tables/bulk {area_id, prefix, from, to, seats, shape}
     * → T1…T12 in one go; names that already exist in the area are skipped.
     */
    public function bulkTables(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_setup', RestaurantSetting::class);
        $data = $request->validate([
            'area_id' => 'required|integer',
            'prefix' => ['nullable', 'string', 'max:10', 'regex:/^[\pL\pN _-]*$/u'],
            'from' => 'required|integer|min:0|max:9999',
            'to' => 'required|integer|gte:from|max:9999',
            'seats' => 'required|integer|min:1|max:50',
            'shape' => ['required', Rule::in(RestaurantTable::SHAPES)],
        ]);
        $this->ready();
        if ($data['to'] - $data['from'] >= 100) {
            return response()->json(['message' => __('Add at most 100 tables at a time.')], 422);
        }
        $area = $this->findArea($data['area_id'], true);
        $existing = RestaurantTable::where('area_id', $area->id)->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        $order = (int) RestaurantTable::where('area_id', $area->id)->max('sort_order');
        $created = [];
        $skipped = [];
        DB::transaction(function () use ($data, $area, $existing, &$order, &$created, &$skipped) {
            for ($n = $data['from']; $n <= $data['to']; $n++) {
                $name = mb_substr(($data['prefix'] ?? '').$n, 0, 20);
                if (in_array(mb_strtolower($name), $existing, true)) {
                    $skipped[] = $name;

                    continue;
                }
                RestaurantTable::create(['area_id' => $area->id, 'name' => $name, 'seats' => $data['seats'], 'shape' => $data['shape'], 'sort_order' => ++$order, 'status' => 'free']);
                $created[] = $name;
            }
        });

        return response()->json(['created' => $created, 'skipped' => $skipped], 201);
    }

    /** POST restaurant/setup/tables/reorder {area_id, ids: [...]} */
    public function reorderTables(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_setup', RestaurantSetting::class);
        $this->ready();
        $data = $request->validate(['area_id' => 'required|integer', 'ids' => 'required|array|max:500', 'ids.*' => 'integer']);
        $area = $this->findArea($data['area_id'], true);
        $own = RestaurantTable::where('area_id', $area->id)->whereIn('id', $data['ids'])->pluck('id')->all();
        foreach (array_values(array_intersect($data['ids'], $own)) as $i => $id) {
            RestaurantTable::whereKey($id)->update(['sort_order' => $i]);
        }

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------

    private function validateArea(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->whereNull('deleted_at')],
            'is_active' => 'nullable|boolean',
        ]);
        if (! empty($data['warehouse_id'])) {
            $this->abortIfWarehouseDenied($data['warehouse_id']);
        } elseif ($this->warehouseScopeIds() !== null) {
            // Staff limited to some branches cannot make an area every branch shares.
            abort(response()->json(['message' => __('Choose the branch this area belongs to.'), 'errors' => ['warehouse_id' => [__('Choose the branch this area belongs to.')]]], 422));
        }

        return ['name' => trim($data['name']), 'warehouse_id' => $data['warehouse_id'] ?? null, 'is_active' => (bool) ($data['is_active'] ?? true)];
    }

    private function validateTable(Request $request, ?RestaurantTable $table = null): array
    {
        $areaId = (int) $request->input('area_id');
        $data = $request->validate([
            'area_id' => 'required|integer',
            'name' => ['required', 'string', 'max:20', Rule::unique('rst_tables', 'name')->where('area_id', $areaId)->whereNull('deleted_at')->ignore($table?->id)],
            'seats' => 'required|integer|min:1|max:50',
            'shape' => ['required', Rule::in(RestaurantTable::SHAPES)],
            'is_active' => 'nullable|boolean',
        ]);

        return ['area_id' => $areaId, 'name' => trim($data['name']), 'seats' => (int) $data['seats'], 'shape' => $data['shape'], 'is_active' => (bool) ($data['is_active'] ?? true)];
    }

    /** $write: areas shared by every branch can only be changed by staff who see every branch. */
    private function findArea($id, bool $write = false): RestaurantArea
    {
        $area = $this->scopeToWarehouses(RestaurantArea::query(), 'warehouse_id', true)->findOrFail((int) $id);
        if ($write && $area->warehouse_id === null && $this->warehouseScopeIds() !== null) {
            abort(403, __('This area is shared by every branch. Ask someone with access to all branches to change it.'));
        }

        return $area;
    }

    private function findTable($id, bool $write = false): RestaurantTable
    {
        $table = RestaurantTable::findOrFail((int) $id);
        $this->findArea($table->area_id, $write);

        return $table;
    }

    private function ready(): void
    {
        if (! RestaurantArea::tablesReady()) {
            abort(503, 'Run the latest database update first.');
        }
    }

    /**
     * Bookings (still to come or seated) and waiting parties matched by $where
     * move to the area / branch. $waitlist: also the area's waitlist (an area move).
     */
    private function moveBookings(callable $where, int $areaId, $warehouseId, bool $waitlist = true): void
    {
        if (! \App\Models\RestaurantReservation::tablesReady()) {
            return;
        }
        $where(DB::table('rst_reservations')->whereIn('status', \App\Models\RestaurantReservation::ACTIVE))
            ->update(['area_id' => $areaId, 'warehouse_id' => $warehouseId, 'updated_at' => now()]);
        if ($waitlist) {
            DB::table('rst_waitlist')->where('area_id', $areaId)->whereIn('status', \App\Models\RestaurantWaitlistEntry::ACTIVE)
                ->update(['warehouse_id' => $warehouseId, 'updated_at' => now()]);
        }
    }
}
