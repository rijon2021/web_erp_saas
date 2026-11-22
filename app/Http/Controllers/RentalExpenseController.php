<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\PaymentMethod;
use App\Models\RentalExpense;
use App\Models\Setting;
use App\Models\RentalItem;
use App\Models\RentalMaintenance;
use App\Services\Rental\RentalAccounting;
use App\Services\Rental\RentalMaintenanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Rental expenses (behind `rnt_expenses`) and per-item profitability.
 * Edits reverse the original journal and post the new figures, so the books
 * always match the row.
 */
class RentalExpenseController extends Controller
{
    public function __construct(
        private RentalMaintenanceService $maintenance,
        private RentalAccounting $accounting,
    ) {
    }

    /** Options for the expense form (items, payment methods, accounts). */
    public function meta(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'expenses', RentalItem::class);
        $setting = Setting::whereNull('deleted_at')->first();

        return response()->json([
            'items' => RentalItem::whereNull('deleted_at')->orderBy('name')->get(['id', 'code', 'name', 'type', 'status']),
            'payment_methods' => PaymentMethod::active()->exceptCustomerWallet()->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'accounts' => Account::whereNull('deleted_at')->orderBy('account_name')->get(['id', 'account_name']),
            'default_payment_method_id' => optional($setting)->default_payment_method_id,
            'default_account_id' => optional($setting)->default_account_id,
        ]);
    }

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'expenses', RentalItem::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';
        $order = in_array($request->SortField, ['expense_date', 'amount', 'category'], true) ? 'rental_expenses.' . $request->SortField : 'rental_expenses.expense_date';

        $query = RentalExpense::with('method', 'account', 'maintenance')
            ->leftJoin('rental_items', 'rental_items.id', '=', 'rental_expenses.rental_item_id')
            ->whereNull('rental_expenses.deleted_at')
            ->select('rental_expenses.*', 'rental_items.name as item_name', 'rental_items.code as item_code')
            ->when($request->filled('rental_item_id'), fn ($q) => $q->where('rental_expenses.rental_item_id', $request->rental_item_id))
            ->when($request->filled('category'), fn ($q) => $q->where('rental_expenses.category', $request->category))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('rental_expenses.expense_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('rental_expenses.expense_date', '<=', $request->to))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(fn ($w) => $w->where('rental_expenses.vendor', 'LIKE', "%{$s}%")
                    ->orWhere('rental_expenses.reference', 'LIKE', "%{$s}%")
                    ->orWhere('rental_expenses.description', 'LIKE', "%{$s}%")
                    ->orWhere('rental_items.name', 'LIKE', "%{$s}%"));
            });

        // select() replaces the row columns, so the GROUP BY stays valid under ONLY_FULL_GROUP_BY.
        $byCategory = (clone $query)->reorder()->setEagerLoads([])
            ->select('rental_expenses.category')->selectRaw('SUM(rental_expenses.amount) as total')
            ->groupBy('rental_expenses.category')->pluck('total', 'category')->map(fn ($v) => round((float) $v, 2));
        $total = round((float) $byCategory->sum(), 2);
        $totalRows = (clone $query)->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $rows = $query->orderBy($order, $dir)->orderBy('rental_expenses.id', 'desc')
            ->offset(($page * $perPage) - $perPage)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'total_amount' => $total,
            'by_category' => $byCategory,
            'expenses' => $rows->map(fn ($e) => $this->present($e))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'expenses', RentalItem::class);

        $data = $this->validated($request);
        $e = DB::transaction(fn () => $this->maintenance->createExpense($data, $request->file('attachment_file')));

        return response()->json(['success' => true, 'id' => $e->id]);
    }

    /** Multipart POST. Re-journals: reverse the old figures, post the new ones. */
    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'expenses', RentalItem::class);

        $e = RentalExpense::whereNull('deleted_at')->findOrFail($id);
        $data = $this->validated($request);

        DB::transaction(function () use ($e, $data, $request) {
            $this->accounting->expensePaid($e, true);
            if ($request->hasFile('attachment_file')) {
                $this->deleteFile($e->attachment);
                $dir = upload_public_path(RentalExpense::FILE_DIR);
                if (! file_exists($dir)) {
                    mkdir($dir, 0755, true);
                }
                $file = $request->file('attachment_file');
                $name = \Illuminate\Support\Str::random(40) . '.' . \App\Services\Rental\RentalUpload::extension($file, 'receipt');
                $file->move($dir, $name);
                $data['attachment'] = $name;
            } elseif ($request->boolean('remove_attachment')) {
                $this->deleteFile($e->attachment);
                $data['attachment'] = null;
            }
            $e->update($data);
            $this->accounting->expensePaid($e->fresh());
        });

        return response()->json(['success' => true, 'id' => $e->id]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'expenses', RentalItem::class);

        $e = RentalExpense::whereNull('deleted_at')->findOrFail($id);
        DB::transaction(fn () => $this->maintenance->deleteExpense($e));

        return response()->json(['success' => true]);
    }

    /**
     * Item profitability: income (billed, attributed by rent share), expenses,
     * maintenance, profit, payback on the purchase cost, utilization and a
     * 12-month series.
     */
    public function itemFinancials(Request $request, $id)
    {
        $user = $request->user('api');
        if (! $user->can('reports', RentalItem::class) && ! $user->can('expenses', RentalItem::class)) {
            abort(403, 'This action is unauthorized.');
        }

        $item = RentalItem::whereNull('deleted_at')->findOrFail($id);
        $income = $this->maintenance->itemIncome($item->id);
        $expenses = RentalExpense::whereNull('deleted_at')->where('rental_item_id', $item->id)->get(['amount', 'expense_date', 'category']);
        $expenseTotal = round((float) $expenses->sum('amount'), 2);
        $profit = round($income['total'] - $expenseTotal, 2);

        $series = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = Carbon::today()->startOfMonth()->subMonths($i);
            $key = $m->format('Y-m');
            $series[] = [
                'month' => $m->format('M Y'),
                'income' => (float) ($income['months'][$key] ?? 0),
                'expenses' => round((float) $expenses->filter(fn ($e) => $e->expense_date->format('Y-m') === $key)->sum('amount'), 2),
            ];
        }

        $jobs = RentalMaintenance::whereNull('deleted_at')->where('rental_item_id', $item->id);

        return response()->json([
            'income' => $income['total'],
            'expenses' => $expenseTotal,
            'profit' => $profit,
            'purchase_cost' => $item->purchase_cost === null ? null : (float) $item->purchase_cost,
            // Share of the purchase cost already earned back by profit.
            'payback' => $item->purchase_cost && (float) $item->purchase_cost > 0 ? round($profit / (float) $item->purchase_cost * 100, 1) : null,
            'utilization' => $this->maintenance->utilization($item),
            'by_category' => $expenses->groupBy('category')->map(fn ($g) => round((float) $g->sum('amount'), 2)),
            'series' => $series,
            'maintenance' => [
                'open' => (clone $jobs)->whereIn('status', RentalMaintenance::OPEN)->count(),
                'completed' => (clone $jobs)->where('status', 'completed')->count(),
                'recent' => (clone $jobs)->orderByDesc('created_at')->limit(5)->get()->map(fn ($m) => [
                    'id' => $m->id, 'number' => $m->number, 'title' => $m->title, 'status' => $m->status,
                    'priority' => $m->priority, 'cost' => (float) $m->cost, 'created_at' => optional($m->created_at)->toIso8601String(),
                ])->values(),
            ],
        ]);
    }

    private function present(RentalExpense $e): array
    {
        return [
            'id' => $e->id,
            'rental_item_id' => $e->rental_item_id,
            'item_name' => $e->item_name ?? optional($e->item)->name,
            'item_code' => $e->item_code ?? optional($e->item)->code,
            'rental_maintenance_id' => $e->rental_maintenance_id,
            'maintenance_number' => optional($e->maintenance)->number,
            'category' => $e->category,
            'amount' => (float) $e->amount,
            'expense_date' => optional($e->expense_date)->toDateString(),
            'payment_method_id' => $e->payment_method_id,
            'method' => optional($e->method)->name,
            'account_id' => $e->account_id,
            'account' => optional($e->account)->account_name,
            'vendor' => $e->vendor,
            'reference' => $e->reference,
            'description' => $e->description,
            'attachment_url' => RentalExpense::fileUrl($e->attachment),
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'rental_item_id' => 'nullable|integer|exists:rental_items,id',
            'rental_maintenance_id' => 'nullable|integer|exists:rental_maintenance,id',
            'category' => ['required', Rule::in(RentalExpense::CATEGORIES)],
            'amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date|before_or_equal:today',
            'payment_method_id' => 'nullable|integer|exists:payment_methods,id',
            'account_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'vendor' => 'nullable|string|max:191',
            'reference' => 'nullable|string|max:191',
            'description' => 'nullable|string|max:2000',
            'attachment_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ]);
        unset($data['attachment_file']);

        $out = array_merge($data, [
            'rental_item_id' => $data['rental_item_id'] ?? null,
            'rental_maintenance_id' => $data['rental_maintenance_id'] ?? null,
            'amount' => round((float) $data['amount'], 2),
        ]);
        // The expense form doesn't carry the job link: leave it as it is.
        if (! $request->has('rental_maintenance_id')) {
            unset($out['rental_maintenance_id']);
        }

        return $out;
    }

    private function deleteFile($name): void
    {
        if (is_string($name) && $name !== '' && ! str_contains($name, '/') && ! str_contains($name, '\\')) {
            @unlink(upload_public_path(RentalExpense::FILE_DIR . '/' . $name));
        }
    }
}
