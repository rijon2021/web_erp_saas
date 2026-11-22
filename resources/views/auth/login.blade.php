<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="/css/master.css">
    <link rel="stylesheet" href="{{ global_asset('css/auth.css') }}?v={{ filemtime(public_path('css/auth.css')) }}">
    <link rel="icon" href="{{ global_asset(upload_path('settings') . '/' . ($app_settings->favicon ?? 'favicon.ico')) }}">
    <title>{{ $app_settings->app_name ?? 'Stocky | Ultimate Inventory With POS' }}</title>
  </head>

  @php
    // Settings > Login Page. Values are whitelisted by LoginPageAppearance;
    // data-text / data-default let the settings preview swap texts live.
    $look = \App\Support\LoginPageAppearance::forView($app_settings ?? null);
    $opt = $look['options'];
    $preview = $preview ?? false;
    $text = fn ($key, $default = '') => filled($app_settings->{$key} ?? null) ? $app_settings->{$key} : $default;
    $features = ['login_hero_feature_1', 'login_hero_feature_2', 'login_hero_feature_3'];
    $hasFeatures = collect($features)->contains(fn ($key) => filled($text($key)));
    $logo = filled($app_settings->logo ?? null) ? global_asset(upload_path('settings') . '/' . $app_settings->logo) : null;
  @endphp

  <body class="auth-login" style="{{ $look['style'] }}" @foreach ($look['attributes'] as $attr => $value) {{ $attr }}="{{ $value }}" @endforeach>
    <div class="auth-page">
      <section class="auth-hero">
        <div class="hero-content">
          <span class="hero-badge" data-text="login_hero_badge" @if (blank($text('login_hero_badge'))) hidden @endif>{{ $text('login_hero_badge') }}</span>
          <h1 class="hero-title" data-text="login_hero_title" data-default="Welcome back!">{{ $text('login_hero_title', 'Welcome back!') }}</h1>
          <p class="hero-subtitle" data-text="login_hero_subtitle" data-default="Sign in to access your account and keep your operations in sync.">{{ $text('login_hero_subtitle', 'Sign in to access your account and keep your operations in sync.') }}</p>
          <ul class="hero-features" @if (! $hasFeatures) hidden @endif>
            @foreach ($features as $key)
            <li data-text="{{ $key }}" @if (blank($text($key))) hidden @endif>{{ $text($key) }}</li>
            @endforeach
          </ul>
        </div>
      </section>

      <section class="auth-panel">
        <div class="auth-panel-inner">
          <header class="panel-header">
            @if ($logo)
            <img class="panel-logo" src="{{ $logo }}" alt="{{ $app_settings->app_name ?? 'logo' }}" @if (! $opt['show_logo']) hidden @endif />
            @endif
            <h2 class="panel-title" data-text="login_panel_title" data-default="Sign In">{{ $text('login_panel_title', 'Sign In') }}</h2>
            <p class="panel-subtitle" data-text="login_panel_subtitle" data-default="Access your dashboard and manage everything from one place.">{{ $text('login_panel_subtitle', 'Access your dashboard and manage everything from one place.') }}</p>
          </header>

          @if (session('status'))
          <div class="auth-alert success">{{ session('status') }}</div>
          @endif

          @if ($errors->any())
          <div class="auth-alert error">
            <ul>
              @foreach ($errors->all() as $error)
              <li>{{ $error }}</li>
              @endforeach
            </ul>
          </div>
          @endif

          <form id="login_form" method="POST" action="{{ route('login') }}">
            @csrf
            <div class="field">
              <label for="email">Email</label>
              <div class="input-shell">
                <span class="input-addon">@</span>
                <input id="email" type="email" name="email" value="{{ old('email') }}" placeholder="you@company.com" required />
              </div>
            </div>

            <div class="field">
              <label for="password">Password</label>
              <div class="input-shell">
                <span class="input-addon">••</span>
                <input id="password" type="password" name="password" placeholder="Enter your password" required />
                <button type="button" class="toggle-password" data-target="password" data-option="show_password_toggle" @if (! $opt['show_password_toggle']) hidden @endif>Show</button>
              </div>
            </div>

            @if (! empty($captcha))
              @include('partials.login-captcha', ['captcha' => $captcha, 'groupClass' => 'field', 'shellClass' => 'input-shell'])
            @endif

            <div class="form-meta">
              {{-- Not rendered at all (so the preview can't re-show it) while
                   System Settings > Security inactivity auto-logout is on. --}}
              @if ($rememberAllowed ?? true)
              <label class="remember-check" data-option="show_remember_me" @if (! $opt['show_remember_me']) hidden @endif>
                <input type="checkbox" name="remember" value="1" @checked(old('remember'))> Remember me
              </label>
              @endif
              <a class="auth-link" href="{{ route('password.request') }}" data-option="show_forgot_password" @if (! $opt['show_forgot_password']) hidden @endif>Forgot password?</a>
            </div>

            <button type="submit" class="auth-btn" id="login_submit_btn">
              <span class="btn-text" data-text="login_btn_text" data-default="Sign In">{{ $text('login_btn_text', 'Sign In') }}</span>
              <span class="btn-loading"><span class="spinner"></span>Verifying</span>
            </button>
          </form>

          <footer class="panel-footer" data-text="login_footer_text" @if (blank($text('login_footer_text'))) hidden @endif>{{ $text('login_footer_text') }}</footer>
        </div>
      </section>
    </div>

    @if ($preview)
    <script src="{{ global_asset('assets_super/js/auth-login-preview.js') }}?v={{ filemtime(public_path('assets_super/js/auth-login-preview.js')) }}"></script>
    @endif
    <script src="{{ global_asset('assets_super/js/auth-login.js') }}?v={{ filemtime(public_path('assets_super/js/auth-login.js')) }}"></script>
  </body>
</html>
