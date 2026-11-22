@extends('central.super.layout')

@section('title', __('super.payment_links.show_title'))

@section('content')
@php
    $badge = match($link->effective_status) {
        'paid' => 'yes',
        'active' => 'info',
        'expired' => 'pending',
        'refunded' => 'info',
        default => 'no',
    };
    $hasOpenAttempts = $link->attempts->contains(fn ($a) => in_array($a->status, ['pending', 'superseded'], true) && $a->gateway !== 'offline');
@endphp

<div class="breadcrumb-custom">
    <a href="{{ route('super.payment-links.index') }}">{{ __('super.payment_links.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ \Illuminate\Support\Str::limit($link->title, 50) }}</span>
</div>

<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ $link->title }}</h1>
        <p class="page-subtitle">
            <span class="badge badge-{{ $badge }}">{{ __('super.payment_links.status_' . $link->effective_status) }}</span>
            &middot; {{ __('super.common.created') }} {{ $link->created_at->format('M d, Y H:i') }}
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        @if(! $link->isPaid() && $hasOpenAttempts)
            <form method="POST" action="{{ route('super.payment-links.refresh', $link) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-secondary"><i class="bi bi-arrow-repeat"></i> {{ __('super.payment_links.check_status') }}</button>
            </form>
        @endif
        @if($link->status === 'active')
            <form method="POST" action="{{ route('super.payment-links.cancel', $link) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-danger" data-swal-confirm="{{ json_encode(['title' => __('super.payment_links.cancel_link'), 'text' => __('super.payment_links.cancel_confirm'), 'icon' => 'warning', 'confirmButtonText' => __('super.payment_links.cancel_link'), 'confirmButtonColor' => '#dc2626']) }}"><i class="bi bi-x-circle"></i> {{ __('super.payment_links.cancel_link') }}</button>
            </form>
        @endif
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        {{-- Link --}}
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-link-45deg me-2 text-muted"></i>{{ __('super.payment_links.the_link') }}</h2>
            </div>
            <div class="card-body-custom">
                <div class="input-group">
                    <input type="text" class="form-control" value="{{ $link->url() }}" readonly id="plUrl" dir="ltr">
                    <button type="button" class="btn btn-outline js-copy-link" data-url="{{ $link->url() }}"><i class="bi bi-clipboard"></i> {{ __('super.payment_links.copy_link') }}</button>
                    <a href="{{ $link->url() }}" target="_blank" rel="noopener" class="btn btn-outline"><i class="bi bi-box-arrow-up-right"></i> {{ __('super.payment_links.open') }}</a>
                </div>
                @unless($link->isPayable() || $link->isPaid())
                    <p class="text-muted fs-sm2 mt-2 mb-0"><i class="bi bi-info-circle"></i> {{ __('super.payment_links.not_payable_hint') }}</p>
                @endunless
            </div>
        </div>

        {{-- Send --}}
        @if($link->isPayable())
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-send me-2 text-muted"></i>{{ __('super.payment_links.send_title') }}</h2>
            </div>
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.payment-links.send-email', $link) }}" class="mb-4">
                    @csrf
                    <label class="form-label"><i class="bi bi-envelope me-1"></i> {{ __('super.payment_links.by_email') }}</label>
                    <div class="input-group">
                        <input type="email" name="email_to" class="form-control" value="{{ old('email_to', $defaultEmail) }}" required maxlength="190">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-envelope"></i> {{ __('super.payment_links.send_email') }}</button>
                    </div>
                    @error('email_to')<p class="text-danger form-error">{{ $message }}</p>@enderror
                    <p class="text-muted fs-sm2 mt-1 mb-0">{{ __('super.payment_links.email_hint') }}</p>
                </form>

                <div class="divider"></div>

                <form method="POST" action="{{ route('super.payment-links.send-whatsapp', $link) }}" target="_blank">
                    @csrf
                    <label class="form-label"><i class="bi bi-whatsapp me-1"></i> {{ __('super.payment_links.by_whatsapp') }}</label>
                    <input type="text" name="phone" class="form-control mb-2" value="{{ old('phone', $defaultPhone) }}" required maxlength="32" placeholder="{{ __('super.payment_links.phone_hint') }}" dir="ltr">
                    @error('phone')<p class="text-danger form-error">{{ $message }}</p>@enderror
                    <textarea name="message" class="form-control mb-2" rows="6" required maxlength="2000">{{ old('message', $whatsappMessage) }}</textarea>
                    <button type="submit" class="btn btn-success"><i class="bi bi-whatsapp"></i> {{ __('super.payment_links.open_whatsapp') }}</button>
                    <p class="text-muted fs-sm2 mt-1 mb-0">{{ __('super.payment_links.whatsapp_hint') }}</p>
                </form>
            </div>
        </div>
        @endif

        {{-- Attempts --}}
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-receipt me-2 text-muted"></i>{{ __('super.payment_links.attempts') }}</h2>
                <span class="text-muted fs-sm2">{{ $link->attempts->count() }}</span>
            </div>
            @if($link->attempts->isNotEmpty())
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('super.payments.invoice') }}</th>
                            <th>{{ __('super.payments.gateway') }}</th>
                            <th>{{ __('super.common.amount') }}</th>
                            <th>{{ __('super.common.status') }}</th>
                            <th>{{ __('super.payments.date') }}</th>
                            <th class="action-cell"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($link->attempts as $attempt)
                        @php
                            $aBadge = match($attempt->status) {
                                'paid' => 'yes',
                                'failed' => 'no',
                                'refunded' => 'info',
                                default => 'pending',
                            };
                        @endphp
                        <tr>
                            <td><a href="{{ route('super.payments.show', $attempt) }}" class="fw-600 table-link-primary">{{ $attempt->invoice_number }}</a></td>
                            <td class="fs-sm2">{{ $attempt->gateway_label }}</td>
                            <td class="fs-sm3">
                                {{ $attempt->currency }} {{ number_format((float) $attempt->amount, 2) }}
                                @if($attempt->gateway_currency && strtoupper($attempt->gateway_currency) !== strtoupper($attempt->currency))
                                    <span class="text-muted fs-2xs d-block">{{ strtoupper($attempt->gateway_currency) }} {{ number_format((float) $attempt->gateway_amount, 2) }}</span>
                                @endif
                            </td>
                            <td><span class="badge badge-{{ $aBadge }}">{{ ucfirst($attempt->status) }}</span></td>
                            <td class="fs-sm2">{{ $attempt->created_at->format('M d, Y H:i') }}</td>
                            <td class="action-cell">
                                <a href="{{ route('super.payments.show', $attempt) }}" class="btn btn-outline btn-sm" title="{{ __('super.common.view') }}"><i class="bi bi-eye"></i></a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div class="card-body-custom text-muted fs-sm2">{{ __('super.payment_links.no_attempts') }}</div>
            @endif
        </div>
    </div>

    <div class="col-lg-4">
        {{-- Summary --}}
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-info-circle me-2 text-muted"></i>{{ __('super.payment_links.summary') }}</h2>
            </div>
            <div class="card-body-custom">
                <div class="mb-3">
                    <div class="text-muted fs-sm2">{{ __('super.common.amount') }}</div>
                    <div class="fw-700 fs-4">{{ $amountLabel }}</div>
                </div>
                <div class="mb-3">
                    <div class="text-muted fs-sm2">{{ __('super.payments.tenant') }}</div>
                    @if($tenant)
                        <a href="{{ route('super.tenants.show', $tenant) }}" class="fw-600 table-link-primary">{{ $tenant->company_name ?? $tenant->id }}</a>
                        @if($tenant->admin_email)<div class="text-muted fs-sm2">{{ $tenant->admin_email }}</div>@endif
                    @else
                        <span class="text-muted">{{ $link->tenant_id }}</span>
                    @endif
                </div>
                @if($link->description)
                <div class="mb-3">
                    <div class="text-muted fs-sm2">{{ __('super.common.description') }}</div>
                    <div class="fs-sm3">{!! nl2br(e($link->description)) !!}</div>
                </div>
                @endif
                <div class="mb-3">
                    <div class="text-muted fs-sm2">{{ __('super.payment_links.expires') }}</div>
                    <div class="fs-sm3">{{ $link->expires_at ? $link->expires_at->format('M d, Y H:i') : __('super.payment_links.no_expiry') }}</div>
                </div>
                @if($link->paid_at)
                <div class="mb-3">
                    <div class="text-muted fs-sm2">{{ __('super.payment_links.paid_at') }}</div>
                    <div class="fs-sm3">
                        {{ $link->paid_at->format('M d, Y H:i') }}
                        @if($link->payment)
                            &middot; <a href="{{ route('super.payments.show', $link->payment) }}" class="table-link-primary">{{ $link->payment->invoice_number }}</a>
                            ({{ $link->payment->gateway_label }})
                        @endif
                    </div>
                </div>
                @endif
                <div>
                    <div class="text-muted fs-sm2">{{ __('super.payment_links.created_by') }}</div>
                    <div class="fs-sm3">{{ $link->creator->name ?? '—' }}</div>
                </div>
            </div>
        </div>

        {{-- Reactivate --}}
        @if(in_array($link->effective_status, ['cancelled', 'expired'], true))
        <div class="content-card mb-4">
            <div class="card-header-custom">
                <h2><i class="bi bi-arrow-counterclockwise me-2 text-muted"></i>{{ __('super.payment_links.reactivate') }}</h2>
            </div>
            <div class="card-body-custom">
                <form method="POST" action="{{ route('super.payment-links.reactivate', $link) }}">
                    @csrf
                    <label class="form-label">{{ __('super.payment_links.new_expiry') }}</label>
                    <input type="datetime-local" name="expires_at" class="form-control mb-2" min="{{ now()->format('Y-m-d\TH:i') }}">
                    @error('expires_at')<p class="text-danger form-error">{{ $message }}</p>@enderror
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-arrow-counterclockwise"></i> {{ __('super.payment_links.reactivate') }}</button>
                </form>
            </div>
        </div>
        @endif

        {{-- Send history --}}
        <div class="content-card">
            <div class="card-header-custom">
                <h2><i class="bi bi-clock-history me-2 text-muted"></i>{{ __('super.payment_links.send_history') }}</h2>
            </div>
            <div class="card-body-custom">
                @forelse(array_reverse($link->send_log ?? []) as $entry)
                    <div class="d-flex gap-2 mb-2 fs-sm2">
                        <i class="bi {{ ($entry['channel'] ?? '') === 'whatsapp' ? 'bi-whatsapp text-success' : 'bi-envelope text-primary' }}"></i>
                        <div>
                            <span dir="ltr">{{ $entry['to'] ?? '' }}</span>
                            @if(! ($entry['ok'] ?? true))
                                <span class="badge badge-no">{{ __('super.payment_links.send_failed') }}</span>
                                @if(! empty($entry['error']))<div class="text-danger fs-2xs">{{ \Illuminate\Support\Str::limit($entry['error'], 160) }}</div>@endif
                            @endif
                            <div class="text-muted fs-2xs">{{ isset($entry['at']) ? \Carbon\Carbon::parse($entry['at'])->format('M d, Y H:i') : '' }}</div>
                        </div>
                    </div>
                @empty
                    <p class="text-muted fs-sm2 mb-0">{{ __('super.payment_links.never_sent') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets_super/js/payment-links.js') }}"></script>
@endpush
