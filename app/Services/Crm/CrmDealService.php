<?php

namespace App\Services\Crm;

use App\Models\CrmDeal;
use App\Models\CrmDealEvent;
use App\Models\CrmDealProduct;
use App\Models\CrmLead;
use App\Models\CrmPipelineStage;
use App\Models\CrmSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deal rules shared by the CRM controllers: visibility (same rule as leads),
 * numbering, product lines and the deal value, moving on the board, history.
 */
class CrmDealService
{
    public function __construct(private CrmLeadService $leads)
    {
    }

    /** Own deals + unassigned ones, or every deal with crm_view_all. */
    public function visible(?User $user, ?Builder $query = null): Builder
    {
        $query = $query ?? CrmDeal::query();
        $query->whereNull('crm_deals.deleted_at');
        if ($user && ! $user->can('viewAll', CrmLead::class)) {
            $query->where(fn ($q) => $q->where('crm_deals.owner_id', $user->id)->orWhereNull('crm_deals.owner_id'));
        }

        return $query;
    }

    public function canSee(?User $user, CrmDeal $deal): bool
    {
        return $user && ($user->can('viewAll', CrmLead::class) || ! $deal->owner_id || (int) $deal->owner_id === (int) $user->id);
    }

    /** Next DL-0001 style number; call inside a transaction (the settings row is locked). */
    public function nextNumber(): string
    {
        if (DB::transactionLevel() > 0) {
            DB::table('crm_settings')->orderBy('id')->limit(1)->lockForUpdate()->get(['id']);
        }
        $prefix = trim((string) CrmSetting::current()->deal_prefix) ?: 'DL';

        $last = CrmDeal::withTrashed()->where('deal_number', 'LIKE', $prefix . '-%')->pluck('deal_number')
            ->map(fn ($n) => (int) substr($n, strlen($prefix) + 1))->max();

        return $prefix . '-' . str_pad((string) (($last ?: 0) + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Replace a deal's product lines. When there are lines, the deal value is
     * their total; with none, the manual value stays.
     */
    public function saveLines(CrmDeal $deal, array $lines): void
    {
        $deal->products()->delete();
        $total = 0.0;
        foreach (array_values($lines) as $i => $l) {
            $qty = max(0.001, (float) ($l['quantity'] ?? 1));
            $price = max(0, (float) ($l['unit_price'] ?? 0));
            $disc = min(100, max(0, (float) ($l['discount_percent'] ?? 0)));
            $tax = min(100, max(0, (float) ($l['tax_percent'] ?? 0)));
            $method = (int) ($l['tax_method'] ?? 1) === 2 ? 2 : 1;
            $lineTotal = CrmDealProduct::lineTotal($qty, $price, $disc, $tax, $method);
            $total += $lineTotal;
            CrmDealProduct::create([
                'crm_deal_id' => $deal->id,
                'product_id' => $l['product_id'] ?? null,
                'product_variant_id' => $l['product_variant_id'] ?? null,
                'name' => mb_substr(trim((string) ($l['name'] ?? '')) ?: 'Item', 0, 191),
                'code' => isset($l['code']) ? mb_substr((string) $l['code'], 0, 191) : null,
                'quantity' => $qty,
                'unit_price' => $price,
                'discount_percent' => $disc,
                'tax_percent' => $tax,
                'tax_method' => $method,
                'total' => $lineTotal,
                'sort_order' => $i + 1,
            ]);
        }
        $deal->update($lines
            ? ['value' => round($total, 2), 'value_from_products' => true]
            : ['value_from_products' => false]);
    }

    /**
     * Put an open deal in a stage at a position (0-based) of that column,
     * shifting the other cards. Logs a stage change.
     */
    public function moveTo(CrmDeal $deal, CrmPipelineStage $stage, ?int $position = null): void
    {
        $fromStage = $deal->crm_pipeline_stage_id;
        $column = CrmDeal::whereNull('deleted_at')->where('status', 'open')
            ->where('crm_pipeline_stage_id', $stage->id)->where('id', '!=', $deal->id)
            ->orderBy('position')->orderBy('id')->pluck('id')->all();
        $position = $position === null ? count($column) : max(0, min($position, count($column)));
        array_splice($column, $position, 0, [$deal->id]);

        foreach ($column as $i => $id) {
            if ((int) $id !== (int) $deal->id) {
                CrmDeal::whereKey($id)->update(['position' => $i]);
            }
        }
        $changes = ['crm_pipeline_stage_id' => $stage->id, 'crm_pipeline_id' => $stage->crm_pipeline_id, 'position' => $position];
        if ((int) $fromStage !== (int) $stage->id) {
            $changes['stage_entered_at'] = now();
            $changes['last_activity_at'] = now();
        }
        $deal->update($changes);

        if ((int) $fromStage !== (int) $stage->id) {
            $this->log($deal, 'stage_changed', null, [
                'from' => optional(CrmPipelineStage::withTrashed()->find($fromStage))->name,
                'to' => $stage->name,
            ]);
        }
    }

    public function log(CrmDeal $deal, string $type, ?string $note = null, array $meta = []): void
    {
        $user = auth()->user();
        CrmDealEvent::create([
            'crm_deal_id' => $deal->id,
            'type' => $type,
            'note' => $note,
            'meta' => $meta ?: null,
            'user_id' => $user instanceof User ? $user->id : null,
        ]);
    }

    /** A line on the lead's own history (deal created / won / lost). */
    public function logOnLead(CrmDeal $deal, string $type, array $meta = []): void
    {
        if ($deal->crm_lead_id && ($lead = CrmLead::find($deal->crm_lead_id))) {
            $this->leads->log($lead, $type, null, array_merge(['deal_id' => $deal->id, 'deal' => $deal->deal_number, 'title' => $deal->title], $meta));
            $lead->update(['last_activity_at' => now()]);
        }
    }

    public function tagIds(array $values): array
    {
        return $this->leads->tagIds($values);
    }
}
