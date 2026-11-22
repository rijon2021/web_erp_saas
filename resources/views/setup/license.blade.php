@extends('setup.main')
@section('content')

    <p class="eyebrow">Step 3 of 5</p>
    <h1>Licence</h1>
    <p class="lead-text">Activate your Stocky licence on <strong>{{ $domain ?: $siteUrl }}</strong>. Nothing is installed yet; the licence is only checked with ui-lib.com and saved at the end.</p>

    {{-- Already activated in this session (coming back from a later step) --}}
    @if ($activated)
        <div class="alert-banner success">
            <i class="fa fa-check-circle"></i>
            <span>
                <strong>Licence activated</strong> on {{ $activated['domain'] }}@if ($activated['local']) (local development host, no domain slot used)@endif.
                {{ $activated['product_name'] }} · {{ $activated['type'] }} · licence …{{ $activated['hint'] }}
            </span>
        </div>

        <form action="{{ route('setupLicense') }}" method="post">
            @csrf
            <input type="hidden" name="action" value="keep">
            <div class="pane-nav">
                <a href="/setup/step-1" class="btn btn-ghost"><i class="fa fa-angle-left"></i> Back</a>
                <span class="spacer"></span>
                <button type="submit" name="action" value="change" class="btn btn-ghost">Use another licence</button>
                <button type="submit" class="btn btn-accent">Continue <i class="fa fa-angle-right"></i></button>
            </div>
        </form>

    @else

        @if ($error)
            <div class="alert-banner danger">
                <i class="fa fa-exclamation-triangle"></i>
                <span>{{ $error }}</span>
            </div>
        @endif

        @if ($networkError)
            <div class="alert-banner warning">
                <i class="fa fa-plug"></i>
                <span><strong>Could not reach ui-lib.com.</strong> Check that this server allows outgoing HTTPS connections and try again. You can also continue now: the platform runs for {{ (int) config('uilib.grace_days', 14) }} days, then locks until you activate it from Settings → Licence.</span>
            </div>
        @endif

        @if ($invalidDomain)
            <div class="alert-banner warning">
                <i class="fa fa-globe"></i>
                <span><strong>"{{ $domain ?: $siteUrl }}" is not a valid host name</strong>, so the licence cannot be tied to it (underscores, for example, are not allowed in domain names — Laragon builds the host from the folder name). Rename the folder / virtual host to something like <code>my-shop.test</code>, update the Application URL in step 1, and activate again.</span>
            </div>
        @endif

        @if ($limit)
            <div class="alert-banner danger" style="flex-direction:column;">
                <div style="display:flex;gap:10px;align-items:flex-start;">
                    <i class="fa fa-exclamation-triangle"></i>
                    <span>{{ $limit['message'] }}</span>
                </div>
                <div class="domain-list">
                    @foreach ($limit['domains'] as $d)
                        <form action="{{ route('setupLicense') }}" method="post" class="domain-row">
                            @csrf
                            <input type="hidden" name="action" value="release">
                            <input type="hidden" name="domain" value="{{ $d }}">
                            <input type="hidden" name="license" value="{{ $license }}">
                            <input type="hidden" name="email" value="{{ $email }}">
                            <code>{{ $d }}</code>
                            <button type="submit" class="btn btn-ghost btn-sm">Deactivate there &amp; activate here</button>
                        </form>
                    @endforeach
                </div>
            </div>
        @endif

        <form action="{{ route('setupLicense') }}" method="post" id="licenseForm">
            @csrf
            <input type="hidden" name="action" value="activate">

            <div class="form-grid">
                <div class="form-group full">
                    <label for="license">Purchase code or licence key</label>
                    <span class="tip" title="Bought on ui-lib.com? Your licence key (ULK-…) is in your purchase email and in My Account → Purchase history. Bought on CodeCanyon? Use your purchase code from Envato → Downloads → License certificate & purchase code."><i class="fa fa-question-circle" aria-hidden="true"></i></span>
                    <input type="text" class="form-control" id="license" name="license" value="{{ $license }}" placeholder="ULK-XXXXX-XXXXX-XXXXX-XXXXX or your Envato purchase code" autocomplete="off" spellcheck="false" autofocus>
                    <small class="field-note">Bought on ui-lib.com? Your licence key is in your purchase email and in My Account → Purchase history. Bought on CodeCanyon? Use your purchase code from Envato → Downloads → License certificate &amp; purchase code.</small>
                </div>

                <div class="form-group full" id="emailGroup">
                    <label for="email">Email</label>
                    <span class="tip" title="Required with an Envato purchase code, so UI Lib can reach you about your licence."><i class="fa fa-question-circle" aria-hidden="true"></i></span>
                    <input type="email" class="form-control" id="email" name="email" value="{{ $email }}" placeholder="you@example.com" autocomplete="email">
                    <small class="field-note">Required with an Envato purchase code, so UI Lib can reach you about your licence.</small>
                </div>
            </div>

            <div class="pane-nav">
                <a href="/setup/step-1" class="btn btn-ghost"><i class="fa fa-angle-left"></i> Back</a>
                <span class="spacer"></span>
                @if ($networkError)
                    <button type="submit" name="action" value="skip" class="btn btn-ghost">Continue without activating</button>
                @endif
                <button type="submit" id="activate" class="btn btn-accent"><i class="fa fa-key"></i> Activate &amp; continue</button>
            </div>
        </form>

        <script>
            (function () {
                var license = document.getElementById('license');
                var group = document.getElementById('emailGroup');
                var email = document.getElementById('email');
                function sync() {
                    var v = (license.value || '').trim().toUpperCase();
                    var needs = v !== '' && v.indexOf('ULK-') !== 0;
                    group.style.display = needs ? '' : 'none';
                    email.required = needs;
                }
                license.addEventListener('input', sync);
                sync();
                document.getElementById('licenseForm').addEventListener('submit', function () {
                    var btn = document.getElementById('activate');
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fa fa-circle-o-notch fa-spin"></i> Checking with ui-lib.com…';
                });
            })();
        </script>

    @endif

@endsection
