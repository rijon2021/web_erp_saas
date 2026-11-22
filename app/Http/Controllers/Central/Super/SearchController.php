<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\GeneralSetting;
use App\Models\Central\SupportTicket;
use App\Models\Central\TenantBillingPayment;
use App\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Topbar global search: a few matches per area (tenants, payments, support
 * tickets) for the areas this admin may open, plus a "search all" link into
 * each list page, which already understands the same `search` parameter.
 */
class SearchController extends Controller
{
    private const LIMIT = 5;

    private const AVATAR_PALETTE = ['#2a78d6', '#6250d6', '#1baf7a', '#eb6834', '#e87ba4', '#0ea5a4', '#d97706'];

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $user = Auth::guard('central')->user();
        $groups = [];

        if (mb_strlen($q) >= 2 && $user) {
            $like = '%' . addcslashes($q, '%_\\') . '%';

            if ($user->hasPermission('tenants')) {
                $tenants = Tenant::with('domains')
                    ->where(function ($query) use ($like) {
                        $query->where('data->company_name', 'like', $like)
                            ->orWhere('data->admin_email', 'like', $like)
                            ->orWhere('id', 'like', $like)
                            ->orWhereHas('domains', fn ($d) => $d->where('domain', 'like', $like));
                    })
                    ->orderByDesc('created_at')
                    ->limit(self::LIMIT)
                    ->get();
                $groups[] = [
                    'key'        => 'tenants',
                    'label'      => __('super.search.tenants'),
                    'items'      => $tenants->map(fn (Tenant $t) => [
                        'title'    => (string) ($t->company_name ?: $t->id),
                        'subtitle' => (string) ($t->domains->first()->domain ?? $t->admin_email ?? ''),
                        'badge'    => trans()->has('super.dash_custom.tenant_status.' . $t->status) ? __('super.dash_custom.tenant_status.' . $t->status) : ucfirst((string) $t->status),
                        'initials' => $this->initials((string) ($t->company_name ?: $t->id)),
                        'color'    => $this->color((string) ($t->company_name ?: $t->id)),
                        'url'      => route('super.tenants.show', $t),
                    ])->values()->all(),
                    'more_url'   => route('super.tenants.index', ['search' => $q]),
                    'more_label' => __('super.search.more_tenants', ['q' => $q]),
                ];
            }

            if ($user->hasPermission('payments_transactions')) {
                $symbol = GeneralSetting::currencySymbol();
                $payments = TenantBillingPayment::on('central')
                    ->with('tenant')
                    ->where(function ($query) use ($like) {
                        $query->where('invoice_number', 'like', $like)
                            ->orWhere('transaction_id', 'like', $like)
                            ->orWhere('gateway_payment_id', 'like', $like)
                            ->orWhereHas('tenant', fn ($t) => $t->where('data->company_name', 'like', $like));
                    })
                    ->latest('id')
                    ->limit(self::LIMIT)
                    ->get();
                $groups[] = [
                    'key'        => 'payments',
                    'label'      => __('super.search.payments'),
                    'items'      => $payments->map(fn (TenantBillingPayment $p) => [
                        'title'    => ($p->tenant?->company_name ?: ($p->tenant_id ?: '—')) . ' · ' . $symbol . number_format((float) $p->amount, 2),
                        'subtitle' => trim(($p->invoice_number ?: $p->transaction_id ?: '') . ' · ' . $p->gateway_label . ' · ' . ($p->paid_at ?? $p->created_at)?->format('M d, Y'), ' ·'),
                        'badge'    => trans()->has('super.status.' . $p->status) ? __('super.status.' . $p->status) : ucfirst((string) $p->status),
                        'icon'     => 'bi-receipt',
                        'url'      => route('super.payments.show', $p),
                    ])->values()->all(),
                    'more_url'   => route('super.payments.index', ['search' => $q]),
                    'more_label' => __('super.search.more_payments', ['q' => $q]),
                ];
            }

            if ($user->hasPermission('support')) {
                $tickets = SupportTicket::with('tenant')
                    ->where(function ($query) use ($like) {
                        $query->where('ticket_number', 'like', $like)
                            ->orWhere('subject', 'like', $like)
                            ->orWhere('opened_by_name', 'like', $like)
                            ->orWhere('opened_by_email', 'like', $like);
                    })
                    ->latest('id')
                    ->limit(self::LIMIT)
                    ->get();
                $groups[] = [
                    'key'        => 'tickets',
                    'label'      => __('super.search.tickets'),
                    'items'      => $tickets->map(fn (SupportTicket $t) => [
                        'title'    => (string) $t->subject,
                        'subtitle' => trim(($t->ticket_number ?: '') . ' · ' . ($t->tenant?->company_name ?: $t->opened_by_name ?: ''), ' ·'),
                        'badge'    => trans()->has('super.support.status.' . $t->status) ? __('super.support.status.' . $t->status) : ucfirst((string) $t->status),
                        'icon'     => 'bi-life-preserver',
                        'url'      => route('super.support.show', $t),
                    ])->values()->all(),
                    'more_url'   => route('super.support.index', ['search' => $q]),
                    'more_label' => __('super.search.more_tickets', ['q' => $q]),
                ];
            }
        }

        return response()->json([
            'q'      => $q,
            'groups' => $groups,
            'empty'  => mb_strlen($q) < 2 ? __('super.search.type_more') : __('super.search.no_results', ['q' => $q]),
        ]);
    }

    private function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name)) ?: [];
        $first = mb_substr($words[0] ?? '?', 0, 1);
        $last = count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : '';

        return mb_strtoupper($first . $last);
    }

    private function color(string $name): string
    {
        return self::AVATAR_PALETTE[crc32($name) % count(self::AVATAR_PALETTE)];
    }
}
