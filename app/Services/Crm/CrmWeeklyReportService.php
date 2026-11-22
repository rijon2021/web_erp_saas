<?php

namespace App\Services\Crm;

use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmSetting;
use App\Models\CrmTarget;
use App\Models\User;
use Carbon\Carbon;

/**
 * Monday-morning summary of last week (Mon–Sun) for CRM managers — users
 * with Reports and "view all": leads, deals won / lost, activities, the top
 * reps and the team's progress on this month's targets. Sent once a week by
 * `crm:daily` when CRM Settings → Weekly summary is on.
 */
class CrmWeeklyReportService
{
    public function __construct(private CrmReportService $reports)
    {
    }

    /** @return int managers emailed */
    public function run(bool $force = false): int
    {
        $s = CrmSetting::current();
        $today = Carbon::today();
        if (! $s->weekly_report || (! $force && ! $today->isMonday())) {
            return 0;
        }
        if (! $force) {
            $claimed = CrmSetting::whereKey($s->id)
                ->where(fn ($q) => $q->whereNull('weekly_report_sent_on')->orWhere('weekly_report_sent_on', '<', $today->toDateString()))
                ->update(['weekly_report_sent_on' => $today->toDateString()]);
            if (! $claimed) {
                return 0;
            }
        }

        $managers = User::whereNull('deleted_at')->where('statut', 1)->get()
            ->filter(fn ($u) => $u->email && $u->can('reports', CrmLead::class) && $u->can('viewAll', CrmLead::class));
        if ($managers->isEmpty()) {
            return 0;
        }

        $from = $today->copy()->subWeek()->startOfWeek(Carbon::MONDAY);
        $to = $from->copy()->endOfWeek(Carbon::SUNDAY);
        $html = $this->html($from, $to);
        $title = 'CRM weekly summary · ' . $from->format('M j') . ' – ' . $to->format('M j');

        foreach ($managers as $u) {
            app(CrmReminderService::class)->notifyUser($u->id, $title, $title, [], true, false, $html);
        }

        return $managers->count();
    }

    public function html(Carbon $from, Carbon $to): string
    {
        $o = $this->reports->overview(null, $from, $to)['kpis'];
        $prev = $this->reports->overview(null, $from->copy()->subWeek(), $to->copy()->subWeek())['kpis'];
        $reps = collect($this->reports->reps(null, $from, $to)['rows'])->filter(fn ($r) => $r['won_value'] > 0 || $r['activities'] > 0)->take(5);
        [$mFrom] = CrmTarget::bounds('month');
        $targets = $this->reports->targetProgress('month', $mFrom, [], true)->whereNull('user_id');
        $base = \App\Services\Crm\CrmLeadSegmentResolver::tenantBaseUrl();
        $money = fn ($v) => number_format((float) $v, 2);
        $delta = function ($now, $before) {
            if (! $before) {
                return '';
            }
            $p = round(($now - $before) / $before * 100);

            return ' <span style="color:' . ($p >= 0 ? '#16a34a' : '#dc2626') . '">(' . ($p >= 0 ? '+' : '') . $p . '%)</span>';
        };

        $row = fn ($label, $value) => '<tr><td style="padding:4px 12px 4px 0;color:#64748b">' . e($label) . '</td><td style="padding:4px 0;font-weight:600">' . $value . '</td></tr>';
        $h = '<p>Here is how the team did last week (' . e($from->toFormattedDateString()) . ' – ' . e($to->toFormattedDateString()) . ').</p>';
        $h .= '<table style="border-collapse:collapse;margin:8px 0 16px">';
        $h .= $row('New leads', $o['new_leads'] . $delta($o['new_leads'], $prev['new_leads']));
        $h .= $row('Leads converted to customers', (string) $o['converted_leads']);
        $h .= $row('Deals won', $o['won_deals'] . ' · ' . $money($o['won_value']) . $delta($o['won_value'], $prev['won_value']));
        $h .= $row('Deals lost', $o['lost_deals'] . ' · ' . $money($o['lost_value']));
        $h .= $row('Win rate', $o['win_rate'] === null ? '—' : $o['win_rate'] . '%');
        $h .= $row('Activities done', $o['activities_done'] . $delta($o['activities_done'], $prev['activities_done']));
        $h .= $row('Open pipeline', $o['open_deals'] . ' deals · ' . $money($o['open_value']) . ' (weighted ' . $money($o['weighted']) . ')');
        $h .= '</table>';

        if ($reps->count()) {
            $h .= '<p><strong>Top reps</strong></p><ol>';
            foreach ($reps as $r) {
                $h .= '<li>' . e($r['name']) . ' — won ' . $money($r['won_value']) . ' (' . $r['won_deals'] . ' deals), ' . $r['activities'] . ' activities</li>';
            }
            $h .= '</ol>';
        }
        if ($targets->count()) {
            $h .= '<p><strong>Team targets this month</strong></p><ul>';
            foreach ($targets as $t) {
                $h .= '<li>' . e(str_replace('_', ' ', ucfirst($t['metric']))) . ': ' . ($t['money'] ? $money($t['actual']) : (int) $t['actual'])
                    . ' of ' . ($t['money'] ? $money($t['target']) : (int) $t['target']) . ' (' . ($t['percent'] ?? 0) . '%)</li>';
            }
            $h .= '</ul>';
        }
        $h .= '<p><a href="' . e($base . '/crm/reports') . '">Open CRM reports</a></p>';

        return $h;
    }
}
