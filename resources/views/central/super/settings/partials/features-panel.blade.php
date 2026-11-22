{{--
    Settings > General > Features: optional platform features, one switch each
    (App\Support\PlatformFeatures). Own form → PUT super.settings.features.update.
--}}
@php
    $platformFeatures = \App\Support\PlatformFeatures::all();
    $featuresReady = \App\Support\PlatformFeatures::tableReady();
@endphp

@unless($featuresReady)
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>{{ __('super.platform_features.migration_needed') }}
    </div>
@endunless

<form method="POST" action="{{ route('super.settings.features.update') }}" id="featuresForm">
    @csrf
    @method('PUT')

    <div class="d-flex flex-column gap-3">
        @foreach($platformFeatures as $key => $feature)
            <div class="content-card mb-0">
                <div class="card-body-custom d-flex flex-wrap align-items-start gap-3">
                    <div class="flex-shrink-0 fs-4 text-muted"><i class="bi {{ $feature['icon'] }}"></i></div>
                    <div class="flex-grow-1" style="min-width: 220px">
                        <div class="fw-600">{{ __($feature['label']) }}</div>
                        <p class="form-hint mb-0">{{ __($feature['description']) }}</p>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <label class="form-switch-label d-flex align-items-center gap-2 mb-0">
                            <input type="hidden" name="features[{{ $key }}]" value="0">
                            <input type="checkbox" class="form-check-input" name="features[{{ $key }}]" value="1"
                                   @checked($feature['enabled']) @disabled(! $featuresReady)>
                            <span>{{ $feature['enabled'] ? __('super.platform_features.on') : __('super.platform_features.off') }}</span>
                        </label>
                        @if($feature['configure'] && \Illuminate\Support\Facades\Route::has($feature['configure']))
                            <a href="{{ route($feature['configure']) }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-gear me-1"></i>{{ __('super.platform_features.configure') }}
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <p class="form-hint mt-3"><i class="bi bi-info-circle me-1"></i>{{ __('super.platform_features.off_hint') }}</p>

    <div class="d-flex justify-content-end mt-3">
        <button type="submit" class="btn btn-primary" @disabled(! $featuresReady)>
            <i class="bi bi-save me-1"></i>{{ __('super.platform_features.save') }}
        </button>
    </div>
</form>
