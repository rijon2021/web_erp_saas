@extends('layouts.store')

@section('content')
@php
  $stateBadge = [
    'active'    => 'badge-success',
    'delivered' => 'badge-success',
    'expired'   => 'badge-warning',
    'suspended' => 'badge-warning',
    'revoked'   => 'badge-danger',
    'inactive'  => 'badge-info',
  ];
  $stateLabel = [
    'active'    => __('messages.License_State_Active'),
    'delivered' => __('messages.License_State_Delivered'),
    'expired'   => __('messages.License_State_Expired'),
    'suspended' => __('messages.License_State_Suspended'),
    'revoked'   => __('messages.License_State_Revoked'),
    'inactive'  => __('messages.License_State_Inactive'),
  ];
@endphp

<section class="border-b border-line-subtle"
         style="background: linear-gradient(135deg, rgb(var(--color-accent-500) / .04), rgb(var(--color-bg-surface)));">
  <div class="container py-6 flex items-center justify-between flex-wrap gap-3">
    <div>
      <span class="section-kicker">{{ __('messages.Account') }}</span>
      <h1 class="section-title mt-1">{{ __('messages.License_My_Licenses') }}</h1>
      <div class="text-fg-muted text-sm mt-1">{{ __('messages.License_Page_Hint') }}</div>
    </div>
    <a href="{{ store_base_url('account') }}" class="btn btn-outline">
      <x-store.icon name="user" class="w-4 h-4" />{{ __('messages.MyAccount') }}
    </a>
  </div>
</section>

