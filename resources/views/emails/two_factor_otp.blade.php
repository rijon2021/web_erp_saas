@component('mail::message')
# {{ __('messages.TwoFactorEmailHeading') }}

{{ __('messages.TwoFactorEmailIntro', ['brand' => $brand]) }}

@component('mail::panel')
<div style="font-size: 28px; font-weight: 700; letter-spacing: 6px; text-align: center;">{{ $code }}</div>
@endcomponent

{{ __('messages.TwoFactorEmailExpiry', ['minutes' => $minutes]) }}

{{ __('messages.TwoFactorEmailIgnore') }}

{{ $brand }}
@endcomponent
