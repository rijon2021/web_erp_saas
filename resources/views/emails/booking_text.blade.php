{{-- Plain-text part of App\Mail\BookingMail: never rendered as HTML, so no entity escaping. --}}
{!! $data['title'] !!}

@if(! empty($data['intro'])){!! $data['intro'] !!}

@endif
@foreach($data['lines'] ?? [] as [$label, $value])
{!! $label !!}: {!! $value !!}
@endforeach
@if(! empty($data['button']))

{!! $data['button'][0] !!}: {!! $data['button'][1] !!}
@endif
@if(! empty($data['note']))

{!! $data['note'] !!}
@endif

— {!! $data['store'] !!}
