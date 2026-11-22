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
@endphp
<!DOCTYPE html>
<html lang="{{ $pdfLocale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Rental Agreement - {{ $contract->contract_number }}</title>
    <style>
        @page { size: A4; margin: 12mm 15mm 16mm; }
        * { box-sizing: border-box; }
        body, body * { font-family: 'DejaVu Sans', sans-serif !important; }
        body { font-size: 9.5pt; color: #1f2937; line-height: 1.5; margin: 0; }
        body.rtl { direction: rtl; text-align: right; }
        h1, h2, h3, h4 { margin: 14px 0 6px; color: #111827; }
        h2 { font-size: 14pt; }
        h3 { font-size: 11pt; }
        p { margin: 0 0 7px; }
        table { border-collapse: collapse; }
        .body table td, .body table th { border: 1px solid #e5e7eb; padding: 5px 7px; }
        .muted { color: #6b7280; }
        .footer { position: fixed; bottom: -8mm; left: 0; right: 0; text-align: center; font-size: 7.5pt; color: #9ca3af; }
    </style>
</head>
<body class="{{ $isRtl ? 'rtl' : '' }}">
    <div class="footer">{{ $contract->contract_number }} · {{ $setting['CompanyName'] ?? '' }}</div>

    <table style="width: 100%; margin-bottom: 10px;" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width: 55%; vertical-align: top;">
                @if($logoSrc)
                    <img src="{{ $logoSrc }}" alt="Logo" style="max-height: 56px; max-width: 180px; margin-bottom: 6px;"><br>
                @endif
                <strong style="font-size: 11pt;">{{ $setting['CompanyName'] ?? '' }}</strong><br>
                <span class="muted">{{ $setting['CompanyAdress'] ?? '' }}</span><br>
                <span class="muted">{{ collect([$setting['CompanyPhone'] ?? null, $setting['email'] ?? null])->filter()->implode(' · ') }}</span>
            </td>
            <td style="width: 45%; vertical-align: top; text-align: right;">
                <div style="font-size: 17pt; font-weight: bold; color: #4c1d95;">Rental Agreement</div>
                <div style="display: inline-block; background: #f3f4f6; padding: 4px 12px; border-radius: 4px; font-size: 10pt; font-weight: bold; color: #374151; margin-top: 6px;">{{ $contract->contract_number }}</div>
                <div class="muted" style="margin-top: 6px;">
                    {{ $contract->start_date->format('M j, Y') }} — {{ $contract->end_date ? $contract->end_date->format('M j, Y') : 'Open-ended' }}
                </div>
            </td>
        </tr>
    </table>
    <div style="height: 2px; background: #6d28d9; margin: 4px 0 12px;"></div>

    <div class="body">{!! $body !!}</div>

    @unless($bodyHasSignature)
        <table style="width: 100%; margin-top: 34px;" cellpadding="0" cellspacing="0">
            <tr>
                <td style="width: 46%; vertical-align: bottom;">
                    <div style="height: 74px;">{!! $signature !!}</div>
                    <div style="border-top: 1px solid #9ca3af; padding-top: 4px;">
                        <strong>Renter</strong>: {{ $contract->signed_name ?: optional($contract->client)->name }}<br>
                        <span class="muted">{{ $contract->signed_at ? 'Signed '.$contract->signed_at->format('M j, Y H:i') : 'Date: ____________' }}</span>
                    </div>
                </td>
                <td style="width: 8%;"></td>
                <td style="width: 46%; vertical-align: bottom;">
                    <div style="height: 74px;"></div>
                    <div style="border-top: 1px solid #9ca3af; padding-top: 4px;">
                        <strong>For {{ $setting['CompanyName'] ?? 'the Owner' }}</strong><br>
                        <span class="muted">Date: ____________</span>
                    </div>
                </td>
            </tr>
        </table>
    @endunless
</body>
</html>
