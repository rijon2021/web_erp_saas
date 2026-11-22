@php($md = fn ($s) => \App\Services\LicenseKeys\TenantMailer::md($s))
@component('mail::message')

<span>{{ __('messages.Hello') }}{!! $customerName ? ' '.$md($customerName) : '' !!},</span>

<span>{!! $md($ref ? __('messages.License_Mail_Intro_Ref', ['ref' => $ref]) : __('messages.License_Mail_Intro')) !!}</span>

@foreach($lines as $product => $keys)
<strong>{!! $md($product) !!}</strong>

@foreach($keys as $i => $line)
<span>{{ count($keys) > 1 ? __('messages.License_Key').' '.($i + 1) : __('messages.License_Key') }}:</span>
<code style="font-size:15px;font-weight:600;letter-spacing:.5px">{!! $md($line['key']) !!}</code>
@if($line['expires_at'])
<br><span style="color:#6b7280;font-size:13px">{{ __('messages.License_Valid_Until') }}: {{ $line['expires_at'] }}@if($line['max_activations']) · {{ __('messages.License_Activations_Limit', ['n' => $line['max_activations']]) }}@endif</span>
@endif

@endforeach
@endforeach

@if($accountUrl)
@component('mail::button', ['url' => $accountUrl])
{{ __('messages.License_View_In_Account') }}
@endcomponent
@endif

<span style="color:#6b7280;font-size:13px">{{ __('messages.License_Mail_Keep_Safe') }}</span>

<span>{{ __('messages.Regards') }},</span><br>
{!! $md($storeName) !!}
@endcomponent
