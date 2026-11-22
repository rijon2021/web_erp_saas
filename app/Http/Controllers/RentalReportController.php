<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\RentalContract;
use App\Models\RentalContractItem;
use App\Models\RentalCustomer;
use App\Models\RentalDepositTransaction;
use App\Models\RentalExpense;
use App\Models\RentalInvoice;
use App\Models\RentalItem;
use App\Models\RentalPayment;
use App\Models\RentalReminder;
use App\Models\Setting;
use App\Services\Rental\RentalBillingService;
use App\Services\Rental\RentalReminderService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PDF;

/**
 * Rental reports. Every report answers { rows, totalRows, totals } and takes
 * the admin's usual list params (page / limit / SortField / SortType / search)
 * plus start_date / end_date, so one Vue shell drives them all.
 *
 * Rows are built in PHP (they mix several tables and attributed figures),
 * then searched, sorted and paginated here — fine at rental-register sizes.
 */
class RentalReportController extends Controller
{
    public function __construct(private RentalBillingService $billing)
    {
    }

    // ------------------------------------------------------------------
    // Occupancy — how much of the period each item spent on contracts
    // ------------------------------------------------------------------

    public function occupancy(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'reports', RentalItem::class);
        [$from, $to] = $this->range($request);
        // Rented time is counted up to now, so the share is measured against
        // the elapsed part of the range (the current month isn't halved).
        $elapsedTo = $to->copy()->min(Carbon::now()->max($from));
        $days = max(1 / 1440, $from->diffInMinutes($elapsedTo) / 1440);

        $items = $this->items($request);
        $lines = $this->contractLines($items->pluck('id')->all(), $from, $to);
        $income = $this->attributedIncome($items->pluck('id')->all(), $from, $to);

        $rows = $items->map(function ($item) use ($lines, $income, $from, $to, $days) {
            $mine = $lines->where('rental_item_id', $item->id);
            $covered = 0.0;
            foreach ($mine as $l) {
                $s = Carbon::parse($l->start_date)->max($from);
                $endRaw = $l->returned_at ?? ($l->status !== 'active' ? ($l->ended_at ?? $l->end_date) : $l->end_date);
                $e = ($endRaw ? Carbon::parse($endRaw) : Carbon::now())->min($to)->min(Carbon::now()->max($from));
                if ($e->gt($s)) {
                    $covered += $s->diffInMinutes($e) / 1440 * min(1, (int) $l->quantity / max(1, (int) $item->quantity));
                }
            }

            return [
                'id' => $item->id,
                'item_name' => $item->name,
                'code' => $item->code,
                'type' => $item->type,
                'category' => optional($item->category)->name,
                'status' => $item->status,
                'days_rented' => round($covered, 1),
                'days_in_period' => round($days, 1),
                'occupancy' => round(min(100, $covered / $days * 100), 1),
                'contracts' => $mine->pluck('rental_contract_id')->unique()->count(),
                'billed' => round($income[$item->id] ?? 0, 2),
            ];
        });

