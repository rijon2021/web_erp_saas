@extends('setup.main')
@section('content')

    @php
        // Fallback for the plain GET /setup/finish route; the controller passes real values after install.
        $setupBaseUrl = rtrim((string) config('app.url'), '/');
        if ($setupBaseUrl === '' || !preg_match('#^https?://#i', $setupBaseUrl)) {
            $setupBaseUrl = request()->getSchemeAndHttpHost();
        }
        $superAdminLoginUrl = $superAdminLoginUrl ?? $setupBaseUrl . '/super/login';
        // No default credentials: only the e-mail chosen during the install
        // is shown (never a password).
        $superAdminEmail = $superAdminEmail ?? null;
    @endphp

    <div class="finish-wrap">

        <div class="success-mark">
            <i class="fa fa-check"></i>
        </div>

        <p class="eyebrow">Setup complete</p>
        <h1>Installation successful</h1>
        <p class="lead-text">Stocky SaaS is installed and ready. Sign in to the super admin panel to configure plans, payment gateways and create your first tenant.</p>

        <div class="credentials-card">
            <h5><i class="fa fa-key"></i> Super admin access</h5>

            <div class="credential-row">
                <span class="credential-label"><i class="fa fa-link"></i> Login URL</span>
                <span class="credential-value-wrap">
                    <span class="credential-value" id="val-url">{{ $superAdminLoginUrl }}</span>
                    <button type="button" class="copy-btn" data-copy-target="val-url" aria-label="Copy login URL"><i class="fa fa-copy"></i><span>Copy</span></button>
                </span>
            </div>

            @if (! empty($superAdminEmail))
                <div class="credential-row">
                    <span class="credential-label"><i class="fa fa-envelope"></i> Email</span>
                    <span class="credential-value-wrap">
                        <span class="credential-value" id="val-email">{{ $superAdminEmail }}</span>
                        <button type="button" class="copy-btn" data-copy-target="val-email" aria-label="Copy email"><i class="fa fa-copy"></i><span>Copy</span></button>
                    </span>
                </div>

                <div class="credential-row">
                    <span class="credential-label"><i class="fa fa-lock"></i> Password</span>
                    <span class="credential-value-wrap">
                        <span class="credential-value">The password you chose during installation</span>
                    </span>
                </div>
            @endif
        </div>

        <div class="alert-banner warning" style="text-align:left;">
            <i class="fa fa-exclamation-triangle"></i>
            <span><strong>Tip:</strong> turn on two-factor authentication for the super admin account after your first login, and remove any APP_SUPER_ADMIN_* values from your .env file.</span>
        </div>

        <a href="{{ $superAdminLoginUrl }}" class="btn btn-accent">Go to super admin login <i class="fa fa-arrow-right"></i></a>

        <p class="finish-alt">Or go back to <a href="/">the homepage</a>.</p>

    </div>

@endsection
