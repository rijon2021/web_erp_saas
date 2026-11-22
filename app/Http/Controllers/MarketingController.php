<?php

namespace App\Http\Controllers;

use App\Jobs\SendMarketingCampaign;
use App\Models\MarketingActivityLog;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingSegment;
use App\Models\MarketingSetting;
use App\Models\MarketingTemplate;
use App\Models\Permission;
use App\Services\Marketing\MarketingDispatcher;
use App\Services\Marketing\SegmentResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketingController extends Controller
{
    /**
     * Ensure the authenticated user owns the given permission (via any of its roles).
     * Used for abilities not tied to an Eloquent model policy (dashboard, reports).
     */
    private function checkPermission(Request $request, string $permissionName): void
    {
        $user = $request->user('api');
        $permission = Permission::where('name', $permissionName)->first();

        if (! $user || ! $permission || ! $user->hasRole($permission->roles)) {
            abort(403, 'This action is unauthorized.');
        }
    }

    /**
     * Write a marketing audit entry.
     */
    private function logActivity(Request $request, string $module, ?int $referenceId, string $action, ?string $description = null): void
    {
        MarketingActivityLog::create([
            'user_id'      => $request->user('api') ? $request->user('api')->id : null,
            'module'       => $module,
            'reference_id' => $referenceId,
            'action'       => $action,
            'description'  => $description,
        ]);
    }

    /**
     * Store an uploaded campaign attachment under public/images/marketing.
     */
    private function storeUpload($file): string
    {
        $destination = public_path('images/marketing');
        if (! is_dir($destination)) {
            mkdir($destination, 0755, true);
        }
        $filename = \App\Support\SafeUpload::name(\App\Support\SafeUpload::documentExtension($file, 'attachment'));
        $file->move($destination, $filename);

        return 'images/marketing/'.$filename;
    }

    private function currentUserId(Request $request)
    {
        return $request->user('api') ? $request->user('api')->id : null;
    }

    /** A WhatsApp campaign/template that names a Meta-approved template. */
    private function usesWaTemplate(Request $request): bool
    {
        return $request->type === 'whatsapp' && trim((string) $request->wa_template_name) !== '';
    }

    /**
     * Rules for a body field (message_content / content): optional when a Meta
     * template carries the message, required otherwise.
     */
    private function bodyRule(Request $request): string
    {
        return $this->usesWaTemplate($request) ? 'nullable|string' : 'required|string';
    }

    private function waTemplateRules(): array
    {
        return [
            // Meta template names: lowercase letters, digits and underscores.
            'wa_template_name'     => ['nullable', 'string', 'max:191', 'regex:/^[a-z0-9_]+$/'],
            'wa_template_language' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z]{2,3}(_[A-Za-z]{2,4})?$/'],
        ];
    }

    /**
     * The three wa_template_* columns from the request; all null unless this is
     * a WhatsApp record that names a template. Params arrive as an array (JSON
     * body) or a JSON string (multipart campaign form).
     */
    private function waTemplateData(Request $request): array
    {
        if (! $this->usesWaTemplate($request)) {
            return ['wa_template_name' => null, 'wa_template_language' => null, 'wa_template_params' => null];
        }

        $params = $request->input('wa_template_params');
        if (is_string($params)) {
            $params = json_decode($params, true);
        }
        $params = array_values(array_map(
            fn ($p) => mb_substr(trim((string) $p), 0, 1024),
            array_slice(is_array($params) ? $params : [], 0, 20)
        ));

        return [
            'wa_template_name'     => trim((string) $request->wa_template_name),
            'wa_template_language' => trim((string) $request->wa_template_language) ?: 'en_US',
            'wa_template_params'   => $params,
        ];
    }

    // ======================== DASHBOARD ========================

    public function dashboard(Request $request)
    {
        $this->checkPermission($request, 'marketing_dashboard');

        $totalCampaigns = MarketingCampaign::count();
        $totalSms = (int) MarketingCampaign::where('type', 'sms')->sum('sent_count');
        $totalEmails = (int) MarketingCampaign::where('type', 'email')->sum('sent_count');
        $totalWhatsapp = (int) MarketingCampaign::where('type', 'whatsapp')->sum('sent_count');
        $totalMessages = $totalSms + $totalEmails + $totalWhatsapp;
        $failedMessages = (int) MarketingCampaign::sum('failed_count');

        $byStatus = MarketingCampaign::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')->pluck('total', 'status');

        $byType = MarketingCampaign::select('type', DB::raw('count(*) as total'))
            ->groupBy('type')->pluck('total', 'type');

        // Last 6 months of sent volume per channel.
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i);
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();

            $base = MarketingCampaign::whereBetween('sent_at', [$start, $end]);
            $months[] = [
                'label'    => $month->format('M Y'),
                'sms'      => (int) (clone $base)->where('type', 'sms')->sum('sent_count'),
                'email'    => (int) (clone $base)->where('type', 'email')->sum('sent_count'),
                'whatsapp' => (int) (clone $base)->where('type', 'whatsapp')->sum('sent_count'),
            ];
        }

        $recent = MarketingCampaign::orderBy('created_at', 'desc')->limit(8)->get();

        return response()->json([
            'total_campaigns' => $totalCampaigns,
            'total_messages'  => $totalMessages,
            'total_emails'    => $totalEmails,
            'total_whatsapp'  => $totalWhatsapp,
            'total_sms'       => $totalSms,
            'failed_messages' => $failedMessages,
            'by_status'       => $byStatus,
            'by_type'         => $byType,
            'monthly'         => $months,
            'recent'          => $recent,
        ]);
    }

    // ======================== GUIDE ========================

    /** Any of these opens the guide; each one also unlocks its own links there. */
    private const GUIDE_PERMISSIONS = [
        'marketing_dashboard', 'marketing_campaigns', 'marketing_segments',
        'marketing_templates', 'marketing_reports', 'marketing_settings',
    ];

    /**
     * Marketing › Guide — the live set-up checklist (channels, audience,
     * delivery health) for the guide page. Read-only.
     */
    public function guide(Request $request)
    {
        $user = $request->user('api');
        $perms = Permission::whereIn('name', self::GUIDE_PERMISSIONS)->get()->keyBy('name');
        $can = collect(self::GUIDE_PERMISSIONS)->mapWithKeys(fn ($p) => [
            $p => (bool) ($user && isset($perms[$p]) && $user->hasRole($perms[$p]->roles)),
        ]);
        if (! $can->contains(true)) {
            abort(403, 'This action is unauthorized.');
        }

        $settings = MarketingSetting::current();
        $clients = fn () => DB::table('clients')->whereNull('deleted_at');
        $since = Carbon::now()->subDays(30);
        $recipients = fn (string $status) => MarketingCampaignRecipient::where('status', $status)
            ->where('updated_at', '>=', $since)->count();

        return response()->json([
            'can' => $can,
            'channels' => [
                'email' => [
                    'enabled' => (bool) $settings->email_enabled,
                    // Same test the dispatcher uses: no tenant mail server = nothing is sent.
                    'ready' => DB::table('servers')->whereNull('deleted_at')->exists()
                        && DB::table('settings')->whereNull('deleted_at')->exists(),
                ],
                'sms' => [
                    'enabled' => (bool) $settings->sms_enabled,
                    'ready' => ! empty($settings->sms_api_url),
                ],
                'whatsapp' => [
                    'enabled' => (bool) $settings->whatsapp_enabled,
                    'ready' => ! empty($settings->whatsapp_api_url),
                ],
            ],
            'scheduling' => (bool) $settings->scheduling_enabled,
            'crm_leads' => \App\Services\Crm\CrmCaptureService::enabled(),
            'counts' => [
                'customers' => (int) $clients()->count(),
                'with_email' => (int) $clients()->whereNotNull('email')->where('email', '!=', '')->count(),
                'with_phone' => (int) $clients()->whereNotNull('phone')->where('phone', '!=', '')->count(),
                'segments' => (int) MarketingSegment::count(),
                'templates' => (int) MarketingTemplate::count(),
                'campaigns' => (int) MarketingCampaign::count(),
                'sent' => (int) MarketingCampaign::where('status', 'sent')->count(),
                'stuck' => (int) MarketingCampaign::where('status', 'sending')
                    ->where('updated_at', '<', Carbon::now()->subMinutes(MarketingCampaign::STALE_SENDING_MINUTES))->count(),
                'failed_30d' => $recipients('failed'),
                'skipped_30d' => $recipients('skipped'),
            ],
        ]);
    }

    // ======================== CAMPAIGNS ========================

    public function campaigns_index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MarketingCampaign::class);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = $request->SortType ?: 'desc';

        $query = MarketingCampaign::query();

        $query->when($request->filled('type'), function ($q) use ($request) {
            $q->where('type', $request->type);
        });
        $query->when($request->filled('status'), function ($q) use ($request) {
            $q->where('status', $request->status);
        });
        $query->when($request->filled('search'), function ($q) use ($request) {
            $search = $request->search;
            $q->where(function ($qq) use ($search) {
                $qq->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        });

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows ?: 1;
        }

        $campaigns = $query->orderBy($order, $dir)
            ->offset($offSet)->limit($perPage)->get();

        return response()->json([
            'campaigns' => $campaigns,
            'totalRows' => $totalRows,
        ]);
    }

    public function campaigns_show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', MarketingCampaign::class);

        $campaign = MarketingCampaign::with(['segment', 'template'])->findOrFail($id);

        $recipientStats = MarketingCampaignRecipient::where('campaign_id', $id)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')->pluck('total', 'status');

        $recipients = MarketingCampaignRecipient::where('campaign_id', $id)
            ->orderBy('id', 'desc')->limit(200)->get();

        return response()->json([
            'campaign'        => $campaign,
            'recipient_stats' => $recipientStats,
            'recipients'      => $recipients,
        ]);
    }

    public function campaigns_store(Request $request, MarketingDispatcher $dispatcher)
    {
        $this->authorizeForUser($request->user('api'), 'create', MarketingCampaign::class);

        $request->validate([
            'title'           => 'required|string|max:255',
            'type'            => 'required|in:sms,email,whatsapp',
            'message_content' => $this->bodyRule($request),
        ] + $this->waTemplateRules());

        $sendImmediately = $request->boolean('send_immediately', true);
        $scheduledAt = $request->filled('scheduled_at') ? $request->scheduled_at : null;

        $status = 'draft';
        if (! $sendImmediately && $scheduledAt) {
            $status = 'scheduled';
        }

        $attachment = null;
        if ($request->hasFile('attachment')) {
            $attachment = $this->storeUpload($request->file('attachment'));
        }

        $campaign = MarketingCampaign::create([
            'title'            => $request->title,
            'description'      => $request->description,
            'type'            => $request->type,
            'subject'         => $request->subject,
            'message_content' => (string) $request->message_content,
            'attachment'      => $attachment,
            'template_id'     => $request->template_id ?: null,
            'segment_id'      => $request->segment_id ?: null,
            'all_customers'   => $request->boolean('all_customers'),
            'status'          => $status,
            'send_immediately' => $sendImmediately,
            'scheduled_at'    => $scheduledAt,
            'created_by'      => $this->currentUserId($request),
        ] + $this->waTemplateData($request));

        $this->logActivity($request, 'campaign', $campaign->id, 'created', 'Campaign "'.$campaign->title.'" created');

        // Send now if requested. The recipient list is built here, not in the
        // job, so the audience shows at once even while the queue lags.
        if ($sendImmediately) {
            $dispatcher->buildRecipients($campaign);
            $campaign->update(['status' => 'sending']);
            SendMarketingCampaign::dispatch($campaign->id);
            $this->logActivity($request, 'campaign', $campaign->id, 'queued', 'Campaign queued for immediate sending');
        }

        return response()->json(['success' => true, 'id' => $campaign->id]);
    }

    public function campaigns_update(Request $request, $id, MarketingDispatcher $dispatcher)
    {
        $this->authorizeForUser($request->user('api'), 'update', MarketingCampaign::class);

        $campaign = MarketingCampaign::findOrFail($id);

        if (in_array($campaign->status, ['sending', 'sent'])) {
            return response()->json(['success' => false, 'message' => 'Sent campaigns cannot be edited.'], 422);
        }

        $request->validate([
            'title'           => 'required|string|max:255',
            'type'            => 'required|in:sms,email,whatsapp',
            'message_content' => $this->bodyRule($request),
        ] + $this->waTemplateRules());

        $sendImmediately = $request->boolean('send_immediately', $campaign->send_immediately);
        $scheduledAt = $request->filled('scheduled_at') ? $request->scheduled_at : null;
        $status = $campaign->status;
        if (! $sendImmediately && $scheduledAt) {
            $status = 'scheduled';
        } elseif ($sendImmediately) {
            $status = 'draft';
        }

        $data = [
            'title'            => $request->title,
            'description'      => $request->description,
            'type'            => $request->type,
            'subject'         => $request->subject,
            'message_content' => (string) $request->message_content,
            'template_id'     => $request->template_id ?: null,
            'segment_id'      => $request->segment_id ?: null,
            'all_customers'   => $request->boolean('all_customers'),
            'send_immediately' => $sendImmediately,
            'scheduled_at'    => $scheduledAt,
            'status'          => $status,
        ] + $this->waTemplateData($request);

        if ($request->hasFile('attachment')) {
            $data['attachment'] = $this->storeUpload($request->file('attachment'));
        }

        $campaign->update($data);

        $this->logActivity($request, 'campaign', $campaign->id, 'updated', 'Campaign "'.$campaign->title.'" updated');

        // Mirror create behaviour: if the user chose to send immediately, queue it now.
        if ($sendImmediately) {
            $dispatcher->buildRecipients($campaign);
            $campaign->update(['status' => 'sending']);
            \App\Jobs\SendMarketingCampaign::dispatch($campaign->id);
            $this->logActivity($request, 'campaign', $campaign->id, 'queued', 'Campaign queued for immediate sending');
        }

        return response()->json(['success' => true]);
    }

    public function campaigns_update_status(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', MarketingCampaign::class);

        $request->validate(['status' => 'required|in:draft,scheduled,sending,sent,failed,cancelled']);

        $campaign = MarketingCampaign::findOrFail($id);
        $campaign->update(['status' => $request->status]);

        $this->logActivity($request, 'campaign', $campaign->id, 'status_changed', 'Status set to '.$request->status);

        return response()->json(['success' => true]);
    }

    /**
     * Manually trigger (or re-trigger) sending of a campaign.
     */
    public function campaigns_send(Request $request, $id, MarketingDispatcher $dispatcher)
    {
        $this->authorizeForUser($request->user('api'), 'update', MarketingCampaign::class);

        $campaign = MarketingCampaign::findOrFail($id);

        // A stuck send (no batch for a while — no queue worker, killed job) may
        // be retried; it resumes from its pending recipients.
        if ($campaign->status === 'sending' && ! $campaign->is_stuck) {
            return response()->json(['success' => false, 'message' => 'Campaign is already sending.'], 422);
        }

        $dispatcher->prepareRecipients($campaign);
        $campaign->status = 'sending';
        $campaign->touch();
        SendMarketingCampaign::dispatch($campaign->id);

        $this->logActivity($request, 'campaign', $campaign->id, 'queued', 'Campaign queued for sending');

        return response()->json(['success' => true]);
    }

    public function campaigns_destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', MarketingCampaign::class);

        $campaign = MarketingCampaign::findOrFail($id);
        $campaign->delete();

        $this->logActivity($request, 'campaign', (int) $id, 'deleted', 'Campaign deleted');

        return response()->json(['success' => true]);
    }

    public function campaigns_delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', MarketingCampaign::class);

        $selectedIds = $request->selectedIds;
        foreach ($selectedIds as $id) {
            $campaign = MarketingCampaign::find($id);
            if ($campaign) {
                $campaign->delete();
            }
        }

        $this->logActivity($request, 'campaign', null, 'deleted', 'Bulk delete of campaigns');

        return response()->json(['success' => true]);
    }

    // ======================== SEGMENTS ========================

    public function segments_index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MarketingSegment::class);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = $request->SortType ?: 'desc';

        $query = MarketingSegment::query();
        $query->when($request->filled('search'), function ($q) use ($request) {
            $q->where('name', 'like', '%'.$request->search.'%');
        });

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows ?: 1;
        }

        $segments = $query->orderBy($order, $dir)->offset($offSet)->limit($perPage)->get();

        return response()->json(['segments' => $segments, 'totalRows' => $totalRows]);
    }

    public function segments_all(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MarketingSegment::class);

        $cols = ['id', 'name', 'all_customers', 'customers_count'];
        if (\Illuminate\Support\Facades\Schema::hasColumn('marketing_segments', 'audience')) {
            $cols[] = 'audience'; // CRM lead segments
        }
        $segments = MarketingSegment::orderBy('name')->get($cols);

        return response()->json(['segments' => $segments]);
    }

    public function segments_store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', MarketingSegment::class);

        $request->validate(['name' => 'required|string|max:255']);

        $audience = $this->segmentAudience($request);
        $filters = $request->filters ?: [];
        $count = (new SegmentResolver)->countFor($audience, $request->boolean('all_customers'), is_array($filters) ? $filters : []);

        $segment = MarketingSegment::create($this->withAudience($audience, [
            'name'            => $request->name,
            'description'     => $request->description,
            'all_customers'   => $request->boolean('all_customers'),
            'filters'         => is_array($filters) ? $filters : [],
            'customers_count' => $count,
            'created_by'      => $this->currentUserId($request),
        ]));

        $this->logActivity($request, 'segment', $segment->id, 'created', 'Segment "'.$segment->name.'" created');

        return response()->json(['success' => true, 'id' => $segment->id]);
    }

    public function segments_update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', MarketingSegment::class);

        $request->validate(['name' => 'required|string|max:255']);

        $segment = MarketingSegment::findOrFail($id);
        $audience = $this->segmentAudience($request);
        $filters = $request->filters ?: [];
        $count = (new SegmentResolver)->countFor($audience, $request->boolean('all_customers'), is_array($filters) ? $filters : []);

        $segment->update($this->withAudience($audience, [
            'name'            => $request->name,
            'description'     => $request->description,
            'all_customers'   => $request->boolean('all_customers'),
            'filters'         => is_array($filters) ? $filters : [],
            'customers_count' => $count,
        ]));

        $this->logActivity($request, 'segment', $segment->id, 'updated', 'Segment "'.$segment->name.'" updated');

        return response()->json(['success' => true]);
    }

    public function segments_destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', MarketingSegment::class);

        MarketingSegment::findOrFail($id)->delete();
        $this->logActivity($request, 'segment', (int) $id, 'deleted', 'Segment deleted');

        return response()->json(['success' => true]);
    }

    /**
     * Preview how many customers (and a small sample) match a filter set.
     */
    public function segments_preview(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MarketingSegment::class);

        $filters = $request->filters ?: [];
        $resolver = new SegmentResolver;
        $clients = $resolver->resolveFor($this->segmentAudience($request), $request->boolean('all_customers'), is_array($filters) ? $filters : []);

        return response()->json([
            'count'   => $clients->count(),
            'sample'  => $clients->take(10)->values(),
            'with_email' => $clients->filter(fn ($c) => ! empty($c->email))->count(),
            'with_phone' => $clients->filter(fn ($c) => ! empty($c->phone))->count(),
        ]);
    }

    /**
     * Lookups for the "CRM leads" audience (null when the CRM isn't available
     * to this user: lead segments need "view all leads").
     */
    public function segments_lead_meta(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MarketingSegment::class);
        if (! $this->canLeadAudience($request)) {
            return response()->json(['available' => false]);
        }

        return response()->json([
            'available' => true,
            'sources'  => \App\Models\CrmLeadSource::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'color']),
            'statuses' => \App\Models\CrmLeadStatus::where('kind', '!=', 'converted')->orderBy('sort_order')->get(['id', 'name', 'color', 'kind']),
            'tags'     => \App\Models\CrmTag::orderBy('name')->get(['id', 'name', 'color']),
            'owners'   => \App\Models\User::whereNull('deleted_at')->where('statut', 1)->orderBy('firstname')->get()
                ->map(fn ($u) => ['id' => $u->id, 'name' => \App\Services\Crm\CrmLeadService::userName($u)])->values(),
        ]);
    }

    private function segmentAudience(Request $request): string
    {
        if ($request->input('audience') !== 'crm_leads') {
            return 'customers';
        }
        if (! $this->canLeadAudience($request)) {
            abort(response()->json(['message' => 'Lead segments need the CRM permission to see all leads.'], 403));
        }

        return 'crm_leads';
    }

    private function canLeadAudience(Request $request): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('marketing_segments', 'audience')
            && \Illuminate\Support\Facades\Schema::hasTable('crm_leads')
            // CRM switched off in Settings → Modules: no lead audience either.
            && \App\Services\Crm\CrmCaptureService::enabled()
            && $request->user('api')->can('viewAll', \App\Models\CrmLead::class);
    }

    /** The audience column only exists once the CRM migrations ran. */
    private function withAudience(string $audience, array $data): array
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('marketing_segments', 'audience')) {
            $data['audience'] = $audience;
        }

        return $data;
    }

    // ======================== TEMPLATES ========================

    public function templates_index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MarketingTemplate::class);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = $request->SortType ?: 'desc';

        $query = MarketingTemplate::query();
        $query->when($request->filled('type'), function ($q) use ($request) {
            $q->where('type', $request->type);
        });
        $query->when($request->filled('search'), function ($q) use ($request) {
            $q->where('name', 'like', '%'.$request->search.'%');
        });

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows ?: 1;
        }

        $templates = $query->orderBy($order, $dir)->offset($offSet)->limit($perPage)->get();

        return response()->json(['templates' => $templates, 'totalRows' => $totalRows]);
    }

    public function templates_all(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MarketingTemplate::class);

        $query = MarketingTemplate::query();
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        $templates = $query->orderBy('name')->get([
            'id', 'name', 'type', 'subject', 'content',
            'wa_template_name', 'wa_template_language', 'wa_template_params',
        ]);

        return response()->json(['templates' => $templates]);
    }

    public function templates_store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', MarketingTemplate::class);

        $request->validate([
            'name'    => 'required|string|max:255',
            'type'    => 'required|in:sms,email,whatsapp',
            'content' => $this->bodyRule($request),
        ] + $this->waTemplateRules());

        $template = MarketingTemplate::create([
            'name'       => $request->name,
            'type'       => $request->type,
            'category'   => $request->category,
            'subject'    => $request->subject,
            'content'    => (string) $request->content,
            'created_by' => $this->currentUserId($request),
        ] + $this->waTemplateData($request));

        $this->logActivity($request, 'template', $template->id, 'created', 'Template "'.$template->name.'" created');

        return response()->json(['success' => true, 'id' => $template->id]);
    }

    public function templates_update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', MarketingTemplate::class);

        $request->validate([
            'name'    => 'required|string|max:255',
            'type'    => 'required|in:sms,email,whatsapp',
            'content' => $this->bodyRule($request),
        ] + $this->waTemplateRules());

        $template = MarketingTemplate::findOrFail($id);
        $template->update([
            'name'     => $request->name,
            'type'     => $request->type,
            'category' => $request->category,
            'subject'  => $request->subject,
            'content'  => (string) $request->content,
        ] + $this->waTemplateData($request));

        $this->logActivity($request, 'template', $template->id, 'updated', 'Template "'.$template->name.'" updated');

        return response()->json(['success' => true]);
    }

    public function templates_duplicate(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'create', MarketingTemplate::class);

        $template = MarketingTemplate::findOrFail($id);
        $copy = $template->replicate();
        $copy->name = $template->name.' (copy)';
        $copy->created_by = $this->currentUserId($request);
        $copy->save();

        $this->logActivity($request, 'template', $copy->id, 'created', 'Template duplicated from #'.$template->id);

        return response()->json(['success' => true, 'id' => $copy->id]);
    }

    public function templates_destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', MarketingTemplate::class);

        MarketingTemplate::findOrFail($id)->delete();
        $this->logActivity($request, 'template', (int) $id, 'deleted', 'Template deleted');

        return response()->json(['success' => true]);
    }

    // ======================== SETTINGS ========================

    public function settings_show(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', MarketingSetting::class);

        return response()->json(['settings' => MarketingSetting::current()]);
    }

    public function settings_update(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', MarketingSetting::class);

        $settings = MarketingSetting::current();

        $settings->update($request->only([
            'sms_enabled', 'sms_provider', 'sms_api_url', 'sms_api_key', 'sms_api_secret',
            'sms_sender_id', 'sms_http_method', 'sms_extra_params', 'sms_to_field', 'sms_message_field',
            'whatsapp_enabled', 'whatsapp_provider', 'whatsapp_api_url', 'whatsapp_api_key',
            'whatsapp_phone_id', 'whatsapp_http_method', 'whatsapp_extra_params',
            'whatsapp_to_field', 'whatsapp_message_field',
            'email_enabled', 'email_from_name', 'email_from_address',
            'default_sender_name', 'scheduling_enabled', 'batch_size',
        ]));

        $this->logActivity($request, 'settings', null, 'updated', 'Marketing settings updated');

        return response()->json(['success' => true]);
    }

    // ======================== REPORTS ========================

    public function reports(Request $request)
    {
        $this->checkPermission($request, 'marketing_reports');

        $from = $request->filled('date_from') ? Carbon::parse($request->date_from)->startOfDay() : Carbon::now()->subMonths(3)->startOfDay();
        $to = $request->filled('date_to') ? Carbon::parse($request->date_to)->endOfDay() : Carbon::now()->endOfDay();

        $campaigns = MarketingCampaign::whereBetween('created_at', [$from, $to])
            ->orderBy('created_at', 'desc')->get();

        $summary = [
            'total_campaigns' => $campaigns->count(),
            'sent'            => (int) $campaigns->sum('sent_count'),
            'delivered'       => (int) $campaigns->sum('delivered_count'),
            'failed'          => (int) $campaigns->sum('failed_count'),
            'pending'         => (int) $campaigns->sum('pending_count'),
            'recipients'      => (int) $campaigns->sum('total_recipients'),
        ];

        $byType = $campaigns->groupBy('type')->map(function ($group, $type) {
            return [
                'type'       => $type,
                'campaigns'  => $group->count(),
                'sent'       => (int) $group->sum('sent_count'),
                'failed'     => (int) $group->sum('failed_count'),
                'recipients' => (int) $group->sum('total_recipients'),
            ];
        })->values();

        return response()->json([
            'summary'   => $summary,
            'by_type'   => $byType,
            'campaigns' => $campaigns,
            'from'      => $from->toDateString(),
            'to'        => $to->toDateString(),
        ]);
    }

    // ======================== ACTIVITY LOG ========================

    public function activity_logs(Request $request)
    {
        $this->checkPermission($request, 'marketing_dashboard');

        $logs = MarketingActivityLog::with('user:id,firstname,lastname,username')
            ->orderBy('id', 'desc')->limit(100)->get();

        return response()->json(['logs' => $logs]);
    }
}
