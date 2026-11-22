@extends('layouts.store')

@section('content')
@php
  $title = __('messages.Trk_Title');
  $tr = $tracking;
  $steps = \App\Http\Controllers\StoreTrackingController::STEPS;
  $fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y, H:i') : '';
  $statusLabel = fn ($st) => $st ? __('messages.Trk_S_' . $st) : '';
  $tone = match ($tr['status'] ?? null) {
    'delivered' => 'success',
    'failed_attempt', 'returning', 'returned', 'cancelled' => 'danger',
    default => 'accent',
  };
@endphp

<section class="py-8 border-b border-line-subtle"
         style="background: linear-gradient(135deg, rgb(var(--color-accent-500) / .12), rgb(var(--color-accent-400) / .06));">
  <div class="container">
    <div class="flex items-center justify-between flex-wrap gap-3">
      <div>
        <span class="section-kicker">{{ __('messages.TrackOrder') }}</span>
        <h1 class="section-title mt-1">{{ $title }}</h1>
        <div class="text-fg-secondary mt-1">{{ __('messages.Trk_Intro') }}</div>
      </div>
      <nav aria-label="breadcrumb">
        <ol class="flex items-center gap-2 text-sm text-fg-muted m-0 p-0 list-none">
          <li><a class="hover:text-accent-500" href="{{ route('store.index') }}">{{ __('messages.Home') }}</a></li>
          <li aria-hidden="true">/</li>
          <li class="text-fg-primary" aria-current="page">{{ $title }}</li>
        </ol>
      </nav>
    </div>
  </div>
</section>

