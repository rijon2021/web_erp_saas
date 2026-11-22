@extends('layouts.store')

@section('content')
@if(($s->theme ?? 'default') !== 'rental')
  {{-- Reached from an email after the store left the Rental theme: bring its styles along. --}}
  @include('store.rental.partials.head')
@endif
@php
  $stages = [
    'pending' => ['icon' => 'clock', 'tone' => 'warn', 'title' => __('messages.RntStagePendingTitle'), 'text' => __('messages.RntStagePendingText')],
    'confirmed' => ['icon' => 'check-circle', 'tone' => 'ok', 'title' => __('messages.RntStageConfirmedTitle'), 'text' => __('messages.RntStageConfirmedText')],
    'contract' => ['icon' => 'key', 'tone' => 'ok', 'title' => __('messages.RntStageContractTitle'), 'text' => __('messages.RntStageContractText')],
    'cancelled' => ['icon' => 'x-circle', 'tone' => 'bad', 'title' => __('messages.RntStageCancelledTitle'), 'text' => __('messages.RntStageCancelledText')],
    'expired' => ['icon' => 'alert-circle', 'tone' => 'bad', 'title' => __('messages.RntStageExpiredTitle'), 'text' => __('messages.RntStageExpiredText')],
  ];
  $st = $stages[$stage] ?? $stages['pending'];
  $tone = ['warn' => ['var(--rn-warn-soft)', 'var(--rn-warn)'], 'ok' => ['var(--rn-ok-soft)', 'var(--rn-ok)'], 'bad' => ['var(--rn-bad-soft)', 'var(--rn-bad)']][$st['tone']];
  $done = ['pending' => 1, 'confirmed' => 2, 'contract' => 3][$stage] ?? 0;
  $defs = collect(\App\Support\RentalTypes::definition($itemType)['booking'] ?? [])->keyBy('id');
  $flavour = \App\Support\RentalTheme::flavour([$itemType]);
  $canCancel = in_array($stage, ['pending', 'confirmed'], true);
  $wa = (\App\Support\RentalTheme::options($s)['whatsapp'] ?? '');
