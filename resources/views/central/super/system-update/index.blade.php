@extends('central.super.layout')

@section('title', __('super.system_update.title'))

@push('styles')
<link href="{{ \App\Support\SuperAsset::url('assets_super/css/system-update.css') }}" rel="stylesheet">
@endpush

@php
    $suData = [
        'endpoints' => [
            'status'        => route('super.system-update.status'),
            'upload'        => route('super.system-update.upload'),
            'validate'      => route('super.system-update.validate'),
            'start'         => route('super.system-update.start'),
            'step'          => route('super.system-update.step'),
            'resume'        => route('super.system-update.resume'),
            'rollback'      => route('super.system-update.rollback'),
            'discard'       => route('super.system-update.discard'),
            'deletePackage' => route('super.system-update.package.delete'),
            'restoreBackup' => route('super.system-update.restore-backup'),
            'deleteBackup'  => route('super.system-update.backups.delete', ['id' => '__ID__']),
            'retention'     => route('super.system-update.retention'),
        ],
        'trans'  => __('super.system_update.js'),
        'status' => $status,
    ];
@endphp

@section('content')
<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.system_update.title') }}</h1>
        <p class="page-subtitle">{{ __('super.system_update.subtitle') }}</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline btn-sm" href="{{ route('super.system-update.recovery') }}" target="_blank" rel="noopener">
            <i class="bi bi-life-preserver"></i> {{ __('super.system_update.recovery_console') }}
        </a>
    </div>
</div>

<div class="alert alert-warning">{{ __('super.system_update.code_overwritten_note') }}</div>

{{-- Interrupted / recovery banner --}}
<div class="su-attention" id="suAttention" hidden>
    <div class="su-attention-title"><i class="bi bi-exclamation-octagon"></i> {{ __('super.system_update.needs_attention') }}</div>
    <div id="suAttentionText"></div>
    <div class="su-attention-error" id="suAttentionError" hidden></div>
    <div class="d-flex flex-wrap gap-2 mt-3">
        <button type="button" class="btn btn-primary btn-sm" id="suBtnResume"><i class="bi bi-play-fill"></i> {{ __('super.system_update.resume') }}</button>
        <button type="button" class="btn btn-outline-danger btn-sm" id="suBtnRollback"><i class="bi bi-arrow-counterclockwise"></i> {{ __('super.system_update.rollback_now') }}</button>
        <a class="btn btn-outline btn-sm" href="{{ route('super.system-update.recovery') }}" target="_blank" rel="noopener"><i class="bi bi-life-preserver"></i> {{ __('super.system_update.recovery_console') }}</a>
    </div>
</div>

