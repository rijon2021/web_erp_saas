@extends('layouts.store')

@section('content')
@if(($s->theme ?? 'default') !== 'booking')
  {{-- Reached from an email after the store left the Booking theme: bring its styles along. --}}
  @include('store.booking.partials.head')
@endif
@php
  $stages = [
    'pending' => ['icon' => 'clock', 'tone' => 'warn', 'title' => __('messages.BkmStagePendingTitle'), 'text' => __('messages.BkmStagePendingText')],
    'requested' => ['icon' => 'clock', 'tone' => 'warn', 'title' => __('messages.BkmStageRequestedTitle'), 'text' => __('messages.BkmStageRequestedText')],
    'confirmed' => ['icon' => 'check-circle', 'tone' => 'ok', 'title' => __('messages.BkmStageConfirmedTitle'), 'text' => __('messages.BkmStageConfirmedText')],
    'completed' => ['icon' => 'check-circle', 'tone' => 'ok', 'title' => __('messages.BkmStageCompletedTitle'), 'text' => __('messages.BkmStageCompletedText')],
    'declined' => ['icon' => 'x-circle', 'tone' => 'bad', 'title' => __('messages.BkmStageDeclinedTitle'), 'text' => __('messages.BkmStageDeclinedText')],
    'cancelled' => ['icon' => 'x-circle', 'tone' => 'bad', 'title' => __('messages.BkmStageCancelledTitle'), 'text' => __('messages.BkmStageCancelledText')],
    'no_show' => ['icon' => 'alert-circle', 'tone' => 'muted', 'title' => __('messages.BkmStageNoShowTitle'), 'text' => __('messages.BkmStageNoShowText')],
  ];
  $st = $stages[$stage] ?? $stages['pending'];
  if ($stage === 'pending' && ! empty($deposit['payable'])) {
      $st['text'] = __('messages.BkmDepStageText');
  }
  $date = \Carbon\Carbon::parse($b->booking_date);
  $isRequest = $stage === 'requested';
  $done = ['pending' => 1, 'requested' => 1, 'confirmed' => 2, 'completed' => 3][$stage] ?? 0;
  $when = $isRequest
      ? $date->translatedFormat('l j F Y').' · '.__('messages.BkmWindow_'.$b->preferred_window)
      : $date->translatedFormat('l j F Y').', '.substr((string) $b->booking_time, 0, 5).($b->booking_end_time ? ' – '.substr((string) $b->booking_end_time, 0, 5) : '');
  $opts = \App\Support\BookingTheme::options($s);
  $holdUntil = $b->hold_until && $stage === 'pending' && $b->hold_until->isFuture() ? $b->hold_until : null;
  $showReason = in_array($stage, ['declined', 'cancelled'], true) && $b->cancel_reason && $b->cancelled_by !== 'customer';
  $cancelUrl = store_base_url('appointment/'.$b->public_token.'/cancel');
