<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\CrmDeal;
use App\Models\CrmMessage;
use App\Models\CrmSetting;
use App\Models\Quotation;
use App\Services\Crm\CrmCaptureService;
use App\Services\Crm\CrmLeadService;
use App\Services\Crm\CrmReminderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Client portal → "My deals": the customer's CRM deals that staff share in
 * the portal (SaaS path /portal/deals — /portal/requests belongs to Protocol
 * & Requests) (quotation requests are shared automatically), a simple
 * progress tracker, and a message thread with their sales rep (crm_messages,
 * channel "portal"). Not-found answers (never 403: the portal client treats
 * 403 as "signed out").
 */
class PortalCrmRequestsController extends Controller
{
    public const STEPS = ['received', 'in_progress', 'quoted', 'accepted'];

    public function summary()
    {
        $client = $this->client();
        if (! $this->available()) {
            return response()->json(['enabled' => false, 'count' => 0, 'unread' => 0]);
        }
        $deals = $this->deals($client->client_id);

        return response()->json([
            'enabled' => (clone $deals)->exists(),
            'count' => (clone $deals)->where('status', 'open')->count(),
            'unread' => $this->unread($client->client_id),
        ]);
    }

    public function index()
    {
        $client = $this->client();
        if (! $this->available()) {
            return response()->json(['requests' => []]);
        }
        $deals = $this->deals($client->client_id)->with('owner', 'stage', 'pipeline', 'quotation')->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')->get();
        $unread = CrmMessage::where('channel', 'portal')->where('direction', 'out')->where('status', 'sent')->whereNull('portal_read_at')
            ->whereIn('crm_deal_id', $deals->pluck('id'))->selectRaw('crm_deal_id, COUNT(*) as n')->groupBy('crm_deal_id')->pluck('n', 'crm_deal_id');
        $last = CrmMessage::where('channel', 'portal')->whereIn('crm_deal_id', $deals->pluck('id'))
            ->selectRaw('crm_deal_id, MAX(created_at) as at')->groupBy('crm_deal_id')->pluck('at', 'crm_deal_id');

        return response()->json(['requests' => $deals->map(fn ($d) => $this->present($d) + [
            'unread' => (int) ($unread[$d->id] ?? 0),
            'last_message_at' => isset($last[$d->id]) ? \Carbon\Carbon::parse($last[$d->id])->toIso8601String() : null,
        ])->values()]);
    }

