{{-- Subscription panel: current plan + one-click admin actions + history.
     Data comes from TenantController::show() ($subscriptionPanel). --}}
@php
    $sp = $subscriptionPanel;
    $cur = $sp['current'];
    $spAdmin = Auth::guard('central')->user();
    $canManageSubs = $spAdmin?->hasPermission('subscriptions');
    $canManagePayments = $spAdmin?->hasPermission('payments');

    $endDate = null;
    $endLabel = __('central.SubPanelEnds');
    $daysLeft = null;
    $inGrace = false;
    if ($cur) {
        if ($cur->status === 'trial' && $cur->trial_ends_at) {
            $endDate = $cur->trial_ends_at;
            $endLabel = __('central.SubPanelTrialEnds');
        } else {
            $endDate = $cur->ends_at;
        }
        if ($endDate) {
            $daysLeft = (int) now()->startOfDay()->diffInDays($endDate->copy()->startOfDay(), false);
        }
        $inGrace = $cur->inGracePeriod();
    }
    $pillClass = $daysLeft === null ? 'sub-pill-ok' : ($daysLeft < 0 ? 'sub-pill-danger' : ($daysLeft <= 3 ? 'sub-pill-danger' : ($daysLeft <= 7 ? 'sub-pill-warn' : 'sub-pill-ok')));
    $fmtDate = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('M d, Y') : '—';