        return $this->respond($request, $rows, 'occupancy', ['days_rented', 'contracts', 'billed'], [
            'occupancy' => $rows->count() ? round($rows->avg('occupancy'), 1) : 0,
        ]);
    }

    // ------------------------------------------------------------------
    // Profit by item
    // ------------------------------------------------------------------

    public function profit(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'reports', RentalItem::class);
        [$from, $to] = $this->range($request);

        $items = $this->items($request);
        $ids = $items->pluck('id')->all();
        $income = $this->attributedIncome($ids, $from, $to);
        $expenses = RentalExpense::whereNull('deleted_at')->whereIn('rental_item_id', $ids)
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('rental_item_id, SUM(amount) as total')->groupBy('rental_item_id')->pluck('total', 'rental_item_id');

        $rows = $items->map(function ($item) use ($income, $expenses) {
            $in = round((float) ($income[$item->id] ?? 0), 2);
            $out = round((float) ($expenses[$item->id] ?? 0), 2);
            $profit = round($in - $out, 2);

            return [
                'id' => $item->id,
                'item_name' => $item->name,
                'code' => $item->code,
                'type' => $item->type,
                'category' => optional($item->category)->name,
                'income' => $in,
                'expenses' => $out,
                'profit' => $profit,
                'margin' => $in > 0 ? round($profit / $in * 100, 1) : null,
                'purchase_cost' => $item->purchase_cost === null ? null : (float) $item->purchase_cost,
            ];
        });

        return $this->respond($request, $rows, 'profit', ['income', 'expenses', 'profit']);
    }

    // ------------------------------------------------------------------
    // Aging — what customers owe, by how late it is
    // ------------------------------------------------------------------

    public function aging(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'reports', RentalItem::class);
        $this->billing->applyLateFees();
        $today = Carbon::today();

        $rows = RentalInvoice::with('contract', 'client')->whereIn('status', ['unpaid', 'partial'])->get()
            ->groupBy('rental_customer_id')
            ->map(function (Collection $invs, $customerId) use ($today) {
                $b = ['current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'd90p' => 0.0];
                foreach ($invs as $inv) {
                    $lateFrom = $inv->due_date->copy()->addDays((int) optional($inv->contract)->grace_days);
                    $late = $lateFrom->lt($today) ? (int) $lateFrom->diffInDays($today) : 0;
                    $key = $late <= 0 ? 'current' : ($late <= 30 ? 'd30' : ($late <= 60 ? 'd60' : ($late <= 90 ? 'd90' : 'd90p')));
                    $b[$key] += $inv->balance();
                }
                $first = $invs->first();

                return array_merge([
                    'id' => (int) $customerId,
                    'customer_name' => optional($first->client)->name,
                    'phone' => optional($first->client)->phone,
                    'invoices' => $invs->count(),
                    'oldest_due' => $invs->min('due_date')->toDateString(),
                ], array_map(fn ($v) => round($v, 2), $b), ['total' => round(array_sum($b), 2)]);
            })->filter(fn ($r) => $r['total'] > 0)->values();

        return $this->respond($request, $rows, 'total', ['current', 'd30', 'd60', 'd90', 'd90p', 'total', 'invoices']);
    }

    // ------------------------------------------------------------------
    // Deposits held
    // ------------------------------------------------------------------

    public function deposits(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'reports', RentalItem::class);

        $tx = RentalDepositTransaction::whereNull('deleted_at')->get()->groupBy('rental_contract_id');
        $contracts = RentalContract::with('client')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereIn('id', $tx->keys())->orWhere(fn ($w) => $w->where('deposit_amount', '>', 0)->where('status', 'active')))
            ->get();

        $rows = $contracts->map(function ($c) use ($tx) {
            $t = $tx->get($c->id, collect());
            $sum = fn ($type) => round((float) $t->where('type', $type)->sum('amount'), 2);
            $held = round($sum('collected') - $sum('refunded') - $sum('applied') - $sum('retained'), 2);

            return [
                'id' => $c->id,
                'contract_number' => $c->contract_number,
                'customer_name' => optional($c->client)->name,
                'status' => $c->status,
                'agreed' => (float) $c->deposit_amount,
                'collected' => $sum('collected'),
                'applied' => $sum('applied'),
                'retained' => $sum('retained'),
                'refunded' => $sum('refunded'),
                'held' => $held,
                // Agreed but not (fully) collected on a live contract.
                'missing' => $c->status === 'active' ? max(0, round((float) $c->deposit_amount - $sum('collected'), 2)) : 0,
            ];
        });
        if ($request->get('only_held', '1') !== '0') {
            $rows = $rows->filter(fn ($r) => $r['held'] > 0 || $r['missing'] > 0)->values();
        }

        return $this->respond($request, $rows, 'held', ['agreed', 'collected', 'applied', 'retained', 'refunded', 'held', 'missing']);
    }

    // ------------------------------------------------------------------
    // Expenses by item and category
    // ------------------------------------------------------------------

    public function expenses(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'reports', RentalItem::class);
        [$from, $to] = $this->range($request);

        $rows = RentalExpense::with('item')->whereNull('deleted_at')
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])->get()
            ->groupBy(fn ($e) => $e->rental_item_id ?: 0)
            ->map(function (Collection $list, $itemId) {
                $row = [
                    'id' => (int) $itemId,
                    'item_name' => $itemId ? optional($list->first()->item)->name : null,
                    'code' => $itemId ? optional($list->first()->item)->code : null,
                    'entries' => $list->count(),
                ];
                foreach (RentalExpense::CATEGORIES as $cat) {
                    $row[$cat] = round((float) $list->where('category', $cat)->sum('amount'), 2);
                }
                $row['total'] = round((float) $list->sum('amount'), 2);

                return $row;
            })->values();

        return $this->respond($request, $rows, 'total', array_merge(RentalExpense::CATEGORIES, ['total', 'entries']));
    }

    // ------------------------------------------------------------------
    // Contracts ending soon
    // ------------------------------------------------------------------

    public function expiring(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'reports', RentalItem::class);
        $from = $request->filled('start_date') ? Carbon::parse($request->start_date)->startOfDay() : Carbon::now();
        $to = $request->filled('end_date') ? Carbon::parse($request->end_date)->endOfDay() : Carbon::now()->addDays(30);

        $rows = RentalContract::with('client', 'items', 'renewal')->whereNull('deleted_at')->where('status', 'active')
            ->whereNotNull('end_date')->whereBetween('end_date', [$from, $to])->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'contract_number' => $c->contract_number,
                'customer_name' => optional($c->client)->name,
                'phone' => optional($c->client)->phone,
                'items' => $c->items->pluck('item_name')->implode(', '),
                'end_date' => $c->end_date->toIso8601String(),
                'days_left' => (int) floor(Carbon::now()->diffInDays($c->end_date, false)),
                'rate_period' => $c->rate_period,
                'total_amount' => (float) $c->total_amount,
                'renewal' => optional($c->renewal)->contract_number,
                'deposit_held' => $c->depositHeld(),
            ]);

        return $this->respond($request, $rows, 'end_date', ['total_amount', 'deposit_held'], [], 'asc');
    }

    // ------------------------------------------------------------------
    // Reminder log
    // ------------------------------------------------------------------

    public function reminders(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'reports', RentalItem::class);
        [$from, $to] = $this->range($request, Carbon::now()->subDays(30));

        $rows = RentalReminder::with('contract.client', 'invoice')
            ->whereBetween('created_at', [$from, $to])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('id')->limit(5000)->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'created_at' => $r->created_at->toIso8601String(),
                'type' => $r->type,
                'channel' => $r->channel,
                'customer_name' => optional(optional($r->contract)->client)->name,
                'rental_contract_id' => $r->rental_contract_id,
                'contract_number' => optional($r->contract)->contract_number,
                'invoice_number' => optional($r->invoice)->invoice_number,
                'recipient' => $r->recipient,
                'status' => $r->status,
                'error' => $r->error,
                'manual' => (bool) $r->manual,
            ]);

        return $this->respond($request, $rows, 'created_at', [], [
            'sent' => $rows->where('status', 'sent')->count(),
            'failed' => $rows->where('status', 'failed')->count(),
            'skipped' => $rows->where('status', 'skipped')->count(),
        ]);
    }

    /** Send one reminder now from an invoice or contract page. */
    public function sendReminder(Request $request, RentalReminderService $reminders)
    {
        $this->authorizeForUser($request->user('api'), 'reminders', RentalItem::class);
        $request->validate([
            'type' => 'required|in:rent_due,rent_overdue,contract_expiring,return_due',
            'rental_invoice_id' => 'nullable|integer|exists:rental_invoices,id',
            'rental_contract_id' => 'nullable|integer|exists:rental_contracts,id|required_without:rental_invoice_id',
            'channels' => 'required|array|min:1',
            'channels.*' => 'in:email,sms,whatsapp',
        ]);

        $inv = $request->rental_invoice_id ? RentalInvoice::find($request->rental_invoice_id) : null;
        $c = $inv ? $inv->contract : RentalContract::find($request->rental_contract_id);
        $results = [];
        foreach (array_unique($request->channels) as $ch) {
            $results[$ch] = $reminders->send($request->type, $ch, $c, $inv, true);
            if (! $results[$ch]) {
                $last = RentalReminder::where('rental_contract_id', optional($c)->id)->where('channel', $ch)->latest('id')->first();
                $results[$ch . '_error'] = optional($last)->error;
            }
        }

        return response()->json(['success' => in_array(true, $results, true), 'results' => $results]);
    }

    // ------------------------------------------------------------------
    // Customer statement
    // ------------------------------------------------------------------

    public function statement(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'reports', RentalItem::class);

        return response()->json($this->buildStatement($request, (int) $id));
    }

    public function statementPdf(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'reports', RentalItem::class);

        $data = $this->buildStatement($request, (int) $id);
        $setting = Setting::whereNull('deleted_at')->first();
        $symbol = optional(Currency::find(optional($setting)->currency_id))->symbol ?? '';

        $pdf = PDF::loadHTML(view('pdf.rental_statement_pdf', [
            'st' => $data,
            'setting' => $setting,
            'money' => fn ($v) => trim($symbol . ' ' . number_format((float) $v, 2)),
        ])->render());
        $filename = 'Rental_Statement_' . preg_replace('/[^A-Za-z0-9]+/', '_', (string) $data['customer']['name']) . '.pdf';

        return $request->boolean('preview') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    /**
     * Invoices (debits) and payments (credits) between two dates, with the
     * balance carried in from before `from` and a running balance. Deposits
     * are listed separately: they are held money, not part of what is owed.
     */
    private function buildStatement(Request $request, int $customerId): array
    {
        $customer = RentalCustomer::with('client')->withTrashed()->findOrFail($customerId);
        $this->billing->applyLateFees();
        $from = $request->filled('start_date') ? Carbon::parse($request->start_date)->startOfDay() : Carbon::today()->subMonths(3)->startOfDay();
        $to = $request->filled('end_date') ? Carbon::parse($request->end_date)->endOfDay() : Carbon::today()->endOfDay();

        $invoices = RentalInvoice::with('contract')->where('rental_customer_id', $customerId)->where('status', '!=', 'void')->get();
        $payments = RentalPayment::with('method', 'contract')->whereNull('deleted_at')->where('rental_customer_id', $customerId)->get();

        $opening = round($invoices->filter(fn ($i) => $i->issue_date->lt($from))->sum(fn ($i) => $i->total())
            - (float) $payments->filter(fn ($p) => $p->payment_date->lt($from))->sum('amount'), 2);

        $lines = collect();
        foreach ($invoices->filter(fn ($i) => $i->issue_date->between($from, $to)) as $i) {
            $lines->push([
                'date' => $i->issue_date->toDateString(),
                'kind' => 'invoice',
                'reference' => $i->invoice_number,
                'contract_number' => optional($i->contract)->contract_number,
                'description' => $i->kind === 'charge' ? $i->description
                    : ('Rent ' . optional($i->period_start)->format('M j, Y') . ' – ' . optional($i->period_end)->format('M j, Y')),
                'debit' => $i->total(),
                'credit' => 0.0,
                'sort' => $i->issue_date->format('Ymd') . '0' . str_pad((string) $i->id, 9, '0', STR_PAD_LEFT),
            ]);
        }
        foreach ($payments->filter(fn ($p) => $p->payment_date->between($from, $to)) as $p) {
            $lines->push([
                'date' => $p->payment_date->toDateString(),
                'kind' => 'payment',
                'reference' => $p->payment_number,
                'contract_number' => optional($p->contract)->contract_number,
                'description' => $p->source === 'deposit' ? 'Paid from deposit' : ('Payment' . ($p->method ? ' — ' . $p->method->name : '') . ($p->reference ? ' (' . $p->reference . ')' : '')),
                'debit' => 0.0,
                'credit' => (float) $p->amount,
                'sort' => $p->payment_date->format('Ymd') . '1' . str_pad((string) $p->id, 9, '0', STR_PAD_LEFT),
            ]);
        }

        $balance = $opening;
        $lines = $lines->sortBy('sort')->values()->map(function ($l) use (&$balance) {
            $balance = round($balance + $l['debit'] - $l['credit'], 2);
            unset($l['sort']);

            return $l + ['balance' => $balance];
        });

        $deposits = RentalDepositTransaction::whereNull('deleted_at')->where('rental_customer_id', $customerId)->get();
        $dsum = fn ($type) => round((float) $deposits->where('type', $type)->sum('amount'), 2);

        return [
            'customer' => [
                'id' => $customer->id,
                'name' => optional($customer->client)->name,
                'phone' => optional($customer->client)->phone,
                'email' => optional($customer->client)->email,
                'address' => collect([optional($customer->client)->adresse, optional($customer->client)->city])->filter()->implode(', '),
            ],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'opening' => $opening,
            'debits' => round((float) $lines->sum('debit'), 2),
            'credits' => round((float) $lines->sum('credit'), 2),
            'closing' => $balance,
            'lines' => $lines,
            'deposit_held' => round($dsum('collected') - $dsum('refunded') - $dsum('applied') - $dsum('retained'), 2),
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** [from, to] from start_date/end_date, defaulting to this month (or `$defaultFrom` → now). */
    private function range(Request $request, ?Carbon $defaultFrom = null): array
    {
        $from = $request->filled('start_date') ? Carbon::parse($request->start_date)->startOfDay() : ($defaultFrom ?? Carbon::today()->startOfMonth());
        $to = $request->filled('end_date') ? Carbon::parse($request->end_date)->endOfDay() : ($defaultFrom ? Carbon::now() : Carbon::today()->endOfMonth());
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    private function items(Request $request)
    {
        return RentalItem::with('category')->whereNull('deleted_at')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('category_id'), fn ($q) => $q->where('rental_category_id', $request->category_id))
            ->get();
    }

    /** Contract lines (live contracts) overlapping [from, to]. */
    private function contractLines(array $itemIds, Carbon $from, Carbon $to)
    {
        return RentalContractItem::join('rental_contracts', 'rental_contracts.id', '=', 'rental_contract_items.rental_contract_id')
            ->whereIn('rental_contract_items.rental_item_id', $itemIds)
            ->whereIn('rental_contracts.status', ['active', 'ended', 'terminated', 'renewed'])
            ->whereNull('rental_contracts.deleted_at')
            ->where('rental_contracts.start_date', '<', $to)
            ->get([
                'rental_contract_items.rental_contract_id', 'rental_contract_items.rental_item_id', 'rental_contract_items.quantity',
                'rental_contract_items.returned_at', 'rental_contracts.start_date', 'rental_contracts.end_date',
                'rental_contracts.ended_at', 'rental_contracts.status',
            ]);
    }

    /** Invoices issued in [from, to], attributed to items by their share of each contract's rent. */
    private function attributedIncome(array $itemIds, Carbon $from, Carbon $to): array
    {
        $lines = RentalContractItem::whereIn('rental_item_id', $itemIds)->get(['rental_contract_id', 'rental_item_id', 'amount']);
        if ($lines->isEmpty()) {
            return [];
        }
        $rent = RentalContractItem::whereIn('rental_contract_id', $lines->pluck('rental_contract_id')->unique())
            ->selectRaw('rental_contract_id, SUM(amount) as rent')->groupBy('rental_contract_id')->pluck('rent', 'rental_contract_id');
        $billed = RentalInvoice::whereIn('rental_contract_id', $lines->pluck('rental_contract_id')->unique())
            ->where('status', '!=', 'void')->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])->get()
            ->groupBy('rental_contract_id')->map(fn ($g) => $g->sum(fn ($i) => $i->total()));

        $out = [];
        foreach ($lines as $l) {
            $r = (float) ($rent[$l->rental_contract_id] ?? 0);
            $share = $r > 0 ? (float) $l->amount / $r : 0;
            $out[$l->rental_item_id] = ($out[$l->rental_item_id] ?? 0) + (float) ($billed[$l->rental_contract_id] ?? 0) * $share;
        }

        return $out;
    }

    /** Search + sort + paginate a built collection; totals over the whole (searched) set. */
    private function respond(Request $request, Collection $rows, string $defaultSort, array $sumKeys, array $extraTotals = [], string $defaultDir = 'desc')
    {
        if ($request->filled('search')) {
            $q = mb_strtolower($request->search);
            $rows = $rows->filter(fn ($r) => collect($r)->filter(fn ($v) => is_string($v))
                ->contains(fn ($v) => str_contains(mb_strtolower($v), $q)))->values();
        }

        $sort = $request->SortField && $rows->isNotEmpty() && array_key_exists($request->SortField, $rows->first()) ? $request->SortField : $defaultSort;
        $dir = $request->SortType ? (strtolower($request->SortType) === 'asc' ? 'asc' : 'desc') : $defaultDir;
        $rows = $rows->sortBy(fn ($r) => $r[$sort] ?? null, SORT_REGULAR, $dir === 'desc')->values();

        $totals = [];
        foreach ($sumKeys as $k) {
            $totals[$k] = round((float) $rows->sum($k), 2);
        }

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $total = $rows->count();
        $pageRows = $perPage === -1 ? $rows : $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'rows' => $pageRows->values(),
            'totalRows' => $total,
            'totals' => array_merge($totals, $extraTotals),
        ]);
    }
}
