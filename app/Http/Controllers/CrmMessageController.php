<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\CrmContact;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Services\Crm\CrmDealService;
use App\Services\Crm\CrmLeadService;
use App\Services\Crm\CrmMessageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * CRM conversations: templates + channels, the thread of a lead / deal /
 * customer, sending, and the inbox of WhatsApp replies.
 */
class CrmMessageController extends Controller
{
    public function __construct(
        private CrmMessageService $messages,
        private CrmLeadService $leads,
        private CrmDealService $deals,
    ) {
    }

    public function meta(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'messages', CrmLead::class);
        $templates = Schema::hasTable('marketing_templates')
            ? DB::table('marketing_templates')->whereNull('deleted_at')->whereIn('type', ['email', 'sms', 'whatsapp'])
                ->orderBy('name')->get(['id', 'name', 'type', 'subject', 'content'])
            : collect();

        return response()->json([
            'channels' => $this->messages->availability(),
            'templates' => $templates,
            'placeholders' => CrmMessageService::PLACEHOLDERS,
        ]);
    }

    /** Thread + who it can go to (the record's email / phone and its contacts). Opening it marks replies read. */
    public function thread(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'messages', CrmLead::class);
        [$lead, $deal, $client] = $this->records($request);

        $rows = $this->messages->thread($lead, $deal, $client);
        CrmMessage::whereIn('id', $rows->where('direction', 'in')->whereNull('read_at')->pluck('id'))->update(['read_at' => now()]);

        return response()->json([
            'messages' => $rows->map(fn ($m) => $this->messages->present($m))->values(),
            'recipients' => $this->recipients($lead, $deal, $client),
        ]);
    }

    /** Fill a template's placeholders for the record (the composer's preview). */
    public function render(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'messages', CrmLead::class);
        [$lead, $deal, $client] = $this->records($request);
        $vars = $this->messages->variables($lead, $deal, $client, $user);

        return response()->json([
            'subject' => $this->messages->render($request->get('subject'), $vars),
            'body' => $this->messages->render($request->get('body'), $vars),
        ]);
    }

    public function send(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'messages', CrmLead::class);
        $portal = $request->channel === 'portal';
        if ($portal) {
            // Portal messages go to the deal's customer; no address to type.
            [, $pDeal] = $this->records($request);
            $account = $pDeal ? CrmMessageService::portalAccount($pDeal->client_id) : null;
            if (! $account) {
                return response()->json(['message' => 'Portal messages need a customer deal whose customer has an active client portal account.'], 422);
            }
            $request->merge(['to' => $account->email ?: 'portal']);
        }
        $request->validate([
            'channel' => ['required', Rule::in(CrmMessage::CHANNELS)],
            'to' => 'required|string|max:191',
            'subject' => 'nullable|string|max:191|required_if:channel,email',
            'body' => 'required|string|max:5000',
            'marketing_template_id' => 'nullable|integer',
        ], ['subject.required_if' => 'An email needs a subject.']);
        if ($request->channel === 'email' && ! filter_var($request->to, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'Enter a valid email address.', 'errors' => ['to' => ['Invalid email.']]], 422);
        }
        if (! $portal && $request->channel !== 'email' && strlen(preg_replace('/\D+/', '', $request->to)) < 6) {
            return response()->json(['message' => 'Enter a valid phone number.', 'errors' => ['to' => ['Invalid phone.']]], 422);
        }
        if (! ($this->messages->availability()[$request->channel] ?? false)) {
            return response()->json(['message' => 'This channel is not set up yet.'], 422);
        }
        [$lead, $deal, $client] = $this->records($request);

        $msg = $this->messages->send([
            'channel' => $request->channel,
            'to' => trim($request->to),
            'subject' => $request->subject,
            'body' => $request->body,
            'marketing_template_id' => $request->marketing_template_id,
            'crm_lead_id' => optional($lead)->id,
            'crm_deal_id' => optional($deal)->id,
            'client_id' => optional($client)->id,
        ], $user);

        if ($msg->status === 'failed') {
            return response()->json(['message' => 'Not sent: ' . $msg->error, 'message_row' => $this->messages->present($msg)], 422);
        }

        return response()->json(['success' => true, 'message_row' => $this->messages->present($msg)]);
    }

    /** WhatsApp replies matched to leads / customers: mine + unassigned, or all with crm_view_all. */
    public function inbox(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'messages', CrmLead::class);
        $q = CrmMessage::with('lead', 'deal', 'client')->where('direction', 'in')
            ->when(! $user->can('viewAll', CrmLead::class), fn ($w) => $w->where(fn ($x) => $x->where('owner_id', $user->id)->orWhereNull('owner_id')))
            ->when($request->get('filter') === 'unread', fn ($w) => $w->whereNull('read_at'))
            // Client Portal → Deal Messages lands on the inbox with ?channel=portal.
            ->when($request->filled('channel'), fn ($w) => $w->where('channel', $request->get('channel')));

        return response()->json([
            'unread' => (clone $q)->whereNull('read_at')->count(),
            'messages' => $q->orderByDesc('created_at')->limit(150)->get()->map(fn ($m) => $this->messages->present($m) + [
                'lead' => $m->lead ? ['id' => $m->lead->id, 'name' => $m->lead->name] : null,
                'deal' => $m->deal ? ['id' => $m->deal->id, 'title' => $m->deal->title] : null,
                'client' => $m->client ? ['id' => $m->client->id, 'name' => $m->client->name] : null,
            ])->values(),
        ]);
    }

    public function markRead(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'messages', CrmLead::class);
        $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        CrmMessage::whereIn('id', $request->ids)->where('direction', 'in')
            ->when(! $user->can('viewAll', CrmLead::class), fn ($w) => $w->where(fn ($x) => $x->where('owner_id', $user->id)->orWhereNull('owner_id')))
            ->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------

    /** [lead, deal, client] from lead / deal / client ids, each checked against the user's access. */
    private function records(Request $request): array
    {
        $user = $request->user('api');
        $lead = $deal = $client = null;
        if ($id = $request->input('crm_deal_id', $request->get('deal'))) {
            $deal = CrmDeal::with('lead', 'client', 'owner')->whereNull('deleted_at')->find((int) $id);
            abort_unless($deal && $this->deals->canSee($user, $deal), 404);
        }
        if ($id = $request->input('crm_lead_id', $request->get('lead'))) {
            $lead = CrmLead::with('client', 'owner')->whereNull('deleted_at')->find((int) $id);
            abort_unless($lead && $this->leads->canSee($user, $lead), 404);
        }
        if ($id = $request->input('client_id', $request->get('client'))) {
            $client = Client::whereNull('deleted_at')->find((int) $id);
            abort_unless($client, 404);
            // A customer on its own: Customer 360 rights; via a lead / deal the user already can see it.
            abort_unless($lead || $deal || $user->can('customers', CrmLead::class), 403);
        }
        abort_unless($lead || $deal || $client, 422, 'Choose a lead, a deal or a customer.');

        return [$lead, $deal, $client];
    }

    /** Email addresses and phones the composer offers. */
    private function recipients(?CrmLead $lead, ?CrmDeal $deal, ?Client $client): array
    {
        $lead = $lead ?? optional($deal)->lead;
        $client = $client ?? ($deal ? $deal->client : optional($lead)->client);
        $out = ['emails' => [], 'phones' => [], 'portal' => null];
        // Portal messages live on a customer's deal.
        if ($deal && ($account = CrmMessageService::portalAccount($deal->client_id))) {
            $out['portal'] = ['email' => $account->email, 'visible' => (bool) $deal->portal_visible];
        }
        $add = function ($label, $email, $phone) use (&$out) {
            if ($email && ! collect($out['emails'])->contains('value', $email)) {
                $out['emails'][] = ['value' => $email, 'label' => "$label · $email"];
            }
            if ($phone && ! collect($out['phones'])->contains('value', $phone)) {
                $out['phones'][] = ['value' => $phone, 'label' => "$label · $phone"];
            }
        };
        if ($client) {
            foreach (CrmContact::whereNull('deleted_at')->where('client_id', $client->id)->orderByDesc('is_primary')->get() as $c) {
                $add($c->name, $c->email, $c->phone);
            }
            $add($client->name, $client->email, $client->phone);
        }
        if ($lead) {
            $add($lead->name, $lead->email, $lead->phone);
        }

        return $out;
    }
}
