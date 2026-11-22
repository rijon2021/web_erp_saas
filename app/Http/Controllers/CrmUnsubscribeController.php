<?php

namespace App\Http\Controllers;

use App\Models\CrmLead;
use App\Models\CrmLeadEvent;
use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * The unsubscribe link in marketing emails sent to CRM leads (tenant-bound
 * HMAC, see CrmLeadSegmentResolver::unsubscribeUrl). One click opts the lead out
 * of every future campaign; the lead's history records it.
 */
class CrmUnsubscribeController extends Controller
{
    public function show(Request $request, int $lead)
    {
        $company = optional(Setting::whereNull('deleted_at')->first())->CompanyName;
        $signature = (string) $request->query('signature', '');
        if (! hash_equals(\App\Services\Crm\CrmLeadSegmentResolver::unsubscribeSignature($lead), $signature)) {
            return response()->view('crm.unsubscribe', ['ok' => false, 'company' => $company], 403);
        }

        // Not plan-gated (old emails must keep working); the CRM may be gone.
        if (! \Illuminate\Support\Facades\Schema::hasTable('crm_leads')) {
            return response()->view('crm.unsubscribe', ['ok' => true, 'company' => $company]);
        }
        $row = CrmLead::withTrashed()->find($lead);
        if ($row && ! $row->marketing_opt_out) {
            CrmLead::whereKey($row->id)->update(['marketing_opt_out' => true, 'marketing_opt_out_at' => now()]);
            CrmLeadEvent::create(['crm_lead_id' => $row->id, 'type' => 'unsubscribed', 'meta' => ['via' => 'email link']]);
        }

        return response()->view('crm.unsubscribe', ['ok' => true, 'company' => $company]);
    }
}