@endphp
<div class="bk-page">
  <div class="bk-container">
    <div class="bk-status">
      @if(session('bk_notice'))<div class="bk-alert bk-alert-ok"><x-store.icon name="check-circle" /><span>{{ session('bk_notice') }}</span></div>@endif
      @if(session('bk_error'))<div class="bk-alert bk-alert-bad"><x-store.icon name="alert-circle" /><span>{{ session('bk_error') }}</span></div>@endif

      @if(\App\Models\Booking::notificationsReady() && $b->hasProposal())
        @php
          $pDate = \Carbon\Carbon::parse($b->proposed_date);
          $pStaff = $b->proposed_staff_id ? \App\Models\BookingStaff::withTrashed()->whereKey($b->proposed_staff_id)->value('name') : null;
        @endphp
        <section class="bk-card" style="border: 2px solid var(--bk-accent)" aria-labelledby="bk-proposal">
          <h2 id="bk-proposal" style="font-size: 20px; margin: 0 0 6px">{{ __('messages.BkmProposalTitle') }}</h2>
          <p class="bk-sub" style="font-size: 15px">{{ __('messages.BkmProposalText') }}</p>
          <dl class="bk-facts-grid" style="margin-top: 14px">
            <div><dt>{{ __('messages.BkmDateTime') }}</dt><dd>{{ $pDate->translatedFormat('l j F Y') }}, {{ $b->proposed_time }}@if($b->proposed_end_time) – {{ $b->proposed_end_time }}@endif</dd></div>
            @if($pStaff)<div><dt>{{ __('messages.BkmWith') }}</dt><dd>{{ $pStaff }}</dd></div>@endif
            @if($b->proposal_note)<div style="grid-column: 1 / -1"><dt>{{ __('messages.BkmMailMessage') }}</dt><dd>{{ $b->proposal_note }}</dd></div>@endif
          </dl>
          <div class="bk-row-actions">
            <form method="POST" action="{{ store_base_url('appointment/'.$b->public_token.'/accept') }}">
              @csrf
              <button type="submit" class="bk-btn"><x-store.icon name="check-circle" />{{ __('messages.BkmProposalAccept') }}</button>
            </form>
            <form method="POST" action="{{ store_base_url('appointment/'.$b->public_token.'/decline-time') }}">
              @csrf
              <button type="submit" class="bk-btn bk-btn-ghost">{{ __('messages.BkmProposalAnother') }}</button>
            </form>
          </div>
        </section>
      @endif

      @if(! empty($deposit['payable']))
        <section class="bk-card" style="border: 2px solid var(--bk-accent)" aria-labelledby="bk-deposit">
          <h2 id="bk-deposit" style="font-size: 20px; margin: 0 0 6px">{{ __('messages.BkmDepPayTitle') }}</h2>
          <p class="bk-sub" style="font-size: 15px">
            {{ $stage === 'confirmed' ? __('messages.BkmDepPayTextConfirmed', ['amount' => $deposit['amount']]) : __('messages.BkmDepPayText', ['amount' => $deposit['amount']]) }}
          </p>
          @if($holdUntil)
            <p class="bk-note" style="margin-top: 6px"><x-store.icon name="clock" /><span>{{ __('messages.BkmDepPayBy', ['time' => $holdUntil->copy()->timezone(config('app.timezone'))->translatedFormat('H:i')]) }}</span></p>
          @endif
          @if($deposit['gateways'])
            <form method="POST" action="{{ store_base_url('appointment/'.$b->public_token.'/deposit') }}" class="bk-row-actions">
              @csrf
              @foreach($deposit['gateways'] as $key => $label)
                <button type="submit" name="gateway" value="{{ $key }}" class="bk-btn {{ $loop->first ? '' : 'bk-btn-ghost' }}"><x-store.icon name="credit-card" />{{ __('messages.BkmDepPayWith', ['gateway' => $label]) }}</button>
              @endforeach
            </form>
          @else
            <p class="bk-note" style="margin-top: 12px"><x-store.icon name="alert-circle" /><span>{{ __('messages.BkmDepNoGateway') }}</span></p>
          @endif
          @if($deposit['note'] !== '')
            <p class="bk-note" style="margin-top: 12px"><x-store.icon name="info" /><span>{{ $deposit['note'] }}</span></p>
          @endif
        </section>
      @endif

      <section class="bk-card">
        <div class="bk-status-top">
          <div>
            <div class="bk-sub" style="font-size: 14px">{{ __('messages.BkmBookingRef', ['ref' => $b->Ref]) }}</div>
            <h1 class="bk-h2" style="margin-top: 4px">{{ optional($b->product)->name ?? __('messages.BkmBooking') }}</h1>
          </div>
          <span class="bk-badge bk-badge-{{ $st['tone'] }}"><x-store.icon :name="$st['icon']" />{{ $st['title'] }}</span>
        </div>
        <p class="bk-sub" style="margin-top: 10px">{{ $st['text'] }}</p>
        @if($holdUntil && empty($deposit['payable']))
          <p class="bk-note" style="margin-top: 6px"><x-store.icon name="clock" /><span>{{ __('messages.BkmHeldUntil', ['time' => $holdUntil->copy()->timezone(config('app.timezone'))->translatedFormat('H:i')]) }}</span></p>
        @endif
        @if(! empty($deposit['later']))
          <p class="bk-note" style="margin-top: 6px"><x-store.icon name="credit-card" /><span>{{ __('messages.BkmDepLater', ['amount' => $deposit['amount']]) }}</span></p>
        @endif

        @if($done > 0)
          <ol class="bk-progress" aria-label="{{ __('messages.BkmProgress') }}">
            <li class="{{ $done >= 1 ? 'is-done' : '' }}">{{ __('messages.BkmStepRequested') }}</li>
            <li class="{{ $done >= 2 ? 'is-done' : '' }}">{{ __('messages.BkmStepConfirmed') }}</li>
            <li class="{{ $done >= 3 ? 'is-done' : '' }}">{{ __('messages.BkmStepVisit') }}</li>
          </ol>
        @endif

        <dl class="bk-facts-grid">
          <div><dt>{{ $isRequest ? __('messages.BkmPreferred') : __('messages.BkmDateTime') }}</dt><dd>{{ $when }}</dd></div>
          @if($b->staff && ! $isRequest)<div><dt>{{ __('messages.BkmWith') }}</dt><dd>{{ $b->staff->name }}</dd></div>@endif
          @if(($b->seats ?? 1) > 1)<div><dt>{{ __('messages.BkmPeople') }}</dt><dd>{{ $b->seats }}</dd></div>@endif
          @if($opts['show_prices'] && $b->price !== null && $priceDisplay !== 'hidden')
            <div><dt>{{ __('messages.BkmPrice') }}</dt><dd>{{ store_money((float) $b->price * max(1, (int) $b->seats)) }}</dd></div>
          @endif
          @if(! empty($deposit['ready']) && in_array($deposit['status'] ?? null, ['paid', 'refunded'], true))
            <div><dt>{{ __('messages.BkmDeposit') }}</dt><dd>{{ $deposit['status'] === 'paid' ? __('messages.BkmDepPaidFact', ['amount' => $deposit['paid']]) : __('messages.BkmDepRefundedFact') }}</dd></div>
          @endif
          @if($s->contact_address)<div><dt>{{ __('messages.BkmWhere') }}</dt><dd>{{ $s->contact_address }}</dd></div>@endif
          {{-- The name typed with this booking (a matched customer record's own name is not shown here). --}}
          @php $contactName = ((array) (($b->request_fields ?? [])['_contact'] ?? []))['name'] ?? null; @endphp
          @if($contactName)<div><dt>{{ __('messages.BkmName') }}</dt><dd>{{ $contactName }}</dd></div>@endif
          @foreach($answers as $a)<div><dt>{{ $a['label'] }}</dt><dd>{{ $a['value'] }}</dd></div>@endforeach
          @if($showReason)<div style="grid-column: 1 / -1"><dt>{{ __('messages.BkmReason') }}</dt><dd>{{ $b->cancel_reason }}</dd></div>@endif
        </dl>

        @if(in_array($stage, ['confirmed', 'pending', 'requested'], true))
          <div class="bk-row-actions">
            @if($stage === 'confirmed')
              <a class="bk-btn bk-btn-ghost" href="{{ store_base_url('appointment/'.$b->public_token.'/calendar.ics') }}"><x-store.icon name="calendar" />{{ __('messages.BkmAddToCalendar') }}</a>
            @endif
            @if($canCancel)
              <form method="POST" action="{{ $cancelUrl }}" onsubmit="return confirm(@js(__('messages.BkmCancelConfirm')));">
                @csrf
                <button type="submit" class="bk-btn bk-btn-danger">{{ __('messages.BkmCancelBooking') }}</button>
              </form>
            @endif
          </div>
          @if(! $canCancel && $customerCancel && $stage === 'confirmed')
            <p class="bk-note" style="margin-top: 12px"><x-store.icon name="info" /><span>{{ __('messages.BkmCancelTooLate', ['h' => $cancelHours]) }}</span></p>
          @elseif($canCancel && $stage === 'confirmed')
            <p class="bk-note" style="margin-top: 12px"><x-store.icon name="info" /><span>{{ __('messages.BkmCancelRule', ['h' => $cancelHours]) }}</span></p>
          @endif
        @endif
      </section>

      <section class="bk-card">
        <h2 style="font-size: 18px; margin: 0 0 10px">{{ __('messages.BkmWhatNext') }}</h2>
        <p class="bk-sub" style="font-size: 15px">{{ __('messages.BkmKeepLink') }}</p>
        <div class="bk-row-actions" style="border: 0; padding-top: 0; margin-top: 14px">
          @if($opts['whatsapp'] !== '')<a class="bk-btn" href="https://wa.me/{{ $opts['whatsapp'] }}" target="_blank" rel="noopener"><x-store.icon name="whatsapp" />{{ __('messages.BkmMessageUs') }}</a>@endif
          @if($s->contact_phone)<a class="bk-btn bk-btn-ghost" href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}"><x-store.icon name="phone" />{{ $s->contact_phone }}</a>@endif
          @if(($s->theme ?? 'default') === 'booking' && Route::has('store.booking.book'))<a class="bk-btn bk-btn-ghost" href="{{ route('store.booking.book') }}">{{ __('messages.BkmBookAgain') }}</a>@endif
        </div>
      </section>
    </div>
  </div>
</div>
@endsection
