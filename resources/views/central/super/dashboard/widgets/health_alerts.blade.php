<div class="dash-alerts">
    @if($d['inactive'] > 0)
    <div class="dash-alert dash-alert--warning">
        <div class="dash-alert-icon dash-alert-icon--warning">
            <i class="bi bi-moon-stars"></i>
        </div>
        <div class="dash-alert-body">
            <div>
                <span class="dash-alert-count">{{ $d['inactive'] }}</span>
                <span class="dash-alert-label">{{ __('super.dashboard.inactive_tenants') }}</span>
            </div>
            <div class="dash-alert-desc">{{ __('super.dashboard.inactive_tenants_desc') }}</div>
        </div>
        <a href="{{ route('super.tenants.index', ['status' => 'inactive']) }}" class="dash-alert-action">
            {{ __('super.dashboard.view_inactive') }} <i class="bi bi-arrow-right"></i>
        </a>
    </div>
    @endif
    @if($d['expired'] > 0)
    <div class="dash-alert dash-alert--danger">
        <div class="dash-alert-icon dash-alert-icon--danger">
            <i class="bi bi-clock-history"></i>
        </div>
        <div class="dash-alert-body">
            <div>
                <span class="dash-alert-count">{{ $d['expired'] }}</span>
                <span class="dash-alert-label">{{ __('super.dashboard.expired_subs') }}</span>
            </div>
        </div>
        <a href="{{ route('super.subscriptions.index') }}" class="dash-alert-action">
            {{ __('super.dashboard.view_expired') }} <i class="bi bi-arrow-right"></i>
        </a>
    </div>
    @endif
    @if($d['suspended'] > 0)
    <div class="dash-alert dash-alert--indigo">
        <div class="dash-alert-icon dash-alert-icon--indigo">
            <i class="bi bi-pause-circle"></i>
        </div>
        <div class="dash-alert-body">
            <div>
                <span class="dash-alert-count">{{ $d['suspended'] }}</span>
                <span class="dash-alert-label">{{ __('super.dashboard.suspended_tenants') }}</span>
            </div>
        </div>
        <a href="{{ route('super.tenants.index') }}" class="dash-alert-action">
            {{ __('super.dashboard.view_suspended') }} <i class="bi bi-arrow-right"></i>
        </a>
    </div>
    @endif
</div>
