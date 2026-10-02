<?php

namespace App\Services\AccountingV2;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * NEW FEATURE - SAFE ADDITION
 *
 * Generates double-entry journal entries without modifying legacy modules.
 * All writes are to new acc_* tables only.
 */
class AccountingHelper
{
    /**
     * Post a sale as a full double entry.
     *
     * Two entries are written. The revenue entry splits the invoice into what it
     * is actually made of — tax collected is a liability, not income, and
     * shipping and discounts belong in their own accounts — so the P&L shows
     * revenue rather than the gross invoice total. The COGS entry moves the cost
     * of what left the warehouse from Inventory to Cost of Goods Sold, which is
     * what makes gross profit and the inventory asset meaningful.
     *
     * COGS is written under its own reference_type so it nets independently of
     * the revenue entry; reverseSale() unwinds both.
     */
    public static function fromSale($sale): void
    {
        self::safe(function () use ($sale) {
            $amount = (float) ($sale->GrandTotal ?? 0);
            if ($amount <= 0) {
                return;
            }

            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($sale->date ?? null),
                    'description' => 'Sale #'.($sale->Ref ?? $sale->id ?? ''),
                    'reference_type' => 'sale',
                    'reference_id' => $sale->id ?? null,
                ],
                'lines' => self::saleRevenueLines($sale),
            ]);

