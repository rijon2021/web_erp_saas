<?php

namespace App\Http\Controllers;

use App\Mail\CustomEmail;
use App\Models\Account;
use App\Models\EmailMessage;
use App\Models\PaymentMethod;
use App\Models\PaymentPurchase;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\ProductVariant;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseCustomField;
use App\Models\PurchaseDetail;
use App\Models\PurchaseExtraCharge;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\Setting;
use App\Models\sms_gateway;
use App\Models\SMSMessage;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\BatchService;
use App\Services\ImportFingerprint;
use App\Services\Opencart\AutoSync as OpencartAutoSync;
use App\Services\SerialNumberService;
use App\Services\WooCommerce\AutoSync as WooAutoSync;
use App\utils\helpers;
use ArPHP\I18N\Arabic;
use Carbon\Carbon;
use DB;
use GuzzleHttp\Client as Client_guzzle;
use GuzzleHttp\Client as Client_termi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Infobip\Api\SendSmsApi;
use Infobip\Configuration;
use Infobip\Model\SmsAdvancedTextualRequest;
use Infobip\Model\SmsDestination;
use Infobip\Model\SmsTextualMessage;
use PDF;
use Twilio\Rest\Client as Client_Twilio;

class PurchasesController extends BaseController
{
    // ------------- Show ALL Purchases ----------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $is_all_warehouses = $user->is_all_warehouses;
        // If the user is restricted, fetch their assigned warehouse IDs once and reuse below.
        if (! $is_all_warehouses) {
            $warehouse_ids = UserWarehouse::where('user_id', $user->id)
                ->pluck('warehouse_id')
                ->toArray();
        }
        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        $helpers = new helpers;
        // Filter fields With Params to retrieve
        $param = [
            0 => 'like',
            1 => '=',
            2 => '=',
            3 => '=',
            4 => '=',
            5 => '=',
        ];
        $columns = [
            0 => 'Ref',
            1 => 'statut',
            2 => 'provider_id',
            3 => 'payment_statut',
            4 => 'warehouse_id',
            5 => 'date',
        ];
        $data = [];
        $total = 0;

