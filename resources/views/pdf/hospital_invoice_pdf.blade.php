@php
    $pdfLocale = app()->getLocale();
    $isRtl = $pdfLocale === 'ar';
    $start = $isRtl ? 'right' : 'left';
    $end = $isRtl ? 'left' : 'right';
    $logoSrc = null;
    if (!empty($setting['logo'])) {
        $logoPath = upload_public_path('settings/'.$setting['logo']);
        if (!is_file($logoPath)) {
            $logoPath = public_path('images/'.$setting['logo']);
        }
        if (is_file($logoPath) && is_readable($logoPath)) {
            $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
            $mime = $ext === 'svg' ? 'image/svg+xml' : ($ext === 'jpg' ? 'image/jpeg' : 'image/'.($ext ?: 'png'));
            $logoSrc = 'data:'.$mime.';base64,'.base64_encode(file_get_contents($logoPath));
        }
    }
    $p = $invoice->patient;
    $statusColors = ['paid' => '#16a34a', 'partial' => '#d97706', 'unpaid' => '#dc2626', 'draft' => '#6b7280', 'cancelled' => '#6b7280'];
    $statusColor = $statusColors[$invoice->status] ?? '#374151';
    $statusLabels = [
        'draft' => __('pdf.draft'), 'unpaid' => __('pdf.hm_unpaid'), 'partial' => __('pdf.hm_partial'),
        'paid' => __('pdf.paid'), 'cancelled' => __('pdf.hm_cancelled'),
    ];
    $typeLabels = [
        'consultation' => __('pdf.hm_consultation'), 'procedure' => __('pdf.hm_procedure'), 'medicine' => __('pdf.hm_medicine'),
        'lab' => __('pdf.hm_lab_test'), 'bed' => __('pdf.hm_bed_charge'), 'other' => __('pdf.hm_other'),
    ];
    $methodLabels = [
        'cash' => __('pdf.hm_cash'), 'card' => __('pdf.hm_card'), 'bank_transfer' => __('pdf.hm_bank_transfer'),
        'insurance' => __('pdf.hm_insurance'), 'cheque' => __('pdf.hm_cheque'), 'other' => __('pdf.hm_other'),
    ];
    $fmt = fn ($d) => $d ? $d->format('M j, Y') : '';
    $payments = $invoice->payments->sortBy('paid_on');
