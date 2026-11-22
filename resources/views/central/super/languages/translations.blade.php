@extends('central.super.layout')

@section('title', __('super.languages.editor_title', ['name' => $language->name]))

@push('styles')
    <link href="{{ \App\Support\SuperAsset::url('assets_super/css/languages.css') }}" rel="stylesheet">
@endpush

@php
    $query = fn (array $over) => route('super.languages.translations', array_merge(
        ['language' => $language->locale],
        array_filter(['group' => $group, 'filter' => $filter !== 'all' ? $filter : null, 'q' => $search !== '' ? $search : null]),
        $over
    ));
@endphp

@section('content')
<div class="breadcrumb-custom">
    <a href="{{ route('super.languages.index') }}">{{ __('super.languages.title') }}</a>
    <span class="separator"><i class="bi bi-chevron-right"></i></span>
    <span class="current">{{ $language->name }}</span>
</div>

<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div class="d-flex align-items-center gap-3">
        @if($language->flag)
            <div class="lang-card-flag"><img src="{{ asset('flags/' . $language->flag) }}" alt=""></div>
        @endif
        <div>
            <h1 class="mb-1">{{ __('super.languages.editor_title', ['name' => $language->display_name]) }}</h1>
            <p class="page-subtitle mb-0">
                {{ __('super.languages.editor_subtitle', ['missing' => number_format($untranslatedTotal), 'total' => number_format($totalKeys)]) }}
            </p>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('super.languages.export', $language) }}" class="btn btn-outline btn-sm"><i class="bi bi-download"></i> {{ __('super.languages.export') }}</a>
    </div>
</div>

