@php
    $priorityBadge = fn ($p) => match ($p) {
        'urgent' => 'no', 'high' => 'pending', 'low' => 'yes', default => 'info',
    };
@endphp
<div class="content-card h-100">
    @include('central.super.dashboard.widgets._header', ['link' => route('super.support.index')])
    <div class="card-body-custom p-0">
        @if($d['rows']->isEmpty())
            @include('central.super.dashboard.widgets._empty', ['icon' => 'bi-emoji-smile', 'text' => __('super.dash_custom.no_open_tickets')])
        @else
        <div class="table-responsive">
            <table class="table table-hover mb-0 dash-table">
                <thead>
                    <tr>
                        <th>{{ __('super.dash_custom.col_ticket') }}</th>
                        <th>{{ __('super.support.priority') }}</th>
                        <th>{{ __('super.dash_custom.col_last_activity') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($d['rows'] as $ticket)
                        <tr>
                            <td>
                                <a href="{{ route('super.support.show', $ticket) }}" class="fw-600 dash-link">{{ \Illuminate\Support\Str::limit($ticket->subject, 48) }}</a>
                                @if($ticket->admin_unread)
                                    <span class="badge badge-no badge-sm ms-1">{{ __('super.support.new') }}</span>
                                @endif
                                <span class="d-block text-muted fs-xs3">{{ $ticket->ticket_number }} · {{ $ticket->tenantName() }}</span>
                            </td>
                            <td><span class="badge badge-{{ $priorityBadge($ticket->priority) }}">{{ __('super.support.priority_' . $ticket->priority) }}</span></td>
                            <td class="text-muted fs-sm2 text-nowrap">{{ ($ticket->last_reply_at ?? $ticket->created_at)?->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
