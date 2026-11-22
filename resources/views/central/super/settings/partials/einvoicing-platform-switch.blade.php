{{-- Platform kill-switch card shared by the ZATCA / FBR / AGT / DGI tabs. Expects $provider and $providers. --}}
@php
    $__row = $providers[$provider] ?? null;
    $__on = $__row ? (bool) $__row->enabled : true;
@endphp
<div class="content-card mb-4">
    <div class="card-header-custom">
        <h2><i class="bi bi-toggle-on me-2 text-muted"></i>{{ __('super.einvoicing.platform') }}</h2>
    </div>
    <div class="card-body-custom">
        <label class="form-switch-label d-flex align-items-center gap-2">
            <input type="checkbox" name="platform_enabled" value="1" class="form-check-input" @checked(old('platform_enabled', $__on)) @disabled(! $__row)>
            <span>{{ __('super.einvoicing.platform_enabled') }}</span>
            @if (! $__on)
                <span class="einv-badge off">{{ __('super.einvoicing.platform_off_badge') }}</span>
            @endif
        </label>
        <p class="form-hint mb-0">{{ __('super.einvoicing.platform_enabled_hint') }}</p>
    </div>
</div>