<section class="py-10">
  <div class="container">
    <div class="grid lg:grid-cols-[1fr_2fr] gap-5" style="align-items:start;">

      {{-- Search --}}
      <div class="card">
        <div class="card-body space-y-4">
          <form method="GET" action="{{ route('store.track') }}" class="space-y-3">
            <div>
              <label class="form-label" for="trk-t">{{ __('messages.Trk_Tracking_Number') }}</label>
              <input id="trk-t" type="text" name="t" class="input" value="{{ $query['t'] }}" maxlength="80" autocomplete="off">
            </div>
            <button type="submit" class="btn btn-primary w-full justify-center">
              <x-store.icon name="search" class="w-4 h-4" />{{ __('messages.Trk_Track') }}
            </button>
          </form>

          <div class="flex items-center gap-3 text-xs text-fg-muted uppercase">
            <span class="flex-1 border-t border-line-subtle"></span>{{ __('messages.Trk_Or') }}<span class="flex-1 border-t border-line-subtle"></span>
          </div>

          <form method="GET" action="{{ route('store.track') }}" class="space-y-3">
            <div>
              <label class="form-label" for="trk-order">{{ __('messages.Trk_Order_Number') }}</label>
              <input id="trk-order" type="text" name="order" class="input" value="{{ $query['order'] }}" maxlength="80" required>
            </div>
            <div>
              <label class="form-label" for="trk-phone">{{ __('messages.Trk_Phone') }}</label>
              <input id="trk-phone" type="tel" name="phone" class="input" value="{{ $query['phone'] }}" maxlength="30" required>
            </div>
            <button type="submit" class="btn btn-outline w-full justify-center">
              <x-store.icon name="package" class="w-4 h-4" />{{ __('messages.Trk_Track') }}
            </button>
          </form>
        </div>
      </div>

      {{-- Result --}}
      <div class="space-y-5">
        @if($searched && ! $tr)
          <div class="alert alert-danger" role="alert">{{ __('messages.Trk_Not_Found') }}</div>
        @endif

        @if($tr)
          <div class="card">
            <div class="card-body space-y-5">
              <div class="flex items-start justify-between flex-wrap gap-3">
                <div>
                  @if($tr['name'])
                    <div class="text-fg-secondary text-sm">{{ __('messages.Trk_Hello', ['name' => $tr['name']]) }}</div>
                  @endif
                  <div class="text-2xl font-semibold mt-1" style="color: rgb(var(--color-{{ $tone === 'accent' ? 'accent-500' : $tone }}));">
                    {{ $statusLabel($tr['status']) ?: __('messages.Trk_S_booked') }}
                  </div>
                </div>
                <div class="text-sm text-end">
                  <div class="text-fg-muted">{{ __('messages.Trk_Tracking_Number') }}</div>
                  <div class="font-mono font-semibold">{{ $tr['tracking_number'] }}</div>
                </div>
              </div>

              {{-- Progress --}}
              @if(! in_array($tr['status'], ['returning', 'returned', 'cancelled'], true))
                <ol class="m-0 p-0 list-none" style="display:grid; grid-template-columns:repeat(5, minmax(0, 1fr)); gap:4px;" aria-label="{{ __('messages.Trk_History') }}">
                  @foreach($steps as $i => $step)
                    @php $done = $i <= $tr['step']; @endphp
                    <li class="text-center">
                      <div class="rounded-full {{ $done ? '' : 'bg-line-subtle' }}" style="height:6px; {{ $done ? 'background: rgb(var(--color-accent-500));' : '' }}"></div>
                      <div class="mt-2 {{ $done ? 'text-fg-primary font-medium' : 'text-fg-muted' }}" style="font-size:12px; line-height:1.3;">{{ $statusLabel($step) }}</div>
                    </li>
                  @endforeach
                </ol>
              @endif

              @if($tr['status'] === 'failed_attempt')
                <div class="alert alert-warning">{{ __('messages.Trk_Failed_Help') }}</div>
              @elseif(in_array($tr['status'], ['returning', 'returned'], true))
                <div class="alert alert-warning">{{ __('messages.Trk_Returning_Help') }}</div>
              @endif

              <dl class="grid sm:grid-cols-3 gap-4 text-sm m-0">
                <div>
                  <dt class="text-fg-muted">{{ __('messages.Trk_Courier') }}</dt>
                  <dd class="font-medium m-0">{{ $tr['courier'] }}</dd>
                </div>
                <div>
                  <dt class="text-fg-muted">{{ __('messages.Trk_Shipped_On') }}</dt>
                  <dd class="font-medium m-0">{{ $fmt($tr['booked_at']) ?: '—' }}</dd>
                </div>
                <div>
                  @if($tr['delivered_at'])
                    <dt class="text-fg-muted">{{ __('messages.Trk_Delivered_On') }}</dt>
                    <dd class="font-medium m-0">{{ $fmt($tr['delivered_at']) }}</dd>
                  @elseif($tr['city'])
                    <dt class="text-fg-muted">{{ __('messages.Trk_Destination') }}</dt>
                    <dd class="font-medium m-0">{{ $tr['city'] }}</dd>
                  @endif
                </div>
              </dl>

              @if($tr['courier_link'])
                <a href="{{ $tr['courier_link'] }}" target="_blank" rel="noopener nofollow" class="inline-flex items-center gap-1 text-accent-500 hover:underline text-sm">
                  {{ __('messages.Trk_Courier_Site') }}<x-store.icon name="external" class="w-3.5 h-3.5" />
                </a>
              @endif
            </div>
          </div>

          <div class="card">
            <div class="card-body">
              <h2 class="font-semibold mb-4">{{ __('messages.Trk_History') }}</h2>
              @if(count($tr['events']))
                <ol class="m-0 p-0 list-none space-y-4">
                  @foreach($tr['events'] as $i => $e)
                    <li class="flex gap-3">
                      <span class="rounded-full shrink-0 {{ $i === 0 ? '' : 'bg-line-subtle' }}" style="width:10px; height:10px; margin-top:6px; {{ $i === 0 ? 'background: rgb(var(--color-accent-500));' : '' }}"></span>
                      <div class="min-w-0">
                        <div class="font-medium {{ $i === 0 ? 'text-fg-primary' : 'text-fg-secondary' }}">
                          {{ $e['status'] ? $statusLabel($e['status']) : $e['text'] }}
                          @if($e['status'] && $e['text'] && strcasecmp($e['text'], $e['status']) !== 0)
                            <span class="text-fg-muted font-normal">· {{ $e['text'] }}</span>
                          @endif
                        </div>
                        @if($e['detail'])
                          <div class="text-sm text-fg-secondary">{{ $e['detail'] }}</div>
                        @endif
                        <div class="text-xs text-fg-muted mt-0.5">
                          {{ $fmt($e['at']) }}@if($e['location']) · {{ $e['location'] }}@endif
                        </div>
                      </div>
                    </li>
                  @endforeach
                </ol>
              @else
                <p class="text-fg-muted m-0">{{ __('messages.Trk_No_History') }}</p>
              @endif
            </div>
          </div>
        @elseif(! $searched)
          <div class="card">
            <div class="card-body flex items-center gap-4">
              <span class="w-12 h-12 rounded-full flex items-center justify-center text-accent-500 shrink-0" style="background: rgb(var(--color-accent-500) / .1);">
                <x-store.icon name="truck" class="w-5 h-5" />
              </span>
              <p class="text-fg-secondary m-0">{{ __('messages.Trk_Intro') }}</p>
            </div>
          </div>
        @endif
      </div>
    </div>
  </div>
</section>
@endsection
