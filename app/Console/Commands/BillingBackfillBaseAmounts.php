<?php

namespace App\Console\Commands;

use App\Models\Central\GeneralSetting;
use App\Models\Central\TenantBillingPayment;
use App\Services\Billing\PlanPricing;
use Illuminate\Console\Command;

/**
 * Fills tenant_billing_payments.base_amount (value in the main currency) for
 * paid payments that lack it — e.g. paid while the exchange-rate APIs were
 * down. Uses today's rate for those; main-currency payments copy their amount.
 */
class BillingBackfillBaseAmounts extends Command
{
    protected $signature = 'billing:backfill-base-amounts {--dry-run : Report only, change nothing}';

    protected $description = 'Fill the main-currency value of paid billing payments that lack it';

    public function handle(): int
    {
        if (! TenantBillingPayment::hasBaseColumns()) {
            $this->warn('Run the multi-currency migration first (2026_11_15_000001).');

            return self::SUCCESS;
        }

        $main = strtoupper((string) GeneralSetting::currencyCode());
        $filled = 0;
        $failed = 0;

        TenantBillingPayment::where('status', TenantBillingPayment::STATUS_PAID)
            ->whereNull('base_amount')
            ->orderBy('id')
            ->chunkById(200, function ($payments) use ($main, &$filled, &$failed) {
                foreach ($payments as $payment) {
                    $currency = strtoupper((string) ($payment->currency ?: $main));
                    try {
                        $rate = $currency === $main ? 1.0 : PlanPricing::rate($currency, $main);
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->error("#{$payment->id}: no {$currency}→{$main} rate ({$e->getMessage()})");
                        continue;
                    }
                    $base = round((float) $payment->amount * $rate, 2);
                    $this->line("#{$payment->id}: {$payment->amount} {$currency} → {$base} {$main}");
                    if (! $this->option('dry-run')) {
                        $payment->base_amount = $base;
                        $payment->base_currency = $main;
                        $payment->base_rate = $rate;
                        $payment->saveQuietly();
                    }
                    $filled++;
                }
            });

        $this->info(($this->option('dry-run') ? 'Would fill' : 'Filled') . " {$filled}, failed {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
