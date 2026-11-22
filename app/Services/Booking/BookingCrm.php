<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\BookingSetting;
use App\Services\Crm\CrmCaptureService;
use App\Support\BookingTypes;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Website bookings into the CRM (Booking Settings › CRM, needs the CRM on
 * the plan and switched on).
 *
 * Every website booking is noted where the person already is in the CRM: an
 * open lead with the same email / phone, else the customer record. A
 * request (no time yet) can also open a deal in the default pipeline, worth
 * the expected price, so it is followed up like any other enquiry. Never
 * breaks the visitor's booking.
 */
class BookingCrm
{
    public static function enabled(): bool
    {
        try {
            return Booking::depositsReady() && CrmCaptureService::enabled() && (bool) BookingSetting::current()->crm_capture;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @param  bool  $knownClient  the booking matched an existing customer (not one made for it) */
    public function capture(Booking $b, bool $knownClient): ?array
    {
        if (! self::enabled() || ($b->source ?? 'staff') !== 'website') {
            return null;
        }
        try {
            $crm = app(CrmCaptureService::class);
            $s = BookingSetting::current();
            $contact = (array) (($b->request_fields ?? [])['_contact'] ?? []);
            $service = optional($b->product)->name ?: 'Booking';
            $lines = $this->lines($b);

            if ($b->booking_mode === 'request' && $s->crm_request_deals && $b->customer_id) {
                $body = trim(implode("\n", array_map(fn ($k, $v) => $k.': '.$v, array_keys($lines), $lines)));
                $deal = $crm->captureDeal('booking', (int) $b->customer_id, 'Booking request: '.$service.' ('.$b->Ref.')', $body ?: null, [
                    'value' => $this->expected($b),
                ]);
                DB::table('bookings')->where('id', $b->id)->update(['crm_deal_id' => $deal->id]);

                return ['status' => 'deal', 'deal_id' => $deal->id];
            }

            $result = $crm->capture('booking', [
                'name' => ($contact['name'] ?? null) ?: optional($b->customer)->name,
                'email' => ($contact['email'] ?? null) ?: optional($b->customer)->email,
                'phone' => ($contact['phone'] ?? null) ?: optional($b->customer)->phone,
                'subject' => $service.' — '.$this->when($b),
                'estimated_value' => $this->expected($b),
                'extra' => $lines,
            ], $knownClient && $b->customer_id ? ['client_id' => (int) $b->customer_id, 'ref_id' => $b->id] : ['ref_id' => $b->id]);
            if (! empty($result['lead_id'])) {
                DB::table('bookings')->where('id', $b->id)->update(['crm_lead_id' => $result['lead_id']]);
            }

            return $result;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** label => value lines for the CRM note / deal. */
    private function lines(Booking $b): array
    {
        $out = [
            'Booking' => $b->Ref,
            'Kind' => $b->booking_mode === 'request' ? 'Request (no time yet)' : 'Picked a time',
            $b->booking_mode === 'request' ? 'Preferred' : 'When' => $this->when($b),
        ];
        if ($b->staff && $b->booking_mode !== 'request') {
            $out['With'] = $b->staff->name;
        }
        if (($b->seats ?? 1) > 1) {
            $out['People'] = (string) $b->seats;
        }
        if ((float) $b->deposit_amount > 0) {
            $out['Deposit'] = number_format((float) $b->deposit_amount, 2);
        }
        $defs = BookingTypes::allFields();
        foreach ((array) ($b->request_fields ?? []) as $key => $value) {
            if (str_starts_with((string) $key, '_') || $value === null || $value === '') {
                continue;
            }
            $f = $defs[$key] ?? ['id' => $key, 'label' => (string) $key, 'input' => 'text'];
            $label = (string) ($f['label'] ?? $key);
            $out[$label] = match (true) {
                ($f['input'] ?? '') === 'bool' => $value ? 'Yes' : 'No',
                ($f['input'] ?? '') === 'file' && is_array($value) => (string) ($value['name'] ?? $value['file'] ?? ''),
                is_array($value) => implode(', ', array_map('strval', $value)),
                default => (string) $value,
            };
        }

        return array_filter($out, fn ($v) => $v !== null && $v !== '');
    }

    private function when(Booking $b): string
    {
        $d = Carbon::parse($b->booking_date)->toDateString();
        if ($b->booking_mode === 'request' && ! $b->confirmed_at) {
            return $d.' ('.$b->preferred_window.')';
        }

        return $d.' '.substr((string) $b->booking_time, 0, 5).($b->booking_end_time ? '–'.substr((string) $b->booking_end_time, 0, 5) : '');
    }

    private function expected(Booking $b): float
    {
        $price = $b->price !== null && (float) $b->price > 0 ? (float) $b->price : (float) optional($b->product)->price;

        return round(max(0, $price) * max(1, (int) ($b->seats ?: 1)), 2);
    }
}
