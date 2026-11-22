{{-- Side-view silhouette per body type (64×28, stroke = currentColor). --}}
@php
  $cdBodyPaths = [
    'suv' => 'M5 20v-7l3-1 5-6h28l7 6 9 1v7z',
    'sedan' => 'M4 20v-4l8-2 8-7h20l9 7 11 2v4z',
    'hatchback' => 'M8 20v-5l5-2 7-7h20l8 8 8 1v5z',
    'pickup' => 'M3 20v-6h5l5-7h15v7h33v6z',
    'coupe' => 'M4 20v-3l10-3 9-6h14l13 6 10 3v3z',
    'convertible' => 'M4 20v-4l10-2 4-4h4l3 4h14l7-3 2 3 10 2v4z',
    'wagon' => 'M4 20v-5l7-2 7-7h32l7 8 3 1v5z',
    'van' => 'M5 20V5h35l10 8 9 2v5z',
  ];
  $cdBodyPath = $cdBodyPaths[$type ?? ''] ?? $cdBodyPaths['sedan'];
  $cdWheels = ($type ?? '') === 'pickup' || ($type ?? '') === 'van' ? [16, 48] : [17, 47];
@endphp
<svg viewBox="0 0 64 28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true" focusable="false" @isset($class) class="{{ $class }}" @endisset><path d="{{ $cdBodyPath }}"/><circle cx="{{ $cdWheels[0] }}" cy="21" r="4" fill="var(--cd-card)"/><circle cx="{{ $cdWheels[1] }}" cy="21" r="4" fill="var(--cd-card)"/></svg>
