<?php

namespace App\Http\Controllers\Api\Super;

use App\Http\Controllers\Central\Super\SupportController as WebSupportController;
use App\Http\Controllers\Controller;
use App\Models\Central\CentralUser;
use App\Models\Central\SupportTicket;
use App\Models\Central\SupportTicketAttachment;
use App\Models\Central\SupportTicketMessage;
use Illuminate\Http\Request;

/** Support tickets from workspaces: list, read, reply, internal notes, status. */
class SupportController extends Controller
{
    use RunsWebActions;

    private const FILTERS = ['active', 'open', 'pending', 'resolved', 'closed', 'unread', 'all'];

    public function index(Request $request)
    {
        $filter = in_array($request->query('status'), self::FILTERS, true) ? $request->query('status') : 'active';

        $query = $this->filtered(SupportTicket::with(['tenant', 'assignedAdmin']), $filter);

        if ($search = trim((string) $request->query('search'))) {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('ticket_number', 'like', $like)
                  ->orWhere('subject', 'like', $like)
                  ->orWhere('opened_by_name', 'like', $like)
                  ->orWhere('opened_by_email', 'like', $like)
                  ->orWhere('tenant_id', 'like', $like);
            });
        }

        $page = $query->orderByRaw("FIELD(status, 'open','pending','resolved','closed')")
            ->orderByDesc('last_reply_at')
            ->orderByDesc('created_at')
            ->paginate(20);

        $counts = [];
        foreach (self::FILTERS as $f) {
            $counts[$f] = $this->filtered(SupportTicket::query(), $f)->count();
        }

        return response()->json([
            'data'   => collect($page->items())->map(fn (SupportTicket $t) => $this->ticket($t))->values(),
            'meta'   => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'total'        => $page->total(),
            ],
            'counts' => $counts,
        ]);
    }

    public function show(SupportTicket $ticket)
    {
        $ticket->load(['tenant', 'assignedAdmin', 'messages.attachments']);

        // Same as opening it on the web: the admin has now seen it.
        if ($ticket->admin_unread) {
            $ticket->update(['admin_unread' => false]);
        }

        return response()->json([
            'ticket'   => $this->ticket($ticket),
            'messages' => $ticket->messages->sortBy('id')->map(fn (SupportTicketMessage $m) => [
                'id'          => $m->id,
                'author_type' => $m->author_type,
                'author_name' => $m->author_name,
                'internal'    => (bool) $m->is_internal_note,
                'body'        => $m->body,
                'created_at'  => SuperPresenter::date($m->created_at),
                'attachments' => $m->attachments->map(fn (SupportTicketAttachment $a) => [
                    'id'        => $a->id,
                    'name'      => $a->original_name ?: basename((string) $a->file_path),
                    'mime_type' => $a->mime_type,
                    'size'      => (int) $a->size,
                ])->values(),
            ])->values(),
            'statuses' => SupportTicket::STATUSES,
            'admins'   => CentralUser::orderBy('name')->get(['id', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values(),
            'assigned_to_id' => $ticket->assigned_to,
        ]);
    }

    /** assigned_to = an admin id, or empty to unassign. */
    public function assign(Request $request, SupportTicket $ticket)
    {
        return $this->runWebAction($request, fn () => app(WebSupportController::class)->assign($request, $ticket));
    }

    public function reply(Request $request, SupportTicket $ticket)
    {
        return $this->runWebAction($request, fn () => app(WebSupportController::class)->reply($request, $ticket));
    }

    public function note(Request $request, SupportTicket $ticket)
    {
        return $this->runWebAction($request, fn () => app(WebSupportController::class)->note($request, $ticket));
    }

    public function status(Request $request, SupportTicket $ticket)
    {
        return $this->runWebAction($request, fn () => app(WebSupportController::class)->updateStatus($request, $ticket));
    }

    public function attachment(SupportTicketAttachment $attachment)
    {
        return app(WebSupportController::class)->downloadAttachment($attachment);
    }

    private function filtered($query, string $filter)
    {
        return match ($filter) {
            'active' => $query->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_PENDING]),
            'unread' => $query->where('admin_unread', true),
            'all'    => $query,
            default  => $query->where('status', $filter),
        };
    }

    private function ticket(SupportTicket $t): array
    {
        return [
            'id'              => $t->id,
            'ticket_number'   => $t->ticket_number,
            'subject'         => $t->subject,
            'category'        => $t->category,
            'priority'        => $t->priority,
            'status'          => $t->status,
            'tenant_id'       => $t->tenant_id,
            'company_name'    => $t->tenant ? $t->tenantName() : $t->tenant_id,
            'opened_by_name'  => $t->opened_by_name,
            'opened_by_email' => $t->opened_by_email,
            'assigned_to'     => $t->assignedAdmin?->name,
            'last_reply_by'   => $t->last_reply_by,
            'last_reply_at'   => SuperPresenter::date($t->last_reply_at),
            'admin_unread'    => (bool) $t->admin_unread,
            'created_at'      => SuperPresenter::date($t->created_at),
        ];
    }
}
