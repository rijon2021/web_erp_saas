@php($md = fn ($s) => \App\Services\LicenseKeys\TenantMailer::md($s))
@component('mail::message')

<span>{{ __('messages.Hello') }}{!! $name ? ' '.$md($name) : '' !!},</span>

<span>{{ __('messages.Verify_Mail_Body') }}</span>

@component('mail::button', ['url' => $url])
{{ __('messages.Verify_Mail_Button') }}
@endcomponent

<span style="color:#6b7280;font-size:13px">{{ __('messages.Verify_Mail_Ignore') }}</span>

<span>{{ __('messages.Regards') }},</span><br>
{!! $md($storeName) !!}
@endcomponent
