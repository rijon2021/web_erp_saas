<?php

namespace App\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * NEW FEATURE - SAFE ADDITION
 *
 * Modular provider for Advanced Accounting (V2). Registers observers and console commands.
 * Does not modify legacy modules.
 */
class AccountingV2ServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Merge default config
        $this->mergeConfigFrom(
            base_path('config/accounting_v2.php'), 'accounting_v2'
        );

        // Register commands here to avoid touching Console Kernel
        $this->commands([
            \App\Console\Commands\AccountingCloseYear::class,
        ]);
    }

    public function boot()
    {
        if (! Config::get('accounting_v2.enabled', true)) {
            return; // allow disabling without impacting legacy modules
        }

        $this->registerModelObservers();
    }

    protected function registerModelObservers(): void
    {
        // Softly dispatch domain events from model lifecycle without touching controllers
        try {
            // Documents: sales, purchases, expenses and both kinds of return.
            $this->observeDocument(\App\Models\Sale::class, 'GrandTotal',
                \App\Events\SaleCreated::class, \App\Events\SaleUpdated::class, \App\Events\SaleDeleted::class);
            $this->observeDocument(\App\Models\Purchase::class, 'GrandTotal',
                \App\Events\PurchaseCreated::class, \App\Events\PurchaseUpdated::class, \App\Events\PurchaseDeleted::class);
            // An expense re-posts when its paying account or date moves too,
            // not only its amount.
            $this->observeDocument(\App\Models\Expense::class, ['amount', 'account_id', 'date'],
                \App\Events\ExpenseCreated::class, \App\Events\ExpenseUpdated::class, \App\Events\ExpenseDeleted::class);
            $this->observeDocument(\App\Models\SaleReturn::class, 'GrandTotal',
                \App\Events\SaleReturned::class, \App\Events\SaleReturnUpdated::class, \App\Events\SaleReturnDeleted::class);
            $this->observeDocument(\App\Models\PurchaseReturn::class, 'GrandTotal',
                \App\Events\PurchaseReturnCreated::class, \App\Events\PurchaseReturnUpdated::class, \App\Events\PurchaseReturnDeleted::class);
            // Dispatch PaymentCreated when a PaymentSale is created
            if (class_exists(\App\Models\PaymentSale::class)) {
                \App\Models\PaymentSale::created(function ($payment) {
                    try {
                        event(new \App\Events\PaymentCreated($payment));
                    } catch (\Throwable $e) {
                        Log::error('[AccountingV2] PaymentCreated dispatch failed: '.$e->getMessage(), [
                            'exception' => get_class($e),
                            'at' => $e->getFile().':'.$e->getLine(),
                        ]);
                    }
                });
                \App\Models\PaymentSale::updated(function ($payment) {
                    try {
                        if (method_exists($payment, 'wasChanged') && $payment->wasChanged('deleted_at') && ! is_null($payment->deleted_at)) {
                            event(new \App\Events\PaymentDeleted($payment));
                        }
                    } catch (\Throwable $e) {
                        Log::error('[AccountingV2] PaymentDeleted dispatch failed: '.$e->getMessage(), [
                            'exception' => get_class($e),
                            'at' => $e->getFile().':'.$e->getLine(),
                        ]);
                    }
                });
            }
            if (class_exists(\App\Models\PaymentPurchase::class)) {
                \App\Models\PaymentPurchase::created(function ($payment) {
                    try {
                        event(new \App\Events\PaymentPurchaseCreated($payment));
                    } catch (\Throwable $e) {
                        Log::error('[AccountingV2] PaymentPurchaseCreated dispatch failed: '.$e->getMessage(), [
                            'exception' => get_class($e),
                            'at' => $e->getFile().':'.$e->getLine(),
                        ]);
                    }
                });
                \App\Models\PaymentPurchase::updated(function ($payment) {
                    try {
                        if (method_exists($payment, 'wasChanged') && $payment->wasChanged('deleted_at') && ! is_null($payment->deleted_at)) {
                            event(new \App\Events\PaymentPurchaseDeleted($payment));
                        }
                    } catch (\Throwable $e) {
                        Log::error('[AccountingV2] PaymentPurchaseDeleted dispatch failed: '.$e->getMessage(), [
                            'exception' => get_class($e),
                            'at' => $e->getFile().':'.$e->getLine(),
                        ]);
                    }
                });
            }
        } catch (\Throwable $e) {
            Log::error('[AccountingV2] Observer registration failed: '.$e->getMessage(), [
                'exception' => get_class($e),
                'at' => $e->getFile().':'.$e->getLine(),
            ]);
        }
    }

    /**
     * Wire a document model's lifecycle to its created / updated / deleted events.
     *
     * Two things the naive model hooks got wrong:
     *  - Controllers save the header row first and insert its detail lines
     *    afterwards, inside one transaction. Dispatching on `created` therefore
     *    ran the journal listeners before any lines existed, so COGS came out
     *    as zero. Events are now dispatched after the transaction commits (or
     *    at once when there is none), when the document is complete.
     *  - These models are not SoftDeletes: controllers delete by writing
     *    `deleted_at`, which fires `updated`, never `deleted`. A newly set
     *    `deleted_at` is treated as the delete, as the payment hooks already do.
     */
    protected function observeDocument(string $model, string|array $totalColumn, string $created, string $updated, string $deleted): void
    {
        if (! class_exists($model)) {
            return;
        }

        $model::created(function ($doc) use ($created) {
            $this->dispatchAfterCommit($doc, $created);
        });

        $model::updated(function ($doc) use ($totalColumn, $updated, $deleted) {
            if ($doc->wasChanged('deleted_at') && ! is_null($doc->deleted_at)) {
                $this->dispatchAfterCommit($doc, $deleted);
            } elseif ($doc->wasChanged($totalColumn)) {
                $this->dispatchAfterCommit($doc, $updated);
            }
        });

        $model::deleted(function ($doc) use ($deleted) {
            $this->dispatchAfterCommit($doc, $deleted);
        });
    }

    protected function dispatchAfterCommit($doc, string $eventClass): void
    {
        $dispatch = function () use ($doc, $eventClass) {
            try {
                event(new $eventClass($doc));
            } catch (\Throwable $e) {
                Log::error('[AccountingV2] '.class_basename($eventClass).' dispatch failed: '.$e->getMessage(), [
                    'exception' => get_class($e),
                    'at' => $e->getFile().':'.$e->getLine(),
                ]);
            }
        };

        try {
            $doc->getConnection()->afterCommit($dispatch);
        } catch (\RuntimeException $e) {
            // No transactions manager on this connection; nothing to wait for.
            $dispatch();
        }
    }
}