<div class="row g-3">
    {{-- Version + upload --}}
    <div class="col-lg-5">
        <div class="content-card h-100">
            <div class="card-header-custom"><h2><i class="bi bi-box-seam"></i> {{ __('super.system_update.package') }}</h2></div>
            <div class="card-body-custom">
                <div class="su-version">
                    <span class="su-version-label">{{ __('super.system_update.installed_version') }}</span>
                    <span class="su-version-value" id="suCurrentVersion">—</span>
                </div>

                <div id="suUploadArea">
                    <div class="su-dropzone" id="suDropzone" role="button" tabindex="0">
                        <i class="bi bi-cloud-arrow-up"></i>
                        <div class="su-dropzone-title">{{ __('super.system_update.upload_title') }}</div>
                        <div class="su-dropzone-hint">{{ __('super.system_update.upload_hint') }}</div>
                    </div>
                    <input type="file" id="suFile" accept=".zip" hidden>

                    <div id="suUploadProgress" hidden>
                        <div class="su-bar"><div class="su-bar-fill su-bar-active" id="suUploadBar" style="width:0%"></div></div>
                        <div class="su-muted">{{ __('super.system_update.uploading') }} <span id="suUploadPct">0%</span></div>
                    </div>

                    <div id="suPackage" hidden>
                        <dl class="su-dl">
                            <dt>{{ __('super.system_update.file') }}</dt><dd id="suPkgFile">—</dd>
                            <dt>{{ __('super.system_update.size') }}</dt><dd id="suPkgSize">—</dd>
                            <dt>{{ __('super.system_update.version') }}</dt><dd id="suPkgVersion">—</dd>
                        </dl>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-outline btn-sm" id="suBtnValidate"><i class="bi bi-shield-check"></i> <span class="su-btn-label">{{ __('super.system_update.js.validate') }}</span></button>
                            <button type="button" class="btn btn-danger btn-sm" id="suBtnStart" hidden><i class="bi bi-cloud-arrow-down"></i> {{ __('super.system_update.update_now') }}</button>
                            <button type="button" class="btn btn-outline btn-sm" id="suBtnRemove"><i class="bi bi-trash"></i> {{ __('super.system_update.remove') }}</button>
                        </div>
                    </div>
                </div>
                <div class="alert alert-info mb-0" id="suRunningNote" hidden>{{ __('super.system_update.in_progress_banner') }}</div>
            </div>
        </div>
    </div>

    {{-- Pre-update checks --}}
    <div class="col-lg-7">
        <div class="content-card h-100">
            <div class="card-header-custom"><h2><i class="bi bi-list-check"></i> {{ __('super.system_update.pre_checks') }}</h2></div>
            <div class="card-body-custom">
                <div class="su-checks" id="suChecks"></div>
                <div class="su-muted" id="suChecksEmpty">{{ __('super.system_update.checks_empty') }}</div>
                <div class="mt-3"><span class="su-badge" id="suChecksVerdict" hidden></span></div>
            </div>
        </div>
    </div>

    {{-- Live run --}}
    <div class="col-12" id="suRunCard" hidden>
        <div class="content-card">
            <div class="card-header-custom">
                <h2><span id="suRunTitle"></span> <span class="su-badge ms-2" id="suRunBadge"></span></h2>
                <span class="su-muted" id="suRunVersions"></span>
            </div>
            <div class="card-body-custom">
                <div class="su-run-head">
                    <div class="su-bar su-bar-lg"><div class="su-bar-fill" id="suRunBar" style="width:0%"></div></div>
                    <span class="su-run-pct" id="suRunPct">0%</span>
                </div>
                <ol class="su-steps" id="suSteps"></ol>
                <div class="su-phase-line" id="suPhaseLine" hidden>
                    <strong>{{ __('super.system_update.current_step') }}:</strong> <span id="suPhaseText"></span>
                </div>
                <div class="su-db-line">
                    <span id="suRunDatabases"></span>
                    <span class="su-db-progress" id="suDbProgress" hidden></span>
                </div>

                <div class="alert alert-warning mt-3" id="suBypass" hidden>
                    <div>
                        {{ __('super.system_update.bypass_hint') }}<br>
                        <a href="#" id="suBypassLink" target="_blank" rel="noopener"></a>
                    </div>
                </div>

                <div class="alert alert-success mt-3" id="suDone" hidden>
                    <div><strong id="suDoneTitle"></strong><br>{{ __('super.system_update.reload_hint') }}</div>
                </div>
                <div class="alert alert-warning mt-3" id="suRolledBack" hidden>
                    <div><strong>{{ __('super.system_update.rolled_back_title') }}</strong><br><span id="suRolledBackDetail"></span></div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button type="button" class="btn btn-primary btn-sm" id="suBtnReload" hidden><i class="bi bi-arrow-clockwise"></i> {{ __('super.system_update.reload') }}</button>
                    <button type="button" class="btn btn-outline btn-sm" id="suBtnDismiss" hidden>{{ __('super.system_update.dismiss') }}</button>
                </div>

                <details class="su-log-wrap" id="suLogWrap" hidden>
                    <summary>{{ __('super.system_update.technical_log') }}</summary>
                    <pre class="su-log" id="suLog"></pre>
                </details>
            </div>
        </div>
    </div>

    {{-- History --}}
    <div class="col-lg-7">
        <div class="content-card h-100">
            <div class="card-header-custom"><h2><i class="bi bi-clock-history"></i> {{ __('super.system_update.history') }}</h2></div>
            <div class="card-body-custom">
                <div class="table-responsive">
                    <table class="table su-table mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('super.system_update.col_date') }}</th>
                                <th>{{ __('super.system_update.col_version') }}</th>
                                <th>{{ __('super.system_update.col_databases') }}</th>
                                <th>{{ __('super.system_update.col_by') }}</th>
                                <th>{{ __('super.system_update.col_duration') }}</th>
                                <th>{{ __('super.system_update.col_status') }}</th>
                            </tr>
                        </thead>
                        <tbody id="suHistoryBody"></tbody>
                    </table>
                </div>
                <div class="su-muted" id="suHistoryEmpty" hidden>{{ __('super.system_update.history_empty') }}</div>
            </div>
        </div>
    </div>

    {{-- Backups --}}
    <div class="col-lg-5">
        <div class="content-card h-100">
            <div class="card-header-custom su-backups-head">
                <h2><i class="bi bi-archive"></i> {{ __('super.system_update.backups') }}</h2>
                <div class="d-flex align-items-center gap-2 su-retention-wrap">
                    <label class="su-muted mb-0" for="suRetention">{{ __('super.system_update.keep_last') }}</label>
                    <input type="number" class="form-control form-control-sm su-retention" id="suRetention" min="1" max="20" value="3">
                    <button type="button" class="btn btn-outline btn-sm" id="suBtnRetention">{{ __('super.system_update.save') }}</button>
                </div>
            </div>
            <div class="card-body-custom">
                <div id="suBackups"></div>
                <div class="su-muted" id="suBackupsEmpty" hidden>{{ __('super.system_update.backups_empty') }}</div>
                <div class="su-muted mt-2">{{ __('super.system_update.backups_hint') }}</div>
            </div>
        </div>
    </div>

    {{-- Guide --}}
    <div class="col-12">
        <div class="content-card">
            <div class="card-header-custom"><h2><i class="bi bi-book"></i> {{ __('super.system_update.guide_title') }}</h2></div>
            <div class="card-body-custom su-guide">
                <h3>{{ __('super.system_update.guide_before_title') }}</h3>
                <ol>
                    <li>{{ __('super.system_update.guide_before_1') }}</li>
                    <li>{{ __('super.system_update.guide_before_2') }}</li>
                    <li>{{ __('super.system_update.guide_before_3') }}</li>
                </ol>
                <h3>{{ __('super.system_update.guide_run_title') }}</h3>
                <ol>
                    <li>{{ __('super.system_update.guide_run_1') }}</li>
                    <li>{{ __('super.system_update.guide_run_2') }}</li>
                    <li>{{ __('super.system_update.guide_run_3') }}</li>
                    <li>{{ __('super.system_update.guide_run_4') }}</li>
                </ol>
                <h3>{{ __('super.system_update.guide_wrong_title') }}</h3>
                <ol>
                    <li>{{ __('super.system_update.guide_wrong_1') }}</li>
                    <li>{{ __('super.system_update.guide_wrong_2') }}</li>
                    <li>{!! str_replace(':path', '<code>/super/system-update/recovery</code>', e(__('super.system_update.guide_wrong_3'))) !!}</li>
                    <li>{{ __('super.system_update.guide_wrong_4') }}</li>
                </ol>
                <h3>{{ __('super.system_update.guide_manual_title') }}</h3>
                <p class="mb-0">{!! str_replace(':link', '<a href="'.e(route('platform.update.index')).'">/update</a>', e(__('super.system_update.guide_manual_text'))) !!}</p>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>window.SystemUpdateData = {!! json_encode($suData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!};</script>
<script src="{{ \App\Support\SuperAsset::url('assets_super/js/system-update.js') }}"></script>
@endpush
