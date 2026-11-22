<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;

/**
 * A Booking Management email (App\Services\Booking\BookingNotifier).
 * $data: subject, title, intro, lines [[label, value]], button [label, url]?, note?,
 * store, accent. An .ics calendar file may ride along.
 */
class BookingMail extends Mailable
{
    use Queueable;

    public function __construct(public array $data, private ?string $ics = null, private string $icsName = 'booking.ics')
    {
    }

    public function build()
    {
        $mail = $this->subject($this->data['subject'])
            ->view('emails.booking', ['data' => $this->data])
            ->text('emails.booking_text', ['data' => $this->data]);
        if ($this->ics) {
            $mail->attachData($this->ics, $this->icsName, ['mime' => 'text/calendar; charset=utf-8; method=PUBLISH']);
        }

        return $mail;
    }
}
