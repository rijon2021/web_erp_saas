@extends('central.super.layout')

@section('title', __('super.languages.title'))

@push('styles')
    <link href="{{ \App\Support\SuperAsset::url('assets_super/css/languages.css') }}" rel="stylesheet">
@endpush

@section('content')
<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <h1>{{ __('super.languages.title') }}</h1>
        <p class="page-subtitle">{{ __('super.languages.subtitle') }}</p>
    </div>
</div>

@if(! $ready)
    <div class="alert alert-error">{{ __('super.languages.migrate_first') }}</div>
@endif

<div class="lp-tabs" role="tablist">
    <button type="button" class="lp-tab active" data-pane="installed" role="tab">
        <i class="bi bi-check2-circle me-1"></i>{{ __('super.languages.tab_installed') }}<span class="count">{{ $installed->count() }}</span>
    </button>
    <button type="button" class="lp-tab" data-pane="available" role="tab">
        <i class="bi bi-cloud-download me-1"></i>{{ __('super.languages.tab_available') }}<span class="count">{{ $available->count() }}</span>
    </button>
</div>

{{-- Installed --}}
<div class="lang-pane active" id="pane-installed">
    <div class="row g-3">
        @foreach($installed as $lang)
            @php
                $stats = $progress[$lang->locale] ?? ['translated' => 0, 'total' => $totalKeys, 'percent' => 0, 'customized' => 0];
                $canRemove = $lang->locale !== 'en' && ! $lang->is_default;
            @endphp
            <div class="col-md-6 col-xl-4">
                <div class="lang-card {{ $lang->is_active ? '' : 'is-inactive' }}">
                    <div class="lang-card-head">
                        <div class="lang-card-flag">
                            @if($lang->flag)<img src="{{ asset('flags/' . $lang->flag) }}" alt="">@endif
                        </div>
                        <div class="lang-card-title">
                            <div class="native" @if($lang->is_rtl) dir="rtl" @endif>{{ $lang->display_name }}</div>
                            <div class="english">{{ $lang->name }}</div>
                        </div>
                        <span class="lang-code-chip">{{ $lang->locale }}</span>
                    </div>

                    <div class="lang-badges">
                        @if($lang->is_default)<span class="lang-badge default"><i class="bi bi-star-fill me-1"></i>{{ __('super.languages.default') }}</span>@endif
                        @if($lang->is_rtl)<span class="lang-badge rtl">RTL</span>@endif
                        @if(! $lang->is_active)<span class="lang-badge off">{{ __('super.common.inactive') }}</span>@endif
                        @if($stats['customized'])<span class="lang-badge">{{ __('super.languages.customized_count', ['count' => $stats['customized']]) }}</span>@endif
                    </div>

                    @if($ready)
                    <div>
                        <div class="lang-progress-label">
                            <span>{{ __('super.languages.translated') }}</span>
                            <span><strong>{{ $stats['percent'] }}%</strong> · {{ number_format($stats['translated']) }} / {{ number_format($stats['total']) }}</span>
                        </div>
                        <div class="lang-progress {{ $stats['percent'] < 60 ? 'low' : '' }}"><span style="width: {{ $stats['percent'] }}%"></span></div>
                    </div>
                    @endif

                    <div class="lang-card-actions">
                        <a href="{{ route('super.languages.translations', $lang) }}" class="btn btn-primary btn-sm btn-edit {{ $ready ? '' : 'disabled' }}">
                            <i class="bi bi-pencil-square"></i> {{ __('super.languages.edit_translations') }}
                        </a>
                        <button type="button" class="btn btn-outline btn-sm js-toggle-settings" title="{{ __('super.languages.settings') }}" aria-label="{{ __('super.languages.settings') }}">
                            <i class="bi bi-sliders"></i>
                        </button>
                        <div class="dropdown">
                            <button type="button" class="btn btn-outline btn-sm" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('super.common.actions') }}">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                @unless($lang->is_default)
                                <li>
                                    <form method="POST" action="{{ route('super.languages.default', $lang) }}">
                                        @csrf
                                        <button type="submit" class="dropdown-item"><i class="bi bi-star me-2"></i>{{ __('super.languages.make_default') }}</button>
                                    </form>
                                </li>
                                @endunless
                                @if($ready)
                                <li>
                                    <form method="POST" action="{{ route('super.languages.sync', $lang) }}">
                                        @csrf
                                        <button type="submit" class="dropdown-item"><i class="bi bi-arrow-repeat me-2"></i>{{ __('super.languages.sync') }}</button>
                                    </form>
                                </li>
                                <li><a class="dropdown-item" href="{{ route('super.languages.export', $lang) }}"><i class="bi bi-download me-2"></i>{{ __('super.languages.export') }}</a></li>
                                <li>
                                    <form method="POST" action="{{ route('super.languages.import', $lang) }}" enctype="multipart/form-data" class="js-import-form">
                                        @csrf
                                        <input type="file" name="file" accept=".json,application/json" class="d-none js-import-file">
                                        <button type="button" class="dropdown-item js-import-trigger"><i class="bi bi-upload me-2"></i>{{ __('super.languages.import') }}</button>
                                    </form>
                                </li>
                                @endif
                                @if($canRemove)
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="POST" action="{{ route('super.languages.uninstall', $lang) }}" class="js-uninstall-form" data-name="{{ $lang->name }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash3 me-2"></i>{{ __('super.languages.uninstall') }}</button>
                                    </form>
                                </li>
                                @endif
                            </ul>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('super.languages.update', $lang) }}" class="lang-settings">
                        @csrf
                        @method('PUT')
                        <div class="form-group mb-3">
                            <label class="form-label">{{ __('super.languages.native_name') }}</label>
                            <input type="text" name="native_name" class="form-control form-control-sm" value="{{ $lang->display_name }}" maxlength="100">
                        </div>
                        <label class="form-check d-flex align-items-center gap-2">
                            <input type="checkbox" name="is_active" value="1" class="form-check-input" {{ $lang->is_active ? 'checked' : '' }} {{ $lang->is_default ? 'disabled' : '' }}>
                            <span>{{ __('super.languages.show_in_switcher') }}</span>
                        </label>
                        @if($lang->is_default)<input type="hidden" name="is_active" value="1">@endif
                        <label class="form-check d-flex align-items-center gap-2">
                            <input type="checkbox" name="is_rtl" value="1" class="form-check-input" {{ $lang->is_rtl ? 'checked' : '' }}>
                            <span>{{ __('super.languages.rtl') }}</span>
                        </label>
                        <button type="submit" class="btn btn-primary btn-sm mt-2">{{ __('super.common.save') }}</button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
