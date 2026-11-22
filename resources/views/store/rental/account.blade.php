@extends('layouts.store')

@section('content')
@if(($s->theme ?? 'default') !== 'rental')
  @include('store.rental.partials.head')
@endif
@php
  $stageTone = ['pending' => 'warn', 'confirmed' => 'ok', 'contract' => 'ok', 'cancelled' => 'muted', 'expired' => 'muted'];
  $contractTone = ['active' => 'ok', 'ended' => 'muted', 'terminated' => 'muted', 'renewed' => 'muted'];
@endphp
<div class="rn-container">
  <div class="rn-page-head">
    <nav class="rn-crumbs" aria-label="{{ __('messages.RntBreadcrumb') }}"><a href="{{ route('store.index') }}">{{ __('messages.Home') }}</a><span>/</span><a href="{{ store_base_url('account') }}">{{ __('messages.Account') }}</a><span>/</span><span>{{ __('messages.RntMyBookings') }}</span></nav>
    <h1 class="rn-h2">{{ __('messages.RntMyBookings') }}</h1>
  </div>

  <div style="padding-bottom: 64px; display: grid; gap: 28px">
    <section>
      <h2 class="rn-h2" style="font-size: 20px; margin-bottom: 12px">{{ __('messages.RntBookingRequests') }}</h2>
      @if($bookings->isEmpty())
        <div class="rn-empty"><x-store.icon name="calendar" /><h3>{{ __('messages.RntNoBookings') }}</h3><p><a href="{{ store_route_or('store.shop') }}" class="rn-btn rn-btn-sm" style="margin-top: 10px">{{ __('messages.RntBrowse') }}</a></p></div>
      @else
        <div class="rn-list">
          @foreach($bookings as $b)
            @php $stg = $b->customerStage(); @endphp
            @if($b->public_token)<a class="rn-row" href="{{ store_base_url('rentals/booking/'.$b->public_token) }}">@else<div class="rn-row">@endif
              <div>
                <b>{{ $b->items->map(fn ($l) => $l->item_name.($l->quantity > 1 ? ' ×'.$l->quantity : ''))->implode(', ') }}</b>
                <small>{{ $b->reservation_number }} · {{ $b->start_date->translatedFormat('d M Y H:i') }} → {{ $b->end_date->translatedFormat('d M Y H:i') }} · {{ store_money($b->total_amount) }}</small>
              </div>
              <span class="rn-badge rn-badge-{{ $stageTone[$stg] ?? 'muted' }}">{{ __('messages.RntStage_'.$stg) }}</span>
            @if($b->public_token)</a>@else</div>@endif
          @endforeach
        </div>
      @endif
    </section>

    @if($contracts->isNotEmpty())
      <section>
        <h2 class="rn-h2" style="font-size: 20px; margin-bottom: 12px">{{ __('messages.RntMyContracts') }}</h2>
        <div class="rn-list">
          @foreach($contracts as $c)
            <div class="rn-row">
              <div>
                <b>{{ $c->items->map(fn ($l) => $l->item_name)->implode(', ') }}</b>
                <small>{{ $c->contract_number }} · {{ optional($c->start_date)->translatedFormat('d M Y') }}@if($c->end_date) → {{ $c->end_date->translatedFormat('d M Y') }}@endif</small>
              </div>
              <span class="rn-badge rn-badge-{{ $contractTone[$c->status] ?? 'muted' }}">{{ __('messages.RntContract_'.$c->status) }}</span>
            </div>
          @endforeach
        </div>
      </section>
    @endif
  </div>
</div>
@endsection
