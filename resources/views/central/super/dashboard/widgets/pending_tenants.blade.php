@php
    $pendingTenants = $d['tenants'];
    $envDbHost = config('database.connections.central.host', '127.0.0.1');
    $envDbPort = config('database.connections.central.port', '3306');
    $envDbUser = config('database.connections.central.username', '');
    $envDbPass = config('database.connections.central.password', '');
@endphp
<div class="content-card">
    <div class="card-header-custom d-flex align-items-center justify-content-between">
        <h2><i class="bi bi-hourglass-split me-2 text-warning"></i>{{ __('central.PendingTenants') }} <span class="badge bg-warning text-dark ms-2 fs-xs2">{{ $pendingTenants->count() }}</span></h2>
    </div>
    <div class="card-body-custom p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 table-pending">
                <thead>
                    <tr>
                        <th>{{ __('central.TenantName') }}</th>
                        <th>{{ __('central.Subdomain') }}</th>
                        <th>{{ __('central.Email') }}</th>
                        <th>{{ __('central.RegistrationDate') }}</th>
                        <th class="text-end">{{ __('central.Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pendingTenants as $pt)
                    <tr>
                        <td>
                            <span class="fw-600">{{ $pt->company_name ?? $pt->id }}</span>
                        </td>
                        <td>
                            <code class="code-badge">{{ $pt->domains->first()->domain ?? '—' }}</code>
                        </td>
                        <td>
                            {{ $pt->admin_email ?? '—' }}
                        </td>
                        <td>
                            {{ $pt->created_at->format('M d, Y H:i') }}
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-approve-trigger btn-approve"
                                data-toggle-db="{{ $pt->id }}">
                                <i class="bi bi-database-gear me-1"></i>{{ __('central.ConfigureAndApprove') }}
                            </button>
                            <button type="button" class="btn btn-sm btn-reject-trigger btn-reject ms-4px"
                                data-tenant-id="{{ $pt->id }}" data-tenant-name="{{ $pt->company_name ?? $pt->id }}">
                                <i class="bi bi-x-lg me-1"></i>{{ __('central.Reject') }}
                            </button>
                        </td>
                    </tr>
                    {{-- Database configuration row (hidden by default) --}}
                    <tr id="db-form-{{ $pt->id }}" class="db-form-row">
                        <td colspan="5" class="db-config-td">
                            <div class="db-config-panel">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <i class="bi bi-database-fill-gear text-primary-mid fs-lg2"></i>
                                    <h6 class="mb-0 fw-700 db-config-title">{{ __('central.DatabaseConfigFor', ['name' => $pt->company_name ?? $pt->id]) }}</h6>
                                </div>
                                <form method="POST" action="{{ route('super.tenants.approve', $pt) }}" id="approve-form-{{ $pt->id }}">
                                    @csrf
                                    {{-- Subdomain reminder (shared hosting only) --}}
                                    @if(\App\Models\Central\GeneralSetting::instance()->isSharedHosting())
                                    @php $subdomainName = $pt->domains->first()->domain ?? null; @endphp
                                    @if($subdomainName)
                                    <div class="mb-3 p-3 rounded-3 notice-warning">
                                        <div class="d-flex align-items-start gap-2">
                                            <i class="bi bi-exclamation-triangle-fill notice-warning-icon"></i>
                                            <div>
                                                <p class="mb-1 notice-title">{{ __('central.SubdomainSetupRequired') }}</p>
                                                <p class="mb-2 notice-text">
                                                    {{ __('central.SubdomainSetupDesc') }}
                                                </p>
                                                <code class="code-badge-warning">
                                                    {{ $subdomainName }}.{{ preg_replace('/^www\./i', '', parse_url(config('app.url', 'http://localhost'), PHP_URL_HOST) ?? 'localhost') }}
                                                </code>
                                                <div class="mt-2">
                                                    <label class="d-flex align-items-center gap-2 notice-label">
                                                        <input type="checkbox" name="subdomain_confirmed" value="1" class="accent-warning">
                                                        {{ __('central.SubdomainCreatedConfirm') }}
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    @endif
                                    @endif
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label form-label-sm">{{ __('central.DbHost') }} <span class="text-danger">*</span></label>
                                            <input type="text" name="db_host" class="form-control form-control-sm form-control-sm-custom" value="{{ old('db_host', $envDbHost) }}" required placeholder="{{ $envDbHost }}">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label form-label-sm">{{ __('central.DbName') }} <span class="text-danger">*</span></label>
                                            <input type="text" name="db_name" class="form-control form-control-sm form-control-sm-custom" value="{{ old('db_name') }}" required placeholder="{{ __('central.DbNamePlaceholder') }}">
                                            <small class="form-text text-muted db-form-hint">{{ __('central.DbNameHint', ['suggestion' => 'tenant_' . ($pt->domains->first()->domain ?? 'name')]) }}</small>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label form-label-sm">{{ __('central.DbPort') }} <span class="text-danger">*</span></label>
                                            <input type="number" name="db_port" class="form-control form-control-sm form-control-sm-custom" value="{{ old('db_port', $envDbPort) }}" required min="1" max="65535">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label form-label-sm">{{ __('central.DbUsername') }} <span class="text-danger">*</span></label>
                                            <input type="text" name="db_username" class="form-control form-control-sm form-control-sm-custom" value="{{ old('db_username', $envDbUser) }}" required placeholder="{{ $envDbUser ?: 'db_user' }}">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label form-label-sm">{{ __('central.DbPassword') }}</label>
                                            <input type="password" name="db_password" class="form-control form-control-sm form-control-sm-custom" value="{{ old('db_password', $envDbPass) }}" placeholder="••••••••">
                                        </div>
                                        <div class="col-md-4 d-flex align-items-end gap-2">
                                            <button type="button" class="btn btn-sm btn-test-connection" data-test-db="{{ $pt->id }}">
                                                <i class="bi bi-plug me-1"></i>{{ __('central.TestConnection') }}
                                            </button>
                                        </div>
                                    </div>
                                    <div id="db-test-result-{{ $pt->id }}" class="mt-2 db-test-result"></div>
                                    <div class="d-flex justify-content-end gap-2 mt-3 pt-3 border-top-border-light">
                                        <button type="button" class="btn btn-sm btn-outline-secondary btn-cancel-sm" data-toggle-db="{{ $pt->id }}">{{ __('central.Cancel') }}</button>
                                        <button type="button" class="btn btn-sm btn-approve approve-locked" data-confirm-approve="{{ $pt->id }}">
                                            <i class="bi bi-check-lg me-1"></i>{{ __('central.ApproveAndProvision') }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Reject Modal --}}
<div id="rejectModal" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-icon-danger">
            <i class="bi bi-x-circle"></i>
        </div>
        <h4 class="modal-title">{{ __('central.RejectTenant') }}</h4>
        <p class="text-muted fs-sm4" id="rejectModalTenantName"></p>
        <form method="POST" id="rejectForm">
            @csrf
            <div class="mb-3 text-start">
                <label class="form-label form-control-sm-custom fw-600">{{ __('central.RejectionReason') }}</label>
                <textarea name="reason" class="form-control fs-sm4" rows="3" required placeholder="{{ __('central.RejectionReasonPlaceholder') }}"></textarea>
            </div>
            <div class="d-flex gap-2 justify-content-center">
                <button type="button" class="btn btn-outline-secondary" id="closeRejectModalBtn">{{ __('central.Cancel') }}</button>
                <button type="submit" class="btn btn-danger">{{ __('central.RejectTenant') }}</button>
            </div>
        </form>
    </div>
</div>
