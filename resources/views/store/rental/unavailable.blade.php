@extends('layouts.store')

{{-- Rental theme while the Rental module is off for this tenant (plan or
     module toggle) or its latest migration has not run yet. --}}
@section('content')
<div class="rn-container">
  <div class="rn-status">
    <div class="rn-empty">
      <x-store.icon name="key" />
      <h3>{{ $s->store_name ?: __('messages.Store') }}</h3>
      <p>{{ __('messages.RntUnavailable') }}</p>
      @if($s->contact_phone)<p style="margin-top: 12px"><a class="rn-btn rn-btn-sm" href="tel:{{ preg_replace('/\s+/', '', $s->contact_phone) }}"><x-store.icon name="phone" />{{ $s->contact_phone }}</a></p>@endif
    </div>
  </div>
</div>
@endsection
