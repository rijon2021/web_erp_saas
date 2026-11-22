<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Invites a customer to the client portal: a button to /portal/set-password
 * carrying the one-time invitation token (PortalAuthController@setPassword).
 */
class PortalInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $clientName;

    public $companyName;

    public $url;

    public $expiryHours;

    public function __construct($clientName, $companyName, $url, $expiryHours)
    {
        $this->clientName = $clientName;
        $this->companyName = $companyName;
        $this->url = $url;
        $this->expiryHours = $expiryHours;
    }

    public function build()
    {
        return $this->subject(__('Your client portal access').' — '.($this->companyName ?: config('app.name')))
            ->markdown('emails.portal_invitation');
    }
}
