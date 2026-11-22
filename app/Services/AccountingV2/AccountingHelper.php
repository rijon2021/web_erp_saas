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

    /**
     * Commission payout to a sales agent: Dr Sales Commission Expense,
     * Cr the cash/bank account the receipt was paid from (1000-CASH when the
     * receipt names no account). Same shape as an Expense.
     */
    public static function fromCommissionReceipt($receipt): void
    {
        self::safe(function () use ($receipt) {
            $cashCoaId = self::getOrCreateCoaForLegacyAccount($receipt->account_id ?? null);
            if (! $cashCoaId) {
                $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
            }
            $expenseCoaId = self::getOrCreateCoa('5200-COMM', 'Sales Commission Expense', 'expense');
            $amount = (float) ($receipt->amount ?? 0);
            if ($amount <= 0) {
                return;
            }
            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($receipt->paid_at ?? null),
                    'description' => 'Commission Receipt #'.($receipt->Ref ?? $receipt->id ?? ''),
                    'reference_type' => 'commission_receipt',
                    'reference_id' => $receipt->id ?? null,
                ],
                'lines' => [
                    ['coa_id' => $expenseCoaId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Sales commission paid'],
                    ['coa_id' => $cashCoaId,    'debit' => 0,       'credit' => $amount, 'memo' => 'Cash Out'],
                ],
            ]);
        });
    }

    public static function reverseCommissionReceipt($receipt): void
    {
        self::reverseByReference('commission_receipt', $receipt->id ?? 0, 'Reverse Commission Receipt #'.($receipt->Ref ?? $receipt->id ?? ''));
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
            // Paid from the customer wallet: the liability 2310-CUSTWAL goes
            // down instead of cash coming in.
            $cashCoaId = self::saleSettlementCoaFor($payment);
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
                    ['coa_id' => $cashCoaId,   'debit' => $amount, 'credit' => 0, 'memo' => self::isWalletFunded($payment) ? 'Customer wallet applied' : 'Cash In'],
                    ['coa_id' => $arCoaId,     'debit' => 0,       'credit' => $amount, 'memo' => 'Clear A/R'],
                ],
            ]);
        });
    }

    public static function fromPaymentPurchase($payment): void
    {
        self::safe(function () use ($payment) {
            // A payment funded from the supplier wallet spends the advance
            // (1300-SUPADV) instead of a cash account.
            $cashCoaId = self::settlementCoaFor($payment);
            $apCoaId = self::getOrCreateCoa('2100-AP', 'Accounts Payable', 'liability');
            $amount = (float) ($payment->montant ?? $payment->amount ?? 0);
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
                    ['coa_id' => $cashCoaId, 'debit' => 0,       'credit' => $amount, 'memo' => self::isWalletFunded($payment) ? 'Supplier advance applied' : 'Cash Out'],
                ],
            ]);
        });
    }

    /** True when a payment row moved a wallet (supplier wallet for purchase rows, customer wallet for sale rows). */
    protected static function isWalletFunded($payment): bool
    {
        return ! empty($payment->wallet_transaction_id ?? null);
    }

    /**
     * The account a supplier payment settles against: the legacy cash
     * account, or 1300-SUPADV (Supplier Advances) for wallet-funded rows.
     */
    protected static function settlementCoaFor($payment): int
    {
        if (self::isWalletFunded($payment)) {
            return self::getOrCreateCoa(
                \App\Services\SupplierWallet\SupplierWalletService::COA_CODE,
                \App\Services\SupplierWallet\SupplierWalletService::COA_NAME,
                'asset'
            );
        }
        $cashCoaId = self::getOrCreateCoaForLegacyAccount($payment->account_id);
        if (! $cashCoaId) {
            $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
        }

        return (int) $cashCoaId;
    }

    /**
     * The account a sale payment settles against: the legacy cash account,
     * or 2310-CUSTWAL (Customer Wallet Balances) for wallet-funded rows.
     */
    protected static function saleSettlementCoaFor($payment): int
    {
        if (self::isWalletFunded($payment)) {
            return self::getOrCreateCoa(
                \App\Services\CustomerWallet\CustomerWalletService::COA_CODE,
                \App\Services\CustomerWallet\CustomerWalletService::COA_NAME,
                'liability'
            );
        }
        $cashCoaId = self::getOrCreateCoaForLegacyAccount($payment->account_id);
        if (! $cashCoaId) {
            $cashCoaId = self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
        }

        return (int) $cashCoaId;
    }

    public static function reversePaymentSale($payment): void
    {
        self::safe(function () use ($payment) {
            $cashCoaId = self::saleSettlementCoaFor($payment);
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
            $cashCoaId = self::settlementCoaFor($payment);
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
            // Credited to the supplier wallet: the refund lands in 1300-SUPADV, not cash.
            $cashCoaId = self::settlementCoaFor($payment);
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
            $cashCoaId = self::settlementCoaFor($payment);
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

    /**
     * Re-state the inventory value of a stock adjustment from its lines as they
     * now stand: reverse what is posted for it, then post it again (nothing
     * once it is deleted). Lines are valued at cost, in base units.
     *
     * Opening stock (the "(auto)" adjustments written by product create and the
     * opening-stock imports) is booked against Opening Balance Equity; any other
     * adjustment against Inventory Adjustments. Once posted as opening stock,
     * later edits keep that account.
     *
     * A document from before inventory posting existed was never booked — its
     * value is part of the opening inventory true-up — so an edit to it is not
     * posted as if it were new.
     */
    public static function syncStockAdjustment(int $adjustmentId, bool $created = false, bool $opening = false): void
    {
        self::safe(function () use ($adjustmentId, $created, $opening) {
            $adjustment = DB::table('adjustments')->where('id', $adjustmentId)->first();
            if (! $adjustment) {
                return;
            }

            $type = ($opening || self::hasPosted('opening_stock', $adjustmentId)) ? 'opening_stock' : 'stock_adjustment';
            if (! $created && ! self::hasPosted($type, $adjustmentId)) {
                return;
            }

            $label = ($type === 'opening_stock' ? 'Opening Stock #' : 'Stock Adjustment #').($adjustment->Ref ?? $adjustmentId);
            self::reverseByReference($type, $adjustmentId, 'Reverse '.$label);
            if ($adjustment->deleted_at) {
                return;
            }

            // Combo lines in a regular adjustment assemble or break up kits —
            // a swap between inventory items, not a gain or loss.
            $value = self::stockLinesValue('adjustment_details', 'adjustment_id', $adjustmentId, $type === 'opening_stock');
            if (abs($value) <= 0.0001) {
                return;
            }

            $invCoaId = self::getOrCreateCoa('1200-INV', 'Inventory', 'asset');
            $otherCoaId = $type === 'opening_stock'
                ? self::getOrCreateCoa('3900-OBE', 'Opening Balance Equity', 'equity')
                : self::getOrCreateCoa('5100-INVADJ', 'Inventory Adjustments', 'expense');
            $amount = abs($value);

            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($adjustment->date ?? null),
                    'description' => $label,
                    'reference_type' => $type,
                    'reference_id' => $adjustmentId,
                ],
                'lines' => $value > 0
                    ? [
                        ['coa_id' => $invCoaId,   'debit' => $amount, 'credit' => 0,       'memo' => 'Inventory'],
                        ['coa_id' => $otherCoaId, 'debit' => 0,       'credit' => $amount, 'memo' => $type === 'opening_stock' ? 'Opening Balance' : 'Inventory Gain'],
                    ]
                    : [
                        ['coa_id' => $otherCoaId, 'debit' => $amount, 'credit' => 0,       'memo' => $type === 'opening_stock' ? 'Opening Balance' : 'Inventory Loss'],
                        ['coa_id' => $invCoaId,   'debit' => 0,       'credit' => $amount, 'memo' => 'Inventory'],
                    ],
            ]);
        });
    }

    /**
     * Re-state the cost of a damage write-off: Damaged Stock (expense) against
     * Inventory. Same reverse-and-repost and pre-existing-document rules as
     * syncStockAdjustment().
     */
    public static function syncDamage(int $damageId, bool $created = false): void
    {
        self::safe(function () use ($damageId, $created) {
            $damage = DB::table('damages')->where('id', $damageId)->first();
            if (! $damage) {
                return;
            }
            if (! $created && ! self::hasPosted('damage', $damageId)) {
                return;
            }

            $label = 'Damage #'.($damage->Ref ?? $damageId);
            self::reverseByReference('damage', $damageId, 'Reverse '.$label);
            if ($damage->deleted_at) {
                return;
            }

            // A damaged kit leaves stock as the kit itself, so combos count.
            $cost = self::stockLinesValue('damage_details', 'damage_id', $damageId, true);
            if ($cost <= 0.0001) {
                return;
            }

            $invCoaId = self::getOrCreateCoa('1200-INV', 'Inventory', 'asset');
            $damageCoaId = self::getOrCreateCoa('5110-DMG', 'Damaged Stock', 'expense');

            self::createBalancedEntry([
                'journal' => [
                    'date' => self::dateFrom($damage->date ?? null),
                    'description' => $label,
                    'reference_type' => 'damage',
                    'reference_id' => $damageId,
                ],
                'lines' => [
                    ['coa_id' => $damageCoaId, 'debit' => $cost, 'credit' => 0,     'memo' => 'Damaged Stock'],
                    ['coa_id' => $invCoaId,    'debit' => 0,     'credit' => $cost, 'memo' => 'Inventory'],
                ],
            ]);
        });
    }

    /**
     * Cost of a stock document's lines (quantities in base units), signed by
     * the line `type` when the table has one ("sub" lines count negative).
     * Services hold no stock; combos count only when $includeCombos and
     * virtual combo stock is off (a virtual combo has no stock of its own).
     */
    protected static function stockLinesValue(string $table, string $foreignKey, int $documentId, bool $includeCombos): float
    {
        $excluded = ['is_service'];
        if (! $includeCombos || \App\Services\ComboStockService::enabled()) {
            $excluded[] = 'is_combo';
        }
        $sign = Schema::hasColumn($table, 'type') ? "CASE WHEN d.type = 'sub' THEN -1 ELSE 1 END * " : '';

        return (float) DB::table($table.' as d')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'd.product_variant_id')
            ->where('d.'.$foreignKey, $documentId)
            ->where(function ($q) use ($excluded) {
                $q->whereNull('p.type')->orWhereNotIn('p.type', $excluded);
            })
            ->sum(DB::raw("{$sign}d.quantity * COALESCE(pv.cost, p.cost, 0)"));
    }

    protected static function hasPosted(string $referenceType, int $referenceId): bool
    {
        return (bool) self::safeReturn(function () use ($referenceType, $referenceId) {
            return Schema::hasTable('acc_journal_entries') && DB::table('acc_journal_entries')
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->exists();
        });
    }

    /**
     * Value of the stock on hand at cost — the same figure the Analytics
     * report shows as closing stock (by purchase price) for all warehouses.
     */
    public static function inventoryStockValue(): float
    {
        return (float) DB::table('product_warehouse as pw')
            ->join('products as p', 'p.id', '=', 'pw.product_id')
            ->join('warehouses as w', 'w.id', '=', 'pw.warehouse_id')
            ->leftJoin('product_variants as pv', function ($join) {
                $join->on('pw.product_variant_id', '=', 'pv.id')->whereNotNull('pw.product_variant_id');
            })
            ->whereNull('pw.deleted_at')
            ->whereNull('p.deleted_at')
            ->whereNull('w.deleted_at')
            ->where('p.type', '!=', 'is_service')
            ->sum(DB::raw('COALESCE(pv.cost, p.cost, 0) * pw.qte'));
    }

    /** Posted balance of the Inventory account (1200-INV). */
    public static function inventoryBookValue(): float
    {
        if (! Schema::hasTable('acc_journal_entry_lines')) {
            return 0.0;
        }

        return (float) DB::table('acc_journal_entry_lines as l')
            ->join('acc_journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->join('acc_chart_of_accounts as c', 'c.id', '=', 'l.coa_id')
            ->where('j.status', 'posted')
            ->where('c.code', '1200-INV')
            ->sum(DB::raw('l.debit - l.credit'));
    }

    /**
     * Bring the Inventory account to the value of the stock on hand with one
     * entry. The first true-up books stock that never went through accounting
     * (opening quantities, imports, adjustments made before inventory posting
     * existed) against Opening Balance Equity; a later one corrects drift
     * against Inventory Adjustments. Returns the amount posted (0 when the
     * account already matches).
     */
    public static function postInventoryTrueUp(): float
    {
        $invCoaId = self::getOrCreateCoa('1200-INV', 'Inventory', 'asset');
        $obeCoaId = self::getOrCreateCoa('3900-OBE', 'Opening Balance Equity', 'equity');
        $adjCoaId = self::getOrCreateCoa('5100-INVADJ', 'Inventory Adjustments', 'expense');

        // Posted even when automatic journals are off: this is a deliberate,
        // one-off action from the Balance Sheet. The Inventory account row is
        // locked first so two clicks at once cannot both post the same gap —
        // the second one waits, then sees the first entry and finds no gap.
        return DB::transaction(function () use ($invCoaId, $obeCoaId, $adjCoaId) {
            DB::table('acc_chart_of_accounts')->where('id', $invCoaId)->lockForUpdate()->first();

            $gap = round(self::inventoryStockValue() - self::inventoryBookValue(), 2);
            if (abs($gap) < 0.01) {
                return 0.0;
            }

            $first = ! DB::table('acc_journal_entries')->where('reference_type', 'inventory_true_up')->exists();
            $otherCoaId = $first ? $obeCoaId : $adjCoaId;
            $amount = abs($gap);
            $memo = $first ? 'Opening Balance' : ($gap > 0 ? 'Inventory Gain' : 'Inventory Loss');

            $journalId = DB::table('acc_journal_entries')->insertGetId([
                'date' => Carbon::now()->toDateString(),
                'reference_type' => 'inventory_true_up',
                'reference_id' => null,
                'description' => 'Inventory true-up to stock on hand',
                'status' => 'posted',
                'posted_at' => Carbon::now(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $lines = $gap > 0
                ? [[$invCoaId, $amount, 0, 'Inventory'], [$otherCoaId, 0, $amount, $memo]]
                : [[$otherCoaId, $amount, 0, $memo], [$invCoaId, 0, $amount, 'Inventory']];
            foreach ($lines as [$coaId, $debit, $credit, $lineMemo]) {
                DB::table('acc_journal_entry_lines')->insert([
                    'journal_entry_id' => $journalId,
                    'coa_id' => $coaId,
                    'account_id' => null,
                    'debit' => $debit,
                    'credit' => $credit,
                    'memo' => $lineMemo,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }

            return $gap;
        });
    }

    // ------------------------------------------------------------- assets --

    /**
     * Bring one fixed asset's ledger in line with the asset as it stands now.
     * Idempotent: safe to call after every save and from the daily run.
     *
     *  asset              Dr 1500-FA cost / Cr the paying account
     *                     (3900-OBE when none: already owned or paid elsewhere)
     *  asset_depreciation Dr 5300-DEPR / Cr 1590-ACCDEP, one entry per month
     *                     as it accrues (back-dated on catch-up)
     *  asset_disposal     clears cost + accumulated depreciation, books the
     *                     proceeds and the gain (4910-GAINDISP) or loss (5310-LOSSDISP)
     *
     * A deleted asset has everything reversed.
     */
    public static function syncAsset(int $assetId): void
    {
        self::safe(function () use ($assetId) {
            if (! Schema::hasTable('assets') || ! Schema::hasTable('acc_journal_entries')) {
                return;
            }
            $asset = \App\Models\Asset::find($assetId);
            if (! $asset) {
                return;
            }

            $live = $asset->deleted_at === null;
            $label = trim(($asset->tag ? $asset->tag.' ' : '').($asset->name ?? '')) ?: '#'.$assetId;
            $cost = round((float) $asset->purchase_cost, 2);

            $faCoa = self::getOrCreateCoa('1500-FA', 'Fixed Assets', 'asset');
            $accDepCoa = self::getOrCreateCoa('1590-ACCDEP', 'Accumulated Depreciation', 'asset');

            // 1. Acquisition
            $acquisition = [];
            if ($live && $cost > 0) {
                $paidFrom = (Schema::hasColumn('assets', 'account_id') && $asset->account_id)
                    ? self::getOrCreateCoaForLegacyAccount($asset->account_id) : null;
                $paidFrom = $paidFrom ?: self::getOrCreateCoa('3900-OBE', 'Opening Balance Equity', 'equity');
                $acquisition = [
                    ['coa_id' => $faCoa,    'debit' => $cost, 'credit' => 0,     'memo' => 'Fixed asset'],
                    ['coa_id' => $paidFrom, 'debit' => 0,     'credit' => $cost, 'memo' => 'Paid'],
                ];
            }
            self::syncReferenceTo('asset', $assetId, $acquisition, self::dateFrom($asset->purchase_date), 'Asset purchase '.$label);

            // 2. Depreciation (stops at the disposal date by itself)
            self::syncAssetDepreciation($asset, $live, $label, $accDepCoa);

            // 3. Disposal
            $disposal = [];
            if ($live && $asset->disposal_date) {
                $accDep = round($asset->accumulatedDepreciation($asset->disposal_date), 2);
                $proceeds = round((float) $asset->disposal_amount, 2);
                $gain = round($proceeds - ($cost - $accDep), 2);

                if ($proceeds > 0) {
                    $into = (Schema::hasColumn('assets', 'disposal_account_id') && $asset->disposal_account_id)
                        ? self::getOrCreateCoaForLegacyAccount($asset->disposal_account_id) : null;
                    $into = $into ?: self::getOrCreateCoa('1000-CASH', 'Cash', 'asset');
                    $disposal[] = ['coa_id' => $into, 'debit' => $proceeds, 'credit' => 0, 'memo' => 'Disposal proceeds'];
                }
                if ($accDep > 0) {
                    $disposal[] = ['coa_id' => $accDepCoa, 'debit' => $accDep, 'credit' => 0, 'memo' => 'Clear accumulated depreciation'];
                }
                if ($cost > 0) {
                    $disposal[] = ['coa_id' => $faCoa, 'debit' => 0, 'credit' => $cost, 'memo' => 'Remove asset cost'];
                }
                if ($gain > 0) {
                    $disposal[] = ['coa_id' => self::getOrCreateCoa('4910-GAINDISP', 'Gain on Asset Disposal', 'income'), 'debit' => 0, 'credit' => $gain, 'memo' => 'Gain on disposal'];
                } elseif ($gain < 0) {
                    $disposal[] = ['coa_id' => self::getOrCreateCoa('5310-LOSSDISP', 'Loss on Asset Disposal', 'expense'), 'debit' => abs($gain), 'credit' => 0, 'memo' => 'Loss on disposal'];
                }
                if (count($disposal) < 2) {
                    $disposal = []; // nothing on the books to clear
                }
            }
            self::syncReferenceTo('asset_disposal', $assetId, $disposal, self::dateFrom($asset->disposal_date), 'Asset disposal '.$label);
        });
    }

    /**
     * Post accumulated depreciation month by month up to today. Anything
     * already over-posted (cost lowered, method changed, asset deleted) is
     * taken back in one correcting entry dated today.
     */
    protected static function syncAssetDepreciation(\App\Models\Asset $asset, bool $live, string $label, int $accDepCoa): void
    {
        $assetId = (int) $asset->id;
        $posted = round(-self::netOnCoa('asset_depreciation', $assetId, $accDepCoa), 2); // credit balance
        $target = $live ? round($asset->accumulatedDepreciation(), 2) : 0.0;

        if (abs($target - $posted) < 0.01) {
            return;
        }

        $deprCoa = self::getOrCreateCoa('5300-DEPR', 'Depreciation Expense', 'expense');
        $post = function (float $amount, string $date) use ($assetId, $label, $deprCoa, $accDepCoa) {
            $amount = round($amount, 2);
            self::createBalancedEntry([
                'journal' => [
                    'date' => $date,
                    'description' => ($amount > 0 ? 'Depreciation ' : 'Depreciation correction ').$label,
                    'reference_type' => 'asset_depreciation',
                    'reference_id' => $assetId,
                ],
                'lines' => $amount > 0
                    ? [['coa_id' => $deprCoa, 'debit' => $amount, 'credit' => 0, 'memo' => 'Depreciation'],
                       ['coa_id' => $accDepCoa, 'debit' => 0, 'credit' => $amount, 'memo' => 'Accumulated depreciation']]
                    : [['coa_id' => $accDepCoa, 'debit' => abs($amount), 'credit' => 0, 'memo' => 'Accumulated depreciation'],
                       ['coa_id' => $deprCoa, 'debit' => 0, 'credit' => abs($amount), 'memo' => 'Depreciation']],
            ]);
        };

        if ($target < $posted) {
            $post($target - $posted, Carbon::now()->toDateString());

            return;
        }

        // Walk the elapsed months; each one whose running total passes what is
        // already on the books gets its own entry, dated on that month.
        $start = Carbon::parse($asset->purchase_date);
        $running = $posted;
        $months = $asset->monthsDepreciated();
        for ($m = 1; $m <= $months; $m++) {
            $date = $start->copy()->addMonthsNoOverflow($m);
            $cumulative = round($asset->accumulatedDepreciation($date), 2);
            if ($cumulative - $running >= 0.01) {
                $post($cumulative - $running, $date->toDateString());
                $running = $cumulative;
            }
        }
        // Rounding left over (or no schedule to walk): settle it today.
        if (abs($target - $running) >= 0.01) {
            $post($target - $running, Carbon::now()->toDateString());
        }
    }

    /** Net debit (debit − credit) posted on one account for one reference. */
    protected static function netOnCoa(string $referenceType, int $referenceId, int $coaId): float
    {
        return (float) DB::table('acc_journal_entries as j')
            ->join('acc_journal_entry_lines as l', 'l.journal_entry_id', '=', 'j.id')
            ->where('j.status', 'posted')
            ->where('j.reference_type', $referenceType)
            ->where('j.reference_id', $referenceId)
            ->where('l.coa_id', $coaId)
            ->sum(DB::raw('l.debit - l.credit'));
    }

    /**
     * Make what is posted under a reference equal $lines (empty = nothing).
     * Unchanged amounts and date → no-op; otherwise reverse and post afresh.
     */
    protected static function syncReferenceTo(string $referenceType, int $referenceId, array $lines, string $date, string $desc): void
    {
        $posted = DB::table('acc_journal_entries as j')
            ->join('acc_journal_entry_lines as l', 'l.journal_entry_id', '=', 'j.id')
            ->where('j.status', 'posted')
            ->where('j.reference_type', $referenceType)
            ->where('j.reference_id', $referenceId)
            ->groupBy('l.coa_id')
            ->selectRaw('l.coa_id, SUM(l.debit) - SUM(l.credit) as net')
            ->pluck('net', 'coa_id')
            ->map(fn ($n) => round((float) $n, 2))
            ->filter(fn ($n) => abs($n) >= 0.01)
            ->all();

        $wanted = [];
        foreach ($lines as $l) {
            $wanted[$l['coa_id']] = round(($wanted[$l['coa_id']] ?? 0) + (float) $l['debit'] - (float) $l['credit'], 2);
        }
        $wanted = array_filter($wanted, fn ($n) => abs($n) >= 0.01);

        ksort($posted);
        ksort($wanted);
        $sameAmounts = $posted == $wanted;
        // The live posting is the newest entry that is not a reversal.
        $latest = DB::table('acc_journal_entries')
            ->where('status', 'posted')
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('description', 'not like', 'Reverse %')
            ->orderByDesc('id')
            ->value('date');
        $sameDate = empty($wanted) || ($latest && Carbon::parse($latest)->toDateString() === $date);
        if ($sameAmounts && $sameDate) {
            return;
        }

        self::reverseByReference($referenceType, $referenceId, 'Reverse '.$desc);
        if (! empty($wanted)) {
            self::createBalancedEntry([
                'journal' => [
                    'date' => $date,
                    'description' => $desc,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                ],
                'lines' => $lines,
            ]);
        }
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
            Log::error('[AccountingV2] '.$e->getMessage(), [
                'exception' => get_class($e),
                'at' => $e->getFile().':'.$e->getLine(),
            ]);
        }
    }

    protected static function safeReturn(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::error('[AccountingV2] '.$e->getMessage(), [
                'exception' => get_class($e),
                'at' => $e->getFile().':'.$e->getLine(),
            ]);

            return null;
        }
    }
}
