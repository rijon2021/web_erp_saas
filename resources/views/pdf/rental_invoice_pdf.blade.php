@php
    $pdfLocale = app()->getLocale();
    $isRtl = $pdfLocale === 'ar';
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
    $c = $inv->contract;
    $statusColors = ['paid' => '#16a34a', 'partial' => '#d97706', 'unpaid' => '#dc2626', 'void' => '#6b7280'];
    $statusColor = $statusColors[$inv->status] ?? '#374151';
    $fmt = fn ($d) => $d ? $d->format('M j, Y') : '';
@endphp
<!DOCTYPE html>
<html lang="{{ $pdfLocale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Invoice {{ $inv->invoice_number }}</title>
    <style>
        @page { size: A4; margin: 12mm 15mm; }
        * { box-sizing: border-box; }
        body, body * { font-family: 'DejaVu Sans', sans-serif !important; }
        body { font-size: 9.5pt; color: #1f2937; line-height: 1.45; margin: 0; }
        .muted { color: #6b7280; }
        table { border-collapse: collapse; width: 100%; }
        .lines th { background: #f3f4f6; text-align: left; padding: 7px 8px; font-size: 8.5pt; border-bottom: 1px solid #e5e7eb; }
        .lines td { padding: 7px 8px; border-bottom: 1px solid #f1f5f9; }
        .right { text-align: right; }
        .totals td { padding: 5px 8px; }
        .totals .grand td { font-size: 11pt; font-weight: bold; border-top: 2px solid #111827; }
    </style>
</head>
<body>
    <table cellpadding="0" cellspacing="0" style="margin-bottom: 12px;">
        <tr>
            <td style="width: 55%; vertical-align: top;">
                @if($logoSrc)<img src="{{ $logoSrc }}" alt="" style="max-height: 54px; max-width: 170px; margin-bottom: 6px;"><br>@endif
                <strong style="font-size: 11pt;">{{ $setting['CompanyName'] ?? '' }}</strong><br>
                <span class="muted">{{ $setting['CompanyAdress'] ?? '' }}</span><br>
                <span class="muted">{{ collect([$setting['CompanyPhone'] ?? null, $setting['email'] ?? null])->filter()->implode(' · ') }}</span>
            </td>
            <td style="width: 45%; vertical-align: top; text-align: right;">
                <div style="font-size: 18pt; font-weight: bold; color: #4c1d95;">INVOICE</div>
                <div style="font-size: 10.5pt; font-weight: bold; margin-top: 4px;">{{ $inv->invoice_number }}</div>
                <div style="display: inline-block; margin-top: 6px; padding: 3px 10px; border-radius: 10px; color: #fff; background: {{ $statusColor }}; font-size: 8.5pt; font-weight: bold;">{{ strtoupper($inv->status) }}</div>
            </td>
        </tr>
    </table>
    <div style="height: 2px; background: #6d28d9; margin: 0 0 12px;"></div>

    <table cellpadding="0" cellspacing="0" style="margin-bottom: 14px;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                <div class="muted" style="font-size: 8pt; text-transform: uppercase;">Bill to</div>
                <strong>{{ optional($inv->client)->name }}</strong><br>
                <span class="muted">{{ collect([optional($inv->client)->phone, optional($inv->client)->email])->filter()->implode(' · ') }}</span><br>
                <span class="muted">{{ collect([optional($inv->client)->adresse, optional($inv->client)->city])->filter()->implode(', ') }}</span>
            </td>
            <td style="width: 50%; vertical-align: top; text-align: right;">
                <table cellpadding="0" cellspacing="0" style="width: auto; margin-left: auto;">
                    <tr><td class="muted" style="padding: 2px 10px;">Contract</td><td style="padding: 2px 0;"><strong>{{ optional($c)->contract_number }}</strong></td></tr>
                    <tr><td class="muted" style="padding: 2px 10px;">Issued</td><td style="padding: 2px 0;">{{ $fmt($inv->issue_date) }}</td></tr>
                    <tr><td class="muted" style="padding: 2px 10px;">Due</td><td style="padding: 2px 0;"><strong>{{ $fmt($inv->due_date) }}</strong></td></tr>
                    @if($inv->period_start)
                    <tr><td class="muted" style="padding: 2px 10px;">Period</td><td style="padding: 2px 0;">{{ $fmt($inv->period_start) }} – {{ $fmt($inv->period_end) }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <table class="lines" cellpadding="0" cellspacing="0">
        <thead>
            <tr><th>Description</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Amount</th></tr>
        </thead>
        <tbody>
            @if($inv->kind === 'rent' && $c)
                @foreach($c->items as $line)
                    <tr>
                        <td>{{ $line->item_name }}@if($line->item_code) <span class="muted">({{ $line->item_code }})</span>@endif</td>
                        <td class="right">{{ $line->quantity }}</td>
                        <td class="right">{{ $money($line->rate) }} / {{ $c->rate_period }}</td>
                        <td class="right">{{ $money($line->amount * ($c->billing_type === 'upfront' ? $periods : 1)) }}</td>
                    </tr>
                @endforeach
                @if($c->billing_type === 'upfront' && $periods > 1)
                    <tr><td colspan="4" class="muted" style="font-size: 8pt;">Billed upfront for {{ $periods }} {{ $c->rate_period }}(s).</td></tr>
                @endif
            @else
                <tr><td>{{ $inv->description }}</td><td class="right">1</td><td class="right">{{ $money($inv->subtotal) }}</td><td class="right">{{ $money($inv->subtotal) }}</td></tr>
            @endif
        </tbody>
    </table>

    <table cellpadding="0" cellspacing="0" style="margin-top: 12px;">
        <tr>
            <td style="width: 55%; vertical-align: top;">
                @if($inv->allocations->count())
                    <div class="muted" style="font-size: 8pt; text-transform: uppercase; margin-bottom: 4px;">Payments received</div>
                    @foreach($inv->allocations as $a)
                        @if($a->payment)
                        <div>{{ $fmt($a->payment->payment_date) }} · {{ optional($a->payment->method)->name }} · {{ $a->payment->payment_number }} — <strong>{{ $money($a->amount) }}</strong></div>
                        @endif
                    @endforeach
                @endif
                @if($inv->notes)<p class="muted" style="margin-top: 10px;">{{ $inv->notes }}</p>@endif
            </td>
            <td style="width: 45%; vertical-align: top;">
                <table class="totals" cellpadding="0" cellspacing="0">
                    <tr><td class="muted">Subtotal</td><td class="right">{{ $money($inv->subtotal) }}</td></tr>
                    @if((float) $inv->discount > 0)<tr><td class="muted">Discount</td><td class="right">− {{ $money($inv->discount) }}</td></tr>@endif
                    @if((float) $inv->tax_amount > 0)<tr><td class="muted">Tax</td><td class="right">{{ $money($inv->tax_amount) }}</td></tr>@endif
                    @if($inv->effectiveLateFee() > 0)<tr><td class="muted">Late fee</td><td class="right">{{ $money($inv->effectiveLateFee()) }}</td></tr>@endif
                    <tr><td><strong>Total</strong></td><td class="right"><strong>{{ $money($inv->total()) }}</strong></td></tr>
                    @if((float) $inv->paid_amount > 0)<tr><td class="muted">Paid</td><td class="right">− {{ $money($inv->paid_amount) }}</td></tr>@endif
                    <tr class="grand"><td>Balance due</td><td class="right">{{ $money($inv->balance()) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
