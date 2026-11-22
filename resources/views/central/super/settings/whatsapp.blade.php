@extends('central.super.layout')

@section('title', __('super.whatsapp_settings.title'))

@section('content')
<div class="page-header">
    <h1>{{ __('super.whatsapp_settings.title') }}</h1>
    <p class="page-subtitle">{{ __('super.whatsapp_settings.subtitle') }}</p>
</div>

<form method="POST" action="{{ route('super.settings.whatsapp.update') }}">
    @csrf
    @method('PUT')

    <div class="row g-4">
        <div class="col-lg-8">
            {{-- General --}}
            <div class="content-card mb-4">
                <div class="card-header-custom">
                    <h2><i class="bi bi-whatsapp me-2 text-muted"></i>{{ __('super.whatsapp_settings.integration') }}</h2>
                </div>
                <div class="card-body-custom">
                    <div class="form-group mb-4">
                        <label class="form-check d-flex align-items-center gap-2">
                            <input type="checkbox" name="whatsapp_enabled" value="1" class="form-check-input"
                                   {{ old('whatsapp_enabled', $setting->whatsapp_enabled) ? 'checked' : '' }}>
                            <span class="fw-600">{{ __('super.whatsapp_settings.enable_globally') }}</span>
                        </label>
                        <p class="form-hint">{{ __('super.whatsapp_settings.enable_hint') }}</p>
                    </div>

                    <div class="form-group mb-4">
                        <label class="form-label">{{ __('super.whatsapp_settings.provider') }}</label>
                        <select name="whatsapp_provider" class="form-control">
                            @foreach($providers as $provider)
                                <option value="{{ $provider }}" {{ old('whatsapp_provider', $setting->whatsapp_provider) === $provider ? 'selected' : '' }}>
                                    {{ $provider === 'meta_cloud' ? 'Meta Cloud API' : ucfirst(str_replace('_', ' ', $provider)) }}
                                </option>
                            @endforeach
                        </select>
                        <p class="form-hint">{{ __('super.whatsapp_settings.provider_hint') }}</p>
                    </div>

                    <div class="form-group">
                        <label class="form-label">{{ __('super.whatsapp_settings.webhook_url') }}</label>
                        <input type="text" class="form-control" readonly value="{{ $webhookBase }}/{tenant_id}">
                        <p class="form-hint">{{ __('super.whatsapp_settings.webhook_hint') }}</p>
                    </div>
                </div>
            </div>

            {{-- Default Templates --}}
            <div class="content-card mb-4">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <h2><i class="bi bi-chat-square-text me-2 text-muted"></i>{{ __('super.whatsapp_settings.default_templates') }}</h2>
                    <button type="button" class="btn btn-outline btn-sm" id="addTemplateBtn"><i class="bi bi-plus-lg"></i> {{ __('super.whatsapp_settings.add') }}</button>
                </div>
                <div class="card-body-custom">
                    <p class="text-muted fs-sm2 mb-3">{{ __('super.whatsapp_settings.templates_hint') }} <code>@{{customer_name}}</code>, <code>@{{invoice_number}}</code>, <code>@{{total_amount}}</code>, <code>@{{company_name}}</code>, <code>@{{payment_status}}</code>, <code>@{{order_status}}</code>.</p>

                    <div id="templatesWrap" class="d-flex flex-column gap-3">
                        @foreach($defaultTemplates as $i => $tpl)
                        <div class="template-row border rounded p-3">
                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label fs-sm2">{{ __('super.whatsapp_settings.key') }}</label>
                                    <input type="text" name="templates[{{ $i }}][key]" class="form-control" value="{{ $tpl['key'] }}" placeholder="invoice_created">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fs-sm2">{{ __('super.whatsapp_settings.name') }}</label>
                                    <input type="text" name="templates[{{ $i }}][name]" class="form-control" value="{{ $tpl['name'] }}" placeholder="{{ __('super.whatsapp_settings.ph_template_name') }}">
                                </div>
                            </div>
                            <label class="form-label fs-sm2">{{ __('super.whatsapp_settings.message') }}</label>
                            <textarea name="templates[{{ $i }}][body]" class="form-control" rows="2">{{ $tpl['body'] }}</textarea>
                            <button type="button" class="btn btn-link text-danger p-0 mt-2 removeTemplateBtn"><i class="bi bi-trash"></i> {{ __('super.whatsapp_settings.remove') }}</button>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="content-card mb-4">
                <div class="card-body-custom">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> {{ __('super.whatsapp_settings.save') }}</button>
                </div>
            </div>
        </div>

        {{-- Usage sidebar --}}
        <div class="col-lg-4">
            <div class="content-card">
                <div class="card-header-custom">
                    <h2><i class="bi bi-bar-chart me-2 text-muted"></i>{{ __('super.whatsapp_settings.usage_month') }}</h2>
                </div>
                <div class="card-body-custom">
                    @if(empty($usage))
                        <p class="text-muted fs-sm2 mb-0">{{ __('super.whatsapp_settings.no_usage') }}</p>
                    @else
                        <table class="table table-sm mb-0">
                            <thead><tr><th>{{ __('super.whatsapp_settings.tenant') }}</th><th class="text-end">{{ __('super.whatsapp_settings.sent') }}</th></tr></thead>
                            <tbody>
                                @foreach($usage as $row)
                                <tr>
                                    <td>{{ $row['tenant'] }}</td>
                                    <td class="text-end fw-600">{{ number_format($row['sent']) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
    </div>
</form>

@push('scripts')
@php
    // Labels for the client-side "add template" row, mirroring the
    // server-rendered one above.
    $templateRowStrings = json_encode([
        'key'     => __('super.whatsapp_settings.key'),
        'name'    => __('super.whatsapp_settings.name'),
        'message' => __('super.whatsapp_settings.message'),
        'remove'  => __('super.whatsapp_settings.remove'),
        'phName'  => __('super.whatsapp_settings.ph_template_name'),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
@endphp
<script>
(function () {
    var wrap = document.getElementById('templatesWrap');
    var addBtn = document.getElementById('addTemplateBtn');

    var T = {!! $templateRowStrings !!};

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function rowTemplate(i) {
        return '<div class="template-row border rounded p-3">' +
            '<div class="row g-2 mb-2">' +
            '<div class="col-md-6"><label class="form-label fs-sm2">' + esc(T.key) + '</label>' +
            '<input type="text" name="templates[' + i + '][key]" class="form-control" placeholder="invoice_created"></div>' +
            '<div class="col-md-6"><label class="form-label fs-sm2">' + esc(T.name) + '</label>' +
            '<input type="text" name="templates[' + i + '][name]" class="form-control" placeholder="' + esc(T.phName) + '"></div>' +
            '</div>' +
            '<label class="form-label fs-sm2">' + esc(T.message) + '</label>' +
            '<textarea name="templates[' + i + '][body]" class="form-control" rows="2"></textarea>' +
            '<button type="button" class="btn btn-link text-danger p-0 mt-2 removeTemplateBtn"><i class="bi bi-trash"></i> ' + esc(T.remove) + '</button>' +
            '</div>';
    }

    if (addBtn) {
        addBtn.addEventListener('click', function () {
            var i = wrap.querySelectorAll('.template-row').length;
            wrap.insertAdjacentHTML('beforeend', rowTemplate(i));
        });
    }

    if (wrap) {
        wrap.addEventListener('click', function (e) {
            var btn = e.target.closest('.removeTemplateBtn');
            if (btn) { btn.closest('.template-row').remove(); }
        });
    }
})();
</script>
@endpush

@endsection
