@extends('central.super.layout')

@section('title', __('super.addons.title'))

@section('content')
<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.addons.title') }}</h1>
        <p class="page-subtitle">{{ __('super.addons.subtitle') }}</p>
    </div>
</div>

@unless($ready)
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>{{ __('super.addons.migration_needed') }}
    </div>
@endunless
@if($ready && ! $extras)
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-1"></i>{{ __('super.addons.extras_migration_needed') }}
    </div>
@endif

{{-- ── Catalogue ── --}}
<form method="POST" action="{{ route('super.addons.update') }}">
    @csrf
    @method('PUT')
    <div class="content-card mb-4">
        <div class="card-header-custom d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h2><i class="bi bi-puzzle me-2 text-muted"></i>{{ __('super.addons.catalog_title') }}</h2>
            <div class="d-flex align-items-center gap-2">
                <input type="search" class="form-control form-control-sm" id="addonSearch" placeholder="{{ __('super.addons.search') }}" style="max-width: 220px">
                <button type="submit" class="btn btn-primary btn-sm" @disabled(! $ready)>
                    <i class="bi bi-save me-1"></i>{{ __('super.addons.save') }}
                </button>
            </div>
        </div>
        <div class="card-body-custom">
            <p class="form-hint mb-3">{{ __('super.addons.catalog_hint') }}</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle addon-table">
                    <thead>
                        <tr>
                            <th>{{ __('super.addons.col_addon') }}</th>
                            <th class="text-center">{{ __('super.addons.col_for_sale') }}</th>
                            <th>{{ __('super.addons.col_monthly') }}</th>
                            <th>{{ __('super.addons.col_yearly') }}</th>
                            <th>{{ __('super.addons.col_lifetime') }}</th>
                            @if($extras)
                                <th style="width: 90px">{{ __('super.addons.col_trial') }}</th>
                            @endif
                            <th>{{ __('super.addons.col_plans') }}</th>
                            <th style="width: 80px">{{ __('super.addons.col_order') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($catalog as $item)
                            @php
                                $row = $item['row'];
                                $k = $item['key'];
                                $field = fn ($f) => 'addons[' . $k . '][' . $f . ']';
                                $selectedPlans = (array) ($row?->plan_ids ?? []);
                            @endphp
                            <tr data-addon-row data-search="{{ strtolower($item['label'] . ' ' . $k) }}">
                                <td style="min-width: 240px">
                                    <div class="d-flex align-items-start gap-2">
                                        <i class="bi {{ $item['icon'] }} text-muted mt-1"></i>
                                        <div class="flex-grow-1">
                                            <div class="fw-600">{{ $row?->name ?: $item['label'] }}
                                                @if($item['type'] === 'theme')
                                                    <span class="badge bg-warning-subtle text-warning-emphasis ms-1">{{ __('super.addons.type_theme') }}</span>
                                                @elseif($item['type'] === 'limit')
                                                    <span class="badge bg-info-subtle text-info-emphasis ms-1">{{ __('super.addons.type_limit') }}</span>
                                                @endif
                                            </div>
                                            <code class="fs-xs3 text-muted">{{ $k }}</code>
                                            @if($item['type'] === 'limit')
                                                <div class="d-flex align-items-center gap-1 mt-1">
                                                    <span class="fs-xs3 text-muted">{{ __('super.addons.pack_size') }}</span>
                                                    <input type="number" min="1" name="{{ $field('pack_size') }}" class="form-control form-control-sm" style="max-width: 90px"
                                                           value="{{ old('addons.' . $k . '.pack_size', $row?->pack_size ?? 1) }}">
                                                </div>
                                            @endif
                                            <details class="mt-1">
                                                <summary class="fs-xs3 text-muted">{{ __('super.addons.customize_text') }}</summary>
                                                <input type="text" name="{{ $field('name') }}" class="form-control form-control-sm mt-1" maxlength="120"
                                                       value="{{ old('addons.' . $k . '.name', $row?->name) }}" placeholder="{{ $item['label'] }}">
                                                <textarea name="{{ $field('description') }}" class="form-control form-control-sm mt-1" rows="2" maxlength="1000"
                                                          placeholder="{{ $item['description'] }}">{{ old('addons.' . $k . '.description', $row?->description) }}</textarea>
                                                @if($extras)
                                                    <input type="text" name="{{ $field('requires') }}" class="form-control form-control-sm mt-1" maxlength="500" list="addonKeyList"
                                                           value="{{ old('addons.' . $k . '.requires', implode(', ', (array) ($row?->requires ?? []))) }}"
                                                           placeholder="{{ __('super.addons.requires_placeholder') }}" title="{{ __('super.addons.requires_hint') }}">
                                                @endif
                                            </details>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    {{-- No hidden "0": every row is in the form, so an absent box = off (saves input vars). --}}
                                    <input type="checkbox" class="form-check-input" name="{{ $field('is_active') }}" value="1" @checked(old('addons.' . $k . '.is_active', $row?->is_active))>
                                </td>
                                @foreach(['monthly_price', 'yearly_price', 'lifetime_price'] as $priceField)
                                    <td style="min-width: 110px">
                                        <input type="number" step="0.01" min="0" name="{{ $field($priceField) }}" class="form-control form-control-sm"
                                               value="{{ old('addons.' . $k . '.' . $priceField, $row?->{$priceField}) }}" placeholder="—">
                                    </td>
                                @endforeach
                                @if($extras)
                                    <td>
                                        @if($item['type'] !== 'limit')
                                            <input type="number" min="0" max="365" name="{{ $field('trial_days') }}" class="form-control form-control-sm"
                                                   value="{{ old('addons.' . $k . '.trial_days', $row?->trial_days) }}" placeholder="—">
                                        @endif
                                    </td>
                                @endif
                                <td style="min-width: 170px">
                                    <select name="{{ $field('plan_ids') }}[]" class="form-select form-select-sm" multiple size="2" title="{{ __('super.addons.plans_hint') }}">
                                        @foreach($plans as $plan)
                                            <option value="{{ $plan->id }}" @selected(in_array($plan->id, array_map('intval', $selectedPlans), true))>{{ $plan->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <input type="number" min="0" max="9999" name="{{ $field('sort_order') }}" class="form-control form-control-sm"
                                           value="{{ old('addons.' . $k . '.sort_order', $row?->sort_order ?? 0) }}">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="form-hint mt-2"><i class="bi bi-info-circle me-1"></i>{{ __('super.addons.prices_hint') }}</p>
            @if($extras)
                <p class="form-hint mt-1"><i class="bi bi-info-circle me-1"></i>{{ __('super.addons.extras_hint') }}</p>
                <datalist id="addonKeyList">
                    @foreach($catalog as $item)<option value="{{ $item['key'] }}">{{ $item['label'] }}</option>@endforeach
                </datalist>
            @endif
        </div>
    </div>
    {{-- LAST field on purpose: PHP drops inputs past max_input_vars from the
         end, so a missing marker means the post was cut short. --}}
    <input type="hidden" name="_catalog_complete" value="1">
</form>

{{-- ── Tenants' add-ons ── --}}
<div class="content-card">
    <div class="card-header-custom d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h2><i class="bi bi-receipt me-2 text-muted"></i>{{ __('super.addons.sales_title') }}</h2>
        <form method="GET" class="d-flex gap-2">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">{{ __('super.addons.all_statuses') }}</option>
                @foreach($statuses as $s)
                    <option value="{{ $s }}" @selected($status === $s)>{{ __('super.addons.status_' . $s) }}</option>
                @endforeach
            </select>
        </form>
    </div>
    <div class="card-body-custom">
        @if(! $sales || $sales->isEmpty())
            <p class="text-muted mb-0">{{ __('super.addons.sales_empty') }}</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>{{ __('super.addons.col_tenant') }}</th>
                            <th>{{ __('super.addons.col_addon') }}</th>
                            <th>{{ __('super.addons.col_cycle') }}</th>
                            <th>{{ __('super.addons.col_amount') }}</th>
                            <th>{{ __('super.addons.col_status') }}</th>
                            <th>{{ __('super.addons.col_ends') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sales as $sale)
                            <tr>
                                <td>
                                    @if($sale->tenant)
                                        <a href="{{ route('super.tenants.show', $sale->tenant) }}">{{ $sale->tenant->company_name ?: $sale->tenant_id }}</a>
                                    @else
                                        {{ $sale->tenant_id }}
                                    @endif
                                </td>
                                <td>{{ $sale->displayName() }}
                                    @if($sale->source === 'admin')<span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">{{ __('super.addons.source_admin') }}</span>@endif
                                </td>
                                <td>{{ __('super.addons.cycle_' . $sale->billing_cycle) }}</td>
                                <td dir="ltr">{{ $sale->currency }} {{ number_format((float) $sale->amount, 2) }}</td>
                                <td>@include('central.super.addons._status', ['row' => $sale])</td>
                                <td>{{ $sale->ends_at ? $sale->ends_at->format('M d, Y') : ($sale->status === 'active' ? __('super.addons.no_end') : '—') }}</td>
                                <td class="text-end">
                                    @if(in_array($sale->status, ['active', 'pending'], true))
                                        <form method="POST" action="{{ route('super.addons.revoke', $sale) }}" class="d-inline"
                                              onsubmit="return confirm(@js(__('super.addons.revoke_confirm')))">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('super.addons.revoke') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $sales->links() }}
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
document.getElementById('addonSearch')?.addEventListener('input', function () {
    var q = this.value.trim().toLowerCase();
    document.querySelectorAll('[data-addon-row]').forEach(function (row) {
        row.style.display = !q || row.getAttribute('data-search').indexOf(q) !== -1 ? '' : 'none';
    });
});
</script>
@endpush
