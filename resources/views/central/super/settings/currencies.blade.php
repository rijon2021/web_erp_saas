@extends('central.super.layout')

@section('title', __('super.currencies.title'))

@section('content')
<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.currencies.title') }}</h1>
        <p class="page-subtitle">{{ __('super.currencies.subtitle') }}</p>
    </div>
    <a href="{{ route('super.settings.general') }}#tab=features" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-toggles me-1"></i>{{ __('super.platform_features.tab') }}
    </a>
</div>

@unless($ready)
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>{{ __('super.currencies.migration_needed') }}</div>
@endunless
@if($ready && ! $enabled)
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-1"></i>{{ __('super.currencies.feature_off') }}
        <a href="{{ route('super.settings.general') }}#tab=features">{{ __('super.platform_features.tab') }}</a>
    </div>
@endif

<form method="POST" action="{{ route('super.settings.currencies.update') }}">
    @csrf
    @method('PUT')

    <div class="content-card mb-4">
        <div class="card-header-custom d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h2><i class="bi bi-currency-exchange me-2 text-muted"></i>{{ __('super.currencies.list_title') }}</h2>
            <button type="submit" class="btn btn-primary btn-sm" @disabled(! $ready)><i class="bi bi-save me-1"></i>{{ __('super.currencies.save') }}</button>
        </div>
        <div class="card-body-custom">
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>{{ __('super.currencies.col_currency') }}</th>
                            <th>{{ __('super.currencies.col_symbol') }}</th>
                            <th>{{ __('super.currencies.col_rate', ['main' => $main]) }}</th>
                            <th>{{ __('super.currencies.col_rounding') }}</th>
                            <th>{{ __('super.currencies.col_gateways') }}</th>
                            <th class="text-center">{{ __('super.currencies.col_active') }}</th>
                            <th style="width: 80px">{{ __('super.currencies.col_order') }}</th>
                            <th class="text-center">{{ __('super.currencies.col_remove') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Main currency: fixed, set in Settings → General. --}}
                        <tr class="table-light">
                            <td><strong>{{ $main }}</strong> <span class="badge bg-primary-subtle text-primary-emphasis ms-1">{{ __('super.currencies.main') }}</span></td>
                            <td>{{ \App\Support\SellingCurrencies::symbol($main) }}</td>
                            <td>1</td>
                            <td>—</td>
                            <td>@include('central.super.settings._currency_gateways', ['list' => $coverage[$main] ?? []])</td>
                            <td class="text-center"><i class="bi bi-check-lg text-success"></i></td>
                            <td>—</td>
                            <td></td>
                        </tr>
                        @forelse($rows as $code => $row)
                            @php $f = fn ($n) => 'rows[' . $code . '][' . $n . ']'; @endphp
                            <tr>
                                <td><strong>{{ $code }}</strong></td>
                                <td style="max-width: 110px">
                                    <input type="text" name="{{ $f('symbol') }}" class="form-control form-control-sm" maxlength="12"
                                           value="{{ $row->symbol }}" placeholder="{{ \App\Support\SellingCurrencies::SYMBOLS[$code] ?? $code }}">
                                </td>
                                <td class="text-muted fs-sm2" dir="ltr">
                                    {{ isset($rates[$code]) && $rates[$code] !== null ? '1 ' . $main . ' = ' . rtrim(rtrim(number_format($rates[$code], 4), '0'), '.') . ' ' . $code : __('super.currencies.no_rate') }}
                                </td>
                                <td>
                                    <select name="{{ $f('rounding') }}" class="form-select form-select-sm" title="{{ __('super.currencies.rounding_hint') }}">
                                        @foreach($roundings as $r)
                                            <option value="{{ $r }}" @selected(($row->rounding ?? 'whole') === $r)>{{ __('super.currencies.round_' . $r) }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>@include('central.super.settings._currency_gateways', ['list' => $coverage[$code] ?? []])</td>
                                <td class="text-center">
                                    <input type="hidden" name="{{ $f('is_active') }}" value="0">
                                    <input type="checkbox" class="form-check-input" name="{{ $f('is_active') }}" value="1" @checked($row->is_active)>
                                </td>
                                <td><input type="number" min="0" max="9999" name="{{ $f('sort_order') }}" class="form-control form-control-sm" value="{{ $row->sort_order }}"></td>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input" name="{{ $f('remove') }}" value="1" title="{{ __('super.currencies.remove_hint') }}">
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-muted">{{ __('super.currencies.none_yet') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="row g-2 align-items-end mt-2">
                <div class="col-sm-4 col-md-3">
                    <label class="form-label form-label-sm" for="addCurrency">{{ __('super.currencies.add') }}</label>
                    <select id="addCurrency" name="add" class="form-select form-select-sm" @disabled(! $ready)>
                        <option value="">—</option>
                        @php
                            // Most-asked first: Africa (West, East, South, CFA), then the rest.
                            $popular = ['USD', 'EUR', 'GBP', 'NGN', 'GHS', 'KES', 'ZAR', 'XOF', 'XAF', 'UGX', 'TZS', 'RWF', 'EGP', 'MAD', 'ZMW', 'ETB'];
                            $firsts = array_values(array_intersect($popular, $available));
                            $rest = array_values(array_diff($available, $firsts));
                        @endphp
                        @foreach(array_merge($firsts, $rest) as $code)
                            <option value="{{ $code }}">{{ $code }}{{ isset(\App\Support\SellingCurrencies::SYMBOLS[$code]) ? ' — ' . \App\Support\SellingCurrencies::SYMBOLS[$code] : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-8 col-md-9">
                    <p class="form-hint mb-1">{{ __('super.currencies.add_hint') }}</p>
                </div>
            </div>

            <hr class="settings-divider">

            <label class="form-switch-label d-flex align-items-center gap-2">
                <input type="hidden" name="auto_detect" value="0">
                <input type="checkbox" name="auto_detect" value="1" class="form-check-input" @checked($autoDetect)>
                <span><i class="bi bi-geo-alt me-1"></i>{{ __('super.currencies.auto_detect') }}</span>
            </label>
            <p class="form-hint mt-1">{{ __('super.currencies.auto_detect_hint') }}</p>
        </div>
    </div>
</form>

<div class="content-card">
    <div class="card-body-custom">
        <p class="form-hint mb-1"><i class="bi bi-info-circle me-1"></i>{{ __('super.currencies.how_hint') }}</p>
        <p class="form-hint mb-0"><i class="bi bi-link-45deg me-1"></i>{{ __('super.currencies.link_hint', ['example' => url('/register') . '?plan=1&currency=' . ($rows->keys()->first() ?? 'NGN')]) }}</p>
    </div>
</div>
@endsection
