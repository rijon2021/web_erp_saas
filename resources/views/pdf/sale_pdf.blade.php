@php $pdfT = \App\Models\PdfTemplate::settingsFor('sale'); @endphp
@php
    // Branch letterhead: the warehouse that issued this document supplies the
    // name, address, phone, email and logo below. Each field falls back to the
    // global company settings on its own, and documents with no warehouse get
    // the plain company block.
    $issuer = $issuer ?? \App\Services\InvoiceIssuerService::company($setting ?? null);
@endphp
@php
    $pdfLocale = app()->getLocale();
    $isRtl = $pdfLocale === 'ar';
    // No colon in Arabic; English keeps colon after summary labels
    $rtlLabelSuffix = $isRtl ? '' : ':';
@endphp
<!DOCTYPE html>
<html lang="{{ $pdfLocale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Sale Invoice - {{$sale['Ref']}}</title>
    @php
        // Price formatting helper function (shared behavior with other PDFs)
        $priceFormat = $setting['price_format'] ?? null;
        function formatPrice($number, $decimals = 2, $priceFormat = null) {
            // Shared formatter: Price Format separators + the Hide Zero Decimals setting (100.00 => 100).
            return \App\utils\helpers::format_price($number, $decimals, $priceFormat === null ? '' : $priceFormat);
        }
    @endphp
    <style>
        @page { size: A4; margin: {{ $pdfT['margin_v'] }}mm {{ $pdfT['margin_h'] }}mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        /* DejaVu Sans has full Arabic support in DomPDF; avoid Arial/sans-serif fallback which can show ???? for Arabic */
        body, body * { 
            font-family: '{{ $pdfT['font_family'] }}', sans-serif !important; 
        }
        /*body { font-size: {{ $pdfT['font_size'] }}pt; color: {{ $pdfT['text_color'] }}; background: {{ $pdfT['background_color'] }}; line-height: 1.4; padding: 15px 20px; max-width: 100%; }*/
        body { font-size: {{ $pdfT['font_size'] }}pt; color: {{ $pdfT['text_color'] }}; line-height: 1.4; padding: 15px 20px; max-width: 100%; }
        body.rtl { direction: rtl; text-align: right; }
        body.rtl table { direction: rtl; }
    </style>
</head>
<body class="{{ $isRtl ? 'rtl' : '' }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
    <!-- Header Section: in RTL, logo column appears on the right -->
    <table style="width: 100%; margin-bottom: 12px;" cellpadding="0" cellspacing="0" {{ $isRtl ? 'dir="rtl"' : '' }}>
        <tr>
            <td style="width: 17%; vertical-align: top;">
                @php
                    $logoSrc = null;
                    if (!empty($issuer['logo'])) {
                        // Tenant-aware upload location (images/tenants/{id}/settings or images/super/settings),
                        // falling back to the legacy shared path for old files like logo-default.png.
                        $logoPath = upload_public_path('settings/'.$issuer['logo']);
                        if (!is_file($logoPath)) {
                            $logoPath = public_path('images/'.$issuer['logo']);
                        }
                        if (file_exists($logoPath) && is_readable($logoPath)) {
                            $logoData = @file_get_contents($logoPath);
                            if ($logoData !== false) {
                                $logoB64 = base64_encode($logoData);
                                $logoExt = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
                                $logoMime = $logoExt === 'svg' ? 'image/svg+xml' : (in_array($logoExt, ['png','jpeg','jpg','gif','webp'], true) ? 'image/'.$logoExt : 'image/png');
                                if ($logoExt === 'jpg') { $logoMime = 'image/jpeg'; }
                                $logoSrc = 'data:'.$logoMime.';base64,'.$logoB64;
                            }
                        }
                    }
                @endphp
                @if($pdfT['logo_show'] && $logoSrc)
                    <img src="{{ $logoSrc }}" alt="Logo" style="margin-top: 10px;max-height: {{ $pdfT['logo_height'] }}px; max-width: {{ $pdfT['logo_width'] }}px;">
                @endif
            </td>
            <td style="width: 83%; vertical-align: top; text-align: {{ $isRtl ? 'right' : 'right' }};">
                <div style="font-size: 18pt; font-weight: bold; color: {{ $pdfT['text_color'] }}; margin-bottom: 4px; text-align: {{ $isRtl ? 'right' : 'left' }};">{{$issuer['name']}}</div>
                    <table style="width: 100%; font-size: 7.5pt; color: #000; line-height: 1;" cellpadding="0" cellspacing="0">
                        @if($isRtl)
                        {{-- RTL: value LEFT, label RIGHT (like summary) --}}
                        <tr><td style="padding: 1px 0; vertical-align: top; text-align: left; direction: ltr;">{{$issuer['phone']}}</td><td style="width: 50%; padding: 1px 0; vertical-align: top; text-align: left; direction: rtl;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.phone') }}</strong></td></tr>
                        <tr><td style="padding: 1px 0; vertical-align: top; text-align: left; direction: ltr;">{{$issuer['email']}}</td><td style="padding: 1px 0; vertical-align: top; text-align: left; direction: rtl;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.email') }}</strong></td></tr>
                        <tr><td style="padding: 1px 0; vertical-align: top; text-align: left; direction: ltr;">{{$issuer['full_address']}}</td><td style="padding: 1px 0; vertical-align: top; text-align: left; direction: rtl;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.address') }}</strong></td></tr>
                        @else
                        {{-- LTR: label left, value right --}}
                        <tr><td style="width: 10%; padding: 1px 0; vertical-align: top; text-align: left;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.phone') }}:</strong></td><td style="padding: 1px 0; vertical-align: top; text-align: left;">{{$issuer['phone']}}</td></tr>
                        <tr><td style="padding: 1px 0; vertical-align: top; text-align: left;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.email') }}:</strong></td><td style="padding: 1px 0; vertical-align: top; text-align: left;">{{$issuer['email']}}</td></tr>
                        <tr><td style="padding: 1px 0; vertical-align: top; text-align: left;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.address') }}:</strong></td><td style="padding: 1px 0; vertical-align: top; text-align: left;">{{$issuer['full_address']}}</td></tr>
                        @endif
                    </table>
                
            </td>
        </tr>
    </table>

    <!-- Divider -->
    <div style="height: 2px; background: {{ $pdfT['primary_color'] }}; margin: 8px 0 2px 0;"></div>

    <!-- Bill To / From Section: same as summary — RTL = value left, label right; LTR = label left, value right -->
    <table style="width: 100%; margin-bottom: 12px;" cellpadding="0" cellspacing="0" {{ $isRtl ? 'dir="rtl"' : '' }}>
        <tr>
            <td colspan="3" style="text-align:center">
                <div style="font-size: 18pt; font-weight: bold; color: {{ $pdfT['primary_color'] }}; margin-bottom: 6px; letter-spacing: 0.5px;">{{ $pdfT['labels']['title'] !== '' ? $pdfT['labels']['title'] : __('pdf.sales_invoice') }}</div>
            </td>
        </tr>
        <tr>
            <td style="width: 65%; vertical-align: top;">
                <div style=" border-radius: 4px; overflow: hidden; {{ $pdfT['show_customer'] ? '' : 'display:none;' }}">
                    <div>
                        <div style="color: #000; font-size: 10pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px;">{{ __('pdf.bill_to') }}</div>
                   
                        
                        <table style="width: 100%; font-size: 7.5pt; color: #000; line-height: 1.2;" cellpadding="0" cellspacing="0">
                            <!--<div>BILL TO</div>-->
                            <tr><td colspan="2"><strong>{{$sale['client_name']}}</strong></td></tr>
                            @if($isRtl)
                            {{-- RTL: value LEFT, label RIGHT (like summary) --}}
                            
                            <tr><td style="padding: 1px 0; vertical-align: top; text-align: left; direction: ltr;">{{$sale['client_phone']}}</td><td style="width: 32%; padding: 1px 0; vertical-align: top; text-align: left; direction: rtl;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.phone') }}{{ $isRtl ? '' : ':' }}</strong></td></tr>
                            <!--<tr><td style="padding: 1px 0; vertical-align: top; text-align: left; direction: ltr;">{{$sale['client_email']}}</td><td style="padding: 1px 0; vertical-align: top; text-align: left; direction: rtl;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.email') }}{{ $isRtl ? '' : ':' }}</strong></td></tr>-->
                            <tr><td style="padding: 1px 0; vertical-align: top; text-align: left; direction: ltr;">{{$sale['client_adr']}}</td><td style="padding: 1px 0; vertical-align: top; text-align: left; direction: rtl;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.address') }}{{ $isRtl ? '' : ':' }}</strong></td></tr>
                            @if($sale['client_tax'])
                            <tr><td style="padding: 1px 0; vertical-align: top; text-align: left; direction: ltr;">{{$sale['client_tax']}}</td><td style="padding: 1px 0; vertical-align: top; text-align: left; direction: rtl;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.tax_no') }}{{ $isRtl ? '' : ':' }}</strong></td></tr>
                            @endif
                            @else
                            {{-- LTR: label left, value right --}}
                            <tr><td style="width: 15%; padding: 1px 0; vertical-align: top; text-align: left;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.phone') }}:</strong></td><td style="padding: 1px 0; vertical-align: top; text-align: left;">{{$sale['client_phone']}}</td></tr>
                            <!--<tr><td style="padding: 1px 0; vertical-align: top; text-align: left;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.email') }}:</strong></td><td style="padding: 1px 0; vertical-align: top; text-align: left;">{{$sale['client_email']}}</td></tr>-->
                            <tr><td style="padding: 1px 0; vertical-align: top; text-align: left;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.address') }}:</strong></td><td style="padding: 1px 0; vertical-align: top; text-align: left;">{{$sale['client_adr']}}</td></tr>
                            @if($sale['client_tax'])
                            <tr><td style="padding: 1px 0; vertical-align: top; text-align: left;"><strong style="color: {{ $pdfT['text_color'] }};">{{ __('pdf.tax_no') }}:</strong></td><td style="padding: 1px 0; vertical-align: top; text-align: left;">{{$sale['client_tax']}}</td></tr>
                            @endif
                            @endif
                        </table>
                    </div>
                </div>
            </td>
            <td style="width: 5%;"></td>
            <td style="width: 30%; vertical-align: top;">
                
                <table style="width: 100%; font-size: 8pt; margin-top: 6px;" cellpadding="3" cellspacing="0">
                    <tr>
                         <td style="text-align: right; color: #000; font-weight: 600;">{{ __('pdf.invoice_no') }}{{ $isRtl ? '' : ':' }}</td>
                        <td style="text-align: right; color: {{ $pdfT['text_color'] }}; font-weight: 500;">{{$sale['Ref']}}</td>
                 
                    </tr>
                    <tr>
                        <td style="text-align: right; color: #000; font-weight: 600;">{{ __('pdf.date') }}{{ $isRtl ? '' : ':' }}</td>
                        <td style="text-align: right; color: {{ $pdfT['text_color'] }}; font-weight: 500;">
                            @php
                                $dateFormat = $setting['date_format'] ?? 'YYYY-MM-DD';
                                $dateTime = \Carbon\Carbon::parse($sale['date']);
                                $phpDateFormat = str_replace(['YYYY', 'MM', 'DD'], ['Y', 'm', 'd'], $dateFormat);
                                // Check if original date string contains time
                                $hasTime = strpos($sale['date'], ' ') !== false && preg_match('/\d{1,2}:\d{2}/', $sale['date']);
                                if ($hasTime) {
                                    $formattedDate = $dateTime->format($phpDateFormat . ' H:i');
                                    // Preserve seconds if they exist
                                    if (preg_match('/:\d{2}:\d{2}/', $sale['date'])) {
                                        $formattedDate = $dateTime->format($phpDateFormat . ' H:i:s');
                                    }
                                } else {
                                    $formattedDate = $dateTime->format($phpDateFormat);
                                }
                            @endphp
                            {{$formattedDate}}
                        </td>
                    </tr>
               
                    @if(!empty($sale['order_reference']))
                    <tr>
                        <td style="text-align: right; color: #000; font-weight: 600;">{{ __('pdf.order_reference') }}{{ $isRtl ? '' : ':' }}</td>
                        <td style="text-align: right; color: {{ $pdfT['text_color'] }}; font-weight: 500;">{{ $sale['order_reference'] }}</td>
                    </tr>
                    @endif
                    @if(!empty($sale['fbr_invoice_number']))
                    <tr>
                        <td style="text-align: right; color: #000; font-weight: 600;">FBR Invoice #{{ $isRtl ? '' : ':' }}</td>
                        <td style="text-align: right; color: {{ $pdfT['text_color'] }}; font-weight: 500;">{{$sale['fbr_invoice_number']}}</td>
                    </tr>
                    @endif
                    <tr style="{{ $pdfT['show_status'] ? '' : 'display:none;' }}">
                        <td style="text-align: right; color: #000; font-weight: 600;">{{ __('pdf.status') }}{{ $isRtl ? '' : ':' }}</td>
                        <td style="text-align: right;">
                            @php
                                $statusColors = [
                                    'completed' => ['bg' => '#d1fae5', 'color' => '#065f46'],
                                    'paid' => ['bg' => '#d1fae5', 'color' => '#065f46'],
                                    'pending' => ['bg' => '#fef3c7', 'color' => '#92400e'],
                                    'unpaid' => ['bg' => '#fef3c7', 'color' => '#92400e'],
                                    'partial' => ['bg' => '#dbeafe', 'color' => '#1e40af'],
                                ];
                                $statusKey = strtolower($sale['statut']);
                                $statusStyle = $statusColors[$statusKey] ?? ['bg' => '#e5e7eb', 'color' => '#374151'];
                            @endphp
                            <span style="background: {{$statusStyle['bg']}}; color: {{$statusStyle['color']}}; padding: 3px 8px; border-radius: 3px; font-size: 7pt; font-weight: bold; text-transform: uppercase;">{{$sale['statut']}}</span>
                        </td>
                    </tr>
                    <tr style="{{ $pdfT['show_status'] ? '' : 'display:none;' }}">
                        <td style="text-align: right; color: #000; font-weight: 600;">{{ __('pdf.payment') }}{{ $isRtl ? '' : ':' }}</td>
                        <td style="text-align: right;">
                            @php
                                $paymentKey = strtolower($sale['payment_status']);
                                $paymentStyle = $statusColors[$paymentKey] ?? ['bg' => '#e5e7eb', 'color' => '#374151'];
                            @endphp
                            <span style="background: {{$paymentStyle['bg']}}; color: {{$paymentStyle['color']}}; padding: 3px 8px; border-radius: 3px; font-size: 7pt; font-weight: bold; text-transform: uppercase;">{{$sale['payment_status']}}</span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Products Table: in RTL, columns order right-to-left -->
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px; border:  {{ $pdfT['table_borders'] ? '1px solid #000' : 'none' }};" cellpadding="1" cellspacing="1" {{ $isRtl ? 'dir="rtl"' : '' }}>
        <thead>
            <tr style="background: {{ $pdfT['background_color'] }};border-bottom: 1px solid #000;">
                <th style="width: 20px;padding: 6px 5px; text-align: left; font-size: 8pt; font-weight: bold; color: #000; text-transform: uppercase; border-right: 1px solid #000;">SN</th>
                <th style="width: 40%;padding: 6px 5px; text-align: {{ $isRtl ? 'right' : 'left' }}; font-size: 8pt; font-weight: bold; color: #000; text-transform: uppercase; border-right: 1px solid #000;">{{ __('pdf.product') }}</th>
                <th style="padding: 6px 5px; text-align: center; font-size: 8pt; font-weight: bold; color: #000; text-transform: uppercase; border-right: 1px solid #000;">Unit {{ __('pdf.price') }}</th>
                <th style="padding: 6px 5px; text-align: center; font-size: 8pt; font-weight: bold; color: #000; text-transform: uppercase; border-right: 1px solid #000;">{{ __('pdf.qty') }}</th>
                <th style="padding: 6px 5px; text-align: center; font-size: 8pt; font-weight: bold; color: #000; text-transform: uppercase; border-right: 1px solid #000;">{{ __('pdf.disc') }}</th>
                <!--<th style="padding: 6px 5px; text-align: right; font-size: 8pt; font-weight: bold; color: #000; text-transform: uppercase; border-right: 1px solid #000;">{{ __('pdf.tax') }}</th>-->
                <th style="padding: 6px 5px; text-align: right; font-size: 8pt; font-weight: bold; color: #000; text-transform: uppercase;">{{ __('pdf.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @php $rowIndex = 0; @endphp
            @foreach ($details as $detail)
            <tr style="border-bottom: 1px solid #000; background: {{$rowIndex % 2 == 0 ? '#ffffff' : '#f9fafb'}};">
                <td style="padding: 5px; text-align: center; font-size: 8.5pt;border-right: 1px solid #000;">{{$rowIndex=1}}</td>
                <td style=" padding: 5px; vertical-align: top;border-right: 1px solid #000;">
                    <div style="font-weight: 600; font-size: 8.5pt; color: {{ $pdfT['text_color'] }}; margin-bottom: 1px;">{{$detail['name']}}</div>
                    <div style="font-size: 7pt; color: #000;"> {{ __('pdf.code') }} {{$detail['code']}}</div>
                    
                    @if($detail['is_imei'] && $detail['imei_number'] !==null)
                        <div style="font-size: 7pt; color: {{ $pdfT['secondary_color'] }}; margin-top: 1px;">{{ __('pdf.sn') }} {{$detail['imei_number']}}</div>
                    @endif
                </td>
                <td style="padding: 5px; text-align: center; font-size: 8.5pt; color: {{ $pdfT['text_color'] }};border-right: 1px solid #000;">{{formatPrice((float)$detail['price'], 2, $priceFormat)}}</td>
                <td style="padding: 5px; text-align: center; font-size: 8.5pt; color: {{ $pdfT['text_color'] }};border-right: 1px solid #000;">
                    {{$detail['quantity']}} {{ (!empty($detail['pack_name']) && (float)($detail['pack_multiplier'] ?? 1) > 1) ? $detail['pack_name'] : $detail['unitSale'] }}
                    @if(!empty($detail['pack_name']) && (float)($detail['pack_multiplier'] ?? 1) > 1)
                        <div style="font-size: 7pt; color: #000;">(&times;{{ rtrim(rtrim(number_format((float)$detail['pack_multiplier'], 2, '.', ''), '0'), '.') }}) = {{ rtrim(rtrim(number_format((float)$detail['quantity'] * (float)$detail['pack_multiplier'], 2, '.', ''), '0'), '.') }} {{$detail['unitSale']}}</div>
                    @endif
                </td>
                <td style="padding: 5px; text-align: center; font-size: 8.5pt; color: #ef4444;border-right: 1px solid #000;">{{formatPrice((float)$detail['DiscountNet'], 2, $priceFormat)}}</td>
                <!--<td style="padding: 5px; text-align: right; font-size: 8.5pt; color: {{ $pdfT['text_color'] }};">{{formatPrice((float)$detail['taxe'], 2, $priceFormat)}}</td>-->
                <td style="padding: 5px; text-align: right; font-size: 9pt; font-weight: bold; color: {{ $pdfT['primary_color'] }};">{{formatPrice((float)$detail['total'], 2, $priceFormat)}}</td>
            </tr>
            @php $rowIndex++; @endphp
            @endforeach
        </tbody>
    </table>

    <!-- Summary Section: in RTL, summary box appears on the left (start) side -->
    <table style="width: 100%; margin-bottom: 10px;" cellpadding="0" cellspacing="0" {{ $isRtl ? 'dir="rtl"' : '' }}>
        <tr>
            <td style="width: 58%;">
                <table>
                    <tr>
                        <td></td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Payment Terms :</strong> {{ $sale['payment_terms'] ?? 'Credit' }}
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>In Word :</strong> {{ ucfirst(\Illuminate\Support\Number::spell((float) ($sale['GrandTotal'] ?? 0), 'en')) }} Only.
                        </td>
                    </tr>
                    <tr>
                        <td>
                          <strong>Previous Dues :</strong> {{ formatPrice((float) ($sale['previous_dues'] ?? 0), 2, $priceFormat) }}
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 42%; vertical-align: top; text-align: {{ $isRtl ? 'right' : 'left' }};">
                @php
                    // Calculate subtotal from line items
                    $subtotal = 0;
                    foreach ($details as $detail) {
                        $subtotal += (float)$detail['total'];
                    }
                    $discountMethod = $sale['discount_Method'] ?? '2';
                    $discountValue = (float)$sale['discount'];
                    $manualDiscountAmount = $discountMethod === '1' ? $subtotal * ($discountValue / 100) : min($discountValue, $subtotal);
                    // Arabic: col1=amount (left), col2=label (right). English: col1=label (left), col2=amount (right).
                    $tdAmountLeft = 'padding: 5px 10px; font-size: 8.5pt; font-weight: 600; text-align: left; direction: ltr;';
                    $tdLabelRight = 'padding: 5px 10px; font-size: 8pt; font-weight: 600; text-align: right; direction: rtl;';
                    // Per-promotion breakdown (PromotionUsage rows). Falls back to the single
                    // promotion_discount column when no usage rows exist (legacy sales).
                    $promotionBreakdown = array_values(array_filter(
                        (array)($sale['promotions'] ?? []),
                        fn ($p) => is_array($p) && (float)($p['amount'] ?? 0) > 0
                    ));
                @endphp
                <table style="width: 100%; border: 1px solid #e5e7eb; border-radius: 4px; border-collapse: collapse;" cellpadding="0" cellspacing="0">
                    @if($isRtl)
                    {{-- Arabic: amount LEFT, label RIGHT (no dir=rtl on table) --}}
                    <tr style="background: {{ $pdfT['table_striped'] ? '#f9fafb' : '#ffffff' }}; border-bottom: 1px solid #e5e7eb;">
                        <td style="{{ $tdAmountLeft }} color: {{ $pdfT['text_color'] }};">{{ \App\utils\helpers::with_currency($symbol, formatPrice($subtotal, 2, $priceFormat)) }}</td>
                        <td style="{{ $tdLabelRight }} color: #000;">{{ __('pdf.subtotal') }}{!! $rtlLabelSuffix !!}</td>
                    </tr>
                    <tr style="background: #ffffff; border-bottom: 1px solid #e5e7eb;">
                        <td style="{{ $tdAmountLeft }} color: {{ $pdfT['text_color'] }};">{{ \App\utils\helpers::with_currency($symbol, formatPrice((float)$sale['TaxNet'], 2, $priceFormat)) }}</td>
                        <td style="{{ $tdLabelRight }} color: #000;">{{ __('pdf.order_tax') }}{!! $rtlLabelSuffix !!} gfdg</td>
                    </tr>
                    <tr style="background: {{ $pdfT['table_striped'] ? '#f9fafb' : '#ffffff' }}; border-bottom: 1px solid #e5e7eb;">
                        <td style="{{ $tdAmountLeft }} color: #ef4444;">@if($discountMethod === '1')- {{number_format($discountValue, 2)}}% (<span></span>{{ \App\utils\helpers::with_currency($symbol, formatPrice($manualDiscountAmount, 2, $priceFormat)) }}<span></span>)@else - {{ \App\utils\helpers::with_currency($symbol, formatPrice($manualDiscountAmount, 2, $priceFormat)) }}@endif</td>
                        <td style="{{ $tdLabelRight }} color: #000;">{{ __('pdf.discount') }}{!! $rtlLabelSuffix !!}</td>
                    </tr>
                    @if(isset($sale['discount_from_points']) && (float)$sale['discount_from_points'] > 0)
                    <tr style="background: {{ $pdfT['table_striped'] ? '#f9fafb' : '#ffffff' }}; border-bottom: 1px solid #e5e7eb;">
                        <td style="{{ $tdAmountLeft }} color: #ef4444;">- {{ \App\utils\helpers::with_currency($symbol, formatPrice((float)$sale['discount_from_points'], 2, $priceFormat)) }}</td>
                        <td style="{{ $tdLabelRight }} color: #000;">{{ __('pdf.discount_from_points') }}{!! $rtlLabelSuffix !!}</td>
                    </tr>
                    @endif
                    @if(!empty($promotionBreakdown))
                        @foreach($promotionBreakdown as $promo)
                        <tr style="background: {{ $pdfT['table_striped'] ? '#f9fafb' : '#ffffff' }}; border-bottom: 1px solid #e5e7eb;">
                            <td style="{{ $tdAmountLeft }} color: #ef4444;">- {{ \App\utils\helpers::with_currency($symbol, formatPrice((float)$promo['amount'], 2, $priceFormat)) }}</td>
                            <td style="{{ $tdLabelRight }} color: #000;">{{ __('pdf.promotion') }} — {{ $promo['name'] ?? '' }}@if(!empty($promo['code'])) ({{ $promo['code'] }})@endif{!! $rtlLabelSuffix !!}</td>
                        </tr>
                        @endforeach
                    @elseif(isset($sale['promotion_discount']) && (float)$sale['promotion_discount'] > 0)
                    <tr style="background: {{ $pdfT['table_striped'] ? '#f9fafb' : '#ffffff' }}; border-bottom: 1px solid #e5e7eb;">
                        <td style="{{ $tdAmountLeft }} color: #ef4444;">- {{ \App\utils\helpers::with_currency($symbol, formatPrice((float)$sale['promotion_discount'], 2, $priceFormat)) }}</td>
                        <td style="{{ $tdLabelRight }} color: #000;">{{ __('pdf.promotion') }}@if(!empty($sale['promotion_code'])) ({{ $sale['promotion_code'] }})@endif{!! $rtlLabelSuffix !!}</td>
                    </tr>
                    @endif
                    <tr style="background: #ffffff; border-bottom: 1px solid #e5e7eb;">
                        <td style="{{ $tdAmountLeft }} color: {{ $pdfT['text_color'] }};">{{ \App\utils\helpers::with_currency($symbol, formatPrice((float)$sale['shipping'], 2, $priceFormat)) }}</td>
                        <td style="{{ $tdLabelRight }} color: #000;">{{ __('pdf.shipping') }}{!! $rtlLabelSuffix !!}</td>
                    </tr>
                    <tr style="background: {{ $pdfT['primary_color'] }};">
                        <td style="padding: 3px 10px; font-size: 11pt; font-weight: bold; color: #ffffff; text-align: left; direction: ltr;">{{ \App\utils\helpers::with_currency($symbol, formatPrice((float)$sale['GrandTotal'], 2, $priceFormat)) }}</td>
                        <td style="padding: 3px 10px; font-size: 10pt; font-weight: bold; color: #ffffff; text-align: right; direction: rtl;">{{ __('pdf.total_label') }}{!! $rtlLabelSuffix !!}</td>
                    </tr>
                    <tr style="background: #d1fae5; border-bottom: 1px solid #a7f3d0;">
                        <td style="padding: 3px 10px; font-size: 9pt; font-weight: bold; color: #065f46; text-align: left; direction: ltr;">{{ \App\utils\helpers::with_currency($symbol, formatPrice((float)$sale['paid_amount'], 2, $priceFormat)) }}</td>
                        <td style="padding: 3px 10px; font-size: 8.5pt; font-weight: bold; color: #065f46; text-align: right; direction: rtl;">{{ __('pdf.paid_amount') }}{!! $rtlLabelSuffix !!}</td>
                    </tr>
                    <tr style="background: #fef3c7;">
                        <td style="padding: 3px 10px; font-size: 9pt; font-weight: bold; color: #92400e; text-align: left; direction: ltr;">{{ \App\utils\helpers::with_currency($symbol, formatPrice((float)$sale['due'], 2, $priceFormat)) }}</td>
                        <td style="padding: 3px 10px; font-size: 8.5pt; font-weight: bold; color: #92400e; text-align: right; direction: rtl;">{{ __('pdf.amount_due') }}{!! $rtlLabelSuffix !!}</td>
                    </tr>
                    @else
                    {{-- English: label left, amount right --}}
                    <tr style="background: {{ $pdfT['table_striped'] ? '#f9fafb' : '#ffffff' }}; border-bottom: 1px solid #e5e7eb;">
                        <td style="padding: 2px 10px; font-size: 8pt; font-weight: 600; color: #000;">{{ __('pdf.subtotal') }}</td>
                        <td style="padding: 2px 10px; font-size: 8.5pt; font-weight: 600; color: {{ $pdfT['text_color'] }}; text-align: right;">{{ \App\utils\helpers::with_currency($symbol, formatPrice($subtotal, 2, $priceFormat)) }}</td>
                    </tr>
                    
                    <tr style="background: {{ $pdfT['table_striped'] ? '#f9fafb' : '#ffffff' }}; border-bottom: 1px solid #e5e7eb;">
                        <td style="padding: 2px 10px; font-size: 8pt; font-weight: 600; color: #000;">{{ __('pdf.discount') }}</td>
                        <td style="padding: 2px 10px; font-size: 8.5pt; font-weight: 600; color: #ef4444; text-align: right;">@if($discountMethod === '1')- {{number_format($discountValue, 2)}}% (<span></span>{{ \App\utils\helpers::with_currency($symbol, formatPrice($manualDiscountAmount, 2, $priceFormat)) }}<span></span>)@else - {{ \App\utils\helpers::with_currency($symbol, formatPrice($manualDiscountAmount, 2, $priceFormat)) }}@endif</td>
                    </tr>
                    <tr style="background: #ffffff; border-bottom: 1px solid #e5e7eb;">
                        <td style="padding: 2px 10px; font-size: 8pt; font-weight: 600; color: #000;">{{ __('pdf.tax') }}</td>
                        <td style="padding: 2px 10px; font-size: 8.5pt; font-weight: 600; color: {{ $pdfT['text_color'] }}; text-align: right;">{{ \App\utils\helpers::with_currency($symbol, formatPrice((float)$sale['TaxNet'], 2, $priceFormat)) }}</td>
                    </tr>
                    
                    @if(isset($sale['discount_from_points']) && (float)$sale['discount_from_points'] > 0)
                    <tr style="background: {{ $pdfT['table_striped'] ? '#f9fafb' : '#ffffff' }}; border-bottom: 1px solid #e5e7eb;">
                        <td style="padding: 2px 10px; font-size: 8pt; font-weight: 600; color: #000;">{{ __('pdf.discount_from_points') }}</td>
                        <td style="padding: 2px 10px; font-size: 8.5pt; font-weight: 600; color: #ef4444; text-align: right;">- {{ \App\utils\helpers::with_currency($symbol, formatPrice((float)$sale['discount_from_points'], 2, $priceFormat)) }}</td>
                    </tr>
                    @endif
                    @if(!empty($promotionBreakdown))
                        @foreach($promotionBreakdown as $promo)
                        <tr style="background: {{ $pdfT['table_striped'] ? '#f9fafb' : '#ffffff' }}; border-bottom: 1px solid #e5e7eb;">
                            <td style="padding: 2px 10px; font-size: 8pt; font-weight: 600; color: #000;">{{ __('pdf.promotion') }} — {{ $promo['name'] ?? '' }}@if(!empty($promo['code'])) ({{ $promo['code'] }})@endif</td>
                            <td style="padding: 2px 10px; font-size: 8.5pt; font-weight: 600; color: #ef4444; text-align: right;">- {{ \App\utils\helpers::with_currency($symbol, formatPrice((float)$promo['amount'], 2, $priceFormat)) }}</td>
                        </tr>
                        @endforeach
                    @elseif(isset($sale['promotion_discount']) && (float)$sale['promotion_discount'] > 0)
                    <tr style="background: {{ $pdfT['table_striped'] ? '#f9fafb' : '#ffffff' }}; border-bottom: 1px solid #e5e7eb;">
                        <td style="padding: 2px 10px; font-size: 8pt; font-weight: 600; color: #000;">{{ __('pdf.promotion') }}@if(!empty($sale['promotion_code'])) ({{ $sale['promotion_code'] }})@endif</td>
                        <td style="padding: 2px 10px; font-size: 8.5pt; font-weight: 600; color: #ef4444; text-align: right;">- {{ \App\utils\helpers::with_currency($symbol, formatPrice((float)$sale['promotion_discount'], 2, $priceFormat)) }}</td>
                    </tr>
                    @endif
                    <!--<tr style="background: #ffffff; border-bottom: 1px solid #e5e7eb;">-->
                    <!--    <td style="padding: 2px 10px; font-size: 8pt; font-weight: 600; color: #000;">{{ __('pdf.shipping') }}</td>-->
                    <!--    <td style="padding: 2px 10px; font-size: 8.5pt; font-weight: 600; color: {{ $pdfT['text_color'] }}; text-align: right;">{{ \App\utils\helpers::with_currency($symbol, formatPrice((float)$sale['shipping'], 2, $priceFormat)) }}</td>-->
                    <!--</tr>-->
                    <tr style="background: {{ $pdfT['background_color'] }};">
                        <td style="padding: 3px 10px; font-size: 10pt; font-weight: bold; color: #000;">{{ __('pdf.total_label') }}</td>
                        <td style="padding: 3px 10px; font-size: 11pt; font-weight: bold; color: #000; text-align: right;">{{ \App\utils\helpers::with_currency($symbol, formatPrice((float)$sale['GrandTotal'], 2, $priceFormat)) }}</td>
                    </tr>
                    <tr style="background: #d1fae5; border-bottom: 1px solid #a7f3d0;">
                        <td style="padding: 2px 10px; font-size: 8.5pt; font-weight: bold; color: #065f46;">{{ __('pdf.paid_amount') }}</td>
                        <td style="padding: 2px 10px; font-size: 9pt; font-weight: bold; color: #065f46; text-align: right;">{{ \App\utils\helpers::with_currency($symbol, formatPrice((float)$sale['paid_amount'], 2, $priceFormat)) }}</td>
                    </tr>
                    <tr style="background: #fef3c7;">
                        <td style="padding: 2px 10px; font-size: 8.5pt; font-weight: bold; color: #92400e;">{{ __('pdf.amount_due') }}</td>
                        <td style="padding: 2px 10px; font-size: 9pt; font-weight: bold; color: #92400e; text-align: right;">{{ \App\utils\helpers::with_currency($symbol, formatPrice((float)$sale['due'], 2, $priceFormat)) }}</td>
                    </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    @include('pdf.partials.fiscal_block', ['fiscal' => $sale['fiscal'] ?? null])

    <!-- Notes Section -->
    @if($pdfT['show_notes'] && (($sale['notes'] ?? null) || ($sale['payment_note'] ?? null)))
    <div style="margin-top: 12px; margin-bottom: 10px;">
        @if($sale['notes'] ?? null)
        <div style="padding: 8px 10px; background: #f0f9ff; border-{{ $isRtl ? 'right' : 'left' }}: 3px solid #0284c7; border-radius: 3px; margin-bottom: 8px; text-align: {{ $isRtl ? 'right' : 'left' }};">
            <div style="font-size: 8pt; font-weight: 600; color: #0c4a6e; margin-bottom: 3px;">{{ __('pdf.notes') }}</div>
            <p style="font-size: 7.5pt; color: #0c4a6e; line-height: 1.5; margin: 0; white-space: pre-wrap; word-wrap: break-word;">{{$sale['notes']}}</p>
        </div>
        @endif
        @if($sale['payment_note'] ?? null)
        <div style="padding: 8px 10px; background: #fef3c7; border-{{ $isRtl ? 'right' : 'left' }}: 3px solid #ca8a04; border-radius: 3px; text-align: {{ $isRtl ? 'right' : 'left' }};">
            <div style="font-size: 8pt; font-weight: 600; color: #92400e; margin-bottom: 3px;">{{ __('pdf.payment_note') }}</div>
            <p style="font-size: 7.5pt; color: #92400e; line-height: 1.5; margin: 0; white-space: pre-wrap; word-wrap: break-word;">{{$sale['payment_note']}}</p>
        </div>
        @endif
    </div>
    @endif

    {{-- Customer signature (Invoice PDF customizer > Sections > Signature Line) --}}
    @if(!empty($pdfT['show_signature']))
    <table style="width: 100%; margin-top: 18px;" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width: 33%; padding: 8px 10px;">
                <div style="border-bottom: 1px solid {{ $pdfT['text_color'] }}; height: 30px;"></div>
                <div style="font-size: 7.5pt; color: #000; text-align: center; margin-top: 4px;">{{ $pdfT['labels']['signature'] !== '' ? $pdfT['labels']['signature'] : __('pdf.customer_signature') }} <br><br></div>
            </td>
            <td style="width: 33%; padding: 8px 10px;">
                <div style="border-bottom: 1px solid {{ $pdfT['text_color'] }}; height: 30px;"></div>
                <div style="font-size: 7.5pt; color: #000; text-align: center; margin-top: 4px;">Authorized By <br><br></div>
            </td>
            <td style="width: 33%; padding: 8px 10px;">
                <div style="border-bottom: 1px solid {{ $pdfT['text_color'] }}; height: 30px;"></div>
                <div style="font-size: 7.5pt; color: #000; text-align: center; margin-top: 4px;">Customer Signature & Seal <br> <small>(Received product in good condition)</small></div>
            </td>
        </tr>
    </table>
    @endif


    <!-- Footer -->
    <div style="margin-top: 5px; padding-top: 5px; border-top: 1px solid #e5e7eb; text-align: {{ $isRtl ? 'right' : 'left' }};">
        @php
            // A branch note wins; otherwise the Invoice PDF footer text, then the global invoice footer.
            $footerText = $issuer['footer'] !== '' ? $issuer['footer'] : ($pdfT['footer_text'] !== '' ? $pdfT['footer_text'] : ((! empty($setting['is_invoice_footer']) && $setting['invoice_footer'] !== null) ? $setting['invoice_footer'] : ''));
        @endphp
        @if($pdfT['show_footer_text'] && $footerText !== '')
            <div style="padding: 2px 10px; background: {{ $pdfT['table_striped'] ? '#f9fafb' : '#ffffff' }}; border-{{ $isRtl ? 'right' : 'left' }}: 3px solid {{ $pdfT['primary_color'] }}; border-radius: 3px; margin-bottom: 10px;">
                <p style="font-size: 7.5pt; color: #000; line-height: 1.5; margin: 0;">{{ $footerText }}</p>
            </div>
        @endif
        <div style="text-align: left; padding: 2px 15px; {{ $pdfT['show_thank_you'] ? '' : 'display:none;' }}">
            <p style="font-size: 8pt; font-weight: bold; color: {{ $pdfT['primary_color'] }}; margin: 0; letter-spacing: 0.3px;">{{ $pdfT['labels']['thank_you'] !== '' ? $pdfT['labels']['thank_you'] : __('pdf.thank_you') }}</p>
        </div>
    </div>
</body>
</html>
