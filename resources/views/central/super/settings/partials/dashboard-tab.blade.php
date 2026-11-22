{{--
    Settings > General > Dashboard — layout of the super-admin dashboard.
    Posts as dashboard_layout[...] with the rest of the General Settings form;
    GeneralSettingsController::update() resolves it via DashboardLayout.
    Row order in the DOM is the saved widget order (dashboard_layout[order][]).
--}}
@php
    $dlSettings = $dashboardLayout['settings'];
    $dlDefaults = $dashboardDefaults;
    $dlPermLabels = [
        'payments'      => __('super.dash_custom.perm.payments'),
        'support'       => __('super.dash_custom.perm.support'),
        'logs_health'   => __('super.dash_custom.perm.logs_health'),
        'tenants'       => __('super.dash_custom.perm.tenants'),
        'subscriptions' => __('super.dash_custom.perm.subscriptions'),
    ];
    $dlChoiceLabel = function (string $option, $value) {
        return match ($option) {
            'months' => __('super.dash_custom.choice_months', ['n' => $value]),
            'days', 'range' => (int) $value === 0
                ? __('super.dash_custom.choice_all_time')
                : __('super.dash_custom.choice_days', ['n' => $value]),
            'ahead'  => __('super.dash_custom.choice_next_days', ['n' => $value]),
            'rows'   => __('super.dash_custom.choice_rows', ['n' => $value]),
            'top'    => __('super.common.top_n', ['count' => $value]),
            'chart'  => __('super.dash_custom.chart_' . $value),
            default  => (string) $value,
        };
    };
    $dlEnabledCount = collect($dashboardLayout['widgets'])->where('enabled', true)->count();

    $dlJs = json_encode([
        'defaultOrder' => array_keys($dlDefaults['widgets']),
        'trans' => [
            'resetTitle'   => __('super.dash_custom.reset_title'),
            'resetText'    => __('super.dash_custom.reset_text'),
            'resetConfirm' => __('super.dash_custom.reset_confirm'),
            'cancel'       => __('super.general_settings.cancel'),
            'shownCount'   => __('super.dash_custom.shown_count'),
        ],
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
@endphp

<section class="settings-panel" id="panel-dashboard" role="tabpanel" data-panel="dashboard"
         data-title="{{ __('super.dash_custom.tab_title') }}"
         data-desc="{{ __('super.dash_custom.tab_desc') }}">

    @unless($dashboardStorable)
        <div class="dl-notice">
            <i class="bi bi-database-exclamation"></i>
            <span>{{ __('super.dash_custom.migration_notice') }}</span>
        </div>
    @endunless

    {{-- ── Page-wide display options ── --}}
    <div class="dl-section-head">
        <h3 class="dl-section-title"><i class="bi bi-display"></i>{{ __('super.dash_custom.display_title') }}</h3>
        <a href="{{ route('super.dashboard') }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline">
            <i class="bi bi-box-arrow-up-right me-1"></i>{{ __('super.dash_custom.open_dashboard') }}
        </a>
    </div>

    <div class="row g-3 mb-2">
        <div class="col-sm-6 col-xl-3">
            <label class="form-label">{{ __('super.dash_custom.setting_greeting') }}</label>
            <label class="form-switch-label d-flex align-items-center gap-2 dl-switch-box">
                <input type="hidden" name="dashboard_layout[settings][show_greeting]" value="0">
                <input type="checkbox" name="dashboard_layout[settings][show_greeting]" value="1" class="form-check-input"
                       data-default="{{ $dlDefaults['settings']['show_greeting'] ? '1' : '0' }}"
                       {{ $dlSettings['show_greeting'] ? 'checked' : '' }}>
                <span>{{ __('super.dash_custom.setting_greeting_hint') }}</span>
            </label>
        </div>
        <div class="col-sm-6 col-xl-3">
            <label class="form-label" for="dl-refresh">{{ __('super.dash_custom.setting_refresh') }}</label>
            <select id="dl-refresh" name="dashboard_layout[settings][refresh]" class="form-control" data-default="{{ $dlDefaults['settings']['refresh'] }}">
                @foreach(\App\Support\SuperDashboard\DashboardLayout::SETTINGS['refresh']['choices'] as $minutes)
                    <option value="{{ $minutes }}" @selected($dlSettings['refresh'] === $minutes)>
                        {{ $minutes === 0 ? __('super.dash_custom.off') : __('super.dash_custom.every_n_minutes', ['n' => $minutes]) }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-sm-6 col-xl-3">
            <label class="form-label" for="dl-density">{{ __('super.dash_custom.setting_density') }}</label>
            <select id="dl-density" name="dashboard_layout[settings][density]" class="form-control" data-default="{{ $dlDefaults['settings']['density'] }}">
                @foreach(\App\Support\SuperDashboard\DashboardLayout::SETTINGS['density']['choices'] as $density)
                    <option value="{{ $density }}" @selected($dlSettings['density'] === $density)>{{ __('super.dash_custom.density_' . $density) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-sm-6 col-xl-3">
            <label class="form-label" for="dl-cache">{{ __('super.dash_custom.setting_cache') }}</label>
            <select id="dl-cache" name="dashboard_layout[settings][cache]" class="form-control" data-default="{{ $dlDefaults['settings']['cache'] }}">
                @foreach(\App\Support\SuperDashboard\DashboardLayout::SETTINGS['cache']['choices'] as $minutes)
                    <option value="{{ $minutes }}" @selected($dlSettings['cache'] === $minutes)>
                        {{ $minutes === 0 ? __('super.dash_custom.cache_off') : __('super.dash_custom.choice_minutes', ['n' => $minutes]) }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>
    <p class="form-hint mb-0">{{ __('super.dash_custom.setting_cache_hint') }}</p>

    <hr class="settings-divider">

    {{-- ── Widgets ── --}}
    <div class="dl-section-head">
        <div>
            <h3 class="dl-section-title"><i class="bi bi-grid-1x2"></i>{{ __('super.dash_custom.widgets_title') }}</h3>
            <p class="form-hint mb-0">{{ __('super.dash_custom.widgets_hint') }}</p>
        </div>
        <span class="dl-count" id="dlCount" data-total="{{ count($dashboardLayout['widgets']) }}">
            {{ str_replace([':on', ':total'], [$dlEnabledCount, count($dashboardLayout['widgets'])], __('super.dash_custom.shown_count')) }}
        </span>
    </div>

    <div class="dl-toolbar">
        <div class="dl-filters" role="group" aria-label="{{ __('super.dash_custom.widgets_title') }}">
            <button type="button" class="dl-chip is-active" data-dl-filter="all">{{ __('super.dash_custom.filter_all') }}</button>
            <button type="button" class="dl-chip" data-dl-filter="kpi">{{ __('super.dash_custom.filter_kpi') }}</button>
            <button type="button" class="dl-chip" data-dl-filter="chart">{{ __('super.dash_custom.filter_chart') }}</button>
            <button type="button" class="dl-chip" data-dl-filter="table">{{ __('super.dash_custom.filter_table') }}</button>
            <button type="button" class="dl-chip" data-dl-filter="panel">{{ __('super.dash_custom.filter_panel') }}</button>
            <button type="button" class="dl-chip" data-dl-filter="on">{{ __('super.dash_custom.filter_on') }}</button>
        </div>
        <div class="dl-bulk">
            <button type="button" class="btn btn-sm btn-outline" data-dl-bulk="1"><i class="bi bi-eye me-1"></i>{{ __('super.dash_custom.show_all') }}</button>
            <button type="button" class="btn btn-sm btn-outline" data-dl-bulk="0"><i class="bi bi-eye-slash me-1"></i>{{ __('super.dash_custom.hide_all') }}</button>
            <button type="button" class="btn btn-sm btn-outline-danger" id="dlReset"><i class="bi bi-arrow-counterclockwise me-1"></i>{{ __('super.dash_custom.reset_defaults') }}</button>
        </div>
    </div>

    <ul class="dl-list" id="dlList">
        @foreach($dashboardLayout['widgets'] as $key => $widget)
            @php
                $def = $dashboardWidgets[$key];
                $default = $dlDefaults['widgets'][$key];
                $locked = ! empty($def['locked']);
                $widths = \App\Support\SuperDashboard\WidgetRegistry::widthsFor($def);
                $filterGroup = $def['group'] === 'alert' ? 'panel' : $def['group'];
                $field = 'dashboard_layout[widgets][' . $key . ']';
            @endphp
            <li class="dl-item {{ $widget['enabled'] ? '' : 'is-off' }} {{ $locked ? 'is-locked' : '' }}"
                data-key="{{ $key }}" data-group="{{ $filterGroup }}">
                <input type="hidden" name="dashboard_layout[order][]" value="{{ $key }}">

                <span class="dl-handle" draggable="true" title="{{ __('super.dash_custom.drag') }}" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span>

                <span class="dl-icon dl-icon--{{ $def['group'] }}"><i class="bi {{ $def['icon'] }}"></i></span>

                <div class="dl-body">
                    <div class="dl-name">
                        <span>{{ __('super.dash_custom.widgets.' . $key . '.name') }}</span>
                        <span class="dl-badge dl-badge--{{ $def['group'] }}">{{ __('super.dash_custom.group_' . $def['group']) }}</span>
                        @if(! empty($def['permission']))
                            <span class="dl-perm" title="{{ __('super.dash_custom.perm_hint', ['section' => $dlPermLabels[$def['permission']] ?? $def['permission']]) }}">
                                <i class="bi bi-lock"></i>{{ $dlPermLabels[$def['permission']] ?? $def['permission'] }}
                            </span>
                        @endif
                    </div>
                    <div class="dl-desc">{{ __('super.dash_custom.widgets.' . $key . '.desc') }}</div>

                    @if(count($widths) > 1 || ! empty($def['options']))
                        <div class="dl-controls">
                            @if(count($widths) > 1)
                                <label class="dl-control">
                                    <span>{{ __('super.dash_custom.opt_width') }}</span>
                                    <select name="{{ $field }}[width]" class="form-control form-control-sm" data-default="{{ $default['width'] }}">
                                        @foreach($widths as $width)
                                            <option value="{{ $width }}" @selected($widget['width'] === $width)>{{ __('super.dash_custom.width_' . $width) }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @else
                                <input type="hidden" name="{{ $field }}[width]" value="{{ $widget['width'] }}">
                            @endif
                            @foreach($def['options'] ?? [] as $option => $spec)
                                <label class="dl-control">
                                    <span>{{ __('super.dash_custom.opt_' . $option) }}</span>
                                    <select name="{{ $field }}[options][{{ $option }}]" class="form-control form-control-sm" data-default="{{ $default['options'][$option] }}">
                                        @foreach($spec['choices'] as $choice)
                                            <option value="{{ $choice }}" @selected((string) $widget['options'][$option] === (string) $choice)>{{ $dlChoiceLabel($option, $choice) }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endforeach
                        </div>
                    @else
                        <input type="hidden" name="{{ $field }}[width]" value="{{ $widget['width'] }}">
                    @endif
                </div>

                <div class="dl-actions">
                    <div class="dl-move">
                        <button type="button" class="dl-move-btn" data-dl-move="up" title="{{ __('super.dash_custom.move_up') }}" aria-label="{{ __('super.dash_custom.move_up') }}"><i class="bi bi-chevron-up"></i></button>
                        <button type="button" class="dl-move-btn" data-dl-move="down" title="{{ __('super.dash_custom.move_down') }}" aria-label="{{ __('super.dash_custom.move_down') }}"><i class="bi bi-chevron-down"></i></button>
                    </div>
                    @if($locked)
                        <input type="hidden" name="{{ $field }}[enabled]" value="1">
                        <span class="dl-locked" title="{{ __('super.dash_custom.locked_hint') }}"><i class="bi bi-pin-angle-fill"></i>{{ __('super.dash_custom.always_on') }}</span>
                    @else
                        <input type="hidden" name="{{ $field }}[enabled]" value="0">
                        <label class="dl-toggle form-check form-switch" title="{{ __('super.dash_custom.toggle') }}">
                            <input type="checkbox" name="{{ $field }}[enabled]" value="1" class="form-check-input dl-enabled"
                                   data-default="{{ $default['enabled'] ? '1' : '0' }}" @checked($widget['enabled'])>
                        </label>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
</section>

@push('styles')
<link href="{{ asset('assets_super/css/settings-dashboard.css') }}?v={{ filemtime(public_path('assets_super/css/settings-dashboard.css')) }}" rel="stylesheet">
@endpush

@push('scripts')
<script>window.DashboardLayoutData = {!! $dlJs !!};</script>
<script src="{{ asset('assets_super/js/settings-dashboard.js') }}?v={{ filemtime(public_path('assets_super/js/settings-dashboard.js')) }}"></script>
@endpush
