{{-- Setup banner: leads the tenant page while the workspace is not live yet
     (pending → provisioning → active, or failed). Owns the approve form,
     the live provisioning state and the retry path. --}}
@php
    $state = $tenant->status;
    $setupError = $tenant->provisioning_error;
    $canManagePayments = Auth::guard('central')->user()?->hasPermission('payments');
    $canViewLogs = Auth::guard('central')->user()?->hasPermission('logs_health');
@endphp
<div class="setup-banner setup-banner-{{ $state }} mb-4" id="tenant-setup">
    <div class="setup-banner-head">
        <div class="setup-banner-icon">
            @if($state === 'provisioning')
                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            @elseif($state === 'failed')
                <i class="bi bi-x-octagon-fill"></i>
            @else
                <i class="bi bi-hourglass-split"></i>
            @endif
        </div>
        <div class="flex-grow-1 min-w-0">
            @if($state === 'provisioning')
                <h2 class="setup-banner-title">{{ __('central.SetupProvisioningTitle') }}</h2>
                <p class="setup-banner-desc">{{ __('central.SetupProvisioningDesc') }} <span class="setup-elapsed" id="setupElapsed"></span></p>
            @elseif($state === 'failed')
                <h2 class="setup-banner-title">{{ __('central.SetupFailedTitle') }}</h2>
                <p class="setup-banner-desc">{{ __('central.SetupFailedDesc') }}</p>
            @else
                <h2 class="setup-banner-title">{{ __('central.SetupPendingTitle') }}</h2>
                <p class="setup-banner-desc">{{ __('central.SetupPendingDesc') }}</p>
            @endif
        </div>
        @if($state === 'pending')
            <form method="POST" action="{{ route('super.tenants.reject', $tenant) }}" id="form-reject-tenant" class="flex-shrink-0">
                @csrf
                <input type="hidden" name="reason" value="">
                <button type="button" class="btn btn-sm btn-reject" id="btnRejectTenant">
                    <i class="bi bi-x-lg me-1"></i>{{ __('central.Reject') }}
                </button>
            </form>
        @endif
    </div>

    {{-- ── Pending: payment → database → provision ── --}}
    @if($state === 'pending')
        @php
            $plan = $setup['plan'];
            if ($setup['payment_ok']) {
                $paymentState = 'done';
                $paymentNote = $setup['paid']
                    ? __('central.SetupPaymentPaid')
                    : ($plan->isFree() ? __('central.SetupPaymentFree') : __('central.SetupPaymentTrial', ['days' => $plan->getTrialDays()]));
            } else {
                $paymentState = 'current';
                $paymentNote = $setup['awaiting_payment'] ? __('central.SetupPaymentAwaiting') : __('central.SetupPaymentMissing');
            }
        @endphp
        <ol class="setup-steps">
            <li class="setup-step is-{{ $paymentState }}" data-setup-step="payment">
                <span class="setup-step-num"><span>1</span><i class="bi bi-check-lg"></i></span>
                <span class="setup-step-body">
                    <strong>{{ __('central.SetupStepPayment') }}</strong>
                    <small>{{ $paymentNote }}</small>
                </span>
            </li>
            <li class="setup-step is-{{ $setup['payment_ok'] ? 'current' : 'blocked' }}" data-setup-step="database">
                <span class="setup-step-num"><span>2</span><i class="bi bi-check-lg"></i></span>
                <span class="setup-step-body">
                    <strong>{{ __('central.SetupStepDatabase') }}</strong>
                    <small data-when="todo">{{ $setup['payment_ok'] ? __('central.SetupDatabaseTodo') : __('central.SetupDatabaseBlocked') }}</small>
                    <small data-when="done">{{ __('central.SetupDatabaseDone') }}</small>
                </span>
            </li>
            <li class="setup-step is-blocked" data-setup-step="provision">
                <span class="setup-step-num"><span>3</span><i class="bi bi-check-lg"></i></span>
                <span class="setup-step-body">
                    <strong>{{ __('central.SetupStepProvision') }}</strong>
                    <small data-when="todo">{{ __('central.SetupProvisionBlocked') }}</small>
                    <small data-when="done">{{ __('central.SetupProvisionTodo') }}</small>
                </span>
            </li>
        </ol>

        @if($setupError)
            <div class="setup-error">
                <i class="bi bi-exclamation-octagon-fill flex-shrink-0"></i>
                <div class="min-w-0">
                    <p class="setup-error-title">{{ __('central.SetupLastAttemptFailed') }}</p>
                    <pre class="setup-error-text">{{ $setupError }}</pre>
                </div>
            </div>
        @endif

        <div class="setup-banner-body">
        @if(! $setup['payment_ok'])
            <div class="p-3 rounded-3 approve-payment-required">
                <div class="d-flex align-items-start gap-2">
                    <i class="bi bi-exclamation-triangle-fill approve-payment-required-icon"></i>
                    <div class="flex-grow-1">
                        @if($setup['awaiting_payment'])
                            <p class="mb-0 fw-600 approve-payment-required-title">{{ __('central.SetupPaymentAwaiting') }}</p>
                            <p class="mb-2 text-muted approve-payment-required-desc">{{ __('central.SetupAwaitingPaymentDesc') }}</p>
                        @else
                            <p class="mb-0 fw-600 approve-payment-required-title">{{ __('central.PaymentRequiredBeforeApproval') }}</p>
                            <p class="mb-2 text-muted approve-payment-required-desc">{{ __('central.PaymentRequiredDesc') }}</p>
                        @endif
                        @if($canManagePayments)
                            <div class="d-flex flex-wrap gap-2">
                                @if($setup['awaiting_payment'])
                                    <a href="{{ route('super.payments.show', $setup['awaiting_payment']) }}" class="btn btn-sm btn-primary">
                                        <i class="bi bi-receipt me-1"></i>{{ __('central.SetupReviewPayment') }}
                                    </a>
                                @endif
                                <a href="{{ route('super.payments.create', ['tenant_id' => $tenant->id]) }}" class="btn btn-sm btn-secondary">
                                    <i class="bi bi-cash-coin me-1"></i>{{ __('super.payments.record_payment') }}
                                </a>
                                <a href="{{ route('super.payment-links.create', ['tenant_id' => $tenant->id]) }}" class="btn btn-sm btn-secondary">
                                    <i class="bi bi-link-45deg me-1"></i>{{ __('super.payment_links.create') }}
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @else
            @php $approveIsShared = \App\Models\Central\GeneralSetting::instance()->isSharedHosting(); @endphp
            <form method="POST" action="{{ route('super.tenants.approve', $tenant) }}" id="approveForm">
                @csrf

                @php
                    $defaultDbHost = $approveIsShared
                        ? 'localhost'
                        : config('database.connections.central.host', '127.0.0.1');
                    $defaultDbUser = $approveIsShared
                        ? ''
                        : config('database.connections.central.username', 'root');
                    $defaultDbPassword = $approveIsShared
                        ? ''
                        : config('database.connections.central.password', '');
                    $defaultDbPort = $approveIsShared
                        ? 3306
                        : (int) config('database.connections.central.port', 3306);
                    $defaultDbName = $approveIsShared
                        ? 'tenant_' . ($tenant->domains->first()->domain ?? '')
                        : config('tenancy.database.prefix', 'tenant') . $tenant->id . config('tenancy.database.suffix', '');
                @endphp

                <div class="mb-3 p-3 rounded-3 approve-notice-amber">
                    <div class="d-flex gap-2">
                        <i class="bi bi-info-circle-fill flex-shrink-0 approve-notice-amber-icon"></i>
                        <p class="mb-0 approve-notice-amber-text">
                            @if($approveIsShared)
                                {!! __('central.SharedHostingDbHint') !!}
                            @else
                                {!! __('central.VpsDbHint') !!}
                            @endif
                        </p>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">{{ __('central.DbHost') }} <span class="text-danger">*</span></label>
                        <input type="text" name="db_host" class="form-control form-control-sm approve-db-field" value="{{ old('db_host', $defaultDbHost) }}" required placeholder="localhost">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">{{ __('central.DbName') }} <span class="text-danger">*</span></label>
                        <input type="text" name="db_name" id="approveDbName" class="form-control form-control-sm approve-db-field" value="{{ old('db_name', $defaultDbName) }}" required placeholder="tenant_name">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">{{ __('central.DbPort') }} <span class="text-danger">*</span></label>
                        <input type="number" name="db_port" class="form-control form-control-sm approve-db-field" value="{{ old('db_port', $defaultDbPort) }}" required min="1" max="65535">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">{{ __('central.DbUsername') }} <span class="text-danger">*</span></label>
                        <input type="text" name="db_username" class="form-control form-control-sm approve-db-field" value="{{ old('db_username', $defaultDbUser) }}" required placeholder="db_user">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">{{ __('central.DbPassword') }}</label>
                        <input type="password" name="db_password" class="form-control form-control-sm approve-db-field" value="{{ old('db_password', $defaultDbPassword) }}" placeholder="********">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="button" class="btn btn-sm btn-test-connection" id="btnTestApproveDb">
                            <i class="bi bi-plug me-1"></i>{{ __('central.TestConnection') }}
                        </button>
                    </div>
                </div>
                <div id="approve-db-test-result" class="mb-3"></div>

                <button type="button" class="btn btn-success approve-locked" id="btnApprove"
                    data-swal-action='{"title":@json(__('central.ApproveAndProvisionTitle')),"text":@json(__('central.ApproveAndProvisionText')),"icon":"question","confirmText":@json(__('central.YesApprove')),"color":"#10b981"}'>
                    <i class="bi bi-check-circle me-1"></i> {{ __('central.ApproveAndProvision') }}
                </button>
            </form>
        @endif
        </div>

    {{-- ── Provisioning: live status, retry only once it looks stuck ── --}}
    @elseif($state === 'provisioning')
        <div class="setup-banner-body setup-slow" id="setupSlow">
            <p class="mb-2"><i class="bi bi-clock-history me-1"></i>{{ __('central.SetupProvisioningSlow') }}</p>
            <div class="d-flex flex-wrap gap-2">
                <form method="POST" action="{{ route('super.tenants.reprovision', $tenant) }}" class="d-inline" id="form-retry-provision">
                    @csrf
                    <button type="button" class="btn btn-sm btn-reprovision" data-swal-action='{"title":@json(__('central.SetupRetryTitle')),"text":@json(__('central.SetupRetryText')),"icon":"warning","confirmText":@json(__('central.SetupRetryProvisioning')),"color":"#ea580c"}'>
                        <i class="bi bi-arrow-clockwise me-1"></i>{{ __('central.SetupRetryProvisioning') }}
                    </button>
                </form>
                @if($canViewLogs)
                    <a href="{{ route('super.logs-health.tenant', $tenant) }}" class="btn btn-sm btn-outline">
                        <i class="bi bi-journal-text me-1"></i>{{ __('central.SetupViewLogs') }}
                    </a>
                @endif
            </div>
        </div>

    {{-- ── Failed: show why, offer retry ── --}}
    @else
        <div class="setup-error">
            <i class="bi bi-exclamation-octagon-fill flex-shrink-0"></i>
            <div class="min-w-0">
                <p class="setup-error-title">{{ __('central.SetupLastAttemptFailed') }}</p>
                <pre class="setup-error-text">{{ $setupError ?: __('central.SetupNoErrorDetails') }}</pre>
            </div>
        </div>
        <div class="setup-banner-body">
            <p class="setup-banner-hint"><i class="bi bi-info-circle me-1"></i>{{ __('central.SetupRetryHint') }}</p>
            <div class="d-flex flex-wrap gap-2">
                <form method="POST" action="{{ route('super.tenants.reprovision', $tenant) }}" class="d-inline" id="form-retry-provision">
                    @csrf
                    <button type="button" class="btn btn-sm btn-reprovision" data-swal-action='{"title":@json(__('central.SetupRetryTitle')),"text":@json(__('central.SetupRetryText')),"icon":"warning","confirmText":@json(__('central.SetupRetryProvisioning')),"color":"#ea580c"}'>
                        <i class="bi bi-arrow-clockwise me-1"></i>{{ __('central.SetupRetryProvisioning') }}
                    </button>
                </form>
                @if($canViewLogs)
                    <a href="{{ route('super.logs-health.tenant', $tenant) }}" class="btn btn-sm btn-outline">
                        <i class="bi bi-journal-text me-1"></i>{{ __('central.SetupViewLogs') }}
                    </a>
                @endif
            </div>
        </div>
    @endif
</div>