@endphp
<!DOCTYPE html>
<html lang="{{ $pdfLocale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>{{ __('pdf.hm_invoice') }} {{ $invoice->reference }}</title>
    <style>
        @page { size: A4; margin: 12mm 15mm; }
        * { box-sizing: border-box; }
        body, body * { font-family: 'DejaVu Sans', sans-serif !important; }
        body { font-size: 9.5pt; color: #1f2937; line-height: 1.45; margin: 0; }
        .muted { color: #6b7280; }
        .label { font-size: 8pt; text-transform: uppercase; color: #6b7280; }
        table { border-collapse: collapse; width: 100%; }
        .lines th { background: #f3f4f6; text-align: {{ $start }}; padding: 7px 8px; font-size: 8pt; text-transform: uppercase; border-bottom: 1px solid #e5e7eb; }
        .lines td { padding: 7px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        .num, .lines th.num { text-align: {{ $end }}; }
        .totals td { padding: 5px 8px; }
        .totals .grand td { font-size: 11pt; font-weight: bold; border-top: 2px solid #111827; }
    </style>
</head>
<body>
    <table cellpadding="0" cellspacing="0" style="margin-bottom: 12px;">
        <tr>
            <td style="width: 55%; vertical-align: top; text-align: {{ $start }};">
                @if($logoSrc)<img src="{{ $logoSrc }}" alt="" style="max-height: 54px; max-width: 170px; margin-bottom: 6px;"><br>@endif
                <strong style="font-size: 11pt;">{{ $setting['CompanyName'] ?? '' }}</strong><br>
                <span class="muted">{{ $setting['CompanyAdress'] ?? '' }}</span><br>
                <span class="muted">{{ collect([$setting['CompanyPhone'] ?? null, $setting['email'] ?? null])->filter()->implode(' · ') }}</span>
            </td>
            <td style="width: 45%; vertical-align: top; text-align: {{ $end }};">
                <div style="font-size: 18pt; font-weight: bold; color: #0f766e;">{{ __('pdf.hm_invoice') }}</div>
                <div style="font-size: 10.5pt; font-weight: bold; margin-top: 4px;">{{ $invoice->reference }}</div>
                <div style="display: inline-block; margin-top: 6px; padding: 3px 10px; border-radius: 10px; color: #fff; background: {{ $statusColor }}; font-size: 8.5pt; font-weight: bold;">{{ mb_strtoupper($statusLabels[$invoice->status] ?? $invoice->status) }}</div>
            </td>
        </tr>
    </table>
    <div style="height: 2px; background: #0d9488; margin: 0 0 12px;"></div>

    <table cellpadding="0" cellspacing="0" style="margin-bottom: 14px;">
        <tr>
            <td style="width: 55%; vertical-align: top; text-align: {{ $start }};">
                <div class="label">{{ __('pdf.hm_patient') }}</div>
                <strong>{{ optional($p)->name }}</strong><br>
                @if($p)
                    <span class="muted">{{ __('pdf.hm_mrn') }}: {{ $p->mrn }}@if($p->age !== null) · {{ __('pdf.hm_age') }}: {{ $p->age }}@endif</span><br>
                    @if($p->phone || $p->email)<span class="muted">{{ collect([$p->phone, $p->email])->filter()->implode(' · ') }}</span><br>@endif
                    @if($p->address || $p->city)<span class="muted">{{ collect([$p->address, $p->city])->filter()->implode(', ') }}</span><br>@endif
                    @if($p->insurance_provider)<span class="muted">{{ __('pdf.hm_insurance') }}: {{ $p->insurance_provider }}@if($p->insurance_number) ({{ $p->insurance_number }})@endif</span>@endif
                @endif
            </td>
            <td style="width: 45%; vertical-align: top;">
                <table cellpadding="0" cellspacing="0" style="width: auto; margin-{{ $start }}: auto;">
                    <tr><td class="muted" style="padding: 2px 10px;">{{ __('pdf.date') }}</td><td style="padding: 2px 0;">{{ $fmt($invoice->invoice_date) }}</td></tr>
                    @if($invoice->due_date)
                    <tr><td class="muted" style="padding: 2px 10px;">{{ __('pdf.hm_due_date') }}</td><td style="padding: 2px 0;"><strong>{{ $fmt($invoice->due_date) }}</strong></td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <table class="lines" cellpadding="0" cellspacing="0">
        <thead>
            <tr>
                <th>{{ __('pdf.description') }}</th>
                <th class="num">{{ __('pdf.qty') }}</th>
                <th class="num">{{ __('pdf.price') }}</th>
                <th class="num">{{ __('pdf.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $line)
                <tr>
                    <td>{{ $line->description }}<br><span class="muted" style="font-size: 8pt;">{{ $typeLabels[$line->type] ?? $line->type }}</span></td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 2, '.', ''), '0'), '.') }}</td>
                    <td class="num">{{ $money($line->unit_price) }}</td>
                    <td class="num">{{ $money($line->total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table cellpadding="0" cellspacing="0" style="margin-top: 12px;">
        <tr>
            <td style="width: 55%; vertical-align: top; text-align: {{ $start }};">
                @if($payments->count())
                    <div class="label" style="margin-bottom: 4px;">{{ __('pdf.hm_payments_received') }}</div>
                    @foreach($payments as $pay)
                        <div>{{ $fmt($pay->paid_on) }} · {{ $methodLabels[$pay->method] ?? $pay->method }} · {{ $pay->reference }} — <strong>{{ $money($pay->amount) }}</strong></div>
                    @endforeach
                @endif
                @if($invoice->notes)<p class="muted" style="margin-top: 10px;">{{ $invoice->notes }}</p>@endif
            </td>
            <td style="width: 45%; vertical-align: top;">
                <table class="totals" cellpadding="0" cellspacing="0">
                    <tr><td class="muted">{{ __('pdf.subtotal') }}</td><td class="num">{{ $money($invoice->subtotal) }}</td></tr>
                    @if((float) $invoice->discount > 0)<tr><td class="muted">{{ __('pdf.discount') }}</td><td class="num">− {{ $money($invoice->discount) }}</td></tr>@endif
                    @if((float) $invoice->tax > 0)<tr><td class="muted">{{ __('pdf.order_tax') }}</td><td class="num">{{ $money($invoice->tax) }}</td></tr>@endif
                    <tr><td><strong>{{ __('pdf.total_label') }}</strong></td><td class="num"><strong>{{ $money($invoice->total) }}</strong></td></tr>
                    @if((float) $invoice->paid > 0)<tr><td class="muted">{{ __('pdf.paid_amount') }}</td><td class="num">− {{ $money($invoice->paid) }}</td></tr>@endif
                    <tr class="grand"><td>{{ __('pdf.amount_due') }}</td><td class="num">{{ $money($invoice->due) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