<div class="container py-8">
  <div class="max-w-4xl mx-auto space-y-4">

    @if(session('license_status'))
      <div class="alert alert-success flex items-start gap-2">
        <x-store.icon name="check-circle" class="w-5 h-5 mt-0.5 shrink-0" />
        <span>{{ session('license_status') }}</span>
      </div>
    @endif

    @error('verify')
      <div class="alert alert-danger flex items-start gap-2">
        <x-store.icon name="alert-circle" class="w-5 h-5 mt-0.5 shrink-0" />
        <span>{{ $message }}</span>
      </div>
    @enderror

    @if($needsVerification ?? false)
      <div class="card">
        <div class="card-body">
          <div class="empty-state py-10">
            <div class="empty-icon"><x-store.icon name="shield-lock" class="w-10 h-10" /></div>
            <h3>{{ __('messages.Verify_Title') }}</h3>
            <p>{{ __('messages.Verify_Hint', ['email' => $email ?? '']) }}</p>
            @if(($keyCount ?? 0) > 0)
              <p class="text-sm text-fg-muted">{{ __('messages.Verify_Keys_Waiting', ['n' => $keyCount]) }}</p>
            @endif
            <form method="POST" action="{{ store_base_url('my/email/verify') }}" class="mt-4">
              @csrf
              <button class="btn btn-primary" type="submit">
                <x-store.icon name="check" class="w-4 h-4" />{{ __('messages.Verify_Send_Button') }}
              </button>
            </form>
          </div>
        </div>
      </div>
    @elseif($licenses->isEmpty())
      <div class="card">
        <div class="card-body">
          <div class="empty-state py-10">
            <div class="empty-icon"><x-store.icon name="key" class="w-10 h-10" /></div>
            <h3>{{ __('messages.License_None') }}</h3>
            <p>{{ __('messages.License_None_Hint') }}</p>
          </div>
        </div>
      </div>
    @endif

    @foreach($licenses as $lic)
      <div class="card">
        <div class="card-body">
          <div class="flex items-start justify-between gap-3 flex-wrap">
            <div class="min-w-0">
              <div class="font-semibold text-fg-primary flex items-center gap-2">
                <x-store.icon name="key" class="w-4 h-4 text-accent-500" />
                <span class="truncate">{{ $lic['product'] }}@if($lic['edition']) — {{ $lic['edition'] }}@endif</span>
              </div>
              <div class="text-xs text-fg-muted mt-1">
                @if($lic['order_ref'])
                  {{ __('messages.Order') }}
                  @if($lic['order_id'])
                    <a class="underline" href="{{ store_base_url('account/orders/'.$lic['order_id']) }}">{{ $lic['order_ref'] }}</a>
                  @else
                    {{ $lic['order_ref'] }}
                  @endif
                  ·
                @endif
                {{ $lic['purchased_at'] }}
              </div>
            </div>
            <span class="badge {{ $stateBadge[$lic['state']] ?? 'badge-info' }}">{{ $stateLabel[$lic['state']] ?? $lic['state'] }}</span>
          </div>

          <div class="mt-4 flex items-center gap-2 flex-wrap">
            <code class="px-3 py-2 rounded-lg bg-bg-muted text-fg-primary font-semibold tracking-wide break-all" style="font-size:15px">{{ $lic['key'] }}</code>
            @if($lic['state'] !== 'revoked')
              <button type="button" class="btn btn-outline btn-sm" data-copy="{{ $lic['key'] }}">
                <x-store.icon name="copy" class="w-4 h-4" /><span>{{ __('messages.License_Copy') }}</span>
              </button>
            @endif
          </div>

          @if($lic['generated'])
            <div class="grid sm:grid-cols-3 gap-3 mt-4 text-sm">
              <div>
                <div class="text-fg-muted text-xs">{{ __('messages.License_Valid_Until') }}</div>
                <div class="font-medium">{{ $lic['expires_at'] ?: __('messages.License_Lifetime') }}</div>
              </div>
              <div>
                <div class="text-fg-muted text-xs">{{ __('messages.License_Activations') }}</div>
                <div class="font-medium">{{ count($lic['activations']) }} / {{ $lic['max_activations'] ?: '∞' }}</div>
              </div>
              <div>
                <div class="text-fg-muted text-xs">{{ __('messages.License_Locked_To') }}</div>
                <div class="font-medium">
                  {{ $lic['restriction'] === 'domain' ? __('messages.License_Restriction_Domain') : ($lic['restriction'] === 'device' ? __('messages.License_Restriction_Device') : __('messages.License_Restriction_None')) }}
                </div>
              </div>
            </div>

            @if(count($lic['activations']))
              <div class="mt-4 border-t border-line-subtle pt-3">
                <div class="text-xs text-fg-muted mb-2">{{ __('messages.License_Activated_On') }}</div>
                <ul class="space-y-2">
                  @foreach($lic['activations'] as $a)
                    <li class="flex items-center justify-between gap-3 text-sm">
                      <span class="min-w-0 truncate">
                        <x-store.icon name="{{ $a['kind'] === 'domain' ? 'globe' : 'laptop' }}" class="w-4 h-4 inline text-fg-muted" />
                        {{ $a['label'] ?: $a['identifier'] }}
                        @if($a['label'])<span class="text-fg-muted text-xs">({{ $a['identifier'] }})</span>@endif
                        <span class="text-fg-muted text-xs">· {{ $a['activated_at'] }}</span>
                      </span>
                      <form method="POST" action="{{ store_base_url('my/licenses/'.$lic['id'].'/activations/'.$a['id'].'/deactivate') }}"
                            onsubmit="return confirm(@js(__('messages.License_Remove_Device_Confirm')))">
                        @csrf
                        <button class="btn btn-ghost btn-sm text-danger" type="submit">
                          <x-store.icon name="trash" class="w-4 h-4" />{{ __('messages.License_Remove_Device') }}
                        </button>
                      </form>
                    </li>
                  @endforeach
                </ul>
              </div>
            @endif
          @endif
        </div>
      </div>
    @endforeach
  </div>
</div>

<script>
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    var text = btn.getAttribute('data-copy');
    var label = btn.querySelector('span');
    var done = function () {
      if (!label) return;
      var old = label.textContent;
      label.textContent = @json(__('messages.License_Copied'));
      setTimeout(function () { label.textContent = old; }, 1500);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done);
    } else {
      var ta = document.createElement('textarea');
      ta.value = text; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (err) {}
      document.body.removeChild(ta);
    }
  });
</script>
@endsection
