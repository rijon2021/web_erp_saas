@extends('setup.main')
@section('content')
    <meta name="csrf_token" content="{{ csrf_token() }}" />

    <div class="loader d-none">Loading...</div>
    <p class="loader loader-caption d-none">Installing Stocky SaaS — writing configuration, migrating the central database, seeding plans and the super admin account, and generating API keys. This can take a few minutes, keep this tab open.</p>

    <div class="d-block" id="content">

        <p class="eyebrow">Step 5 of 5</p>
        <h1>Review &amp; install</h1>
        <p class="lead-text">These settings will be written to your .env file, then the central database will be prepared and the super admin account created.</p>

        <form action="{{ route('lastStep') }}" method="post">
            @csrf

            <div class="summary-list" id="tochange">

                <div class="form-group">
                    <div class="row">
                        <div class="col-12 col-md-5">Licence</div>
                        <div class="col-12 col-md-7">
                            @if(session('setup.license'))
                                Activated on {{ session('setup.license.domain') }} · licence …{{ session('setup.license.hint') }}
                            @else
                                Not activated — you can activate later from Super Admin → Settings → Licence
                            @endif
                        </div>
                    </div>
                </div>

                @if($data['APP_NAME'] != 'old')
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-5">Application name</div>
                            <div class="col-12 col-md-7">{{ $data['APP_NAME'] }}</div>
                        </div>
                    </div>
                @endif

                @if(session('env.APP_URL'))
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-5">Application URL</div>
                            <div class="col-12 col-md-7">{{ session('env.APP_URL') }}</div>
                        </div>
                    </div>
                @endif

                @if($data['APP_ENV'] != 'old')
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-5">Environment</div>
                            <div class="col-12 col-md-7">{{ $data['APP_ENV'] }}</div>
                        </div>
                    </div>
                @endif

                @if($data['APP_KEY'] != 'old')
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-5">Application key</div>
                            <div class="col-12 col-md-7">{{ $data['APP_KEY'] }}</div>
                        </div>
                    </div>
                @endif

                @if($data['APP_DEBUG'] != 'old')
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-5">Debug mode</div>
                            <div class="col-12 col-md-7">{{ $data['APP_DEBUG'] }}</div>
                        </div>
                    </div>
                @endif

                @if($data['DB_HOST'] != 'old')
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-5">Database host</div>
                            <div class="col-12 col-md-7">{{ $data['DB_HOST'] }}</div>
                        </div>
                    </div>
                @endif

                @if($data['DB_PORT'] != 'old')
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-5">Database port</div>
                            <div class="col-12 col-md-7">{{ $data['DB_PORT'] }}</div>
                        </div>
                    </div>
                @endif

                @if($data['DB_DATABASE'] != 'old')
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-5">Central database</div>
                            <div class="col-12 col-md-7">{{ $data['DB_DATABASE'] }}</div>
                        </div>
                    </div>
                @endif

                @if($data['DB_USERNAME'] != 'old')
                    <div class="form-group">
                        <div class="row">
                            <div class="col-12 col-md-5">Database username</div>
                            <div class="col-12 col-md-7">{{ $data['DB_USERNAME'] }}</div>
                        </div>
                    </div>
                @endif

            </div>

            <h2 style="margin-top:28px;">Super admin account</h2>
            <p class="lead-text">This is the super admin login for the central panel. There is no default password — choose your own.</p>

            @if ($errors->any())
                <div class="alert-banner danger" style="text-align:left;">
                    <i class="fa fa-exclamation-triangle"></i>
                    <span>@foreach ($errors->all() as $error){{ $error }}@if (! $loop->last)<br>@endif @endforeach</span>
                </div>
            @endif

            <div class="form-grid">
                <div class="form-group full">
                    <label for="admin_email">Super admin email</label>
                    <input type="email" class="form-control" id="admin_email" name="admin_email" value="{{ old('admin_email') }}" required autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="admin_password">Password</label>
                    <span class="tip" title="At least 8 characters, with upper and lower case letters, a number and a symbol"><i class="fa fa-question-circle" aria-hidden="true"></i></span>
                    <input type="password" class="form-control" id="admin_password" name="admin_password" required minlength="8" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label for="admin_password_confirmation">Confirm password</label>
                    <input type="password" class="form-control" id="admin_password_confirmation" name="admin_password_confirmation" required minlength="8" autocomplete="new-password">
                </div>
            </div>

            <div class="alert-banner warning" style="margin-top:18px;">
                <i class="fa fa-exclamation-triangle"></i>
                <span><strong>Heads up:</strong> the selected database will be reset (all existing tables dropped) before the central schema is installed.</span>
            </div>

            <div class="pane-nav">
                <a href="/setup/step-2" class="btn btn-ghost"><i class="fa fa-angle-left"></i> Back</a>
                <button type="submit" class="btn btn-success" id="lastStep">Install Stocky SaaS <i class="fa fa-check"></i></button>
            </div>
        </form>
    </div>

@endsection