    public function show($id)
    {
        $client = $this->client();
        $deal = $this->find($client->client_id, $id);

        // Opening the request marks the rep's replies as seen.
        CrmMessage::where('crm_deal_id', $deal->id)->where('channel', 'portal')->where('direction', 'out')->whereNull('portal_read_at')
            ->update(['portal_read_at' => now()]);

        $messages = CrmMessage::with('user')->where('crm_deal_id', $deal->id)->where('channel', 'portal')
            ->where(fn ($q) => $q->where('direction', 'in')->orWhere('status', 'sent'))
            ->orderBy('created_at')->orderBy('id')->limit(300)->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'mine' => $m->direction === 'in',
                'author' => $m->direction === 'in' ? null : (CrmLeadService::userName($m->user) ?: CrmLeadService::userName($deal->owner)),
                'body' => $m->body,
                'created_at' => optional($m->created_at)->toIso8601String(),
            ])->values();

        return response()->json(['request' => $this->present($deal, true) + ['messages' => $messages]]);
    }

    public function message(Request $request, $id)
    {
        $client = $this->client();
        $deal = $this->find($client->client_id, $id);
        $data = $request->validate(['body' => 'required|string|max:3000']);
        $body = trim(strip_tags($data['body']));
        if ($body === '') {
            return response()->json(['message' => __('portal.req_message_empty'), 'errors' => ['body' => [__('portal.req_message_empty')]]], 422);
        }

        $msg = CrmMessage::create([
            'channel' => 'portal',
            'direction' => 'in',
            'crm_deal_id' => $deal->id,
            'crm_lead_id' => $deal->crm_lead_id,
            'client_id' => $deal->client_id,
            'to' => $client->email ?: 'portal',
            'body' => $body,
            'status' => 'received',
            'owner_id' => $deal->owner_id,
        ]);
        CrmDeal::whereKey($deal->id)->update(['last_activity_at' => now()]);

        // Tell the rep (email / push per the CRM reminder channels).
        if ($deal->owner_id) {
            $s = CrmSetting::current();
            app(CrmReminderService::class)->notifyUser($deal->owner_id,
                'New message from ' . (optional($deal->client)->name ?: 'a customer'),
                $deal->title . ' — ' . mb_strimwidth($body, 0, 180, '…'),
                ['type' => 'crm_deal', 'id' => $deal->id], (bool) $s->reminder_email, (bool) $s->reminder_push);
        }

        return response()->json(['success' => true, 'message' => [
            'id' => $msg->id, 'mine' => true, 'author' => null, 'body' => $msg->body, 'created_at' => $msg->created_at->toIso8601String(),
        ]], 201);
    }

    // ------------------------------------------------------------------

    /** Customer-facing view of a deal: no internal stage names, probabilities or notes. */
    private function present(CrmDeal $d, bool $full = false): array
    {
        $quotation = $d->quotation && ! $d->quotation->deleted_at && (int) $d->quotation->client_id === (int) $d->client_id ? $d->quotation : null;
        $step = $this->step($d, $quotation);
        $out = [
            'id' => $d->id,
            'number' => $d->deal_number,
            'title' => $d->title,
            'step' => $step,
            'step_index' => array_search($step, self::STEPS, true),
            'closed' => $d->status === 'lost',
            'rep' => $d->owner ? ['name' => CrmLeadService::userName($d->owner)] : null,
            'quotation' => $quotation ? ['id' => $quotation->id, 'ref' => $quotation->Ref, 'total' => (float) $quotation->GrandTotal, 'status' => $quotation->statut] : null,
            'expected_date' => $d->status === 'open' ? optional($d->expected_close_date)->toDateString() : null,
            'created_at' => optional($d->created_at)->toIso8601String(),
            'updated_at' => optional($d->updated_at)->toIso8601String(),
        ];
        if ($full) {
            // The text the customer sent — only on deals their own request created, never a staff-written description.
            $fromRequest = \App\Models\CrmDealEvent::where('crm_deal_id', $d->id)->where('type', 'created')
                ->whereIn('meta->via', ['portal_request', 'quote_request'])->exists();
            $out['details'] = $fromRequest ? $d->description : null;
        }

        return $out;
    }

    private function step(CrmDeal $d, ?Quotation $quotation): string
    {
        if ($d->status === 'won') {
            return 'accepted';
        }
        if ($quotation && (float) $quotation->GrandTotal > 0) {
            return 'quoted';
        }
        $first = $d->pipeline ? $d->pipeline->stages()->value('id') : null;

        return $first && (int) $d->crm_pipeline_stage_id !== (int) $first ? 'in_progress' : 'received';
    }

    private function deals(?int $clientId)
    {
        // A portal login without a customer record sees nothing (never everyone's deals).
        return CrmDeal::query()->whereNull('deleted_at')->where('client_id', $clientId ?: 0)->where('portal_visible', true);
    }

    private function find(?int $clientId, $id): CrmDeal
    {
        abort_unless($this->available(), 404);
        $deal = $this->deals($clientId)->with('owner', 'client', 'pipeline', 'quotation')->find((int) $id);
        abort_unless($deal, 404, __('portal.req_not_found'));

        return $deal;
    }

    private function unread(?int $clientId): int
    {
        return CrmMessage::where('channel', 'portal')->where('direction', 'out')->where('status', 'sent')->whereNull('portal_read_at')
            ->whereIn('crm_deal_id', $this->deals($clientId)->pluck('id'))->count();
    }

    /** Rep replies not seen yet — for the portal bell. */
    public static function unreadFor(?int $clientId): int
    {
        if (! (new self)->available()) {
            return 0;
        }

        return (new self)->unread($clientId);
    }

    private function available(): bool
    {
        return Schema::hasColumn('crm_deals', 'portal_visible') && CrmCaptureService::enabled();
    }

    private function client()
    {
        $client = Auth::guard('portal')->user();
        if ((int) $client->status !== 1) {
            abort(403, __('portal.portal_disabled'));
        }

        return $client;
    }
}
