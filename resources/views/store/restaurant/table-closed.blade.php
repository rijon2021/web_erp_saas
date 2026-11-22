@php
  $s = \App\Models\StoreSetting::first();
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>{{ $s->store_name ?? config('app.name') }}</title>
  <style>
    body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #faf7f2; color: #1f1a17; padding: 24px; text-align: center; }
    @media (prefers-color-scheme: dark) { body { background: #171412; color: #f4efe9; } }
    h1 { font-size: 20px; margin: 0 0 8px; }
  </style>
</head>
<body>
  <main>
    <h1>{{ $s->store_name ?? config('app.name') }}</h1>
    <p>{{ $message }}</p>
  </main>
</body>
</html>
