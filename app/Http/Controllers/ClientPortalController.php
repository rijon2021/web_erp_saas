<?php

namespace App\Http\Controllers;

use App\Mail\PortalInvitationMail;
use App\Models\Client;
use App\Models\PortalClient;
use App\Models\Sale;
use App\Models\Setting;
use App\Support\PortalSettings;
use App\Support\Security\TwoFactor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Admin side of the Client Portal sidebar section: dashboard, portal accounts
 * (grant / disable / invite), the account-deletion log and Portal Settings.
 *
 * A portal account is only a login (portal_clients) for an existing customer;
 * the customer page's per-client switch (PortalAdminController) keeps working
 * alongside this. Account states, derived from the row:
 *   active   status = 1
 *   invited  status = 0 with an invitation token (expired once past the expiry)
 *   disabled status = 0, no token
 *
 * SaaS: work counters and activity only cover modules the tenant's plan has
 * (PortalSettings::inPlan), and Protocol & Requests submissions from the
 * portal are counted too (stocky has no Protocol module).
 */
class ClientPortalController extends BaseController
{
    private bool $mailConfigured = false;

    private ?string $companyName = null;

    private function authorizePortal(Request $request, string $ability): void
    {
        $this->authorizeForUser($request->user('api'), $ability, Sale::class);
    }

    // ------------------------------------------------------------ dashboard

    public function dashboard(Request $request)
    {
        $this->authorizePortal($request, 'portal_accounts');

        $cutoff = Carbon::now()->subHours(PortalSettings::invitationExpiryHours());
        $accounts = PortalClient::query();

        $stats = [
            'total' => (clone $accounts)->count(),
            'active' => (clone $accounts)->where('status', 1)->count(),
            'invited' => (clone $accounts)->where('status', 0)->whereNotNull('invitation_token')
                ->where('invitation_sent_at', '>=', $cutoff)->count(),
            'expired_invites' => (clone $accounts)->where('status', 0)->whereNotNull('invitation_token')
                ->where(fn ($q) => $q->whereNull('invitation_sent_at')->orWhere('invitation_sent_at', '<', $cutoff))->count(),
            'disabled' => (clone $accounts)->where('status', 0)->whereNull('invitation_token')->count(),
            'new_this_month' => (clone $accounts)->where('created_at', '>=', Carbon::now()->startOfMonth())->count(),
            'customers_without_access' => Client::whereNull('deleted_at')
                ->whereNotIn('id', PortalClient::select('client_id'))->count(),
            'deletions_30d' => Schema::hasTable('portal_account_deletions')
                ? DB::table('portal_account_deletions')->where('created_at', '>=', Carbon::now()->subDays(30))->count() : 0,
        ];

        $work = [
            'quote_requests' => PortalSettings::inPlan('quotations') && Schema::hasColumn('quotations', 'source')
                ? DB::table('quotations')->whereNull('deleted_at')->where('source', 'portal')->where('statut', 'pending')->count() : 0,
            // Portal bookings are the only service jobs numbered APPT_… (PortalAppointmentsController@store).
            'appointments' => PortalSettings::inPlan('appointments') && Schema::hasTable('service_jobs')
                ? DB::table('service_jobs')->whereNull('deleted_at')->where('Ref', 'like', 'APPT\_%')->where('status', 'pending')->count() : 0,
            'unread_messages' => PortalSettings::inPlan('deals') && Schema::hasTable('crm_messages')
                ? DB::table('crm_messages')->where('channel', 'portal')->where('direction', 'in')->whereNull('read_at')->count() : 0,
            // Protocol & Requests: customer submissions nobody has received yet.
            'protocol_requests' => PortalSettings::inPlan('requests') && Schema::hasTable('protocol_requests')
                ? DB::table('protocol_requests')->whereNull('deleted_at')->whereNotNull('client_id')->where('status', 'open')->count() : 0,
        ];

        $recentAccounts = PortalClient::with('client:id,name')
            ->orderByDesc('id')->limit(6)->get()
            ->map(fn ($pc) => $this->presentAccount($pc, $cutoff));

        $settings = PortalSettings::all();
        $planModules = PortalSettings::planModules();
        $available = array_keys(array_filter($planModules));

        return response()->json([
            'stats' => $stats,
            'work' => $work,
            'recent_accounts' => $recentAccounts,
            'activity' => $this->recentActivity(),
            'portal' => [
                'enabled' => $settings['enabled'],
                // Counted over the modules the plan offers.
                'modules_on' => count(array_filter($available, fn ($m) => ! empty($settings['modules'][$m]))),
                'modules_total' => count($available),
            ],
            'plan_modules' => $planModules,
        ]);
    }

