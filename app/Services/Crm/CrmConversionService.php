<?php

namespace App\Services\Crm;

use App\Http\Controllers\QuotationsController;
use App\Models\Client;
use App\Models\CrmActivity;
use App\Models\CrmContact;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmLeadStatus;
use App\Models\CrmPipelineStage;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationDetail;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where the CRM meets the rest of Stocky:
 *  - a lead becomes a customer (a new clients row, or an existing one);
 *  - a deal becomes a quotation (catalogue lines, same maths as the quotation form);
 *  - converting that quotation to a sale wins the deal.
 */
class CrmConversionService
{
    public function __construct(private CrmLeadService $leads, private CrmDealService $deals)
    {
    }

    /**
     * $opts: mode new|existing, client_id (existing), deal (optional:
     * title, crm_pipeline_stage_id, value). Returns [client, deal|null].
     */
    public function convertLead(CrmLead $lead, array $opts, User $user): array
    {
        return DB::transaction(function () use ($lead, $opts, $user) {
            $lead = CrmLead::whereKey($lead->id)->lockForUpdate()->first();
            if ($lead->client_id) {
                abort(response()->json(['message' => 'This lead was already converted.'], 422));
            }

            if (($opts['mode'] ?? 'new') === 'existing') {
                $client = Client::whereNull('deleted_at')->find((int) ($opts['client_id'] ?? 0));
                if (! $client) {
                    abort(response()->json(['message' => 'Choose the customer to link this lead to.', 'errors' => ['client_id' => ['Required.']]], 422));
                }
            } else {
                $client = $this->createClient($lead);
            }

            // Company leads: the person becomes the customer's first contact.
            $person = trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? ''));
            if ($lead->type === 'company' && $person !== '') {
                $hasPrimary = CrmContact::whereNull('deleted_at')->where('client_id', $client->id)->where('is_primary', true)->exists();
                CrmContact::create([
                    'client_id' => $client->id,
                    'name' => mb_substr($person, 0, 191),
                    'job_title' => $lead->job_title,
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                    'is_primary' => ! $hasPrimary,
                    'created_by' => $user->id,
                ]);
            }

            $converted = CrmLeadStatus::firstOfKind('converted');
            $lead->update([
                'client_id' => $client->id,
                'converted_at' => now(),
                'crm_lead_status_id' => $converted ? $converted->id : $lead->crm_lead_status_id,
                'crm_lost_reason_id' => null, 'lost_note' => null, 'lost_at' => null,
                'last_activity_at' => now(),
            ]);
            // Everything already hanging off the lead now also belongs to the customer.
            CrmDeal::where('crm_lead_id', $lead->id)->whereNull('client_id')->update(['client_id' => $client->id]);
            CrmActivity::where('crm_lead_id', $lead->id)->whereNull('client_id')->update(['client_id' => $client->id]);

            $this->leads->log($lead, 'converted', null, [
                'client_id' => $client->id, 'client' => $client->name, 'mode' => $opts['mode'] ?? 'new',
            ]);

            $deal = null;
            if (! empty($opts['deal']['title']) && ! empty($opts['deal']['crm_pipeline_stage_id'])) {
                $stage = CrmPipelineStage::findOrFail((int) $opts['deal']['crm_pipeline_stage_id']);
                $deal = CrmDeal::create([
                    'deal_number' => $this->deals->nextNumber(),
                    'title' => mb_substr(trim($opts['deal']['title']), 0, 191),
                    'crm_pipeline_id' => $stage->crm_pipeline_id,
                    'crm_pipeline_stage_id' => $stage->id,
                    'status' => 'open',
                    'crm_lead_id' => $lead->id,
                    'client_id' => $client->id,
                    'owner_id' => $lead->owner_id ?: $user->id,
                    'value' => round((float) ($opts['deal']['value'] ?? $lead->estimated_value), 2),
                    'position' => (int) CrmDeal::where('crm_pipeline_stage_id', $stage->id)->where('status', 'open')->max('position') + 1,
                    'stage_entered_at' => now(),
                    'last_activity_at' => now(),
                    'created_by' => $user->id,
                ]);
                $this->deals->log($deal, 'created');
                $this->deals->logOnLead($deal, 'deal_created');
            }

            return [$client, $deal];
        });
    }

    /** A new clients row from the lead (same numbering and uniqueness rules as Customers). */
    private function createClient(CrmLead $lead): Client
    {
        // SaaS: a new customer counts against the plan's max_customers.
        \App\Support\PlanLimitGuard::abortIfReached('max_customers');
        $email = $lead->email ? mb_strtolower(trim($lead->email)) : null;
        if ($email) {
            $clash = Client::whereNull('deleted_at')->whereRaw('LOWER(email) = ?', [$email])->first(['id', 'name']);
            if ($clash) {
                abort(response()->json([
                    'message' => "A customer with this email already exists ({$clash->name}). Link the lead to that customer instead.",
                    'existing_client' => ['id' => $clash->id, 'name' => $clash->name],
                ], 422));
            }
            if (Schema::hasTable('ecommerce_clients')
                && DB::table('ecommerce_clients')->whereNull('deleted_at')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                // The address belongs to an online-store account; keep the customer, drop the email.
                $email = null;
            }
        }

        $person = $lead->type === 'person';

        return Client::create([
            'name' => mb_substr($lead->name, 0, 191),
            'firstname' => $person ? $lead->first_name : null,
            'lastname' => $person ? $lead->last_name : null,
            'code' => (int) DB::table('clients')->lockForUpdate()->max('code') + 1,
            'email' => $email,
            'phone' => $lead->phone,
            'adresse' => $lead->address,
            'city' => $lead->city,
            'country' => $lead->country,
            'opening_balance' => 0,
            'credit_limit' => 0,
        ]);
    }

    /**
     * Create a Stocky quotation from a deal. Catalogue lines become
     * quotation lines (Stocky's line maths: discount_method 1 = percent);
     * custom lines (no product) can't be quotation lines, so they are listed
     * in the notes. $opts: warehouse_id, tax_rate, discount, shipping, notes.
     *
     * @return array [Quotation, int custom lines moved to the notes]
     */
    public function createQuotation(CrmDeal $deal, array $opts, User $user): array
    {
        return DB::transaction(function () use ($deal, $opts, $user) {
            $deal = CrmDeal::with('products')->whereKey($deal->id)->lockForUpdate()->first();
            if (! $deal->client_id) {
                abort(response()->json(['message' => 'Convert the lead to a customer first: a quotation is made out to a customer.', 'needs_customer' => true], 422));
            }
            if ($deal->quotation_id && Quotation::whereNull('deleted_at')->whereKey($deal->quotation_id)->exists()) {
                abort(response()->json(['message' => 'This deal already has a quotation. Open or delete it first.'], 422));
            }
            $lines = $deal->products->filter(fn ($l) => $l->product_id);
            $custom = $deal->products->filter(fn ($l) => ! $l->product_id);
            if ($lines->isEmpty()) {
                abort(response()->json(['message' => 'Add at least one product from your catalogue to the deal first.'], 422));
            }

            $units = Product::whereIn('id', $lines->pluck('product_id'))->pluck('unit_sale_id', 'id');
            $total = round((float) $lines->sum('total'), 2);
            $discount = max(0, round((float) ($opts['discount'] ?? 0), 2));
            $taxRate = min(100, max(0, (float) ($opts['tax_rate'] ?? 0)));
            $shipping = max(0, round((float) ($opts['shipping'] ?? 0), 2));
            $taxNet = round(($total - $discount) * $taxRate / 100, 6);
            $grand = round($total - $discount + $taxNet + $shipping, 2);

            $notes = array_filter([
                trim((string) ($opts['notes'] ?? '')),
                'From CRM deal ' . $deal->deal_number . ': ' . $deal->title,
                $custom->isNotEmpty()
                    ? "Other items (not in the catalogue):\n" . $custom->map(fn ($l) => '- ' . $l->name . ' (' . (float) $l->quantity . ' x ' . number_format((float) $l->unit_price, 2) . ' = ' . number_format((float) $l->total, 2) . ')')->implode("\n")
                    : null,
            ]);

            $quotation = Quotation::create([
                'date' => now()->toDateString(),
                'time' => now()->toTimeString(),
                'Ref' => app(QuotationsController::class)->getNumberOrder(),
                'client_id' => $deal->client_id,
                'warehouse_id' => (int) $opts['warehouse_id'],
                'user_id' => $user->id,
                'tax_rate' => $taxRate,
                'TaxNet' => $taxNet,
                'discount' => $discount,
                'shipping' => $shipping,
                'GrandTotal' => $grand,
                'statut' => 'pending',
                'source' => 'crm',
                'notes' => implode("\n\n", $notes),
            ]);
            foreach ($lines as $l) {
                QuotationDetail::create([
                    'quotation_id' => $quotation->id,
                    'product_id' => $l->product_id,
                    'product_variant_id' => $l->product_variant_id,
                    'sale_unit_id' => $units[$l->product_id] ?? null,
                    'quantity' => (float) $l->quantity,
                    'price' => (float) $l->unit_price,
                    'TaxNet' => (float) $l->tax_percent,
                    'tax_method' => (string) ((int) $l->tax_method === 2 ? 2 : 1),
                    'discount' => (float) $l->discount_percent,
                    'discount_method' => '1',
                    'total' => (float) $l->total,
                ]);
            }

            $deal->update(['quotation_id' => $quotation->id, 'last_activity_at' => now()]);
            $this->deals->log($deal, 'quotation_created', null, [
                'quotation_id' => $quotation->id, 'ref' => $quotation->Ref, 'total' => $grand, 'custom_lines' => $custom->count(),
            ]);

            return [$quotation, $custom->count()];
        });
    }

    /** Called when a quotation is converted to a sale: its open deals are won. */
    public function onQuotationConverted(int $quotationId, int $saleId): void
    {
        $ref = optional(Sale::find($saleId))->Ref;
        CrmDeal::whereNull('deleted_at')->where('status', 'open')->where('quotation_id', $quotationId)->get()
            ->each(function (CrmDeal $deal) use ($ref) {
                $deal->update(['status' => 'won', 'won_at' => now(), 'last_activity_at' => now()]);
                $this->deals->log($deal, 'won', null, ['value' => (float) $deal->value, 'sale' => $ref, 'via' => 'quotation']);
                $this->deals->logOnLead($deal, 'deal_won', ['value' => (float) $deal->value]);
            });
    }

    /**
     * Safety net for sales made before the hook existed (or when it failed):
     * open deals whose quotation has been converted are marked won.
     */
    public function syncQuotationWins(): void
    {
        if (! Schema::hasColumn('quotations', 'converted_sale_id')) {
            return;
        }
        CrmDeal::query()->whereNull('crm_deals.deleted_at')->where('crm_deals.status', 'open')->whereNotNull('crm_deals.quotation_id')
            ->join('quotations', 'quotations.id', '=', 'crm_deals.quotation_id')
            ->whereNotNull('quotations.converted_sale_id')
            ->get(['crm_deals.quotation_id', 'quotations.converted_sale_id'])
            ->unique('quotation_id')
            ->each(fn ($r) => $this->onQuotationConverted((int) $r->quotation_id, (int) $r->converted_sale_id));
    }
}
