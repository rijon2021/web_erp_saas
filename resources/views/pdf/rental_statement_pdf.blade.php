@php
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
    $d = fn ($v) => \Carbon\Carbon::parse($v)->format('M j, Y');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Statement</title>
    <style>
        @page { size: A4; margin: 12mm 14mm; }
        body, body * { font-family: 'DejaVu Sans', sans-serif !important; }
        body { font-size: 9pt; color: #1f2937; margin: 0; }
        .muted { color: #6b7280; }
        table { border-collapse: collapse; width: 100%; }
        .lines th { background: #f3f4f6; text-align: left; padding: 6px; font-size: 8pt; border-bottom: 1px solid #e5e7eb; }
        .lines td { padding: 6px; border-bottom: 1px solid #f1f5f9; }
        .right { text-align: right; }
        .box td { padding: 8px 10px; }
    </style>
</head>
<body>
    <table cellpadding="0" cellspacing="0" style="margin-bottom: 10px;">
        <tr>
            <td style="width: 55%; vertical-align: top;">
                @if($logoSrc)<img src="{{ $logoSrc }}" alt="" style="max-height: 50px; max-width: 170px; margin-bottom: 4px;"><br>@endif
                <strong>{{ $setting['CompanyName'] ?? '' }}</strong><br>
                <span class="muted">{{ $setting['CompanyAdress'] ?? '' }}</span>
            </td>
            <td style="width: 45%; vertical-align: top; text-align: right;">
                <div style="font-size: 16pt; font-weight: bold; color: #4c1d95;">STATEMENT</div>
                <div class="muted">{{ $d($st['from']) }} – {{ $d($st['to']) }}</div>
            </td>
        </tr>
    </table>
    <div style="height: 2px; background: #6d28d9; margin-bottom: 12px;"></div>

    <table cellpadding="0" cellspacing="0" style="margin-bottom: 12px;">
        <tr>
            <td style="width: 50%; vertical-align: top;">
                <div class="muted" style="font-size: 8pt;">CUSTOMER</div>
                <strong>{{ $st['customer']['name'] }}</strong><br>
                <span class="muted">{{ collect([$st['customer']['phone'], $st['customer']['email']])->filter()->implode(' · ') }}</span><br>
                <span class="muted">{{ $st['customer']['address'] }}</span>
            </td>
            <td style="width: 50%; vertical-align: top;">
                <table class="box" cellpadding="0" cellspacing="0" style="background: #f5f3ff;">
                    <tr><td class="muted">Opening balance</td><td class="right">{{ $money($st['opening']) }}</td></tr>
                    <tr><td class="muted">Billed</td><td class="right">{{ $money($st['debits']) }}</td></tr>
                    <tr><td class="muted">Paid</td><td class="right">− {{ $money($st['credits']) }}</td></tr>
                    <tr><td><strong>Balance due</strong></td><td class="right"><strong>{{ $money($st['closing']) }}</strong></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="lines" cellpadding="0" cellspacing="0">
        <thead>
            <tr><th>Date</th><th>Reference</th><th>Description</th><th class="right">Charges</th><th class="right">Payments</th><th class="right">Balance</th></tr>
        </thead>
        <tbody>
            <tr><td colspan="5" class="muted">Balance brought forward</td><td class="right">{{ $money($st['opening']) }}</td></tr>
            @foreach($st['lines'] as $l)
                <tr>
                    <td>{{ $d($l['date']) }}</td>
                    <td>{{ $l['reference'] }}<br><span class="muted" style="font-size: 7.5pt;">{{ $l['contract_number'] }}</span></td>
                    <td>{{ $l['description'] }}</td>
                    <td class="right">{{ $l['debit'] ? $money($l['debit']) : '' }}</td>
                    <td class="right">{{ $l['credit'] ? $money($l['credit']) : '' }}</td>
                    <td class="right">{{ $money($l['balance']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($st['deposit_held'] > 0)
        <p class="muted" style="margin-top: 12px;">Security deposit currently held: <strong>{{ $money($st['deposit_held']) }}</strong> (not included in the balance).</p>
    @endif
</body>
</html>
