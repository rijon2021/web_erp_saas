<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** The 6-digit sign-in code for customers who chose emailed codes (TwoFactor::sendEmailCode). */
class TwoFactorOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $code, public string $brand, public int $minutes)
    {
    }

    public function build()
    {
        return $this->subject(__('messages.TwoFactorEmailSubject', ['code' => $this->code, 'brand' => $this->brand]))
            ->markdown('emails.two_factor_otp');
    }
}
