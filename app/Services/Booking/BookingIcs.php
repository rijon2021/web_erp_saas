<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\StoreSetting;
use Carbon\Carbon;

/** An iCalendar (.ics) file for one booking: the status page download and the email attachment. */
class BookingIcs
{
    public static function make(Booking $b): string
    {
        $s = StoreSetting::query()->first();
        $tz = config('app.timezone');
        $date = Carbon::parse($b->booking_date)->toDateString();
        $start = Carbon::createFromFormat('Y-m-d H:i', $date.' '.substr((string) $b->booking_time, 0, 5), $tz);
        $end = $b->booking_end_time
            ? Carbon::createFromFormat('Y-m-d H:i', $date.' '.substr((string) $b->booking_end_time, 0, 5), $tz)
            : $start->copy()->addMinutes(max(15, (int) ($b->duration_minutes ?: 60)));
        $esc = fn ($v) => str_replace(["\\", "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\\;'], (string) $v);
        $store = ($s->store_name ?? null) ?: config('app.name');
        $link = $b->public_token ? store_base_url('appointment/'.$b->public_token) : '';
        $host = parse_url(store_base_url(''), PHP_URL_HOST) ?: 'stocky';
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Stocky//Booking//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:booking-'.$b->id.'@'.$host,
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$start->copy()->utc()->format('Ymd\THis\Z'),
            'DTEND:'.$end->copy()->utc()->format('Ymd\THis\Z'),
            'SUMMARY:'.$esc(trim((optional($b->product)->name ?? __('messages.BkmBooking')).' · '.$store)),
            'DESCRIPTION:'.$esc(trim(__('messages.BkmIcsDescription', ['ref' => $b->Ref]).' '.$link)),
            'LOCATION:'.$esc((string) ($s->contact_address ?? '')),
            'END:VEVENT', 'END:VCALENDAR',
        ];

        return implode("\r\n", $lines)."\r\n";
    }

    public static function filename(Booking $b): string
    {
        return (preg_replace('/[^A-Za-z0-9_-]/', '', (string) $b->Ref) ?: 'booking').'.ics';
    }
}
