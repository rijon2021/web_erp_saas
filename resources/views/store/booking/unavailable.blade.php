@extends('layouts.store')

{{-- Booking theme while Booking Management is off for this tenant (plan or
     module toggle) or its latest migrations have not run yet. --}}
@section('content')
<div class="bk-page">
  <div class="bk-container">
    <div class="bk-status">
      <div class="bk-card bk-empty">
        <x-store.icon name="calendar" />
        <h3>{{ $s->store_name ?: __('messages.Store') }}</h3>
        <p>{{ __('messages.BkmUnavailable') }}</p>
        @if($s->contact_phone)<p style="margin-top: 12px"><a class="bk-btn bk-btn-sm" href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}"><x-store.icon name="phone" />{{ $s->contact_phone }}</a></p>@endif
      </div>
    </div>
  </div>
</div>
@endsection
