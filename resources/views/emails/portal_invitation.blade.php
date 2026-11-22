@component('mail::message')
# {{ __('Hello') }}{{ $clientName ? ' '.$clientName : '' }},

{{ __(':company has set up a client portal account for you. There you can view your invoices, payments and statement, and send us requests.', ['company' => $companyName ?: config('app.name')]) }}

{{ __('Choose a password to activate your account:') }}

@component('mail::button', ['url' => $url])
{{ __('Set My Password') }}
@endcomponent

{{ __('This link expires in :hours hours. If it has expired, ask us to send a new one.', ['hours' => $expiryHours]) }}

{{ $companyName ?: config('app.name') }}
@endcomponent
