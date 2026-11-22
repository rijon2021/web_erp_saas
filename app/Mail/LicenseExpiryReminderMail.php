<?php

namespace App\Mail;

use App\Models\LicenseKey;
use App\Models\StoreSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;

/** "Your license expires in N days" (licenses:send-reminders). */
class LicenseExpiryReminderMail extends Mailable
{
    use Queueable;

    public function __construct(
        public LicenseKey $key,
        public string $customerName,
        public int $daysLeft
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

        $accountUrl = null;
        try {
            $accountUrl = url('/'.store_path_to('account/licenses'));
        } catch (\Throwable $e) {
            // storefront helpers unavailable
        }

        $product = trim(($this->key->product->name ?? '').($this->key->variant ? ' — '.$this->key->variant->name : ''));

        return $this->subject(__('messages.License_Reminder_Subject', ['product' => $product, 'days' => $this->daysLeft]))
            ->markdown('emails.license_expiry_reminder')
            ->with([
                'customerName' => $this->customerName,
                'product' => $product,
                'maskedKey' => $this->key->maskedKey(),
                'expiresAt' => $this->key->expires_at?->toDateString(),
                'daysLeft' => $this->daysLeft,
                'storeName' => $storeName,
                'accountUrl' => $accountUrl,
            ]);
    }
}
