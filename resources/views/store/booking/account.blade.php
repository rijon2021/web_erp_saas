@extends('layouts.store')

{{-- Booking theme: the signed-in customer's bookings, each linking to its status page. --}}
@section('content')
@php
  $badge = [
    'pending' => 'warn', 'requested' => 'warn', 'confirmed' => 'ok', 'completed' => 'ok',
    'declined' => 'bad', 'cancelled' => 'bad', 'no_show' => 'muted',
  ];
  $label = [
    'pending' => __('messages.BkmStatusPending'), 'requested' => __('messages.BkmStatusRequested'), 'confirmed' => __('messages.BkmStatusConfirmed'),
    'completed' => __('messages.BkmStatusCompleted'), 'declined' => __('messages.BkmStatusDeclined'), 'cancelled' => __('messages.BkmStatusCancelled'),
    'no_show' => __('messages.BkmStatusNoShow'),
  ];
@endphp
<div class="bk-page">
  <div class="bk-container">
    <div class="bk-status" style="max-width: 960px">
      <section class="bk-card">
        <div class="bk-status-top" style="margin-bottom: 14px">
          <h1 class="bk-h2">{{ __('messages.BkmMyBookings') }}</h1>
          @if($bkReady && Route::has('store.booking.book'))<a class="bk-btn bk-btn-sm" href="{{ route('store.booking.book') }}">{{ __('messages.BkmBookNow') }}</a>@endif
        </div>
        @if($bookings->isEmpty())
          <div class="bk-empty" style="padding: 24px 8px">
            <x-store.icon name="calendar" />
            <h3>{{ __('messages.BkmNoBookingsTitle') }}</h3>
            <p>{{ __('messages.BkmNoBookingsText') }}</p>
          </div>
        @else
          <div class="bk-table-wrap">
            <table class="bk-table">
              <thead>
                <tr>
                  <th scope="col">{{ __('messages.BkmRef') }}</th>
                  <th scope="col">{{ __('messages.BkmService') }}</th>
                  <th scope="col">{{ __('messages.BkmWhen') }}</th>
                  <th scope="col">{{ __('messages.Status') }}</th>
                </tr>
              </thead>
              <tbody>
                @foreach($bookings as $bk)
                  @php
                    $stg = $web->stage($bk);
                    $d = \Carbon\Carbon::parse($bk->booking_date);
                    $whenText = $stg === 'requested'
                      ? $d->translatedFormat('j M Y').' · '.__('messages.BkmWindow_'.$bk->preferred_window)
                      : $d->translatedFormat('j M Y').', '.substr((string) $bk->booking_time, 0, 5);
                  @endphp
                  <tr>
                    <td>
                      @if($bk->public_token && ($bk->source ?? '') === 'website')
                        <a class="bk-link" href="{{ store_base_url('appointment/'.$bk->public_token) }}">{{ $bk->Ref }}</a>
                      @else
                        {{ $bk->Ref }}
                      @endif
                    </td>
                    <td>{{ optional($bk->product)->name ?? '—' }}</td>
                    <td>{{ $whenText }}</td>
                    <td><span class="bk-badge bk-badge-{{ $badge[$stg] ?? 'muted' }}" style="padding: 3px 10px; font-size: 13px">{{ $label[$stg] ?? $stg }}</span></td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
      </section>
    </div>
  </div>
</div>
@endsection
