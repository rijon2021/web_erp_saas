{{-- Gateways that charge a currency directly ($list: key => label). --}}
@php $online = array_diff_key($list, ['offline' => true]); @endphp
@if($online === [])
    <span class="badge bg-warning-subtle text-warning-emphasis" title="{{ __('super.currencies.no_gateway_hint') }}">
        <i class="bi bi-exclamation-triangle me-1"></i>{{ isset($list['offline']) ? __('super.currencies.only_bank') : __('super.currencies.no_gateway') }}
    </span>
@else
    @foreach($list as $key => $label)
        <span class="badge {{ $key === 'offline' ? 'bg-secondary-subtle text-secondary-emphasis' : 'bg-success-subtle text-success-emphasis' }} me-1">{{ $label }}</span>
    @endforeach
@endif
