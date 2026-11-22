{{-- Default colour picker for Settings > General.
     $name    field name (super_primary_color | tenant_primary_color)
     $value   saved #rrggbb or null (null = built-in colour)
     $builtin the built-in colour used while nothing is chosen
     $label, $hint
     The text input is the submitted value; empty means "use the built-in
     colour". Swatches and the native picker only fill it in (settings-general.js). --}}
@php
    $current = old($name, $value);
    $effective = \App\Models\Central\GeneralSetting::normalizeHexColor($current) ?? strtolower($builtin);
    $presets = ['#019652', '#018a00', '#6d28d9', '#6366f1', '#3b82f6', '#06b6d4', '#14b8a6', '#ec4899', '#ef4444', '#f97316', '#f59e0b', '#0f172a'];
@endphp
<div class="form-group mb-4" data-default-color data-builtin="{{ strtolower($builtin) }}">
    <label class="form-label" for="{{ $name }}">
        <i class="bi bi-palette me-1"></i>{{ $label }}
    </label>
    <p class="form-hint mb-2">{{ $hint }}</p>
    @php
        // Until the default-colours migration runs, the controller skips this
        // field — say so instead of letting "Settings saved" look like it worked.
        try {
            $colorColumnReady = \Illuminate\Support\Facades\Schema::connection('central')->hasColumn('general_settings', $name);
        } catch (\Throwable $e) {
            $colorColumnReady = false;
        }
    @endphp
    @unless($colorColumnReady)
        <div class="alert alert-warning py-2 px-3 mb-2 fs-sm2">
            <i class="bi bi-exclamation-triangle me-1"></i>{{ __('super.general_settings.default_color_migration') }}
        </div>
    @endunless

    <div class="default-color-swatches mb-2" role="group" aria-label="{{ $label }}">
        @foreach($presets as $preset)
            <button type="button" class="default-color-swatch {{ $preset === $effective ? 'is-active' : '' }}"
                    data-color="{{ $preset }}" style="background: {{ $preset }}"
                    title="{{ strtoupper($preset) }}" aria-label="{{ strtoupper($preset) }}"></button>
        @endforeach
    </div>

    <div class="default-color-row">
        <input type="color" class="default-color-picker" value="{{ $effective }}"
               aria-label="{{ __('super.general_settings.default_color_pick') }}">
        <input type="text" id="{{ $name }}" name="{{ $name }}" maxlength="7"
               class="form-control default-color-hex @error($name) is-invalid @enderror"
               value="{{ $current ? strtoupper($current) : '' }}"
               placeholder="{{ strtoupper($builtin) }}">
        <button type="button" class="btn btn-sm btn-outline default-color-reset">
            <i class="bi bi-arrow-counterclockwise me-1"></i>{{ __('super.general_settings.default_color_reset') }}
        </button>
    </div>
    <p class="form-hint mt-1">{{ __('super.general_settings.default_color_builtin', ['color' => strtoupper($builtin)]) }}</p>
    @error($name)
        <div class="text-danger mt-1 fs-sm2">{{ $message }}</div>
    @enderror
</div>
