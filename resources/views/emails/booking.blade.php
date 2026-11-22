{{-- Booking Management email (App\Mail\BookingMail). Table layout + inline styles for mail clients. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $data['subject'] }}</title>
</head>
<body style="margin:0; padding:0; background:#f6f7f9; font-family:Arial, Helvetica, sans-serif; color:#13202b;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f7f9; padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px; background:#ffffff; border:1px solid #e6e9ed; border-radius:12px;">
          <tr>
            <td style="padding:20px 28px; border-bottom:1px solid #e6e9ed; font-size:18px; font-weight:bold; color:{{ $data['accent'] ?? '#13202b' }};">{{ $data['store'] }}</td>
          </tr>
          <tr>
            <td style="padding:24px 28px 8px;">
              <h1 style="margin:0 0 10px; font-size:22px; line-height:1.3; color:#13202b;">{{ $data['title'] }}</h1>
              @if(! empty($data['intro']))<p style="margin:0 0 16px; font-size:15px; line-height:1.6; color:#3b4a57; white-space:pre-line;">{{ $data['intro'] }}</p>@endif
            </td>
          </tr>
          @if(! empty($data['lines']))
          <tr>
            <td style="padding:0 28px 8px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:14px; border-top:1px solid #e6e9ed;">
                @foreach($data['lines'] as [$label, $value])
                  <tr>
                    <td style="padding:9px 12px 9px 0; color:#4f5d6a; vertical-align:top; width:38%; border-bottom:1px solid #eef1f4;">{{ $label }}</td>
                    <td style="padding:9px 0; font-weight:bold; color:#13202b; vertical-align:top; border-bottom:1px solid #eef1f4; white-space:pre-line;">{{ $value }}</td>
                  </tr>
                @endforeach
              </table>
            </td>
          </tr>
          @endif
          @if(! empty($data['button']))
          <tr>
            <td style="padding:16px 28px 8px;">
              <a href="{{ $data['button'][1] }}" style="display:inline-block; background:{{ $data['accent'] ?? '#13202b' }}; color:#ffffff; text-decoration:none; font-weight:bold; font-size:15px; padding:12px 20px; border-radius:8px;">{{ $data['button'][0] }}</a>
            </td>
          </tr>
          @endif
          @if(! empty($data['note']))
          <tr>
            <td style="padding:12px 28px 4px; font-size:13px; line-height:1.6; color:#4f5d6a; white-space:pre-line;">{{ $data['note'] }}</td>
          </tr>
          @endif
          <tr>
            <td style="padding:20px 28px 24px; font-size:12px; color:#6b7785;">&copy; {{ date('Y') }} {{ $data['store'] }}</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