            self::postSaleCogs($sale);
        });
    }

    /**
     * The revenue side of a sale, as balanced lines.
     *
     * Debits: A/R for the whole invoice, plus discounts given (contra-revenue,
     * held in an income-type account so the P&L nets it off). Credits: tax
     * payable, shipping income, and whatever is left as revenue. The two sides
     * balance by construction, so no rounding plug is needed.
     */
    protected static function saleRevenueLines($sale): array
    {
        $arCoaId = self::getOrCreateCoa('1100-AR', 'Accounts Receivable', 'asset');
        $revCoaId = self::getOrCreateCoa('4000-REV', 'Sales Revenue', 'income');

        $amount = round((float) ($sale->GrandTotal ?? 0), 6);
        $tax = round((float) ($sale->TaxNet ?? 0), 6);
        $shipping = round((float) ($sale->shipping ?? 0), 6);
        $discount = round((float) ($sale->discount ?? 0) + (float) ($sale->promotion_discount ?? 0), 6);

        // Never let a stray field push a component past the invoice itself.
        $tax = max(0.0, min($tax, $amount));
        $shipping = max(0.0, min($shipping, $amount));
        $discount = max(0.0, $discount);

        $revenue = round($amount - $tax - $shipping + $discount, 6);

        $lines = [
            ['coa_id' => $arCoaId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Accounts Receivable'],
        ];

        if ($discount > 0.0001) {
            $discountCoaId = self::getOrCreateCoa('4030-DISC', 'Sales Discounts', 'income');
            $lines[] = ['coa_id' => $discountCoaId, 'debit' => $discount, 'credit' => 0, 'memo' => 'Discount Given'];
        }

        if ($tax > 0.0001) {
            $taxCoaId = self::getOrCreateCoa('2200-TAX', 'Tax Payable', 'liability');
            $lines[] = ['coa_id' => $taxCoaId, 'debit' => 0, 'credit' => $tax, 'memo' => 'Tax Collected'];
        }

        if ($shipping > 0.0001) {
            $shipCoaId = self::getOrCreateCoa('4020-SHIP', 'Shipping Income', 'income');
            $lines[] = ['coa_id' => $shipCoaId, 'debit' => 0, 'credit' => $shipping, 'memo' => 'Shipping'];
        }

        if (abs($revenue) > 0.0001) {
            $lines[] = ['coa_id' => $revCoaId, 'debit' => 0, 'credit' => $revenue, 'memo' => 'Sales Revenue'];
        }

        return $lines;
    }

    /**
     * Move the cost of the goods sold out of Inventory.
     *
     * Skipped silently when the cost works out at zero — a service-only sale has
     * no inventory to relieve, and posting a zero entry would just be noise.
     */
    protected static function postSaleCogs($sale): void
    {
        $cost = self::saleCostOfGoods($sale->id ?? 0);

        if ($cost <= 0.0001) {
            return;
        }

        $cogsCoaId = self::getOrCreateCoa('5000-COGS', 'Cost of Goods Sold', 'expense');
        $invCoaId = self::getOrCreateCoa('1200-INV', 'Inventory', 'asset');

        self::createBalancedEntry([
            'journal' => [
                'date' => self::dateFrom($sale->date ?? null),
                'description' => 'COGS for Sale #'.($sale->Ref ?? $sale->id ?? ''),
                'reference_type' => 'sale_cogs',
                'reference_id' => $sale->id ?? null,
            ],
            'lines' => [
                ['coa_id' => $cogsCoaId, 'debit' => $cost, 'credit' => 0,     'memo' => 'Cost of Goods Sold'],
                ['coa_id' => $invCoaId,  'debit' => 0,     'credit' => $cost, 'memo' => 'Inventory'],
            ],
        ]);
    }

    /**
     * Cost of the lines on a sale, in BASE units.
     *
     * Cost is held per base unit while a sale line is recorded in packs of a sale
     * unit, so both conversions have to be applied — the same arithmetic the
     * stock deduction and the profit report use. Getting this wrong understates
     * COGS and inflates gross profit for anyone selling in packs.
     */
    protected static function saleCostOfGoods($saleId): float
    {
        return self::costOfLines('sale_details', 'sale_id', $saleId);
    }

    /**
     * Cost of the lines on any sale-shaped document (sale or sale return), in
     * BASE units. See saleCostOfGoods() for the unit arithmetic.
     */
    protected static function costOfLines(string $table, string $foreignKey, $documentId): float
    {
        if (! $documentId) {
            return 0.0;
        }

        return (float) (self::safeReturn(function () use ($table, $foreignKey, $documentId) {
            if (! Schema::hasTable($table)) {
                return 0.0;
            }

            $packExpr = Schema::hasColumn($table, 'pack_multiplier')
                ? 'COALESCE(sd.pack_multiplier, 1)'
                : '1';

            return (float) DB::table($table.' as sd')
                ->join('products as p', 'p.id', '=', 'sd.product_id')
                ->leftJoin('product_variants as pv', 'pv.id', '=', 'sd.product_variant_id')
                ->leftJoin('units as cu', function ($j) {
                    $j->on(DB::raw('cu.id'), '=', DB::raw('COALESCE(sd.sale_unit_id, p.unit_sale_id, p.unit_id)'));
                })
                ->where('sd.'.$foreignKey, $documentId)
                ->sum(DB::raw("sd.quantity
                               * {$packExpr}
                               * CASE WHEN cu.operator = '/'
                                      THEN 1 / COALESCE(NULLIF(cu.operator_value, 0), 1)
                                      ELSE COALESCE(cu.operator_value, 1)
                                 END
                               * COALESCE(pv.cost, p.cost, 0)"));
        }) ?: 0.0);
    }

    public static function fromPurchase($purchase): void
    {
        self::safe(function () use ($purchase) {
            $amount = (float) ($purchase->GrandTotal ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($purchase->date ?? null),
                    'description' => 'Purchase #'.($purchase->Ref ?? $purchase->id ?? ''),
                    'reference_type' => 'purchase',
                    'reference_id' => $purchase->id ?? null,
                ],
                'lines' => self::purchaseLines($purchase),
            ]);
        });
    }

    /**
     * The lines of a purchase.
     *
     * Recoverable input tax is an asset, not part of the cost of the stock, so
     * it is split out: capitalising it into Inventory overstates what the goods
     * cost and therefore understates gross profit when they are sold.
     */
    protected static function purchaseLines($purchase): array
    {
        $invCoaId = self::getOrCreateCoa('1200-INV', 'Inventory', 'asset');
        $apCoaId = self::getOrCreateCoa('2100-AP', 'Accounts Payable', 'liability');

        $amount = round((float) ($purchase->GrandTotal ?? 0), 6);
        $tax = max(0.0, min(round((float) ($purchase->TaxNet ?? 0), 6), $amount));
        $goods = round($amount - $tax, 6);

        $lines = [];

        if (abs($goods) > 0.0001) {
            $lines[] = ['coa_id' => $invCoaId, 'debit' => $goods, 'credit' => 0, 'memo' => 'Inventory'];
        }

        if ($tax > 0.0001) {
            $taxCoaId = self::getOrCreateCoa('1300-TAXIN', 'Input Tax Receivable', 'asset');
            $lines[] = ['coa_id' => $taxCoaId, 'debit' => $tax, 'credit' => 0, 'memo' => 'Input Tax'];
        }

        $lines[] = ['coa_id' => $apCoaId, 'debit' => 0, 'credit' => $amount, 'memo' => 'Accounts Payable'];

        return $lines;
    }

    /**
     * Post a sale return as the mirror of a sale.
     *
     * The revenue entry gives back the tax that was collected (it is no longer
     * owed) and books the rest against Sales Returns. The cost entry puts the
     * returned goods back into Inventory and takes their cost off COGS, under
     * its own reference_type exactly like postSaleCogs(); reverseSaleReturn()
     * unwinds both.
     */
    public static function fromSaleReturn($saleReturn): void
    {
        self::safe(function () use ($saleReturn) {
            $amount = (float) ($saleReturn->GrandTotal ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($saleReturn->date ?? null),
                    'description' => 'Sale Return #'.($saleReturn->Ref ?? $saleReturn->id ?? ''),
                    'reference_type' => 'sale_return',
                    'reference_id' => $saleReturn->id ?? null,
                ],
                'lines' => self::saleReturnLines($saleReturn),
            ]);

            self::postSaleReturnCogs($saleReturn);
        });
    }

    protected static function saleReturnLines($saleReturn): array
    {
        $arCoaId = self::getOrCreateCoa('1100-AR', 'Accounts Receivable', 'asset');
        $retCoaId = self::getOrCreateCoa('4010-RET', 'Sales Returns', 'income');

        $amount = round((float) ($saleReturn->GrandTotal ?? 0), 6);
        $tax = max(0.0, min(round((float) ($saleReturn->TaxNet ?? 0), 6), $amount));
        $returned = round($amount - $tax, 6);

        $lines = [];

        if (abs($returned) > 0.0001) {
            $lines[] = ['coa_id' => $retCoaId, 'debit' => $returned, 'credit' => 0, 'memo' => 'Sales Return'];
        }

        if ($tax > 0.0001) {
            $taxCoaId = self::getOrCreateCoa('2200-TAX', 'Tax Payable', 'liability');
            $lines[] = ['coa_id' => $taxCoaId, 'debit' => $tax, 'credit' => 0, 'memo' => 'Tax Refunded'];
        }

        $lines[] = ['coa_id' => $arCoaId, 'debit' => 0, 'credit' => $amount, 'memo' => 'Reduce A/R'];

        return $lines;
    }

    protected static function postSaleReturnCogs($saleReturn): void
    {
        $cost = self::costOfLines('sale_return_details', 'sale_return_id', $saleReturn->id ?? 0);

        if ($cost <= 0.0001) {
            return;
        }

        $cogsCoaId = self::getOrCreateCoa('5000-COGS', 'Cost of Goods Sold', 'expense');
        $invCoaId = self::getOrCreateCoa('1200-INV', 'Inventory', 'asset');

        self::createBalancedEntry([
            'journal' => [
                'date' => self::dateFrom($saleReturn->date ?? null),
                'description' => 'COGS for Sale Return #'.($saleReturn->Ref ?? $saleReturn->id ?? ''),
                'reference_type' => 'sale_return_cogs',
                'reference_id' => $saleReturn->id ?? null,
            ],
            'lines' => [
                ['coa_id' => $invCoaId,  'debit' => $cost, 'credit' => 0,     'memo' => 'Inventory Restocked'],
                ['coa_id' => $cogsCoaId, 'debit' => 0,     'credit' => $cost, 'memo' => 'Cost of Goods Sold'],
            ],
        ]);
    }

    /**
     * Re-state a sale whose total changed.
     *
     * Reverses whatever is currently posted for the sale — revenue entry and
     * COGS entry alike — and posts it again from the document as it now stands.
     * A delta against A/R would leave the tax, shipping and discount split from
     * the original entry untouched and therefore wrong.
     */
    public static function fromSaleAdjustment($sale): void
    {
        self::safe(function () use ($sale) {
            self::reverseSale($sale);
            self::fromSale($sale);
        });
    }

    /**
     * Re-state a purchase whose total changed. Same reasoning as
     * fromSaleAdjustment(): the input-tax split makes delta maths wrong.
     */
    public static function fromPurchaseAdjustment($purchase): void
    {
        self::safe(function () use ($purchase) {
            self::reversePurchase($purchase);
            self::fromPurchase($purchase);
        });
    }

    public static function fromExpenseAdjustment($expense): void
    {
        self::safe(function () use ($expense) {
            $current = self::getPostedAmount('expense', $expense->id ?? 0);
            $target = (float) ($expense->amount ?? $expense->total ?? 0);
            $delta = round($target - $current, 6);
            if (abs($delta) <= 0.0001) {
                return;
            }
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($expense->account_id);
            $expenseCoaId = self::getOrCreateCoa('EXP', 'Expenses', 'expense');
            if ($delta > 0) {
                self::createBalancedEntry([
                    'journal' => [
                        'date' => self::dateFrom($expense->date ?? null),
                        'description' => 'Expense Adjustment #'.($expense->id ?? ''),
                        'reference_type' => 'expense',
                        'reference_id' => $expense->id ?? null,
                    ],
                    'lines' => [
                        ['coa_id' => $expenseCoaId, 'debit' => $delta, 'credit' => 0,      'memo' => 'Expense Adj'],
                        ['coa_id' => $cashCoaId,    'debit' => 0,      'credit' => $delta, 'memo' => 'Cash Adj'],
                    ],
                ]);
            } else {
                $delta = abs($delta);
                self::createBalancedEntry([
                    'journal' => [
                        'date' => self::dateFrom($expense->date ?? null),
                        'description' => 'Expense Adjustment #'.($expense->id ?? ''),
                        'reference_type' => 'expense',
                        'reference_id' => $expense->id ?? null,
                    ],
                    'lines' => [
                        ['coa_id' => $cashCoaId,    'debit' => $delta, 'credit' => 0,      'memo' => 'Cash Adj'],
                        ['coa_id' => $expenseCoaId, 'debit' => 0,      'credit' => $delta, 'memo' => 'Expense Adj'],
                    ],
                ]);
            }
        });
    }

    public static function reverseSale($sale): void
    {
        $id = $sale->id ?? 0;
        $ref = $sale->Ref ?? $sale->id ?? '';

        self::reverseByReference('sale', $id, 'Reverse Sale #'.$ref);
        // The cost side is a separate entry, so it needs its own reversal or the
        // inventory relief would survive a deleted sale.
        self::reverseByReference('sale_cogs', $id, 'Reverse COGS for Sale #'.$ref);
    }

    public static function reversePurchase($purchase): void
    {
        self::reverseByReference('purchase', $purchase->id ?? 0, 'Reverse Purchase #'.($purchase->Ref ?? $purchase->id ?? ''));
    }

    public static function reverseExpense($expense): void
    {
        self::reverseByReference('expense', $expense->id ?? 0, 'Reverse Expense #'.($expense->id ?? ''));
    }

    protected static function reverseByReference(string $referenceType, int $referenceId, string $desc): void
    {
        self::safe(function () use ($referenceType, $referenceId, $desc) {
            if (! Schema::hasTable('acc_journal_entries') || ! Schema::hasTable('acc_journal_entry_lines')) {
                return;
            }
            $groups = DB::table('acc_journal_entries as j')
                ->join('acc_journal_entry_lines as l', 'l.journal_entry_id', '=', 'j.id')
                ->select('l.coa_id', 'l.account_id', DB::raw('SUM(l.debit) as debit'), DB::raw('SUM(l.credit) as credit'))
                ->where('j.status', 'posted')
                ->where('j.reference_type', $referenceType)
                ->where('j.reference_id', $referenceId)
                ->groupBy('l.coa_id', 'l.account_id')
                ->get();
            if ($groups->isEmpty()) {
                return;
            }
            $lines = [];
            foreach ($groups as $g) {
                $net = round((float) $g->debit - (float) $g->credit, 6);
                if (abs($net) <= 0.0001) {
                    continue;
                }
                if ($net > 0) {
                    // previously debit > credit -> now credit net
                    $lines[] = ['coa_id' => $g->coa_id, 'account_id' => $g->account_id, 'debit' => 0, 'credit' => abs($net), 'memo' => 'Reversal'];
                } else {
                    $lines[] = ['coa_id' => $g->coa_id, 'account_id' => $g->account_id, 'debit' => abs($net), 'credit' => 0, 'memo' => 'Reversal'];
                }
            }
            if (empty($lines)) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => Carbon::now()->toDateString(),
                    'description' => $desc,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                ],
                'lines' => $lines,
            ]);
        });
    }

    protected static function getPostedAmount(string $referenceType, int $referenceId): float
    {
        return (float) self::safeReturn(function () use ($referenceType, $referenceId) {
            if (! Schema::hasTable('acc_journal_entries') || ! Schema::hasTable('acc_journal_entry_lines')) {
                return 0.0;
            }
            // Net each account first, then sum the net-debit side. Adjustment
            // entries add equally to raw debit and credit totals, so
            // max(debits, credits) overstates the posted amount after any edit
            // and later deltas would compound the error.
            $rows = DB::table('acc_journal_entries as j')
                ->join('acc_journal_entry_lines as l', 'l.journal_entry_id', '=', 'j.id')
                ->select('l.coa_id', DB::raw('SUM(l.debit) as debits'), DB::raw('SUM(l.credit) as credits'))
                ->where('j.status', 'posted')
                ->where('j.reference_type', $referenceType)
                ->where('j.reference_id', $referenceId)
                ->groupBy('l.coa_id')
                ->get();
            $amount = 0.0;
            foreach ($rows as $row) {
                $net = (float) ($row->debits ?? 0) - (float) ($row->credits ?? 0);
                if ($net > 0) {
                    $amount += $net;
                }
            }

            return $amount;
        }) ?: 0.0;
    }

    public static function fromExpense($expense): void
    {
        self::safe(function () use ($expense) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($expense->account_id);
            $expenseCoaId = self::getOrCreateCoa('EXP', 'Expenses', 'expense');
            $amount = (float) ($expense->amount ?? $expense->total ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($expense->date ?? null),
                    'description' => 'Expense #'.($expense->id ?? ''),
                    'reference_type' => 'expense',
                    'reference_id' => $expense->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $expenseCoaId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Expense'],
                    ['coa_id' => $cashCoaId,    'debit' => 0,      'credit' => $amount, 'memo' => 'Cash'],
                ],
            ]);
        });
    }

    public static function fromPaymentSale($payment): void
    {
        self::safe(function () use ($payment) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($payment->account_id);
            if (! $cashCoaId) {
                $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            }
            $arCoaId = self::getOrCreateCoa('1100-AR', 'Accounts Receivable', 'asset');
            $amount = (float) ($payment->montant ?? $payment->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($payment->date ?? null),
                    'description' => 'Payment Sale #'.($payment->id ?? ''),
                    'reference_type' => 'payment_sale',
                    'reference_id' => $payment->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $cashCoaId,   'debit' => $amount, 'credit' => 0, 'memo' => 'Cash In'],
                    ['coa_id' => $arCoaId,     'debit' => 0,       'credit' => $amount, 'memo' => 'Clear A/R'],
                ],
            ]);
        });
    }

    public static function fromPaymentPurchase($payment): void
    {
        self::safe(function () use ($payment) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($payment->account_id);
            if (! $cashCoaId) {
                $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            }
            $apCoaId = self::getOrCreateCoa('2100-AP', 'Accounts Payable', 'liability');
            $amount = (float) ($payment->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($payment->date ?? null),
                    'description' => 'Payment Purchase #'.($payment->id ?? ''),
                    'reference_type' => 'payment_purchase',
                    'reference_id' => $payment->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $apCoaId,   'debit' => $amount, 'credit' => 0, 'memo' => 'Reduce A/P'],
                    ['coa_id' => $cashCoaId, 'debit' => 0,       'credit' => $amount, 'memo' => 'Cash Out'],
                ],
            ]);
        });
    }

    public static function reversePaymentSale($payment): void
    {
        self::safe(function () use ($payment) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($payment->account_id);
            if (! $cashCoaId) {
                $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            }
            $arCoaId = self::getOrCreateCoa('1100-AR', 'Accounts Receivable', 'asset');
            $amount = (float) ($payment->montant ?? $payment->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($payment->date ?? null),
                    'description' => 'Reversal Payment Sale #'.($payment->id ?? ''),
                    'reference_type' => 'payment_sale',
                    'reference_id' => $payment->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $arCoaId,   'debit' => $amount, 'credit' => 0, 'memo' => 'Reinstate A/R'],
                    ['coa_id' => $cashCoaId, 'debit' => 0,       'credit' => $amount, 'memo' => 'Cash Out'],
                ],
            ]);
        });
    }

    public static function reversePaymentPurchase($payment): void
    {
        self::safe(function () use ($payment) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($payment->account_id);
            if (! $cashCoaId) {
                $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            }
            $apCoaId = self::getOrCreateCoa('2100-AP', 'Accounts Payable', 'liability');
            $amount = (float) ($payment->montant ?? $payment->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($payment->date ?? null),
                    'description' => 'Reversal Payment Purchase #'.($payment->id ?? ''),
                    'reference_type' => 'payment_purchase',
                    'reference_id' => $payment->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $cashCoaId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Cash In'],
                    ['coa_id' => $apCoaId,  'debit' => 0,       'credit' => $amount, 'memo' => 'Reinstate A/P'],
                ],
            ]);
        });
    }

    public static function fromDeposit($deposit): void
    {
        self::safe(function () use ($deposit) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($deposit->account_id);
            $equityCoaId = self::getOrCreateCoa(Config::get('accounting_v2.codes.retained_earnings', '3100'), 'Retained Earnings', 'equity');
            $amount = (float) ($deposit->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($deposit->date ?? null),
                    'description' => 'Deposit #'.($deposit->id ?? ''),
                    'reference_type' => 'deposit',
                    'reference_id' => $deposit->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $cashCoaId,   'debit' => $amount, 'credit' => 0, 'memo' => 'Cash In'],
                    ['coa_id' => $equityCoaId, 'debit' => 0,       'credit' => $amount, 'memo' => 'Equity'],
                ],
            ]);
        });
    }

    public static function fromTransferMoney($transfer): void
    {
        self::safe(function () use ($transfer) {
            $fromCoaId = self::getOrCreateCoaForLegacyAccount($transfer->from_account_id ?? $transfer->account_from_id ?? null);
            $toCoaId = self::getOrCreateCoaForLegacyAccount($transfer->to_account_id ?? $transfer->account_to_id ?? null);
            $amount = (float) ($transfer->amount ?? 0);
            if ($amount <= 0 || ! $fromCoaId || ! $toCoaId) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($transfer->date ?? null),
                    'description' => 'Transfer #'.($transfer->id ?? ''),
                    'reference_type' => 'transfer',
                    'reference_id' => $transfer->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $toCoaId,   'debit' => $amount, 'credit' => 0, 'memo' => 'To'],
                    ['coa_id' => $fromCoaId, 'debit' => 0,      'credit' => $amount, 'memo' => 'From'],
                ],
            ]);
        });
    }

    /**
     * Post a purchase return as the mirror of a purchase.
     *
     * A purchase capitalises the goods into Inventory and the tax into Input Tax
     * Receivable, so sending goods back has to take them out of the same two
     * accounts — crediting a separate "Purchase Returns" expense would leave
     * Inventory overstated by everything ever returned.
     */
    public static function fromPurchaseReturn($purchaseReturn): void
    {
        self::safe(function () use ($purchaseReturn) {
            $amount = (float) ($purchaseReturn->GrandTotal ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($purchaseReturn->date ?? null),
                    'description' => 'Purchase Return #'.($purchaseReturn->Ref ?? $purchaseReturn->id ?? ''),
                    'reference_type' => 'purchase_return',
                    'reference_id' => $purchaseReturn->id ?? null,
                ],
                'lines' => self::purchaseReturnLines($purchaseReturn),
            ]);
        });
    }

    protected static function purchaseReturnLines($purchaseReturn): array
    {
        $invCoaId = self::getOrCreateCoa('1200-INV', 'Inventory', 'asset');
        $apCoaId = self::getOrCreateCoa('2100-AP', 'Accounts Payable', 'liability');

        $amount = round((float) ($purchaseReturn->GrandTotal ?? 0), 6);
        $tax = max(0.0, min(round((float) ($purchaseReturn->TaxNet ?? 0), 6), $amount));
        $goods = round($amount - $tax, 6);

        $lines = [
            ['coa_id' => $apCoaId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Reduce A/P'],
        ];

        if (abs($goods) > 0.0001) {
            $lines[] = ['coa_id' => $invCoaId, 'debit' => 0, 'credit' => $goods, 'memo' => 'Inventory Returned'];
        }

        if ($tax > 0.0001) {
            $taxCoaId = self::getOrCreateCoa('1300-TAXIN', 'Input Tax Receivable', 'asset');
            $lines[] = ['coa_id' => $taxCoaId, 'debit' => 0, 'credit' => $tax, 'memo' => 'Input Tax Reversed'];
        }

        return $lines;
    }

    /**
     * Re-state a purchase return whose total changed. Same reasoning as
     * fromPurchaseAdjustment(). Reversal nets by account, so returns posted in
     * the old A/P-vs-Purchase-Returns shape are unwound cleanly too.
     */
    public static function fromPurchaseReturnAdjustment($purchaseReturn): void
    {
        self::safe(function () use ($purchaseReturn) {
            self::reversePurchaseReturn($purchaseReturn);
            self::fromPurchaseReturn($purchaseReturn);
        });
    }

    public static function reversePurchaseReturn($purchaseReturn): void
    {
        self::reverseByReference('purchase_return', $purchaseReturn->id ?? 0, 'Reverse Purchase Return #'.($purchaseReturn->Ref ?? $purchaseReturn->id ?? ''));
    }

    /**
     * Re-state a sale return whose total changed. Same reasoning as
     * fromSaleAdjustment(): the tax split and the separate cost entry make
     * delta maths wrong.
     */
    public static function fromSaleReturnAdjustment($saleReturn): void
    {
        self::safe(function () use ($saleReturn) {
            self::reverseSaleReturn($saleReturn);
            self::fromSaleReturn($saleReturn);
        });
    }

    public static function reverseSaleReturn($saleReturn): void
    {
        $id = $saleReturn->id ?? 0;
        $ref = $saleReturn->Ref ?? $saleReturn->id ?? '';

        self::reverseByReference('sale_return', $id, 'Reverse Sale Return #'.$ref);
        self::reverseByReference('sale_return_cogs', $id, 'Reverse COGS for Sale Return #'.$ref);
    }

    public static function fromPaymentSaleReturn($payment): void
    {
        self::safe(function () use ($payment) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($payment->account_id);
            if (! $cashCoaId) {
                $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            }
            $arCoaId = self::getOrCreateCoa('1100-AR', 'Accounts Receivable', 'asset');
            $amount = (float) ($payment->montant ?? $payment->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($payment->date ?? null),
                    'description' => 'Payment Sale Return #'.($payment->id ?? ''),
                    'reference_type' => 'payment_sale_return',
                    'reference_id' => $payment->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $arCoaId,   'debit' => $amount, 'credit' => 0,       'memo' => 'Settle Customer Credit'],
                    ['coa_id' => $cashCoaId, 'debit' => 0,       'credit' => $amount, 'memo' => 'Cash Refunded'],
                ],
            ]);
        });
    }

    public static function reversePaymentSaleReturn($payment): void
    {
        self::safe(function () use ($payment) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($payment->account_id);
            if (! $cashCoaId) {
                $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            }
            $arCoaId = self::getOrCreateCoa('1100-AR', 'Accounts Receivable', 'asset');
            $amount = (float) ($payment->montant ?? $payment->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($payment->date ?? null),
                    'description' => 'Reversal Payment Sale Return #'.($payment->id ?? ''),
                    'reference_type' => 'payment_sale_return',
                    'reference_id' => $payment->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $cashCoaId, 'debit' => $amount, 'credit' => 0,       'memo' => 'Cash Reinstated'],
                    ['coa_id' => $arCoaId,   'debit' => 0,       'credit' => $amount, 'memo' => 'Reinstate Customer Credit'],
                ],
            ]);
        });
    }

    public static function fromPaymentPurchaseReturn($payment): void
    {
        self::safe(function () use ($payment) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($payment->account_id);
            if (! $cashCoaId) {
                $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            }
            $apCoaId = self::getOrCreateCoa('2100-AP', 'Accounts Payable', 'liability');
            $amount = (float) ($payment->montant ?? $payment->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($payment->date ?? null),
                    'description' => 'Payment Purchase Return #'.($payment->id ?? ''),
                    'reference_type' => 'payment_purchase_return',
                    'reference_id' => $payment->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $cashCoaId, 'debit' => $amount, 'credit' => 0,       'memo' => 'Cash Received'],
                    ['coa_id' => $apCoaId,   'debit' => 0,       'credit' => $amount, 'memo' => 'Settle Supplier Credit'],
                ],
            ]);
        });
    }

    public static function reversePaymentPurchaseReturn($payment): void
    {
        self::safe(function () use ($payment) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($payment->account_id);
            if (! $cashCoaId) {
                $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            }
            $apCoaId = self::getOrCreateCoa('2100-AP', 'Accounts Payable', 'liability');
            $amount = (float) ($payment->montant ?? $payment->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($payment->date ?? null),
                    'description' => 'Reversal Payment Purchase Return #'.($payment->id ?? ''),
                    'reference_type' => 'payment_purchase_return',
                    'reference_id' => $payment->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $apCoaId,   'debit' => $amount, 'credit' => 0,       'memo' => 'Reinstate Supplier Credit'],
                    ['coa_id' => $cashCoaId, 'debit' => 0,       'credit' => $amount, 'memo' => 'Cash Reversed'],
                ],
            ]);
        });
    }

    public static function fromCashRegisterOpening($cashRegister): void
    {
        self::safe(function () use ($cashRegister) {
            $amount = (float) ($cashRegister->opening_balance ?? 0);
            if ($amount <= 0) {
                return;
            }
            $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            $equityCoaId = self::getOrCreateCoa(Config::get('accounting_v2.codes.retained_earnings', '3100'), 'Retained Earnings', 'equity');
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($cashRegister->opened_at ?? $cashRegister->created_at ?? null),
                    'description' => 'Cash Register Opening #'.($cashRegister->id ?? ''),
                    'reference_type' => 'cash_register_open',
                    'reference_id' => $cashRegister->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $cashCoaId,   'debit' => $amount, 'credit' => 0,       'memo' => 'Opening Float'],
                    ['coa_id' => $equityCoaId, 'debit' => 0,       'credit' => $amount, 'memo' => 'Opening Balance'],
                ],
            ]);
        });
    }

    public static function createBalancedEntry(array $data): void
    {
        self::safe(function () use ($data) {
            if (! Schema::hasTable('acc_journal_entries') || ! Schema::hasTable('acc_journal_entry_lines')) {
                return; // not installed yet
            }

            DB::transaction(function () use ($data) {
                $journalId = DB::table('acc_journal_entries')->insertGetId([
                    'date' => $data['journal']['date'] ?? Carbon::now()->toDateString(),
                    'reference_type' => $data['journal']['reference_type'] ?? null,
                    'reference_id' => $data['journal']['reference_id'] ?? null,
                    'description' => $data['journal']['description'] ?? null,
                    'status' => Config::get('accounting_v2.auto_post_journals', true) ? 'posted' : 'draft',
                    'posted_at' => Config::get('accounting_v2.auto_post_journals', true) ? Carbon::now() : null,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                $lines = $data['lines'] ?? [];
                foreach ($lines as $line) {
                    DB::table('acc_journal_entry_lines')->insert([
                        'journal_entry_id' => $journalId,
                        'coa_id' => $line['coa_id'] ?? null,
                        'account_id' => $line['account_id'] ?? null,
                        'debit' => (float) ($line['debit'] ?? 0),
                        'credit' => (float) ($line['credit'] ?? 0),
                        'memo' => $line['memo'] ?? null,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ]);
                }
            });
        });
    }

    public static function getOrCreateCoaForLegacyAccount($accountId): ?int
    {
        if (! $accountId) {
            return null;
        }

        return self::safeReturn(function () use ($accountId) {
            if (! Schema::hasTable('acc_chart_of_accounts')) {
                return null;
            }
            $existing = DB::table('acc_chart_of_accounts')->where('account_id', $accountId)->first();
            if ($existing) {
                return (int) $existing->id;
            }
            $legacy = DB::table('accounts')->where('id', $accountId)->first();
            $code = '1010-'.(string) $accountId;
            $name = $legacy->account_name ?? ('Account #'.$accountId);

            return DB::table('acc_chart_of_accounts')->insertGetId([
                'account_id' => $accountId,
                'code' => $code,
                'name' => $name,
                'type' => 'asset',
                'parent_id' => null,
                'level' => 1,
                'is_active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        });
    }

    public static function getOrCreateCoa(string $code, string $name, string $type): int
    {
        return self::safeReturn(function () use ($code, $name, $type) {
            if (! Schema::hasTable('acc_chart_of_accounts')) {
                return 0;
            }
            $existing = DB::table('acc_chart_of_accounts')->where('code', $code)->first();
            if ($existing) {
                return (int) $existing->id;
            }

            return DB::table('acc_chart_of_accounts')->insertGetId([
                'account_id' => null,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'parent_id' => null,
                'level' => 0,
                'is_active' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        });
    }

    protected static function dateFrom($date): string
    {
        try {
            return $date ? Carbon::parse($date)->toDateString() : Carbon::now()->toDateString();
        } catch (\Throwable $e) {
            return Carbon::now()->toDateString();
        }
    }

    protected static function safe(callable $fn): void
    {
        try {
            if (! config('accounting_v2.enabled', true)) {
                return;
            }
            $fn();
        } catch (\Throwable $e) {
            Log::warning('[AccountingV2] '.$e->getMessage());
        }
    }

    protected static function safeReturn(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::warning('[AccountingV2] '.$e->getMessage());

            return null;
        }
    }
}
