<?php

namespace App\Mail;

use App\Models\StoreSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;

/** Storefront: "confirm your e-mail address" with a signed one-hour link. */
class StoreVerifyEmailMail extends Mailable
{
    use Queueable;

    public function __construct(public string $url, public string $name = '')
    {
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

        return $this->subject(__('messages.Verify_Mail_Subject', ['store' => $storeName]))
            ->markdown('emails.store_verify_email')
            ->with(['url' => $this->url, 'name' => $this->name, 'storeName' => $storeName]);
    }
}