</div>

{{-- Available --}}
<div class="lang-pane" id="pane-available">
    <div class="lang-available-toolbar">
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" id="availableSearch" placeholder="{{ __('super.languages.search_languages') }}" autocomplete="off">
        </div>
        <span class="text-muted small">{{ __('super.languages.available_hint') }}</span>
    </div>

    @if($available->isEmpty())
        <div class="content-card">
            <div class="empty-state">
                <div class="empty-icon"><i class="bi bi-check2-all"></i></div>
                <h3>{{ __('super.languages.all_installed') }}</h3>
            </div>
        </div>
    @else
    <div class="row g-3" id="availableGrid">
        @foreach($available as $entry)
            <div class="col-sm-6 col-lg-4 col-xxl-3 js-available-item" data-search="{{ mb_strtolower($entry['name'] . ' ' . $entry['native'] . ' ' . $entry['locale']) }}">
                <div class="lang-card lang-available-card">
                    <div class="lang-card-head">
                        <div class="lang-card-flag"><img src="{{ asset('flags/' . $entry['flag']) }}" alt=""></div>
                        <div class="lang-card-title">
                            <div class="native" @if($entry['rtl']) dir="rtl" @endif>{{ $entry['native'] }}</div>
                            <div class="english">{{ $entry['name'] }}</div>
                        </div>
                        <span class="lang-code-chip">{{ $entry['locale'] }}</span>
                    </div>
                    <div class="lang-badges">
                        @if($entry['has_pack'])
                            <span class="lang-badge pack"><i class="bi bi-check2 me-1"></i>{{ __('super.languages.pack_included') }}</span>
                        @else
                            <span class="lang-badge">{{ __('super.languages.pack_missing') }}</span>
                        @endif
                        @if($entry['rtl'])<span class="lang-badge rtl">RTL</span>@endif
                    </div>
                    <form method="POST" action="{{ route('super.languages.install', $entry['locale']) }}" class="mt-auto js-install-form">
                        @csrf
                        <button type="submit" class="btn btn-outline btn-sm w-100" {{ $ready ? '' : 'disabled' }}>
                            <i class="bi bi-download"></i> {{ __('super.languages.install') }}
                        </button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
(function () {
    var T = @json(__('super.languages.js'));

    // Tabs (remember the last one across install / uninstall redirects)
    function showPane(name) {
        document.querySelectorAll('.lp-tab').forEach(function (t) { t.classList.toggle('active', t.dataset.pane === name); });
        document.querySelectorAll('.lang-pane').forEach(function (p) { p.classList.toggle('active', p.id === 'pane-' + name); });
        try { sessionStorage.setItem('super_lang_tab', name); } catch (e) {}
    }
    document.querySelectorAll('.lp-tab').forEach(function (t) {
        t.addEventListener('click', function () { showPane(t.dataset.pane); });
    });
    // ?tab=available (the navbar switcher links here) wins over the remembered tab
    var requested = new URLSearchParams(window.location.search).get('tab');
    if (requested && document.getElementById('pane-' + requested)) {
        showPane(requested);
    } else {
        try {
            var saved = sessionStorage.getItem('super_lang_tab');
            if (saved && document.getElementById('pane-' + saved)) showPane(saved);
        } catch (e) {}
    }

    // Per-card settings panel
    document.querySelectorAll('.js-toggle-settings').forEach(function (btn) {
        btn.addEventListener('click', function () {
            btn.closest('.lang-card').querySelector('.lang-settings').classList.toggle('open');
        });
    });

    // Import: pick a file, then submit
    document.querySelectorAll('.js-import-form').forEach(function (form) {
        var input = form.querySelector('.js-import-file');
        form.querySelector('.js-import-trigger').addEventListener('click', function () { input.click(); });
        input.addEventListener('change', function () { if (input.files.length) form.submit(); });
    });

    // Uninstall confirmation
    document.querySelectorAll('.js-uninstall-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var text = T.uninstall_text.split(':name').join(form.dataset.name);
            var go = function () { form.submit(); };
            if (window.Swal) {
                Swal.fire({ icon: 'warning', title: T.uninstall_title, text: text, showCancelButton: true,
                    confirmButtonText: T.uninstall_confirm, cancelButtonText: T.cancel, confirmButtonColor: '#dc2626' })
                    .then(function (r) { if (r.isConfirmed) go(); });
            } else if (confirm(text)) { go(); }
        });
    });

    // Installing copies a few thousand strings: show progress on the button
    document.querySelectorAll('.js-install-form').forEach(function (form) {
        form.addEventListener('submit', function () {
            var b = form.querySelector('button');
            b.disabled = true;
            b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + T.installing;
        });
    });

    // Available search
    var search = document.getElementById('availableSearch');
    if (search) {
        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            document.querySelectorAll('.js-available-item').forEach(function (el) {
                el.style.display = !q || el.dataset.search.indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }
})();
</script>
@endpush