@endphp
<div class="content-card mt-4" id="subscription-panel">
    <div class="card-header-custom flex-wrap gap-2">
        <h2><i class="bi bi-credit-card-2-front me-2 text-muted"></i>{{ __('central.SubPanelTitle') }}</h2>
        <div class="d-flex flex-wrap gap-2">
            @if($cur && $canManageSubs)
                <button type="button" class="btn btn-sm btn-outline" data-sub-toggle="sub-extend-form">
                    <i class="bi bi-calendar-plus me-1"></i>{{ __('central.SubPanelExtend') }}
                </button>
                <button type="button" class="btn btn-sm btn-outline" data-sub-toggle="sub-plan-form">
                    <i class="bi bi-arrow-left-right me-1"></i>{{ __('central.SubPanelChangePlan') }}
                </button>
            @endif
            @if($canManagePayments)
                <a href="{{ route('super.payments.create', ['tenant_id' => $tenant->id]) }}" class="btn btn-sm btn-outline">
                    <i class="bi bi-cash-coin me-1"></i>{{ __('super.payments.record_payment') }}
                </a>
            @endif
            @if($cur && $canManageSubs)
                <a href="{{ route('super.subscriptions.edit', $cur) }}" class="btn btn-ghost btn-xs" title="{{ __('central.SubPanelAdvancedEdit') }}">
                    <i class="bi bi-sliders"></i>
                </a>
            @endif
        </div>
    </div>

    <div class="card-body-custom">
        @if(! $cur)
            <p class="text-muted mb-0">{{ __('central.SubPanelNoSubscription') }}</p>
        @else
            <div class="sub-summary">
                <div class="sub-summary-cell">
                    <span class="sub-summary-label">{{ __('super.tenants.plan') }}</span>
                    <span class="sub-summary-value">{{ $cur->plan?->name ?? '—' }}</span>
                    <span class="sub-summary-sub">
                        {{ $cur->billing_cycle === 'yearly' ? __('super.payments.yearly') : __('super.payments.monthly') }}
                        · {{ $currencySymbol }}{{ number_format((float) $cur->amount, 2) }}
                    </span>
                </div>
                <div class="sub-summary-cell">
                    <span class="sub-summary-label">{{ __('super.common.status') }}</span>
                    <span class="sub-summary-value"><span class="badge badge-{{ $cur->status }}">{{ ucfirst($cur->status) }}</span></span>
                    <span class="sub-summary-sub">
                        @if($inGrace)
                            <span class="text-danger">{{ __('central.SubPanelInGrace', ['date' => $fmtDate($cur->graceEndsAt())]) }}</span>
                        @elseif($cur->status === 'cancelled' && $cur->ends_at?->isFuture())
                            {{ __('central.SubPanelCancelsAtEnd') }}
                        @elseif($tenant->isSuspendedForNonPayment())
                            <span class="text-danger">{{ __('central.SuspendedNonPayment') }}</span>
                        @endif
                    </span>
                </div>
                <div class="sub-summary-cell">
                    <span class="sub-summary-label">{{ __('central.SubPanelStarted') }}</span>
                    <span class="sub-summary-value">{{ $fmtDate($cur->starts_at) }}</span>
                </div>
                <div class="sub-summary-cell">
                    <span class="sub-summary-label">{{ $endLabel }}</span>
                    @if($endDate)
                        <span class="sub-summary-value">{{ $fmtDate($endDate) }}</span>
                        <span class="sub-pill {{ $pillClass }}">
                            @if($daysLeft > 0)
                                {{ trans_choice('central.SubPanelDaysLeft', $daysLeft, ['days' => $daysLeft]) }}
                            @elseif($daysLeft === 0)
                                {{ __('central.SubPanelEndsToday') }}
                            @else
                                {{ __('central.SubPanelEndedAgo', ['time' => $endDate->diffForHumans()]) }}
                            @endif
                        </span>
                    @else
                        <span class="sub-summary-value">{{ __('central.SubPanelNever') }}</span>
                    @endif
                </div>
            </div>

            @if($sp['pending_change'])
                <div class="sub-notice mt-3">
                    <i class="bi bi-hourglass-split"></i>
                    <span>{{ __('central.SubPanelPendingChange', [
                        'plan'  => $sp['pending_change']->plan?->name ?? '—',
                        'cycle' => $sp['pending_change']->billing_cycle === 'yearly' ? __('super.payments.yearly') : __('super.payments.monthly'),
                    ]) }}</span>
                    @if($canManageSubs)
                        <a href="{{ route('super.subscriptions.show', $sp['pending_change']) }}">{{ __('super.common.view') }}</a>
                    @endif
                </div>
            @endif

            @if($canManageSubs)
                {{-- Extend --}}
                <form method="POST" action="{{ route('super.tenants.subscription.extend', $tenant) }}" class="sub-action-form" id="sub-extend-form" hidden>
                    @csrf
                    <p class="sub-action-title"><i class="bi bi-calendar-plus me-1"></i>{{ __('central.SubPanelExtendTitle') }}</p>
                    <p class="form-hint mb-2">{{ $cur->trial_ends_at ? __('central.SubPanelExtendHintTrial') : __('central.SubPanelExtendHint') }}</p>
                    <div class="d-flex flex-wrap align-items-end gap-2 mb-2">
                        @foreach([7, 14, 30, 90] as $preset)
                            <button type="button" class="btn btn-sm btn-outline sub-days-preset" data-days="{{ $preset }}">+{{ $preset }}</button>
                        @endforeach
                        <div>
                            <label class="form-label form-label-sm mb-1" for="sub-extend-days">{{ __('central.SubPanelDays') }}</label>
                            <input type="number" id="sub-extend-days" name="days" class="form-control form-control-sm w-auto" min="1" max="3650" value="30" required>
                        </div>
                    </div>
                    <input type="text" name="note" class="form-control form-control-sm mb-2" maxlength="500" placeholder="{{ __('central.SubPanelNotePlaceholder') }}">
                    <button type="button" class="btn btn-sm btn-primary"
                        data-swal-action='{"title":@json(__('central.SubPanelExtendConfirmTitle')),"text":@json(__('central.SubPanelExtendConfirmText')),"icon":"question","confirmText":@json(__('central.SubPanelExtend')),"color":"#6366f1"}'>
                        {{ __('central.SubPanelExtend') }}
                    </button>
                </form>

                {{-- Change plan / cycle --}}
                <form method="POST" action="{{ route('super.tenants.subscription.change-plan', $tenant) }}" class="sub-action-form" id="sub-plan-form" hidden>
                    @csrf
                    <p class="sub-action-title"><i class="bi bi-arrow-left-right me-1"></i>{{ __('central.SubPanelChangePlanTitle') }}</p>
                    <div class="row g-2 mb-2">
                        <div class="col-sm-4">
                            <label class="form-label form-label-sm mb-1">{{ __('super.tenants.plan') }}</label>
                            <select name="plan_id" class="form-control form-control-sm" id="sub-plan-select" required>
                                @foreach($sp['plans'] as $p)
                                    <option value="{{ $p->id }}" data-monthly="{{ number_format($p->getPriceForCycle('monthly'), 2, '.', '') }}" data-yearly="{{ number_format($p->getPriceForCycle('yearly'), 2, '.', '') }}" {{ (int) $p->id === (int) $cur->plan_id ? 'selected' : '' }}>
                                        {{ $p->name }}{{ $p->isFree() ? ' (' . __('central.SubPanelFree') . ')' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label form-label-sm mb-1">{{ __('super.payments.billing_cycle') }}</label>
                            <select name="billing_cycle" class="form-control form-control-sm" id="sub-cycle-select">
                                <option value="monthly" {{ $cur->billing_cycle !== 'yearly' ? 'selected' : '' }}>{{ __('super.payments.monthly') }}</option>
                                <option value="yearly" {{ $cur->billing_cycle === 'yearly' ? 'selected' : '' }}>{{ __('super.payments.yearly') }}</option>
                            </select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label form-label-sm mb-1">{{ __('central.SubPanelPrice') }} ({{ $currencySymbol }})</label>
                            <input type="number" name="amount" id="sub-amount-input" class="form-control form-control-sm" step="0.01" min="0" value="{{ number_format((float) $cur->amount, 2, '.', '') }}">
                        </div>
                    </div>
                    <div class="d-flex flex-column gap-1 mb-2">
                        <label class="d-flex align-items-start gap-2 fs-sm3">
                            <input type="radio" name="period" value="keep" class="form-check-input mt-1" checked>
                            <span>{{ __('central.SubPanelPeriodKeep') }}</span>
                        </label>
                        <label class="d-flex align-items-start gap-2 fs-sm3">
                            <input type="radio" name="period" value="restart" class="form-check-input mt-1">
                            <span>{{ __('central.SubPanelPeriodRestart') }}</span>
                        </label>
                    </div>
                    <input type="text" name="note" class="form-control form-control-sm mb-2" maxlength="500" placeholder="{{ __('central.SubPanelNotePlaceholder') }}">
                    <button type="button" class="btn btn-sm btn-primary"
                        data-swal-action='{"title":@json(__('central.SubPanelChangePlanConfirmTitle')),"text":@json(__('central.SubPanelChangePlanConfirmText')),"icon":"question","confirmText":@json(__('central.SubPanelChangePlan')),"color":"#6366f1"}'>
                        {{ __('central.SubPanelChangePlan') }}
                    </button>
                </form>
            @endif
        @endif

        {{-- History --}}
        <div class="sub-history-grid mt-4">
            <div>
                <p class="sub-section-title">{{ __('central.SubPanelHistory') }}</p>
                @if($sp['history']->isEmpty())
                    <p class="text-muted small mb-0">—</p>
                @else
                    <ul class="sub-list">
                        @foreach($sp['history'] as $row)
                            <li>
                                <span class="badge badge-{{ $row->status }} badge-sm">{{ ucfirst($row->status) }}</span>
                                <span class="fw-600">{{ $row->plan?->name ?? '—' }}</span>
                                <span class="text-muted">{{ $row->billing_cycle === 'yearly' ? __('super.payments.yearly') : __('super.payments.monthly') }}</span>
                                @if(in_array($row->status, ['pending', 'failed'], true) && ! $row->ends_at && ! $row->trial_ends_at)
                                    <span class="sub-list-meta">{{ __('central.SubPanelNotStarted') }} · {{ $fmtDate($row->created_at) }}</span>
                                @else
                                    <span class="sub-list-meta">{{ $fmtDate($row->starts_at) }} → {{ $row->trial_ends_at ? $fmtDate($row->trial_ends_at) : ($row->ends_at ? $fmtDate($row->ends_at) : __('central.SubPanelNever')) }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div>
                <p class="sub-section-title">{{ __('central.SubPanelPayments') }}</p>
                @if($sp['payments']->isEmpty())
                    <p class="text-muted small mb-0">{{ __('central.SubPanelNoPayments') }}</p>
                @else
                    <ul class="sub-list">
                        @foreach($sp['payments'] as $pay)
                            <li>
                                <span class="badge badge-{{ $pay->status === 'paid' ? 'active' : ($pay->status === 'pending' ? 'trial' : 'cancelled') }} badge-sm">{{ ucfirst($pay->status) }}</span>
                                @if($canManagePayments)
                                    <a href="{{ route('super.payments.show', $pay) }}" class="fw-600">{{ $currencySymbol }}{{ number_format((float) $pay->amount, 2) }}</a>
                                @else
                                    <span class="fw-600">{{ $currencySymbol }}{{ number_format((float) $pay->amount, 2) }}</span>
                                @endif
                                <span class="text-muted">{{ \App\Models\Central\TenantBillingPayment::GATEWAYS[$pay->gateway] ?? ucfirst((string) $pay->gateway) }}</span>
                                <span class="sub-list-meta">{{ $fmtDate($pay->paid_at ?? $pay->created_at) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div>
                <p class="sub-section-title">{{ __('central.SubPanelChangeLog') }}</p>
                @if(! $sp['events_ready'])
                    <p class="text-muted small mb-0">{{ __('central.SubPanelChangeLogMigrate') }}</p>
                @elseif($sp['events']->isEmpty())
                    <p class="text-muted small mb-0">{{ __('central.SubPanelNoChanges') }}</p>
                @else
                    <ul class="sub-list">
                        @foreach($sp['events'] as $ev)
                            @php
                                $d = $ev->details ?? [];
                                $summary = match ($ev->action) {
                                    'extended'         => __('central.SubEventExtended', ['days' => $d['days'] ?? '?', 'date' => $fmtDate($d['after']['trial_ends_at'] ?? $d['after']['ends_at'] ?? null)]),
                                    'plan_changed'     => __('central.SubEventPlanChanged', ['from' => $d['before']['plan'] ?? '—', 'to' => $d['after']['plan'] ?? '—', 'cycle' => $d['after']['billing_cycle'] ?? '']),
                                    'payment_recorded' => __('central.SubEventPaymentRecorded', ['amount' => $currencySymbol . number_format((float) ($d['amount'] ?? 0), 2), 'date' => $fmtDate($d['ends_at'] ?? null)]),
                                    'activated'        => __('central.SubEventActivated', ['date' => $fmtDate($d['ends_at'] ?? null)]),
                                    'credited'         => __('central.SubEventCredited', ['days' => $d['days'] ?? '?', 'date' => $fmtDate($d['ends_at'] ?? null)]),
                                    'suspended'        => __('central.SubEventSuspended'),
                                    'cancelled'        => __('central.SubEventCancelled'),
                                    default            => __('central.SubEventEdited'),
                                };
                            @endphp
                            <li>
                                <span class="fw-600">{{ $summary }}</span>
                                <span class="sub-list-meta">{{ $ev->created_at->format('M d, Y H:i') }} · {{ $ev->admin?->name ?? __('central.SubEventSystem') }}</span>
                                @if($ev->note)
                                    <span class="sub-list-note">“{{ $ev->note }}”</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
