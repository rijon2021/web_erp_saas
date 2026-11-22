<?php

namespace App\Services\Crm;

use App\Models\CrmLead;
use Illuminate\Support\Facades\DB;

/**
 * Marketing audience made of CRM leads (marketing_segments.audience =
 * 'crm_leads'). Converted leads (now customers) and leads who opted out of
 * marketing are never included.
 *
 * Filters (all optional):
 *   tag_ids[]        any of these tags
 *   source_ids[]     lead source
 *   status_ids[]     lead status (default: open statuses only, see status_kind)
 *   status_kind      open | lost | any (default open)
 *   ratings[]        cold | warm | hot
 *   owner_ids[]      owner ("none" = unassigned)
 *   channels[]       captured_via (manual = typed in)
 *   score_min / score_max
 *   country          text (name or code)
 *   created_from / created_to   dates
 */
class CrmLeadSegmentResolver
{
    public function query(bool $all, array $f = [])
    {
        $q = CrmLead::query()->whereNull('crm_leads.deleted_at')->whereNull('crm_leads.client_id')
            ->where(fn ($w) => $w->where('crm_leads.marketing_opt_out', false)->orWhereNull('crm_leads.marketing_opt_out'));
        if ($all) {
            return $q;
        }

        $ids = fn ($k) => array_values(array_filter(array_map('intval', (array) ($f[$k] ?? []))));
        $kind = $f['status_kind'] ?? 'open';
        if ($s = $ids('status_ids')) {
            $q->whereIn('crm_leads.crm_lead_status_id', $s);
        } elseif (in_array($kind, ['open', 'lost'], true)) {
            $q->whereIn('crm_leads.crm_lead_status_id', DB::table('crm_lead_statuses')->where('kind', $kind)->pluck('id'));
        }
        if ($t = $ids('tag_ids')) {
            $q->whereExists(fn ($w) => $w->from('crm_taggables')->whereColumn('crm_taggables.taggable_id', 'crm_leads.id')
                ->where('crm_taggables.taggable_type', 'lead')->whereIn('crm_taggables.crm_tag_id', $t));
        }
        if ($s = $ids('source_ids')) {
            $q->whereIn('crm_leads.crm_lead_source_id', $s);
        }
        if (! empty($f['ratings'])) {
            $q->whereIn('crm_leads.rating', array_intersect((array) $f['ratings'], CrmLead::RATINGS));
        }
        if (! empty($f['owner_ids'])) {
            $owners = (array) $f['owner_ids'];
            $q->where(function ($w) use ($owners) {
                $real = array_values(array_filter(array_map('intval', $owners)));
                if ($real) {
                    $w->orWhereIn('crm_leads.owner_id', $real);
                }
                if (in_array('none', $owners, true)) {
                    $w->orWhereNull('crm_leads.owner_id');
                }
            });
        }
        if (! empty($f['channels'])) {
            $ch = (array) $f['channels'];
            $q->where(function ($w) use ($ch) {
                $w->whereIn('crm_leads.captured_via', $ch);
                if (in_array('manual', $ch, true)) {
                    $w->orWhereNull('crm_leads.captured_via');
                }
            });
        }
        if (isset($f['score_min']) && $f['score_min'] !== '' && $f['score_min'] !== null) {
            $q->where('crm_leads.score', '>=', (int) $f['score_min']);
        }
        if (isset($f['score_max']) && $f['score_max'] !== '' && $f['score_max'] !== null) {
            $q->where('crm_leads.score', '<=', (int) $f['score_max']);
        }
        if (! empty($f['country'])) {
            $c = trim((string) $f['country']);
            $code = \App\Services\CountryService::toCode($c);
            $q->where(fn ($w) => $w->where('crm_leads.country', $c)->when($code, fn ($x) => $x->orWhere('crm_leads.country', $code)
                ->orWhere('crm_leads.country', \App\Services\CountryService::name($code))));
        }
        if (! empty($f['created_from'])) {
            $q->whereDate('crm_leads.created_at', '>=', $f['created_from']);
        }
        if (! empty($f['created_to'])) {
            $q->whereDate('crm_leads.created_at', '<=', $f['created_to']);
        }

        return $q;
    }

    /** Rows shaped like SegmentResolver's (id, name, email, phone) plus lead fields. */
    public function resolve(bool $all, array $filters = [])
    {
        return $this->query($all, $filters)->orderBy('crm_leads.id')
            ->get(['crm_leads.id', 'crm_leads.name', 'crm_leads.first_name', 'crm_leads.last_name', 'crm_leads.company_name',
                'crm_leads.email', 'crm_leads.phone', 'crm_leads.city', 'crm_leads.country', 'crm_leads.score']);
    }

    public function count(bool $all, array $filters = []): int
    {
        return $this->query($all, $filters)->count();
    }

    /**
     * Public link that opts the lead out of marketing (in every lead email).
     *
     * Not a Laravel signed route: tenant routes are only registered for
     * tenant-host requests, so URL::signedRoute() fails when a scheduled
     * campaign is sent from the console. The signature is an HMAC bound to
     * the tenant as well as the lead, so a link from one workspace can't be
     * replayed against another workspace's lead with the same id.
     */
    public static function unsubscribeUrl(int $leadId): string
    {
        return self::tenantBaseUrl() . '/crm/unsubscribe/' . $leadId . '?signature=' . self::unsubscribeSignature($leadId);
    }

    /**
     * This workspace's own origin (scheme + host), also from the console
     * (scheduled digests / campaigns), where url() would give the central
     * platform domain.
     */
    public static function tenantBaseUrl(): string
    {
        $base = '';
        if (! app()->runningInConsole()) {
            $base = request()->getSchemeAndHttpHost();
        } elseif (function_exists('tenancy') && tenancy()->initialized && tenancy()->tenant) {
            try {
                $base = (string) tenancy()->tenant->getTenantUrl();
                // Console requests are never "secure": follow the platform's scheme.
                if ($base && str_starts_with((string) config('app.url'), 'https://')) {
                    $base = preg_replace('#^http://#', 'https://', $base);
                }
            } catch (\Throwable $e) {
                $base = '';
            }
        }
        return rtrim($base ?: url('/'), '/');
    }

    public static function unsubscribeSignature(int $leadId): string
    {
        $tenant = function_exists('tenancy') && tenancy()->initialized && tenancy()->tenant
            ? (string) tenancy()->tenant->getTenantKey()
            : 'central';

        return hash_hmac('sha256', 'crm-unsubscribe|' . $tenant . '|' . $leadId, (string) config('app.key'));
    }
}
