<?php

namespace App\Services\Crm;

use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmLeadStatus;
use App\Models\CrmSetting;
use Carbon\Carbon;

/**
 * Once a day, each rep gets one digest of their leads with no activity for
 * "stale after" days and their deals stuck in a stage past its rot days
 * (email and / or push, per the reminder channels in CRM Settings).
 */
class CrmStaleAlertService
{
    /** @return int reps alerted */
    public function run(bool $force = false): int
    {
        $s = CrmSetting::current();
        $today = Carbon::today();
        if (! $s->stale_alerts || (! $force && $s->stale_alerts_sent_on && $s->stale_alerts_sent_on->isSameDay($today))) {
            return 0;
        }
        // Claim today first, so an overlapping run can't send twice.
        $claimed = CrmSetting::whereKey($s->id)->where(fn ($q) => $q->whereNull('stale_alerts_sent_on')->orWhere('stale_alerts_sent_on', '<', $today->toDateString()))
            ->update(['stale_alerts_sent_on' => $today->toDateString()]);
        if (! $claimed && ! $force) {
            return 0;
        }

        $before = Carbon::now()->subDays(max(1, (int) $s->stale_lead_days));
        $leads = CrmLead::whereNull('deleted_at')->whereNull('client_id')->whereNotNull('owner_id')
            ->whereIn('crm_lead_status_id', CrmLeadStatus::where('kind', 'open')->pluck('id'))
            ->where(fn ($q) => $q->whereNull('last_activity_at')->orWhere('last_activity_at', '<', $before))
            ->orderBy('last_activity_at')->get(['id', 'lead_number', 'name', 'owner_id', 'last_activity_at'])->groupBy('owner_id');
        $deals = CrmDeal::with('stage')->whereNull('deleted_at')->where('status', 'open')->whereNotNull('owner_id')->get()
            ->filter(fn ($d) => $d->isRotting())->groupBy('owner_id');

        $base = \App\Services\Crm\CrmLeadSegmentResolver::tenantBaseUrl();
        $sent = 0;
        foreach ($leads->keys()->merge($deals->keys())->unique() as $ownerId) {
            $myLeads = $leads->get($ownerId, collect());
            $myDeals = $deals->get($ownerId, collect());
            $title = trim(($myLeads->count() ? $myLeads->count() . ' stale leads' : '') . ($myLeads->count() && $myDeals->count() ? ' and ' : '')
                . ($myDeals->count() ? $myDeals->count() . ' stuck deals' : '')) . ' need attention';

            $html = '<p>' . e($title) . '.</p>';
            if ($myLeads->count()) {
                $html .= '<p><strong>Leads with no activity for ' . (int) $s->stale_lead_days . '+ days</strong></p><ul>';
                foreach ($myLeads->take(25) as $l) {
                    $html .= '<li><a href="' . e($base . '/crm/leads/' . $l->id) . '">' . e($l->name) . '</a> · ' . e($l->lead_number)
                        . ' · ' . e($l->last_activity_at ? 'last activity ' . $l->last_activity_at->toDateString() : 'no activity yet') . '</li>';
                }
                $html .= '</ul>' . ($myLeads->count() > 25 ? '<p>…and ' . ($myLeads->count() - 25) . ' more.</p>' : '');
            }
            if ($myDeals->count()) {
                $html .= '<p><strong>Deals stuck in their stage</strong></p><ul>';
                foreach ($myDeals->take(25) as $d) {
                    $html .= '<li><a href="' . e($base . '/crm/deals/' . $d->id) . '">' . e($d->title) . '</a> · ' . e(optional($d->stage)->name) . '</li>';
                }
                $html .= '</ul>';
            }

            app(CrmReminderService::class)->notifyUser((int) $ownerId, $title, $title, ['type' => 'crm_stale'],
                (bool) $s->reminder_email, (bool) $s->reminder_push, $html);
            $sent++;
        }

        return $sent;
    }
}