@endphp
<div class="rn-container">
  <div class="rn-status">
    @if(session('rn_notice'))<div class="rn-alert rn-alert-ok" style="margin-bottom: 14px"><x-store.icon name="check-circle" /><span>{{ session('rn_notice') }}</span></div>@endif
    @if(session('rn_error'))<div class="rn-alert rn-alert-bad" style="margin-bottom: 14px"><x-store.icon name="alert-circle" /><span>{{ session('rn_error') }}</span></div>@endif

    <div class="rn-status-card">
      <div class="rn-status-top">
        <div>
          <span class="rn-status-ic" style="background: {{ $tone[0] }}; color: {{ $tone[1] }}"><x-store.icon :name="$st['icon']" /></span>
          <h1 class="rn-h2">{{ $st['title'] }}</h1>
          <p class="rn-sub">{{ $st['text'] }}</p>
        </div>
        <div style="text-align: end">
          <div class="rn-label">{{ __('messages.RntBookingNumber') }}</div>
          <div class="rn-price" style="font-size: 22px">{{ $r->reservation_number }}</div>
        </div>
      </div>

      @if($done > 0)
        <div class="rn-timeline" aria-hidden="true">
          <div class="{{ $done >= 1 ? 'is-done' : '' }}">{{ __('messages.RntStepRequested') }}</div>
          <div class="{{ $done >= 2 ? 'is-done' : '' }}">{{ __('messages.RntStepConfirmed') }}</div>
          <div class="{{ $done >= 3 ? 'is-done' : '' }}">{{ __('messages.RntStepRental') }}</div>
        </div>
      @endif

      <div class="rn-list" style="margin-top: 16px">
        @foreach($r->items as $line)
          @php $it = $line->item; $cover = $it ? $it->coverUrl() : null; @endphp
          <div class="rn-summary">
            @if($cover)<img src="{{ $cover }}" alt="">@else<div class="rn-card-ph"><x-store.icon name="package" /></div>@endif
            <div>
              <div class="rn-card-title" style="font-size: 17px">{{ $line->item_name }}@if($line->quantity > 1) × {{ $line->quantity }}@endif</div>
              <div class="rn-card-meta" style="margin-top: 4px">{{ $r->start_date->translatedFormat('d M Y H:i') }} → {{ $r->end_date->translatedFormat('d M Y H:i') }}</div>
            </div>
          </div>
        @endforeach
      </div>

      <div class="rn-block" style="margin-top: 16px">
        <table class="rn-specs"><tbody>
          <tr><th scope="row">{{ __('messages.RntPickup_'.$flavour) }}</th><td>{{ $r->start_date->translatedFormat('l d M Y, H:i') }}</td></tr>
          <tr><th scope="row">{{ __('messages.RntReturn_'.$flavour) }}</th><td>{{ $r->end_date->translatedFormat('l d M Y, H:i') }}</td></tr>
          <tr><th scope="row">{{ __('messages.RntEstimatedTotal') }}</th><td><b>{{ store_money($r->total_amount) }}</b></td></tr>
          @if((float) $r->deposit_amount > 0)<tr><th scope="row">{{ __('messages.RntDepositRefundable') }}</th><td>{{ store_money($r->deposit_amount) }}</td></tr>@endif
          @foreach($answers as $a)
            @php
              $def = $defs->get($a['id'] ?? '') ?: ['id' => $a['id'] ?? '', 'label' => $a['label'] ?? '', 'input' => $a['input'] ?? 'text'];
              $val = ($a['input'] ?? '') === 'location' ? (string) $a['value'] : \App\Support\RentalTypes::display($def, $a['value'] ?? null);
            @endphp
            @if($val !== null && $val !== '')<tr><th scope="row">{{ \App\Support\RentalTypes::fieldLabel($def) }}</th><td>{{ $val }}</td></tr>@endif
          @endforeach
          {{-- The customer's own note only: reservations.notes is staff's to edit. --}}
          @php $customerNote = ($r->request_fields ?? [])['notes'] ?? null; @endphp
          @if($customerNote)<tr><th scope="row">{{ __('messages.RntNotes') }}</th><td style="white-space: pre-line">{{ $customerNote }}</td></tr>@endif
        </tbody></table>
        <p class="rn-note">{{ __('messages.RntEstimateNote') }}</p>
      </div>

      <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px">
        <a href="{{ store_route_or('store.shop') }}" class="rn-btn rn-btn-ghost">{{ __('messages.RntBrowseMore') }}</a>
        @if($s->contact_phone)<a href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}" class="rn-btn rn-btn-ghost"><x-store.icon name="phone" />{{ __('messages.RntCallUs') }}</a>@endif
        @if($wa !== '')<a href="https://wa.me/{{ $wa }}?text={{ rawurlencode($r->reservation_number) }}" target="_blank" rel="noopener" class="rn-btn rn-btn-ghost">WhatsApp</a>@endif
        @if($canCancel)
          <form method="POST" action="{{ route('store.rental.booking.cancel', $r->public_token) }}" style="margin-inline-start: auto"
                onsubmit="event.preventDefault(); var f = this; (window.StoreDialog ? window.StoreDialog.confirm(@js(__('messages.RntCancelConfirm')), { type: 'warning', okText: @js(__('messages.RntCancelBooking')) }) : Promise.resolve(confirm(@js(__('messages.RntCancelConfirm'))))).then(function (ok) { if (ok) f.submit(); });">
            @csrf
            <button type="submit" class="rn-btn rn-btn-ghost" style="color: var(--rn-bad)">{{ __('messages.RntCancelBooking') }}</button>
          </form>
        @endif
      </div>
    </div>
    <p class="rn-note" style="text-align: center; margin-top: 14px">{{ __('messages.RntKeepLink') }}</p>
  </div>
</div>
@endsection
