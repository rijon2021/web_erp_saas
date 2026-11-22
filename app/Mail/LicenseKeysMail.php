<?php

namespace App\Mail;

use App\Models\StoreSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;

/**
 * The customer's software license keys after a purchase (or a manual issue /
 * admin resend). Keys are decrypted only while rendering.
 */
class LicenseKeysMail extends Mailable
{
    use Queueable;

    public function __construct(
        public Collection $keys,
        public string $customerName = '',
        public ?string $ref = null
    ) {
    }

    public function build()
    {
        $storeName = null;
        try {
            $storeName = StoreSetting::query()->value('store_name');
        } catch (\Throwable $e) {
            // no store tables
        }
        $storeName = $storeName ?: (\App\Models\Setting::query()->value('CompanyName') ?: config('app.name'));

        $lines = $this->keys->map(fn ($k) => [
            'product' => trim(($k->product->name ?? '').($k->variant ? ' — '.$k->variant->name : '')),
            'key' => $k->plainKey(),
            'expires_at' => $k->isGenerated() ? ($k->expires_at ? $k->expires_at->toDateString() : __('messages.License_Lifetime')) : null,
            'max_activations' => $k->isGenerated() ? $k->max_activations : null,
        ])->groupBy('product');

        $accountUrl = null;
        try {
            $accountUrl = url('/'.store_path_to('account/licenses'));
        } catch (\Throwable $e) {
            // storefront helpers unavailable
        }

        $subject = $this->ref
            ? __('messages.License_Mail_Subject_Ref', ['ref' => $this->ref])
            : __('messages.License_Mail_Subject');

        return $this->subject($subject)
            ->markdown('emails.license_keys')
            ->with([
                'customerName' => $this->customerName,
                'ref' => $this->ref,
                'lines' => $lines,
                'storeName' => $storeName,
                'accountUrl' => $accountUrl,
            ]);
    }
}
