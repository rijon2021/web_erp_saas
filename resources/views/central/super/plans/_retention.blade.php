{{-- Backup & data retention (Plan::RETENTION_FIELDS). Shared by create + edit; $plan is null on create. --}}
@php
    $retentionValue = function (string $key) use ($plan) {
        $current = $plan ? $plan->retentionSetting($key) : \App\Models\Central\Plan::RETENTION_FIELDS[$key]['default'];

        return old('retention.' . $key, $current);
    };
    $dayFields = [
        'log_retention_days'     => ['icon' => 'bi-journal-text',  'min' => 7],
        'audit_retention_days'   => ['icon' => 'bi-clipboard-check', 'min' => 30],
        'message_retention_days' => ['icon' => 'bi-chat-dots',     'min' => 7],
        'temp_retention_days'    => ['icon' => 'bi-hourglass-split', 'min' => 1],
    ];
@endphp
<div class="content-card mb-4">
    <div class="card-header-custom">
        <h2><i class="bi bi-shield-check me-2 text-muted"></i>{{ __('super.plans.retention.title') }}</h2>
        <span class="text-muted fs-sm">{{ __('super.plans.retention.subtitle') }}</span>
    </div>
    <div class="card-body-custom">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">{{ __('super.plans.retention.backup_frequency') }}</label>
                <select name="retention[backup_frequency]" class="form-control">
                    @foreach(\App\Models\Central\Plan::BACKUP_FREQUENCIES as $freq)
                        <option value="{{ $freq }}" {{ $retentionValue('backup_frequency') === $freq ? 'selected' : '' }}>{{ __('super.plans.retention.frequency_' . $freq) }}</option>
                    @endforeach
                </select>
                <p class="form-hint">{{ __('super.plans.retention.backup_frequency_hint') }}</p>
            </div>
            <div class="form-group">
                <label class="form-label">{{ __('super.plans.retention.backup_retention_days') }}</label>
                <input type="number" name="retention[backup_retention_days]" class="form-control max-w-160"
                       value="{{ $retentionValue('backup_retention_days') }}" min="1" max="3650">
                <p class="form-hint">{{ __('super.plans.retention.backup_retention_days_hint') }}</p>
            </div>
        </div>

        <div class="limits-grid">
            @foreach($dayFields as $key => $meta)
            @php $val = (int) $retentionValue($key); @endphp
            <div class="limit-item">
                <div class="limit-item-header">
                    <div class="limit-icon"><i class="bi {{ $meta['icon'] }}"></i></div>
                    <label class="form-label mb-0">{{ __('super.plans.retention.' . $key) }}</label>
                </div>
                <input type="number" name="retention[{{ $key }}]" class="form-control"
                       value="{{ $val > 0 ? $val : '' }}" min="0" max="3650"
                       placeholder="{{ __('super.plans.retention.keep_forever') }}">
                <p class="form-hint mb-0">{{ __('super.plans.retention.' . $key . '_hint', ['min' => $meta['min']]) }}</p>
            </div>
            @endforeach
        </div>

        <p class="form-hint mt-3 mb-0">
            <i class="bi bi-lock me-1"></i>{{ __('super.plans.retention.protected_note') }}
        </p>
    </div>
</div>