    /** Latest things customers did in the portal: quote requests, bookings, requests, messages. */
    private function recentActivity(): array
    {
        $items = collect();

        if (PortalSettings::inPlan('requests') && Schema::hasTable('protocol_requests')) {
            DB::table('protocol_requests')->leftJoin('clients', 'clients.id', '=', 'protocol_requests.client_id')
                ->whereNull('protocol_requests.deleted_at')->whereNotNull('protocol_requests.client_id')
                ->orderByDesc('protocol_requests.id')->limit(5)
                ->get(['protocol_requests.id', 'protocol_requests.Ref', 'protocol_requests.status', 'protocol_requests.subject', 'protocol_requests.created_at', 'clients.name as client_name'])
                ->each(fn ($r) => $items->push([
                    'type' => 'protocol', 'id' => $r->id, 'ref' => $r->Ref, 'status' => $r->status,
                    'text' => Str::limit((string) $r->subject, 90), 'client_name' => $r->client_name, 'at' => (string) $r->created_at,
                ]));
        }

        if (PortalSettings::inPlan('quotations') && Schema::hasColumn('quotations', 'source')) {
            DB::table('quotations')->leftJoin('clients', 'clients.id', '=', 'quotations.client_id')
                ->whereNull('quotations.deleted_at')->where('quotations.source', 'portal')
                ->orderByDesc('quotations.id')->limit(5)
                ->get(['quotations.id', 'quotations.Ref', 'quotations.statut', 'quotations.created_at', 'clients.name as client_name'])
                ->each(fn ($r) => $items->push([
                    'type' => 'quote', 'id' => $r->id, 'ref' => $r->Ref, 'status' => $r->statut,
                    'client_name' => $r->client_name, 'at' => (string) $r->created_at,
                ]));
        }

        if (PortalSettings::inPlan('appointments') && Schema::hasTable('service_jobs')) {
            DB::table('service_jobs')->leftJoin('clients', 'clients.id', '=', 'service_jobs.client_id')
                ->whereNull('service_jobs.deleted_at')->where('service_jobs.Ref', 'like', 'APPT\_%')
                ->orderByDesc('service_jobs.id')->limit(5)
                ->get(['service_jobs.id', 'service_jobs.Ref', 'service_jobs.status', 'service_jobs.service_item', 'service_jobs.created_at', 'clients.name as client_name'])
                ->each(fn ($r) => $items->push([
                    'type' => 'appointment', 'id' => $r->id, 'ref' => $r->Ref, 'status' => $r->status,
                    'text' => $r->service_item, 'client_name' => $r->client_name, 'at' => (string) $r->created_at,
                ]));
        }

        if (PortalSettings::inPlan('deals') && Schema::hasTable('crm_messages')) {
            DB::table('crm_messages')->leftJoin('clients', 'clients.id', '=', 'crm_messages.client_id')
                ->where('crm_messages.channel', 'portal')->where('crm_messages.direction', 'in')
                ->orderByDesc('crm_messages.id')->limit(5)
                ->get(['crm_messages.id', 'crm_messages.crm_deal_id', 'crm_messages.body', 'crm_messages.read_at', 'crm_messages.created_at', 'clients.name as client_name'])
                ->each(fn ($r) => $items->push([
                    'type' => 'message', 'id' => $r->id, 'deal_id' => $r->crm_deal_id, 'status' => $r->read_at ? 'read' : 'unread',
                    'text' => Str::limit((string) $r->body, 90), 'client_name' => $r->client_name, 'at' => (string) $r->created_at,
                ]));
        }

        return $items->sortByDesc('at')->take(8)->values()->all();
    }

