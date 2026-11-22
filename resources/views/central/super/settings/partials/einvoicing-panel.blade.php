{{--
    Settings > General > E-Invoicing tab. Expects $einv (array from
    EInvoicingSettingsController::viewData()). Sub-tabs are driven by the
    "einv=" part of the URL hash (#tab=einvoicing&einv=zatca) so the
    controllers can send the admin back to the right provider.
--}}
@php
    extract($einv, EXTR_SKIP);
    $p = $producer;
    $val = fn ($key) => old($key, $p->{$key} ?? '');
    $set = fn ($key) => $p && ! empty($p->{$key});
    $prov = fn ($key) => $providers[$key] ?? null;
    $provEnabled = fn ($key) => $prov($key) ? (bool) $prov($key)->enabled : true;
    $provVal = fn ($key, $field) => old($field, $prov($key)?->get($field) ?? (\App\Models\Central\EInvoicingProviderSetting::DEFAULTS[$key][$field] ?? ''));
    $subtabs = [
        'overview' => ['icon' => 'bi-grid-1x2'],
        'zatca'    => ['icon' => 'bi-qr-code'],
        'fbr'      => ['icon' => 'bi-receipt'],
        'agt'      => ['icon' => 'bi-qr-code-scan'],
        'dgi'      => ['icon' => 'bi-file-earmark-check'],
    ];
    $stats = $overview['providers'] ?? [];
@endphp

@push('styles')
<style>
    .einv-nav { display: flex; flex-wrap: wrap; gap: 0.375rem; margin-bottom: 1.25rem; padding: 0.3rem; background: var(--color-bg, #f3f4f6); border-radius: 999px; width: fit-content; max-width: 100%; }
    .einv-tab { border: 0; background: transparent; border-radius: 999px; padding: 0.45rem 0.9rem; font-size: 0.85rem; font-weight: 600; color: #4b5563; display: inline-flex; align-items: center; gap: 0.4rem; cursor: pointer; }
    .einv-tab:hover { color: var(--color-primary); }
    .einv-tab.is-active { background: #fff; color: var(--color-primary); box-shadow: var(--shadow-sm); }
    .einv-tab .bi-pause-circle-fill { color: #dc2626; }
    .einv-pane { display: none; }
    .einv-pane.is-active { display: block; }
    .einv-pane-intro { color: #6b7280; font-size: 0.86rem; margin: 0 0 1rem; }
    .einv-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; }
    .einv-card { border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 1rem 1.1rem; background: #fff; display: flex; flex-direction: column; gap: 0.75rem; }
    .einv-card-head { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
    .einv-card-title { font-weight: 700; font-size: 0.98rem; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
    .einv-kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; }
    .einv-kpi { background: var(--color-bg, #f8f9fb); border-radius: 8px; padding: 0.55rem 0.6rem; }
    .einv-kpi-value { font-size: 1.25rem; font-weight: 800; line-height: 1.1; }
    .einv-kpi-label { font-size: 0.72rem; color: #6b7280; font-weight: 600; }
    .einv-kpi.is-bad .einv-kpi-value { color: #dc2626; }
    .einv-kpi.is-warn .einv-kpi-value { color: #d97706; }
    .einv-kpi.is-good .einv-kpi-value { color: #16a34a; }
    .einv-meta { font-size: 0.78rem; color: #6b7280; display: flex; flex-wrap: wrap; gap: 0.35rem 1rem; }
    .einv-badge { font-size: 0.7rem; font-weight: 700; padding: 0.2rem 0.55rem; border-radius: 999px; white-space: nowrap; }
    .einv-badge.on { background: #dcfce7; color: #166534; }
    .einv-badge.off { background: #fee2e2; color: #991b1b; }
    .einv-badge.muted { background: #e5e7eb; color: #374151; }
    .einv-table { font-size: 0.82rem; }
    .einv-table th { font-weight: 600; color: #6b7280; }
    .einv-table td, .einv-table th { padding: 0.35rem 0.5rem; }
    .einv-settings-link { font-size: 0.8rem; }
    body.dark-mode .einv-nav { background: rgba(255,255,255,0.06); }
    body.dark-mode .einv-tab { color: #cbd5e1; }
    body.dark-mode .einv-tab.is-active { background: rgba(255,255,255,0.1); }
    body.dark-mode .einv-card { background: var(--dsh-card, #1f2937); border-color: rgba(255,255,255,0.08); }
    body.dark-mode .einv-kpi { background: rgba(255,255,255,0.05); }
</style>
@endpush

@if (! $providersMigrated)
    <div class="alert alert-error">{{ __('super.einvoicing.providers_not_migrated') }}</div>
@endif

<div class="einv-nav" role="tablist">
    @foreach ($subtabs as $key => $tab)
        <button type="button" class="einv-tab {{ $loop->first ? 'is-active' : '' }}" role="tab" data-einv="{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">
            <i class="bi {{ $tab['icon'] }}"></i>
            <span>{{ __('super.einvoicing.tab_'.$key) }}</span>
            @if ($key !== 'overview' && ! $provEnabled($key))
                <i class="bi bi-pause-circle-fill" title="{{ __('super.einvoicing.platform_off_badge') }}"></i>
            @endif
        </button>
    @endforeach
</div>

{{-- ══════════════════════ Overview ══════════════════════ --}}
<div class="einv-pane is-active" data-einv-pane="overview" role="tabpanel">
    <p class="einv-pane-intro">{{ __('super.einvoicing.tab_overview_desc') }}</p>
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <div class="einv-meta">
            @if ($overview)
                <span>{{ __('super.einvoicing.overview_generated', ['scanned' => $overview['tenants_scanned'] ?? 0, 'at' => $overview['generated_at'] ?? '—', 'ttl' => $overviewTtl]) }}</span>
                @if (! empty($overview['tenants_skipped']))
                    <span class="text-warning">{{ __('super.einvoicing.overview_skipped', ['count' => $overview['tenants_skipped']]) }}</span>
                @endif
            @else
                <span>{{ __('super.einvoicing.overview_not_scanned') }}</span>
            @endif
        </div>
        <form method="POST" action="{{ route('super.settings.e-invoicing.refresh-overview') }}">
            @csrf
            <button type="submit" class="btn btn-outline btn-sm"><i class="bi bi-arrow-clockwise"></i> {{ __('super.einvoicing.overview_refresh') }}</button>
        </form>
    </div>

    <div class="einv-grid mb-4">
        @foreach (['zatca', 'fbr', 'agt', 'dgi'] as $key)
            @php $s = $stats[$key] ?? []; @endphp
            <div class="einv-card">
                <div class="einv-card-head">
                    <h3 class="einv-card-title"><i class="bi {{ $subtabs[$key]['icon'] }} text-muted"></i> {{ __('super.einvoicing.tab_'.$key) }}</h3>
                    @if ($provEnabled($key))
                        <span class="einv-badge on">{{ __('super.einvoicing.platform_on_badge') }}</span>
                    @else
                        <span class="einv-badge off">{{ __('super.einvoicing.platform_off_badge') }}</span>
                    @endif
                </div>
                <div class="einv-kpis">
                    <div class="einv-kpi"><div class="einv-kpi-value">{{ $s['plans'] ?? '—' }}</div><div class="einv-kpi-label">{{ __('super.einvoicing.overview_plans') }}</div></div>
                    <div class="einv-kpi"><div class="einv-kpi-value">{{ $s['tenants_enabled'] ?? '—' }}</div><div class="einv-kpi-label">{{ __('super.einvoicing.overview_tenants_enabled') }}</div></div>
                    <div class="einv-kpi is-good"><div class="einv-kpi-value">{{ $s['tenants_ready'] ?? '—' }}</div><div class="einv-kpi-label">{{ __('super.einvoicing.overview_tenants_ready') }}</div></div>
                    <div class="einv-kpi is-warn"><div class="einv-kpi-value">{{ $s['docs_pending'] ?? '—' }}</div><div class="einv-kpi-label">{{ __('super.einvoicing.overview_docs_pending') }}</div></div>
                    <div class="einv-kpi is-bad"><div class="einv-kpi-value">{{ $s['docs_failed'] ?? '—' }}</div><div class="einv-kpi-label">{{ __('super.einvoicing.overview_docs_failed') }}</div></div>
                    <div class="einv-kpi"><div class="einv-kpi-value">{{ $s['docs_accepted'] ?? '—' }}</div><div class="einv-kpi-label">{{ __('super.einvoicing.overview_docs_accepted') }}</div></div>
                </div>
                <div class="einv-meta">
                    <span><i class="bi bi-clock me-1"></i>{{ __('super.einvoicing.overview_last_document') }}: {{ $s['last_document_at'] ?? '—' }}</span>
                    <a href="#tab=einvoicing&einv={{ $key }}" class="einv-settings-link" data-goto-einv="{{ $key }}">{{ __('super.einvoicing.overview_open_settings') }} →</a>
                </div>
            </div>
        @endforeach
    </div>

    @foreach (['zatca', 'fbr', 'agt', 'dgi'] as $key)
        @php $rows = $stats[$key]['tenants'] ?? []; @endphp
        @if (! empty($rows))
            <div class="content-card mb-3">
                <div class="card-header-custom">
                    <h2><i class="bi {{ $subtabs[$key]['icon'] }} me-2 text-muted"></i>{{ __('super.einvoicing.tab_'.$key) }} — {{ __('super.einvoicing.overview_top_tenants') }}</h2>
                </div>
                <div class="card-body-custom p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 einv-table">
                            <thead>
                                <tr>
                                    <th>{{ __('super.einvoicing.overview_tenant') }}</th>
                                    <th>{{ __('super.einvoicing.overview_state') }}</th>
                                    <th>{{ __('super.einvoicing.overview_environment') }}</th>
                                    <th class="text-end">{{ __('super.einvoicing.overview_docs_pending') }}</th>
                                    <th class="text-end">{{ __('super.einvoicing.overview_docs_failed') }}</th>
                                    <th class="text-end">{{ __('super.einvoicing.overview_docs_accepted') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    <tr>
                                        <td><a href="{{ route('super.tenants.show', $r['id']) }}">{{ $r['name'] }}</a></td>
                                        <td>
                                            @if ($r['ready'])
                                                <span class="einv-badge on">{{ __('super.einvoicing.overview_state_ready') }}</span>
                                            @elseif ($r['enabled'])
                                                <span class="einv-badge muted">{{ __('super.einvoicing.overview_state_enabled') }}</span>
                                            @else
                                                <span class="einv-badge off">{{ __('super.einvoicing.overview_state_off') }}</span>
                                            @endif
                                        </td>
                                        <td>{{ $r['environment'] ?? '—' }}</td>
                                        <td class="text-end">{{ $r['pending'] }}</td>
                                        <td class="text-end {{ $r['failed'] ? 'text-danger fw-600' : '' }}">{{ $r['failed'] }}</td>
                                        <td class="text-end">{{ $r['accepted'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    @endforeach
</div>

{{-- ══════════════════════ ZATCA ══════════════════════ --}}
<div class="einv-pane" data-einv-pane="zatca" role="tabpanel">
    <p class="einv-pane-intro">{{ __('super.einvoicing.tab_zatca_desc') }}</p>
    <div class="row g-4">
        <div class="col-lg-8">
            <form method="POST" action="{{ route('super.settings.e-invoicing.provider.update', 'zatca') }}">
                @csrf
                @method('PUT')
                @include('central.super.settings.partials.einvoicing-platform-switch', ['provider' => 'zatca', 'providers' => $providers])

                <div class="content-card mb-4">
                    <div class="card-header-custom">
                        <h2><i class="bi bi-sliders me-2 text-muted"></i>{{ __('super.einvoicing.defaults') }}</h2>
                    </div>
                    <div class="card-body-custom">
                        <p class="form-hint mb-3">{{ __('super.einvoicing.defaults_hint') }}</p>
                        <div class="form-group mb-3">
                            <label class="form-label">{{ __('super.einvoicing.default_environment') }}</label>
                            <select name="default_environment" class="form-control">
                                @foreach ($zatcaEnvironments as $envKey => $env)
                                    <option value="{{ $envKey }}" @selected($provVal('zatca', 'default_environment') === $envKey)>{{ $env['label'] }} — {{ $env['base_url'] }}</option>
                                @endforeach
                            </select>
                            <p class="form-hint">{{ __('super.einvoicing.zatca_env_hint') }} {{ __('super.einvoicing.tenant_overrides') }}</p>
                        </div>
                    </div>
                </div>

                <div class="content-card mb-4">
                    <div class="card-header-custom">
                        <h2><i class="bi bi-cpu me-2 text-muted"></i>{{ __('super.einvoicing.zatca_solution') }}</h2>
                    </div>
                    <div class="card-body-custom">
                        <p class="form-hint mb-3">{{ __('super.einvoicing.zatca_solution_hint') }}</p>
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">{{ __('super.einvoicing.zatca_solution_name') }}</label>
                                <input type="text" name="solution_name" class="form-control" maxlength="190" value="{{ $provVal('zatca', 'solution_name') }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ __('super.einvoicing.zatca_solution_version') }}</label>
                                <input type="text" name="solution_version" class="form-control" maxlength="50" value="{{ $provVal('zatca', 'solution_version') }}">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="content-card mb-4">
                    <div class="card-body-custom">
                        <button type="submit" class="btn btn-primary" @disabled(! $providersMigrated)><i class="bi bi-check-lg"></i> {{ __('super.einvoicing.save') }}</button>
                    </div>
                </div>
            </form>
        </div>
        <div class="col-lg-4">
            <div class="content-card">
                <div class="card-header-custom">
                    <h2><i class="bi bi-info-circle me-2 text-muted"></i>{{ __('super.einvoicing.zatca_how_title') }}</h2>
                </div>
                <div class="card-body-custom">
                    <ol class="fs-sm2 text-muted ps-3 mb-0">
                        <li class="mb-2">{{ __('super.einvoicing.zatca_how_1') }}</li>
                        <li class="mb-2">{{ __('super.einvoicing.zatca_how_2') }}</li>
                        <li class="mb-2">{{ __('super.einvoicing.zatca_how_3') }}</li>
                        <li>{{ __('super.einvoicing.zatca_how_4') }}</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ══════════════════════ FBR ══════════════════════ --}}
<div class="einv-pane" data-einv-pane="fbr" role="tabpanel">
    <p class="einv-pane-intro">{{ __('super.einvoicing.tab_fbr_desc') }}</p>
    <div class="row g-4">
        <div class="col-lg-8">
            <form method="POST" action="{{ route('super.settings.e-invoicing.provider.update', 'fbr') }}">
                @csrf
                @method('PUT')
                @include('central.super.settings.partials.einvoicing-platform-switch', ['provider' => 'fbr', 'providers' => $providers])

                <div class="content-card mb-4">
                    <div class="card-header-custom">
                        <h2><i class="bi bi-sliders me-2 text-muted"></i>{{ __('super.einvoicing.defaults') }}</h2>
                    </div>
                    <div class="card-body-custom">
                        <p class="form-hint mb-3">{{ __('super.einvoicing.defaults_hint') }}</p>
                        <div class="form-group mb-3">
                            <label class="form-label">{{ __('super.einvoicing.default_environment') }}</label>
                            <select name="default_environment" class="form-control">
                                @foreach ($fbrEnvironments as $envKey => $env)
                                    <option value="{{ $envKey }}" @selected($provVal('fbr', 'default_environment') === $envKey)>{{ $env['label'] }}</option>
                                @endforeach
                            </select>
                            <p class="form-hint">{{ __('super.einvoicing.fbr_env_hint') }} {{ __('super.einvoicing.tenant_overrides') }}</p>
                        </div>
                    </div>
                </div>

                <div class="content-card mb-4">
                    <div class="card-header-custom">
                        <h2><i class="bi bi-hdd-network me-2 text-muted"></i>{{ __('super.einvoicing.fbr_gateway') }}</h2>
                    </div>
                    <div class="card-body-custom">
                        <p class="form-hint mb-2">{{ __('super.einvoicing.fbr_gateway_hint') }}</p>
                        <table class="table table-sm mb-0 einv-table">
                            <tbody>
                                @foreach ($fbrEnvironments as $envKey => $env)
                                    <tr>
                                        <td class="fw-600">{{ $env['label'] }}</td>
                                        <td><code>{{ rtrim($fbrBaseUrl, '/') }}{{ $env['post_path'] }}</code></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="content-card mb-4">
                    <div class="card-body-custom">
                        <button type="submit" class="btn btn-primary" @disabled(! $providersMigrated)><i class="bi bi-check-lg"></i> {{ __('super.einvoicing.save') }}</button>
                    </div>
                </div>
            </form>
        </div>
        <div class="col-lg-4">
            <div class="content-card">
                <div class="card-header-custom">
                    <h2><i class="bi bi-info-circle me-2 text-muted"></i>{{ __('super.einvoicing.fbr_how_title') }}</h2>
                </div>
                <div class="card-body-custom">
                    <ol class="fs-sm2 text-muted ps-3 mb-0">
                        <li class="mb-2">{{ __('super.einvoicing.fbr_how_1') }}</li>
                        <li class="mb-2">{{ __('super.einvoicing.fbr_how_2') }}</li>
                        <li class="mb-2">{{ __('super.einvoicing.fbr_how_3') }}</li>
                        <li>{{ __('super.einvoicing.fbr_how_4') }}</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ══════════════════════ AGT ══════════════════════ --}}
<div class="einv-pane" data-einv-pane="agt" role="tabpanel">
    <p class="einv-pane-intro">{{ __('super.einvoicing.tab_agt_desc') }}</p>
    @if (! $migrated)
        <div class="alert alert-error">{{ __('super.einvoicing.not_migrated') }}</div>
    @endif

    <div class="row g-4">
        <div class="col-lg-8">
            <form method="POST" action="{{ route('super.settings.e-invoicing.update') }}">
                @csrf
                @method('PUT')
                @include('central.super.settings.partials.einvoicing-platform-switch', ['provider' => 'agt', 'providers' => $providers])

                {{-- Software --}}
                <div class="content-card mb-4">
                    <div class="card-header-custom">
                        <h2><i class="bi bi-cpu me-2 text-muted"></i>{{ __('super.einvoicing.software') }}</h2>
                    </div>
                    <div class="card-body-custom">
                        <p class="form-hint mb-3">{{ __('super.einvoicing.software_hint') }}</p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">{{ __('super.einvoicing.product_id') }}</label>
                                <input type="text" name="product_id" class="form-control" maxlength="120" value="{{ $val('product_id') }}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('super.einvoicing.product_version') }}</label>
                                <input type="text" name="product_version" class="form-control" maxlength="40" value="{{ $val('product_version') }}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">{{ __('super.einvoicing.signature_version') }}</label>
                                <input type="number" name="signature_version" class="form-control" min="1" max="999" value="{{ $val('signature_version') }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('super.einvoicing.validation_number') }}</label>
                                <input type="text" name="software_validation_number" class="form-control" maxlength="60" value="{{ $val('software_validation_number') }}">
                                <p class="form-hint">{{ __('super.einvoicing.validation_number_hint') }}</p>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">{{ __('super.einvoicing.producer_tax_id') }}</label>
                                <input type="text" name="producer_tax_id" class="form-control" maxlength="20" value="{{ $val('producer_tax_id') }}">
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Credentials --}}
                <div class="content-card mb-4">
                    <div class="card-header-custom">
                        <h2><i class="bi bi-key me-2 text-muted"></i>{{ __('super.einvoicing.credentials') }}</h2>
                    </div>
                    <div class="card-body-custom">
                        <p class="form-hint mb-3">{{ __('super.einvoicing.credentials_hint') }}</p>
                        @foreach (['test', 'production'] as $env)
                            <div class="fw-600 mb-2">{{ $environments[$env]['label'] ?? $env }} <span class="text-muted fs-sm2">— {{ $environments[$env]['base_url'] ?? '' }}</span></div>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">{{ __('super.einvoicing.username') }}</label>
                                    <input type="text" name="{{ $env }}_username" class="form-control" value="{{ $val($env.'_username') }}" autocomplete="off">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">
                                        {{ __('super.einvoicing.password') }}
                                        <i class="bi bi-lock-fill text-muted fs-xs" title="{{ __('central.StoredEncrypted') }}"></i>
                                    </label>
                                    <input type="password" name="{{ $env }}_password" class="form-control" value="" autocomplete="new-password">
                                    @if ($set($env.'_password'))
                                        <p class="form-hint">{{ __('super.einvoicing.secret_set_hint') }}</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Software key --}}
                <div class="content-card mb-4">
                    <div class="card-header-custom">
                        <h2><i class="bi bi-shield-lock me-2 text-muted"></i>{{ __('super.einvoicing.software_key') }}</h2>
                    </div>
                    <div class="card-body-custom">
                        <p class="form-hint mb-3">{{ __('super.einvoicing.software_key_hint') }}</p>
                        <label class="form-label">
                            {{ __('super.einvoicing.private_key') }}
                            <i class="bi bi-lock-fill text-muted fs-xs" title="{{ __('central.StoredEncrypted') }}"></i>
                        </label>
                        <textarea name="software_private_key" class="form-control @error('software_private_key') is-invalid @enderror" rows="4" style="font-family: monospace; font-size: 12px" placeholder="-----BEGIN PRIVATE KEY-----" autocomplete="off"></textarea>
                        @if ($set('software_private_key'))
                            <p class="form-hint">{{ __('super.einvoicing.key_set_hint') }}</p>
                        @endif
                        @error('software_private_key')
                            <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
                        @enderror
                        @if ($publicKey)
                            <label class="form-label mt-3">{{ __('super.einvoicing.public_key') }}</label>
                            <textarea class="form-control" rows="5" readonly style="font-family: monospace; font-size: 12px">{{ $publicKey }}</textarea>
                            <p class="form-hint">{{ __('super.einvoicing.public_key_hint') }}</p>
                        @endif
                    </div>
                </div>

                {{-- Printing --}}
                <div class="content-card mb-4">
                    <div class="card-header-custom">
                        <h2><i class="bi bi-qr-code me-2 text-muted"></i>{{ __('super.einvoicing.printing') }}</h2>
                    </div>
                    <div class="card-body-custom">
                        <div class="form-group mb-3">
                            <label class="form-label">{{ __('super.einvoicing.certification_text') }}</label>
                            <input type="text" name="certification_text" class="form-control" maxlength="200" value="{{ $val('certification_text') }}" placeholder="{{ \App\Models\Central\AgtProducerSetting::DEFAULT_CERTIFICATION_TEXT }}">
                            <p class="form-hint">{{ __('super.einvoicing.certification_text_hint') }}</p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">{{ __('super.einvoicing.qr_template') }}</label>
                            <input type="text" name="qr_template" class="form-control" maxlength="500" value="{{ $val('qr_template') }}" placeholder="{{ \App\Models\Central\AgtProducerSetting::DEFAULT_QR_TEMPLATE }}">
                            <p class="form-hint">{{ __('super.einvoicing.qr_template_hint') }} <code>{{ implode(' ', $placeholders) }}</code></p>
                        </div>
                    </div>
                </div>

                <div class="content-card mb-4">
                    <div class="card-body-custom">
                        <button type="submit" class="btn btn-primary" @disabled(! $migrated)><i class="bi bi-check-lg"></i> {{ __('super.einvoicing.save') }}</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="col-lg-4">
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-magic me-2 text-muted"></i>{{ __('super.einvoicing.generate_title') }}</h2>
                </div>
                <div class="card-body-custom">
                    <p class="text-muted fs-sm2">{{ __('super.einvoicing.generate_hint') }}</p>
                    <form method="POST" action="{{ route('super.settings.e-invoicing.generate-key') }}">
                        @csrf
                        <div class="form-group mb-3">
                            <label class="form-label">{{ __('super.einvoicing.key_size') }}</label>
                            <select name="bits" class="form-control">
                                <option value="4096">4096</option>
                                <option value="3072">3072</option>
                                <option value="2048">2048</option>
                            </select>
                        </div>
                        @if ($set('software_private_key'))
                            <label class="form-check d-flex align-items-center gap-2 mb-3">
                                <input type="checkbox" name="confirm_replace" value="1" class="form-check-input">
                                <span class="fs-sm2">{{ __('super.einvoicing.replace_confirm') }}</span>
                            </label>
                        @endif
                        <button type="submit" class="btn btn-outline" @disabled(! $migrated)><i class="bi bi-key"></i> {{ __('super.einvoicing.generate') }}</button>
                    </form>
                </div>
            </div>

            <div class="content-card">
                <div class="card-header-custom">
                    <h2><i class="bi bi-info-circle me-2 text-muted"></i>{{ __('super.einvoicing.how_title') }}</h2>
                </div>
                <div class="card-body-custom">
                    <ol class="fs-sm2 text-muted ps-3 mb-0">
                        <li class="mb-2">{{ __('super.einvoicing.how_1') }}</li>
                        <li class="mb-2">{{ __('super.einvoicing.how_2') }}</li>
                        <li class="mb-2">{{ __('super.einvoicing.how_3') }}</li>
                        <li>{{ __('super.einvoicing.how_4') }}</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ══════════════════════ DGI ══════════════════════ --}}
<div class="einv-pane" data-einv-pane="dgi" role="tabpanel">
    <p class="einv-pane-intro">{{ __('super.einvoicing.tab_dgi_desc') }}</p>
    <div class="row g-4">
        <div class="col-lg-8">
            <form method="POST" action="{{ route('super.settings.e-invoicing.provider.update', 'dgi') }}">
                @csrf
                @method('PUT')
                @include('central.super.settings.partials.einvoicing-platform-switch', ['provider' => 'dgi', 'providers' => $providers])

                <div class="content-card mb-4">
                    <div class="card-header-custom">
                        <h2><i class="bi bi-hourglass-split me-2 text-muted"></i>{{ __('super.einvoicing.dgi_status') }}</h2>
                    </div>
                    <div class="card-body-custom">
                        <p class="text-muted fs-sm2 mb-0">{{ __('super.einvoicing.dgi_status_text') }}</p>
                    </div>
                </div>

                <div class="content-card mb-4">
                    <div class="card-body-custom">
                        <button type="submit" class="btn btn-primary" @disabled(! $providersMigrated)><i class="bi bi-check-lg"></i> {{ __('super.einvoicing.save') }}</button>
                    </div>
                </div>
            </form>
        </div>
        <div class="col-lg-4">
            <div class="content-card">
                <div class="card-header-custom">
                    <h2><i class="bi bi-info-circle me-2 text-muted"></i>{{ __('super.einvoicing.how_title') }}</h2>
                </div>
                <div class="card-body-custom">
                    <ol class="fs-sm2 text-muted ps-3 mb-0">
                        <li class="mb-2">{{ __('super.einvoicing.dgi_how_1') }}</li>
                        <li class="mb-2">{{ __('super.einvoicing.dgi_how_2') }}</li>
                        <li>{{ __('super.einvoicing.dgi_how_3') }}</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.einv-tab[data-einv]'));
    var panes = Array.prototype.slice.call(document.querySelectorAll('.einv-pane[data-einv-pane]'));
    if (!tabs.length) return;

    function activate(name, updateHash) {
        var matched = false;
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-einv') === name;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            if (on) matched = true;
        });
        if (!matched) return;
        panes.forEach(function (p) { p.classList.toggle('is-active', p.getAttribute('data-einv-pane') === name); });
        if (updateHash !== false) history.replaceState(null, '', '#tab=einvoicing&einv=' + name);
    }

    tabs.forEach(function (t) {
        t.addEventListener('click', function () { activate(this.getAttribute('data-einv')); });
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-goto-einv]'), function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            activate(this.getAttribute('data-goto-einv'));
            var top = document.getElementById('panel-einvoicing');
            if (top) top.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    var m = (location.hash || '').match(/einv=([\w-]+)/);
    var invalid = document.querySelector('.einv-pane .is-invalid');
    if (invalid) {
        var p = invalid.closest('.einv-pane');
        if (p) activate(p.getAttribute('data-einv-pane'), false);
    } else if (m) {
        activate(m[1], false);
    }
});
</script>
@endpush
