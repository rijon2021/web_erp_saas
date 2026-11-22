<?php

namespace App\Console\Commands;

use App\Models\OnlineOrder;
use App\Models\Sale;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-off repair: online orders whose payment_status fell behind the Sale they
 * are linked to, because the payment predates the Sale::updated sync (it only
 * fires when the sale's status changes).
 *
 * Only moves an order forward (pending -> partial -> paid). An order can be
 * ahead of its sale on purpose (an approved payment proof marks the order paid
 * without touching the sale), so those are left alone and listed for a person
 * to review. Refunded and failed orders are not touched.
 */
class SyncOnlineOrderPaymentStatus extends Command
{
    private const RANK = ['pending' => 0, 'partial' => 1, 'paid' => 2];

    protected $signature = 'online-orders:sync-payment-status
        {--tenant= : Only this tenant id}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Bring online order payment statuses up to date with their linked sales';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenants = \App\Tenant::query()
            ->when($this->option('tenant'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        foreach ($tenants as $tenant) {
            try {
                tenancy()->initialize($tenant);

                if (! Schema::hasTable('online_orders') || ! Schema::hasColumn('online_orders', 'sale_id')) {
                    $this->warn("tenant {$tenant->id}: online_orders.sale_id missing, run the tenant migrations first");

                    continue;
                }

                $result = $this->syncCurrentTenant($dryRun);

                if ($result['updated'] || $result['review'] || $result['unlinked']) {
                    $verb = $dryRun ? 'would update' : 'updated';
                    $this->line("tenant {$tenant->id}: {$verb} ".count($result['updated']).', '.count($result['review']).' to review, '.$result['unlinked'].' not linked to a sale');
                    foreach ($result['updated'] as $line) {
                        $this->line("  {$line}");
                    }
                    foreach ($result['review'] as $line) {
                        $this->warn("  review: {$line}");
                    }
                }
            } catch (\Throwable $e) {
                $this->warn("tenant {$tenant->id}: ".$e->getMessage());
                report($e);
            } finally {
                try {
                    tenancy()->end();
                } catch (\Throwable $e) {
                    // Nothing to clean up.
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array{updated: string[], review: string[], unlinked: int}
     */
    public function syncCurrentTenant(bool $dryRun): array
    {
        $rows = DB::table('online_orders as o')
            ->join('sales as s', 's.id', '=', 'o.sale_id')
            ->whereNull('s.deleted_at')
            ->whereIn('o.payment_status', array_keys(self::RANK))
            ->get(['o.id', 'o.ref', 'o.payment_status', 's.Ref as sale_ref', 's.payment_statut', 's.GrandTotal', 's.paid_amount']);

        $updated = [];
        $review = [];

        foreach ($rows as $row) {
            $byAmount = Sale::paymentStatusFor($row->GrandTotal, $row->paid_amount);
            if ($byAmount !== $row->payment_statut) {
                $review[] = "sale {$row->sale_ref} is {$row->payment_statut} but its amounts say {$byAmount} (order {$row->ref})";
            }

            $target = OnlineOrder::paymentStatusFromSale($row->payment_statut);
            if ($target === $row->payment_status) {
                continue;
            }

            if (self::RANK[$target] < self::RANK[$row->payment_status]) {
                $review[] = "order {$row->ref} is {$row->payment_status} but sale {$row->sale_ref} is {$row->payment_statut}";

                continue;
            }

            // A plain update, like the sync itself: going through the model
            // would fire the "paid" hooks (Printful, kitchen) on old orders.
            if (! $dryRun) {
                OnlineOrder::whereKey($row->id)->update(['payment_status' => $target]);
            }
            $updated[] = "order {$row->ref}: {$row->payment_status} -> {$target}";
        }

        $unlinked = DB::table('online_orders')
            ->whereNull('sale_id')
            ->whereIn('status', ['confirmed', 'shipped', 'delivered'])
            ->count();

        return ['updated' => $updated, 'review' => $review, 'unlinked' => $unlinked];
    }
}