    // ------------------------------------------------------------- accounts

    public function accounts(Request $request)
    {
        $this->authorizePortal($request, 'portal_accounts');

        $cutoff = Carbon::now()->subHours(PortalSettings::invitationExpiryHours());
        $perPage = (int) ($request->limit ?: 10);
        $page = max(1, (int) $request->get('page', 1));
        $sortable = [
            'id' => 'portal_clients.id',
            'email' => 'portal_clients.email',
            'client_name' => 'clients.name',
            'created_at' => 'portal_clients.created_at',
            'invitation_sent_at' => 'portal_clients.invitation_sent_at',
        ];
        $order = $sortable[$request->SortField] ?? 'portal_clients.id';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = PortalClient::query()
            ->leftJoin('clients', 'clients.id', '=', 'portal_clients.client_id')
            ->select('portal_clients.*', 'clients.name as client_name', 'clients.code as client_code', 'clients.phone as client_phone')
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(fn ($w) => $w->where('portal_clients.email', 'like', "%{$s}%")
                    ->orWhere('clients.name', 'like', "%{$s}%")
                    ->orWhere('clients.phone', 'like', "%{$s}%")
                    ->orWhere('clients.code', 'like', "%{$s}%"));
            })
            ->when($request->get('status') === 'active', fn ($q) => $q->where('portal_clients.status', 1))
            ->when($request->get('status') === 'invited', fn ($q) => $q->where('portal_clients.status', 0)->whereNotNull('portal_clients.invitation_token'))
            ->when($request->get('status') === 'disabled', fn ($q) => $q->where('portal_clients.status', 0)->whereNull('portal_clients.invitation_token'));

        $total = (clone $query)->count();
        if ($perPage < 1) {
            $perPage = max(1, $total);
        }

        $rows = $query->orderBy($order, $dir)->offset(($page - 1) * $perPage)->limit($perPage)->get()
            ->map(fn ($pc) => $this->presentAccount($pc, $cutoff));

        return response()->json(['accounts' => $rows, 'totalRows' => $total]);
    }

    private function presentAccount(PortalClient $pc, Carbon $cutoff): array
    {
        $invited = (int) $pc->status !== 1 && $pc->getRawOriginal('invitation_token') !== null;

        return [
            'id' => $pc->id,
            'client_id' => $pc->client_id,
            'client_name' => $pc->client_name ?? optional($pc->client)->name,
            'client_code' => $pc->client_code ?? null,
            'client_phone' => $pc->client_phone ?? null,
            'email' => $pc->email,
            'state' => (int) $pc->status === 1 ? 'active' : ($invited ? 'invited' : 'disabled'),
            'invite_expired' => $invited && (! $pc->invitation_sent_at || $pc->invitation_sent_at->lt($cutoff)),
            'has_password' => $pc->getRawOriginal('password') !== null,
            'invitation_sent_at' => optional($pc->invitation_sent_at)->toDateTimeString(),
            'preferred_locale' => $pc->preferred_locale,
            'created_at' => optional($pc->created_at)->toDateTimeString(),
            // Two-step verification method ('email' | 'app'), null when off.
            'two_factor' => TwoFactor::enabled($pc) ? TwoFactor::method($pc) : null,
        ];
    }

    /** Customers that have no portal account yet, for the "Grant access" picker. */
    public function availableClients(Request $request)
    {
        $this->authorizePortal($request, 'portal_accounts');

        $s = trim((string) $request->get('search', ''));
        $clients = Client::whereNull('deleted_at')
            ->whereNotIn('id', PortalClient::select('client_id'))
            ->when($s !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->orderBy('name')->limit(30)->get(['id', 'name', 'email', 'phone', 'code']);

        return response()->json(['clients' => $clients]);
    }

    /** Grant portal access: either email an invitation or set a password now. */
    public function store(Request $request)
    {
        $this->authorizePortal($request, 'portal_accounts');

        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at'), Rule::unique('portal_clients', 'client_id')],
            'email' => ['required', 'email', 'max:190', Rule::unique('portal_clients', 'email')],
            'mode' => ['required', Rule::in(['invite', 'password'])],
            'password' => ['required_if:mode,password', 'nullable', 'string', 'min:8'],
        ], [
            'client_id.unique' => 'This customer already has a portal account.',
            'email.unique' => 'This email is already used by another portal account.',
        ]);

        $pc = new PortalClient();
        $pc->client_id = (int) $data['client_id'];
        $pc->email = $data['email'];

        if ($data['mode'] === 'password') {
            $pc->password = Hash::make($data['password']);
            $pc->status = 1;
            $pc->save();

            return response()->json(['success' => true, 'id' => $pc->id]);
        }

        $pc->status = 0;
        $pc->save();
        $result = $this->sendInvitation($pc);

        return response()->json(['success' => true, 'id' => $pc->id] + $result);
    }

    public function update(Request $request, $id)
    {
        $this->authorizePortal($request, 'portal_accounts');

        $pc = PortalClient::findOrFail($id);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190', Rule::unique('portal_clients', 'email')->ignore($pc->id)],
            'password' => ['nullable', 'string', 'min:8'],
        ], [
            'email.unique' => 'This email is already used by another portal account.',
        ]);

        $pc->email = $data['email'];
        if (! empty($data['password'])) {
            $pc->password = Hash::make($data['password']);
        }
        $pc->save();

        return response()->json(['success' => true]);
    }

    /** Enable / disable one or many accounts. */
    /** Turn off a client's two-step verification (lost phone and recovery codes). */
    public function resetTwoFactor(Request $request, $id)
    {
        $this->authorizePortal($request, 'portal_accounts');

        TwoFactor::disable(PortalClient::findOrFail($id));

        return response()->json(['success' => true]);
    }

    public function setStatus(Request $request)
    {
        $this->authorizePortal($request, 'portal_accounts');

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'status' => ['required', 'boolean'],
        ]);

        $enable = (bool) $data['status'];
        $skipped = 0;
        $changed = 0;

        foreach (PortalClient::whereIn('id', $data['ids'])->get() as $pc) {
            // Enabling an account nobody ever set a password for would leave a
            // login that cannot be used; those need an invitation instead.
            if ($enable && $pc->getRawOriginal('password') === null) {
                $skipped++;

                continue;
            }
            $pc->status = $enable ? 1 : 0;
            // Either way any pending invitation ends: enabled accounts no longer
            // need it, and a disabled one must not be re-activated through it.
            $pc->invitation_token = null;
            $pc->invitation_sent_at = null;
            $pc->save();
            $changed++;
        }

        return response()->json(['success' => true, 'changed' => $changed, 'skipped' => $skipped]);
    }

    /** (Re)send the set-password invitation to one or many accounts. */
    public function invite(Request $request)
    {
        $this->authorizePortal($request, 'portal_accounts');

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ], [
            'ids.max' => 'Send invitations to at most 100 accounts at a time.',
        ]);

        // One SMTP round-trip per recipient: give a large batch room to finish.
        @set_time_limit(300);

        $sent = 0;
        $failed = 0;
        $last = [];
        foreach (PortalClient::with('client:id,name')->whereIn('id', $data['ids'])->get() as $pc) {
            $last = $this->sendInvitation($pc);
            $last['mail_sent'] ? $sent++ : $failed++;
        }

        return response()->json([
            'success' => true,
            'sent' => $sent,
            'failed' => $failed,
            // For a single account the link is returned so staff can share it
            // another way when mail is not configured.
            'invite_url' => count($data['ids']) === 1 ? ($last['invite_url'] ?? null) : null,
        ]);
    }

    /**
     * Issue a fresh token and email the set-password link. The token is saved
     * even when the mail fails, so the returned link still works.
     */
    private function sendInvitation(PortalClient $pc): array
    {
        $token = Str::random(64);
        $pc->invitation_token = $token;
        $pc->invitation_sent_at = Carbon::now();
        $pc->save();

        $url = url('/portal/set-password?token='.$token);
        $hours = PortalSettings::invitationExpiryHours();
        $companyName = $this->companyName ??= (string) optional(Setting::whereNull('deleted_at')->first())->CompanyName;
        $clientName = optional($pc->client)->name;

        try {
            // Mailer is configured once per request, not once per recipient.
            if (! $this->mailConfigured) {
                $this->Set_config_mail();
                $this->mailConfigured = true;
            }
            Mail::to($pc->email)->send(new PortalInvitationMail($clientName, $companyName, $url, $hours));
            $mailed = true;
        } catch (\Throwable $e) {
            Log::warning('Portal invitation email failed: '.$e->getMessage());
            $mailed = false;
        }

        return ['mail_sent' => $mailed, 'invite_url' => $url];
    }

    // ------------------------------------------------------------ deletions

    public function deletions(Request $request)
    {
        $this->authorizePortal($request, 'portal_accounts');

        if (! Schema::hasTable('portal_account_deletions')) {
            return response()->json(['deletions' => [], 'totalRows' => 0]);
        }

        $perPage = (int) ($request->limit ?: 10);
        $page = max(1, (int) $request->get('page', 1));
        $sortable = ['id' => 'id', 'created_at' => 'created_at', 'client_name' => 'client_name', 'email' => 'email'];
        $order = $sortable[$request->SortField] ?? 'id';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = DB::table('portal_account_deletions')
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(fn ($w) => $w->where('email', 'like', "%{$s}%")
                    ->orWhere('client_name', 'like', "%{$s}%")->orWhere('reason', 'like', "%{$s}%"));
            });

        $total = (clone $query)->count();
        if ($perPage < 1) {
            $perPage = max(1, $total);
        }

        $rows = $query->orderBy($order, $dir)->offset(($page - 1) * $perPage)->limit($perPage)
            ->get(['id', 'client_id', 'client_name', 'email', 'reason', 'ip', 'created_at']);

        return response()->json(['deletions' => $rows, 'totalRows' => $total]);
    }

    // ------------------------------------------------------------- settings

    public function getSettings(Request $request)
    {
        $this->authorizePortal($request, 'portal_settings');

        return response()->json([
            'settings' => PortalSettings::all(),
            'modules' => PortalSettings::MODULES,
            'actions' => PortalSettings::ACTIONS,
            // SaaS: modules the plan lacks stay off whatever the switch says.
            'plan_modules' => PortalSettings::planModules(),
            'action_modules' => PortalSettings::ACTION_MODULES,
            'locales' => \App\Services\PortalLanguageInstaller::installedLocales(),
            'portal_url' => url('/portal'),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $this->authorizePortal($request, 'portal_settings');

        $request->validate([
            'enabled' => ['required', 'boolean'],
            'modules' => ['required', 'array'],
            'actions' => ['required', 'array'],
            'welcome_message' => ['nullable', 'string', 'max:2000'],
            'default_locale' => ['nullable', Rule::in(array_keys(\App\Services\PortalLanguageInstaller::installedLocales()))],
            'invitation_expiry_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'two_factor_enabled' => ['nullable', 'boolean'],
        ]);

        // An older admin bundle doesn't send two_factor_enabled: keep the saved value.
        $saved = PortalSettings::save($request->only([
            'enabled', 'modules', 'actions', 'welcome_message', 'default_locale', 'invitation_expiry_hours',
        ]) + ['two_factor_enabled' => $request->has('two_factor_enabled')
            ? $request->boolean('two_factor_enabled')
            : PortalSettings::all()['two_factor_enabled']]);

        // The default portal language must be offered in the portal switcher:
        // if it is hidden in Portal Languages, show it again.
        if (! empty($saved['default_locale']) && \Illuminate\Support\Facades\Schema::hasTable('portal_languages')) {
            \Illuminate\Support\Facades\DB::table('portal_languages')
                ->where('locale', $saved['default_locale'])->where('is_active', 0)
                ->update(['is_active' => 1, 'updated_at' => now()]);
            \App\Services\PortalLanguageInstaller::forget();
        }

        return response()->json(['success' => true, 'settings' => $saved]);
    }
}
