{{-- AGT (Angola) / DGI (DR Congo) e-invoicing mentions — see App\Services\EInvoicing\FiscalPrint.
     Expects $fiscal = null | ['blocks' => [['title', 'lines' => [], 'qr' => ?data-uri]]]. --}}
@if(!empty($fiscal['blocks']))
    @foreach($fiscal['blocks'] as $fiscalBlock)
    <table style="width: 100%; margin-top: 10px; border: 1px solid #e5e7eb; border-radius: 3px; border-collapse: separate;">
        <tr>
            @if(!empty($fiscalBlock['qr']))
            <td style="width: 92px; padding: 6px; vertical-align: top;">
                <img src="{{ $fiscalBlock['qr'] }}" style="width: 84px; height: 84px;" alt="QR">
            </td>
            @endif
            <td style="padding: 6px 8px; vertical-align: top; text-align: left;" dir="ltr">
                <div style="font-size: 8.5pt; font-weight: bold; color: #111827; margin-bottom: 3px;">{{ $fiscalBlock['title'] }}</div>
                @foreach($fiscalBlock['lines'] as $fiscalLine)
                    <div style="font-size: 7.5pt; color: #4b5563; line-height: 1.45;">{{ $fiscalLine }}</div>
                @endforeach
            </td>
        </tr>
    </table>
    @endforeach
@endif
