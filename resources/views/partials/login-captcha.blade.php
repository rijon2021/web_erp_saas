{{--
  Login CAPTCHA (System Settings > Security, super admin Settings > Security).
  $captcha     = App\Support\Security\Captcha::widget()
  $groupClass  = wrapper class of a form row on the host page
  $shellClass  = wrapper class of an input on the host page
  $inputClass  = class of a text input on the host page
--}}
@php
  $groupClass = $groupClass ?? 'field';
  $shellClass = $shellClass ?? 'input-shell';
  $inputClass = $inputClass ?? '';
@endphp

@if ($captcha['provider'] === 'builtin')
<div class="{{ $groupClass }} login-captcha">
  <label for="captcha">{{ __('Security check') }}</label>
  @if (($captcha['mode'] ?? 'image') === 'image')
  <div class="login-captcha-challenge">
    <img src="{{ $captcha['image_url'] }}" alt="{{ __('Security code') }}" width="170" height="56" class="login-captcha-img" id="login_captcha_img">
    <button type="button" class="login-captcha-refresh" id="login_captcha_refresh" title="{{ __('Show a new code') }}" aria-label="{{ __('Show a new code') }}">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><polyline points="21 3 21 9 15 9"/></svg>
    </button>
  </div>
  @else
  <div class="login-captcha-question">{{ $captcha['question'] }}</div>
  @endif
  <div class="{{ $shellClass }}">
    <input id="captcha" type="text" name="captcha" class="{{ $inputClass }}" required autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="10"
      placeholder="{{ ($captcha['mode'] ?? 'image') === 'image' ? __('Type the characters shown') : __('Your answer') }}">
  </div>
</div>
@else
<div class="login-captcha login-captcha-external">
  <div class="{{ $captcha['class'] }}" data-sitekey="{{ $captcha['site_key'] }}"></div>
</div>
<script src="{{ $captcha['script'] }}" async defer></script>
@endif

<style>
  .login-captcha { margin-bottom: 16px; }
  .login-captcha-challenge { display: flex; align-items: center; gap: 10px; margin: 6px 0 8px; }
  .login-captcha-img { border-radius: 8px; border: 1px solid rgba(0, 0, 0, .12); background: #f4f5f7; display: block; }
  .login-captcha-refresh { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 8px; border: 1px solid rgba(0, 0, 0, .15); background: transparent; color: inherit; cursor: pointer; }
  .login-captcha-refresh:hover { background: rgba(0, 0, 0, .05); }
  .login-captcha-question { font-weight: 600; font-size: 18px; letter-spacing: 1px; margin: 6px 0 8px; }
  .login-captcha-external { display: flex; justify-content: center; max-width: 100%; }
  .login-captcha .input-wrapper input { padding-left: 0.9rem; padding-right: 0.9rem; }
</style>
<script>
  (function () {
    var btn = document.getElementById('login_captcha_refresh');
    var img = document.getElementById('login_captcha_img');
    if (!btn || !img) return;
    btn.addEventListener('click', function () {
      img.src = img.src.split('?')[0] + '?t=' + Date.now();
      var input = document.getElementById('captcha');
      if (input) { input.value = ''; input.focus(); }
    });
  })();
</script>
