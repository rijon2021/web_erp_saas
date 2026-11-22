<div class="content-card h-100">
    <div class="card-header-custom">
        <h2><i class="bi bi-lightning-charge me-2 text-muted"></i>{{ __('super.dashboard.quick_actions') }}</h2>
    </div>
    <div class="card-body-custom">
        <div class="dash-actions-grid">
            <a href="{{ route('super.tenants.index') }}" class="dash-action">
                <div class="dash-action-icon dash-action-icon--indigo">
                    <i class="bi bi-building"></i>
                </div>
                <span class="dash-action-title">{{ __('super.dashboard.manage_tenants') }}</span>
                <span class="dash-action-desc">{{ __('super.dashboard.manage_tenants_desc') }}</span>
            </a>
            <a href="{{ route('super.plans.create') }}" class="dash-action">
                <div class="dash-action-icon dash-action-icon--emerald">
                    <i class="bi bi-plus-lg"></i>
                </div>
                <span class="dash-action-title">{{ __('super.dashboard.create_plan') }}</span>
                <span class="dash-action-desc">{{ __('super.dashboard.create_plan_desc') }}</span>
            </a>
            <a href="{{ route('super.subscriptions.index') }}" class="dash-action">
                <div class="dash-action-icon dash-action-icon--blue">
                    <i class="bi bi-credit-card-2-front"></i>
                </div>
                <span class="dash-action-title">{{ __('super.dashboard.subscriptions') }}</span>
                <span class="dash-action-desc">{{ __('super.dashboard.subscriptions_desc') }}</span>
            </a>
            <a href="{{ route('central.welcome') }}" target="_blank" class="dash-action">
                <div class="dash-action-icon dash-action-icon--amber">
                    <i class="bi bi-box-arrow-up-right"></i>
                </div>
                <span class="dash-action-title">{{ __('super.dashboard.visit_landing') }}</span>
                <span class="dash-action-desc">{{ __('super.dashboard.visit_landing_desc') }}</span>
            </a>
        </div>
    </div>
</div>