        // Check If User Has Permission View  All Records
        $Purchases = Purchase::with('provider', 'warehouse', 'currency')
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });
        if (! $is_all_warehouses) {
            $Purchases->whereIn('warehouse_id', $warehouse_ids);
        }

        // Multiple Filter
        $Filtred = $helpers->filter($Purchases, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('order_reference', 'LIKE', "%{$request->search}%")
                        ->orWhere('statut', 'LIKE', "%{$request->search}%")
                        ->orWhere('GrandTotal', $request->search)
                        ->orWhere('payment_statut', 'like', "$request->search")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('provider', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('warehouse', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });

        $totalRows = $Filtred->count();

        // Summary cards on the list page: global sums over the whole filtered
        // set (every page), computed before pagination is applied.
        $sumTotal = (float) (clone $Filtred)->sum('GrandTotal');
        $sumPaid  = (float) (clone $Filtred)->sum('paid_amount');
        $stats = [
            'count' => $totalRows,
            'total' => round($sumTotal, 2),
            'paid'  => round($sumPaid, 2),
            'due'   => round(max($sumTotal - $sumPaid, 0), 2),
        ];
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $Purchases = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        // Returns and document counts for the whole page in two queries, not
        // up to three per purchase: the "All" page size and the list exports
        // load every row. Raw integers because a big page would otherwise
        // pass MySQL's 65,535 placeholder limit.
        $purchaseIds = $Purchases->pluck('id')->all();
        $returnIdByPurchase = PurchaseReturn::whereIntegerInRaw('purchase_id', $purchaseIds)
            ->whereNull('deleted_at')
            ->groupBy('purchase_id')
            ->selectRaw('purchase_id, MIN(id) as return_id')
            ->pluck('return_id', 'purchase_id');
        $documentCounts = DB::table('purchase_documents')
            ->whereIntegerInRaw('purchase_id', $purchaseIds)
            ->whereNull('deleted_at')
            ->groupBy('purchase_id')
            ->selectRaw('purchase_id, COUNT(*) as total')
            ->pluck('total', 'purchase_id');

        foreach ($Purchases as $Purchase) {

            $item = [];
            $item['id'] = $Purchase->id;
            $item['date'] = $Purchase['date'].' '.$Purchase['time'];
            $item['Ref'] = $Purchase->Ref;
            $item['order_reference'] = $Purchase->order_reference;
            $item['warehouse_name'] = $Purchase['warehouse']->name;
            $item['discount'] = $Purchase->discount;
            $item['shipping'] = $Purchase->shipping;
            $item['statut'] = $Purchase->statut;
            $item['provider_id'] = $Purchase['provider']->id;
            $item['provider_name'] = $Purchase['provider']->name;
            $item['provider_email'] = $Purchase['provider']->email;
            $item['provider_tele'] = $Purchase['provider']->phone;
            $item['provider_code'] = $Purchase['provider']->code;
            $item['provider_adr'] = $Purchase['provider']->adresse;
            $item['GrandTotal'] = number_format($Purchase->GrandTotal, helpers::price_decimals(), '.', '');
            $item['paid_amount'] = number_format($Purchase->paid_amount, helpers::price_decimals(), '.', '');
            $item['due'] = number_format($item['GrandTotal'] - $item['paid_amount'], helpers::price_decimals(), '.', '');
            $item['payment_status'] = $Purchase->payment_statut;
            // Multi-Currency badge: the document's currency code, null for
            // base-currency documents (amounts in this list are always base).
            $item['currency_code'] = optional($Purchase['currency'])->code ? strtoupper($Purchase['currency']->code) : null;

            if (isset($returnIdByPurchase[$Purchase['id']])) {
                $item['purchasereturn_id'] = $returnIdByPurchase[$Purchase['id']];
                $item['purchase_has_return'] = 'yes';
            } else {
                $item['purchase_has_return'] = 'no';
            }

            // Get documents count
            $item['documents_count'] = (int) ($documentCounts[$Purchase['id']] ?? 0);

            $data[] = $item;
        }

        $suppliers = provider::where('deleted_at', '=', null)->get(['id', 'name']);
        $accounts = Account::where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'account_name']);

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        $payment_methods = PaymentMethod::active()->exceptCustomerWallet()->whereNull('deleted_at')->get(['id', 'name']);

        return response()->json([
            'totalRows' => $totalRows,
            'stats' => $stats,
            'purchases' => $data,
            'suppliers' => $suppliers,
            'warehouses' => $warehouses,
            'accounts' => $accounts,
            'payment_methods' => $payment_methods,
        ]);
    }

    // ------ Store new Purchase -------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Purchase::class);

        request()->validate([
            'supplier_id' => 'required',
            'warehouse_id' => 'required',
            'order_reference' => 'nullable|string|max:100',
        ]);
        $this->assertBatchCostsMatchLines($request);

        $createdId = null;
        $sellingPriceOn = $this->sellingPriceOnPurchaseEnabled();
        $productCostOn = $this->productCostOnPurchaseEnabled();
        \DB::transaction(function () use ($request, &$createdId, $sellingPriceOn, $productCostOn) {
            $order = new Purchase;

            $order->date = $request->date;
            $order->time = now()->toTimeString();
            $order->Ref = $this->getNumberOrder();
            $order->provider_id = $request->supplier_id;
            $order->GrandTotal = $request->GrandTotal;
            $order->warehouse_id = $request->warehouse_id;
            $order->tax_rate = $request->tax_rate;
            $order->TaxNet = $request->TaxNet;
            $order->discount = $request->discount;
            $order->shipping = $request->shipping;
            $order->statut = $request->statut;
            $order->payment_statut = 'unpaid';
            $order->notes = $request->notes;
            $order->order_reference = $this->orderReference($request);
            $order->user_id = Auth::user()->id;
            // Multi-Currency snapshot (NULL/NULL = base currency); amounts on
            // the purchase are always stored in the base currency.
            $docCurrency = helpers::resolve_request_currency($request);
            $order->currency_id = $docCurrency['currency_id'];
            $order->exchange_rate = $docCurrency['exchange_rate'];

            $order->save();
            $createdId = $order->id;

            $this->syncExtraChargesAndCustomFields($order->id, $request);

            $data = $request['details'];
            foreach ($data as $key => $value) {
                $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                $orderDetails[] = [
                    'purchase_id' => $order->id,
                    'quantity' => $value['quantity'],
                    'cost' => $value['Unit_cost'],
                    'purchase_unit_id' => $value['purchase_unit_id'],
                    'TaxNet' => $value['tax_percent'],
                    'tax_method' => $value['tax_method'],
                    'discount' => $value['discount'],
                    'discount_method' => $value['discount_Method'],
                    'product_id' => $value['product_id'],
                    'product_variant_id' => $value['product_variant_id'],
                    'total' => $value['subtotal'],
                    'imei_number' => $this->resolveImeiString($value),
                ];
                if ($sellingPriceOn) {
                    $orderDetails[count($orderDetails) - 1]['price'] = $this->sellingPriceInput($value);
                }

                if ($order->statut == 'received') {
                    if ($value['product_variant_id'] !== null) {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $order->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->where('product_variant_id', $value['product_variant_id'])
                            ->lockForUpdate()->first();

                        if ($unit && $product_warehouse) {
                            if ($unit->operator == '/') {
                                $product_warehouse->qte += $value['quantity'] / $unit->operator_value;
                            } else {
                                $product_warehouse->qte += $value['quantity'] * $unit->operator_value;
                            }
                            $product_warehouse->save();
                        }

                    } else {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $order->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->lockForUpdate()->first();

                        if ($unit && $product_warehouse) {
                            if ($unit->operator == '/') {
                                $product_warehouse->qte += $value['quantity'] / $unit->operator_value;
                            } else {
                                $product_warehouse->qte += $value['quantity'] * $unit->operator_value;
                            }
                            $product_warehouse->save();
                        }
                    }
                }
            }
            PurchaseDetail::insert($orderDetails);

            if ($sellingPriceOn) {
                $this->applySellingPrices($data);
            }
            if ($productCostOn && $order->statut == 'received') {
                $this->applyProductCosts($data);
            }

            // Pharmacy: link captured batches to the freshly inserted PurchaseDetail rows.
            // Re-fetch in id order so position matches the input array order.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported() && $order->statut == 'received') {
                $persisted = PurchaseDetail::where('purchase_id', $order->id)
                    ->orderBy('id', 'asc')
                    ->get();
                $batchService->applyForPurchase($order, $data, $persisted);
            }

            // Serial / IMEI: register one ledger row per received unit.
            $serialService = app(SerialNumberService::class);
            if ($serialService->isSupported() && $order->statut == 'received') {
                $persistedSerials = PurchaseDetail::where('purchase_id', $order->id)
                    ->orderBy('id', 'asc')
                    ->get();
                foreach (array_values($data) as $i => $row) {
                    $detail = $persistedSerials->get($i);
                    if (! $detail) {
                        continue;
                    }
                    $serialService->receiveOnPurchase($order, $detail, $row['serial_numbers'] ?? null);
                }
            }
        }, 10);

        // id: the purchase form attaches a scanned supplier invoice right after saving.
        return response()->json(['success' => true, 'message' => 'Purchase Created !!', 'id' => $createdId]);
    }

    /** Settings > Features > Update Product Cost on Purchase (column must exist too). */
    private function productCostOnPurchaseEnabled(): bool
    {
        $setting = Setting::current();

        return (bool) ($setting->purchase_update_product_cost ?? false)
            && \Schema::hasColumn('settings', 'purchase_update_product_cost');
    }

    /**
     * Base units per purchase unit, as the stock update works it out: a
     * "box of 12" (operator *, value 12) receives 12 base units per line unit.
     */
    private function purchaseUnitFactor($unitId): float
    {
        $unit = $unitId ? Unit::find($unitId) : null;
        if (! $unit) {
            return 1.0;
        }
        $value = (float) ($unit->operator_value ?? 1) ?: 1.0;

        return ($unit->operator ?? '*') === '/' ? 1 / $value : $value;
    }

    /**
     * Last-cost write-back for a RECEIVED purchase: each line's product (or
     * variant) cost becomes the line's net unit cost - after the line
     * discount, without the tax when the cost was entered tax-inclusive -
     * converted to the BASE unit, since the card cost is per base unit. Same
     * rule as stocky's landed-cost "update the products' cost" checkbox.
     * Batch-tracked lines already carry the weighted average of their batch
     * costs (assertBatchCostsMatchLines). Zero-cost lines are skipped and an
     * unchanged figure is not rewritten, so the audit log only shows real
     * moves. Never reverted when the purchase is edited or deleted.
     */
    private function applyProductCosts(array $rows): void
    {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $productId = (int) ($row['product_id'] ?? 0);
            $variantId = (int) ($row['product_variant_id'] ?? 0);
            $qty = (float) ($row['quantity'] ?? 0);
            $unitCost = (float) ($row['Unit_cost'] ?? 0);
            if ($productId <= 0 || $qty <= 0 || $unitCost <= 0) {
                continue;
            }

            $product = Product::where('id', $productId)->where('deleted_at', '=', null)->first();
            if (! $product) {
                continue;
            }

            $discount = (float) ($row['discount'] ?? 0);
            $discountNet = (string) ($row['discount_Method'] ?? '2') === '2' ? $discount : $unitCost * $discount / 100;
            $net = $unitCost - $discountNet;
            if ((string) ($row['tax_method'] ?? '1') === '2') {
                $net -= $net * (float) ($row['tax_percent'] ?? 0) / 100;
            }
            $unitId = $row['purchase_unit_id'] ?? null;
            $unitId = $unitId ?: ($product->unit_purchase_id ?: $product->unit_id);
            $baseCost = round($net / $this->purchaseUnitFactor($unitId), 4);
            if ($baseCost <= 0) {
                continue;
            }

            if ($variantId > 0) {
                $variant = ProductVariant::where('product_id', $productId)->where('id', $variantId)->first();
                if ($variant && abs((float) $variant->cost - $baseCost) > 0.00001) {
                    $variant->update(['cost' => $baseCost]);
                }
                continue;
            }
            if ($product->type !== 'is_variant' && abs((float) $product->cost - $baseCost) > 0.00001) {
                $product->update(['cost' => $baseCost]);
            }
        }
    }

    /**
     * Settings > Features > Edit Selling Price on Purchase, and the tenant has
     * run the migration that adds purchase_details.price.
     */
    private function sellingPriceOnPurchaseEnabled(): bool
    {
        $setting = Setting::current();

        return (bool) ($setting->purchase_update_selling_price ?? false)
            && \Schema::hasColumn('purchase_details', 'price');
    }

    /** The selling price typed on a purchase line, or null when left blank / invalid. */
    private function sellingPriceInput($row): ?float
    {
        $v = is_array($row) ? ($row['price'] ?? null) : null;
        if ($v === null || $v === '' || ! is_numeric($v) || (float) $v < 0) {
            return null;
        }

        return round((float) $v, 4);
    }

    /**
     * Write the selling prices typed on purchase lines back to the products
     * (variant rows for variant lines). Only lines with a price are touched,
     * and only when the figure differs, so untouched products keep their
     * audit history clean and no needless channel push is queued. Goes
     * through Eloquent so the audit observer and the Woo/OpenCart auto-sync
     * see the change like a product-form edit.
     */
    private function applySellingPrices(array $rows): void
    {
        $changed = [];
        foreach ($rows as $row) {
            $price = $this->sellingPriceInput($row);
            if ($price === null) {
                continue;
            }
            $productId = (int) ($row['product_id'] ?? 0);
            $variantId = (int) ($row['product_variant_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            if ($variantId > 0) {
                $variant = ProductVariant::where('product_id', $productId)->where('id', $variantId)->first();
                if ($variant && abs((float) $variant->price - $price) > 0.00001) {
                    $variant->update(['price' => $price]);
                    $changed[$productId] = true;
                }
                continue;
            }

            $product = Product::where('id', $productId)->where('deleted_at', '=', null)->first();
            if ($product && $product->type !== 'is_variant' && abs((float) $product->price - $price) > 0.00001) {
                $product->update(['price' => $price]);
                $changed[$productId] = true;
            }
        }

        foreach (array_keys($changed) as $pid) {
            WooAutoSync::productSaved($pid);
            OpencartAutoSync::productSaved($pid);
        }
    }

    /**
     * Back-compat: collapse a detail row's serial_numbers (array or delimited
     * string) into the legacy comma-separated imei_number text column so existing
     * invoice/receipt templates keep working. Falls back to the raw imei_number.
     */
    private function resolveImeiString($value)
    {
        $serials = $value['serial_numbers'] ?? null;
        if (is_string($serials)) {
            $serials = preg_split('/[\r\n,;\t]+/', $serials) ?: [];
        }
        if (is_array($serials)) {
            $clean = array_values(array_filter(array_map(fn ($s) => trim((string) $s), $serials), fn ($s) => $s !== ''));
            if (! empty($clean)) {
                return implode(',', $clean);
            }
        }

        return $value['imei_number'] ?? null;
    }

    /**
     * Replace a purchase's extra charges (name + amount, included in GrandTotal)
     * and custom fields (name + free text value, informational only) with the
     * arrays sent by the create/edit forms.
     */
    private function syncExtraChargesAndCustomFields($purchaseId, Request $request)
    {
        PurchaseExtraCharge::where('purchase_id', $purchaseId)->whereNull('deleted_at')
            ->update(['deleted_at' => Carbon::now()]);
        PurchaseCustomField::where('purchase_id', $purchaseId)->whereNull('deleted_at')
            ->update(['deleted_at' => Carbon::now()]);

        foreach ((array) $request['extra_charges'] as $charge) {
            $name = trim((string) ($charge['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            PurchaseExtraCharge::create([
                'purchase_id' => $purchaseId,
                'name' => $name,
                'amount' => is_numeric($charge['amount'] ?? null) ? $charge['amount'] : 0,
            ]);
        }

        foreach ((array) $request['custom_fields'] as $field) {
            $name = trim((string) ($field['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            PurchaseCustomField::create([
                'purchase_id' => $purchaseId,
                'name' => $name,
                'value' => $field['value'] ?? null,
            ]);
        }
    }

    // --------- Update Purchase  -------------\\

    /**
     * A batch-tracked line is costed at the quantity-weighted average of its
     * batch unit costs, and the purchase form derives Unit_cost that way. Any
     * client that posts a line cost disagreeing with its own batch rows would
     * store a purchase whose subtotal, COGS and stock value contradict the
     * batches, so it is refused up front with a 422 the form surfaces as-is.
     */
    protected function assertBatchCostsMatchLines(Request $request): void
    {
        $batchService = app(BatchService::class);
        if (! $batchService->isSupported()) {
            return;
        }

        $errors = [];
        foreach ((array) $request->input('details', []) as $i => $row) {
            if (! is_array($row) || ! $batchService->productIsTracked($row['product_id'] ?? null)) {
                continue;
            }
            $avg = $batchService->weightedAverageCostForRow($row);
            if ($avg === null) {
                continue;
            }
            $lineCost = (float) ($row['Unit_cost'] ?? 0);
            if (abs($lineCost - $avg) > 0.01) {
                $label = trim((string) ($row['name'] ?? $row['code'] ?? ('#'.($i + 1))));
                $errors["details.$i.Unit_cost"] = __('messages.BatchCostMismatch', [
                    'product' => $label,
                    'cost' => number_format($lineCost, helpers::price_decimals(), '.', ''),
                    'avg' => number_format($avg, helpers::price_decimals(), '.', ''),
                ]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Purchase::class);

        request()->validate([
            'warehouse_id' => 'required',
            'supplier_id' => 'required',
            'order_reference' => 'nullable|string|max:100',
        ]);
        $this->assertBatchCostsMatchLines($request);
        $sellingPriceOn = $this->sellingPriceOnPurchaseEnabled();
        $productCostOn = $this->productCostOnPurchaseEnabled();

        \DB::transaction(function () use ($request, $id, $sellingPriceOn, $productCostOn) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $current_Purchase = Purchase::findOrFail($id);

             /**
             * Warehouses restriction
             * Allow if:
             * - user has access to all warehouses (is_all_warehouses = 1)
             * - OR sale warehouse_id is in user's assigned warehouses
            */
            $user_auth = auth()->user();

            if (! $user_auth->is_all_warehouses) {
                $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)
                    ->pluck('warehouse_id')
                    ->toArray();

                if (empty($current_Purchase->warehouse_id) || ! in_array($current_Purchase->warehouse_id, $warehouses_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not allowed to access this sale (warehouse restriction).',
                    ], 403);
                }
            }

            if (PurchaseReturn::where('purchase_id', $id)->where('deleted_at', '=', null)->exists()) {
                return response()->json(['success' => false, 'Return exist for the Transaction' => false], 403);
            } else {

                // Check If User Has Permission view All Records
                // Warehouse half of the same rule: record_view says whose documents,
                // the assigned warehouses say which warehouses they may come from.
                $this->abortIfDocumentWarehouseDenied($current_Purchase);

                if (! $view_records) {
                    // Check If User->id === Purchase->id
                    $this->authorizeForUser($request->user('api'), 'check_record', $current_Purchase);
                }

                $old_purchase_details = PurchaseDetail::where('purchase_id', $id)->get();
                $new_purchase_details = $request['details'];
                $length = count($new_purchase_details);

                // Pharmacy: reverse all existing batch links for this purchase before
                // re-applying. The legacy stock-adjustment block below already reverses
                // and re-applies product_warehouse qte; we mirror that for batches.
                $batchService = app(BatchService::class);
                if ($batchService->isSupported()) {
                    $batchService->reverseForPurchaseDetails($old_purchase_details);
                }

                // Serial / IMEI: only touch the serial ledger when the edit payload
                // actually carries serials (forms without serial UI must not wipe them).
                $serialService = app(SerialNumberService::class);
                $serialPayloadPresent = $serialService->payloadHasSerials($new_purchase_details);
                if ($serialService->isSupported() && $serialPayloadPresent) {
                    $serialService->reverseForPurchaseDetails($old_purchase_details);
                }

                // Get Ids for new Details
                $new_products_id = [];
                foreach ($new_purchase_details as $new_detail) {
                    $new_products_id[] = $new_detail['id'];
                }

                // Init Data with old Parametre
                $old_products_id = [];
                foreach ($old_purchase_details as $key => $value) {
                    $old_products_id[] = $value->id;

                    // check if detail has purchase_unit_id Or Null
                    if ($value['purchase_unit_id'] !== null) {
                        $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                    } else {
                        $product_unit_purchase_id = Product::with('unitPurchase')
                            ->where('id', $value['product_id'])
                            ->first();
                        $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                    }

                    if ($value['purchase_unit_id'] !== null) {
                        if ($current_Purchase->statut == 'received') {

                            if ($value['product_variant_id'] !== null) {
                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $current_Purchase->warehouse_id)
                                    ->where('product_id', $value['product_id'])
                                    ->where('product_variant_id', $value['product_variant_id'])
                                    ->lockForUpdate()->first();

                                if ($unit && $product_warehouse) {
                                    if ($unit->operator == '/') {
                                        $product_warehouse->qte -= $value['quantity'] / $unit->operator_value;
                                    } else {
                                        $product_warehouse->qte -= $value['quantity'] * $unit->operator_value;
                                    }

                                    $product_warehouse->save();
                                }

                            } else {
                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $current_Purchase->warehouse_id)
                                    ->where('product_id', $value['product_id'])
                                    ->lockForUpdate()->first();

                                if ($unit && $product_warehouse) {
                                    if ($unit->operator == '/') {
                                        $product_warehouse->qte -= $value['quantity'] / $unit->operator_value;
                                    } else {
                                        $product_warehouse->qte -= $value['quantity'] * $unit->operator_value;
                                    }

                                    $product_warehouse->save();
                                }
                            }
                        }

                        // Delete Detail
                        if (! in_array($old_products_id[$key], $new_products_id)) {
                            $PurchaseDetail = PurchaseDetail::findOrFail($value->id);
                            $PurchaseDetail->delete();
                        }
                    }

                }

                // Update Data with New request
                foreach ($new_purchase_details as $key => $prod_detail) {

                    if (($prod_detail['no_unit'] ?? 1) !== 0) {
                        $unit_prod = Unit::where('id', $prod_detail['purchase_unit_id'])->first();

                        if ($request['statut'] == 'received') {

                            if ($prod_detail['product_variant_id'] !== null) {
                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $request->warehouse_id)
                                    ->where('product_id', $prod_detail['product_id'])
                                    ->where('product_variant_id', $prod_detail['product_variant_id'])
                                    ->lockForUpdate()->first();

                                if ($unit_prod && $product_warehouse) {
                                    if ($unit_prod->operator == '/') {
                                        $product_warehouse->qte += $prod_detail['quantity'] / $unit_prod->operator_value;
                                    } else {
                                        $product_warehouse->qte += $prod_detail['quantity'] * $unit_prod->operator_value;
                                    }

                                    $product_warehouse->save();
                                }

                            } else {
                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $request->warehouse_id)
                                    ->where('product_id', $prod_detail['product_id'])
                                    ->lockForUpdate()->first();

                                if ($unit_prod && $product_warehouse) {
                                    if ($unit_prod->operator == '/') {
                                        $product_warehouse->qte += $prod_detail['quantity'] / $unit_prod->operator_value;
                                    } else {
                                        $product_warehouse->qte += $prod_detail['quantity'] * $unit_prod->operator_value;
                                    }

                                    $product_warehouse->save();
                                }
                            }

                        }

                        $orderDetails['purchase_id'] = $id;
                        $orderDetails['cost'] = $prod_detail['Unit_cost'];
                        $orderDetails['purchase_unit_id'] = $prod_detail['purchase_unit_id'];
                        $orderDetails['TaxNet'] = $prod_detail['tax_percent'];
                        $orderDetails['tax_method'] = $prod_detail['tax_method'];
                        $orderDetails['discount'] = $prod_detail['discount'];
                        $orderDetails['discount_method'] = $prod_detail['discount_Method'];
                        $orderDetails['quantity'] = $prod_detail['quantity'];
                        $orderDetails['product_id'] = $prod_detail['product_id'];
                        $orderDetails['product_variant_id'] = $prod_detail['product_variant_id'];
                        $orderDetails['total'] = $prod_detail['subtotal'];
                        $orderDetails['imei_number'] = $this->resolveImeiString($prod_detail);
                        if ($sellingPriceOn) {
                            $orderDetails['price'] = $this->sellingPriceInput($prod_detail);
                        }

                        if (! in_array($prod_detail['id'], $old_products_id)) {
                            PurchaseDetail::Create($orderDetails);
                        } else {
                            PurchaseDetail::where('id', $prod_detail['id'])->update($orderDetails);
                        }
                    }
                }

                if ($sellingPriceOn) {
                    $this->applySellingPrices($new_purchase_details);
                }
                if ($productCostOn && $request['statut'] == 'received') {
                    $this->applyProductCosts($new_purchase_details);
                }

                $due = $request['GrandTotal'] - $current_Purchase->paid_amount;
                if ($due === 0.0 || $due < 0.0) {
                    $payment_statut = 'paid';
                } elseif ($due != $request['GrandTotal']) {
                    $payment_statut = 'partial';
                } elseif ($due == $request['GrandTotal']) {
                    $payment_statut = 'unpaid';
                }

                $current_Purchase->update([
                    'date' => $request['date'],
                    'provider_id' => $request['supplier_id'],
                    'warehouse_id' => $request['warehouse_id'],
                    'notes' => $request['notes'],
                    // Only forms that carry the field may change it.
                    'order_reference' => $request->has('order_reference') ? $this->orderReference($request) : $current_Purchase->order_reference,
                    'tax_rate' => $request['tax_rate'],
                    'TaxNet' => $request['TaxNet'],
                    'discount' => $request['discount'],
                    'shipping' => $request['shipping'],
                    'statut' => $request['statut'],
                    'GrandTotal' => $request['GrandTotal'],
                    'payment_statut' => $payment_statut,
                ] + helpers::resolve_request_currency($request, $current_Purchase->currency_id, $current_Purchase->exchange_rate));

                $this->syncExtraChargesAndCustomFields($id, $request);

                // Pharmacy: re-apply batches to the now-persisted PurchaseDetail rows.
                if ($batchService->isSupported() && $current_Purchase->statut == 'received') {
                    // Build aligned arrays: skip rows the controller skipped (no_unit === 0).
                    $alignedInput = [];
                    foreach ($new_purchase_details as $row) {
                        if (($row['no_unit'] ?? 1) !== 0) {
                            $alignedInput[] = $row;
                        }
                    }
                    $persisted = PurchaseDetail::where('purchase_id', $id)
                        ->orderBy('id', 'asc')
                        ->get();
                    // Match by (product_id, product_variant_id) preserving input order.
                    $aligned = [];
                    $persistedByKey = [];
                    foreach ($persisted as $d) {
                        $k = $d->product_id . '|' . ($d->product_variant_id ?? '');
                        $persistedByKey[$k][] = $d;
                    }
                    foreach ($alignedInput as $row) {
                        $k = ($row['product_id'] ?? '') . '|' . ($row['product_variant_id'] ?? '');
                        $match = isset($persistedByKey[$k]) ? array_shift($persistedByKey[$k]) : null;
                        $aligned[] = $match;
                    }
                    $batchService->applyForPurchase($current_Purchase, $alignedInput, $aligned);
                }

                // Serial / IMEI: re-create serials for the now-persisted details.
                if ($serialService->isSupported() && $serialPayloadPresent && $current_Purchase->statut == 'received') {
                    $sInput = [];
                    foreach ($new_purchase_details as $row) {
                        if (($row['no_unit'] ?? 1) !== 0) {
                            $sInput[] = $row;
                        }
                    }
                    $sPersisted = PurchaseDetail::where('purchase_id', $id)
                        ->orderBy('id', 'asc')
                        ->get();
                    $sByKey = [];
                    foreach ($sPersisted as $d) {
                        $sByKey[$d->product_id . '|' . ($d->product_variant_id ?? '')][] = $d;
                    }
                    $sAligned = [];
                    foreach ($sInput as $row) {
                        $k = ($row['product_id'] ?? '') . '|' . ($row['product_variant_id'] ?? '');
                        $sAligned[] = isset($sByKey[$k]) ? array_shift($sByKey[$k]) : null;
                    }
                    $serialService->resyncPurchaseSerials($current_Purchase, $sInput, $sAligned);
                }
            }

        }, 10);

        return response()->json(['success' => true, 'message' => 'Purchase Updated !!']);

    }

    // ------ Delete Purchase -------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Purchase::class);

        \DB::transaction(function () use ($id, $request) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $current_Purchase = Purchase::findOrFail($id);

             /**
             * Warehouses restriction
             * Allow if:
             * - user has access to all warehouses (is_all_warehouses = 1)
             * - OR sale warehouse_id is in user's assigned warehouses
            */
            $user_auth = auth()->user();

            if (! $user_auth->is_all_warehouses) {
                $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)
                    ->pluck('warehouse_id')
                    ->toArray();

                if (empty($current_Purchase->warehouse_id) || ! in_array($current_Purchase->warehouse_id, $warehouses_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not allowed to access this sale (warehouse restriction).',
                    ], 403);
                }
            }

            $old_purchase_details = PurchaseDetail::where('purchase_id', $id)->get();

            if (PurchaseReturn::where('purchase_id', $id)->where('deleted_at', '=', null)->exists()) {
                return response()->json(['success' => false, 'Return exist for the Transaction' => false], 403);
            } else {

                // Check If User Has Permission view All Records
                // Warehouse half of the same rule: record_view says whose documents,
                // the assigned warehouses say which warehouses they may come from.
                $this->abortIfDocumentWarehouseDenied($current_Purchase);

                if (! $view_records) {
                    // Check If User->id === current_Purchase->id
                    $this->authorizeForUser($request->user('api'), 'check_record', $current_Purchase);
                }

                foreach ($old_purchase_details as $key => $value) {

                    // check if detail has purchase_unit_id Or Null
                    if ($value['purchase_unit_id'] !== null) {
                        $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                    } else {
                        $product_unit_purchase_id = Product::with('unitPurchase')
                            ->where('id', $value['product_id'])
                            ->first();
                        $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                    }

                    if ($current_Purchase->statut == 'received') {

                        if ($value['product_variant_id'] !== null) {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Purchase->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->where('product_variant_id', $value['product_variant_id'])
                                ->lockForUpdate()->first();

                            if ($unit && $product_warehouse) {
                                if ($unit->operator == '/') {
                                    $product_warehouse->qte -= $value['quantity'] / $unit->operator_value;
                                } else {
                                    $product_warehouse->qte -= $value['quantity'] * $unit->operator_value;
                                }

                                $product_warehouse->save();
                            }

                        } else {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Purchase->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->lockForUpdate()->first();

                            if ($unit && $product_warehouse) {
                                if ($unit->operator == '/') {
                                    $product_warehouse->qte -= $value['quantity'] / $unit->operator_value;
                                } else {
                                    $product_warehouse->qte -= $value['quantity'] * $unit->operator_value;
                                }

                                $product_warehouse->save();
                            }
                        }
                    }
                }

                // Pharmacy: reverse batch links before soft-deleting the details.
                $batchService = app(BatchService::class);
                if ($batchService->isSupported()) {
                    $batchService->reverseForPurchaseDetails($old_purchase_details);
                }

                // Serial / IMEI: release (or block) serials before deleting the details.
                $serialService = app(SerialNumberService::class);
                if ($serialService->isSupported()) {
                    $serialService->reverseForPurchaseDetails($old_purchase_details);
                }

                $current_Purchase->details()->delete();
                $current_Purchase->update([
                    'deleted_at' => Carbon::now(),
                ]);

                $Payment_purchase_data = PaymentPurchase::where('purchase_id', $id)->get();
                foreach ($Payment_purchase_data as $Payment_purchase) {
                    // Wallet-funded rows go back to the supplier wallet (their account_id is null).
                    app(\App\Services\SupplierWallet\SupplierWalletService::class)->reversePaymentPurchase($Payment_purchase, ['user_id' => Auth::id()]);
                    Account::adjustBalance($Payment_purchase->account_id, $Payment_purchase->montant);

                    $Payment_purchase->delete();
                    // Soft delete fires no `updated` hook: post the Accounting V2 reversal explicitly.
                    event(new \App\Events\PaymentPurchaseDeleted($Payment_purchase));
                }

            }

        }, 10);

        return response()->json(['success' => true, 'message' => 'Purchase Deleted !!']);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Purchase::class);

        \DB::transaction(function () use ($request) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $selectedIds = $request->selectedIds;

            foreach ($selectedIds as $purchase_id) {

                if (PurchaseReturn::where('purchase_id', $purchase_id)->where('deleted_at', '=', null)->exists()) {
                    return response()->json(['success' => false, 'Return exist for the Transaction' => false], 403);
                } else {

                    $current_Purchase = Purchase::findOrFail($purchase_id);

                    /**
                     * Warehouses restriction
                     * Allow if:
                     * - user has access to all warehouses (is_all_warehouses = 1)
                     * - OR sale warehouse_id is in user's assigned warehouses
                    */
                    $user_auth = auth()->user();

                    if (! $user_auth->is_all_warehouses) {
                        $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)
                            ->pluck('warehouse_id')
                            ->toArray();

                        if (empty($current_Purchase->warehouse_id) || ! in_array($current_Purchase->warehouse_id, $warehouses_id)) {
                            return response()->json([
                                'success' => false,
                                'message' => 'You are not allowed to access this sale (warehouse restriction).',
                            ], 403);
                        }
                    }

                    $old_purchase_details = PurchaseDetail::where('purchase_id', $purchase_id)->get();
                    // Check If User Has Permission view All Records
                    // Warehouse half of the same rule: record_view says whose documents,
                    // the assigned warehouses say which warehouses they may come from.
                    $this->abortIfDocumentWarehouseDenied($current_Purchase);

                    if (! $view_records) {
                        // Check If User->id === current_Purchase->id
                        $this->authorizeForUser($request->user('api'), 'check_record', $current_Purchase);
                    }

                    // Pharmacy: reverse batch consumption (subtracts qty from product_batches,
                    // removes pivot rows) before warehouse stock is decremented below.
                    $batchService = app(BatchService::class);
                    if ($batchService->isSupported() && $current_Purchase->statut == 'received') {
                        $batchService->reverseForPurchaseDetails($old_purchase_details);
                    }

                    foreach ($old_purchase_details as $key => $value) {

                        // check if detail has purchase_unit_id Or Null
                        if ($value['purchase_unit_id'] !== null) {
                            $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                        } else {
                            $product_unit_purchase_id = Product::with('unitPurchase')
                                ->where('id', $value['product_id'])
                                ->first();
                            $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                        }

                        if ($current_Purchase->statut == 'received') {

                            if ($value['product_variant_id'] !== null) {
                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $current_Purchase->warehouse_id)
                                    ->where('product_id', $value['product_id'])
                                    ->where('product_variant_id', $value['product_variant_id'])
                                    ->lockForUpdate()->first();

                                if ($unit && $product_warehouse) {
                                    if ($unit->operator == '/') {
                                        $product_warehouse->qte -= $value['quantity'] / $unit->operator_value;
                                    } else {
                                        $product_warehouse->qte -= $value['quantity'] * $unit->operator_value;
                                    }

                                    $product_warehouse->save();
                                }

                            } else {
                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $current_Purchase->warehouse_id)
                                    ->where('product_id', $value['product_id'])
                                    ->lockForUpdate()->first();

                                if ($unit && $product_warehouse) {
                                    if ($unit->operator == '/') {
                                        $product_warehouse->qte -= $value['quantity'] / $unit->operator_value;
                                    } else {
                                        $product_warehouse->qte -= $value['quantity'] * $unit->operator_value;
                                    }

                                    $product_warehouse->save();
                                }
                            }
                        }
                    }

                    $current_Purchase->details()->delete();
                    $current_Purchase->update([
                        'deleted_at' => Carbon::now(),
                    ]);

                    $Payment_purchase_data = PaymentPurchase::where('purchase_id', $purchase_id)->get();
                    foreach ($Payment_purchase_data as $Payment_purchase) {
                        // Wallet-funded rows go back to the supplier wallet (their account_id is null).
                        app(\App\Services\SupplierWallet\SupplierWalletService::class)->reversePaymentPurchase($Payment_purchase, ['user_id' => Auth::id()]);
                        Account::adjustBalance($Payment_purchase->account_id, $Payment_purchase->montant);

                        $Payment_purchase->delete();
                        // Soft delete fires no `updated` hook: post the Accounting V2 reversal explicitly.
                        event(new \App\Events\PaymentPurchaseDeleted($Payment_purchase));
                    }

                }
            }

        }, 10);

        return response()->json(['success' => true, 'message' => 'Purchase Deleted !!']);

    }

    // ---------------- Get Details Purchase -----------------\\

    public function show(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $purchase = Purchase::with('details.product.unitPurchase', 'details.batches.batch')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        // Document currency: stored amounts are base currency, display = stored * rate.
        $docCurrency = helpers::Get_Document_Currency($purchase);

        $details = [];

        // Check If User Has Permission view All Records
        // Warehouse half of the same rule: record_view says whose documents,
        // the assigned warehouses say which warehouses they may come from.
        $this->abortIfDocumentWarehouseDenied($purchase);

        if (! $view_records) {
            // Check If User->id === purchase->id
            $this->authorizeForUser($request->user('api'), 'check_record', $purchase);
        }

        $purchase_data['id'] = $purchase->id;
        $purchase_data['Ref'] = $purchase->Ref;
        $purchase_data['order_reference'] = $purchase->order_reference;
        // Multi-Currency: amounts in this payload are converted into the
        // document currency — the page must render them with THIS symbol.
        $purchase_data['currency_symbol'] = $docCurrency['symbol'];
        $purchase_data['currency_code'] = $docCurrency['code'];
        $purchase_data['currency_id'] = $docCurrency['id'];
        $purchase_data['currency_rate'] = $docCurrency['rate'];
        $purchase_data['date'] = $purchase->date.' '.$purchase->time;
        $purchase_data['statut'] = $purchase->statut;
        $purchase_data['note'] = $purchase->notes;
        $purchase_data['discount'] = $purchase->discount * $docCurrency['rate'];
        $purchase_data['shipping'] = $purchase->shipping * $docCurrency['rate'];
        $purchase_data['tax_rate'] = $purchase->tax_rate;
        $purchase_data['TaxNet'] = $purchase->TaxNet * $docCurrency['rate'];
        $purchase_data['supplier_name'] = $purchase['provider']->name;
        $purchase_data['supplier_email'] = $purchase['provider']->email;
        $purchase_data['supplier_phone'] = $purchase['provider']->phone;
        $purchase_data['supplier_adr'] = $purchase['provider']->adresse;
        $purchase_data['supplier_tax'] = $purchase['provider']->tax_number;
        $purchase_data['warehouse'] = $purchase['warehouse']->name;
        $purchase_data['GrandTotal'] = number_format($purchase->GrandTotal * $docCurrency['rate'], helpers::price_decimals(), '.', '');
        $purchase_data['paid_amount'] = number_format($purchase->paid_amount * $docCurrency['rate'], helpers::price_decimals(), '.', '');
        $purchase_data['due'] = number_format($purchase_data['GrandTotal'] - $purchase_data['paid_amount'], helpers::price_decimals(), '.', '');
        $purchase_data['payment_status'] = $purchase->payment_statut;
        // Extra charges are fixed amounts stored in base currency: convert for display.
        $purchase_data['extra_charges'] = PurchaseExtraCharge::where('purchase_id', $id)
            ->whereNull('deleted_at')->orderBy('id')->get(['id', 'name', 'amount'])
            ->map(function ($charge) use ($docCurrency) {
                $charge->amount = $charge->amount * $docCurrency['rate'];

                return $charge;
            });
        $purchase_data['custom_fields'] = PurchaseCustomField::where('purchase_id', $id)
            ->whereNull('deleted_at')->orderBy('id')->get(['id', 'name', 'value']);

        if (PurchaseReturn::where('purchase_id', $id)->where('deleted_at', '=', null)->exists()) {
            $PurchaseReturn = PurchaseReturn::where('purchase_id', $id)->where('deleted_at', '=', null)->first();
            $purchase_data['purchasereturn_id'] = $PurchaseReturn->id;
            $purchase_data['purchase_has_return'] = 'yes';
        } else {
            $purchase_data['purchase_has_return'] = 'no';
        }

        foreach ($purchase['details'] as $detail) {

            // -------check if detail has purchase_unit_id Or Null
            if ($detail->purchase_unit_id !== null) {
                $unit = Unit::where('id', $detail->purchase_unit_id)->first();
            } else {
                $product_unit_purchase_id = Product::with('unitPurchase')
                    ->where('id', $detail->product_id)
                    ->first();
                $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
            }

            if ($detail->product_variant_id) {

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];

            } else {
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
            }

            // unit cost in document currency; the whole line stays consistent from here on
            $cost = $detail->cost * $docCurrency['rate'];

            $data['quantity'] = $detail->quantity;
            $data['total'] = $detail->total * $docCurrency['rate'];
            $data['cost'] = $cost;
            $data['unit_purchase'] = $unit->ShortName;

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = $detail->discount * $docCurrency['rate'];
            } else {
                $data['DiscountNet'] = $cost * $detail->discount / 100;
            }

            $tax_cost = $detail->TaxNet * (($cost - $data['DiscountNet']) / 100);
            $data['Unit_cost'] = $cost;
            // fixed discounts are amounts (convert); percentage discounts stay as-is
            $data['discount'] = $detail->discount_method == '2' ? $detail->discount * $docCurrency['rate'] : $detail->discount;

            if ($detail->tax_method == '1') {

                $data['Net_cost'] = $cost - $data['DiscountNet'];
                $data['taxe'] = $tax_cost;
            } else {
                $data['Net_cost'] = ($cost - $data['DiscountNet'] - $tax_cost);
                $data['taxe'] = $cost - $data['Net_cost'] - $data['DiscountNet'];
            }

            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;

            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $data['batches'] = $detail->batches->map(function ($b) use ($docCurrency) {
                $expiry = optional($b->batch)->expiry_date;
                $mfg = optional($b->batch)->mfg_date;

                return [
                    'batch_no'    => optional($b->batch)->batch_no,
                    'expiry_date' => $expiry ? (is_string($expiry) ? $expiry : $expiry->toDateString()) : null,
                    'mfg_date'    => $mfg ? (is_string($mfg) ? $mfg : $mfg->toDateString()) : null,
                    'qty'         => $b->qty,
                    'unit_cost'   => $b->unit_cost !== null ? $b->unit_cost * $docCurrency['rate'] : null,
                    'barcode'     => optional($b->batch)->barcode,
                    'notes'       => optional($b->batch)->notes,
                ];
            })->toArray();

            $details[] = $data;
        }

        $company = Setting::where('deleted_at', '=', null)->first();

        return response()->json([
            'details' => $details,
            'purchase' => $purchase_data,
            'company' => $company,
            // Branch letterhead: the issuing warehouse, falling back to company settings.
            'issuer' => \App\Services\InvoiceIssuerService::resolve($purchase->warehouse_id, $company),
        ]);

    }

    // --------------- Get Payments of Purchase ----------------\\

    public function Get_Payments(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'view', PaymentPurchase::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $purchase = Purchase::findOrFail($id);

        // Check If User Has Permission view All Records
        // Warehouse half of the same rule: record_view says whose documents,
        // the assigned warehouses say which warehouses they may come from.
        $this->abortIfDocumentWarehouseDenied($purchase);

        if (! $view_records) {
            // Check If User->id === purchase->id
            $this->authorizeForUser($request->user('api'), 'check_record', $purchase);
        }

        $payments = PaymentPurchase::with('purchase', 'payment_method')
            ->where('purchase_id', $id)
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })->orderBy('id', 'DESC')->get();

        $due = $purchase->GrandTotal - $purchase->paid_amount;

        return response()->json(['payments' => $payments, 'due' => $due]);
    }

    // --------------- Reference Number of Purchase ----------------\\

    public function getNumberOrder()
    {
        // Get prefix from settings, fallback to 'PR' if not set
        $setting = \App\Models\Setting::where('deleted_at', '=', null)->first();
        $prefix = !empty($setting->purchase_prefix) ? $setting->purchase_prefix : 'PR';

        // Get the last purchase with a reference that starts with the prefix
        $last = DB::table('purchases')
            ->where('Ref', 'like', $prefix.'_%')
            ->latest('id')
            ->first();

        if ($last) {
            $item = $last->Ref;
            $nwMsg = explode('_', $item);
            
            // Ensure valid structure before processing
            if (isset($nwMsg[1]) && is_numeric($nwMsg[1])) {
                $inMsg = $nwMsg[1] + 1;
                $code = $nwMsg[0].'_'.str_pad($inMsg, 4, '0', STR_PAD_LEFT);
            } else {
                $code = $prefix.'_0001'; // Fallback if reference is corrupted
            }
        } else {
            $code = $prefix.'_0001';
        }

        return $code;

    }

    // -------------- purchase PDF -----------\\

    public function Purchase_pdf(Request $request, $id)
    {
        $details = [];
        $helpers = new helpers;
        $Purchase_data = Purchase::with('details.product.unitPurchase')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $this->authorizeDocumentView($request, $Purchase_data);

        // Document currency: stored amounts are base currency, display = stored * rate.
        $docCurrency = helpers::Get_Document_Currency($Purchase_data);

        $purchaseBatchesByDetail = app(BatchService::class)
            ->batchesForPurchaseDetails($Purchase_data['details']);

        $purchase['supplier_name'] = $Purchase_data['provider']->name;
        $purchase['supplier_phone'] = $Purchase_data['provider']->phone;
        $purchase['supplier_adr'] = $Purchase_data['provider']->adresse;
        $purchase['supplier_email'] = $Purchase_data['provider']->email;
        $purchase['supplier_tax'] = $Purchase_data['provider']->tax_number;
        $purchase['TaxNet'] = number_format($Purchase_data->TaxNet * $docCurrency['rate'], helpers::price_decimals(), '.', '');
        $purchase['discount'] = number_format($Purchase_data->discount * $docCurrency['rate'], helpers::price_decimals(), '.', '');
        $purchase['shipping'] = number_format($Purchase_data->shipping * $docCurrency['rate'], helpers::price_decimals(), '.', '');
        $purchase['statut'] = $Purchase_data->statut;
        $purchase['Ref'] = $Purchase_data->Ref;
        $purchase['order_reference'] = $Purchase_data->order_reference;
        $purchase['date'] = $Purchase_data->date.' '.$Purchase_data->time;
        $purchase['GrandTotal'] = number_format($Purchase_data->GrandTotal * $docCurrency['rate'], helpers::price_decimals(), '.', '');
        $purchase['paid_amount'] = number_format($Purchase_data->paid_amount * $docCurrency['rate'], helpers::price_decimals(), '.', '');
        $purchase['due'] = number_format($purchase['GrandTotal'] - $purchase['paid_amount'], helpers::price_decimals(), '.', '');
        $purchase['payment_status'] = $Purchase_data->payment_statut;
        // Extra charges are fixed amounts stored in base currency: convert for display.
        $purchase['extra_charges'] = PurchaseExtraCharge::where('purchase_id', $id)
            ->whereNull('deleted_at')->orderBy('id')->get(['id', 'name', 'amount'])
            ->map(function ($charge) use ($docCurrency) {
                $charge->amount = $charge->amount * $docCurrency['rate'];

                return $charge;
            });
        $purchase['custom_fields'] = PurchaseCustomField::where('purchase_id', $id)
            ->whereNull('deleted_at')->orderBy('id')->get(['id', 'name', 'value']);

        $detail_id = 0;
        foreach ($Purchase_data['details'] as $detail) {

            // -------check if detail has purchase_unit_id Or Null
            if ($detail->purchase_unit_id !== null) {
                $unit = Unit::where('id', $detail->purchase_unit_id)->first();
            } else {
                $product_unit_purchase_id = Product::with('unitPurchase')
                    ->where('id', $detail->product_id)
                    ->first();
                $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
            }

            if ($detail->product_variant_id) {

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
            } else {
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
            }

            $data['detail_id'] = $detail_id += 1;
            $data['quantity'] = number_format($detail->quantity, helpers::price_decimals(), '.', '');
            $data['total'] = number_format($detail->total * $docCurrency['rate'], helpers::price_decimals(), '.', '');
            $data['unit_purchase'] = $unit->ShortName;
            // unit cost in document currency; the whole line stays consistent from here on
            $cost = $detail->cost * $docCurrency['rate'];
            $data['cost'] = number_format($cost, helpers::price_decimals(), '.', '');

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = number_format($detail->discount * $docCurrency['rate'], helpers::price_decimals(), '.', '');
            } else {
                $data['DiscountNet'] = number_format($cost * $detail->discount / 100, helpers::price_decimals(), '.', '');
            }

            $tax_cost = $detail->TaxNet * (($cost - $data['DiscountNet']) / 100);
            $data['Unit_cost'] = number_format($cost, helpers::price_decimals(), '.', '');
            // fixed discounts are amounts (convert); percentage discounts stay as-is
            $data['discount'] = number_format($detail->discount_method == '2' ? $detail->discount * $docCurrency['rate'] : $detail->discount, helpers::price_decimals(), '.', '');

            if ($detail->tax_method == '1') {

                $data['Net_cost'] = $cost - $data['DiscountNet'];
                $data['taxe'] = number_format($tax_cost, helpers::price_decimals(), '.', '');
            } else {
                $data['Net_cost'] = ($cost - $data['DiscountNet'] - $tax_cost);
                $data['taxe'] = number_format($cost - $data['Net_cost'] - $data['DiscountNet'], helpers::price_decimals(), '.', '');
            }

            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;
            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $data['batches'] = array_map(function ($b) use ($docCurrency) {
                if (isset($b['unit_cost'])) {
                    $b['unit_cost'] *= $docCurrency['rate'];
                }

                return $b;
            }, $purchaseBatchesByDetail[(int) $detail->id] ?? []);

            $details[] = $data;
        }

        $settings = Setting::where('deleted_at', '=', null)->first();
        $symbol = $docCurrency['code'];

        $Html = view('pdf.purchase_pdf', [
            'symbol' => $symbol,
            'setting' => $settings,
            // Branch letterhead: the issuing warehouse, falling back to company settings.
            'issuer' => \App\Services\InvoiceIssuerService::resolve($Purchase_data->warehouse_id, $settings),
            'purchase' => $purchase,
            'details' => $details,
        ])->render();

        $arabic = new Arabic;
        $p = $arabic->arIdentify($Html);

        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        $pdf = PDF::loadHTML($Html);

        return $pdf->download('purchase.pdf');

    }

    /**
     * Returns the purchase invoice HTML (rendered from pdf.purchase_pdf template) instead of a PDF.
     * Uses the same template (`pdf.purchase_pdf`) but returns raw HTML instead of a PDF.
     * This is used by the print functionality, which opens a popup, injects the HTML, and
     * calls window.print().
     */
    public function Purchase_PDF_Inline(Request $request, $id)
    {
        $details = [];
        $helpers = new helpers;
        $Purchase_data = Purchase::with('details.product.unitPurchase')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $this->authorizeDocumentView($request, $Purchase_data);

        // Document currency: stored amounts are base currency, display = stored * rate.
        $docCurrency = helpers::Get_Document_Currency($Purchase_data);

        $purchaseBatchesByDetail = app(BatchService::class)
            ->batchesForPurchaseDetails($Purchase_data['details']);

        $purchase['supplier_name'] = $Purchase_data['provider']->name;
        $purchase['supplier_phone'] = $Purchase_data['provider']->phone;
        $purchase['supplier_adr'] = $Purchase_data['provider']->adresse;
        $purchase['supplier_email'] = $Purchase_data['provider']->email;
        $purchase['supplier_tax'] = $Purchase_data['provider']->tax_number;
        $purchase['TaxNet'] = number_format($Purchase_data->TaxNet * $docCurrency['rate'], helpers::price_decimals(), '.', '');
        $purchase['discount'] = number_format($Purchase_data->discount * $docCurrency['rate'], helpers::price_decimals(), '.', '');
        $purchase['shipping'] = number_format($Purchase_data->shipping * $docCurrency['rate'], helpers::price_decimals(), '.', '');
        $purchase['statut'] = $Purchase_data->statut;
        $purchase['Ref'] = $Purchase_data->Ref;
        $purchase['order_reference'] = $Purchase_data->order_reference;
        $purchase['date'] = $Purchase_data->date.' '.$Purchase_data->time;
        $purchase['GrandTotal'] = number_format($Purchase_data->GrandTotal * $docCurrency['rate'], helpers::price_decimals(), '.', '');
        $purchase['paid_amount'] = number_format($Purchase_data->paid_amount * $docCurrency['rate'], helpers::price_decimals(), '.', '');
        $purchase['due'] = number_format($purchase['GrandTotal'] - $purchase['paid_amount'], helpers::price_decimals(), '.', '');
        $purchase['payment_status'] = $Purchase_data->payment_statut;
        // Extra charges are fixed amounts stored in base currency: convert for display.
        $purchase['extra_charges'] = PurchaseExtraCharge::where('purchase_id', $id)
            ->whereNull('deleted_at')->orderBy('id')->get(['id', 'name', 'amount'])
            ->map(function ($charge) use ($docCurrency) {
                $charge->amount = $charge->amount * $docCurrency['rate'];

                return $charge;
            });
        $purchase['custom_fields'] = PurchaseCustomField::where('purchase_id', $id)
            ->whereNull('deleted_at')->orderBy('id')->get(['id', 'name', 'value']);

        $detail_id = 0;
        foreach ($Purchase_data['details'] as $detail) {

            // -------check if detail has purchase_unit_id Or Null
            if ($detail->purchase_unit_id !== null) {
                $unit = Unit::where('id', $detail->purchase_unit_id)->first();
            } else {
                $product_unit_purchase_id = Product::with('unitPurchase')
                    ->where('id', $detail->product_id)
                    ->first();
                $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
            }

            if ($detail->product_variant_id) {

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
            } else {
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
            }

            $data['detail_id'] = $detail_id += 1;
            $data['quantity'] = number_format($detail->quantity, helpers::price_decimals(), '.', '');
            $data['total'] = number_format($detail->total * $docCurrency['rate'], helpers::price_decimals(), '.', '');
            $data['unit_purchase'] = $unit ? $unit->ShortName : '';
            // unit cost in document currency; the whole line stays consistent from here on
            $cost = $detail->cost * $docCurrency['rate'];
            $data['cost'] = number_format($cost, helpers::price_decimals(), '.', '');

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = number_format($detail->discount * $docCurrency['rate'], helpers::price_decimals(), '.', '');
            } else {
                $data['DiscountNet'] = number_format($cost * $detail->discount / 100, helpers::price_decimals(), '.', '');
            }

            $tax_cost = $detail->TaxNet * (($cost - $data['DiscountNet']) / 100);
            $data['Unit_cost'] = number_format($cost, helpers::price_decimals(), '.', '');
            // fixed discounts are amounts (convert); percentage discounts stay as-is
            $data['discount'] = number_format($detail->discount_method == '2' ? $detail->discount * $docCurrency['rate'] : $detail->discount, helpers::price_decimals(), '.', '');

            if ($detail->tax_method == '1') {

                $data['Net_cost'] = $cost - $data['DiscountNet'];
                $data['taxe'] = number_format($tax_cost, helpers::price_decimals(), '.', '');
            } else {
                $data['Net_cost'] = ($cost - $data['DiscountNet'] - $tax_cost);
                $data['taxe'] = number_format($cost - $data['Net_cost'] - $data['DiscountNet'], helpers::price_decimals(), '.', '');
            }

            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;
            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $data['batches'] = array_map(function ($b) use ($docCurrency) {
                if (isset($b['unit_cost'])) {
                    $b['unit_cost'] *= $docCurrency['rate'];
                }

                return $b;
            }, $purchaseBatchesByDetail[(int) $detail->id] ?? []);

            $details[] = $data;
        }

        $settings = Setting::where('deleted_at', '=', null)->first();
        $symbol = $docCurrency['code'];

        $Html = view('pdf.purchase_pdf', [
            'symbol' => $symbol,
            'setting' => $settings,
            // Branch letterhead: the issuing warehouse, falling back to company settings.
            'issuer' => \App\Services\InvoiceIssuerService::resolve($Purchase_data->warehouse_id, $settings),
            'purchase' => $purchase,
            'details' => $details,
        ])->render();

        // No Arabic glyph preprocessing here: browsers shape and order Arabic text
        // natively, and pre-reshaped glyphs get double-reversed by the browser's
        // bidi algorithm. utf8Glyphs() is only needed on the DomPDF download path.

        // When rendering as HTML in the browser, filesystem paths like public_path('images/...')
        // do not work as <img src>. Convert any ".../public/images/<file>" path (Windows or Unix)
        // into a proper web URL so logos/images display.
        try {
            $webImagesPath = rtrim(url('images'), '/').'/';
            $Html = preg_replace_callback(
                '~(?:[A-Za-z]:)?[\/\\\\][^"\']*?[\/\\\\]public[\/\\\\]images[\/\\\\]([^"\'>]+)~',
                function ($m) use ($webImagesPath) {
                    $file = ltrim($m[1], '/\\');
                    return $webImagesPath.$file;
                },
                $Html
            );
        } catch (\Throwable $e) {
            // If anything goes wrong, fall back to the original HTML.
        }

        // Return raw HTML so the print popup can inject it and call window.print().
        return response($Html);
    }

    // ---------------- Show Form Create Purchase ---------------\\

    /** The customer's / supplier's own order number (e.g. a CS-Cart order), trimmed; blank = none. */
    private function orderReference(Request $request): ?string
    {
        $ref = trim((string) $request->input('order_reference', ''));

        return $ref === '' ? null : mb_substr($ref, 0, 100);
    }

    public function create(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'create', Purchase::class);

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        $suppliers = Provider::where('deleted_at', '=', null)->get(['id', 'name']);

        $setting = Setting::where('deleted_at', '=', null)->first();

        return response()->json([
            'warehouses' => $warehouses,
            'suppliers' => $suppliers,
            'purchase_extra_charges_enabled' => (bool) ($setting->purchase_extra_charges_enabled ?? false),
            'purchase_custom_fields_enabled' => (bool) ($setting->purchase_custom_fields_enabled ?? false),
            'purchase_update_selling_price' => $this->sellingPriceOnPurchaseEnabled(),
        ]);
    }

    // -------------Show Form Edit Purchase-----------\\

    public function edit(Request $request, $id)
    {
        if (PurchaseReturn::where('purchase_id', $id)->where('deleted_at', '=', null)->exists()) {
            return response()->json(['success' => false, 'Return exist for the Transaction' => false], 403);
        } else {

            $this->authorizeForUser($request->user('api'), 'update', Purchase::class);
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $Purchase_data = Purchase::with('details.product.unitPurchase')
                ->where('deleted_at', '=', null)
                ->findOrFail($id);

            /**
             * Warehouses restriction
             * Allow if:
             * - user has access to all warehouses (is_all_warehouses = 1)
             * - OR sale warehouse_id is in user's assigned warehouses
            */
            $user_auth = auth()->user();

            if (! $user_auth->is_all_warehouses) {
                $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)
                    ->pluck('warehouse_id')
                    ->toArray();

                if (empty($Purchase_data->warehouse_id) || ! in_array($Purchase_data->warehouse_id, $warehouses_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not allowed to access this sale (warehouse restriction).',
                    ], 403);
                }
            }

            $details = [];
            // Check If User Has Permission view All Records
            // Warehouse half of the same rule: record_view says whose documents,
            // the assigned warehouses say which warehouses they may come from.
            $this->abortIfDocumentWarehouseDenied($Purchase_data);

            if (! $view_records) {
                // Check If User->id === Purchase->id
                $this->authorizeForUser($request->user('api'), 'check_record', $Purchase_data);
            }

            if ($Purchase_data->provider_id) {
                if (Provider::where('id', $Purchase_data->provider_id)->where('deleted_at', '=', null)->first()) {
                    $purchase['supplier_id'] = $Purchase_data->provider_id;
                } else {
                    $purchase['supplier_id'] = '';
                }
            } else {
                $purchase['supplier_id'] = '';
            }

            if ($Purchase_data->warehouse_id) {
                if (Warehouse::where('id', $Purchase_data->warehouse_id)->where('deleted_at', '=', null)->first()) {
                    $purchase['warehouse_id'] = $Purchase_data->warehouse_id;
                } else {
                    $purchase['warehouse_id'] = '';
                }
            } else {
                $purchase['warehouse_id'] = '';
            }

            $purchase['date'] = $Purchase_data->date;
            $purchase['tax_rate'] = $Purchase_data->tax_rate;
            $purchase['TaxNet'] = $Purchase_data->TaxNet;
            $purchase['discount'] = $Purchase_data->discount;
            $purchase['shipping'] = $Purchase_data->shipping;
            $purchase['statut'] = $Purchase_data->statut;
            $purchase['notes'] = $Purchase_data->notes;
            $purchase['order_reference'] = $Purchase_data->order_reference;
            // Multi-Currency snapshot (null = base currency)
            $purchase['currency_id'] = $Purchase_data->currency_id;
            $purchase['exchange_rate'] = $Purchase_data->exchange_rate;
            $purchase['extra_charges'] = PurchaseExtraCharge::where('purchase_id', $id)
                ->whereNull('deleted_at')->orderBy('id')->get(['id', 'name', 'amount']);
            $purchase['custom_fields'] = PurchaseCustomField::where('purchase_id', $id)
                ->whereNull('deleted_at')->orderBy('id')->get(['id', 'name', 'value']);

            // Pharmacy: prefetch batch links keyed by purchase_detail_id.
            $batchService = app(BatchService::class);
            $batchesByDetail = $batchService->batchesForPurchaseDetails($Purchase_data['details']);

            $detail_id = 0;
            foreach ($Purchase_data['details'] as $detail) {

                // -------check if detail has purchase_unit_id Or Null
                if ($detail->purchase_unit_id !== null) {
                    $unit = Unit::where('id', $detail->purchase_unit_id)->first();
                    $data['no_unit'] = 1;
                } else {
                    $product_unit_purchase_id = Product::with('unitPurchase')
                        ->where('id', $detail->product_id)
                        ->first();
                    $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                    $data['no_unit'] = 0;
                }

                if ($detail->product_variant_id) {
                    $item_product = product_warehouse::where('product_id', $detail->product_id)
                        ->where('deleted_at', '=', null)
                        ->where('product_variant_id', $detail->product_variant_id)
                        ->where('warehouse_id', $Purchase_data->warehouse_id)
                        ->first();

                    $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                        ->where('id', $detail->product_variant_id)->first();

                    $item_product ? $data['del'] = 0 : $data['del'] = 1;

                    $data['code'] = $productsVariants->code;
                    $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
                    $data['product_variant_id'] = $detail->product_variant_id;

                    if ($unit && $unit->operator == '/') {
                        $data['stock'] = $item_product ? $item_product->qte * $unit->operator_value : 0;
                    } elseif ($unit && $unit->operator == '*') {
                        $data['stock'] = $item_product ? $item_product->qte / $unit->operator_value : 0;
                    } else {
                        $data['stock'] = 0;
                    }

                } else {
                    $item_product = product_warehouse::where('product_id', $detail->product_id)
                        ->where('deleted_at', '=', null)->where('product_variant_id', '=', null)
                        ->where('warehouse_id', $Purchase_data->warehouse_id)->first();

                    $item_product ? $data['del'] = 0 : $data['del'] = 1;
                    $data['product_variant_id'] = null;

                    $data['code'] = $detail['product']['code'];
                    $data['name'] = $detail['product']['name'];

                    if ($unit && $unit->operator == '/') {
                        $data['stock'] = $item_product ? $item_product->qte * $unit->operator_value : 0;
                    } elseif ($unit && $unit->operator == '*') {
                        $data['stock'] = $item_product ? $item_product->qte / $unit->operator_value : 0;
                    } else {
                        $data['stock'] = 0;
                    }

                }

                $data['id'] = $detail->id;
                $data['detail_id'] = $detail_id += 1;
                $data['quantity'] = $detail->quantity;
                $data['product_id'] = $detail->product_id;
                $data['unitPurchase'] = $unit->ShortName;
                $data['purchase_unit_id'] = $unit->id;

                $data['is_imei'] = $detail['product']['is_imei'];
                $data['imei_number'] = $detail->imei_number;

                if ($detail->discount_method == '2') {
                    $data['DiscountNet'] = $detail->discount;
                } else {
                    $data['DiscountNet'] = $detail->cost * $detail->discount / 100;
                }

                $tax_cost = $detail->TaxNet * (($detail->cost - $data['DiscountNet']) / 100);
                $data['Unit_cost'] = $detail->cost;
                $data['tax_percent'] = $detail->TaxNet;
                $data['tax_method'] = $detail->tax_method;
                $data['discount'] = $detail->discount;
                $data['discount_Method'] = $detail->discount_method;

                if ($detail->tax_method == '1') {
                    $data['Net_cost'] = $detail->cost - $data['DiscountNet'];
                    $data['taxe'] = $tax_cost;
                    $data['subtotal'] = ($data['Net_cost'] * $data['quantity']) + ($tax_cost * $data['quantity']);
                } else {
                    $data['Net_cost'] = ($detail->cost - $data['DiscountNet'] - $tax_cost);
                    $data['taxe'] = $detail->cost - $data['Net_cost'] - $data['DiscountNet'];
                    $data['subtotal'] = ($data['Net_cost'] * $data['quantity']) + ($tax_cost * $data['quantity']);
                }

                // Pharmacy: attach is_batch_tracked + existing batches for this line.
                $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
                $data['batches'] = $batchesByDetail[(int) $detail->id] ?? [];

                // Selling price typed on this line when it was saved (NULL = none)
                // and the product's price as it stands now, for the form's hint.
                $data['price'] = isset($detail->price) ? (float) $detail->price : null;
                $data['current_price'] = (float) ($detail->product_variant_id && isset($productsVariants)
                    ? ($productsVariants->price ?? 0)
                    : ($detail['product']['price'] ?? 0));

                $details[] = $data;
            }

            // get warehouses assigned to user
            $user_auth = auth()->user();
            if ($user_auth->is_all_warehouses) {
                $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
            } else {
                $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
                $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
            }

            $suppliers = Provider::where('deleted_at', '=', null)->get(['id', 'name']);

            $setting = Setting::where('deleted_at', '=', null)->first();

            return response()->json([
                'details' => $details,
                'purchase' => $purchase,
                'suppliers' => $suppliers,
                'warehouses' => $warehouses,
                'purchase_extra_charges_enabled' => (bool) ($setting->purchase_extra_charges_enabled ?? false),
                'purchase_custom_fields_enabled' => (bool) ($setting->purchase_custom_fields_enabled ?? false),
                'purchase_update_selling_price' => $this->sellingPriceOnPurchaseEnabled(),
            ]);
        }
    }

    // ------------------- get_Products_by_purchase -----------------\\

    public function get_Products_by_purchase(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'create', PurchaseReturn::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $Purchase_data = Purchase::with('details.product.unitPurchase')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $details = [];

        // Check If User Has Permission view All Records
        // Warehouse half of the same rule: record_view says whose documents,
        // the assigned warehouses say which warehouses they may come from.
        $this->abortIfDocumentWarehouseDenied($Purchase_data);

        if (! $view_records) {
            // Check If User->id === Purchase->id
            $this->authorizeForUser($request->user('api'), 'check_record', $Purchase_data);
        }

        $Return_detail['supplier_id'] = $Purchase_data->provider_id;
        $Return_detail['warehouse_id'] = $Purchase_data->warehouse_id;
        $Return_detail['purchase_id'] = $Purchase_data->id;
        $Return_detail['tax_rate'] = 0;
        $Return_detail['TaxNet'] = 0;
        $Return_detail['discount'] = 0;
        $Return_detail['shipping'] = 0;
        $Return_detail['statut'] = 'completed';
        $Return_detail['notes'] = '';

        $detail_id = 0;
        foreach ($Purchase_data['details'] as $detail) {

            // -------check if detail has purchase_unit_id Or Null
            if ($detail->purchase_unit_id !== null) {
                $unit = Unit::where('id', $detail->purchase_unit_id)->first();
                $data['no_unit'] = 1;
            } else {
                $product_unit_purchase_id = Product::with('unitPurchase')
                    ->where('id', $detail->product_id)
                    ->first();
                $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                $data['no_unit'] = 0;
            }

            if ($detail->product_variant_id) {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)
                    ->where('product_variant_id', $detail->product_variant_id)
                    ->where('warehouse_id', $Purchase_data->warehouse_id)
                    ->first();

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $item_product ? $data['del'] = 0 : $data['del'] = 1;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
                $data['code'] = $productsVariants->code;

                $data['product_variant_id'] = $detail->product_variant_id;

                if ($unit && $unit->operator == '/') {
                    $data['stock'] = $item_product ? $item_product->qte * $unit->operator_value : 0;
                } elseif ($unit && $unit->operator == '*') {
                    $data['stock'] = $item_product ? $item_product->qte / $unit->operator_value : 0;
                } else {
                    $data['stock'] = 0;
                }

            } else {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)->where('product_variant_id', '=', null)
                    ->where('warehouse_id', $Purchase_data->warehouse_id)->first();

                $item_product ? $data['del'] = 0 : $data['del'] = 1;
                $data['product_variant_id'] = null;
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];

                if ($unit && $unit->operator == '/') {
                    $data['stock'] = $item_product ? $item_product->qte * $unit->operator_value : 0;
                } elseif ($unit && $unit->operator == '*') {
                    $data['stock'] = $item_product ? $item_product->qte / $unit->operator_value : 0;
                } else {
                    $data['stock'] = 0;
                }

            }

            $data['id'] = $detail->id;
            $data['detail_id'] = $detail_id += 1;
            $data['quantity'] = $detail->quantity;
            $data['purchase_quantity'] = $detail->quantity;
            $data['product_id'] = $detail->product_id;
            $data['unitPurchase'] = $unit->ShortName;
            $data['purchase_unit_id'] = $unit->id;

            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = $detail->discount;
            } else {
                $data['DiscountNet'] = $detail->cost * $detail->discount / 100;
            }

            $tax_cost = $detail->TaxNet * (($detail->cost - $data['DiscountNet']) / 100);
            $data['Unit_cost'] = $detail->cost;
            $data['tax_percent'] = $detail->TaxNet;
            $data['tax_method'] = $detail->tax_method;
            $data['discount'] = $detail->discount;
            $data['discount_Method'] = $detail->discount_method;

            if ($detail->tax_method == '1') {
                $data['Net_cost'] = $detail->cost - $data['DiscountNet'];
                $data['taxe'] = $tax_cost;
                $data['subtotal'] = ($data['Net_cost'] * $data['quantity']) + ($tax_cost * $data['quantity']);
            } else {
                $data['Net_cost'] = ($detail->cost - $data['DiscountNet'] - $tax_cost);
                $data['taxe'] = $detail->cost - $data['Net_cost'] - $data['DiscountNet'];
                $data['subtotal'] = ($data['Net_cost'] * $data['quantity']) + ($tax_cost * $data['quantity']);
            }

            $details[] = $data;
        }

        return response()->json([
            'details' => $details,
            'purchase_return' => $Return_detail,
        ]);

    }

    // ------------------- Get barcode products for a Purchase -----------------\\

    public function get_barcode_products(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);

        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();

        $purchase = Purchase::with('details.product')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        // Check If User Has Permission view All Records
        // Warehouse half of the same rule: record_view says whose documents,
        // the assigned warehouses say which warehouses they may come from.
        $this->abortIfDocumentWarehouseDenied($purchase);

        if (! $view_records) {
            // Check If User->id === Purchase->id
            $this->authorizeForUser($request->user('api'), 'check_record', $purchase);
        }

        $products = [];
        $storePriceMap = \App\Services\WarehousePrices::map(
            $purchase->warehouse_id, $purchase->details->pluck('product_id')->all()
        );

        foreach ($purchase->details as $detail) {
            $product = $detail->product;

            if (! $product) {
                continue;
            }

            $item = [];
            $barcodeValue = null;
            $product_price = $product->price;

            if ($detail->product_variant_id) {
                $variant = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)
                    ->first();

                if ($variant) {
                    $item['code'] = $variant->code;
                    $item['name'] = '['.$variant->name.']'.$product->name;
                    $barcodeValue = $variant->code;
                    $product_price = $variant->price ?? $product->price;
                } else {
                    $item['code'] = $product->code;
                    $item['name'] = $product->name;
                    $barcodeValue = $product->code;
                }
            } else {
                $item['code'] = $product->code;
                $item['name'] = $product->name;
                $barcodeValue = $product->code;
            }

            // Different prices per store: labels for goods received into a
            // store carry that store's price.
            $product_price = \App\Services\WarehousePrices::pick(
                $storePriceMap[\App\Services\WarehousePrices::key($detail->product_id, $detail->product_variant_id)] ?? null,
                'price', $product_price
            );

            // Apply discount
            $price_discounted = $product_price;
            if ($product->discount != 0.0) {
                if ($product->discount_method == '1') {
                    $discount = $product_price * $product->discount / 100;
                } else {
                    $discount = $product->discount;
                }
                $price_discounted = $product_price - $discount;
            }

            // Apply tax
            if ($product->TaxNet != 0.0) {
                if ($product->tax_method == '1') {
                    $tax_price = $price_discounted * $product->TaxNet / 100;
                    $net_price = $price_discounted + $tax_price;
                } else {
                    $net_price = $price_discounted;
                }
            } else {
                $net_price = $price_discounted;
            }

            $item['barcode'] = $barcodeValue;
            $item['Type_barcode'] = $product->Type_barcode ?: 'CODE128';
            $item['Net_price'] = number_format($net_price, helpers::price_decimals(), '.', '');
            $item['qte'] = $detail->quantity;

            $products[] = $item;
        }

        return response()->json([
            'warehouse_id' => $purchase->warehouse_id,
            'products' => $products,
        ]);
    }

    // ------------- Send Email -----------\\

    public function Send_Email(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);

        // purchase
        $purchase = Purchase::with('provider')->where('deleted_at', '=', null)->findOrFail($request->id);

        $docCurrency = helpers::Get_Document_Currency($purchase);
        $currency = $docCurrency['symbol'];

        // settings
        $settings = Setting::where('deleted_at', '=', null)->first();

        // the custom msg of sale
        $emailMessage = EmailMessage::getForLocale('purchase');

        if ($emailMessage) {
            $message_body = $emailMessage->body;
            $message_subject = $emailMessage->subject;
        } else {
            $message_body = '';
            $message_subject = '';
        }
        // Tags
        $this->authorizeDocumentView($request, $purchase);
        $invoice_url = $purchase->shareUrl();

        $invoice_number = $purchase->Ref;

        $total_amount = helpers::with_currency($currency, helpers::format_price($purchase->GrandTotal * $docCurrency['rate'], null, 'comma_dot'));
        $paid_amount = helpers::with_currency($currency, helpers::format_price($purchase->paid_amount * $docCurrency['rate'], null, 'comma_dot'));
        $due_amount = helpers::with_currency($currency, helpers::format_price(($purchase->GrandTotal - $purchase->paid_amount) * $docCurrency['rate'], null, 'comma_dot'));

        $contact_name = $purchase['provider']->name;
        $business_name = $settings->CompanyName;

        // receiver email
        $receiver_email = $purchase['provider']->email;

        // replace the text with tags
        $message_body = str_replace('{contact_name}', e((string) $contact_name), $message_body);
        $message_body = str_replace('{business_name}', e((string) $business_name), $message_body);
        $message_body = str_replace('{invoice_url}', $invoice_url, $message_body);
        $message_body = str_replace('{invoice_number}', $invoice_number, $message_body);

        $message_body = str_replace('{total_amount}', $total_amount, $message_body);
        $message_body = str_replace('{paid_amount}', $paid_amount, $message_body);
        $message_body = str_replace('{due_amount}', $due_amount, $message_body);

        $email['subject'] = $message_subject;
        $email['body'] = $message_body;
        $email['company_name'] = $business_name;

        $this->Set_config_mail();
        Mail::to($receiver_email)->send(new CustomEmail($email));

        return response()->json(['message' => 'Email sent successfully'], 200);
        // return $mail;
    }

    // -------------------Sms Notifications -----------------\\

    public function Send_SMS(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);

        // purchase
        $purchase = Purchase::with('provider')->where('deleted_at', '=', null)->findOrFail($request->id);

        $docCurrency = helpers::Get_Document_Currency($purchase);
        $currency = $docCurrency['symbol'];

        // settings
        $settings = Setting::where('deleted_at', '=', null)->first();

        $default_sms_gateway = sms_gateway::where('id', $settings->sms_gateway)
            ->where('deleted_at', '=', null)->first();

        // the custom msg of purchase
        $smsMessage = SMSMessage::getForLocale('purchase');

        if ($smsMessage) {
            $message_text = $smsMessage->text;
        } else {
            $message_text = '';
        }

        // Tags
        $this->authorizeDocumentView($request, $purchase);
        $invoice_url = $purchase->shareUrl();
        $invoice_number = $purchase->Ref;

        $total_amount = helpers::with_currency($currency, helpers::format_price($purchase->GrandTotal * $docCurrency['rate'], null, 'comma_dot'));
        $paid_amount = helpers::with_currency($currency, helpers::format_price($purchase->paid_amount * $docCurrency['rate'], null, 'comma_dot'));
        $due_amount = helpers::with_currency($currency, helpers::format_price(($purchase->GrandTotal - $purchase->paid_amount) * $docCurrency['rate'], null, 'comma_dot'));

        $contact_name = $purchase['provider']->name;
        $business_name = $settings->CompanyName;

        // receiver Number
        $receiverNumber = $purchase['provider']->phone;

        // replace the text with tags
        $message_text = str_replace('{contact_name}', $contact_name, $message_text);
        $message_text = str_replace('{business_name}', $business_name, $message_text);
        $message_text = str_replace('{invoice_url}', $invoice_url, $message_text);
        $message_text = str_replace('{invoice_number}', $invoice_number, $message_text);

        $message_text = str_replace('{total_amount}', $total_amount, $message_text);
        $message_text = str_replace('{paid_amount}', $paid_amount, $message_text);
        $message_text = str_replace('{due_amount}', $due_amount, $message_text);

        if (! $default_sms_gateway) {
            return response()->json(['status' => 'error', 'message' => 'SMS gateway is not configured'], 500);
        }

        // credentials are resolved from the tenant's own sms_settings table
        try {
            app(\App\Services\SmsOtpSender::class)->sendVia($default_sms_gateway->title, $receiverNumber, $message_text);
        } catch (\Throwable $e) {
            Log::error('SMS Error: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['success' => true]);

    }

    // purchase_send_whatsapp
    public function purchase_send_whatsapp(Request $request)
    {

        // purchase
        $purchase = Purchase::with('provider')->where('deleted_at', '=', null)->findOrFail($request->id);

        $docCurrency = helpers::Get_Document_Currency($purchase);
        $currency = $docCurrency['symbol'];

        // settings
        $settings = Setting::where('deleted_at', '=', null)->first();

        // the custom msg of purchase
        $smsMessage = SMSMessage::getForLocale('purchase');

        if ($smsMessage) {
            $message_text = $smsMessage->text;
        } else {
            $message_text = '';
        }

        // Tags
        $this->authorizeDocumentView($request, $purchase);
        $invoice_url = $purchase->shareUrl();
        $invoice_number = $purchase->Ref;

        $total_amount = helpers::with_currency($currency, helpers::format_price($purchase->GrandTotal * $docCurrency['rate'], null, 'comma_dot'));
        $paid_amount = helpers::with_currency($currency, helpers::format_price($purchase->paid_amount * $docCurrency['rate'], null, 'comma_dot'));
        $due_amount = helpers::with_currency($currency, helpers::format_price(($purchase->GrandTotal - $purchase->paid_amount) * $docCurrency['rate'], null, 'comma_dot'));

        $contact_name = $purchase['provider']->name;
        $business_name = $settings->CompanyName;

        // receiver Number
        $receiverNumber = $purchase['provider']->phone;

        // Check if the phone number is empty or null
        if (empty($receiverNumber) || $receiverNumber == null || $receiverNumber == 'null' || $receiverNumber == '') {
            return response()->json(['error' => 'Phone number is missing'], 400);
        }

        // replace the text with tags
        $message_text = str_replace('{contact_name}', $contact_name, $message_text);
        $message_text = str_replace('{business_name}', $business_name, $message_text);
        $message_text = str_replace('{invoice_url}', $invoice_url, $message_text);
        $message_text = str_replace('{invoice_number}', $invoice_number, $message_text);

        $message_text = str_replace('{total_amount}', $total_amount, $message_text);
        $message_text = str_replace('{paid_amount}', $paid_amount, $message_text);
        $message_text = str_replace('{due_amount}', $due_amount, $message_text);

        return response()->json(['message' => $message_text, 'phone' => $receiverNumber]);

    }

    // ---------------- get_import_purchases ---------------\\

    public function get_import_purchases(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'create', Purchase::class);

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        $suppliers = Provider::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'warehouses' => $warehouses,
            'suppliers' => $suppliers,
        ]);
    }

    // ------ preview_import_purchases (parse CSV without saving) -------------\\

    public function preview_import_purchases(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Purchase::class);

        $data = $this->request_products_csv($request);

        // request_products_csv returns a JsonResponse when parsing/validation fails
        if ($data instanceof \Illuminate\Http\JsonResponse) {
            return $data;
        }

        if ($data === null || !is_array($data)) {
            return response()->json([
                'status' => false,
                'msg' => 'Invalid CSV file',
            ]);
        }

        $rows = [];
        $subtotal = 0;

        foreach ($data as $row) {
            $product = Product::with('unitPurchase')
                ->where('deleted_at', '=', null)
                ->where('code', $row['productcode'])
                ->first();

            if (!$product) {
                continue;
            }

            $qty = (float) ($row['qty'] ?? 0);
            $cost = self::importLineCost($row, $product);
            $total = $qty * $cost;
            $subtotal += $total;

            $rows[] = [
                'code' => $product->code,
                'name' => $product->name,
                'qty' => $qty,
                'cost' => $cost,
                'cost_from_file' => self::hasImportCost($row),
                'total' => $total,
                'unit' => optional($product->unitPurchase)->ShortName,
                'is_batch_tracked' => (bool) ($product->is_batch_tracked ?? false),
                'product_id' => $product->id,
            ];
        }

        return response()->json([
            'status' => true,
            'rows' => $rows,
            'subtotal' => $subtotal,
            'count' => count($rows),
        ]);
    }

    // ------ store_import_purchases -------------\\

    public function store_import_purchases(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Purchase::class);

        $data = $this->request_products_csv($request);

        // A parse / validation failure comes back as its error response.
        if ($data instanceof \Illuminate\Http\JsonResponse) {
            return $data;
        }
        if (! is_array($data) || ! $data) {
            return response()->json(['status' => false, 'msg' => 'Invalid CSV file']);
        }

        request()->validate([
            'supplier_id' => 'required',
            'warehouse_id' => 'required',
            'supplier_invoice' => 'nullable|string|max:100',
        ]);

        // The same lines into the same warehouse from the same supplier, still
        // standing as a purchase → ask before receiving them twice.
        $fingerprint = ImportFingerprint::hash(
            'purchase_import:'.(int) $request->supplier_id,
            (int) $request->warehouse_id,
            array_map(fn ($r) => [$r['productcode'] ?? '', $r['qty'] ?? '', $r['cost'] ?? ''], $data)
        );
        if (! $request->boolean('confirm_duplicate') && ($previous = ImportFingerprint::previous('purchase_import:'.(int) $request->supplier_id, $fingerprint))) {
            return ImportFingerprint::duplicateResponse($previous);
        }

        // Pharmacy: decode batches payload (map of productcode → batches[]).
        $batchesByCode = [];
        $rawBatches = $request->input('batches_by_code');
        if (is_string($rawBatches) && $rawBatches !== '') {
            $decoded = json_decode($rawBatches, true);
            if (is_array($decoded)) {
                $batchesByCode = $decoded;
            }
        }

        \DB::transaction(function () use ($request, $data, $batchesByCode, $fingerprint) {
            $order = new Purchase;

            $order->date = $request->date;
            $order->time = now()->toTimeString();
            $order->Ref = $this->getNumberOrder();
            $order->provider_id = $request->supplier_id;
            $order->GrandTotal = 0;
            $order->warehouse_id = $request->warehouse_id;
            $order->tax_rate = $request->tax_rate;
            $order->TaxNet = 0;
            $order->discount = $request->discount;
            $order->shipping = $request->shipping;
            $order->statut = $request->statut;
            $order->payment_statut = 'unpaid';
            // The supplier's invoice number heads the notes, the way the AI
            // invoice scan records it on hand-made purchases.
            $supplierInvoice = trim((string) $request->input('supplier_invoice', ''));
            $order->notes = $supplierInvoice !== ''
                ? trim('Supplier invoice: '.$supplierInvoice."\n".(string) $request->notes)
                : $request->notes;
            $order->user_id = Auth::user()->id;

            $order->save();

            $total = 0;
            $inputDetailsForBatches = [];
            foreach ($data as $key => $value) {

                $product = Product::where('deleted_at', '=', null)->where('code', $value['productcode'])->first();
                $unit = Unit::where('id', $product->unit_purchase_id)->first();
                $lineCost = self::importLineCost($value, $product);

                $total += $value['qty'] * $lineCost;

                $orderDetails[] = [
                    'purchase_id' => $order->id,
                    'quantity' => $value['qty'],
                    'cost' => $lineCost,
                    'purchase_unit_id' => $product->unit_purchase_id,
                    'TaxNet' => 0,
                    'tax_method' => 1,
                    'discount' => 0,
                    'discount_method' => 2,
                    'product_id' => $product->id,
                    'product_variant_id' => null,
                    'total' => $value['qty'] * $lineCost,
                    'imei_number' => null,
                ];

                $inputDetailsForBatches[] = [
                    'product_id' => $product->id,
                    'batches' => $batchesByCode[$value['productcode']] ?? [],
                ];

                if ($order->statut == 'received') {

                    $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                        ->where('warehouse_id', $order->warehouse_id)
                        ->where('product_id', $product->id)
                        ->lockForUpdate()->first();

                    if ($unit && $product_warehouse) {
                        if ($unit->operator == '/') {
                            $product_warehouse->qte += $value['qty'] / $unit->operator_value;
                        } else {
                            $product_warehouse->qte += $value['qty'] * $unit->operator_value;
                        }
                        $product_warehouse->save();
                    }
                }
            }
            PurchaseDetail::insert($orderDetails);

            // Pharmacy: link captured batches to the freshly inserted PurchaseDetail rows.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported() && $order->statut == 'received') {
                $persisted = PurchaseDetail::where('purchase_id', $order->id)
                    ->orderBy('id', 'asc')
                    ->get();
                $batchService->applyForPurchase($order, $inputDetailsForBatches, $persisted);
            }

            //  Calculte Grand_Total
            $purchase_data = Purchase::where('id', $order->id)->first();

            $total_without_discount = $total - $purchase_data->discount;

            $TaxNet = ($total_without_discount * $purchase_data->tax_rate) / 100;

            $purchase_data->TaxNet = $TaxNet;

            $purchase_data->GrandTotal = $total_without_discount + $TaxNet + $purchase_data->shipping;
            $purchase_data->save();

            ImportFingerprint::record('purchase_import:'.(int) $request->supplier_id, $fingerprint, (int) $request->warehouse_id, 'purchase', $order->id, $request->file('products')->getClientOriginalName());
        }, 10);

        return response()->json(['success' => true, 'message' => 'Purchase Created !!']);
    }

    /** A CSV line's cost column, when filled in; otherwise the product's cost. */
    private static function hasImportCost(array $row): bool
    {
        return isset($row['cost']) && $row['cost'] !== '' && is_numeric($row['cost']);
    }

    private static function importLineCost(array $row, Product $product): float
    {
        return self::hasImportCost($row) ? (float) $row['cost'] : (float) $product->cost;
    }

    // import Products
    public function request_products_csv(Request $request)
    {

        ini_set('max_execution_time', 2000);

        $file = $request->file('products');
        $ext = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
        if ($ext != 'csv') {
            return response()->json([
                'msg' => 'must be in csv format',
                'status' => false,
            ]);
        } else {
            // Read the CSV file
            $data = [];
            $rowcount = 0;
            if (($handle = fopen($file->getPathname(), 'r')) !== false) {
                $max_line_length = defined('MAX_LINE_LENGTH') ? MAX_LINE_LENGTH : 10000;
                $header = fgetcsv($handle, $max_line_length, ';'); // Use semicolon as the delimiter

                // Process the header row
                $escapedHeader = [];
                foreach ($header as $key => $value) {
                    $lheader = strtolower($value);
                    $escapedItem = preg_replace('/[^a-z]/', '', $lheader);
                    $escapedHeader[] = $escapedItem;
                }

                $header_colcount = count($header);
                while (($row = fgetcsv($handle, $max_line_length, ';')) !== false) { // Use semicolon as the delimiter
                    $row_colcount = count($row);
                    if ($row_colcount == $header_colcount) {
                        $entry = array_combine($escapedHeader, $row);
                        $data[] = $entry;
                    } else {
                        return null;
                    }
                    $rowcount++;
                }
                fclose($handle);
            } else {
                return null;
            }

            // Clean the data
            $cleanedData = [];
            foreach ($data as $row) {
                $cleanedRow = [];
                foreach ($row as $key => $value) {
                    $cleanedKey = trim($key);
                    $cleanedRow[$cleanedKey] = $value;
                }
                // Optional line cost: "cost" or "Unit Cost"; blank = product cost.
                if (! array_key_exists('cost', $cleanedRow) && array_key_exists('unitcost', $cleanedRow)) {
                    $cleanedRow['cost'] = $cleanedRow['unitcost'];
                }
                if (array_key_exists('cost', $cleanedRow)) {
                    $cleanedRow['cost'] = trim((string) $cleanedRow['cost']);
                }
                $cleanedData[] = $cleanedRow;
            }

            // Check for duplicate productcode in CSV
            $productCodes = array_column($cleanedData, 'productcode');
            if (count($productCodes) !== count(array_unique($productCodes))) {
                return response()->json([
                    'msg' => 'Duplicate product code found in CSV file',
                    'status' => false,
                ]);
            }

            // Validate productcode existence in the database
            $missingProductCodes = [];
            foreach ($productCodes as $code) {
                // Live products only: the save step looks codes up the same way.
                if (! Product::whereNull('deleted_at')->where('code', $code)->exists()) {
                    $missingProductCodes[] = $code;
                }
            }

            if (! empty($missingProductCodes)) {
                return response()->json([
                    'msg' => 'The following product codes do not exist in the database: '.implode(', ', $missingProductCodes),
                    'status' => false,
                ]);
            }

            // Define validation rules
            $rules = [];
            foreach ($cleanedData as $index => $row) {
                $rules[$index.'.productcode'] = 'required';
                $rules[$index.'.qty'] = 'required|numeric';
                $rules[$index.'.cost'] = 'nullable|numeric|min:0';
            }

            // Validate the data
            $validator = validator()->make($cleanedData, $rules);

            if ($validator->fails()) {
                return response()->json([
                    'msg' => 'Validation failed',
                    'errors' => $validator->errors(),
                    'status' => false,
                ]);
            }

            // Return the cleaned data
            return $cleanedData;

        }
    }

    // ------------- Get Purchase Documents ----------\\
    public function getDocuments($purchaseId)
    {
        $this->authorizeForUser(request()->user('api'), 'view', Purchase::class);
        
        $purchase = Purchase::findOrFail($purchaseId);
        
        $documents = DB::table('purchase_documents')
            ->where('purchase_id', $purchaseId)
            ->where('deleted_at', null)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'documents' => $documents,
            'status' => true
        ]);
    }

    // ------------- Upload Purchase Documents ----------\\
    public function uploadDocuments(Request $request, $purchaseId)
    {
        $this->authorizeForUser($request->user('api'), 'update', Purchase::class);
        
        $purchase = Purchase::findOrFail($purchaseId);

        $request->validate([
            'documents.*' => 'required|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv,txt|max:10240', // Max 10MB per file
        ]);

        $uploadedDocuments = [];

        if ($request->hasFile('documents')) {
            // Create directory if it doesn't exist
            $uploadPath = upload_public_path('purchase_documents');
            if (!file_exists($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }

            // Refuse the whole batch before writing anything if one file is not an allowed type
            $extensions = [];
            foreach ($request->file('documents') as $i => $file) {
                $extensions[$i] = \App\Support\SafeUpload::documentExtension($file, 'documents');
            }

            foreach ($request->file('documents') as $i => $file) {
                // Capture metadata BEFORE moving the file (tmp file is still readable)
                $originalName = \App\Support\SafeUpload::displayName($file);
                $size = $file->getSize();
                $mimeType = $file->getMimeType();

                // Never reuse the client name on disk: it decides how public/ serves the file
                $filename = \App\Support\SafeUpload::name($extensions[$i], time() . '_');

                // Move file to public/images/purchase_documents
                $file->move($uploadPath, $filename);
                
                $relativePath = upload_path('purchase_documents') . '/' . $filename;

                $documentId = DB::table('purchase_documents')->insertGetId([
                    'purchase_id' => $purchaseId,
                    'name' => $originalName,
                    'path' => $relativePath,
                    'size' => $size,
                    'mime_type' => $mimeType,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                $uploadedDocuments[] = $documentId;
            }
        }

        return response()->json([
            'message' => 'Documents uploaded successfully',
            'documents' => $uploadedDocuments,
            'status' => true
        ]);
    }

    // ------------- Download Purchase Document ----------\\
    public function downloadDocument($documentId)
    {
        $this->authorizeForUser(request()->user('api'), 'view', Purchase::class);
        
        $document = DB::table('purchase_documents')
            ->where('id', $documentId)
            ->where('deleted_at', null)
            ->first();

        if (!$document) {
            return response()->json([
                'message' => 'Document not found in database',
                'status' => false
            ], 404);
        }

        $filePath = public_path($document->path);

        if (!file_exists($filePath)) {
            return response()->json([
                'message' => 'Physical file not found on server',
                'status' => false,
                'path' => $document->path
            ], 404);
        }

        return response()->download($filePath, $document->name);
    }

    // ------------- Delete Purchase Document ----------\\
    public function deleteDocument($documentId)
    {
        $this->authorizeForUser(request()->user('api'), 'delete', Purchase::class);
        
        $document = DB::table('purchase_documents')
            ->where('id', $documentId)
            ->where('deleted_at', null)
            ->first();

        if (!$document) {
            return response()->json([
                'message' => 'Document not found',
                'status' => false
            ], 404);
        }

        // Soft delete
        DB::table('purchase_documents')
            ->where('id', $documentId)
            ->update(['deleted_at' => Carbon::now()]);

        // Optionally delete the physical file
        // Only delete files that really live under public/images — the stored
        // path is server-written, but keep the unlink() contained regardless.
        $filePath = realpath(public_path($document->path));
        $imagesRoot = realpath(public_path('images'));
        if ($filePath && $imagesRoot && str_starts_with($filePath, $imagesRoot . DIRECTORY_SEPARATOR) && is_file($filePath)) {
            unlink($filePath);
        }

        return response()->json([
            'message' => 'Document deleted successfully',
            'status' => true
        ]);
    }
}
