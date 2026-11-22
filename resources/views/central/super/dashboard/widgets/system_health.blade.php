@php
    $backupState = $d['backup_age'] === null ? 'bad' : ($d['backup_age'] > 7 ? 'mid' : 'ok');
    $diskUsedPct = ($d['disk_free'] !== null && ! empty($d['disk_total'])) ? (int) round((1 - $d['disk_free'] / $d['disk_total']) * 100) : null;
    $diskState = $d['disk_free'] === null ? 'ok' : ($d['disk_free'] < 1073741824 ? 'bad' : (($diskUsedPct ?? 0) >= 80 ? 'mid' : 'ok'));
    $errorsState = $d['critical_logs'] > 0 ? 'bad' : ($d['warning_logs'] > 0 ? 'mid' : 'ok');
    $rows = [
        ['state' => $backupState, 'label' => __('super.dash_custom.health_backup'), 'value' => $d['last_backup'] ? \Carbon\Carbon::parse($d['last_backup'])->diffForHumans() : __('super.dash_custom.health_never')],
    ];
    if ($d['failed_jobs'] !== null) {
        $rows[] = ['state' => $d['failed_jobs'] > 0 ? 'mid' : 'ok', 'label' => __('super.dashboard.health_queue'), 'value' => number_format($d['failed_jobs'])];
    }
    $rows[] = ['state' => $errorsState, 'label' => __('super.dashboard.health_errors'),
        'value' => $errorsState === 'ok' ? '0' : __('super.dashboard.health_errors_value', ['critical' => number_format($d['critical_logs']), 'warnings' => number_format($d['warning_logs'])])];
    if ($d['disk_free'] !== null) {
        $rows[] = ['state' => $diskState, 'label' => __('super.dashboard.health_disk_used'),
            'value' => $diskUsedPct !== null ? __('super.dashboard.disk_used', ['pct' => $diskUsedPct]) : '', 'meter' => $diskUsedPct];
    }
    $rows[] = ['state' => $d['debug'] && $d['env'] === 'production' ? 'bad' : 'ok', 'label' => __('super.dash_custom.health_mode'), 'value' => $d['env'] . ($d['debug'] ? ' · debug' : '')];
    $rows[] = ['state' => 'ok', 'label' => __('super.dashboard.health_version'), 'value' => ($d['version'] ? 'v' . $d['version'] . ' · ' : '') . 'PHP ' . $d['php']];
    $states = array_column($rows, 'state');
    $overall = in_array('bad', $states, true) ? 'bad' : (in_array('mid', $states, true) ? 'mid' : 'ok');
    $overallLabel = ['ok' => __('super.dashboard.health_ok'), 'mid' => __('super.dashboard.health_issues'), 'bad' => __('super.dashboard.health_problem')][$overall];
    $icons = ['ok' => 'bi-check-lg', 'mid' => 'bi-exclamation-lg', 'bad' => 'bi-x-lg'];
@endphp
<section class="dsh-card h-100">
    @include('central.super.dashboard.widgets._header', [
        'link' => route('super.logs-health.index'),
        'pill' => $overallLabel,
        'pill_tone' => ['ok' => 'good', 'mid' => 'warn', 'bad' => 'crit'][$overall],
    ])
    <div class="dsh-card-body">
        <ul class="dsh-health">
            @foreach($rows as $row)
                <li>
                    <div class="dsh-health-row">
                        <span class="dsh-dot dsh-dot--{{ $row['state'] }}"><i class="bi {{ $icons[$row['state']] }}"></i></span>
                        <span class="dsh-health-l">{{ $row['label'] }}</span>
                        <span class="dsh-health-r">{{ $row['value'] }}</span>
                    </div>
                    @if(isset($row['meter']) && $row['meter'] !== null)
                        <div class="dsh-meter"><i style="width: {{ max(0, min(100, $row['meter'])) }}%"></i></div>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</section>
