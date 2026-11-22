@php($md = fn ($s) => \App\Services\LicenseKeys\TenantMailer::md($s))
@component('mail::message')

<span>{{ __('messages.Hello') }}{!! $customerName ? ' '.$md($customerName) : '' !!},</span>

<span>{!! $md(__('messages.License_Reminder_Body', ['product' => $product, 'days' => $daysLeft, 'date' => $expiresAt])) !!}</span>

<span>{{ __('messages.License_Key') }}: <code>{!! $md($maskedKey) !!}</code></span>

<span>{{ __('messages.License_Reminder_Renew') }}</span>

@if($accountUrl)
@component('mail::button', ['url' => $accountUrl])
{{ __('messages.License_View_In_Account') }}
@endcomponent
@endif

<span>{{ __('messages.Regards') }},</span><br>
{!! $md($storeName) !!}
@endcomponent
