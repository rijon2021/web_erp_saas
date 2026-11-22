{{--
    Allowed Sections picker — one checkbox per super-admin sidebar link,
    grouped like the sidebar. Expects $checked (array of permission keys).
--}}
@php
    $checked = array_values((array) ($checked ?? []));
    $permGroups = \App\Models\Central\CentralUser::PERMISSION_GROUPS;
    $permTotal = collect($permGroups)->sum(fn ($g) => count($g['items']));
    $permChecked = count(array_intersect($checked, array_keys(\App\Models\Central\CentralUser::PERMISSIONS)));
@endphp
<div class="perm-groups" id="permGroups">
    <div class="perm-toolbar">
        <span class="perm-count"><strong data-perm-count>{{ $permChecked }}</strong> / {{ $permTotal }} {{ __('central.PermissionsSelected') }}</span>
        <button type="button" class="btn btn-sm btn-outline-primary" data-perm-all="1"><i class="bi bi-check2-all me-1"></i>{{ __('central.SelectAll') }}</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-perm-all="0"><i class="bi bi-x-lg me-1"></i>{{ __('central.DeselectAll') }}</button>
    </div>

    @foreach($permGroups as $groupKey => $group)
    <div class="perm-group" data-perm-group="{{ $groupKey }}">
        <div class="perm-group-head">
            <label class="perm-group-title">
                <input type="checkbox" class="form-check-input" data-perm-group-toggle aria-label="{{ __('central.SelectAll') }}: {{ __($group['label']) }}">
                <i class="bi {{ $group['icon'] }}"></i>
                <span>{{ __($group['label']) }}</span>
                <small class="perm-group-count" data-perm-group-count></small>
            </label>
        </div>
        <div class="row g-2">
            @foreach($group['items'] as $key => $langKey)
            <div class="col-md-4 col-6">
                <label class="perm-check-card">
                    <input type="checkbox" name="permissions[]" value="{{ $key }}" {{ in_array($key, $checked, true) ? 'checked' : '' }}>
                    <span class="perm-check-label">
                        <i class="bi bi-check-lg perm-check-icon"></i>
                        {{ __($langKey) }}
                    </span>
                </label>
            </div>
            @endforeach
        </div>
    </div>
    @endforeach
</div>
