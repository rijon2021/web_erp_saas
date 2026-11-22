@php
    $granting = $row->isGranting();
    $color = match (true) {
        $granting                      => 'success',
        $row->status === 'pending'     => 'warning',
        $row->status === 'refunded'    => 'info',
        default                        => 'secondary',
    };
    // An "active" row past its end date reads as expired until the daily sweep runs.
    $label = $row->status === 'active' && ! $granting ? 'expired' : $row->status;
@endphp
<span class="badge bg-{{ $color }}-subtle text-{{ $color }}-emphasis">{{ __('super.addons.status_' . $label) }}</span>
