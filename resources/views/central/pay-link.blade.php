@php
    $locale = app()->getLocale();
    $isRtl  = \App\Models\Central\CentralLanguage::isRtl($locale);
    $tenantName = $link->tenant->company_name ?? $link->tenant_id;
    $reference = strtoupper(substr($link->token, 0, 8));
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('central.PayLinkPageTitle') }} — {{ $appName }}</title>
    <link rel="icon" href="{{ \App\Models\Central\GeneralSetting::instance()->getPublicFaviconUrl() }}">
    <link href="{{ asset('assets_super/css/plus-jakarta-sans.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/central-shared.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/checkout.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/pay-link.css') }}" rel="stylesheet">
</head>
<body>
<div class="checkout-container">
    <div class="mb-4 pay-link-brand"><i class="bi bi-shield-lock me-1"></i> {{ $appName }}</div>

@if($state === 'pay')

    @if(request('cancelled'))
    <div class="alert alert-warning d-flex align-items-center gap-2 mb-4 checkout-alert">
        <i class="bi bi-exclamation-triangle-fill"></i> {{ __('central.PaymentCancelled') }}
    </div>
    @elseif($attempt && $attempt->status === 'failed')
    <div class="alert alert-danger d-flex align-items-center gap-2 mb-4 checkout-alert">
        <i class="bi bi-x-circle-fill"></i> {{ __('central.PayLinkAttemptFailed') }}
    </div>
    @elseif($attempt && $attempt->isPending())
    <div class="alert alert-info d-flex align-items-center gap-2 mb-4 checkout-alert">
        <i class="bi bi-info-circle-fill"></i>
        <span>{{ __('central.PayLinkNotConfirmedYet') }} <a href="{{ request()->fullUrl() }}">{{ __('central.PayLinkRefresh') }}</a></span>
    </div>
    @endif

    @if($errors->any())
    <div class="alert alert-danger d-flex align-items-center gap-2 mb-4 checkout-alert">
        <i class="bi bi-x-circle-fill"></i> {{ $errors->first() }}
    </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-5 order-lg-2">
            <div class="checkout-card sticky-sidebar-sm">
                <div class="checkout-card-header">
                    <i class="bi bi-receipt me-2 text-muted"></i>{{ __('central.PayLinkSummary') }}
                </div>
                <div class="checkout-card-body">
                    <p class="pay-link-title">{{ $link->title }}</p>
                    @if($link->description)
                        <p class="pay-link-desc mb-3">{{ $link->description }}</p>
                    @endif
                    <div class="pay-link-meta d-flex justify-content-between gap-3 mb-2">
                        <span class="label">{{ __('central.PayLinkBilledTo') }}</span>
                        <span class="value">{{ $tenantName }}</span>
                    </div>
                    <div class="pay-link-meta d-flex justify-content-between gap-3 mb-2">
                        <span class="label">{{ __('central.Reference') }}</span>
                        <span class="value" dir="ltr">{{ $reference }}</span>
                    </div>
                    @if($link->expires_at)
                    <div class="pay-link-meta d-flex justify-content-between gap-3 mb-2">
                        <span class="label">{{ __('central.PayLinkValidUntil') }}</span>
                        <span class="value">{{ $link->expires_at->format('M d, Y H:i') }}</span>
                    </div>
                    @endif
                    <hr class="checkout-separator">
                    <div class="d-flex justify-content-between">
                        <span class="checkout-total-label">{{ __('central.Total') }}</span>
                        <span class="checkout-total-value" dir="ltr">{{ $amountLabel }}@if($currencySymbol) {{ $link->currency }}@endif</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7 order-lg-1">
            <form method="POST" action="{{ route('central.pay-link.process', ['token' => $link->token]) }}" id="checkoutForm" enctype="multipart/form-data">
                @csrf

                <div class="checkout-card mb-4">
                    <div class="checkout-card-header">
                        <i class="bi bi-credit-card me-2 text-muted"></i>{{ __('central.PaymentMethod') }}
                    </div>
                    <div class="checkout-card-body">
                        @if(count($gateways) === 0)
                            <div class="text-center py-4">
                                <i class="bi bi-exclamation-triangle no-gateways-icon"></i>
                                <p class="text-muted mt-2">{{ __('central.NoGatewaysConfigured') }}</p>
                            </div>
                        @else
                            <div class="d-flex flex-column gap-2">
                                @foreach(array_values($gateways) as $i => $gw)
                                <label class="gateway-option {{ $i === 0 ? 'selected' : '' }}">
                                    <input type="radio" name="gateway" value="{{ $gw['key'] }}" {{ $i === 0 ? 'checked' : '' }} class="d-none gateway-radio">
                                    <div class="gateway-card">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="gateway-icon-box" style="background: {{ $gw['color'] }}15;">
                                                <i class="bi {{ $gw['icon'] }} gateway-icon" style="color: {{ $gw['color'] }};"></i>
                                            </div>
                                            <div>
                                                <p class="mb-0 fw-bold gateway-label">{{ $gw['label'] }}</p>
                                                <p class="mb-0 text-muted gateway-desc">
                                                    @if($gw['key'] === 'offline')
                                                        {{ __('central.PayViaBankTransfer') }}
                                                    @else
                                                        {{ __('central.PaySecurelyWith') }} {{ $gw['label'] }}
                                                    @endif
                                                </p>
                                            </div>
                                        </div>
                                        <div class="gateway-check"><i class="bi bi-check-circle-fill"></i></div>
                                    </div>
                                </label>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                @if(isset($gateways['offline']))
                {{-- Bank transfer: details + proof upload --}}
                <div class="checkout-card mb-4" id="offlineSection" style="display:none">
                    <div class="checkout-card-header">
                        <i class="bi bi-bank me-2 text-muted"></i>{{ __('central.BankTransferDetails') }}
                    </div>
                    <div class="checkout-card-body">
                        <div class="offline-amount-box mb-3">
                            <p class="mb-1 text-muted offline-amount-label">{{ __('central.AmountToTransfer') }}</p>
                            <p class="mb-0 fw-bold offline-amount-value" dir="ltr">{{ $amountLabel }}@if($currencySymbol) {{ $link->currency }}@endif</p>
                        </div>

                        @if(!empty($bankDetails) && (($bankDetails['bank_name'] ?? '') || ($bankDetails['account_number'] ?? '')))
                        <div class="bank-details-grid mb-3">
                            @foreach([
                                'bank_name'      => __('central.BankName'),
                                'account_holder' => __('central.AccountHolder'),
                                'account_number' => __('central.AccountNumber'),
                                'iban'           => __('central.IBAN'),
                                'swift'          => __('central.SwiftBic'),
                                'branch'         => __('central.Branch'),
                            ] as $field => $label)
                                @if($bankDetails[$field] ?? '')
                                <div class="bank-detail-row">
                                    <span class="bank-detail-label">{{ $label }}</span>
                                    <span class="bank-detail-value">{{ $bankDetails[$field] }}</span>
                                </div>
                                @endif
                            @endforeach
                        </div>
                        <div class="bank-instructions mb-3">
                            <p class="mb-1 fw-600 bank-instructions-label">{{ __('central.Instructions') }}</p>
                            <p class="mb-0 text-muted bank-instructions-text">
                                {{ __('central.PayLinkTransferReference', ['ref' => $reference]) }}
                                @if($bankDetails['instructions'] ?? '')<br>{{ $bankDetails['instructions'] }}@endif
                            </p>
                        </div>
                        @else
                        <div class="alert alert-warning d-flex align-items-center gap-2 mb-3 bank-not-configured">
                            <i class="bi bi-exclamation-triangle-fill"></i> {{ __('central.BankDetailsNotConfigured') }}
                        </div>
                        @endif

                        <hr class="checkout-separator">

                        <div class="mb-2">
                            <label class="form-label fw-600 upload-label">
                                <i class="bi bi-upload me-1"></i> {{ __('central.UploadProofOfPayment') }} <span class="text-danger">*</span>
                            </label>
                            <div class="offline-upload-area" id="uploadArea">
                                <input type="file" name="payment_proof" id="paymentProof" accept=".jpg,.jpeg,.png,.webp,.pdf" class="d-none">
                                <div id="uploadPlaceholder">
                                    <i class="bi bi-cloud-arrow-up upload-icon-placeholder"></i>
                                    <p class="mb-1 fw-600 upload-label">{{ __('central.ClickOrDragFileHere') }}</p>
                                    <p class="mb-0 text-muted upload-hint-text">{{ __('central.AllowedFileTypes') }}</p>
                                </div>
                                <div id="uploadPreview">
                                    <i class="bi bi-file-earmark-check upload-icon-preview"></i>
                                    <p class="mb-0 fw-600 upload-file-name" id="uploadFileName"></p>
                                    <button type="button" class="btn btn-sm btn-outline-danger mt-1" id="removeFile">
                                        <i class="bi bi-x-lg"></i> {{ __('central.Remove') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @endif

                @if(count($gateways) > 0)
                <button type="submit" class="btn-pay" id="payBtn">
                    <i class="bi bi-lock-fill me-2"></i>
                    <span id="payBtnText">{{ __('central.PayLinkPay') }} {{ $amountLabel }}</span>
                </button>
                <p class="text-center text-muted mt-3 checkout-secure-note" id="secureNote">
                    <i class="bi bi-shield-check"></i> {{ __('central.SecurePaymentNotice') }}
                </p>
                @endif
            </form>
            {{-- Add-on purchase: a way back to the workspace without paying. --}}
            @if(($link->tenant_addon_id ?? null) && $link->tenant && ($workspaceUrl = $link->tenant->getTenantUrl()) !== '')
                <p class="text-center mt-2 mb-0">
                    <a href="{{ rtrim($workspaceUrl, '/') }}/subscription/addons" class="text-muted small">
                        <i class="bi bi-arrow-left me-1"></i>{{ __('super.addons.back_to_workspace') }}
                    </a>
                </p>
            @endif
        </div>
    </div>

    <script src="{{ asset('assets_super/js/bootstrap.bundle.min.js') }}"></script>
    <script>
    window.CheckoutData = {
        currencySymbol: @json($currencySymbol),
        currencyCode:   @json($currencySymbol ? strtoupper($link->currency) : ''),
        prices:         { monthly: {{ (float) $link->amount }} },
        trans: {
            pay:                @json(__('central.PayLinkPay')),
            submitPaymentProof: @json(__('central.PayLinkSubmitProof')),
            securePayment:      @json(__('central.SecurePaymentNotice')),
            proofReviewed:      @json(__('central.PayLinkProofReviewed')),
            fileTooLarge:       @json(__('central.FileTooLarge')),
            submitting:         @json(__('central.Submitting')),
            redirecting:        @json(__('central.RedirectingToPayment')),
        },
    };
    </script>
    <script src="{{ asset('assets_super/js/checkout.js') }}"></script>

@else
    @php
        [$iconClass, $icon, $titleKey, $descKey] = match($state) {
            'paid'       => ['is-success', 'bi-check2-circle', 'central.PayLinkPaidTitle', 'central.PayLinkPaidDesc'],
            'review'     => ['is-pending', 'bi-hourglass-split', 'central.PayLinkReviewTitle', 'central.PayLinkReviewDesc'],
            'processing' => ['is-pending', 'bi-arrow-repeat', 'central.PayLinkProcessingTitle', 'central.PayLinkProcessingDesc'],
            'expired'    => ['is-muted', 'bi-clock-history', 'central.PayLinkExpiredTitle', 'central.PayLinkUnavailableDesc'],
            'refunded'   => ['is-muted', 'bi-arrow-counterclockwise', 'central.PayLinkRefundedTitle', 'central.PayLinkRefundedDesc'],
            default      => ['is-muted', 'bi-slash-circle', 'central.PayLinkCancelledTitle', 'central.PayLinkUnavailableDesc'],
        };
    @endphp
    <div class="pay-link-status-card">
        <div class="pay-link-status-icon {{ $iconClass }}"><i class="bi {{ $icon }}"></i></div>
        <h1 class="pay-link-status-title">{{ __($titleKey) }}</h1>
        <p class="pay-link-status-desc">{{ __($descKey) }}</p>

        <div class="info-rows mb-3">
            <div><span>{{ __('central.PayLinkFor') }}</span><span>{{ $link->title }}</span></div>
            <div><span>{{ __('central.PayLinkBilledTo') }}</span><span>{{ $tenantName }}</span></div>
            <div><span>{{ __('central.Total') }}</span><span dir="ltr">{{ $amountLabel }}</span></div>
            <div><span>{{ __('central.Reference') }}</span><span dir="ltr">{{ $reference }}</span></div>
            @if($state === 'paid' && $link->payment)
                <div><span>{{ __('central.PayLinkReceiptNo') }}</span><span dir="ltr">{{ $link->payment->invoice_number }}</span></div>
                <div><span>{{ __('central.PayLinkPaidOn') }}</span><span>{{ ($link->paid_at ?? $link->payment->paid_at)?->format('M d, Y H:i') }}</span></div>
            @endif
        </div>

        {{-- Add-on purchase / renewal: back to the workspace's Add-ons page. --}}
        @if(($link->tenant_addon_id ?? null) && $link->tenant && ($workspaceUrl = $link->tenant->getTenantUrl()) !== '')
            <a href="{{ rtrim($workspaceUrl, '/') }}/subscription/addons" class="btn btn-outline-secondary btn-sm mb-3">
                <i class="bi bi-arrow-left me-1"></i>{{ __('super.addons.back_to_workspace') }}
            </a>
        @endif

        @if($state === 'processing')
            <p class="text-muted small mb-0" id="processingNote"><span class="spinner-border spinner-border-sm me-1"></span> {{ __('central.PayLinkCheckingStatus') }}</p>
        @endif
    </div>

    @if($state === 'processing')
    @php $statusUrl = route('central.pay-link.status', ['token' => $link->token]); @endphp
    <script>
    (function () {
        var url = @json($statusUrl);
        var tries = 0;
        var timer = setInterval(function () {
            if (++tries > 36) { clearInterval(timer); return; } // ~3 minutes
            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (d && d.paid) { clearInterval(timer); window.location.reload(); }
                })
                .catch(function () {});
        }, 5000);
    })();
    </script>
    @endif
@endif
</div>
</body>
</html>
