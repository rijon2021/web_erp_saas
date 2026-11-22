<?php

namespace App\Services\Backup;

use App\Models\Central\Plan;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Deletes a tenant's expired housekeeping data under its plan's retention
 * policy (Plan::RETENTION_FIELDS). Call while tenancy is initialised.
 *
 * Only the tables listed in CATEGORIES are ever touched. Everything else is
 * kept for good — in particular all business records (sales, purchases,
 * returns, payments, expenses, accounting, stock and stock movements,
 * products, customers, suppliers) and these look-alikes, on purpose:
 *  - zatca_logs, fbr_logs ............ tax-authority submission records
 *  - protocol_request_events ......... workflow history of a business record
 *  - user_login_sessions ............. a deleted *revoked* row would let that
 *                                      signed-out device's token back in
 *                                      (EnforceApiTokenTimeout recreates it)
 *  - whatsapp_usages ................. the monthly WhatsApp quota counter
 *  - email_messages, sms_messages .... message templates, not history
 */
class TenantDataPruner
{
    /**
     * plan setting => [table, date column, extra conditions]
     *
     * Conditions keep in-flight rows: a webhook still being retried, a sync
     * that hasn't finished, an unread contact-form message.
     */
    public const CATEGORIES = [
        'log_retention_days' => [
            ['error_logs', 'occurred_at', []],
            ['woocommerce_logs', 'created_at', []],
            ['shopify_logs', 'created_at', []],
            ['salla_logs', 'created_at', []],
            ['xero_logs', 'created_at', []],
            ['prestashop_logs', 'created_at', []],
            ['opencart_logs', 'created_at', []],
            ['google_sheet_logs', 'created_at', []],
            ['mailchimp_logs', 'created_at', []],
            ['jumia_logs', 'created_at', []],
            ['courier_logs', 'created_at', []],
            ['quick_books_audits', 'created_at', []],
            ['webhook_deliveries', 'created_at', ['status_not' => 'pending']],
            ['webhook_incoming_logs', 'created_at', []],
            ['shopify_webhook_events', 'created_at', ['status_not' => 'pending']],
            ['sync_jobs', 'created_at', ['not_null' => 'finished_at']],
            ['shopify_sync_runs', 'created_at', ['not_null' => 'finished_at']],
            ['meeting_activity_logs', 'created_at', []],
            ['marketing_activity_logs', 'created_at', []],
        ],
        'audit_retention_days' => [
            ['audit_logs', 'created_at', []],
        ],
        'message_retention_days' => [
            ['notifications', 'created_at', []],
            ['whatsapp_logs', 'created_at', []],
            ['messages', 'created_at', ['equals' => ['is_read', 1]]],
        ],
        'temp_retention_days' => [
            // POS drafts go with their lines — see pruneDrafts().
            ['password_resets', 'created_at', []],
            ['oauth_access_tokens', 'expires_at', []],
            ['oauth_access_tokens', 'updated_at', ['equals' => ['revoked', 1]]],
            ['oauth_refresh_tokens', 'expires_at', []],
            ['oauth_auth_codes', 'expires_at', []],
            ['failed_jobs', 'failed_at', []],
        ],
    ];

    /** @var array<string, bool> */
    private array $tableExists = [];

    public function __construct(private ?int $chunk = null)
    {
        // At least 1: a 0 or negative LIMIT would make the delete loop spin forever.
        $this->chunk = max(1, $this->chunk ?? (int) config('retention.prune_chunk', 1000));
    }

    /**
     * @return array<string, int> rows deleted (or, dry run, that would be) per table
     */
    public function prune(Plan $plan, bool $dryRun = false): array
    {
        $result = [];

        foreach (self::CATEGORIES as $setting => $rules) {
            $days = (int) $plan->retentionSetting($setting);
            if ($days <= 0) {
                continue; // keep forever
            }
            $cutoff = now()->subDays($days);

            foreach ($rules as [$table, $column, $conditions]) {
                if (! $this->hasTable($table)) {
                    continue;
                }

                try {
                    $n = $this->pruneTable($table, $column, $conditions, $cutoff, $dryRun);
                } catch (\Throwable $e) {
                    // A tenant DB behind on migrations (missing column) costs
                    // that table, not the rest of the run.
                    Log::warning("tenants:prune-data skipped {$table}: ".$e->getMessage(), ['tenant' => tenant('id')]);

                    continue;
                }

                if ($n > 0) {
                    $result[$table] = ($result[$table] ?? 0) + $n;
                }
            }

            if ($setting === 'temp_retention_days') {
                try {
                    foreach ($this->pruneDrafts($cutoff, $dryRun) as $table => $n) {
                        $result[$table] = ($result[$table] ?? 0) + $n;
                    }
                } catch (\Throwable $e) {
                    Log::warning('tenants:prune-data skipped draft_sales: '.$e->getMessage(), ['tenant' => tenant('id')]);
                }
            }
        }

        return $result;
    }

    private function pruneTable(string $table, string $column, array $conditions, $cutoff, bool $dryRun): int
    {
        $query = fn (): Builder => $this->applyConditions(
            DB::table($table)->where($column, '<', $cutoff),
            $conditions
        );

        if ($dryRun) {
            return $query()->count();
        }

        $total = 0;
        do {
            // DELETE ... LIMIT n: short statements, no long lock on a busy table.
            $deleted = $query()->limit($this->chunk)->delete();
            $total += $deleted;
        } while ($deleted >= $this->chunk);

        return $total;
    }

    private function applyConditions(Builder $query, array $conditions): Builder
    {
        if (isset($conditions['status_not'])) {
            $query->where('status', '!=', $conditions['status_not']);
        }
        if (isset($conditions['not_null'])) {
            $query->whereNotNull($conditions['not_null']);
        }
        if (isset($conditions['equals'])) {
            [$col, $value] = $conditions['equals'];
            $query->where($col, $value);
        }

        return $query;
    }

    /**
     * POS drafts (held sales) older than the cutoff, lines first.
     *
     * @return array<string, int>
     */
    private function pruneDrafts($cutoff, bool $dryRun): array
    {
        if (! $this->hasTable('draft_sales') || ! $this->hasTable('draft_sale_details')) {
            return [];
        }

        $old = fn () => DB::table('draft_sales')->where('created_at', '<', $cutoff);

        if ($dryRun) {
            return array_filter([
                'draft_sale_details' => DB::table('draft_sale_details')->whereIn('draft_sale_id', $old()->select('id'))->count(),
                'draft_sales' => $old()->count(),
            ]);
        }

        $counts = ['draft_sale_details' => 0, 'draft_sales' => 0];
        while (($ids = $old()->orderBy('id')->limit($this->chunk)->pluck('id'))->isNotEmpty()) {
            DB::transaction(function () use ($ids, &$counts) {
                $counts['draft_sale_details'] += DB::table('draft_sale_details')->whereIn('draft_sale_id', $ids)->delete();
                $counts['draft_sales'] += DB::table('draft_sales')->whereIn('id', $ids)->delete();
            });
        }

        return array_filter($counts);
    }

    private function hasTable(string $table): bool
    {
        return $this->tableExists[$table] ??= Schema::hasTable($table);
    }
}