<div class="content-card">
    <div class="filter-bar">
        <form method="GET" action="{{ route('super.languages.translations', $language) }}" class="tr-toolbar w-100" id="trFilterForm">
            @if($group)<input type="hidden" name="group" value="{{ $group }}">@endif
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="text" name="q" value="{{ $search }}" placeholder="{{ __('super.languages.search_strings') }}" autocomplete="off">
            </div>
            <select name="filter" class="form-control filter-select w-auto" onchange="this.form.submit()">
                <option value="all" {{ $filter === 'all' ? 'selected' : '' }}>{{ __('super.languages.filter_all') }}</option>
                <option value="untranslated" {{ $filter === 'untranslated' ? 'selected' : '' }}>{{ __('super.languages.filter_untranslated') }}</option>
                <option value="customized" {{ $filter === 'customized' ? 'selected' : '' }}>{{ __('super.languages.filter_customized') }}</option>
            </select>
            <span class="text-muted small ms-auto">{{ __('super.languages.results', ['count' => number_format($rows->total())]) }}</span>
        </form>
    </div>

    <div class="px-3 pt-3">
        <div class="tr-group-pills">
            <a href="{{ $query(['group' => null, 'page' => null]) }}" class="{{ $group ? '' : 'active' }}">{{ __('super.languages.group_all') }}<span class="n">{{ number_format($totalKeys) }}</span></a>
            @foreach($groups as $g)
                <a href="{{ $query(['group' => $g, 'page' => null]) }}" class="{{ $group === $g ? 'active' : '' }}">{{ __('super.languages.groups.' . $g) }}<span class="n">{{ number_format($groupCounts[$g] ?? 0) }}</span></a>
            @endforeach
        </div>
    </div>

    <div class="table-responsive">
        <table class="tr-table">
            <thead>
                <tr>
                    <th>{{ __('super.languages.col_key') }}</th>
                    <th>{{ __('super.languages.col_english') }}</th>
                    <th>{{ $language->display_name }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    <tr data-group="{{ $row['group'] }}" data-key="{{ $row['key'] }}">
                        <td class="tr-key"><span class="grp">{{ $row['group'] }}</span><br>{{ $row['key'] }}</td>
                        <td class="tr-source">{{ $row['english'] }}</td>
                        <td>
                            <textarea class="tr-input {{ $row['value'] === '' ? 'empty' : '' }}" rows="{{ max(1, min(6, (int) ceil(mb_strlen($row['english']) / 60))) }}"
                                      @if($language->is_rtl) dir="rtl" @endif
                                      data-original="{{ $row['value'] }}"
                                      placeholder="{{ __('super.languages.falls_back') }}">{{ $row['value'] }}</textarea>
                            <div class="tr-meta">
                                @if($row['value'] === '')
                                    <span class="missing">{{ __('super.languages.untranslated') }}</span>
                                @elseif($row['customized'])
                                    <span class="custom">{{ __('super.languages.customized') }}</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            <button type="button" class="tr-reset js-reset" title="{{ __('super.languages.reset') }}" aria-label="{{ __('super.languages.reset') }}">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            <div class="empty-state">
                                <div class="empty-icon"><i class="bi bi-translate"></i></div>
                                <h3>{{ __('super.languages.no_results') }}</h3>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($rows->hasPages())
        <div class="card-body-custom pt-2">{{ $rows->links() }}</div>
    @endif

    <div class="tr-savebar">
        <span class="dirty-count" id="dirtyCount">{{ __('super.languages.no_changes') }}</span>
        <button type="button" class="btn btn-primary btn-sm" id="btnSaveAll" disabled>
            <i class="bi bi-check2"></i> {{ __('super.common.save_changes') }}
        </button>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var T = @json(__('super.languages.js'));
    var saveUrl = @json(route('super.languages.translations.save', $language));
    var resetUrl = @json(route('super.languages.translations.reset', $language));
    var csrf = document.querySelector('meta[name="csrf-token"]').content;
    var saveBtn = document.getElementById('btnSaveAll');
    var counter = document.getElementById('dirtyCount');

    function inputs() { return Array.prototype.slice.call(document.querySelectorAll('.tr-input')); }
    function dirtyInputs() { return inputs().filter(function (i) { return i.value !== i.dataset.original; }); }

    function refresh() {
        var n = dirtyInputs().length;
        saveBtn.disabled = n === 0;
        counter.innerHTML = n ? T.unsaved.split(':count').join('<strong>' + n + '</strong>') : T.no_changes;
    }

    function toast(icon, title) {
        if (window.Swal) {
            Swal.fire({ toast: true, position: 'top-end', icon: icon, title: title, showConfirmButton: false, timer: 2200 });
        }
    }

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().then(function (data) {
                if (!r.ok) throw new Error(data.message || T.error);
                return data;
            });
        });
    }

    function setMeta(input, state) {
        var meta = input.parentNode.querySelector('.tr-meta');
        meta.innerHTML = state === 'missing' ? '<span class="missing">' + T.untranslated + '</span>'
            : state === 'custom' ? '<span class="custom">' + T.customized + '</span>' : '';
        input.classList.toggle('empty', input.value === '');
    }

    inputs().forEach(function (input) {
        input.addEventListener('input', function () {
            input.classList.toggle('dirty', input.value !== input.dataset.original);
            refresh();
        });
        // Ctrl/Cmd+S saves from anywhere in the grid
        input.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); if (!saveBtn.disabled) saveBtn.click(); }
        });
    });

    saveBtn.addEventListener('click', function () {
        var dirty = dirtyInputs();
        if (!dirty.length) return;
        var items = dirty.map(function (i) {
            var tr = i.closest('tr');
            return { group: tr.dataset.group, key: tr.dataset.key, value: i.value };
        });
        saveBtn.disabled = true;
        post(saveUrl, { items: items }).then(function (data) {
            dirty.forEach(function (i) {
                i.dataset.original = i.value;
                i.classList.remove('dirty');
                setMeta(i, i.value === '' ? 'missing' : 'custom');
            });
            refresh();
            toast('success', data.message);
        }).catch(function (err) {
            refresh();
            toast('error', err.message);
        });
    });

    document.querySelectorAll('.js-reset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tr = btn.closest('tr');
            var input = tr.querySelector('.tr-input');
            post(resetUrl, { group: tr.dataset.group, key: tr.dataset.key }).then(function (data) {
                input.value = data.value;
                input.dataset.original = data.value;
                input.classList.remove('dirty');
                setMeta(input, data.value === '' ? 'missing' : '');
                refresh();
                toast('success', data.message);
            }).catch(function (err) { toast('error', err.message); });
        });
    });

    // Leaving with unsaved edits (pagination, filters, sidebar)
    window.addEventListener('beforeunload', function (e) {
        if (dirtyInputs().length) { e.preventDefault(); e.returnValue = ''; }
    });
    document.getElementById('trFilterForm').addEventListener('submit', function (e) {
        if (dirtyInputs().length && !confirm(T.leave_unsaved)) e.preventDefault();
    });
})();
</script>
@endpush
