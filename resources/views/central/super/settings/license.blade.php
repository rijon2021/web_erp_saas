@extends('central.super.layout')

@section('title', __('super.license.title'))

@section('content')
@php
    $info = $state['data']['license'] ?? null;
    $domainData = $state['data'] ?? null;
    $fmt = fn ($v) => $v ? \Carbon\Carbon::parse(is_numeric($v) ? '@'.$v : $v)->format('Y-m-d H:i') : '—';
    $lastCodeText = [
        'not_activated'   => __('super.license.last_not_activated'),
        'license_inactive'=> __('super.license.last_inactive'),
        'invalid_license' => __('super.license.last_invalid'),
        'wrong_product'   => __('super.license.last_wrong_product'),
    ][$state['last_code'] ?? ''] ?? null;
@endphp

<div class="page-header">
    <h1>{{ __('super.license.title') }}</h1>
    <p class="page-subtitle">{{ __('super.license.subtitle') }}</p>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-key-fill me-2 text-muted"></i>{{ __('super.license.title') }}</h2>
            </div>
            <div class="card-body-custom">

                {{-- Table missing --}}
                @if (! ($state['available'] ?? false))
                    <div class="alert alert-error">{{ __('super.license.not_migrated') }}</div>

                {{-- Licensed --}}
                @elseif (($state['licensed'] ?? false) && $info)
                    @if ($offline)
                        <div class="alert alert-warning">{{ __('super.license.offline_details') }}</div>
                    @endif

                    <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
                        <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>{{ __('super.license.status_active') }}</span>
                        <strong>{{ $domainData['domain'] }}</strong>
                        @if (! empty($domainData['local']))
                            <span class="badge bg-info text-dark">{{ __('super.license.local_development') }}</span>
                        @endif
                    </div>

                    <table class="table table-sm align-middle mb-3">
                        <tbody>
                            <tr>
                                <th class="text-muted fw-normal" style="width: 38%">{{ __('super.license.product') }}</th>
                                <td>{{ $info['product_name'] ?? $info['product'] }} <span class="badge bg-secondary ms-1">{{ $info['type'] ?? '' }}</span></td>
                            </tr>
                            <tr>
                                <th class="text-muted fw-normal">{{ __('super.license.bought_on') }}</th>
                                <td>{{ ($info['source'] ?? '') === 'envato' ? __('super.license.source_envato') : __('super.license.source_store') }}
                                    @if (! empty($info['purchased_at'])) <span class="text-muted">· {{ $fmt($info['purchased_at']) }}</span> @endif
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted fw-normal">{{ __('super.license.number') }}</th>
                                <td><code>…{{ $info['hint'] ?? '' }}</code>
                                    @if (! empty($info['email'])) <span class="text-muted">· {{ $info['email'] }}</span> @endif
                                    @if (! empty($info['buyer'])) <span class="text-muted">· {{ __('super.license.buyer') }}: {{ $info['buyer'] }}</span> @endif
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted fw-normal">{{ __('super.license.support') }}</th>
                                <td>
                                    <span class="badge {{ ! empty($info['support_active']) ? 'bg-success' : 'bg-secondary' }}">
                                        {{ ! empty($info['support_active']) ? __('super.license.support_active') : __('super.license.support_expired') }}
                                    </span>
                                    @if (! empty($info['supported_until'])) <span class="text-muted ms-1">{{ __('super.license.supported_until') }} {{ $fmt($info['supported_until']) }}</span> @endif
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted fw-normal">{{ __('super.license.domains') }}</th>
                                <td>
                                    {{ $info['domains_used'] ?? 0 }} / {{ array_key_exists('max_domains', $info) && $info['max_domains'] === null ? __('super.license.unlimited') : ($info['max_domains'] ?? '—') }}
                                    @if (! empty($info['domains']))
                                        <div class="mt-1">
                                            @foreach ($info['domains'] as $d)
                                                <span class="badge {{ $d === $domainData['domain'] ? 'bg-primary' : 'bg-secondary' }} me-1">{{ $d }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted fw-normal">{{ __('super.license.this_domain') }}</th>
                                <td>{{ $domainData['domain'] }} @if (! empty($domainData['local'])) <span class="text-muted">· {{ __('super.license.local_development') }}</span> @endif</td>
                            </tr>
                            <tr>
                                <th class="text-muted fw-normal">{{ __('super.license.valid_until') }}</th>
                                <td>{{ $fmt($domainData['expires_at'] ?? null) }}</td>
                            </tr>
                            @if (! empty($state['last_checked_at']))
                            <tr>
                                <th class="text-muted fw-normal">{{ __('super.license.last_checked') }}</th>
                                <td>{{ $fmt($state['last_checked_at']) }}</td>
                            </tr>
                            @endif
                        </tbody>
                    </table>

                    <div class="d-flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('super.settings.license.check') }}">
                            @csrf
                            <button type="submit" class="btn btn-outline btn-sm"><i class="bi bi-arrow-clockwise"></i> {{ __('super.license.refresh') }}</button>
                        </form>
                        <form method="POST" action="{{ route('super.settings.license.deactivate') }}"
                              onsubmit="return confirm(@json(__('super.license.deactivate_confirm', ['domain' => $state['domain'] ?? ''])));">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle"></i> {{ __('super.license.deactivate_here') }}</button>
                        </form>
                    </div>

                {{-- Not licensed --}}
                @else
                    <div class="alert alert-warning">
                        <strong>{{ __('super.license.not_licensed') }}</strong><br>
                        {{ __('super.license.not_licensed_hint') }}
                    </div>

                    @if ($state['has_license'] ?? false)
                        <div class="alert alert-info">
                            @if ($lastCodeText)<div class="mb-2">{{ $lastCodeText }}</div>@endif
                            <form method="POST" action="{{ route('super.settings.license.reactivate') }}" class="d-flex align-items-center flex-wrap gap-2">
                                @csrf
                                <span>{{ __('super.license.reactivate_hint', ['key' => $state['license_masked'] ?? '']) }}</span>
                                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-key"></i> {{ __('super.license.reactivate') }}</button>
                            </form>
                        </div>
                    @endif

                    @if ($checkError)
                        <div class="alert alert-error">{{ $checkError }}</div>
                    @endif

                    @if ($limit)
                        <div class="alert alert-error">
                            <strong>{{ __('super.license.activation_limit_title') }}</strong>
                            <div class="mb-2">{{ $limit['message'] }}</div>
                            @foreach ($limit['domains'] as $d)
                                <form method="POST" action="{{ route('super.settings.license.deactivate') }}" class="d-flex align-items-center justify-content-between gap-2 py-1"
                                      onsubmit="return confirm(@json(__('super.license.deactivate_there_confirm', ['domain' => $d])));">
                                    @csrf
                                    <input type="hidden" name="domain" value="{{ $d }}">
                                    <input type="hidden" name="license" value="{{ $limit['license'] }}">
                                    <input type="hidden" name="email" value="{{ $limit['email'] }}">
                                    <code>{{ $d }}</code>
                                    <button type="submit" class="btn btn-outline-danger btn-sm">{{ __('super.license.deactivate_there') }}</button>
                                </form>
                            @endforeach
                        </div>
                    @endif

                    <form method="POST" action="{{ route('super.settings.license.activate') }}" id="licenseForm">
                        @csrf
                        <div class="form-group mb-4">
                            <label class="form-label" for="license">{{ __('super.license.key_or_code') }}</label>
                            <input type="text" name="license" id="license" class="form-control @error('license') is-invalid @enderror"
                                   value="{{ old('license') }}" placeholder="{{ $state['license_masked'] ?? 'ULK-XXXXX-XXXXX-XXXXX-XXXXX' }}" autocomplete="off" spellcheck="false">
                            @error('license')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <p class="form-hint">{{ __('super.license.help') }}</p>
                        </div>
                        <div class="form-group mb-4" id="emailGroup">
                            <label class="form-label" for="email">{{ __('super.license.email') }}</label>
                            <input type="email" name="email" id="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email') }}" autocomplete="email">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <p class="form-hint">{{ __('super.license.email_help') }}</p>
                        </div>
                        <button type="submit" class="btn btn-primary" id="activateBtn"><i class="bi bi-key"></i> {{ __('super.license.activate') }}</button>
                    </form>

                    <script>
                        (function () {
                            var license = document.getElementById('license');
                            var group = document.getElementById('emailGroup');
                            var email = document.getElementById('email');
                            function sync() {
                                var v = (license.value || '').trim().toUpperCase();
                                var needs = v !== '' && v.indexOf('ULK-') !== 0;
                                group.style.display = needs ? '' : 'none';
                                email.required = needs;
                            }
                            license.addEventListener('input', sync);
                            sync();
                            document.getElementById('licenseForm').addEventListener('submit', function () {
                                var b = document.getElementById('activateBtn');
                                b.disabled = true;
                                b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + @json(__('super.license.checking'));
                            });
                        })();
                    </script>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-info-circle me-2 text-muted"></i>{{ __('super.license.guide') }}</h2>
            </div>
            <div class="card-body-custom">
                <dl class="mb-3">
                    <dt class="text-muted fw-normal">{{ __('super.license.site_url') }}</dt>
                    <dd><code>{{ $state['site_url'] ?? '—' }}</code><br><small class="text-muted">{{ __('super.license.site_url_hint') }}</small></dd>
                    <dt class="text-muted fw-normal">{{ __('super.license.version') }}</dt>
                    <dd>v{{ $state['version'] ?? '—' }}</dd>
                </dl>
                <p class="text-muted fs-sm2">{{ __('super.license.daily_check_hint') }}</p>
                <p class="text-muted fs-sm2">{{ __('super.license.move_hint') }}</p>
                <p class="text-muted fs-sm2 mb-0">{{ __('super.license.lock_hint', ['days' => (int) config('uilib.grace_days', 14)]) }}</p>
            </div>
        </div>
    </div>
</div>
@endsection
